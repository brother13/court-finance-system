<?php

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\service\casefund\banking\CaseFundBankReconcileRepository;

class CaseFundBankQueryStore
{
    public $queries = [];
    public $counts = [];
    public $sums = [];
    public $rows = [];
    public $groupRows = [];
    public $aggregateRows = [];

    public function db($table)
    {
        $index = count($this->queries);
        $this->queries[$index] = [
            'table' => $table,
            'where' => [],
            'field' => null,
            'order' => null,
            'page' => null,
            'group' => null,
            'lock' => false,
            'operation' => null,
        ];
        return new CaseFundBankQueryFake($this, $index);
    }
}

class CaseFundBankQueryFake
{
    private $store;
    private $index;

    public function __construct($store, $index)
    {
        $this->store = $store;
        $this->index = $index;
    }

    public function where($field, $operator = null, $value = null)
    {
        if (is_array($field)) {
            $this->store->queries[$this->index]['where'][] = ['array', $field];
        } elseif (func_num_args() === 2) {
            $this->store->queries[$this->index]['where'][] = [$field, '=', $operator];
        } else {
            $this->store->queries[$this->index]['where'][] = [$field, $operator, $value];
        }
        return $this;
    }

    public function field($fields)
    {
        $this->store->queries[$this->index]['field'] = $fields;
        return $this;
    }

    public function order($order)
    {
        $this->store->queries[$this->index]['order'] = $order;
        return $this;
    }

    public function page($page, $pageSize)
    {
        $this->store->queries[$this->index]['page'] = [$page, $pageSize];
        return $this;
    }

    public function group($group)
    {
        $this->store->queries[$this->index]['group'] = $group;
        return $this;
    }

    public function lock($lock = true)
    {
        $this->store->queries[$this->index]['lock'] = $lock;
        return $this;
    }

    public function count()
    {
        $this->store->queries[$this->index]['operation'] = 'count';
        $table = $this->store->queries[$this->index]['table'];
        return $this->store->counts[$table] ?? 0;
    }

    public function find()
    {
        $this->store->queries[$this->index]['operation'] = 'find';
        $table = $this->store->queries[$this->index]['table'];
        return $this->store->aggregateRows[$table] ?? null;
    }

    public function sum($field)
    {
        $this->store->queries[$this->index]['operation'] = 'sum:' . $field;
        $table = $this->store->queries[$this->index]['table'];
        return $this->store->sums[$table][$field] ?? 0;
    }

    public function select()
    {
        $this->store->queries[$this->index]['operation'] = 'select';
        $query = $this->store->queries[$this->index];
        if ($query['group'] !== null) {
            return $this->store->groupRows[$query['table']] ?? [];
        }
        return $this->store->rows[$query['table']] ?? [];
    }
}

function bankQueryAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            $message
            . '; expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
            . PHP_EOL
        );
        exit(1);
    }
}

$hooks = [
    'get_db' => function () {
        return null;
    },
    'account_set_id' => function () {
        return 'account-bank-query';
    },
    'current_year' => function () {
        return 2026;
    },
];
$tables = [
    'payment' => 'payment',
    'refund' => 'refund',
    'unknown_payment' => 'unknown_payment',
    'statement' => 'statement',
    'reconcile' => 'reconcile',
];

try {
    new CaseFundBankReconcileRepository($hooks, $tables, []);
    fwrite(STDERR, 'Bank reconcile repository should reject an empty field map' . PHP_EOL);
    exit(1);
} catch (\InvalidArgumentException $e) {
    bankQueryAssertSame(
        '案款银行对账仓储缺少字段清单：payment',
        $e->getMessage(),
        'Bank reconcile repository should report the first missing field list'
    );
}

$store = new CaseFundBankQueryStore();
$store->counts = ['statement' => 6, 'reconcile' => 4];
$store->sums = ['statement' => ['debit_amount' => '12.00', 'credit_amount' => '34.00']];
$store->aggregateRows = [
    'statement' => ['total' => '6', 'debit_amount' => '12.00', 'credit_amount' => '34.00'],
];
$store->rows = [
    'statement' => [['statement_id' => 'S-1']],
    'reconcile' => [[
        'reconcile_id' => 'RC-1',
        'statement_id' => 'S-1',
        'biz_type' => 'PAYMENT',
        'biz_id' => 'P-1',
    ]],
];
$store->groupRows = [
    'reconcile' => [
        ['match_status' => 'MATCHED', 'total' => '3'],
        ['match_status' => 'BANK_ONLY', 'total' => '1'],
    ],
];

$repository = new CaseFundBankReconcileRepository([
    'get_db' => function ($table) use ($store) {
        return $store->db($table);
    },
    'account_set_id' => $hooks['account_set_id'],
    'current_year' => $hooks['current_year'],
], $tables, [
    'payment' => ['payment_id'],
    'refund' => ['refund_id'],
    'unknown_payment' => ['unknown_payment_id'],
    'statement' => ['statement_id'],
    'reconcile' => ['reconcile_id'],
]);

$statementList = $repository->bankStatementList([
    'period' => '2026-07',
    'bank_code' => 'CCB',
    'direction' => 'CREDIT',
    'reconcile_status' => 'MATCHED',
    'date_start' => '2026-07-01',
    'date_end' => '2026-07-31',
    'keyword' => '流水-001',
], 2, 25);
bankQueryAssertSame([
    'items' => [[
        'statement_id' => 'S-1',
        'reconcile_items' => [[
            'reconcile_id' => 'RC-1',
            'statement_id' => 'S-1',
            'biz_type' => 'PAYMENT',
            'biz_id' => 'P-1',
        ]],
    ]],
    'total' => 6,
    'debit_amount' => '12.00',
    'credit_amount' => '34.00',
], $statementList, 'Bank statement list should preserve totals and attach active reconcile associations');
bankQueryAssertSame(3, count($store->queries), 'Bank statement list should use one page query, one aggregate query and one association query');
foreach (array_slice($store->queries, 0, 2) as $query) {
    $where = $query['where'][0][1];
    bankQueryAssertSame('account-bank-query', $where['account_set_id'], 'Bank statement list should scope current account set');
    bankQueryAssertSame(2026, $where['fiscal_year'], 'Bank statement list should scope current fiscal year');
    bankQueryAssertSame('MATCHED', $where['reconcile_status'], 'Bank statement list should filter the selected reconcile status');
    bankQueryAssertSame([['>=', '2026-07-01'], ['<=', '2026-07-31']], $where['transaction_date'], 'Bank statement list should preserve date range');
    bankQueryAssertSame(
        ['counterparty_account_no|counterparty_account_name|counterparty_bank_name|purpose|postscript|bank_serial_no', 'like', '%流水-001%'],
        $query['where'][1],
        'Bank statement keyword should apply to every independent query'
    );
}
bankQueryAssertSame('transaction_time asc, source_row_no asc', $store->queries[0]['order'], 'Bank statement list order should remain stable');
bankQueryAssertSame([2, 25], $store->queries[0]['page'], 'Bank statement list pagination should remain stable');
bankQueryAssertSame(
    'count(*) as total,sum(debit_amount) as debit_amount,sum(credit_amount) as credit_amount',
    $store->queries[1]['field'],
    'Bank statement totals should use one aggregate query'
);
bankQueryAssertSame('find', $store->queries[1]['operation'], 'Bank statement totals should read one aggregate row');
bankQueryAssertSame('reconcile', $store->queries[2]['table'], 'Bank statement list should batch-load reconcile rows from the reconcile table');
bankQueryAssertSame([
    'account_set_id' => 'account-bank-query',
    'fiscal_year' => 2026,
    'del_flag' => 0,
], $store->queries[2]['where'][0][1], 'Association query should keep account, year and active-row scope');
bankQueryAssertSame(
    ['statement_id', 'in', ['S-1']],
    $store->queries[2]['where'][1],
    'Association query should only load reconcile rows for the current statement page'
);
bankQueryAssertSame(
    'statement_id asc, match_status asc, biz_type asc, biz_no asc',
    $store->queries[2]['order'],
    'Association query should keep duplicate candidates deterministic'
);

$store->queries = [];
$scopeRows = $repository->bankStatementsInScope('CCB', '2026-07-01', '2026-07-31');
bankQueryAssertSame([['statement_id' => 'S-1']], $scopeRows, 'Bank statement scope should return repository rows');
bankQueryAssertSame(['bank_code', '=', 'CCB'], $store->queries[0]['where'][1], 'Bank statement scope should filter selected bank');
bankQueryAssertSame(['transaction_date', '>=', '2026-07-01'], $store->queries[0]['where'][2], 'Bank statement scope should apply start date');
bankQueryAssertSame(['transaction_date', '<=', '2026-07-31'], $store->queries[0]['where'][3], 'Bank statement scope should apply end date');
bankQueryAssertSame('transaction_time asc, source_row_no asc', $store->queries[0]['order'], 'Bank statement scope order should remain stable');

$store->queries = [];
$store->rows = [
    'statement' => [['statement_id' => 'S-PREFETCH']],
    'payment' => [['payment_id' => 'P-PREFETCH']],
    'unknown_payment' => [['unknown_payment_id' => 'U-PREFETCH']],
    'refund' => [['refund_id' => 'R-PREFETCH']],
    'reconcile' => [[
        'reconcile_id' => 'RC-1',
        'statement_id' => 'S-1',
        'biz_type' => 'PAYMENT',
        'biz_id' => 'P-1',
    ]],
];
$prefetched = $repository->prefetchReconcileScope('CCB', '2026-07-01', '2026-07-31', true);
bankQueryAssertSame([
    'statements' => [['statement_id' => 'S-PREFETCH']],
    'payments' => [['payment_id' => 'P-PREFETCH']],
    'unknown_payments' => [['unknown_payment_id' => 'U-PREFETCH']],
    'refunds' => [['refund_id' => 'R-PREFETCH']],
], $prefetched, 'Bank reconcile prefetch should return all four range datasets');
bankQueryAssertSame(4, count($store->queries), 'Bank reconcile prefetch query count must stay constant regardless of row count');
$expectedTables = ['statement', 'payment', 'unknown_payment', 'refund'];
$expectedFields = [
    CaseFundBankReconcileRepository::RECONCILE_STATEMENT_FIELDS,
    CaseFundBankReconcileRepository::RECONCILE_PAYMENT_FIELDS,
    CaseFundBankReconcileRepository::RECONCILE_UNKNOWN_PAYMENT_FIELDS,
    CaseFundBankReconcileRepository::RECONCILE_REFUND_FIELDS,
];
foreach ($store->queries as $index => $prefetchQuery) {
    bankQueryAssertSame($expectedTables[$index], $prefetchQuery['table'], 'Prefetch should query each banking source exactly once');
    bankQueryAssertSame($expectedFields[$index], $prefetchQuery['field'], 'Prefetch should select the narrow reconcile field set');
    bankQueryAssertSame(true, $prefetchQuery['lock'], 'Prefetch should lock every source range inside the outer reconcile transaction');
    if (in_array('source_raw_json', $prefetchQuery['field'], true)) {
        fwrite(STDERR, 'Bank reconcile prefetch must not load source_raw_json' . PHP_EOL);
        exit(1);
    }
}
bankQueryAssertSame(['bank_code', '=', 'CCB'], $store->queries[0]['where'][1], 'Only statement prefetch should apply bank code');
bankQueryAssertSame(['transaction_date', '>=', '2026-07-01'], $store->queries[0]['where'][2], 'Statement prefetch should apply start date');
bankQueryAssertSame(['payment_date', '>=', '2026-07-01'], $store->queries[1]['where'][1], 'Payment prefetch should apply business date');

$store->queries = [];
$store->rows['statement'] = [
    ['source_fingerprint' => 'FP-EXISTING'],
    ['source_fingerprint' => 'FP-OTHER'],
];
$existing = $repository->existingBankStatementFingerprints([
    'FP-EXISTING',
    'FP-EXISTING',
    'FP-OTHER',
]);
bankQueryAssertSame([
    'FP-EXISTING' => true,
    'FP-OTHER' => true,
], $existing, 'Existing bank statement fingerprints should be returned as a lookup map');
bankQueryAssertSame(1, count($store->queries), 'Fingerprint lookup should use one query object');
bankQueryAssertSame([
    'account_set_id' => 'account-bank-query',
    'del_flag' => 0,
], $store->queries[0]['where'][0][1], 'Fingerprint lookup should preserve account-set and active-row scope');
bankQueryAssertSame(
    ['source_fingerprint', 'in', ['FP-EXISTING', 'FP-OTHER']],
    $store->queries[0]['where'][1],
    'Fingerprint lookup should de-duplicate its IN condition'
);
bankQueryAssertSame(['source_fingerprint'], $store->queries[0]['field'], 'Fingerprint lookup should only select its lookup field');
$queryCount = count($store->queries);
bankQueryAssertSame([], $repository->existingBankStatementFingerprints([]), 'Empty fingerprint lookup should return immediately');
bankQueryAssertSame($queryCount, count($store->queries), 'Empty fingerprint lookup should not allocate a database query');

$store->queries = [];
$deletionRows = $repository->bankStatementsForDeletion(['S-1', 'S-2']);
bankQueryAssertSame($store->rows['statement'], $deletionRows, 'Bank deletion lookup should return repository rows');
bankQueryAssertSame([
    'account_set_id' => 'account-bank-query',
    'fiscal_year' => 2026,
    'del_flag' => 0,
    'reconcile_status' => 'UNMATCHED',
], $store->queries[0]['where'][0][1], 'Bank deletion lookup should preserve account, year, active and unmatched scope');
bankQueryAssertSame(['statement_id', 'in', ['S-1', 'S-2']], $store->queries[0]['where'][1], 'Bank deletion lookup should limit selected ids');
bankQueryAssertSame(['statement_id'], $store->queries[0]['field'], 'Bank deletion lookup should preserve the configured statement field list');
bankQueryAssertSame(true, $store->queries[0]['lock'], 'Bank deletion lookup should lock validated statement rows');

$store->queries = [];
$reconcileCount = $repository->activeBankReconcileCountForStatements(['S-1', 'S-2']);
bankQueryAssertSame(4, $reconcileCount, 'Bank deletion guard should return the active reconcile count');
bankQueryAssertSame([
    'account_set_id' => 'account-bank-query',
    'fiscal_year' => 2026,
    'del_flag' => 0,
], $store->queries[0]['where'][0][1], 'Bank deletion reconcile lookup should preserve account, year and active-row scope');
bankQueryAssertSame(['statement_id', 'in', ['S-1', 'S-2']], $store->queries[0]['where'][1], 'Bank deletion reconcile lookup should use selected statement ids');

$store->queries = [];
$emptyCounts = [
    'MATCHED' => 0,
    'BANK_ONLY' => 0,
    'BIZ_ONLY' => 0,
    'AMOUNT_DIFF' => 0,
    'DUPLICATE' => 0,
];
$reconcileList = $repository->bankReconcileList([
    'status' => 'MATCHED',
    'biz_type' => 'PAYMENT',
    'date_start' => '2026-07-01',
    'date_end' => '2026-07-31',
    'keyword' => 'RC-001',
], 1, 50, $emptyCounts);
bankQueryAssertSame(4, $reconcileList['total'], 'Bank reconcile list should preserve filtered total');
bankQueryAssertSame($store->rows['reconcile'], $reconcileList['items'], 'Bank reconcile list should preserve list rows');
bankQueryAssertSame([
    'MATCHED' => 3,
    'BANK_ONLY' => 1,
    'BIZ_ONLY' => 0,
    'AMOUNT_DIFF' => 0,
    'DUPLICATE' => 0,
], $reconcileList['summary'], 'Bank reconcile list should merge grouped counts into the complete dictionary');
bankQueryAssertSame(3, count($store->queries), 'Bank reconcile list should use independent list, count and summary query objects');
bankQueryAssertSame(
    ['bank_serial_no|biz_no|bank_summary|biz_summary', 'like', '%RC-001%'],
    $store->queries[0]['where'][1],
    'Bank reconcile keyword should apply to list query'
);
bankQueryAssertSame(
    ['bank_serial_no|biz_no|bank_summary|biz_summary', 'like', '%RC-001%'],
    $store->queries[1]['where'][1],
    'Bank reconcile keyword should apply to count query'
);
bankQueryAssertSame(1, count($store->queries[2]['where']), 'Bank reconcile summary should preserve the previous structured-filter-only scope');
bankQueryAssertSame('match_status', $store->queries[2]['group'], 'Bank reconcile summary should group by status');
bankQueryAssertSame('reconcile_date asc, bank_serial_no asc', $store->queries[0]['order'], 'Bank reconcile list order should remain stable');

echo 'Case fund bank reconcile query test passed' . PHP_EOL;
