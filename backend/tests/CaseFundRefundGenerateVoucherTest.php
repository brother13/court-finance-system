<?php

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$source = file_get_contents($modelPath);

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
];

foreach ($checks as [$needle, $message]) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Case fund refund generate voucher test passed' . PHP_EOL;
