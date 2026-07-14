<?php

function input($key = '', $default = null) { return $default; }
function config($key = '') {
    if ($key === 'default_account_set_id') return '00000000-0000-0000-0000-000000000101';
    if ($key === 'default_year') return '2026';
    return null;
}
function getNowTime() { return date('Y-m-d H:i:s'); }
function uuid() { return '12345678-1234-4000-8000-123456789abc'; }
function unknownPaymentImportCents($amount) {
    $text = trim((string)$amount);
    if ($text === '') return 0;
    $negative = false;
    if (substr($text, 0, 1) === '-') {
        $negative = true;
        $text = substr($text, 1);
    }
    $parts = explode('.', $text, 2);
    $yuan = preg_replace('/\D/', '', $parts[0]);
    $cent = isset($parts[1]) ? preg_replace('/\D/', '', $parts[1]) : '';
    $cent = substr(str_pad($cent, 2, '0'), 0, 2);
    $cents = ((int)$yuan) * 100 + (int)$cent;
    return $negative ? -$cents : $cents;
}

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$ddlPath = __DIR__ . '/../database/court_finance_ddl.sql';
$patchPath = __DIR__ . '/../database/finance_standard_patch.sql';
require_once __DIR__ . '/../app/finance/service/casefund/support/LegacySpreadsheetReader.php';
require_once __DIR__ . '/../app/finance/model/Common.php';
require_once $modelPath;

$ddlSource = file_get_contents($ddlPath);
if (strpos($ddlSource, 'uk_case_fund_unknown_payment_source on fin_case_fund_unknown_payment (account_set_id, source_fingerprint, del_flag)') === false) {
    fwrite(STDERR, 'Unknown payment DDL unique index should include del_flag for soft-delete reimport' . PHP_EOL);
    exit(1);
}

$patchSource = file_get_contents($patchPath);
if (strpos($patchSource, 'unique key uk_case_fund_unknown_payment_source (account_set_id, source_fingerprint, del_flag)') === false) {
    fwrite(STDERR, 'Unknown payment patch unique key should include del_flag for soft-delete reimport' . PHP_EOL);
    exit(1);
}
if (strpos($patchSource, 'drop index uk_case_fund_unknown_payment_source on fin_case_fund_unknown_payment') === false
    || strpos($patchSource, 'alter table fin_case_fund_unknown_payment add unique key uk_case_fund_unknown_payment_source (account_set_id, source_fingerprint, del_flag)') === false) {
    fwrite(STDERR, 'Unknown payment patch should migrate existing unique index to include del_flag' . PHP_EOL);
    exit(1);
}

use app\finance\model\CaseFund;

$xlsPath = __DIR__ . '/../../不明款查询 (4).xls';
if (!file_exists($xlsPath)) {
    fwrite(STDERR, 'Unknown payment sample xls does not exist' . PHP_EOL);
    exit(1);
}

$caseFund = new CaseFund();
$parseMethod = new ReflectionMethod(CaseFund::class, 'parseUnknownPaymentImportRowsFromXls');
$parseMethod->setAccessible(true);
$rows = $parseMethod->invoke($caseFund, file_get_contents($xlsPath));

if (count($rows) !== 37) {
    fwrite(STDERR, 'Expected 37 unknown payment rows, got ' . count($rows) . PHP_EOL);
    exit(1);
}

$first = $rows[0];
$expected = [
    'court_name' => '沈阳市苏家屯区人民法院',
    'claim_status' => '待认领',
    'payer_name' => '王雪梅',
    'unknown_amount' => '1612.00',
    'bank_summary' => '',
    'bank_remark' => '账号不存在账号错误',
    'transaction_date' => '2026-01-07',
    'transaction_time' => '2026-01-07 10:29:55',
    'court_bank_account_no' => '21050144000600000799',
    'arrival_method' => '其他方式',
    'payer_bank_name' => '中国农业银行股份有限公司沈阳苏家屯支行',
    'payer_account_no' => '6228480004804304473',
    'last_modified_time' => '2026-03-31 14:42:26',
];
foreach ($expected as $field => $value) {
    if (($first[$field] ?? null) !== $value) {
        fwrite(STDERR, 'Unexpected first row ' . $field . ': ' . var_export($first[$field] ?? null, true) . PHP_EOL);
        exit(1);
    }
}

$totalCents = 0;
foreach ($rows as $row) {
    $totalCents += unknownPaymentImportCents($row['unknown_amount']);
}
if ($totalCents !== 377613674) {
    fwrite(STDERR, 'Expected sample amount total 3776136.74, got cents ' . $totalCents . PHP_EOL);
    exit(1);
}

if (empty($first['source_fingerprint']) || strlen($first['source_fingerprint']) !== 64) {
    fwrite(STDERR, 'Expected stable sha256 source fingerprint' . PHP_EOL);
    exit(1);
}

$fingerprintMethod = new ReflectionMethod(CaseFund::class, 'unknownPaymentFingerprint');
$fingerprintMethod->setAccessible(true);
$payerOnlyA = $first;
$payerOnlyA['payer_name'] = '付款人甲';
$payerOnlyA['court_bank_account_no'] = '';
$payerOnlyA['payer_account_no'] = '';
$payerOnlyA['bank_remark'] = '';
$payerOnlyA['bank_summary'] = '';
$payerOnlyB = $payerOnlyA;
$payerOnlyB['payer_name'] = '付款人乙';
if ($fingerprintMethod->invoke($caseFund, $payerOnlyA) === $fingerprintMethod->invoke($caseFund, $payerOnlyB)) {
    fwrite(STDERR, 'Unknown payment fingerprint should include payer_name for payer-only trace rows' . PHP_EOL);
    exit(1);
}

$validateMethod = new ReflectionMethod(CaseFund::class, 'validateUnknownPaymentRows');
$validateMethod->setAccessible(true);
$errors = $validateMethod->invoke($caseFund, $rows, 'CASE_FUND');
if (!empty($errors)) {
    fwrite(STDERR, 'Sample unknown payments should pass CASE_FUND validation: ' . $errors[0] . PHP_EOL);
    exit(1);
}

$payerOnlyTrace = $first;
$payerOnlyTrace['source_row_no'] = 9001;
$payerOnlyTrace['bank_remark'] = '';
$payerOnlyTrace['bank_summary'] = '';
$payerOnlyTrace['court_bank_account_no'] = '';
$payerOnlyTrace['payer_account_no'] = '';
$payerOnlyTraceErrors = $validateMethod->invoke($caseFund, [$payerOnlyTrace], 'CASE_FUND');
if (!empty($payerOnlyTraceErrors)) {
    fwrite(STDERR, 'Unknown payment validation should allow payer_name as the only trace field: ' . $payerOnlyTraceErrors[0] . PHP_EOL);
    exit(1);
}

$bankRemarkOnlyTrace = $first;
$bankRemarkOnlyTrace['source_row_no'] = 9002;
$bankRemarkOnlyTrace['payer_name'] = '';
$bankRemarkOnlyTrace['bank_summary'] = '';
$bankRemarkOnlyTrace['court_bank_account_no'] = '';
$bankRemarkOnlyTrace['payer_account_no'] = '';
$bankRemarkOnlyTraceErrors = $validateMethod->invoke($caseFund, [$bankRemarkOnlyTrace], 'CASE_FUND');
if (!empty($bankRemarkOnlyTraceErrors)) {
    fwrite(STDERR, 'Unknown payment validation should allow bank_remark as the only trace field: ' . $bankRemarkOnlyTraceErrors[0] . PHP_EOL);
    exit(1);
}

$bankSummaryOnlyTrace = $first;
$bankSummaryOnlyTrace['source_row_no'] = 9003;
$bankSummaryOnlyTrace['payer_name'] = '';
$bankSummaryOnlyTrace['bank_remark'] = '';
$bankSummaryOnlyTrace['bank_summary'] = '电子汇入';
$bankSummaryOnlyTrace['court_bank_account_no'] = '';
$bankSummaryOnlyTrace['payer_account_no'] = '';
$bankSummaryOnlyTraceErrors = $validateMethod->invoke($caseFund, [$bankSummaryOnlyTrace], 'CASE_FUND');
if (!empty($bankSummaryOnlyTraceErrors)) {
    fwrite(STDERR, 'Unknown payment validation should allow bank_summary as the only trace field: ' . $bankSummaryOnlyTraceErrors[0] . PHP_EOL);
    exit(1);
}

$courtAccountOnlyTrace = $first;
$courtAccountOnlyTrace['source_row_no'] = 9004;
$courtAccountOnlyTrace['payer_name'] = '';
$courtAccountOnlyTrace['bank_remark'] = '';
$courtAccountOnlyTrace['bank_summary'] = '';
$courtAccountOnlyTrace['payer_account_no'] = '';
$courtAccountOnlyTraceErrors = $validateMethod->invoke($caseFund, [$courtAccountOnlyTrace], 'CASE_FUND');
if (!empty($courtAccountOnlyTraceErrors)) {
    fwrite(STDERR, 'Unknown payment validation should allow court_bank_account_no as the only trace field: ' . $courtAccountOnlyTraceErrors[0] . PHP_EOL);
    exit(1);
}

$payerAccountOnlyTrace = $first;
$payerAccountOnlyTrace['source_row_no'] = 9005;
$payerAccountOnlyTrace['payer_name'] = '';
$payerAccountOnlyTrace['bank_remark'] = '';
$payerAccountOnlyTrace['bank_summary'] = '';
$payerAccountOnlyTrace['court_bank_account_no'] = '';
$payerAccountOnlyTraceErrors = $validateMethod->invoke($caseFund, [$payerAccountOnlyTrace], 'CASE_FUND');
if (!empty($payerAccountOnlyTraceErrors)) {
    fwrite(STDERR, 'Unknown payment validation should allow payer_account_no as the only trace field: ' . $payerAccountOnlyTraceErrors[0] . PHP_EOL);
    exit(1);
}

$badDate = $first;
$badDate['source_row_no'] = 9006;
$badDate['transaction_date'] = 'not-a-date';
$badDateErrors = $validateMethod->invoke($caseFund, [$badDate], 'CASE_FUND');
if (empty($badDateErrors) || strpos(implode("\n", $badDateErrors), '交易日期不能为空或格式不正确') === false) {
    fwrite(STDERR, 'Unknown payment validation should reject invalid transaction_date' . PHP_EOL);
    exit(1);
}

$wrongBizErrors = $validateMethod->invoke($caseFund, [$first], 'LITIGATION_FEE');
if (empty($wrongBizErrors) || strpos(implode("\n", $wrongBizErrors), '当前账套类型不支持不明款业务') === false) {
    fwrite(STDERR, 'Non case-fund account sets should reject unknown payment import' . PHP_EOL);
    exit(1);
}

$summaryMethod = new ReflectionMethod(CaseFund::class, 'buildUnknownPaymentVoucherSummary');
$summaryMethod->setAccessible(true);
$summary = $summaryMethod->invoke($caseFund, $first);
if ($summary !== '收 不明款 王雪梅 账号不存在账号错误') {
    fwrite(STDERR, 'Unexpected unknown payment voucher summary: ' . $summary . PHP_EOL);
    exit(1);
}

$fallback = $first;
$fallback['bank_remark'] = '';
$fallback['bank_summary'] = '电子汇入';
if ($summaryMethod->invoke($caseFund, $fallback) !== '收 不明款 王雪梅 电子汇入') {
    fwrite(STDERR, 'Unknown payment summary should fall back to bank summary' . PHP_EOL);
    exit(1);
}

$source = file_get_contents($modelPath);
foreach ([
    "case 'unknownPaymentList'" => 'CaseFund should route unknownPaymentList',
    "case 'unknownPaymentImport'" => 'CaseFund should route unknownPaymentImport',
    'public function unknownPaymentList' => 'CaseFund should expose unknownPaymentList',
    'public function unknownPaymentImport' => 'CaseFund should expose unknownPaymentImport',
    "requirePermission('case_fund:view')" => 'Unknown payment list should check view permission',
    "requirePermission('case_fund:import')" => 'Unknown payment import should check import permission',
    'CASE_FUND_UNKNOWN_PAYMENT' => 'Unknown payment writes should use audit biz type',
] as $needle => $message) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Case fund unknown payment import test passed' . PHP_EOL;
