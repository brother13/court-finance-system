<?php

require_once __DIR__ . '/../app/finance/service/casefund/policy/CaseFundBusinessPolicy.php';
require_once __DIR__ . '/../app/finance/service/casefund/subject/SubjectConfigResolver.php';
require_once __DIR__ . '/../app/finance/service/casefund/policy/CaseFundReceiptTracePolicy.php';

use app\finance\service\casefund\policy\CaseFundBusinessPolicy;
use app\finance\service\casefund\policy\CaseFundReceiptTracePolicy;
use app\finance\service\casefund\subject\SubjectConfigResolver;

class CaseFundReceiptPolicyStore
{
    public $rows = [];
    public $events = [];
    public $throwOnAuxFind = false;

    public function db($table)
    {
        return new CaseFundReceiptPolicyQuery($this, $table, $this->rows[$table] ?? []);
    }
}

class CaseFundReceiptPolicyQuery
{
    protected $store;
    protected $table;
    protected $rows;
    protected $conditions = [];

    public function __construct($store, $table, $rows)
    {
        $this->store = $store;
        $this->table = $table;
        $this->rows = array_values($rows);
    }

    public function where($field, $operator = null, $value = null)
    {
        if (is_array($field)) {
            foreach ($field as $name => $expected) {
                $this->conditions[] = [$name, '=', $expected];
                $this->filterEquals($name, $expected);
            }
        } elseif (func_num_args() === 2) {
            $this->conditions[] = [$field, '=', $operator];
            $this->filterEquals($field, $operator);
        } elseif ($operator === 'in') {
            $this->conditions[] = [$field, 'in', $value];
            $this->rows = array_values(array_filter($this->rows, function ($row) use ($field, $value) {
                return array_key_exists($field, $row) && in_array($row[$field], $value, true);
            }));
        }
        return $this;
    }

    public function field($fields)
    {
        return $this;
    }

    public function select()
    {
        $this->remember('select');
        return $this->rows;
    }

    public function find()
    {
        $this->remember('find');
        if ($this->table === 'fin_subject_aux_config' && $this->store->throwOnAuxFind) {
            throw new \RuntimeException('aux query failed');
        }
        return $this->rows[0] ?? null;
    }

    protected function filterEquals($field, $expected)
    {
        $this->rows = array_values(array_filter($this->rows, function ($row) use ($field, $expected) {
            return array_key_exists($field, $row) && $row[$field] == $expected;
        }));
    }

    protected function remember($operation)
    {
        $this->store->events[] = [
            'table' => $this->table,
            'operation' => $operation,
            'conditions' => $this->conditions,
        ];
    }
}

function receiptPolicyFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function receiptSubjectConfig($voucherBizType, $businessItem, $debit, $credit, $overrides = [])
{
    return array_merge([
        'account_set_id' => 'set-1',
        'biz_type' => 'CASE_FUND',
        'voucher_biz_type' => $voucherBizType,
        'business_item_type' => $businessItem,
        'debit_subject_code' => $debit,
        'credit_subject_code' => $credit,
        'del_flag' => 0,
    ], $overrides);
}

function receiptAuxConfig($subjectCode, $overrides = [])
{
    return array_merge([
        'account_set_id' => 'set-1',
        'subject_code' => $subjectCode,
        'aux_type_code' => 'receipt_no',
        'del_flag' => 0,
    ], $overrides);
}

function receiptPolicyFixture($subjectRows = [], $auxRows = [])
{
    $store = new CaseFundReceiptPolicyStore();
    $store->rows = [
        'fin_case_fund_subject_config' => $subjectRows,
        'fin_subject_aux_config' => $auxRows,
    ];
    $businessPolicy = new CaseFundBusinessPolicy();
    $resolver = new SubjectConfigResolver(
        'set-1',
        function ($table) use ($store) { return $store->db($table); },
        $businessPolicy,
        [
            'business_item_type',
            'debit_subject_code',
            'credit_subject_code',
        ]
    );
    $policy = new CaseFundReceiptTracePolicy($businessPolicy, $resolver, [
        'get_db' => function ($table) use ($store) { return $store->db($table); },
        'account_set_id' => function () { return 'set-1'; },
    ]);
    return [$policy, $store];
}

function receiptPolicyAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        receiptPolicyFail(
            $message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

try {
    $businessPolicy = new CaseFundBusinessPolicy();
    $resolverStore = new CaseFundReceiptPolicyStore();
    $resolver = new SubjectConfigResolver(
        'set-1',
        function ($table) use ($resolverStore) { return $resolverStore->db($table); },
        $businessPolicy,
        []
    );
    new CaseFundReceiptTracePolicy($businessPolicy, $resolver, []);
    receiptPolicyFail('Incomplete receipt policy hooks should be rejected');
} catch (\InvalidArgumentException $e) {
    receiptPolicyAssertSame(
        '案款收据追溯策略缺少回调：get_db',
        $e->getMessage(),
        'Missing receipt policy hook should be explicit'
    );
}

$paymentConfig = receiptSubjectConfig('PAYMENT', '缴费登记', '100201', '220301');
list($policy) = receiptPolicyFixture($paymentConfig ? [$paymentConfig] : [], [receiptAuxConfig('100201')]);
receiptPolicyAssertSame(
    false,
    $policy->paymentImportRequiresReceiptNoDuplicateCheck('CASE_FUND'),
    'Payment receipt duplicate policy must ignore receipt auxiliary accounting on the debit subject'
);

list($policy) = receiptPolicyFixture([$paymentConfig], [receiptAuxConfig('220301')]);
receiptPolicyAssertSame(
    true,
    $policy->paymentImportRequiresReceiptNoDuplicateCheck('CASE_FUND'),
    'Payment receipt duplicate policy must follow the configured credit subject'
);

list($policy) = receiptPolicyFixture([$paymentConfig], []);
receiptPolicyAssertSame(
    false,
    $policy->paymentImportRequiresReceiptNoDuplicateCheck('CASE_FUND'),
    'Payment receipt duplicates should be relaxed only after a valid credit subject is resolved'
);

$litigationPaymentConfig = receiptSubjectConfig(
    'PAYMENT',
    '缴费登记',
    '100201',
    '220401',
    ['biz_type' => 'LITIGATION_FEE']
);
list($policy) = receiptPolicyFixture([$litigationPaymentConfig], [receiptAuxConfig('220401')]);
receiptPolicyAssertSame(
    true,
    $policy->paymentImportRequiresReceiptNoDuplicateCheck('LITIGATION_FEE'),
    'Litigation-fee payment imports should use the same configured-credit receipt policy'
);

$legacyPaymentConfig = receiptSubjectConfig('PAYMENT', '执行、调解款', '100299', '220399');
list($policy) = receiptPolicyFixture(
    [$legacyPaymentConfig, $paymentConfig],
    [receiptAuxConfig('220399')]
);
receiptPolicyAssertSame(
    false,
    $policy->paymentImportRequiresReceiptNoDuplicateCheck('CASE_FUND'),
    'Canonical payment config must override a historical subject-config alias'
);

list($policy) = receiptPolicyFixture([], []);
receiptPolicyAssertSame(
    true,
    $policy->paymentImportRequiresReceiptNoDuplicateCheck('CASE_FUND'),
    'Missing payment subject config must conservatively keep receipt duplicate checks'
);
receiptPolicyAssertSame(
    true,
    $policy->paymentImportRequiresReceiptNoDuplicateCheck('UNKNOWN'),
    'Unknown account-set types must conservatively keep receipt duplicate checks'
);

$blankCreditConfig = receiptSubjectConfig('PAYMENT', '缴费登记', '100201', '');
list($policy) = receiptPolicyFixture([$blankCreditConfig], []);
receiptPolicyAssertSame(
    true,
    $policy->paymentImportRequiresReceiptNoDuplicateCheck('CASE_FUND'),
    'Blank payment credit subjects must conservatively keep receipt duplicate checks'
);

$outOfScopeAuxRows = [
    receiptAuxConfig('220301', ['account_set_id' => 'other-set']),
    receiptAuxConfig('220301', ['del_flag' => 1]),
    receiptAuxConfig('220301', ['aux_type_code' => 'case_no']),
];
list($policy, $store) = receiptPolicyFixture([$paymentConfig], $outOfScopeAuxRows);
receiptPolicyAssertSame(
    false,
    $policy->paymentImportRequiresReceiptNoDuplicateCheck('CASE_FUND'),
    'Receipt auxiliary lookup must exclude other accounts, deleted rows and other dimensions'
);
$auxFind = null;
foreach ($store->events as $event) {
    if ($event['table'] === 'fin_subject_aux_config' && $event['operation'] === 'find') {
        $auxFind = $event;
    }
}
if ($auxFind === null) {
    receiptPolicyFail('Receipt auxiliary lookup should query subject auxiliary config');
}
$conditionMap = [];
foreach ($auxFind['conditions'] as $condition) {
    $conditionMap[$condition[0] . ':' . $condition[1]] = $condition[2];
}
if (($conditionMap['account_set_id:='] ?? '') !== 'set-1'
    || ($conditionMap['aux_type_code:='] ?? '') !== 'receipt_no'
    || ($conditionMap['del_flag:='] ?? null) !== 0
    || ($conditionMap['subject_code:in'] ?? []) !== ['220301']
) {
    receiptPolicyFail('Receipt auxiliary lookup scope should be exact and account-set isolated');
}

list($policy, $store) = receiptPolicyFixture([], []);
receiptPolicyAssertSame(
    false,
    $policy->refundImportRequiresReceiptTrace('LITIGATION_FEE'),
    'Litigation-fee refund import must not require receipt auxiliary trace'
);
receiptPolicyAssertSame(
    true,
    $policy->refundImportRequiresReceiptTrace('UNKNOWN'),
    'Unknown refund account-set types must conservatively require receipt trace'
);
receiptPolicyAssertSame(
    true,
    $policy->refundImportRequiresReceiptTrace('CASE_FUND'),
    'Missing case-fund refund config must conservatively require receipt trace'
);

$refundConfig = receiptSubjectConfig('REFUND', '出账查询', '224101', '100201');
list($policy) = receiptPolicyFixture([
    receiptSubjectConfig('REFUND', '出账查询', '', ''),
], []);
receiptPolicyAssertSame(
    true,
    $policy->refundImportRequiresReceiptTrace('CASE_FUND'),
    'Blank refund subjects must conservatively require receipt trace'
);

list($policy) = receiptPolicyFixture([$refundConfig], []);
receiptPolicyAssertSame(
    false,
    $policy->refundImportRequiresReceiptTrace('CASE_FUND'),
    'Case-fund refund receipt trace may relax only when neither configured subject uses receipt_no'
);
receiptPolicyAssertSame(
    false,
    $policy->refundImportRequiresReceiptTrace('CASE_FUND', [
        ['out_type' => '执行、调解款发放'],
    ]),
    'Refund row out_type should resolve through the canonical subject-config key'
);

list($policy) = receiptPolicyFixture([$refundConfig], [receiptAuxConfig('224101')]);
receiptPolicyAssertSame(
    true,
    $policy->refundImportRequiresReceiptTrace('CASE_FUND'),
    'Case-fund refund debit subject receipt auxiliary accounting must require trace'
);
list($policy) = receiptPolicyFixture([$refundConfig], [receiptAuxConfig('100201')]);
receiptPolicyAssertSame(
    true,
    $policy->refundImportRequiresReceiptTrace('CASE_FUND'),
    'Case-fund refund credit subject receipt auxiliary accounting must require trace'
);

list($policy, $store) = receiptPolicyFixture([$paymentConfig], []);
$store->throwOnAuxFind = true;
try {
    $policy->paymentImportRequiresReceiptNoDuplicateCheck('CASE_FUND');
    receiptPolicyFail('Receipt auxiliary database errors must abort import policy evaluation');
} catch (\RuntimeException $e) {
    receiptPolicyAssertSame('aux query failed', $e->getMessage(), 'Receipt policy must not swallow database errors');
}

$modelSource = file_get_contents(__DIR__ . '/../app/finance/model/CaseFund.php');
foreach (['paymentImportRequiresReceiptNoDuplicateCheck', 'refundImportRequiresReceiptTrace'] as $policyMethod) {
    if (strpos($modelSource, '->' . $policyMethod . '(') === false) {
        receiptPolicyFail('CaseFund import flow must delegate to receipt policy: ' . $policyMethod);
    }
    if (strpos($modelSource, 'protected function ' . $policyMethod) !== false) {
        receiptPolicyFail('Receipt policy should not keep a production-only compatibility wrapper: ' . $policyMethod);
    }
}
foreach ([
    'validatePaymentRows($rows, $bizType, $receiptNoDuplicateCheckRequired)',
    'loadExistingPaymentsForImport($rows, $receiptNoDuplicateCheckRequired)',
    "['use_receipt_no_as_duplicate_key'] = \$receiptNoDuplicateCheckRequired",
] as $decisionUse) {
    if (strpos($modelSource, $decisionUse) === false) {
        receiptPolicyFail('One payment receipt policy decision must flow through validation and persistence');
    }
}

echo 'Case fund receipt duplicate policy test passed' . PHP_EOL;
