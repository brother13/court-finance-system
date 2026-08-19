<?php

require_once __DIR__ . '/../app/finance/service/job/FinanceAsyncJobService.php';
require_once __DIR__ . '/../app/finance/service/job/FinanceAsyncJobWorker.php';
require_once __DIR__ . '/../app/finance/service/job/FinanceAsyncJobLauncher.php';

use app\finance\service\job\FinanceAsyncJobLauncher;
use app\finance\service\job\FinanceAsyncJobService;
use app\finance\service\job\FinanceAsyncJobWorker;

class FinanceAsyncJobMemoryStore
{
    public $rows = ['job' => [], 'item' => []];
    public $sequence = 0;
    public $now = '2026-08-03 10:00:00';
    public $insertAllCalls = [];
    public $updateCalls = [];
    public $transactionCalls = 0;

    public function db($table)
    {
        return new FinanceAsyncJobMemoryQuery($this, $table);
    }

    public function nextId()
    {
        $this->sequence++;
        return '00000000-0000-0000-0000-' . str_pad((string)$this->sequence, 12, '0', STR_PAD_LEFT);
    }

    public function advance($seconds)
    {
        $this->now = date('Y-m-d H:i:s', strtotime($this->now) + (int)$seconds);
    }
}

class FinanceAsyncJobMemoryQuery
{
    protected $store;
    protected $table;
    protected $conditions = [];
    protected $order = '';
    protected $page = 1;
    protected $pageSize = 0;

    public function __construct($store, $table)
    {
        $this->store = $store;
        $this->table = $table;
    }

    public function where($field, $operator = null, $value = null)
    {
        if (is_array($field)) {
            foreach ($field as $key => $item) {
                $this->conditions[] = [$key, '=', $item];
            }
        } elseif (func_num_args() === 2) {
            $this->conditions[] = [$field, '=', $operator];
        } else {
            $this->conditions[] = [$field, strtolower((string)$operator), $value];
        }
        return $this;
    }

    public function order($order)
    {
        $this->order = (string)$order;
        return $this;
    }

    public function lock($lock = true)
    {
        return $this;
    }

    public function page($page, $pageSize)
    {
        $this->page = max(1, (int)$page);
        $this->pageSize = max(1, (int)$pageSize);
        return $this;
    }

    public function find()
    {
        $rows = $this->matchingRows();
        return empty($rows) ? null : reset($rows);
    }

    public function select()
    {
        $rows = array_values($this->matchingRows());
        if ($this->pageSize > 0) {
            $rows = array_slice($rows, ($this->page - 1) * $this->pageSize, $this->pageSize);
        }
        return $rows;
    }

    public function count()
    {
        return count($this->matchingRows());
    }

    public function insert($row)
    {
        $keyField = $this->table === 'job' ? 'job_id' : 'item_id';
        foreach ($this->store->rows[$this->table] as $existing) {
            if (($existing[$keyField] ?? '') === ($row[$keyField] ?? '')) {
                throw new RuntimeException('duplicate primary key');
            }
            if ($this->table === 'job'
                && ($existing['account_set_id'] ?? '') === ($row['account_set_id'] ?? '')
                && ($existing['job_type'] ?? '') === ($row['job_type'] ?? '')
                && ($existing['client_request_id'] ?? '') === ($row['client_request_id'] ?? '')
            ) {
                throw new RuntimeException('duplicate request key');
            }
            if ($this->table === 'item'
                && ($existing['job_id'] ?? '') === ($row['job_id'] ?? '')
                && ($existing['source_type'] ?? '') === ($row['source_type'] ?? '')
                && ($existing['source_id'] ?? '') === ($row['source_id'] ?? '')
            ) {
                throw new RuntimeException('duplicate item source');
            }
        }
        $this->store->rows[$this->table][] = $row;
        return 1;
    }

    public function insertAll($rows)
    {
        $this->store->insertAllCalls[] = [
            'table' => $this->table,
            'count' => count($rows),
        ];
        foreach ($rows as $row) {
            $this->insert($row);
        }
        return count($rows);
    }

    public function update($update)
    {
        $this->store->updateCalls[] = [
            'table' => $this->table,
            'conditions' => $this->conditions,
            'update' => $update,
        ];
        $affected = 0;
        foreach ($this->store->rows[$this->table] as &$row) {
            if (!$this->matches($row)) {
                continue;
            }
            foreach ($update as $field => $value) {
                if (is_array($value) && ($value[0] ?? '') === 'inc') {
                    $row[$field] = (int)($row[$field] ?? 0) + (int)($value[1] ?? 0);
                } else {
                    $row[$field] = $value;
                }
            }
            $affected++;
        }
        unset($row);
        return $affected;
    }

    protected function matchingRows()
    {
        $rows = array_values(array_filter($this->store->rows[$this->table], function ($row) {
            return $this->matches($row);
        }));
        if ($this->order !== '') {
            $orders = array_map('trim', explode(',', $this->order));
            usort($rows, function ($left, $right) use ($orders) {
                foreach ($orders as $order) {
                    $parts = preg_split('/\s+/', trim($order));
                    $field = $parts[0];
                    $direction = strtolower($parts[1] ?? 'asc');
                    $comparison = ($left[$field] ?? null) <=> ($right[$field] ?? null);
                    if ($comparison !== 0) {
                        return $direction === 'desc' ? -$comparison : $comparison;
                    }
                }
                return 0;
            });
        }
        return $rows;
    }

    protected function matches($row)
    {
        foreach ($this->conditions as [$field, $operator, $value]) {
            $actual = $row[$field] ?? null;
            if ($operator === '=' && $actual !== $value) {
                return false;
            }
            if ($operator === 'in' && !in_array($actual, $value, true)) {
                return false;
            }
            if ($operator === '<=' && !($actual <= $value)) {
                return false;
            }
        }
        return true;
    }
}

class FinanceAsyncJobCountingService extends FinanceAsyncJobService
{
    public $completeCalls = 0;

    public function complete($jobId, $workerToken, array $result = [], $status = '')
    {
        $this->completeCalls++;
        return parent::complete($jobId, $workerToken, $result, $status);
    }
}

function financeAsyncJobFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function financeAsyncJobAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        financeAsyncJobFail($message . '; expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}

function financeAsyncJobService($store, $serviceClass = FinanceAsyncJobService::class)
{
    return new $serviceClass([
        'get_db' => function ($table) use ($store) {
            return $store->db($table);
        },
        'transaction' => function ($callback) use ($store) {
            $store->transactionCalls++;
            return $callback();
        },
        'new_id' => function () use ($store) {
            return $store->nextId();
        },
        'now' => function () use ($store) {
            return $store->now;
        },
    ], ['job' => 'job', 'item' => 'item']);
}

try {
    new FinanceAsyncJobService([]);
    financeAsyncJobFail('Missing service hooks should be rejected');
} catch (InvalidArgumentException $e) {
    financeAsyncJobAssertSame('异步任务服务缺少回调：get_db', $e->getMessage(), 'Missing hook message should identify get_db');
}

$store = new FinanceAsyncJobMemoryStore();
$service = financeAsyncJobService($store);
$base = [
    'account_set_id' => 'set-1',
    'fiscal_year' => 2026,
    'period' => '2026-08',
    'job_type' => 'CASE_FUND_PAYMENT_IMPORT',
    'client_request_id' => 'request-1',
    'payload' => ['filename' => 'payment.xls'],
    'source_file_name' => 'payment.xls',
    'source_file_path' => '/safe/import/payment.xls',
    'source_file_hash' => str_repeat('a', 64),
    'created_by' => 'user-1',
];
$created = $service->create($base);
financeAsyncJobAssertSame(true, $created['created'], 'First task request should create a row');
financeAsyncJobAssertSame('QUEUED', $created['job']['status'], 'New task should be queued');
financeAsyncJobAssertSame('payment.xls', $created['job']['payload']['filename'], 'Task payload JSON should round trip');
$publicJob = $service->publicJob($created['job']);
financeAsyncJobAssertSame(false, array_key_exists('source_file_path', $publicJob), 'Public job must not expose server file path');
financeAsyncJobAssertSame(false, array_key_exists('payload', $publicJob), 'Public job must not expose internal payload');
$duplicate = $service->create($base);
financeAsyncJobAssertSame(false, $duplicate['created'], 'Same client request should be idempotent');
financeAsyncJobAssertSame($created['job']['job_id'], $duplicate['job']['job_id'], 'Idempotent create should return original job');
financeAsyncJobAssertSame(1, count($store->rows['job']), 'Idempotent create should not insert a second row');
$launchWarned = $service->noteQueuedLaunchWarning($created['job']['job_id'], 'worker未启动，任务仍在队列中');
financeAsyncJobAssertSame('worker未启动，任务仍在队列中', $launchWarned['message'], 'Queued launcher warning should persist');
$requestLookup = $service->listJobs(['account_set_id' => 'set-1', 'client_request_id' => 'request-1']);
financeAsyncJobAssertSame(1, $requestLookup['total'], 'Job list should recover a lost create response by client_request_id');

try {
    $service->create(array_merge($base, ['client_request_id' => 'bad-period', 'period' => '2025-08']));
    financeAsyncJobFail('Mismatched fiscal period should be rejected');
} catch (InvalidArgumentException $e) {
    financeAsyncJobAssertSame('任务会计期间格式不正确或与年度不一致', $e->getMessage(), 'Period validation should be explicit');
}

$claimed = $service->claimNext('worker-1');
financeAsyncJobAssertSame('RUNNING', $claimed['status'], 'Claim should move queued task to running');
financeAsyncJobAssertSame('worker-1', $claimed['worker_token'], 'Claim should persist worker ownership');
financeAsyncJobAssertSame(1, $claimed['attempt_count'], 'First claim should increment attempt count');
try {
    $service->updateProgress($claimed['job_id'], 'worker-other', ['processed' => 1]);
    financeAsyncJobFail('Foreign worker should not update progress');
} catch (RuntimeException $e) {
    financeAsyncJobAssertSame('任务执行权已失效，禁止继续更新', $e->getMessage(), 'Foreign worker rejection should be explicit');
}
$fenced = $service->fenceBusinessTransaction($claimed['job_id'], 'worker-1', 'WRITING', '事务写入中');
financeAsyncJobAssertSame('WRITING', $fenced['stage'], 'Business transaction fence should retain worker ownership and stage');

$store->advance(5);
$progressed = $service->updateProgress($claimed['job_id'], 'worker-1', [
    'stage' => 'WRITING',
    'total' => 10,
    'processed' => 3,
    'success' => 2,
    'failure' => 1,
    'message' => '正在写入第3行',
]);
financeAsyncJobAssertSame(30.0, $progressed['progress_percent'], 'Progress should be computed from processed and total');
financeAsyncJobAssertSame('WRITING', $progressed['stage'], 'Progress should update stage');
financeAsyncJobAssertSame($store->now, $progressed['heartbeat_time'], 'Progress should also refresh heartbeat');
$recounted = $service->updateProgress($claimed['job_id'], 'worker-1', [
    'replace_counts' => true,
    'total_count' => 10,
    'processed_count' => 2,
    'success_count' => 2,
    'failure_count' => 0,
]);
financeAsyncJobAssertSame(0, $recounted['failure_count'], 'Retry recount should clear stale failure count');
financeAsyncJobAssertSame(2, $recounted['processed_count'], 'Retry recount should allow processed count to decrease');
financeAsyncJobAssertSame(20.0, $recounted['progress_percent'], 'Retry recount should allow progress to reflect the new attempt');
$progressed = $service->updateProgress($claimed['job_id'], 'worker-1', [
    'total' => 10,
    'processed' => 3,
    'success' => 2,
    'failure' => 1,
]);

$item = $service->addItem([
    'job_id' => $claimed['job_id'],
    'account_set_id' => 'set-1',
    'item_no' => 1,
    'source_row_no' => 2,
    'source_type' => 'PAYMENT_IMPORT_ROW',
    'source_id' => 'ROW-2',
    'amount' => '1234.56',
    'status' => 'FAILED',
    'error_code' => 'VALIDATION_ERROR',
    'error_message' => '案号不能为空',
]);
financeAsyncJobAssertSame(true, $item['created'], 'Task item should be created');
$itemDuplicate = $service->addItem([
    'job_id' => $claimed['job_id'],
    'item_no' => 99,
    'source_type' => 'PAYMENT_IMPORT_ROW',
    'source_id' => 'ROW-2',
]);
financeAsyncJobAssertSame(false, $itemDuplicate['created'], 'Task item source should be idempotent');
$errors = $service->listErrors($claimed['job_id'], 'set-1', 1, 20);
financeAsyncJobAssertSame(1, $errors['total'], 'Error list should only return failed items');
financeAsyncJobAssertSame('案号不能为空', $errors['items'][0]['error_message'], 'Error list should expose row-level message');

$itemRowsBeforeInvalidBatch = count($store->rows['item']);
try {
    $service->batchAddItems([
        ['item_no' => 2, 'source_type' => 'PAYMENT', 'source_id' => 'VALID-BEFORE-ERROR', 'amount' => '10.00'],
        ['item_no' => 3, 'source_type' => 'PAYMENT', 'source_id' => 'INVALID-AMOUNT', 'amount' => '10.001'],
    ], $claimed['job_id'], 'set-1', 'worker-1');
    financeAsyncJobFail('Invalid batch item should reject the whole preflight');
} catch (InvalidArgumentException $e) {
    financeAsyncJobAssertSame('任务明细金额格式不正确', $e->getMessage(), 'Batch preflight should report invalid amount');
}
financeAsyncJobAssertSame($itemRowsBeforeInvalidBatch, count($store->rows['item']), 'Batch validation must finish before first insertAll');

$batchInput = [];
for ($index = 1; $index <= 405; $index++) {
    $batchInput[] = [
        'item_no' => $index + 1,
        'source_row_no' => $index + 2,
        'source_type' => 'PAYMENT',
        'source_id' => 'BATCH-' . str_pad((string)$index, 4, '0', STR_PAD_LEFT),
        'amount' => '10.00',
    ];
}
$batchInput[] = $batchInput[0];
$insertAllBefore = count($store->insertAllCalls);
$batchAdded = $service->batchAddItems($batchInput, $claimed['job_id'], 'set-1', 'worker-1');
financeAsyncJobAssertSame(405, $batchAdded['created'], 'Batch add should insert each unique source once');
financeAsyncJobAssertSame(1, $batchAdded['skipped'], 'Batch add should skip duplicate sources in the same request');
financeAsyncJobAssertSame(406, $batchAdded['total'], 'Batch add should report original request size');
$batchCalls = array_slice($store->insertAllCalls, $insertAllBefore);
financeAsyncJobAssertSame([200, 200, 5], array_column($batchCalls, 'count'), 'Batch add should use 200-row insertAll chunks');
$insertAllAfterFirst = count($store->insertAllCalls);
$batchRetried = $service->batchAddItems($batchInput, $claimed['job_id'], 'set-1', 'worker-1');
financeAsyncJobAssertSame(0, $batchRetried['created'], 'Retry should not reinsert existing sources');
financeAsyncJobAssertSame(406, $batchRetried['skipped'], 'Retry should report database and request duplicates as skipped');
financeAsyncJobAssertSame($insertAllAfterFirst, count($store->insertAllCalls), 'Idempotent retry should issue no insertAll');
$allPaymentItems = $service->allItems($claimed['job_id'], 'set-1', 'PAYMENT');
financeAsyncJobAssertSame(405, count($allPaymentItems), 'Worker should load frozen source items without paginated count queries');
financeAsyncJobAssertSame('BATCH-0001', $allPaymentItems[0]['source_id'], 'All-item loading should preserve item order');

try {
    $service->batchAddItems([[
        'item_no' => 999,
        'source_type' => 'PAYMENT',
        'source_id' => 'FOREIGN-WORKER',
    ]], $claimed['job_id'], 'set-1', 'worker-other');
    financeAsyncJobFail('Foreign worker must not batch add task items');
} catch (RuntimeException $e) {
    financeAsyncJobAssertSame(
        '任务执行权或账套范围已失效，禁止批量更新任务明细',
        $e->getMessage(),
        'Batch add should enforce worker ownership'
    );
}

$batchItemIds = array_column(array_slice($batchAdded['items'], 0, 3), 'item_id');
$updatesBefore = count($store->updateCalls);
$batchRunning = $service->batchUpdateItems(
    $batchItemIds,
    $claimed['job_id'],
    'set-1',
    'worker-1',
    ['status' => 'RUNNING', 'group_key' => 'voucher-group-1']
);
financeAsyncJobAssertSame(3, $batchRunning['affected'], 'Batch update should update the complete ID group');
financeAsyncJobAssertSame($updatesBefore + 1, count($store->updateCalls), 'Batch update should use one shared UPDATE statement');

$batchSucceeded = $service->batchUpdateItems(
    $batchItemIds,
    $claimed['job_id'],
    'set-1',
    'worker-1',
    [
        'status' => 'SUCCEEDED',
        'voucher_id' => 'voucher-batch-1',
        'voucher_no' => 18,
        'voucher_period' => '2026-08',
        'result' => ['message' => '凭证已生成'],
    ]
);
financeAsyncJobAssertSame(3, $batchSucceeded['affected'], 'Batch voucher result should update all grouped items');
foreach ($store->rows['item'] as $storedItem) {
    if (!in_array($storedItem['item_id'], $batchItemIds, true)) {
        continue;
    }
    financeAsyncJobAssertSame('SUCCEEDED', $storedItem['status'], 'Batch item should persist common success status');
    financeAsyncJobAssertSame('voucher-batch-1', $storedItem['voucher_id'], 'Batch item should persist common voucher');
    financeAsyncJobAssertSame(2, $storedItem['version'], 'Each batch update should increment item version');
}

$updatesBeforeForeign = count($store->updateCalls);
try {
    $service->batchUpdateItems($batchItemIds, $claimed['job_id'], 'set-other', 'worker-1', ['status' => 'FAILED']);
    financeAsyncJobFail('Cross-account worker must not batch update task items');
} catch (RuntimeException $e) {
    financeAsyncJobAssertSame(
        '任务执行权或账套范围已失效，禁止批量更新任务明细',
        $e->getMessage(),
        'Batch update should enforce account scope and worker fence'
    );
}
financeAsyncJobAssertSame($updatesBeforeForeign, count($store->updateCalls), 'Rejected batch update must not issue item UPDATE');

$completed = $service->complete($claimed['job_id'], 'worker-1', [
    'message' => '完成但有3行失败',
    'total_count' => 10,
    'processed_count' => 10,
    'success_count' => 7,
    'failure_count' => 3,
], 'PARTIAL_FAILED');
financeAsyncJobAssertSame('PARTIAL_FAILED', $completed['status'], 'Completion should support partial failure');
financeAsyncJobAssertSame(100.0, $completed['progress_percent'], 'Terminal job should report 100 percent');
financeAsyncJobAssertSame($completed['total_count'], $completed['processed_count'], 'Completion should close processed count to total');
financeAsyncJobAssertSame(7, $completed['success_count'], 'Partial completion should take current result success count');
financeAsyncJobAssertSame(3, $completed['failure_count'], 'Partial completion should take current result failure count');
try {
    $service->batchUpdateItems($batchItemIds, $claimed['job_id'], 'set-1', 'worker-1', ['status' => 'FAILED']);
    financeAsyncJobFail('Terminal task must reject later batch item updates');
} catch (RuntimeException $e) {
    financeAsyncJobAssertSame(
        '任务执行权或账套范围已失效，禁止批量更新任务明细',
        $e->getMessage(),
        'Batch update should require RUNNING task status'
    );
}

// Stale takeover is opt-in and invalidates the previous worker token.
$staleCreated = $service->create(array_merge($base, [
    'job_type' => 'CASE_FUND_PAYMENT_VOUCHER',
    'client_request_id' => 'request-stale',
]));
$staleClaim = $service->claimNext('worker-old');
$store->advance(601);
$takenOver = $service->claimNext('worker-new', [], 600);
financeAsyncJobAssertSame($staleCreated['job']['job_id'], $takenOver['job_id'], 'Stale claim should take over the same job');
financeAsyncJobAssertSame('worker-new', $takenOver['worker_token'], 'Stale claim should replace worker token');
financeAsyncJobAssertSame(2, $takenOver['attempt_count'], 'Stale takeover should increment attempt count');
try {
    $service->heartbeat($staleClaim['job_id'], 'worker-old');
    financeAsyncJobFail('Old worker should lose ownership after takeover');
} catch (RuntimeException $e) {
    financeAsyncJobAssertSame('任务执行权已失效，禁止继续更新', $e->getMessage(), 'Stale worker heartbeat must be rejected');
}
$service->updateProgress($takenOver['job_id'], 'worker-new', [
    'replace_counts' => true,
    'total_count' => 10,
    'processed_count' => 10,
    'success_count' => 8,
    'failure_count' => 2,
]);
$retrySucceeded = $service->complete($takenOver['job_id'], 'worker-new', [
    'message' => '接管重试完成',
    'success_count' => 10,
], 'SUCCEEDED');
financeAsyncJobAssertSame('SUCCEEDED', $retrySucceeded['status'], 'Successful retry result should override stale failure outcome');
financeAsyncJobAssertSame(0, $retrySucceeded['failure_count'], 'Successful completion should clear stale failure count');
financeAsyncJobAssertSame(10, $retrySucceeded['success_count'], 'Successful completion should use current retry success count');

// Atomic completion must use the caller's existing business transaction instead of
// opening a second service transaction. The worker later confirms this durable row.
$atomicServiceCreated = $service->create(array_merge($base, [
    'job_type' => 'ATOMIC_SERVICE',
    'client_request_id' => 'atomic-service',
]));
$atomicServiceClaim = $service->claimNext('worker-atomic-service', ['ATOMIC_SERVICE']);
$transactionCallsBeforeAtomicComplete = $store->transactionCalls;
$atomicServiceCompleted = $service->completeInBusinessTransaction(
    $atomicServiceClaim['job_id'],
    'worker-atomic-service',
    [
        'code' => 20000,
        'message' => '银行对账完成',
        'total' => 4,
        'processed_count' => 4,
        'success_count' => 4,
        'failure_count' => 0,
    ],
    'SUCCEEDED'
);
financeAsyncJobAssertSame(
    $transactionCallsBeforeAtomicComplete,
    $store->transactionCalls,
    'Atomic completion must not open an inner service transaction'
);
financeAsyncJobAssertSame('SUCCEEDED', $atomicServiceCompleted['status'], 'Atomic completion should persist success');
financeAsyncJobAssertSame(4, $atomicServiceCompleted['success_count'], 'Atomic completion should persist final counters');
$atomicServiceConfirmed = $service->assertCompletedByWorker(
    $atomicServiceClaim['job_id'],
    'worker-atomic-service',
    'SUCCEEDED'
);
financeAsyncJobAssertSame(
    $atomicServiceCompleted['job_id'],
    $atomicServiceConfirmed['job_id'],
    'Worker should confirm the exact atomically completed task'
);
financeAsyncJobAssertSame(
    null,
    $service->completedByWorker($atomicServiceClaim['job_id'], 'worker-other'),
    'Foreign worker must not adopt another worker atomic completion'
);

// Worker protocol: a valid atomic marker skips the second complete(); an exception
// after durable commit is recovered as success; a forged marker without durable
// completion is rejected and falls back to FAILED.
$atomicWorkerStore = new FinanceAsyncJobMemoryStore();
$atomicWorkerService = financeAsyncJobService($atomicWorkerStore, FinanceAsyncJobCountingService::class);
foreach ([
    ['job_type' => 'WORKER_ATOMIC_MARKER', 'client_request_id' => 'worker-atomic-marker'],
    ['job_type' => 'WORKER_ATOMIC_POST_COMMIT', 'client_request_id' => 'worker-atomic-post-commit'],
] as $atomicWorkerJob) {
    $atomicWorkerService->create(array_merge($base, $atomicWorkerJob));
}
$atomicWorker = new FinanceAsyncJobWorker($atomicWorkerService, function (array $job) use ($atomicWorkerService) {
    return function (array $runningJob) use ($job, $atomicWorkerService) {
        $result = [
            'code' => 20000,
            'message' => '原子任务完成',
            'total' => 2,
            'processed_count' => 2,
            'success_count' => 2,
            'failure_count' => 0,
        ];
        $atomicWorkerService->completeInBusinessTransaction(
            $runningJob['job_id'],
            $runningJob['worker_token'],
            $result,
            'SUCCEEDED'
        );
        if ($job['job_type'] === 'WORKER_ATOMIC_POST_COMMIT') {
            // 模拟业务事务和任务终态提交成功后，handler 在返回前异常退出。
            throw new RuntimeException('模拟原子提交后的进程异常');
        }
        $result[FinanceAsyncJobService::ATOMIC_COMPLETION_KEY] = 'SUCCEEDED';
        return $result;
    };
});
$atomicWorkerSummary = $atomicWorker->run([
    'worker_token' => 'worker-atomic',
    'job_types' => ['WORKER_ATOMIC_MARKER', 'WORKER_ATOMIC_POST_COMMIT'],
    'idle_grace_milliseconds' => 0,
]);
financeAsyncJobAssertSame(2, $atomicWorkerSummary['claimed'], 'Atomic worker should claim both test jobs');
financeAsyncJobAssertSame(2, $atomicWorkerSummary['succeeded'], 'Atomic marker and post-commit exception should both remain successful');
financeAsyncJobAssertSame(0, $atomicWorkerSummary['failed'], 'Committed atomic jobs must not be reversed to failed');
financeAsyncJobAssertSame(0, $atomicWorkerService->completeCalls, 'Atomic marker must not trigger a second worker complete call');
foreach (['WORKER_ATOMIC_MARKER', 'WORKER_ATOMIC_POST_COMMIT'] as $jobType) {
    $atomicStored = $atomicWorkerService->listJobs(['account_set_id' => 'set-1', 'job_type' => $jobType]);
    financeAsyncJobAssertSame('SUCCEEDED', $atomicStored['items'][0]['status'], 'Atomic worker task should remain durable success');
    financeAsyncJobAssertSame(
        false,
        array_key_exists(FinanceAsyncJobService::ATOMIC_COMPLETION_KEY, $atomicStored['items'][0]['result']),
        'Internal atomic completion marker must not leak into persisted task result'
    );
}

$atomicWorkerService->create(array_merge($base, [
    'job_type' => 'WORKER_FALSE_ATOMIC_MARKER',
    'client_request_id' => 'worker-false-atomic-marker',
]));
$falseAtomicWorker = new FinanceAsyncJobWorker($atomicWorkerService, function () {
    return function () {
        return [
            'code' => 20000,
            'message' => '未真正完成',
            FinanceAsyncJobService::ATOMIC_COMPLETION_KEY => 'SUCCEEDED',
        ];
    };
});
$falseAtomicSummary = $falseAtomicWorker->run([
    'worker_token' => 'worker-false-atomic',
    'job_types' => ['WORKER_FALSE_ATOMIC_MARKER'],
    'idle_grace_milliseconds' => 0,
]);
financeAsyncJobAssertSame(1, $falseAtomicSummary['failed'], 'Uncommitted atomic marker must be rejected');
$falseAtomicStored = $atomicWorkerService->findByClientRequestId('set-1', 'WORKER_FALSE_ATOMIC_MARKER', 'worker-false-atomic-marker');
financeAsyncJobAssertSame('FAILED', $falseAtomicStored['status'], 'False atomic marker should fall back to failed terminal state');

// Worker drains the queue, persists handler failures, and injects callbacks.
$service->create(array_merge($base, [
    'job_type' => 'WORKER_SUCCESS',
    'client_request_id' => 'worker-success',
]));
$service->create(array_merge($base, [
    'job_type' => 'WORKER_FAIL',
    'client_request_id' => 'worker-fail',
]));
$resolver = function (array $job) {
    return function (array $runningJob) use ($job) {
        if ($job['job_type'] === 'WORKER_FAIL') {
            throw new RuntimeException('模拟后台业务失败');
        }
        $runningJob['_progress_callback']([
            'stage' => 'GENERATING',
            'total' => 2,
            'processed' => 1,
            'success' => 1,
        ]);
        $runningJob['_heartbeat_callback']('FINALIZING', '正在收尾');
        return ['code' => 20000, 'message' => '处理成功', 'data' => ['count' => 2]];
    };
};
$worker = new FinanceAsyncJobWorker($service, $resolver);
$summary = $worker->run(['worker_token' => 'worker-drain', 'idle_grace_milliseconds' => 0]);
financeAsyncJobAssertSame(2, $summary['claimed'], 'Default worker mode should drain queued jobs');
financeAsyncJobAssertSame(1, $summary['succeeded'], 'Worker should complete successful handler');
financeAsyncJobAssertSame(1, $summary['failed'], 'Worker should persist thrown handler failure');
$failedJob = $service->findByClientRequestId('set-1', 'WORKER_FAIL', 'worker-fail');
financeAsyncJobAssertSame('FAILED', $failedJob['status'], 'Handler exception should persist failed status');
financeAsyncJobAssertSame('模拟后台业务失败', $failedJob['error_message'], 'Handler exception should persist its message');
financeAsyncJobAssertSame(100.0, $failedJob['progress_percent'], 'Failed terminal job should report 100 percent');
financeAsyncJobAssertSame(1, $failedJob['failure_count'], 'Early handler failures should still report one failed unit');

// --once equivalent should claim at most one item and leave the next queued.
$service->create(array_merge($base, ['job_type' => 'ONCE', 'client_request_id' => 'once-1']));
$service->create(array_merge($base, ['job_type' => 'ONCE', 'client_request_id' => 'once-2']));
$onceWorker = new FinanceAsyncJobWorker($service, function () {
    return function () {
        return ['code' => 20000, 'message' => 'OK'];
    };
});
$onceSummary = $onceWorker->run(['worker_token' => 'worker-once', 'once' => true, 'job_types' => ['ONCE']]);
financeAsyncJobAssertSame(1, $onceSummary['claimed'], 'Once mode should claim only one task');
$queuedOnce = $service->listJobs(['account_set_id' => 'set-1', 'job_type' => 'ONCE', 'status' => 'QUEUED']);
financeAsyncJobAssertSame(1, $queuedOnce['total'], 'Once mode should leave the next task queued');

$launcher = new FinanceAsyncJobLauncher(['script_path' => __DIR__ . '/missing-worker.php']);
$launch = $launcher->launch();
financeAsyncJobAssertSame(false, $launch['ok'], 'Missing worker script should not pretend to launch');
if (strpos($launch['launcher_warning'], '任务脚本不存在') === false) {
    financeAsyncJobFail('Launcher warning should explain missing worker script');
}
financeAsyncJobAssertSame(true, (new FinanceAsyncJobLauncher())->isPhpCliBinaryPath('/xampp/php/php.exe'), 'Launcher should accept php.exe');
financeAsyncJobAssertSame(false, (new FinanceAsyncJobLauncher())->isPhpCliBinaryPath('/xampp/php/php-cgi.exe'), 'Launcher should reject php-cgi.exe');

$workerSource = file_get_contents(__DIR__ . '/../scripts/finance_job_worker.php');
foreach (['--once', 'LOCK_EX | LOCK_NB', 'processAsyncJob', "'once' => false"] as $needle) {
    if (strpos($workerSource, $needle) === false) {
        financeAsyncJobFail('Worker source is missing required behavior: ' . $needle);
    }
}

echo 'Finance async job service test passed' . PHP_EOL;
