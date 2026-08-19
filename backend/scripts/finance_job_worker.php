<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI mode.\n");
    exit(1);
}

set_time_limit(0);
date_default_timezone_set('PRC');

$options = [
    'once' => false,
    'job_types' => [],
    // 异常退出任务默认一小时后可接管；业务事务会锁住任务头，避免双 worker 提交同一凭证。
    'stale_seconds' => 3600,
    'worker_token' => '',
];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--once') {
        $options['once'] = true;
        continue;
    }
    if (strpos($argument, '--job-type=') === 0) {
        $values = explode(',', substr($argument, strlen('--job-type=')));
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '') {
                $options['job_types'][$value] = true;
            }
        }
        continue;
    }
    if (strpos($argument, '--stale-seconds=') === 0) {
        $options['stale_seconds'] = max(0, (int)substr($argument, strlen('--stale-seconds=')));
        continue;
    }
    if (strpos($argument, '--worker-token=') === 0) {
        $options['worker_token'] = trim(substr($argument, strlen('--worker-token=')));
        continue;
    }
    fwrite(STDERR, 'Unknown argument: ' . $argument . PHP_EOL);
    exit(1);
}
$options['job_types'] = array_keys($options['job_types']);

$backendRoot = dirname(__DIR__);
$runtimeDir = $backendRoot . '/runtime/finance_async_jobs';
if (!is_dir($runtimeDir) && !@mkdir($runtimeDir, 0775, true) && !is_dir($runtimeDir)) {
    fwrite(STDERR, 'Unable to create worker runtime directory: ' . $runtimeDir . PHP_EOL);
    exit(1);
}
$lockHandle = @fopen($runtimeDir . '/worker.lock', 'c+');
if (!$lockHandle) {
    fwrite(STDERR, "Unable to open finance worker lock file.\n");
    exit(1);
}
$lockAcquired = false;
$lockDeadline = microtime(true) + 5.0;
do {
    $lockAcquired = flock($lockHandle, LOCK_EX | LOCK_NB);
    if (!$lockAcquired) {
        usleep(200000);
    }
} while (!$lockAcquired && microtime(true) < $lockDeadline);
if (!$lockAcquired) {
    fwrite(STDOUT, "Another finance worker is already running.\n");
    fclose($lockHandle);
    exit(0);
}

try {
    define('APP_PATH', $backendRoot . '/app/');
    define('ROOT_PATH', $backendRoot . '/');
    define('APIMODE', 'API');
    require $backendRoot . '/thinkphp/base.php';
    require $backendRoot . '/thinkphp/helper.php';
    require APP_PATH . 'common.php';
    \think\Loader::addNamespace('app', APP_PATH);
    \think\Config::set(include APP_PATH . 'config.php');
    \think\Config::set(include APP_PATH . 'database.php', 'database');

    $service = \app\finance\service\job\FinanceAsyncJobService::createDefault();
    $resolver = function (array $job) {
        return function (array $runningJob) {
            $accountSetId = (string)($runningJob['account_set_id'] ?? '');
            $year = (string)($runningJob['fiscal_year'] ?? '');
            $userId = (string)($runningJob['created_by'] ?? 'system');
            $payload = is_array($runningJob['payload'] ?? null) ? $runningJob['payload'] : [];
            $userName = trim((string)($payload['operator_name'] ?? '')) ?: $userId;
            foreach (['_GET', '_POST', '_REQUEST'] as $scope) {
                $GLOBALS[$scope]['account_set_id'] = $accountSetId;
                $GLOBALS[$scope]['year'] = $year;
                $GLOBALS[$scope]['action'] = '/caseFund/processAsyncJob';
            }
            $_SERVER['HTTP_X_USER_ID'] = $userId;
            $_SERVER['HTTP_X_USER_NAME'] = $userName;

            $caseFund = new \app\finance\model\CaseFund();
            if (!method_exists($caseFund, 'processAsyncJob')) {
                throw new \RuntimeException('CaseFund::processAsyncJob(array $job) 尚未部署');
            }
            return $caseFund->processAsyncJob($runningJob);
        };
    };
    $logger = function (array $entry) {
        fwrite(STDOUT, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    };
    $worker = new \app\finance\service\job\FinanceAsyncJobWorker($service, $resolver, $logger);
    $summary = $worker->run($options);
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(1);
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit(0);
