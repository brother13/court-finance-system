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

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
if (!file_exists($modelPath)) {
    fwrite(STDERR, 'CaseFund model does not exist' . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once $modelPath;

use app\finance\model\CaseFund;

$xlsPath = '/Users/apple/Documents/缴费登记.xls';
if (!file_exists($xlsPath)) {
    fwrite(STDERR, 'Sample payment xls file does not exist' . PHP_EOL);
    exit(1);
}
$litigationXlsPath = '/Users/apple/Documents/缴费登记 su.xls';
if (!file_exists($litigationXlsPath)) {
    fwrite(STDERR, 'Sample litigation fee payment xls file does not exist' . PHP_EOL);
    exit(1);
}

$caseFund = new CaseFund();
$parseMethod = new ReflectionMethod(CaseFund::class, 'parsePaymentImportRowsFromXls');
$parseMethod->setAccessible(true);
$rows = $parseMethod->invoke($caseFund, file_get_contents($xlsPath));

if (count($rows) !== 19) {
    fwrite(STDERR, 'Expected 19 payment rows, got ' . count($rows) . PHP_EOL);
    exit(1);
}

$first = $rows[0];
$expected = [
    'case_no' => '（2025）辽0103执1348号',
    'business_type' => '执行、调解款',
    'payer_name' => '刘丹宁',
    'party_name' => '王楠',
    'payment_amount' => '551788.00',
    'payment_date' => '2025-06-30',
    'payment_time' => '2025-06-30 16:48:01',
    'receipt_no' => '0000665970',
    'bank_account_no' => '0334210102000370000717900',
    'bank_serial_no' => '20250630900481000173',
    'deposit_revoke_flag' => 0,
];

foreach ($expected as $field => $value) {
    if (($first[$field] ?? null) !== $value) {
        fwrite(STDERR, 'Unexpected first row ' . $field . ': ' . var_export($first[$field] ?? null, true) . PHP_EOL);
        exit(1);
    }
}

if (empty($first['source_fingerprint']) || strlen($first['source_fingerprint']) !== 32) {
    fwrite(STDERR, 'Expected stable source fingerprint' . PHP_EOL);
    exit(1);
}

$third = $rows[2];
if ($third['payment_amount'] !== '117.70' || $third['trial_case_no'] !== '(2024)辽0103民初21275号') {
    fwrite(STDERR, 'Expected decimals and half-width case number to remain parseable' . PHP_EOL);
    exit(1);
}

$duplicateKeyMethod = new ReflectionMethod(CaseFund::class, 'paymentImportDuplicateKey');
$duplicateKeyMethod->setAccessible(true);
$receiptKey = $duplicateKeyMethod->invoke($caseFund, [
    'receipt_no' => '0000665970',
    'bank_account_no' => 'A001',
    'payment_amount' => '100.00',
]);
if ($receiptKey !== 'receipt:0000665970') {
    fwrite(STDERR, 'Payment import duplicate key should prefer receipt no' . PHP_EOL);
    exit(1);
}
$accountAmountKey = $duplicateKeyMethod->invoke($caseFund, [
    'receipt_no' => '',
    'bank_account_no' => '0334210102000370000717900',
    'payment_amount' => '551788.00',
]);
if ($accountAmountKey !== 'account_amount:0334210102000370000717900|551788.00') {
    fwrite(STDERR, 'Payment import duplicate key should use bank account plus amount when receipt no is empty' . PHP_EOL);
    exit(1);
}

$source = file_get_contents($modelPath);
foreach ([
    'loadExistingPaymentsForImport' => 'Payment import should load existing rows by duplicate key',
    'updateExistingPaymentFromImport' => 'Payment import should overwrite ungenerated existing rows',
    "'voucher_status'] !== 'UNGENERATED'" => 'Payment import should skip generated existing rows',
    "'updated' => \$updated" => 'Payment import result should report updated rows',
] as $needle => $message) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$allowedMethod = new ReflectionMethod(CaseFund::class, 'allowedPaymentBusinessTypes');
$allowedMethod->setAccessible(true);
$caseFundTypes = $allowedMethod->invoke($caseFund, 'CASE_FUND');
$litigationFeeTypes = $allowedMethod->invoke($caseFund, 'LITIGATION_FEE');
if (!in_array('执行、调解款', $caseFundTypes, true) || !in_array('执行、调节款', $caseFundTypes, true)) {
    fwrite(STDERR, 'CASE_FUND should allow execution mediation payment types' . PHP_EOL);
    exit(1);
}
if (!in_array('预收诉讼费', $litigationFeeTypes, true) || !in_array('诉讼费预收', $litigationFeeTypes, true)) {
    fwrite(STDERR, 'LITIGATION_FEE should allow prepaid litigation fee payment type aliases' . PHP_EOL);
    exit(1);
}

$validateMethod = new ReflectionMethod(CaseFund::class, 'validatePaymentRows');
$validateMethod->setAccessible(true);
$wrongBizRows = [$rows[0]];
$wrongBizRows[0]['business_type'] = '预收诉讼费';
$wrongBizErrors = $validateMethod->invoke($caseFund, $wrongBizRows, 'CASE_FUND');
if (empty($wrongBizErrors) || strpos(implode("\n", $wrongBizErrors), '当前账套不允许导入业务类型') === false) {
    fwrite(STDERR, 'CASE_FUND validation should reject prepaid litigation fee rows' . PHP_EOL);
    exit(1);
}

$noReceiptRows = [$rows[0]];
$noReceiptRows[0]['receipt_no'] = '';
$noReceiptRows[0]['bank_serial_no'] = '';
$noReceiptRows[0]['payment_order_no'] = '';
$noReceiptRows[0]['bank_account_no'] = '0334210102000370000717900';
$errors = $validateMethod->invoke($caseFund, $noReceiptRows, 'CASE_FUND');
if (!empty($errors)) {
    fwrite(STDERR, 'Payment import validation should allow bank account plus amount as trace key' . PHP_EOL);
    exit(1);
}

$messageMethod = new ReflectionMethod(CaseFund::class, 'paymentImportErrorMessage');
$messageMethod->setAccessible(true);
if ($messageMethod->invoke($caseFund, $wrongBizErrors, 'CASE_FUND') !== '导入的不是案款数据') {
    fwrite(STDERR, 'CASE_FUND wrong business type should use case fund specific import error message' . PHP_EOL);
    exit(1);
}

$litigationRows = [$rows[0]];
$litigationRows[0]['business_type'] = '预收诉讼费';
$errors = $validateMethod->invoke($caseFund, $litigationRows, 'LITIGATION_FEE');
if (!empty($errors)) {
    fwrite(STDERR, 'LITIGATION_FEE validation should accept prepaid litigation fee rows' . PHP_EOL);
    exit(1);
}

$litigationRows[0]['business_type'] = '诉讼费预收';
$errors = $validateMethod->invoke($caseFund, $litigationRows, 'LITIGATION_FEE');
if (!empty($errors)) {
    fwrite(STDERR, 'LITIGATION_FEE validation should accept exported litigation fee prepaid rows' . PHP_EOL);
    exit(1);
}

$largeCaseFundPath = '/Users/apple/Documents/苏家屯/缴费登记 暂存款1-4.xls';
if (file_exists($largeCaseFundPath)) {
    $largeRows = $parseMethod->invoke($caseFund, file_get_contents($largeCaseFundPath));
    if (count($largeRows) !== 1119) {
        fwrite(STDERR, 'Expected 1119 rows from large case fund payment xls, got ' . count($largeRows) . PHP_EOL);
        exit(1);
    }
    $largeRowByNo = [];
    foreach ($largeRows as $row) {
        $largeRowByNo[$row['source_row_no']] = $row;
    }
    if (($largeRowByNo[69]['payment_time'] ?? '') !== '2026-04-24 15:21:30'
        || ($largeRowByNo[69]['receipt_no'] ?? '') !== '0001776218'
        || ($largeRowByNo[69]['bank_serial_no'] ?? '') !== '210001500EZ9A1YLXJK') {
        fwrite(STDERR, 'Large case fund payment xls should parse SST continued strings' . PHP_EOL);
        exit(1);
    }
    $errors = $validateMethod->invoke($caseFund, $largeRows, 'CASE_FUND');
    if (!empty($errors)) {
        fwrite(STDERR, 'Large case fund payment xls should pass CASE_FUND validation: ' . $errors[0] . PHP_EOL);
        exit(1);
    }
}

$wrongLitigationRows = [$rows[0]];
$wrongLitigationRows[0]['business_type'] = '执行、调解款';
$errors = $validateMethod->invoke($caseFund, $wrongLitigationRows, 'LITIGATION_FEE');
if (empty($errors) || $messageMethod->invoke($caseFund, $errors, 'LITIGATION_FEE') !== '导入的不是诉讼费数据') {
    fwrite(STDERR, 'LITIGATION_FEE wrong business type should use litigation fee specific import error message' . PHP_EOL);
    exit(1);
}

$litigationImportRows = $parseMethod->invoke($caseFund, file_get_contents($litigationXlsPath));
if (count($litigationImportRows) !== 99) {
    fwrite(STDERR, 'Expected 99 valid litigation fee payment rows after parsing continued SST strings, got ' . count($litigationImportRows) . PHP_EOL);
    exit(1);
}
$errors = $validateMethod->invoke($caseFund, $litigationImportRows, 'LITIGATION_FEE');
if (!empty($errors)) {
    fwrite(STDERR, 'Litigation fee payment sample should validate after skipping non-detail rows: ' . implode('; ', array_slice($errors, 0, 5)) . PHP_EOL);
    exit(1);
}

echo 'Case fund payment import test passed' . PHP_EOL;
