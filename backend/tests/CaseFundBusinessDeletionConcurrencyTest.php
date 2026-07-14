<?php

require_once __DIR__ . '/../app/finance/service/casefund/persistence/CaseFundBusinessDeletionService.php';

use app\finance\service\casefund\persistence\CaseFundBusinessDeletionService;

class CaseFundDeletionConcurrencyStore
{
    public $rows = [];
    public $deleteResult = 1;
    public $updateResult = 1;
    public $queries = [];
    public $activeVoucher = false;
    public $activeHookCalls = 0;
    public $activeLockObserved = false;

    public function db($table)
    {
        return new CaseFundDeletionConcurrencyQuery($this, $table);
    }
}

class CaseFundDeletionConcurrencyQuery
{
    protected $store;
    protected $table;
    protected $where = [];
    protected $locked = false;

    public function __construct($store, $table)
    {
        $this->store = $store;
        $this->table = $table;
    }

    public function where($field, $operator = null, $value = null)
    {
        if (is_array($field)) {
            $this->where[] = ['array', $field];
        } elseif (func_num_args() === 2) {
            $this->where[] = [$field, '=', $operator];
        } else {
            $this->where[] = [$field, $operator, $value];
        }
        return $this;
    }

    public function field($fields)
    {
        return $this;
    }

    public function lock($lock = true)
    {
        $this->locked = $lock;
        return $this;
    }

    public function select()
    {
        $this->remember('select');
        return $this->store->rows[$this->table] ?? [];
    }

    public function count()
    {
        $this->remember('count');
        return count($this->store->rows[$this->table] ?? []);
    }

    public function delete()
    {
        $this->remember('delete');
        return $this->store->deleteResult;
    }

    public function update($row)
    {
        $this->remember('update', $row);
        return $this->store->updateResult;
    }

    protected function remember($operation, $row = null)
    {
        $this->store->queries[] = [
            'table' => $this->table,
            'operation' => $operation,
            'where' => $this->where,
            'locked' => $this->locked,
            'row' => $row,
        ];
    }
}

function deletionConcurrencyFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function deletionConcurrencyService($store)
{
    $service = null;
    $service = new CaseFundBusinessDeletionService([
        'get_db' => function ($table) use ($store) { return $store->db($table); },
        'account_where' => function () {
            return ['account_set_id' => 'set-1', 'del_flag' => 0];
        },
        'account_set_id' => function () { return 'set-1'; },
        'current_year' => function () { return 2026; },
        'prepare_update' => function ($row) {
            $row['updated_by'] = 'user-1';
            return $row;
        },
        'has_active_generated_voucher' => function () use ($store) {
            $store->activeHookCalls++;
            $lastQuery = end($store->queries);
            $store->activeLockObserved = !empty($lastQuery['locked']);
            return $store->activeVoucher;
        },
        'assert_rows_can_delete' => function ($rows, $label) use (&$service) {
            return $service->assertRowsCanDeleteBusinessData($rows, $label);
        },
        'should_physical_delete' => function ($bizType) use (&$service) {
            return $service->shouldPhysicalDeleteCaseFundBusinessRows($bizType);
        },
    ], 'reconcile', [
        'PAYMENT' => ['payment_id', 'case_no', 'voucher_status'],
    ]);
    return $service;
}

$store = new CaseFundDeletionConcurrencyStore();
$store->rows['business'] = [[
    'payment_id' => 'P-1',
    'case_no' => 'CASE-1',
    'voucher_status' => 'UNGENERATED',
]];
$store->rows['reconcile'] = [];
$service = deletionConcurrencyService($store);
$prepared = $service->prepareBusinessDeletion(
    'business',
    'payment_id',
    ['P-1'],
    'PAYMENT',
    '缴费记录'
);
if (!empty($prepared['error']) || empty($store->queries[0]['locked'])) {
    deletionConcurrencyFail('Deletion validation should lock selected source rows before checking status');
}

$store->rows['business'][0]['voucher_status'] = 'GENERATED';
$store->activeVoucher = true;
$store->queries = [];
$blocked = $service->prepareBusinessDeletion(
    'business',
    'payment_id',
    ['P-1'],
    'PAYMENT',
    '缴费记录'
);
if (strpos((string)($blocked['error'] ?? ''), '原凭证仍有效') === false
    || $store->activeHookCalls !== 1
    || !$store->activeLockObserved
) {
    deletionConcurrencyFail('Locked latest source state should block deletion when the generated voucher remains active');
}
$store->rows['business'][0]['voucher_status'] = 'UNGENERATED';
$store->activeVoucher = false;

$affectedRows = $service->executeBusinessDeletion('business', 'payment_id', ['P-1'], true);
if ($affectedRows !== 1) {
    deletionConcurrencyFail('Hard deletion should return the exact affected row count');
}
$deleteQuery = $store->queries[count($store->queries) - 1];
$deleteScope = $deleteQuery['where'][0][1];
if (($deleteScope['account_set_id'] ?? '') !== 'set-1'
    || ($deleteScope['fiscal_year'] ?? 0) !== 2026
    || ($deleteScope['del_flag'] ?? 1) !== 0
) {
    deletionConcurrencyFail('Deletion execution should preserve account, year and active-row scope');
}

$store->deleteResult = 0;
try {
    $service->executeBusinessDeletion('business', 'payment_id', ['P-1'], true);
    deletionConcurrencyFail('Hard deletion should reject a stale affected row count');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== '案款业务记录状态已变化，请刷新后重试') {
        deletionConcurrencyFail('Hard deletion stale-state error should be explicit');
    }
}

$store->updateResult = 1;
try {
    $service->executeBusinessDeletion('business', 'payment_id', ['P-1', 'P-2'], false);
    deletionConcurrencyFail('Soft deletion should reject a partial affected row count');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== '案款业务记录状态已变化，请刷新后重试') {
        deletionConcurrencyFail('Soft deletion stale-state error should be explicit');
    }
}

$source = file_get_contents(__DIR__ . '/../app/finance/model/CaseFund.php');
$methodStart = strpos($source, 'protected function deleteCaseFundBusinessRows');
$methodEnd = strpos($source, 'protected function shouldPhysicalDeleteCaseFundBusinessRows', $methodStart);
$methodSource = substr($source, $methodStart, $methodEnd - $methodStart);
if (strpos($methodSource, 'Db::startTrans()') > strpos($methodSource, 'prepareBusinessDeletion')) {
    deletionConcurrencyFail('Business deletion must validate locked rows inside the transaction');
}

echo 'Case fund business deletion concurrency test passed' . PHP_EOL;
