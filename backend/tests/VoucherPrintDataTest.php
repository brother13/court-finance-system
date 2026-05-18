<?php

$source = file_get_contents(__DIR__ . '/../app/finance/model/Voucher.php');
$api = file_get_contents(__DIR__ . '/../app/finance/model/AccountSet.php');
$view = file_get_contents(__DIR__ . '/../..//frontend/src/views/voucher/VoucherListView.vue');
$voucherApi = file_get_contents(__DIR__ . '/../..//frontend/src/api/voucher.ts');

$checks = [
    [$source, "case 'printData'", 'Voucher should route printData action'],
    [$source, 'public function printData', 'Voucher should expose printData method'],
    [$source, "requirePermission('voucher:print')", 'Voucher print data should require voucher print permission'],
    [$source, 'voucher_print_line_count', 'Voucher print data should return configured print line count'],
    [$source, 'paper_size', 'Voucher print data should return configured paper size'],
    [$source, 'loadVouchersForPrint', 'Voucher print data should load selected vouchers in a dedicated helper'],
    [$source, 'fillVoucherPrintDetails', 'Voucher print data should include voucher detail rows'],
    [$source, 'markVouchersPrinted', 'Voucher print data should mark printed vouchers'],
    [$voucherApi, "apiAction('/voucher/printData'", 'frontend voucher API should call printData action'],
    [$view, 'printSelectedVouchers', 'Voucher list should print selected vouchers'],
    [$view, 'createVoucherPrintPdf', 'Voucher list should generate a voucher PDF'],
    [$view, 'window.open', 'Voucher list should open generated PDF in browser preview'],
    [$view, "context.hasPermission('voucher:print')", 'Voucher list should gate print button by voucher print permission'],
];

foreach ($checks as [$haystack, $needle, $message]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Voucher print data test passed' . PHP_EOL;
