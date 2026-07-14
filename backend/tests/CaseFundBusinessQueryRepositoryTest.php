<?php

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\service\casefund\query\CaseFundBusinessQueryRepository;

class CaseFundBusinessQueryStore
{
    public $queries = [];
    public $counts = [];
    public $sums = [];
    public $rows = [];

    public function db($table)
    {
        $index = count($this->queries);
        $this->queries[$index] = [
            'table' => $table,
            'where' => [],
            'field' => null,
            'order' => null,
            'page' => null,
            'operation' => null,
        ];
        return new CaseFundBusinessQueryFake($this, $index);
    }
}

class CaseFundBusinessQueryFake
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
        $table = $this->store->queries[$this->index]['table'];
        return $this->store->rows[$table] ?? [];
    }
}

function businessQueryAssertSame($expected, $actual, $message)
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

try {
    new CaseFundBusinessQueryRepository([], [], []);
    fwrite(STDERR, 'Business query repository should reject incomplete hook configuration' . PHP_EOL);
    exit(1);
} catch (\InvalidArgumentException $e) {
    businessQueryAssertSame('案款业务查询仓储缺少回调：get_db', $e->getMessage(), 'Business query repository should report the first missing hook');
}

$store = new CaseFundBusinessQueryStore();
$store->counts = [
    'payment' => 2,
    'refund' => 3,
    'unknown' => 4,
    'distribution' => 5,
];
$store->sums = [
    'refund' => ['refund_amount' => '66.00'],
    'unknown' => ['unknown_amount' => '77.00'],
    'distribution' => ['distribution_amount' => '88.00'],
];
$store->rows = [
    'payment' => [['payment_id' => 'P-1']],
    'refund' => [['refund_id' => 'R-1']],
    'unknown' => [['unknown_payment_id' => 'U-1']],
    'distribution' => [['distribution_id' => 'D-1']],
];

$repository = new CaseFundBusinessQueryRepository([
    'get_db' => function ($table) use ($store) {
        return $store->db($table);
    },
    'account_where' => function () {
        return ['account_set_id' => 'account-query', 'del_flag' => 0];
    },
    'current_year' => function () {
        return 2026;
    },
    'normalize_payment_method' => function ($method) {
        return strtoupper(trim((string)$method));
    },
    'payment_method_options' => function () {
        return ['POS' => 'POS 收款', 'TRANSFER' => '银行转账', 'UNKNOWN' => '不明款认领'];
    },
    'normalize_date_filter' => function ($value) {
        return is_string($value) || is_numeric($value) ? trim((string)$value) : '';
    },
], [
    'payment' => 'payment',
    'refund' => 'refund',
    'unknown_payment' => 'unknown',
    'distribution' => 'distribution',
], [
    'payment' => ['payment_id'],
    'refund' => ['refund_id'],
    'unknown_payment' => ['unknown_payment_id'],
    'distribution' => ['distribution_id'],
]);

$payment = $repository->paymentList([
    'period' => '2026-07',
    'voucher_status' => 'UNGENERATED',
    'paymentMethod' => 'pos',
    'date_start' => '2026-07-01',
    'date_end' => '2026-07-31',
    'keyword' => 'CASE-001',
], 2, 25, ['执行、调解款']);
businessQueryAssertSame(['items' => [['payment_id' => 'P-1']], 'total' => 2], $payment, 'Payment list result shape should remain stable');
businessQueryAssertSame(2, count($store->queries), 'Payment list should use independent list and count query objects');
$paymentWhere = $store->queries[0]['where'][0][1];
businessQueryAssertSame('account-query', $paymentWhere['account_set_id'], 'Payment list should scope current account set');
businessQueryAssertSame(2026, $paymentWhere['fiscal_year'], 'Payment list should scope current fiscal year');
businessQueryAssertSame('POS', $paymentWhere['payment_method'], 'Payment method filter should be normalized against the dictionary');
businessQueryAssertSame([['>=', '2026-07-01'], ['<=', '2026-07-31']], $paymentWhere['payment_date'], 'Payment list should preserve date range filters');
businessQueryAssertSame(['in', ['执行、调解款']], $paymentWhere['business_type'], 'Payment list should enforce the account-set business whitelist');
businessQueryAssertSame('payment_date asc, source_row_no asc', $store->queries[0]['order'], 'Payment list ordering should remain stable');
businessQueryAssertSame([2, 25], $store->queries[0]['page'], 'Payment list pagination should remain stable');
businessQueryAssertSame(
    ['case_no|trial_case_no|payer_name|party_name|receipt_no|bank_serial_no|payment_order_no', 'like', '%CASE-001%'],
    $store->queries[0]['where'][1],
    'Payment keyword filter should keep the complete search field set'
);

$store->queries = [];
$refund = $repository->refundList([
    'period' => '2026-07',
    'voucher_status' => 'UNGENERATED',
    'startDate' => ' 2026-07-01 ',
    'endDate' => ' 2026-07-31 ',
    'out_status' => 'PAID',
    'caseNo' => 'CASE-R',
    'partyName' => '张三',
    'keyword' => 'OUT-001',
], 1, 50, ['执行、调解款发放']);
businessQueryAssertSame([
    'items' => [['refund_id' => 'R-1']],
    'total' => 3,
    'total_amount' => '66.00',
], $refund, 'Refund list result and amount total should remain stable');
businessQueryAssertSame(3, count($store->queries), 'Refund list should use independent list, count and amount query objects');
foreach ($store->queries as $query) {
    $where = $query['where'][0][1];
    businessQueryAssertSame([['>=', '2026-07-01'], ['<=', '2026-07-31']], $where['refund_date'], 'Refund list should normalize camel-case date filters');
    businessQueryAssertSame(['in', ['执行、调解款发放']], $where['out_type'], 'Refund list should enforce the refund type whitelist');
    businessQueryAssertSame(['case_no', 'like', '%CASE-R%'], $query['where'][1], 'Refund case number filter should apply to every query object');
    businessQueryAssertSame(['party_name|actual_payee_name|payee_bank_account_name', 'like', '%张三%'], $query['where'][2], 'Refund party filter should apply to every query object');
    businessQueryAssertSame(['case_no|party_name|actual_payee_name|receipt_no|source_receipt_no|out_order_no|payee_bank_account_no', 'like', '%OUT-001%'], $query['where'][3], 'Refund keyword filter should apply to every query object');
}
businessQueryAssertSame('refund_date asc, source_row_no asc', $store->queries[0]['order'], 'Refund ordering should remain stable');

$store->queries = [];
$unknown = $repository->unknownPaymentList([
    'claim_status' => 'UNCLAIMED',
    'date_start' => '2026-07-01',
    'date_end' => '2026-07-31',
    'keyword' => '付款人',
], 3, 10);
businessQueryAssertSame(4, $unknown['total'], 'Unknown payment list should preserve total count');
businessQueryAssertSame('77.00', $unknown['total_amount'], 'Unknown payment list should preserve total amount');
businessQueryAssertSame(3, count($store->queries), 'Unknown payment list should not reuse a count query after aggregation');
businessQueryAssertSame('transaction_date asc, source_row_no asc', $store->queries[0]['order'], 'Unknown payment ordering should remain stable');

$store->queries = [];
$distribution = $repository->distributionList([
    'invoice_type' => '执行款',
    'date_start' => '2026-07-01',
    'date_end' => '2026-07-31',
    'keyword' => 'D-001',
], 1, 20);
businessQueryAssertSame(5, $distribution['total'], 'Distribution list should preserve total count');
businessQueryAssertSame('88.00', $distribution['total_amount'], 'Distribution list should preserve total amount');
businessQueryAssertSame('receive_date asc, distribution_no asc, source_row_no asc', $store->queries[0]['order'], 'Distribution ordering should remain stable');

echo 'Case fund business query repository test passed' . PHP_EOL;
