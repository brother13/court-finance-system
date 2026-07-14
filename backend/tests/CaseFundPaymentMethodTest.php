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
    return date('Y-m-d H:i:s');
}

function uuid()
{
    return '12345678-1234-4000-8000-123456789abc';
}

$base = dirname(__DIR__);
require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\model\CaseFund;

$source = file_get_contents($base . '/app/finance/model/CaseFund.php');
$policySource = file_get_contents($base . '/app/finance/service/casefund/policy/CaseFundBusinessPolicy.php');
$ddl = file_get_contents($base . '/database/court_finance_ddl.sql');
$preset = file_get_contents($base . '/database/account_sets_preset.sql');
$legacyPatch = file_get_contents($base . '/database/finance_standard_patch.sql');
$datedPatchPath = $base . '/database/2026-06-03-case-fund-payment-method.sql';

if (!file_exists($datedPatchPath)) {
    fwrite(STDERR, 'Dated payment-method voucher patch should exist' . PHP_EOL);
    exit(1);
}
$datedPatch = file_get_contents($datedPatchPath);

foreach ([
    'generate_payment_voucher_by_method_flag tinyint not null default 0',
    'generate_payment_voucher_by_method_flag',
] as $needle) {
    if (strpos($ddl, $needle) === false) {
        fwrite(STDERR, 'DDL should include payment-method voucher flag: ' . $needle . PHP_EOL);
        exit(1);
    }
}

if (strpos($datedPatch, 'alter table fin_account_set add column generate_payment_voucher_by_method_flag') === false) {
    fwrite(STDERR, 'Dated patch should add payment-method voucher flag idempotently' . PHP_EOL);
    exit(1);
}

if (strpos($legacyPatch, 'generate_payment_voucher_by_method_flag') !== false) {
    fwrite(STDERR, 'Legacy finance_standard_patch.sql should not contain this dated database change' . PHP_EOL);
    exit(1);
}

if (strpos($preset, 'generate_payment_voucher_by_method_flag') === false) {
    fwrite(STDERR, 'Account set preset should include payment-method voucher flag' . PHP_EOL);
    exit(1);
}

foreach ([
    'inferPaymentMethodByBankSerialNo',
    'in_array($length, [10, 12], true)',
    'paymentMethodOptions',
    "'POS'",
    "'TRANSFER'",
    "'UNKNOWN'",
] as $needle) {
    if (strpos($policySource, $needle) === false) {
        fwrite(STDERR, 'Case fund business policy should implement payment-method rule: ' . $needle . PHP_EOL);
        exit(1);
    }
}

foreach ([
    '$paymentMethodFilter',
    '$where[\'payment_method\']',
    "'PAYMENT_METHOD'",
    'generatePaymentVoucherByMethodFlag',
    'buildPaymentVoucherDetailsData',
    "'summary' => \$detailsData[0]['summary']",
] as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, 'CaseFund should implement payment-method behavior: ' . $needle . PHP_EOL);
        exit(1);
    }
}

$optionsMethod = new ReflectionMethod(CaseFund::class, 'paymentMethodOptions');
$optionsMethod->setAccessible(true);
$caseFund = new CaseFund();
$paymentMethodOptions = $optionsMethod->invoke($caseFund);
if (($paymentMethodOptions['UNKNOWN'] ?? '') !== '不明款认领') {
    fwrite(STDERR, 'UNKNOWN payment method label should be 不明款认领' . PHP_EOL);
    exit(1);
}

$method = new ReflectionMethod(CaseFund::class, 'inferPaymentMethodByBankSerialNo');
$method->setAccessible(true);

$cases = [
    ['1234567890', '原值', 'POS'],
    ['810354444674', '原值', 'POS'],
    ['210001500EZ9A1YLXJK', '原值', 'TRANSFER'],
    ['', '原值', 'UNKNOWN'],
    ['   ', '转账', 'UNKNOWN'],
    ['12345', '转账', '转账'],
];
foreach ($cases as $case) {
    $actual = $method->invoke($caseFund, $case[0], $case[1]);
    if ($actual !== $case[2]) {
        fwrite(STDERR, 'Payment method inference failed for serial [' . $case[0] . '], expected ' . $case[2] . ', got ' . $actual . PHP_EOL);
        exit(1);
    }
}

$validationMethod = new ReflectionMethod(CaseFund::class, 'paymentRowValidationErrors');
$validationMethod->setAccessible(true);
$row = [
    'source_row_no' => 77,
    'case_no' => '（2026）辽0106民初1号',
    'business_type' => '诉讼费预收',
    'payer_name' => '张三',
    'party_name' => '',
    'payment_amount' => '10.00',
    'payment_date' => '2026-01-05',
    'receipt_no' => '',
    'bank_serial_no' => '1.3907186942727182889049956068E-309',
    'payment_order_no' => '',
    'bank_account_no' => '',
];
$errors = $validationMethod->invoke($caseFund, $row, 'LITIGATION_FEE', ['诉讼费预收']);
if (empty($errors) || strpos(implode("\n", $errors), '银行流水号格式异常') === false) {
    fwrite(STDERR, 'Payment import should reject scientific-notation bank serial numbers' . PHP_EOL);
    exit(1);
}

$summaryMethod = new ReflectionMethod(CaseFund::class, 'buildPaymentMethodVoucherSummary');
$summaryMethod->setAccessible(true);
$posSummary = $summaryMethod->invoke($caseFund, 'POS', [
    ['payment_date' => '2026-02-02'],
]);
if ($posSummary !== '2026-02-02 收 POS 合计') {
    fwrite(STDERR, 'POS payment voucher first summary should be date + 收 POS 合计, got ' . $posSummary . PHP_EOL);
    exit(1);
}

$posPatchPath = $base . '/database/2026-06-03-case-fund-payment-method-pos-12.sql';
if (!file_exists($posPatchPath)) {
    fwrite(STDERR, 'Dated 12-digit POS correction patch should exist' . PHP_EOL);
    exit(1);
}
$posPatch = file_get_contents($posPatchPath);
foreach (['char_length(trim(bank_serial_no)) = 12', "payment_method = 'POS'", "payment_method = 'TRANSFER'", "payment_method = 'UNKNOWN'"] as $needle) {
    if (strpos($posPatch, $needle) === false) {
        fwrite(STDERR, '12-digit POS correction patch should contain: ' . $needle . PHP_EOL);
        exit(1);
    }
}

echo 'Case fund payment method test passed' . PHP_EOL;
