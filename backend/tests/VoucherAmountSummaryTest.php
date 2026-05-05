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
    return '00000000-0000-4000-8000-000000000000';
}

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/Voucher.php';

use app\finance\model\Voucher;

$voucher = new Voucher();
$method = new ReflectionMethod(Voucher::class, 'sumVoucherAmounts');
$method->setAccessible(true);

$totals = $method->invoke($voucher, [
    ['debit_amount' => '10.10', 'credit_amount' => ''],
    ['debit_amount' => '0.20', 'credit_amount' => '0'],
    ['debit_amount' => '', 'credit_amount' => '5.15'],
    ['debit_amount' => '0', 'credit_amount' => '5.15'],
]);

if ($totals['debit_amount'] !== '10.30') {
    fwrite(STDERR, 'Expected debit_amount 10.30, got ' . $totals['debit_amount'] . PHP_EOL);
    exit(1);
}

if ($totals['credit_amount'] !== '10.30') {
    fwrite(STDERR, 'Expected credit_amount 10.30, got ' . $totals['credit_amount'] . PHP_EOL);
    exit(1);
}

echo 'Voucher amount summary test passed' . PHP_EOL;
