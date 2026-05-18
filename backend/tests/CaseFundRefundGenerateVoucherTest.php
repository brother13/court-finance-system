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
    ["'out_order_no'", 'Refund voucher generation should use out_order_no for grouping'],
    ["'SINGLE:' . \$row['refund_id']", 'Refund voucher generation should fall back to single-row grouping when out_order_no is empty'],
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

echo 'Case fund refund generate voucher test passed' . PHP_EOL;
