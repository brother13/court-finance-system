<?php

require_once __DIR__ . '/../app/finance/service/job/FinanceAsyncJobService.php';
require_once __DIR__ . '/../app/finance/service/job/FinanceAsyncJobWorker.php';

use app\finance\service\job\FinanceAsyncJobService;
use app\finance\service\job\FinanceAsyncJobWorker;

if (!class_exists('\\think\\Db', false)) {
    eval('namespace think; class Db { public static $rollbackCalls = 0; public static $clearCalls = 0; public static function rollback() { self::$rollbackCalls++; } public static function clear() { self::$clearCalls++; } }');
}

class FinanceAsyncFatalFakeService extends FinanceAsyncJobService
{
    public $failCalls = [];
    public $completedLookupCalls = [];
    public $completedJob = null;

    public function __construct()
    {
        parent::__construct([
            'get_db' => function () {
                throw new RuntimeException('Fatal guard test must not query database');
            },
            'transaction' => function ($callback) {
                return $callback();
            },
            'new_id' => function () {
                return 'test-id';
            },
            'now' => function () {
                return '2026-08-03 12:00:00';
            },
        ]);
    }

    public function fail($jobId, $workerToken, $errorMessage, array $result = [])
    {
        $this->failCalls[] = [$jobId, $workerToken, $errorMessage, $result];
        return [
            'job_id' => $jobId,
            'job_type' => 'CASE_FUND_PAYMENT_VOUCHER',
            'status' => 'FAILED',
            'message' => $errorMessage,
        ];
    }

    public function completedByWorker($jobId, $workerToken)
    {
        $this->completedLookupCalls[] = [$jobId, $workerToken];
        return $this->completedJob;
    }
}

class FinanceAsyncFatalWorkerProbe extends FinanceAsyncJobWorker
{
    public function arm(array $job, $workerToken)
    {
        $this->activeJob = $job;
        $this->activeWorkerToken = $workerToken;
        $this->fatalMemoryReserve = str_repeat('R', 1024);
    }
}

$service = new FinanceAsyncFatalFakeService();
$worker = new FinanceAsyncFatalWorkerProbe($service, function () {
    return function () {
        return [];
    };
});
$worker->arm([
    'job_id' => 'job-fatal-1',
    'job_type' => 'CASE_FUND_PAYMENT_VOUCHER',
], 'worker-token-1');
$worker->handleFatalShutdown([
    'type' => E_ERROR,
    'message' => 'Allowed memory size of 134217728 bytes exhausted',
]);

if (count($service->failCalls) !== 1) {
    fwrite(STDERR, 'Fatal guard should fail the active job exactly once' . PHP_EOL);
    exit(1);
}
list($jobId, $token, $message, $result) = $service->failCalls[0];
if ($jobId !== 'job-fatal-1' || $token !== 'worker-token-1') {
    fwrite(STDERR, 'Fatal guard must preserve job and worker ownership fence' . PHP_EOL);
    exit(1);
}
if (strpos($message, '内存不足') === false || (int)($result['fatal_type'] ?? 0) !== E_ERROR) {
    fwrite(STDERR, 'Fatal guard should record a clear memory exhaustion result' . PHP_EOL);
    exit(1);
}
if (\think\Db::$rollbackCalls !== 32 || \think\Db::$clearCalls !== 1) {
    fwrite(STDERR, 'Fatal guard must unwind all TP5 transaction levels and refresh the connection' . PHP_EOL);
    exit(1);
}

// activeJob is cleared after the first terminal update, so a second shutdown callback is idempotent.
$worker->handleFatalShutdown([
    'type' => E_ERROR,
    'message' => 'Allowed memory size exhausted again',
]);
if (count($service->failCalls) !== 1) {
    fwrite(STDERR, 'Fatal guard must not fail the same active job twice' . PHP_EOL);
    exit(1);
}

// Fatal after the business transaction and SUCCEEDED task row were committed must
// never reverse the durable terminal state. The guard first unwinds any open TP5
// transaction levels, then confirms the already committed task on a fresh connection.
$completedService = new FinanceAsyncFatalFakeService();
$completedService->completedJob = [
    'job_id' => 'job-fatal-atomic',
    'job_type' => 'CASE_FUND_BANK_RECONCILE',
    'status' => 'SUCCEEDED',
    'message' => '自动对账完成',
];
$completedWorker = new FinanceAsyncFatalWorkerProbe($completedService, function () {
    return function () {
        return [];
    };
});
$completedWorker->arm([
    'job_id' => 'job-fatal-atomic',
    'job_type' => 'CASE_FUND_BANK_RECONCILE',
], 'worker-token-atomic');
$rollbackCallsBeforeCompletedFatal = \think\Db::$rollbackCalls;
$clearCallsBeforeCompletedFatal = \think\Db::$clearCalls;
$completedWorker->handleFatalShutdown([
    'type' => E_ERROR,
    'message' => '模拟原子提交后的致命错误',
]);
if (count($completedService->completedLookupCalls) !== 1) {
    fwrite(STDERR, 'Fatal guard should check durable atomic completion exactly once' . PHP_EOL);
    exit(1);
}
if (count($completedService->failCalls) !== 0) {
    fwrite(STDERR, 'Fatal guard must not overwrite committed atomic success with failed' . PHP_EOL);
    exit(1);
}
if (\think\Db::$rollbackCalls !== $rollbackCallsBeforeCompletedFatal + 32
    || \think\Db::$clearCalls !== $clearCallsBeforeCompletedFatal + 1
) {
    fwrite(STDERR, 'Fatal guard must verify atomic completion only after transaction unwind and connection refresh' . PHP_EOL);
    exit(1);
}

// The recovered active job is cleared, so repeated shutdown delivery stays idempotent.
$completedWorker->handleFatalShutdown([
    'type' => E_ERROR,
    'message' => '重复 fatal',
]);
if (count($completedService->completedLookupCalls) !== 1 || count($completedService->failCalls) !== 0) {
    fwrite(STDERR, 'Recovered atomic fatal handling should be idempotent' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Finance async job worker fatal guard test passed' . PHP_EOL);
