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

class CaseFundRefundImportTestModel extends CaseFund
{
    public $receiptTraceRequired = true;

    protected function refundImportRequiresReceiptTrace($bizType, $rows = [])
    {
        return $this->receiptTraceRequired;
    }
}

$xlsPath = '/Users/apple/Documents/出账查询 2024(收据号).xls';
if (!file_exists($xlsPath)) {
    fwrite(STDERR, 'Sample refund xls file does not exist' . PHP_EOL);
    exit(1);
}

$caseFund = new CaseFundRefundImportTestModel();
$parseMethod = new ReflectionMethod(CaseFund::class, 'parseRefundImportRowsFromXls');
$parseMethod->setAccessible(true);
$rows = $parseMethod->invoke($caseFund, file_get_contents($xlsPath));

if (count($rows) !== 29) {
    fwrite(STDERR, 'Expected 29 refund rows, got ' . count($rows) . PHP_EOL);
    exit(1);
}

$first = $rows[0];
$expected = [
    'case_no' => '（2024）辽0111执恢11号',
    'handler_name' => '王立东',
    'clerk_name' => '赵莹',
    'refund_date' => '2024-12-26',
    'source_receipt_no' => '20240000561672',
    'source_receipt_date' => '2024-12-17',
    'out_status' => '银行处理中',
    'out_type' => '执行、调解款发放',
    'litigation_position' => '申请执行人',
    'party_name' => '郭祥',
    'refund_amount' => '9029.00',
    'total_refund_amount' => '9029.00',
    'payee_party_relation' => '本人账户',
    'payment_method' => '法银直联',
    'actual_payee_name' => '郭祥',
    'payee_bank_account_name' => '郭祥',
    'payee_bank_account_no' => '6227000733140383356',
    'payee_bank_name' => '中国建设银行股份有限公司沈阳苏铁支行',
    'unionpay_no' => '105221004017',
    'handler_note' => '案款发放',
    'applicant_name' => '王立东',
];

foreach ($expected as $field => $value) {
    if (($first[$field] ?? null) !== $value) {
        fwrite(STDERR, 'Unexpected first row ' . $field . ': ' . var_export($first[$field] ?? null, true) . PHP_EOL);
        exit(1);
    }
}

if (!array_key_exists('invoice_date', $first) || $first['invoice_date'] !== null) {
    fwrite(STDERR, 'Blank nullable invoice date should be parsed as NULL for MySQL date columns' . PHP_EOL);
    exit(1);
}

if (empty($first['source_fingerprint']) || strlen($first['source_fingerprint']) !== 64) {
    fwrite(STDERR, 'Expected stable sha256 source fingerprint' . PHP_EOL);
    exit(1);
}

$source = file_get_contents($modelPath);
foreach ([
    'updateExistingRefundFromImport' => 'Refund import should overwrite existing inactive generated rows',
    'canOverwriteGeneratedBusinessRow' => 'Refund import should allow overwriting generated rows only when original voucher is deleted or missing',
    'hasActiveGeneratedVoucher($row)' => 'Refund import should keep active generated voucher rows from being overwritten',
    "'updated' => \$updated" => 'Refund import result should report updated rows',
] as $needle => $message) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$allowedMethod = new ReflectionMethod(CaseFund::class, 'allowedRefundOutTypes');
$allowedMethod->setAccessible(true);
$caseFundTypes = $allowedMethod->invoke($caseFund, 'CASE_FUND');
$litigationFeeTypes = $allowedMethod->invoke($caseFund, 'LITIGATION_FEE');
if (!in_array('执行、调解款发放', $caseFundTypes, true)) {
    fwrite(STDERR, 'CASE_FUND should allow execution mediation refund type' . PHP_EOL);
    exit(1);
}
if (!in_array('诉讼费退费', $litigationFeeTypes, true)) {
    fwrite(STDERR, 'LITIGATION_FEE should allow litigation fee refund type' . PHP_EOL);
    exit(1);
}

$headersMethod = new ReflectionMethod(CaseFund::class, 'refundImportRequiredHeaders');
$headersMethod->setAccessible(true);
$caseFundHeaders = $headersMethod->invoke($caseFund, 'CASE_FUND');
$litigationHeaders = $headersMethod->invoke($caseFund, 'LITIGATION_FEE');
$caseFundHeadersWithoutReceiptAux = $headersMethod->invoke($caseFund, 'CASE_FUND', false);
if (!in_array('来源票据号码', $caseFundHeaders, true) || !in_array('来源票据开票日期', $caseFundHeaders, true)) {
    fwrite(STDERR, 'CASE_FUND refund import should still require source receipt headers' . PHP_EOL);
    exit(1);
}
if (in_array('来源票据号码', $caseFundHeadersWithoutReceiptAux, true) || in_array('出账单号', $caseFundHeadersWithoutReceiptAux, true)) {
    fwrite(STDERR, 'CASE_FUND refund import should not require source receipt or out-order headers when refund subjects do not use receipt_no auxiliary accounting' . PHP_EOL);
    exit(1);
}
if (in_array('来源票据号码', $litigationHeaders, true) || in_array('来源票据开票日期', $litigationHeaders, true)) {
    fwrite(STDERR, 'LITIGATION_FEE refund import should not require source receipt headers' . PHP_EOL);
    exit(1);
}
if (!in_array('出账单号', $litigationHeaders, true)) {
    fwrite(STDERR, 'LITIGATION_FEE refund import should keep requiring the out-order header' . PHP_EOL);
    exit(1);
}
if (in_array('总出账金额', $caseFundHeaders, true) || in_array('总出账金额', $litigationHeaders, true)) {
    fwrite(STDERR, 'Refund import should not require total refund amount header for either account set type' . PHP_EOL);
    exit(1);
}

$validateMethod = new ReflectionMethod(CaseFund::class, 'validateRefundRows');
$validateMethod->setAccessible(true);
$errors = $validateMethod->invoke($caseFund, [$first], 'CASE_FUND');
if (!empty($errors)) {
    fwrite(STDERR, 'CASE_FUND validation should accept sample refund rows: ' . implode('; ', array_slice($errors, 0, 5)) . PHP_EOL);
    exit(1);
}

$messageMethod = new ReflectionMethod(CaseFund::class, 'refundImportErrorMessage');
$messageMethod->setAccessible(true);
$wrongCaseFundRows = [$first];
$wrongCaseFundRows[0]['out_type'] = '诉讼费退费';
$errors = $validateMethod->invoke($caseFund, $wrongCaseFundRows, 'CASE_FUND');
if (empty($errors) || $messageMethod->invoke($caseFund, $errors, 'CASE_FUND') !== '导入的不是案款数据') {
    fwrite(STDERR, 'CASE_FUND wrong out type should use case fund specific import error message' . PHP_EOL);
    exit(1);
}

$litigationRows = [$first];
$litigationRows[0]['out_type'] = '诉讼费退费';
$errors = $validateMethod->invoke($caseFund, $litigationRows, 'LITIGATION_FEE');
if (!empty($errors)) {
    fwrite(STDERR, 'LITIGATION_FEE validation should accept litigation fee refund rows' . PHP_EOL);
    exit(1);
}

$litigationRowsWithoutReceipt = [$litigationRows[0]];
$litigationRowsWithoutReceipt[0]['receipt_no'] = '';
$litigationRowsWithoutReceipt[0]['source_receipt_no'] = '';
$litigationRowsWithoutReceipt[0]['out_order_no'] = '';
$errors = $validateMethod->invoke($caseFund, $litigationRowsWithoutReceipt, 'LITIGATION_FEE');
if (!empty($errors)) {
    fwrite(STDERR, 'LITIGATION_FEE refund import should not require receipt fields for auxiliary accounting' . PHP_EOL);
    exit(1);
}

$caseFundRowsWithoutReceipt = [$first];
$caseFundRowsWithoutReceipt[0]['receipt_no'] = '';
$caseFundRowsWithoutReceipt[0]['source_receipt_no'] = '';
$caseFundRowsWithoutReceipt[0]['out_order_no'] = '';
$errors = $validateMethod->invoke($caseFund, $caseFundRowsWithoutReceipt, 'CASE_FUND');
if (empty($errors) || strpos(implode("\n", $errors), '至少填写一项用于追溯') === false) {
    fwrite(STDERR, 'CASE_FUND refund import should still require receipt or out-order trace fields' . PHP_EOL);
    exit(1);
}

$caseFund->receiptTraceRequired = false;
$errors = $validateMethod->invoke($caseFund, $caseFundRowsWithoutReceipt, 'CASE_FUND');
$caseFund->receiptTraceRequired = true;
if (!empty($errors)) {
    fwrite(STDERR, 'CASE_FUND refund import should not require receipt or out-order trace fields when refund subjects do not use receipt_no auxiliary accounting' . PHP_EOL);
    exit(1);
}

$wrongLitigationRows = [$first];
$wrongLitigationRows[0]['out_type'] = '执行、调解款发放';
$errors = $validateMethod->invoke($caseFund, $wrongLitigationRows, 'LITIGATION_FEE');
if (empty($errors) || $messageMethod->invoke($caseFund, $errors, 'LITIGATION_FEE') !== '导入的不是诉讼费数据') {
    fwrite(STDERR, 'LITIGATION_FEE wrong out type should use litigation fee specific import error message' . PHP_EOL);
    exit(1);
}

echo 'Case fund refund import test passed' . PHP_EOL;
