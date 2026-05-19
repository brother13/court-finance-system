<?php

$source = file_get_contents(__DIR__ . '/../app/finance/model/Voucher.php');

$checks = [
    [$source, 'ensurePeriodWritable($period,', 'Voucher write actions should check fiscal period writability'],
    [$source, '会计期间已结账，不允许修改凭证', 'Voucher save should reject closed periods'],
    [$source, '会计期间已结账，不允许审核或反审核', 'Voucher audit and unaudit should reject closed periods'],
    [$source, '会计期间已结账，不允许作废凭证', 'Voucher void should reject closed periods'],
];

foreach ($checks as [$haystack, $needle, $message]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Voucher closed period guard test passed' . PHP_EOL;
