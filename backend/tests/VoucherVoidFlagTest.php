<?php

$voucher = file_get_contents(__DIR__ . '/../app/finance/model/Voucher.php');
$patch = file_get_contents(__DIR__ . '/../database/2026-08-13-sync-voided-voucher-flag.sql');

if (strpos($voucher, "\$update = ['status' => 'VOIDED', 'void_flag' => '1'];") === false) {
    fwrite(STDERR, 'Voiding a voucher must persist both VOIDED status and void_flag=1' . PHP_EOL);
    exit(1);
}

$requiredPatchFragments = [
    'update fin_voucher',
    "set void_flag = '1'",
    "status = 'VOIDED'",
    "void_flag <> '1'",
    'del_flag = 0',
];
foreach ($requiredPatchFragments as $fragment) {
    if (stripos($patch, $fragment) === false) {
        fwrite(STDERR, 'Voided voucher repair patch is missing: ' . $fragment . PHP_EOL);
        exit(1);
    }
}

echo 'Voucher void flag test passed' . PHP_EOL;
