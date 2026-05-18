<?php

$source = file_get_contents(__DIR__ . '/../app/finance/model/Voucher.php');

$checks = [
    ['resolveVoucherListPeriodRange', 'Voucher list should normalize period_start and period_end'],
    ["'period_start'", 'Voucher list should accept period_start'],
    ["'period_end'", 'Voucher list should accept period_end'],
    ["['>=', \$periodRange['start']]", 'Voucher header query should filter period range start'],
    ["['<=', \$periodRange['end']]", 'Voucher header query should filter period range end'],
    ['voucherIdsByDetailFilters($periodRange', 'Detail filters should use the same period range as header filters'],
    ['voucherIdsByAmount($periodRange', 'Amount detail filters should use period range'],
    ['voucherIdsByAux($periodRange', 'Auxiliary detail filters should use period range'],
];

foreach ($checks as [$needle, $message]) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Voucher period range list test passed' . PHP_EOL;
