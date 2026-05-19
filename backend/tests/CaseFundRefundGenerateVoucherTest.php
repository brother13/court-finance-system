<?php

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$source = file_get_contents($modelPath);
$ddl = file_get_contents(__DIR__ . '/../database/court_finance_ddl.sql');
$patch = file_get_contents(__DIR__ . '/../database/finance_standard_patch.sql');

$checks = [
    ["case 'refundGenerateVoucher'", 'CaseFund should route refundGenerateVoucher'],
    ['public function refundGenerateVoucher', 'CaseFund should expose refundGenerateVoucher method'],
    ["'voucher_biz_type' => 'REFUND'", 'Refund voucher generation should use REFUND subject configs'],
    ["self::TABLE_REFUND", 'Refund voucher generation should read refund table'],
    ["'refund_ids'", 'Refund voucher generation should accept refund_ids'],
    ["'refund_date'", 'Refund voucher generation should group by refund date'],
    ["'refund_amount'", 'Refund voucher generation should use refund amount'],
    ["'out_type'", 'Refund voucher generation should use out_type subject config'],
    ["'refund_count'", 'Refund voucher generation should return refund count'],
    ["'CASE_FUND_REFUND'", 'Refund voucher generation should write refund audit log'],
    ["requirePermission('case_fund:generate_voucher')", 'Refund voucher generation should check generate_voucher permission'],
    ["'UNGENERATED'", 'Refund voucher generation should reject generated rows'],
    ["'GENERATED'", 'Refund voucher generation should mark generated rows'],
    ['ensureAuxArchives', 'Refund voucher generation should ensure case and receipt aux archives'],
    ['fin_voucher', 'Refund voucher generation should write voucher table'],
    ['fin_voucher_detail', 'Refund voucher generation should write voucher detail table'],
    ['fin_voucher_aux_value', 'Refund voucher generation should write voucher aux values'],
    ['generateRefundVoucherByOutOrderNoFlag', 'Refund voucher generation should read out-order grouping config'],
    ['groupRefundRowsForVoucher', 'Refund voucher generation should group rows through a dedicated helper'],
    ['assertRowsCanGenerateVoucher', 'Refund voucher generation should validate whether selected generated rows can be regenerated'],
    ['hasActiveGeneratedVoucher', 'Refund voucher generation should block duplicate generation when the original voucher is still active'],
    ["'GENERATED' &&", 'Refund voucher generation should inspect generated rows before allowing regeneration'],
    ["'out_order_no'", 'Refund voucher generation should use out_order_no for grouping'],
    ['$aggregateCreditBuckets', 'Refund voucher generation should aggregate credit lines inside a daily voucher'],
    ['$aggregateByOutOrderNo && $outOrderNo !== \'\'', 'Refund voucher generation should only aggregate non-empty out_order_no credit lines'],
    ['buildRefundAggregateCreditDetail', 'Refund voucher generation should build one credit line for an out-order group'],
];

foreach ($checks as [$needle, $message]) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

if (strpos($source, '缺少出账单号，不能按出账单号汇总生成凭证') !== false) {
    fwrite(STDERR, 'Empty out_order_no should be processed as a single refund voucher instead of failing' . PHP_EOL);
    exit(1);
}

$schemaChecks = [
    [$ddl, 'generate_refund_voucher_by_out_order_no_flag tinyint not null default 0', 'DDL should add refund out-order grouping flag'],
    [$patch, 'generate_refund_voucher_by_out_order_no_flag', 'Patch should add refund out-order grouping flag idempotently'],
];

foreach ($schemaChecks as [$haystack, $needle, $message]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

if (!function_exists('input')) {
    function input($key = '', $default = null)
    {
        return $default;
    }
}

if (!function_exists('config')) {
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
}

if (!function_exists('getNowTime')) {
    function getNowTime()
    {
        return date('Y-m-d H:i:s');
    }
}

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once $modelPath;

use app\finance\model\CaseFund;

$caseFund = new CaseFund();
$groupMethod = new ReflectionMethod(CaseFund::class, 'groupRefundRowsForVoucher');
$groupMethod->setAccessible(true);
$sampleRows = [
    ['refund_id' => 'refund-1', 'refund_date' => '2026-03-02', 'out_order_no' => 'OUT-001', 'case_no' => '案一'],
    ['refund_id' => 'refund-2', 'refund_date' => '2026-03-02', 'out_order_no' => 'OUT-001', 'case_no' => '案二'],
    ['refund_id' => 'refund-3', 'refund_date' => '2026-03-02', 'out_order_no' => '', 'case_no' => '案三'],
    ['refund_id' => 'refund-4', 'refund_date' => '2026-03-03', 'out_order_no' => 'OUT-001', 'case_no' => '案四'],
];

$singleGroups = $groupMethod->invoke($caseFund, $sampleRows, 0, 1);
if (count($singleGroups) !== 4) {
    fwrite(STDERR, 'Refund out-order grouping should require daily voucher generation; expected 4 single groups when by-day is off' . PHP_EOL);
    exit(1);
}
foreach ($singleGroups as $groupRows) {
    if (count($groupRows) !== 1) {
        fwrite(STDERR, 'Refund out-order grouping should not merge rows when by-day is off' . PHP_EOL);
        exit(1);
    }
}

$outOrderGroups = $groupMethod->invoke($caseFund, $sampleRows, 1, 1);
$groupSizes = array_map('count', $outOrderGroups);
sort($groupSizes);
if ($groupSizes !== [1, 3] || count($outOrderGroups['2026-03-02'] ?? []) !== 3 || count($outOrderGroups['2026-03-03'] ?? []) !== 1) {
    fwrite(STDERR, 'Daily refund generation should keep one voucher group per day; out_order_no only aggregates credit lines inside that daily voucher' . PHP_EOL);
    exit(1);
}

echo 'Case fund refund generate voucher test passed' . PHP_EOL;
