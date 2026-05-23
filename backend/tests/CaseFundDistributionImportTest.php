<?php

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$ddlPath = __DIR__ . '/../database/court_finance_ddl.sql';
$patchPath = __DIR__ . '/../database/finance_standard_patch.sql';
$source = file_get_contents($modelPath);
$ddl = file_get_contents($ddlPath);
$patch = file_get_contents($patchPath);

foreach ([
    "case 'distributionList'" => 'CaseFund should route distributionList',
    "case 'distributionImport'" => 'CaseFund should route distributionImport',
    "case 'distributionDelete'" => 'CaseFund should route distributionDelete',
    'public function distributionList' => 'CaseFund should expose distributionList',
    'public function distributionImport' => 'CaseFund should expose distributionImport',
    'public function distributionDelete' => 'CaseFund should expose distributionDelete',
    "requirePermission('case_fund:view')" => 'Distribution list should check view permission',
    "requirePermission('case_fund:import')" => 'Distribution import should check import permission',
    'TABLE_DISTRIBUTION' => 'CaseFund should define distribution table constant',
    'parseDistributionImportRowsFromXls' => 'CaseFund should parse distribution xls rows',
    'validateDistributionRows' => 'CaseFund should validate distribution rows',
    'distributionFingerprint' => 'CaseFund should generate distribution fingerprint',
    'allowedDistributionInvoiceTypes' => 'CaseFund should define allowed distribution invoice types',
    'CASE_FUND_DISTRIBUTION' => 'Distribution import should write audit log',
] as $needle => $message) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

if (strpos($ddl, 'create table fin_case_fund_distribution') === false) {
    fwrite(STDERR, 'DDL should create fin_case_fund_distribution' . PHP_EOL);
    exit(1);
}
if (strpos($patch, 'create table if not exists fin_case_fund_distribution') === false) {
    fwrite(STDERR, 'Finance standard patch should create fin_case_fund_distribution idempotently' . PHP_EOL);
    exit(1);
}
if (strpos($ddl, 'uk_case_fund_distribution_source') === false || strpos($ddl, 'source_fingerprint, del_flag') === false) {
    fwrite(STDERR, 'Distribution DDL should keep soft-delete-aware source fingerprint index' . PHP_EOL);
    exit(1);
}

if (!function_exists('input')) {
    function input($key = '', $default = null) { return $default; }
}
if (!function_exists('config')) {
    function config($key = '') {
        if ($key === 'default_account_set_id') return '00000000-0000-0000-0000-000000000101';
        if ($key === 'default_year') return '2026';
        return null;
    }
}
if (!function_exists('getNowTime')) {
    function getNowTime() { return date('Y-m-d H:i:s'); }
}

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once $modelPath;

use app\finance\model\CaseFund;

function distributionImportCents($row)
{
    return (int)round(((float)$row['distribution_amount']) * 100);
}

$xlsPath = __DIR__ . '/../../分配清单(带单据号) (3).xls';
if (!is_file($xlsPath)) {
    fwrite(STDERR, 'Missing distribution sample xls file' . PHP_EOL);
    exit(1);
}

$caseFund = new CaseFund();
$parseMethod = new ReflectionMethod(CaseFund::class, 'parseDistributionImportRowsFromXls');
$parseMethod->setAccessible(true);
$rows = $parseMethod->invoke($caseFund, file_get_contents($xlsPath));

if (count($rows) !== 32) {
    fwrite(STDERR, 'Distribution import should parse 32 rows, got ' . count($rows) . PHP_EOL);
    exit(1);
}

$first = $rows[0];
$expectedFirst = [
    'source_row_no' => 2,
    'distribution_no' => 'ZXFP60726051900001',
    'in_case_no' => '（2024）辽0111执3548号',
    'in_judge_name' => '赵之泽',
    'in_party_name' => '吴健',
    'out_case_no' => '（2017）辽0111执611号',
    'out_judge_name' => '',
    'out_party_name' => '李洁',
    'invoice_type' => '执行、调解款',
    'receipt_no' => '0001759490',
    'distribution_amount' => '5084.00',
    'applicant_name' => '赵之泽',
    'apply_date' => '2026-05-19',
    'receive_date' => '2026-05-20',
    'period' => '2026-05',
    'fiscal_year' => '2026',
    'business_status' => '正常可操作',
    'description' => '',
    'original_invoice_date' => '2026-02-09',
];
foreach ($expectedFirst as $field => $expected) {
    if (($first[$field] ?? null) !== $expected) {
        fwrite(STDERR, "First distribution row {$field} mismatch: expected {$expected}, got " . ($first[$field] ?? 'NULL') . PHP_EOL);
        exit(1);
    }
}

$totalCents = 0;
foreach ($rows as $row) {
    $totalCents += distributionImportCents($row);
}
if ($totalCents !== 327331559) {
    fwrite(STDERR, 'Distribution import total cents mismatch: ' . $totalCents . PHP_EOL);
    exit(1);
}

$validateMethod = new ReflectionMethod(CaseFund::class, 'validateDistributionRows');
$validateMethod->setAccessible(true);
if ($validateMethod->invoke($caseFund, $rows, 'CASE_FUND') !== []) {
    fwrite(STDERR, 'CASE_FUND distribution rows should validate cleanly' . PHP_EOL);
    exit(1);
}
$litigationErrors = $validateMethod->invoke($caseFund, $rows, 'LITIGATION_FEE');
if (empty($litigationErrors) || strpos($litigationErrors[0], '当前账套类型不支持案款分配') === false) {
    fwrite(STDERR, 'Distribution import should reject non-CASE_FUND account sets' . PHP_EOL);
    exit(1);
}

$badRows = $rows;
$badRows[0]['receipt_no'] = '';
$badRows[0]['distribution_amount'] = '0.00';
$badErrors = $validateMethod->invoke($caseFund, [$badRows[0]], 'CASE_FUND');
if (count($badErrors) < 2) {
    fwrite(STDERR, 'Distribution validation should reject missing receipt and zero amount' . PHP_EOL);
    exit(1);
}

$fingerprintMethod = new ReflectionMethod(CaseFund::class, 'distributionFingerprint');
$fingerprintMethod->setAccessible(true);
$changedReceipt = $first;
$changedReceipt['receipt_no'] = 'DIFFERENT';
if ($fingerprintMethod->invoke($caseFund, $first) === $fingerprintMethod->invoke($caseFund, $changedReceipt)) {
    fwrite(STDERR, 'Distribution fingerprint should include receipt_no' . PHP_EOL);
    exit(1);
}
if (strlen($first['source_fingerprint']) !== 64) {
    fwrite(STDERR, 'Distribution fingerprint should be sha256 hex' . PHP_EOL);
    exit(1);
}

echo 'Case fund distribution import test passed' . PHP_EOL;
