<?php

$source = file_get_contents(__DIR__ . '/../app/finance/model/Voucher.php');

$checks = [
    [$source, "['AUDITED', 'POSTED', 'PRINTED', 'VOIDED']", 'Posted vouchers should be immutable in voucher editing'],
    [$source, "if (\$status === 'POSTED')", 'Saving vouchers should not bypass the post permission'],
    [$source, "if (\$before['status'] === 'POSTED')", 'Posted vouchers should not be directly voided'],
    [$source, "'POSTED'", 'Voucher workflow should include POSTED status'],
];

foreach ($checks as [$haystack, $needle, $message]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Voucher posted immutable test passed' . PHP_EOL;
