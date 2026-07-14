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
        return $this->buildPaymentVoucherDetailsData($rows, $subjectConfigMap, $methodConfigMap, $byMethod, $bizType);
    }

    public function paymentGroups($rows, $byDay, $byMethod)
    {
        return $this->voucherRule()->groupPaymentRowsForVoucher($rows, $byDay, $byMethod);
    }

    public function distributionDetails($rows, $subjectConfigMap)
    {
        return $this->buildDistributionVoucherDetailsData($rows, $subjectConfigMap);
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
    'buildPaymentVoucherDetailsData' => 5,
    'paymentVoucherAuxValues' => 1,
    'filterAuxValuesBySubjectConfigs' => 2,
    'buildPaymentMethodVoucherSummary' => 2,
    'groupRefundRowsForVoucher' => 3,
    'groupDistributionRowsForVoucher' => 1,
    'buildRefundVoucherDetailsData' => 4,
    'buildDistributionVoucherDetailsData' => 2,
    'buildRefundDebitDetailData' => 4,
    'buildPaymentVoucherSummary' => 1,
    'buildRefundVoucherHeaderSummary' => 3,
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
$refundDetailParameters = (new ReflectionMethod(CaseFund::class, 'buildRefundVoucherDetailsData'))->getParameters();
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

echo 'Case fund voucher rule test passed' . PHP_EOL;
