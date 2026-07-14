<?php

$policyPath = __DIR__ . '/../app/finance/service/casefund/policy/CaseFundBusinessPolicy.php';
require_once $policyPath;

use app\finance\service\casefund\policy\CaseFundBusinessPolicy;

function assertSameValue($expected, $actual, $message)
{
    if ($actual !== $expected) {
        fwrite(
            STDERR,
            $message
            . PHP_EOL
            . 'Expected: ' . var_export($expected, true)
            . PHP_EOL
            . 'Actual:   ' . var_export($actual, true)
            . PHP_EOL
        );
        exit(1);
    }
}

function assertContainsValue($needle, $haystack, $message)
{
    if (!in_array($needle, $haystack, true)) {
        fwrite(STDERR, $message . ': ' . var_export($needle, true) . PHP_EOL);
        exit(1);
    }
}

function assertNotContainsValue($needle, $haystack, $message)
{
    if (in_array($needle, $haystack, true)) {
        fwrite(STDERR, $message . ': ' . var_export($needle, true) . PHP_EOL);
        exit(1);
    }
}

$policy = new CaseFundBusinessPolicy();

// Import whitelists are strict accounting business types, not compatibility aliases.
$caseFundPaymentTypes = $policy->allowedPaymentBusinessTypes('CASE_FUND');
$litigationPaymentTypes = $policy->allowedPaymentBusinessTypes('LITIGATION_FEE');
assertSameValue(
    ['执行、调解款'],
    $caseFundPaymentTypes,
    'CASE_FUND payment import whitelist should remain strict'
);
assertSameValue(
    ['诉讼费直接结算', '诉讼费预收'],
    $litigationPaymentTypes,
    'LITIGATION_FEE payment import whitelist should remain strict'
);
assertSameValue([], $policy->allowedPaymentBusinessTypes('UNKNOWN_BIZ'), 'Unknown account-set type should not allow payment imports');

foreach (['执行、调节款', '预收诉讼费', '诉讼费收款', 'POS', 'TRANSFER', 'UNKNOWN'] as $forbiddenType) {
    assertNotContainsValue($forbiddenType, $caseFundPaymentTypes, 'CASE_FUND payment whitelist contains a forbidden alias or payment method');
    assertNotContainsValue($forbiddenType, $litigationPaymentTypes, 'LITIGATION_FEE payment whitelist contains a forbidden alias or payment method');
}

$caseFundRefundTypes = $policy->allowedRefundOutTypes('CASE_FUND');
$litigationRefundTypes = $policy->allowedRefundOutTypes('LITIGATION_FEE');
assertSameValue(
    ['执行、调解款发放'],
    $caseFundRefundTypes,
    'CASE_FUND refund import whitelist should remain strict'
);
assertSameValue(
    ['诉讼费退费'],
    $litigationRefundTypes,
    'LITIGATION_FEE refund import whitelist should remain strict'
);
assertSameValue([], $policy->allowedRefundOutTypes('UNKNOWN_BIZ'), 'Unknown account-set type should not allow refund imports');
assertNotContainsValue('执行、调节款发放', $caseFundRefundTypes, 'CASE_FUND refund whitelist contains the deprecated typo alias');

// Subject configuration uses canonical rows, while accepted business labels resolve to them.
assertSameValue(
    ['缴费登记'],
    $policy->allowedSubjectConfigItems('CASE_FUND', 'PAYMENT'),
    'CASE_FUND payment subject config should expose only its canonical row'
);
assertSameValue(
    ['缴费登记'],
    $policy->allowedSubjectConfigItems('LITIGATION_FEE', 'PAYMENT'),
    'LITIGATION_FEE payment subject config should expose only its canonical row'
);
assertSameValue(
    ['出账查询'],
    $policy->allowedSubjectConfigItems('CASE_FUND', 'REFUND'),
    'CASE_FUND refund subject config should expose only its canonical row'
);
assertSameValue(
    ['出账查询'],
    $policy->allowedSubjectConfigItems('LITIGATION_FEE', 'REFUND'),
    'LITIGATION_FEE refund subject config should expose only its canonical row'
);

$configKeyCases = [
    ['CASE_FUND', 'PAYMENT', '缴费登记', '缴费登记'],
    ['CASE_FUND', 'PAYMENT', '执行、调解款', '缴费登记'],
    ['LITIGATION_FEE', 'PAYMENT', '诉讼费直接结算', '缴费登记'],
    ['LITIGATION_FEE', 'PAYMENT', '诉讼费预收', '缴费登记'],
    ['LITIGATION_FEE', 'PAYMENT', '预收诉讼费', '缴费登记'],
    ['LITIGATION_FEE', 'PAYMENT', '诉讼费收款', '缴费登记'],
    ['CASE_FUND', 'REFUND', '出账查询', '出账查询'],
    ['CASE_FUND', 'REFUND', '执行、调解款发放', '出账查询'],
    ['LITIGATION_FEE', 'REFUND', '诉讼费退费', '出账查询'],
];
foreach ($configKeyCases as $case) {
    assertSameValue(
        $case[3],
        $policy->voucherSubjectConfigKey($case[0], $case[1], $case[2]),
        'Subject config alias should resolve to its canonical row'
    );
}

// Removed typo aliases must not silently resolve to valid subject config rows either.
assertSameValue(
    '执行、调节款',
    $policy->voucherSubjectConfigKey('CASE_FUND', 'PAYMENT', '执行、调节款'),
    'Deprecated CASE_FUND payment typo should not be a subject config alias'
);
assertSameValue(
    '执行、调节款发放',
    $policy->voucherSubjectConfigKey('CASE_FUND', 'REFUND', '执行、调节款发放'),
    'Deprecated CASE_FUND refund typo should not be a subject config alias'
);

$caseFundPaymentAliases = $policy->voucherSubjectConfigAliases('CASE_FUND', 'PAYMENT');
$caseFundRefundAliases = $policy->voucherSubjectConfigAliases('CASE_FUND', 'REFUND');
if (array_key_exists('执行、调节款', $caseFundPaymentAliases)) {
    fwrite(STDERR, 'Deprecated CASE_FUND payment typo should not appear in subject config aliases' . PHP_EOL);
    exit(1);
}
if (array_key_exists('执行、调节款发放', $caseFundRefundAliases)) {
    fwrite(STDERR, 'Deprecated CASE_FUND refund typo should not appear in subject config aliases' . PHP_EOL);
    exit(1);
}

$caseFundPaymentLookupItems = $policy->subjectConfigLookupItems('CASE_FUND', 'PAYMENT', ['缴费登记']);
assertContainsValue('缴费登记', $caseFundPaymentLookupItems, 'Payment config lookup should include the canonical row');
assertContainsValue('执行、调解款', $caseFundPaymentLookupItems, 'Payment config lookup should include the valid business label');
assertNotContainsValue('执行、调节款', $caseFundPaymentLookupItems, 'Payment config lookup should not include the deprecated typo alias');

// PAYMENT_METHOD and UNKNOWN_PAYMENT are intentionally separate voucher configuration types.
$paymentMethodItems = $policy->allowedSubjectConfigItems('CASE_FUND', 'PAYMENT_METHOD');
$unknownPaymentItems = $policy->allowedSubjectConfigItems('CASE_FUND', 'UNKNOWN_PAYMENT');
assertSameValue(
    ['POS', 'TRANSFER', 'UNKNOWN'],
    $paymentMethodItems,
    'PAYMENT_METHOD should expose exactly the supported payment method codes'
);
assertSameValue(['不明款'], $unknownPaymentItems, 'UNKNOWN_PAYMENT should expose the independent unknown-payment business row');
assertSameValue(
    [],
    $policy->allowedSubjectConfigItems('LITIGATION_FEE', 'UNKNOWN_PAYMENT'),
    'LITIGATION_FEE should not expose CASE_FUND unknown-payment config'
);
assertNotContainsValue('不明款', $paymentMethodItems, 'PAYMENT_METHOD should not contain the UNKNOWN_PAYMENT business row');
assertNotContainsValue('UNKNOWN', $unknownPaymentItems, 'UNKNOWN_PAYMENT should not contain the payment-method UNKNOWN code');
assertSameValue('PAYMENT_METHOD', $policy->normalizeVoucherBizType(' payment_method '), 'PAYMENT_METHOD should be a valid voucher business type');
assertSameValue('UNKNOWN_PAYMENT', $policy->normalizeVoucherBizType('unknown_payment'), 'UNKNOWN_PAYMENT should be a valid voucher business type');
assertSameValue('', $policy->normalizeVoucherBizType('UNKNOWN'), 'Payment-method UNKNOWN should not be treated as a voucher business type');

$expectedPaymentMethodOptions = [
    'POS' => 'POS 收款',
    'TRANSFER' => '银行转账',
    'UNKNOWN' => '不明款认领',
];
assertSameValue($expectedPaymentMethodOptions, $policy->paymentMethodOptions(), 'Payment method options should remain stable');

$normalizeCases = [
    ['', 'UNKNOWN'],
    ['   ', 'UNKNOWN'],
    ['pos', 'POS'],
    [' POS 收款 ', 'POS'],
    ['POS刷卡', 'POS'],
    ['transfer', 'TRANSFER'],
    ['银行转账', 'TRANSFER'],
    [' 转 账 ', 'TRANSFER'],
    ['unknown', 'UNKNOWN'],
    ['不明款认领', 'UNKNOWN'],
    ['不明款', 'UNKNOWN'],
    ['未知', 'UNKNOWN'],
    [' 现金 ', '现金'],
];
foreach ($normalizeCases as $case) {
    assertSameValue(
        $case[1],
        $policy->normalizePaymentMethodCode($case[0]),
        'Payment method normalization failed for ' . var_export($case[0], true)
    );
}

$inferCases = [
    ['1234567890', '原值', 'POS'],
    ['810354444674', '原值', 'POS'],
    [' 1234 567890 ', '原值', 'POS'],
    ['210001500EZ9A1YLXJK', '原值', 'TRANSFER'],
    ['', '原值', 'UNKNOWN'],
    ['   ', '转账', 'UNKNOWN'],
    ['123456789012345', ' 转账 ', '转账'],
    ['12345', ' 现金 ', '现金'],
];
foreach ($inferCases as $case) {
    assertSameValue(
        $case[2],
        $policy->inferPaymentMethodByBankSerialNo($case[0], $case[1]),
        'Payment method inference failed for serial ' . var_export($case[0], true)
    );
}

$labelCases = [
    ['POS', 'POS 收款'],
    ['POS刷卡', 'POS 收款'],
    ['TRANSFER', '银行转账'],
    ['转账', '银行转账'],
    ['UNKNOWN', '不明款认领'],
    ['', '不明款认领'],
    ['现金', '现金'],
];
foreach ($labelCases as $case) {
    assertSameValue(
        $case[1],
        $policy->paymentMethodLabel($case[0]),
        'Payment method label failed for ' . var_export($case[0], true)
    );
}

echo 'Case fund business policy test passed' . PHP_EOL;
