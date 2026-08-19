<?php

function input($key = '', $default = null)
{
    return $default;
}

function config($key = '')
{
    if ($key === 'default_account_set_id') {
        return '00000000-0000-0000-0000-000000000101';
    }
    if ($key === 'default_year') {
        return '2026';
    }
    return null;
}

function getNowTime()
{
    return '2026-07-14 12:34:56';
}

function uuid()
{
    static $sequence = 0;
    $sequence++;
    return 'voucher-rule-id-' . $sequence;
}

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\model\CaseFund;
use app\finance\service\casefund\voucher\CaseFundVoucherRule;

class CaseFundVoucherRuleProbe extends CaseFund
{
    private $subjectAuxConfigs = [];

    public function setSubjectAuxConfigs($configs)
    {
        $this->subjectAuxConfigs = $configs;
    }

    protected function getSubjectAuxConfigs($subjectCode)
    {
        return $this->subjectAuxConfigs[$subjectCode] ?? [];
    }

    public function ruleService()
    {
        return $this->voucherRule();
    }

    public function paymentDetails($rows, $subjectConfigMap, $methodConfigMap, $byMethod, $bizType)
    {
        return $this->voucherRule()->buildPaymentVoucherDetailsData(
            $rows,
            $subjectConfigMap,
            $methodConfigMap,
            $byMethod,
            $bizType
        );
    }

    public function paymentGroups($rows, $byDay, $byMethod)
    {
        return $this->voucherRule()->groupPaymentRowsForVoucher($rows, $byDay, $byMethod);
    }

    public function unknownPaymentGroups($rows, $byDay)
    {
        return $this->voucherRule()->groupUnknownPaymentRowsForVoucher($rows, $byDay);
    }

    public function unknownPaymentDetails($rows, $subjectConfig, $debitConfigs = [], $creditConfigs = [])
    {
        return $this->voucherRule()->buildUnknownPaymentVoucherDetailsData(
            $rows,
            $subjectConfig,
            $debitConfigs,
            $creditConfigs
        );
    }

    public function distributionDetails($rows, $subjectConfigMap)
    {
        return $this->voucherRule()->buildDistributionVoucherDetailsData($rows, $subjectConfigMap);
    }
}

class CaseFundVoucherRuleOverrideProbe extends CaseFundVoucherRuleProbe
{
    protected function buildPaymentVoucherSummary($row)
    {
        return 'override-payment-' . ($row['case_no'] ?? '');
    }

    protected function buildDistributionTransferOutSummary($row)
    {
        return 'override-out-' . ($row['distribution_no'] ?? '');
    }
}

function voucherRuleAssertSame($expected, $actual, $message)
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
    new CaseFundVoucherRule([]);
    fwrite(STDERR, 'Voucher rule should reject incomplete hook configuration' . PHP_EOL);
    exit(1);
} catch (\InvalidArgumentException $e) {
    voucherRuleAssertSame('案款凭证规则缺少回调：amount_to_cents', $e->getMessage(), 'Voucher rule should report the first missing hook');
}

$probe = new CaseFundVoucherRuleProbe();
voucherRuleAssertSame(true, $probe->ruleService() instanceof CaseFundVoucherRule, 'CaseFund should lazily create the extracted voucher rule');

$wrapperSignatures = [
    'filterAuxValuesBySubjectConfigs' => 2,
    'buildPaymentMethodVoucherSummary' => 2,
    'buildRefundDebitDetailData' => 4,
    'buildPaymentVoucherSummary' => 1,
    'buildRefundNormalVoucherSummary' => 1,
    'buildRefundAggregateCreditSummary' => 1,
    'buildDistributionVoucherHeaderSummary' => 1,
    'buildDistributionTransferOutSummary' => 1,
    'buildDistributionTransferInSummary' => 1,
    'buildUnknownPaymentVoucherSummary' => 1,
    'buildRefundAggregateCreditDetail' => 6,
    'buildAuxDesc' => 1,
];
foreach ($wrapperSignatures as $methodName => $parameterCount) {
    $method = new ReflectionMethod(CaseFund::class, $methodName);
    voucherRuleAssertSame(true, $method->isProtected(), 'Voucher compatibility wrapper should remain protected: ' . $methodName);
    voucherRuleAssertSame($parameterCount, count($method->getParameters()), 'Voucher wrapper parameter count should remain stable: ' . $methodName);
}
$refundDetailParameters = (new ReflectionMethod(CaseFundVoucherRule::class, 'buildRefundVoucherDetailsData'))->getParameters();
voucherRuleAssertSame('', $refundDetailParameters[3]->getDefaultValue(), 'Refund voucher detail biz-type default should remain empty');

$probe->setSubjectAuxConfigs([
    '100201' => [],
    '224101' => [
        ['aux_type_code' => 'case_no', 'required_flag' => 1, 'verification_flag' => 0],
        ['aux_type_code' => 'receipt_no', 'required_flag' => 0, 'verification_flag' => 1],
    ],
]);
$payment = $probe->paymentDetails([
    [
        'business_type' => '执行、调解款',
        'case_no' => '（2026）辽0111执1号',
        'receipt_no' => 'R-001',
        'payment_amount' => '10.005',
        'payment_method' => 'TRANSFER',
        'payment_date' => '2026-07-14',
        'party_name' => '张三',
    ],
], [
    '缴费登记' => ['debit_subject_code' => '100201', 'credit_subject_code' => '224101'],
], [], false, 'CASE_FUND');
voucherRuleAssertSame(1001, $payment['total_debit'], 'Payment detail total should remain integer cents');
voucherRuleAssertSame('10.01', $payment['details'][0]['debit_amount'], 'Payment debit should be rounded once from cents');
voucherRuleAssertSame('UNVERIFIED', $payment['details'][1]['verification_status'], 'Credit verification status should follow subject aux config');
voucherRuleAssertSame('case_no:（2026）辽0111执1号; receipt_no:R-001', $payment['details'][1]['aux_desc'], 'Configured payment aux values should remain ordered');

$paymentGroupRows = [
    ['payment_id' => 'p1', 'payment_date' => '2026-07-14', 'payment_method' => 'POS'],
    ['payment_id' => 'p2', 'payment_date' => '2026-07-14', 'payment_method' => 'POS收款'],
    ['payment_id' => 'p3', 'payment_date' => '2026-07-14', 'payment_method' => 'TRANSFER'],
];
voucherRuleAssertSame(
    ['2026-07-14|POS' => [$paymentGroupRows[0], $paymentGroupRows[1]], '2026-07-14|TRANSFER' => [$paymentGroupRows[2]]],
    $probe->paymentGroups($paymentGroupRows, 1, 1),
    'Daily payment grouping should split normalized payment methods'
);
voucherRuleAssertSame(
    ['2026-07-14|ALL' => $paymentGroupRows],
    $probe->paymentGroups($paymentGroupRows, 1, 0),
    'Daily payment grouping should merge methods when method grouping is off'
);
voucherRuleAssertSame(
    ['p1|POS' => [$paymentGroupRows[0]], 'p2|POS' => [$paymentGroupRows[1]], 'p3|TRANSFER' => [$paymentGroupRows[2]]],
    $probe->paymentGroups($paymentGroupRows, 0, 1),
    'Non-daily payment grouping should keep one voucher per payment row'
);

$unknownRows = [
    [
        'unknown_payment_id' => 'u1',
        'transaction_date' => '2026-07-14',
        'unknown_amount' => '10.005',
        'payer_name' => '甲',
        'bank_remark' => '备注一',
        'bank_summary' => '',
        'unknown_aux_case_no' => '甲｜备注一｜BMK-A82F193B',
    ],
    [
        'unknown_payment_id' => 'u2',
        'transaction_date' => '2026-07-14',
        'unknown_amount' => '2.00',
        'payer_name' => '乙',
        'bank_remark' => '',
        'bank_summary' => '摘要二',
        'unknown_aux_case_no' => '乙｜BMK-C0FFEE01',
    ],
];
voucherRuleAssertSame(
    ['2026-07-14' => $unknownRows],
    $probe->unknownPaymentGroups($unknownRows, 1),
    'Daily unknown-payment grouping should keep one group per transaction date'
);
voucherRuleAssertSame(
    ['u1' => [$unknownRows[0]], 'u2' => [$unknownRows[1]]],
    $probe->unknownPaymentGroups($unknownRows, 0),
    'Non-daily unknown-payment grouping should keep one voucher per source row'
);
$unknownDetails = $probe->unknownPaymentDetails($unknownRows, [
    'debit_subject_code' => '100201',
    'credit_subject_code' => '224101',
]);
voucherRuleAssertSame(1201, $unknownDetails['total_cents'], 'Unknown-payment total should remain integer cents');
voucherRuleAssertSame(4, count($unknownDetails['details']), 'Each unknown-payment row should create one debit and one credit detail');
voucherRuleAssertSame('收 不明款 甲 备注一', $unknownDetails['details'][0]['summary'], 'Unknown-payment summary should prefer bank remark');
voucherRuleAssertSame('收 不明款 乙 摘要二', $unknownDetails['details'][2]['summary'], 'Unknown-payment summary should fall back to bank summary');
voucherRuleAssertSame([], $unknownDetails['details'][0]['aux_values'] ?? [], 'Unconfigured unknown-payment subjects should keep the original no-auxiliary behavior');

$configuredUnknownDetails = $probe->unknownPaymentDetails(
    [$unknownRows[0]],
    ['debit_subject_code' => '100201', 'credit_subject_code' => '224101'],
    [],
    [['aux_type_code' => 'case_no', 'required_flag' => 1, 'verification_flag' => 0]]
);
voucherRuleAssertSame([], $configuredUnknownDetails['details'][0]['aux_values'], 'Unknown-payment debit line should not receive case_no unless its own subject is configured');
voucherRuleAssertSame(
    [[
        'aux_type_code' => 'case_no',
        'aux_value' => '甲｜备注一｜BMK-A82F193B',
        'aux_label' => '甲｜备注一｜BMK-A82F193B',
    ]],
    $configuredUnknownDetails['details'][1]['aux_values'],
    'Unknown-payment credit line should receive the normal case_no value when its subject is configured'
);
voucherRuleAssertSame(
    'case_no:甲｜备注一｜BMK-A82F193B',
    $configuredUnknownDetails['details'][1]['aux_desc'],
    'Unknown-payment auxiliary description should use the common case_no formatter'
);

$override = new CaseFundVoucherRuleOverrideProbe();
$override->setSubjectAuxConfigs(['100201' => [], '224101' => []]);
$overridePayment = $override->paymentDetails([
    [
        'business_type' => '执行、调解款',
        'case_no' => 'OVERRIDE-CASE',
        'receipt_no' => '',
        'payment_amount' => '1.00',
        'payment_method' => 'TRANSFER',
        'payment_date' => '2026-07-14',
        'party_name' => '',
    ],
], [
    '缴费登记' => ['debit_subject_code' => '100201', 'credit_subject_code' => '224101'],
], [], false, 'CASE_FUND');
voucherRuleAssertSame('override-payment-OVERRIDE-CASE', $overridePayment['details'][0]['summary'], 'Extracted payment builder should preserve subclass summary overrides');

$distribution = $override->distributionDetails([
    [
        'distribution_no' => 'D-001',
        'invoice_type' => '执行款',
        'distribution_amount' => '2.00',
        'out_case_no' => 'OUT-CASE',
        'out_party_name' => '甲',
        'in_case_no' => 'IN-CASE',
        'in_party_name' => '乙',
        'receipt_no' => 'R-002',
    ],
], [
    '执行款' => ['debit_subject_code' => '100201', 'credit_subject_code' => '224101'],
]);
voucherRuleAssertSame('override-out-D-001', $distribution['details'][0]['summary'], 'Extracted distribution builder should preserve subclass summary overrides');
voucherRuleAssertSame(200, $distribution['total_cents'], 'Distribution total should remain integer cents');

$override->setSubjectAuxConfigs([
    '100201' => [['aux_type_code' => 'case_no']],
    '224101' => [['aux_type_code' => 'receipt_no']],
]);
try {
    $override->distributionDetails([
        [
            'distribution_no' => '',
            'invoice_type' => '执行款',
            'distribution_amount' => '2.00',
            'out_case_no' => 'OUT-CASE',
            'out_party_name' => '甲',
            'in_case_no' => 'IN-CASE',
            'in_party_name' => '乙',
            'receipt_no' => '',
        ],
    ], [
        '执行款' => ['debit_subject_code' => '100201', 'credit_subject_code' => '224101'],
    ]);
    fwrite(STDERR, 'Distribution voucher generation should reject an empty document number when either subject uses receipt_no' . PHP_EOL);
    exit(1);
} catch (InvalidArgumentException $e) {
    voucherRuleAssertSame(
        '案款分配记录的借方或贷方科目已关联单据号辅助核算，单据号不能为空',
        $e->getMessage(),
        'Distribution voucher generation should recheck the current subject auxiliary configuration'
    );
}

echo 'Case fund voucher rule test passed' . PHP_EOL;
