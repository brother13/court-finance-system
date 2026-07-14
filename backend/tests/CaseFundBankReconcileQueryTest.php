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

    public function count()
    {
        $this->store->queries[$this->index]['operation'] = 'count';
        $table = $this->store->queries[$this->index]['table'];
        return $this->store->counts[$table] ?? 0;
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
    'prepare_update' => function ($row) {
        return $row;
    },
];
$tables = [
    'payment' => 'payment',
    'refund' => 'refund',
    'statement' => 'statement',
    'reconcile' => 'reconcile',
];

try {
    new CaseFundBankReconcileRepository($hooks, $tables, [], []);
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
$store->rows = [
    'statement' => [['statement_id' => 'S-1']],
    'reconcile' => [['reconcile_id' => 'RC-1']],
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
    'prepare_update' => $hooks['prepare_update'],
], $tables, [
    'payment' => ['payment_id'],
    'refund' => ['refund_id'],
    'statement' => ['statement_id'],
    'reconcile' => ['reconcile_id'],
], ['SERIAL_NO_DATE', 'PAYEE_ACCOUNT_DATE']);

$statementList = $repository->bankStatementList([
    'period' => '2026-07',
    'bank_code' => 'CCB',
    'direction' => 'CREDIT',
    'date_start' => '2026-07-01',
    'date_end' => '2026-07-31',
    'keyword' => '流水-001',
], 2, 25);
bankQueryAssertSame([
    'items' => [['statement_id' => 'S-1']],
    'total' => 6,
    'debit_amount' => '12.00',
    'credit_amount' => '34.00',
], $statementList, 'Bank statement list should preserve result totals and amount totals');
bankQueryAssertSame(4, count($store->queries), 'Bank statement list should use independent list, count and sum query objects');
foreach ($store->queries as $query) {
    $where = $query['where'][0][1];
    bankQueryAssertSame('account-bank-query', $where['account_set_id'], 'Bank statement list should scope current account set');
    bankQueryAssertSame(2026, $where['fiscal_year'], 'Bank statement list should scope current fiscal year');
    bankQueryAssertSame([['>=', '2026-07-01'], ['<=', '2026-07-31']], $where['transaction_date'], 'Bank statement list should preserve date range');
    bankQueryAssertSame(
        ['counterparty_account_no|counterparty_account_name|counterparty_bank_name|purpose|postscript|bank_serial_no', 'like', '%流水-001%'],
        $query['where'][1],
        'Bank statement keyword should apply to every independent query'
    );
}
bankQueryAssertSame('transaction_time asc, source_row_no asc', $store->queries[0]['order'], 'Bank statement list order should remain stable');
bankQueryAssertSame([2, 25], $store->queries[0]['page'], 'Bank statement list pagination should remain stable');

$store->queries = [];
$scopeRows = $repository->bankStatementsInScope('CCB', '2026-07-01', '2026-07-31');
bankQueryAssertSame([['statement_id' => 'S-1']], $scopeRows, 'Bank statement scope should return repository rows');
bankQueryAssertSame(['bank_code', '=', 'CCB'], $store->queries[0]['where'][1], 'Bank statement scope should filter selected bank');
bankQueryAssertSame(['transaction_date', '>=', '2026-07-01'], $store->queries[0]['where'][2], 'Bank statement scope should apply start date');
bankQueryAssertSame(['transaction_date', '<=', '2026-07-31'], $store->queries[0]['where'][3], 'Bank statement scope should apply end date');
bankQueryAssertSame('transaction_time asc, source_row_no asc', $store->queries[0]['order'], 'Bank statement scope order should remain stable');

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
bankQueryAssertSame([['reconcile_id' => 'RC-1']], $reconcileList['items'], 'Bank reconcile list should preserve list rows');
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
