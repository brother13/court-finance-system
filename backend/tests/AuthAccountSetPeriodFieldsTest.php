<?php

$common = file_get_contents(__DIR__ . '/../app/finance/model/Common.php');
$auth = file_get_contents(__DIR__ . '/../app/finance/model/Auth.php');

$checks = [
    [$common, 'a.enabled_period', 'authorized account set list should include enabled_period'],
    [$auth, 'appendAccountSetPeriodFields', 'auth account set list should append period fields'],
    [$auth, "'current_period'", 'auth account set list should return current_period'],
    [$auth, "'enabled_period'", 'auth account set list should return enabled_period'],
];

foreach ($checks as [$haystack, $needle, $message]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Auth account set period fields test passed' . PHP_EOL;
