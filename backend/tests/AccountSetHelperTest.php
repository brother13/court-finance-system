<?php

function input($key = '', $default = null)
{
    return $default;
}

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

function getNowTime()
{
    return date('Y-m-d H:i:s');
}

function uuid()
{
    return '12345678-1234-4000-8000-123456789abc';
}

require_once __DIR__ . '/../app/finance/model/Common.php';

$modelPath = __DIR__ . '/../app/finance/model/AccountSet.php';
if (!file_exists($modelPath)) {
    fwrite(STDERR, 'AccountSet model does not exist' . PHP_EOL);
    exit(1);
}

require_once $modelPath;

use app\finance\model\AccountSet;

$accountSet = new AccountSet();

$formatMethod = new ReflectionMethod(AccountSet::class, 'formatPeriodLabel');
$formatMethod->setAccessible(true);
if ($formatMethod->invoke($accountSet, '2026-05') !== '2026年05月') {
    fwrite(STDERR, 'Expected 2026-05 to format as 2026年05月' . PHP_EOL);
    exit(1);
}

$currentMethod = new ReflectionMethod(AccountSet::class, 'currentPeriodValue');
$currentMethod->setAccessible(true);
if ($currentMethod->invoke($accountSet, '2026-03', '') !== '2026-03') {
    fwrite(STDERR, 'Expected empty voucher date to fall back to enabled period' . PHP_EOL);
    exit(1);
}
if ($currentMethod->invoke($accountSet, '2026-03', '2026-11-18') !== '2026-11') {
    fwrite(STDERR, 'Expected voucher date to determine current period' . PHP_EOL);
    exit(1);
}

$codeMethod = new ReflectionMethod(AccountSet::class, 'generateSetCode');
$codeMethod->setAccessible(true);
$code = $codeMethod->invoke($accountSet, 'CASE_FUND', '2026-05');
if (strpos($code, 'CASE_FUND_202605_') !== 0) {
    fwrite(STDERR, 'Expected generated set code to include biz type and enabled period, got ' . $code . PHP_EOL);
    exit(1);
}

echo 'Account set helper test passed' . PHP_EOL;
