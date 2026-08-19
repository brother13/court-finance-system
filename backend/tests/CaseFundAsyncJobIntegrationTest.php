<?php

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/CaseFund.php';

use app\finance\model\CaseFund;

class CaseFundAsyncJobTestDouble extends CaseFund
{
    public function __construct()
    {
        $this->accountSetId = 'set-1';
        $this->year = 2026;
        $this->userid = 'user-1';
        $this->username = '测试用户';
    }

    public function sanitizeJobPayload($jobType, array $payload)
    {
        return $this->sanitizeAsyncJobPayload($jobType, $payload);
    }

    public function requestFingerprint($jobType, array $payload, $sourceFileHash = '')
    {
        return $this->asyncRequestFingerprint($jobType, $payload, $sourceFileHash);
    }

    public function assertSameRequest(array $existing, $jobType, array $payload, $knownSourceHash = null)
    {
        return $this->assertAsyncRequestMatchesExisting(
            $existing,
            $jobType,
            $payload,
            [],
            $knownSourceHash
        );
    }

    public function voucherBusinessQueryPayload(array $payload)
    {
        return $this->asyncVoucherBusinessQueryPayload($payload);
    }

    protected function supportedBankStatementBanks()
    {
        return ['SHENGJING' => '盛京银行', 'CCB' => '建设银行'];
    }
}

function caseFundAsyncJobFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function caseFundAsyncJobAssert($condition, $message)
{
    if (!$condition) {
        caseFundAsyncJobFail($message);
    }
}

$model = new CaseFundAsyncJobTestDouble();
caseFundAsyncJobAssert(
    CaseFund::ASYNC_VOUCHER_MAX_ROWS === 5000,
    'Low-memory async voucher scope should use the conservative 5000-row hard limit'
);
caseFundAsyncJobAssert(
    CaseFund::ASYNC_IMPORT_MAX_BYTES === 10 * 1024 * 1024,
    'Base64 async imports should use the 10MB decoded file hard limit'
);
caseFundAsyncJobAssert(
    CaseFund::JOB_BANK_RECONCILE === 'CASE_FUND_BANK_RECONCILE',
    'Bank reconciliation should use a dedicated finance job type'
);

$selected = $model->sanitizeJobPayload(CaseFund::JOB_PAYMENT_VOUCHER, [
    'period' => '2026-08',
    'generate_scope' => 'SELECTED',
    'payment_ids' => ['payment-2', 'payment-1', 'payment-1', ''],
    'source_file_path' => '/tmp/should-not-pass',
]);
caseFundAsyncJobAssert(
    $selected['payment_ids'] === ['payment-1', 'payment-2'],
    'Selected voucher payload should deduplicate non-empty source ids'
);
caseFundAsyncJobAssert(
    !array_key_exists('source_file_path', $selected),
    'Client payload must not inject a server source path'
);

try {
    $tooManyIds = [];
    for ($index = 1; $index <= CaseFund::ASYNC_VOUCHER_MAX_ROWS + 1; $index++) {
        $tooManyIds[] = 'payment-' . $index;
    }
    $model->sanitizeJobPayload(CaseFund::JOB_PAYMENT_VOUCHER, [
        'period' => '2026-08',
        'generate_scope' => 'SELECTED',
        'payment_ids' => $tooManyIds,
    ]);
    caseFundAsyncJobFail('Oversized selected voucher scope should be rejected before queueing');
} catch (InvalidArgumentException $e) {
    caseFundAsyncJobAssert(
        strpos($e->getMessage(), '最多处理5000条') !== false,
        'Oversized selected scope should return the low-memory split guidance'
    );
}
unset($tooManyIds);

$query = $model->sanitizeJobPayload(CaseFund::JOB_REFUND_VOUCHER, [
    'period' => '2026-08',
    'generate_scope' => 'QUERY',
    'date_start' => '2026-08-01',
    'date_end' => '2026-08-31',
    'amount_min' => '0100.5',
    'amount_max' => '200',
    'keyword' => '案号',
    'voucher_status' => 'GENERATED',
    'unexpected_sql' => 'drop table',
]);
caseFundAsyncJobAssert(
    $query['voucher_status'] === 'UNGENERATED',
    'Query voucher job must force the ungenerated source scope'
);
caseFundAsyncJobAssert(
    ($query['amount_min'] ?? '') === '100.50' && ($query['amount_max'] ?? '') === '200.00',
    'Query voucher job should validate and canonicalize the amount range'
);
caseFundAsyncJobAssert(
    !array_key_exists('unexpected_sql', $query),
    'Query voucher payload should use an explicit allowlist'
);
$originalPrecision = ini_get('precision');
ini_set('precision', '30');
try {
    $highPrecisionAmountQuery = $model->sanitizeJobPayload(CaseFund::JOB_PAYMENT_VOUCHER, [
        'period' => '2026-08',
        'generate_scope' => 'QUERY',
        'amount_min' => 100.01,
        'amount_max' => 200.02,
    ]);
} finally {
    ini_set('precision', (string)$originalPrecision);
}
caseFundAsyncJobAssert(
    ($highPrecisionAmountQuery['amount_min'] ?? '') === '100.01'
        && ($highPrecisionAmountQuery['amount_max'] ?? '') === '200.02',
    'Float amount filters should remain stable when production PHP precision is 30'
);
foreach ([
    ['amount_min' => 'invalid', 'amount_max' => '', 'message' => '最低金额格式不正确'],
    ['amount_min' => '200.00', 'amount_max' => '100.00', 'message' => '最低金额不能大于最高金额'],
] as $invalidAmountRange) {
    try {
        $model->sanitizeJobPayload(CaseFund::JOB_REFUND_VOUCHER, [
            'period' => '2026-08',
            'generate_scope' => 'QUERY',
            'amount_min' => $invalidAmountRange['amount_min'],
            'amount_max' => $invalidAmountRange['amount_max'],
        ]);
        caseFundAsyncJobFail('Invalid voucher amount range should be rejected before queueing');
    } catch (InvalidArgumentException $e) {
        caseFundAsyncJobAssert(
            strpos($e->getMessage(), $invalidAmountRange['message']) !== false,
            'Voucher amount range rejection should explain the invalid value'
        );
    }
}
$bankReconcile = $model->sanitizeJobPayload(CaseFund::JOB_BANK_RECONCILE, [
    'period' => '2026-08',
    'bank_code' => 'ccb',
    'date_start' => '2026-08-01',
    'date_end' => '2026-08-31',
    'unexpected_sql' => 'drop table',
]);
caseFundAsyncJobAssert(
    $bankReconcile === [
        'period' => '2026-08',
        'bank_code' => 'CCB',
        'date_start' => '2026-08-01',
        'date_end' => '2026-08-31',
    ],
    'Bank reconciliation payload should validate and freeze only the explicit date and bank scope'
);
foreach ([
    ['date_start' => '2026-08-32', 'date_end' => '2026-08-31', 'message' => '开始日期格式不正确'],
    ['date_start' => '2026-09-01', 'date_end' => '2026-08-31', 'message' => '开始日期不能晚于结束日期'],
    ['date_start' => '2025-12-01', 'date_end' => '2026-01-31', 'message' => '日期必须属于当前会计年度'],
] as $invalidRange) {
    try {
        $model->sanitizeJobPayload(CaseFund::JOB_BANK_RECONCILE, [
            'period' => '2026-08',
            'bank_code' => 'CCB',
            'date_start' => $invalidRange['date_start'],
            'date_end' => $invalidRange['date_end'],
        ]);
        caseFundAsyncJobFail('Invalid bank reconciliation range should be rejected before queueing');
    } catch (InvalidArgumentException $e) {
        caseFundAsyncJobAssert(
            strpos($e->getMessage(), $invalidRange['message']) !== false,
            'Bank reconciliation date rejection should explain the invalid range'
        );
    }
}
$crossPeriodBusinessQuery = $model->voucherBusinessQueryPayload(array_merge($query, [
    'period' => '2022-01',
    'date_start' => '2022-02-01',
    'date_end' => '2022-02-28',
    'scope_snapshot_time' => '2026-08-03 21:03:51',
]));
caseFundAsyncJobAssert(
    !array_key_exists('period', $crossPeriodBusinessQuery),
    'Async task context period must not silently narrow an explicit cross-period business query'
);
caseFundAsyncJobAssert(
    ($crossPeriodBusinessQuery['date_start'] ?? '') === '2022-02-01'
        && ($crossPeriodBusinessQuery['date_end'] ?? '') === '2022-02-28'
        && ($crossPeriodBusinessQuery['amount_min'] ?? '') === '100.50'
        && ($crossPeriodBusinessQuery['amount_max'] ?? '') === '200.00'
        && ($crossPeriodBusinessQuery['scope_snapshot_time'] ?? '') === '2026-08-03 21:03:51',
    'Cross-period async query must preserve the visible date and amount range plus submission snapshot'
);
$annualBusinessQuery = $model->voucherBusinessQueryPayload(array_merge($query, [
    'period' => '2022-01',
    'date_start' => '2022-01-01',
    'date_end' => '2022-12-31',
]));
caseFundAsyncJobAssert(
    !array_key_exists('period', $annualBusinessQuery)
        && ($annualBusinessQuery['date_start'] ?? '') === '2022-01-01'
        && ($annualBusinessQuery['date_end'] ?? '') === '2022-12-31',
    'Annual query generation must not be truncated to the task context month'
);

$fingerprint = $model->requestFingerprint(CaseFund::JOB_PAYMENT_VOUCHER, $selected);
$sameSelected = $model->sanitizeJobPayload(CaseFund::JOB_PAYMENT_VOUCHER, [
    'period' => '2026-08',
    'generate_scope' => 'SELECTED',
    'payment_ids' => ['payment-1', 'payment-2'],
]);
caseFundAsyncJobAssert(
    $fingerprint === $model->requestFingerprint(CaseFund::JOB_PAYMENT_VOUCHER, $sameSelected),
    'Idempotency fingerprint should be stable regardless of selected id order'
);
$existingJob = [
    'payload' => array_merge($selected, ['_request_fingerprint' => $fingerprint]),
    'source_file_hash' => '',
];
$model->assertSameRequest($existingJob, CaseFund::JOB_PAYMENT_VOUCHER, $sameSelected);
try {
    $differentSelected = $model->sanitizeJobPayload(CaseFund::JOB_PAYMENT_VOUCHER, [
        'period' => '2026-08',
        'generate_scope' => 'SELECTED',
        'payment_ids' => ['payment-3'],
    ]);
    $model->assertSameRequest($existingJob, CaseFund::JOB_PAYMENT_VOUCHER, $differentSelected);
    caseFundAsyncJobFail('Reusing a client request id for another source scope should be rejected');
} catch (RuntimeException $e) {
    caseFundAsyncJobAssert(
        strpos($e->getMessage(), '同一客户端请求ID已用于不同') !== false,
        'Idempotency mismatch should have an explicit operator-facing message'
    );
}

$importPayload = $model->sanitizeJobPayload(CaseFund::JOB_PAYMENT_IMPORT, ['period' => '2026-08']);
$importHash = str_repeat('a', 64);
$importExisting = [
    'payload' => array_merge($importPayload, [
        '_request_fingerprint' => $model->requestFingerprint(
            CaseFund::JOB_PAYMENT_IMPORT,
            $importPayload,
            $importHash
        ),
    ]),
    'source_file_hash' => $importHash,
];
$model->assertSameRequest($importExisting, CaseFund::JOB_PAYMENT_IMPORT, $importPayload, $importHash);
try {
    $model->assertSameRequest(
        $importExisting,
        CaseFund::JOB_PAYMENT_IMPORT,
        $importPayload,
        str_repeat('b', 64)
    );
    caseFundAsyncJobFail('Reusing a client request id for another import file should be rejected');
} catch (RuntimeException $e) {
    caseFundAsyncJobAssert(
        strpos($e->getMessage(), '同一客户端请求ID已用于不同') !== false,
        'Import idempotency must compare the source file hash'
    );
}

try {
    $model->sanitizeJobPayload(CaseFund::JOB_PAYMENT_IMPORT, ['period' => '2025-08']);
    caseFundAsyncJobFail('Cross-year async job period should be rejected');
} catch (InvalidArgumentException $e) {
    caseFundAsyncJobAssert(
        $e->getMessage() === '后台任务会计期间与当前年度不一致',
        'Cross-year period rejection should be explicit'
    );
}

$caseFundSource = file_get_contents(__DIR__ . '/../app/finance/model/CaseFund.php');
$workerSource = file_get_contents(__DIR__ . '/../scripts/finance_job_worker.php');
$jobServiceSource = file_get_contents(__DIR__ . '/../app/finance/service/job/FinanceAsyncJobService.php');
$jobWorkerSource = file_get_contents(__DIR__ . '/../app/finance/service/job/FinanceAsyncJobWorker.php');
foreach (['jobCreate', 'jobStatus', 'jobList', 'jobErrorList'] as $action) {
    caseFundAsyncJobAssert(
        strpos($caseFundSource, "case '" . $action . "':") !== false,
        'CaseFund router should expose async action ' . $action
    );
}
caseFundAsyncJobAssert(
    strpos($caseFundSource, 'Db::startTrans();') !== false
        && strpos($caseFundSource, "'GENERATE_ASYNC'") !== false,
    'Async voucher generation must preserve per-voucher transaction and audit markers'
);
caseFundAsyncJobAssert(
    strpos($caseFundSource, 'JOB_BANK_RECONCILE') !== false
        && strpos($caseFundSource, "return 'case_fund:reconcile';") !== false
        && strpos($caseFundSource, 'processAsyncBankReconcile($job)') !== false
        && strpos($caseFundSource, "'RECONCILE_ASYNC'") !== false
        && strpos($caseFundSource, 'fenceBusinessTransaction(') !== false,
    'Bank reconciliation jobs must preserve permission, worker dispatch, audit and transaction fencing'
);
$bankExecuteStart = strpos($caseFundSource, 'protected function executeBankReconcile');
$bankExecuteEnd = strpos($caseFundSource, 'public function bankReconcileList', $bankExecuteStart);
$bankExecuteSource = substr($caseFundSource, $bankExecuteStart, $bankExecuteEnd - $bankExecuteStart);
$bankAtomicOrder = [
    'persistReconcileResult(',
    "'CASE_FUND_BANK_RECONCILE'",
    'completeInBusinessTransaction(',
    'Db::commit()',
];
$bankAtomicPosition = -1;
foreach ($bankAtomicOrder as $needle) {
    $nextPosition = strpos($bankExecuteSource, $needle);
    caseFundAsyncJobAssert(
        $nextPosition !== false && $nextPosition > $bankAtomicPosition,
        'Bank reconciliation atomic completion order is invalid at: ' . $needle
    );
    $bankAtomicPosition = $nextPosition;
}
caseFundAsyncJobAssert(
    strpos($bankExecuteSource, 'fenceBusinessTransaction(') < strpos($bankExecuteSource, 'persistReconcileResult('),
    'Bank reconciliation must fence worker ownership before any business persistence'
);
$bankProcessStart = strpos($caseFundSource, 'protected function processAsyncBankReconcile');
$bankProcessEnd = strpos($caseFundSource, 'protected function processAsyncPaymentImport', $bankProcessStart);
$bankProcessSource = substr($caseFundSource, $bankProcessStart, $bankProcessEnd - $bankProcessStart);
$bankExecuteCallPosition = strpos($bankProcessSource, 'executeBankReconcile($payload, $job)');
caseFundAsyncJobAssert(
    $bankExecuteCallPosition !== false
        && strpos(substr($bankProcessSource, $bankExecuteCallPosition), 'asyncProgress(') === false
        && strpos($bankProcessSource, 'FinanceAsyncJobService::ATOMIC_COMPLETION_KEY') !== false,
    'Bank reconcile handler must return the atomic marker without a post-commit progress write'
);
caseFundAsyncJobAssert(
    strpos($jobServiceSource, 'public function completeInBusinessTransaction') !== false
        && strpos($jobServiceSource, 'const ATOMIC_COMPLETION_KEY') !== false
        && strpos($jobWorkerSource, 'assertCompletedByWorker(') !== false
        && strpos($jobWorkerSource, 'ATOMIC_COMPLETION_SURVIVED_FATAL') !== false,
    'Job service and worker must implement atomic completion confirmation and fatal recovery'
);
caseFundAsyncJobAssert(
    strpos($caseFundSource, 'batchAddItems(') !== false
        && strpos($caseFundSource, 'batchUpdateItems(') !== false,
    'Large async scopes should freeze and update job items with batch SQL'
);
caseFundAsyncJobAssert(
    strpos($caseFundSource, "'replace_counts' => true") !== false,
    'Stale worker retries should replace counters derived from the previous attempt'
);
caseFundAsyncJobAssert(
    strpos($caseFundSource, "(int)(\$generated['generated_count'] ?? 0) !== 1") !== false,
    'One outer async voucher group must map to exactly one committed voucher'
);
caseFundAsyncJobAssert(
    strpos($workerSource, 'processAsyncJob($runningJob)') !== false,
    'CLI worker should delegate claimed jobs to CaseFund::processAsyncJob'
);
caseFundAsyncJobAssert(
    strpos($caseFundSource, '$launchResult = (new FinanceAsyncJobLauncher())->launch();') !== false,
    'Single locked worker must drain all registered finance job types'
);

fwrite(STDOUT, "Case fund async job integration test passed\n");
