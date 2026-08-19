<?php

require_once __DIR__ . '/../app/finance/service/casefund/banking/CaseFundBankingPersistenceService.php';

use app\finance\service\casefund\banking\CaseFundBankingPersistenceService;

class CaseFundBankingPersistenceStore
{
    public $events = [];
    public $insertResults = [];
    public $updateResults = [];
    public $countResults = [];
    public $accountSetExists = true;

    public function db($table)
    {
        return new CaseFundBankingPersistenceQuery($this, $table);
    }

    public function nextResult(&$collection, $table, $default)
    {
        if (!array_key_exists($table, $collection)) {
            return $default;
        }
        if (is_array($collection[$table])) {
            return array_shift($collection[$table]);
        }
        return $collection[$table];
    }
}

class CaseFundBankingPersistenceQuery
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

    public function lock($lock = true)
    {
        $this->locked = $lock;
        return $this;
    }

    public function find()
    {
        $this->remember('find');
        return $this->store->accountSetExists ? ['account_set_id' => 'set-1'] : null;
    }

    public function insert($row)
    {
        $this->remember('insert', $row);
        return $this->store->nextResult($this->store->insertResults, $this->table, 1);
    }

    public function insertAll($rows)
    {
        $this->remember('insertAll', $rows);
        return $this->store->nextResult($this->store->insertResults, $this->table, count($rows));
    }

    public function update($row)
    {
        $this->remember('update', $row);
        return $this->store->nextResult($this->store->updateResults, $this->table, 1);
    }

    public function count()
    {
        $this->remember('count');
        return $this->store->nextResult($this->store->countResults, $this->table, 0);
    }

    protected function remember($operation, $row = null)
    {
        $this->store->events[] = [
            'table' => $this->table,
            'operation' => $operation,
            'where' => $this->where,
            'locked' => $this->locked,
            'row' => $row,
        ];
    }
}

function bankingPersistenceFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function bankingPersistenceService($store, &$createCount = 0, &$updateCount = 0)
{
    return new CaseFundBankingPersistenceService([
        'get_db' => function ($table) use ($store) { return $store->db($table); },
        'account_set_id' => function () { return 'set-1'; },
        'current_year' => function () { return 2026; },
        'prepare_create' => function ($row) use (&$createCount) {
            $createCount++;
            $row['created_by'] = 'user-1';
            $row['del_flag'] = 0;
            return $row;
        },
        'prepare_update' => function ($row) use (&$updateCount) {
            $updateCount++;
            $row['updated_by'] = 'user-1';
            return $row;
        },
    ], [
        'account_set' => 'account_set',
        'statement' => 'statement',
        'reconcile' => 'reconcile',
    ], ['SERIAL_NO', 'SERIAL_NO_DATE']);
}

try {
    new CaseFundBankingPersistenceService([], [], []);
    bankingPersistenceFail('Banking persistence should reject incomplete hooks');
} catch (\InvalidArgumentException $e) {
    if ($e->getMessage() !== '案款银行持久化服务缺少回调：get_db') {
        bankingPersistenceFail('Missing banking persistence hook should be explicit');
    }
}

$store = new CaseFundBankingPersistenceStore();
$createCount = 0;
$updateCount = 0;
$service = bankingPersistenceService($store, $createCount, $updateCount);
$service->lockReconcileScope();
if ($store->events[0]['table'] !== 'account_set'
    || $store->events[0]['operation'] !== 'find'
    || !$store->events[0]['locked']
) {
    bankingPersistenceFail('Automatic reconciliation should lock the current account set scope');
}
$store->accountSetExists = false;
try {
    $service->lockReconcileScope();
    bankingPersistenceFail('Missing account set should reject automatic reconciliation');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== '当前账套不存在或已删除') {
        bankingPersistenceFail('Missing account set error should be explicit');
    }
}
$store->accountSetExists = true;

$store->events = [];
$inserted = $service->insertStatements([
    [
        'statement_id' => 'S-1',
        'account_set_id' => 'untrusted',
        'source_fingerprint' => 'FP-1',
        'transaction_date' => '2026-07-01',
        'fiscal_year' => 2026,
        'period' => '2026-07',
    ],
    [
        'statement_id' => 'S-2',
        'account_set_id' => 'untrusted',
        'source_fingerprint' => 'FP-2',
        'transaction_date' => '2026-07-02',
        'fiscal_year' => 2026,
        'period' => '2026-07',
    ],
]);
if ($inserted !== 2 || $createCount !== 2 || count($store->events) !== 2) {
    bankingPersistenceFail('Imported bank statements should be inserted once in source order');
}
foreach ($store->events as $event) {
    if (($event['row']['account_set_id'] ?? '') !== 'set-1') {
        bankingPersistenceFail('Bank statement insert should use trusted account scope');
    }
}

$store->events = [];
try {
    $service->insertStatements([
        [
            'statement_id' => 'S-VALID',
            'transaction_date' => '2026-07-01',
            'fiscal_year' => 2026,
            'period' => '2026-07',
        ],
        [
            'statement_id' => 'S-WRONG-PERIOD',
            'transaction_date' => '2026-08-01',
            'fiscal_year' => 2026,
            'period' => '2026-07',
        ],
    ]);
    bankingPersistenceFail('Invalid statement period should reject the complete batch');
} catch (\InvalidArgumentException $e) {
    if ($e->getMessage() !== '银行流水第2行年度期间与交易日期不一致' || !empty($store->events)) {
        bankingPersistenceFail('Statement period validation should fail before the first insert');
    }
}

$store->events = [];
try {
    $service->insertStatements([
        ['statement_id' => 'S-DUP'],
        ['statement_id' => 'S-DUP'],
    ]);
    bankingPersistenceFail('Duplicate statement ids should be rejected');
} catch (\InvalidArgumentException $e) {
    if ($e->getMessage() !== '银行流水ID重复：S-DUP' || !empty($store->events)) {
        bankingPersistenceFail('Invalid statement batch should fail before the first insert');
    }
}

$store->insertResults['statement'] = 0;
try {
    $service->insertStatements([[
        'statement_id' => 'S-FAIL',
        'transaction_date' => '2026-07-03',
        'fiscal_year' => 2026,
        'period' => '2026-07',
    ]]);
    bankingPersistenceFail('Zero-row statement insert should fail');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== '银行流水写入失败：S-FAIL') {
        bankingPersistenceFail('Statement insert failure should identify its source row');
    }
}
$store->insertResults['statement'] = 1;

$store->events = [];
$store->updateResults['statement'] = 2;
$deleted = $service->deleteStatements(['S-1', 'S-2']);
if ($deleted !== 2 || count($store->events) !== 1) {
    bankingPersistenceFail('Bank statement deletion should update the complete selected scope once');
}
$deleteScope = $store->events[0]['where'][0][1];
if (($deleteScope['account_set_id'] ?? '') !== 'set-1'
    || ($deleteScope['fiscal_year'] ?? 0) !== 2026
    || ($deleteScope['reconcile_status'] ?? '') !== 'UNMATCHED'
    || ($deleteScope['del_flag'] ?? 1) !== 0
) {
    bankingPersistenceFail('Bank statement deletion should keep account, year, status and active-row guards');
}
$store->updateResults['statement'] = 1;
try {
    $service->deleteStatements(['S-1', 'S-2']);
    bankingPersistenceFail('Partial bank statement deletion should fail');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== '银行对账单流水状态已变化，请刷新后重试') {
        bankingPersistenceFail('Partial bank statement deletion should report stale state');
    }
}

$store->events = [];
$store->updateResults['statement'] = 1;
$store->countResults['reconcile'] = 1;
$store->updateResults['reconcile'] = 1;
$service->persistReconcileResult(
    '2026-07-01',
    '2026-07-31',
    ['S-1', 'S-2'],
    [[
        'reconcile_id' => 'R-1',
        'account_set_id' => 'untrusted',
        'fiscal_year' => 2025,
        'statement_id' => 'S-1',
        'match_status' => 'MATCHED',
        'match_rule' => 'SERIAL_NO',
    ]],
    ['S-1' => 'MATCHED', 'S-2' => 'BANK_ONLY'],
    ['S-1' => 'UNMATCHED', 'S-2' => 'UNMATCHED']
);
$operations = array_map(function ($event) {
    return $event['table'] . ':' . $event['operation'];
}, $store->events);
if ($operations !== [
    'reconcile:count',
    'reconcile:update',
    'reconcile:insertAll',
    'statement:update',
    'statement:update',
]) {
    bankingPersistenceFail('Reconcile persistence should count, replace, batch insert and then update grouped statuses in order');
}
$reconcileInsert = $store->events[2]['row'][0];
if (($reconcileInsert['account_set_id'] ?? '') !== 'set-1'
    || ($reconcileInsert['fiscal_year'] ?? 0) !== 2026
) {
    bankingPersistenceFail('Reconcile rows should use trusted account and year scope');
}
$softDeleteWhere = $store->events[0]['where'];
if ($softDeleteWhere[1] !== ['match_rule', 'in', ['SERIAL_NO', 'SERIAL_NO_DATE']]
    || $softDeleteWhere[2] !== ['reconcile_date', '>=', '2026-07-01']
    || $softDeleteWhere[3] !== ['reconcile_date', '<=', '2026-07-31']
) {
    bankingPersistenceFail('Reconcile replacement should preserve rule and date scope');
}

$store->events = [];
try {
    $service->persistReconcileResult(
        '',
        '',
        ['S-1', 'S-2'],
        [],
        ['S-1' => 'MATCHED'],
        ['S-1' => 'UNMATCHED', 'S-2' => 'UNMATCHED']
    );
    bankingPersistenceFail('Incomplete statement statuses should be rejected');
} catch (\InvalidArgumentException $e) {
    if ($e->getMessage() !== '本次对账银行流水状态回写不完整' || !empty($store->events)) {
        bankingPersistenceFail('Incomplete statuses should fail before replacing old results');
    }
}

$store->events = [];
$store->countResults['reconcile'] = 2;
$store->updateResults['reconcile'] = 1;
try {
    $service->persistReconcileResult('', '', [], [], [], []);
    bankingPersistenceFail('Partial old reconcile cleanup should fail');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== '原银行对账结果状态已变化，请刷新后重试') {
        bankingPersistenceFail('Partial old reconcile cleanup should report stale state');
    }
}

$store->events = [];
$store->countResults['reconcile'] = 0;
$store->updateResults['reconcile'] = 0;
$store->updateResults['statement'] = 0;
$store->countResults['statement'] = 1;
$service->persistReconcileResult(
    '',
    '',
    ['S-SAME'],
    [],
    ['S-SAME' => 'BANK_ONLY'],
    ['S-SAME' => 'BANK_ONLY']
);

$store->events = [];
$store->updateResults['statement'] = 0;
try {
    $service->persistReconcileResult(
        '',
        '',
        ['S-CHANGED'],
        [],
        ['S-CHANGED' => 'MATCHED'],
        ['S-CHANGED' => 'UNMATCHED']
    );
    bankingPersistenceFail('Missing changed-status update should fail');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== '银行流水对账状态写入失败：S-CHANGED') {
        bankingPersistenceFail('Changed-status update failure should identify its statement');
    }
}

$store->events = [];
$store->countResults['reconcile'] = 0;
$store->updateResults['reconcile'] = 0;
$store->updateResults['statement'] = 3;
$service->persistReconcileResult(
    '2026-07-01',
    '2026-07-31',
    ['S-GROUP-1', 'S-GROUP-2', 'S-GROUP-3'],
    [],
    [
        'S-GROUP-1' => 'MATCHED',
        'S-GROUP-2' => 'MATCHED',
        'S-GROUP-3' => 'MATCHED',
    ],
    [
        'S-GROUP-1' => 'UNMATCHED',
        'S-GROUP-2' => 'UNMATCHED',
        'S-GROUP-3' => 'UNMATCHED',
    ]
);
$statementUpdates = array_values(array_filter($store->events, function ($event) {
    return $event['table'] === 'statement' && $event['operation'] === 'update';
}));
if (count($statementUpdates) !== 1) {
    bankingPersistenceFail('Same statement status transition should use one grouped update');
}
if ($statementUpdates[0]['where'][0][1]['reconcile_status'] !== 'UNMATCHED'
    || $statementUpdates[0]['where'][1] !== [
        'statement_id',
        'in',
        ['S-GROUP-1', 'S-GROUP-2', 'S-GROUP-3'],
    ]
) {
    bankingPersistenceFail('Grouped statement update should fence the old status and exact statement ids');
}

$store->events = [];
$store->updateResults['statement'] = 2;
try {
    $service->persistReconcileResult(
        '',
        '',
        ['S-PARTIAL-1', 'S-PARTIAL-2', 'S-PARTIAL-3'],
        [],
        [
            'S-PARTIAL-1' => 'MATCHED',
            'S-PARTIAL-2' => 'MATCHED',
            'S-PARTIAL-3' => 'MATCHED',
        ],
        [
            'S-PARTIAL-1' => 'UNMATCHED',
            'S-PARTIAL-2' => 'UNMATCHED',
            'S-PARTIAL-3' => 'UNMATCHED',
        ]
    );
    bankingPersistenceFail('Partial grouped statement status update should fail');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== '银行流水对账状态写入失败：S-PARTIAL-1') {
        bankingPersistenceFail('Partial grouped statement update should identify the failed scope');
    }
}

$store->events = [];
try {
    $service->persistReconcileResult(
        '',
        '',
        ['S-1'],
        [],
        ['S-2' => 'MATCHED'],
        ['S-1' => 'UNMATCHED']
    );
    bankingPersistenceFail('Out-of-scope statement status should be rejected');
} catch (\InvalidArgumentException $e) {
    if ($e->getMessage() !== '银行流水状态回写超出本次对账范围：S-2' || !empty($store->events)) {
        bankingPersistenceFail('Invalid reconcile payload should fail before replacing old results');
    }
}

$store->events = [];
$store->insertResults['reconcile'] = 0;
$store->countResults['reconcile'] = 0;
$store->updateResults['reconcile'] = 0;
try {
    $service->persistReconcileResult('', '', [], [[
        'reconcile_id' => 'R-FAIL',
        'match_status' => 'BIZ_ONLY',
        'match_rule' => 'SERIAL_NO_DATE',
    ]], [], []);
    bankingPersistenceFail('Zero-row reconcile insert should fail');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== '银行对账结果写入失败：R-FAIL') {
        bankingPersistenceFail('Reconcile insert failure should identify its row');
    }
}

$store->events = [];
$store->insertResults['reconcile'] = 1;
$store->countResults['reconcile'] = 0;
$store->updateResults['reconcile'] = 0;
try {
    $service->persistReconcileResult('', '', [], [
        ['reconcile_id' => 'R-FIRST', 'match_status' => 'BIZ_ONLY', 'match_rule' => 'SERIAL_NO_DATE'],
        ['reconcile_id' => 'R-SECOND', 'match_status' => 'BIZ_ONLY', 'match_rule' => 'SERIAL_NO_DATE'],
    ], [], []);
    bankingPersistenceFail('Second reconcile insert failure should propagate');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== '银行对账结果写入失败：R-FIRST...R-SECOND') {
        bankingPersistenceFail('Partial batch reconcile insert failure should identify its row range');
    }
}

$store->events = [];
$store->insertResults['reconcile'] = [200, 200, 1];
$store->countResults['reconcile'] = 0;
$store->updateResults['reconcile'] = 0;
$bulkRows = [];
for ($index = 1; $index <= 401; $index++) {
    $bulkRows[] = [
        'reconcile_id' => 'R-BULK-' . $index,
        'match_status' => 'BIZ_ONLY',
        'match_rule' => 'SERIAL_NO_DATE',
    ];
}
$service->persistReconcileResult('', '', [], $bulkRows, [], []);
$bulkInsertEvents = array_values(array_filter($store->events, function ($event) {
    return $event['table'] === 'reconcile' && $event['operation'] === 'insertAll';
}));
if (array_map(function ($event) {
    return count($event['row']);
}, $bulkInsertEvents) !== [200, 200, 1]) {
    bankingPersistenceFail('Reconcile result insertAll should use bounded 200-row batches');
}
unset($bulkRows);

$serviceSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/banking/CaseFundBankingPersistenceService.php');
foreach (['Db::startTrans', 'Db::commit', 'Db::rollback', 'logAudit'] as $forbidden) {
    if (strpos($serviceSource, $forbidden) !== false) {
        bankingPersistenceFail('Banking persistence should not own transaction or audit: ' . $forbidden);
    }
}

$modelSource = file_get_contents(__DIR__ . '/../app/finance/model/CaseFund.php');
foreach (['insertStatements', 'deleteStatements', 'lockReconcileScope', 'persistReconcileResult'] as $method) {
    if (strpos($modelSource, 'bankingPersistenceService()->' . $method) === false) {
        bankingPersistenceFail('CaseFund should delegate banking write boundary: ' . $method);
    }
}
$runStart = strpos($modelSource, 'protected function executeBankReconcile');
$runEnd = strpos($modelSource, 'public function bankReconcileList', $runStart);
$runSource = substr($modelSource, $runStart, $runEnd - $runStart);
$runOrder = [
    'Db::startTrans()',
    'lockReconcileScope',
    'prefetchReconcileScope',
    'bankReconcileMatcher()->matchPrefetched',
    'persistReconcileResult',
    "'CASE_FUND_BANK_RECONCILE'",
    'Db::commit()',
];
$position = -1;
foreach ($runOrder as $needle) {
    $next = strpos($runSource, $needle);
    if ($next === false || $next <= $position) {
        bankingPersistenceFail('Bank reconcile orchestration order is invalid at: ' . $needle);
    }
    $position = $next;
}

$deleteStart = strpos($modelSource, 'public function bankStatementDelete');
$deleteEnd = strpos($modelSource, 'public function bankReconcileRun', $deleteStart);
$deleteSource = substr($modelSource, $deleteStart, $deleteEnd - $deleteStart);
if (strpos($deleteSource, 'Db::startTrans()') > strpos($deleteSource, 'bankStatementDeletionService()->prepare')) {
    bankingPersistenceFail('Bank statement deletion must lock and revalidate inside the transaction');
}

echo 'Case fund banking persistence service test passed' . PHP_EOL;
