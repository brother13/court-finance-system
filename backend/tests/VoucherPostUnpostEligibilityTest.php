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
$postMethod = new ReflectionMethod(Voucher::class, 'voucherPostBlockReason');
$postMethod->setAccessible(true);
$unpostMethod = new ReflectionMethod(Voucher::class, 'voucherUnpostBlockReason');
$unpostMethod->setAccessible(true);

$postCases = [
    [
        'name' => 'audited voucher in open period can be posted',
        'voucher' => ['status' => 'AUDITED', 'void_flag' => '0'],
        'period_status' => 'OPEN',
        'blocked' => false,
    ],
    [
        'name' => 'submitted voucher cannot be posted',
        'voucher' => ['status' => 'SUBMITTED', 'void_flag' => '0'],
        'period_status' => 'OPEN',
        'blocked' => true,
    ],
    [
        'name' => 'closed period voucher cannot be posted',
        'voucher' => ['status' => 'AUDITED', 'void_flag' => '0'],
        'period_status' => 'CLOSED',
        'blocked' => true,
    ],
    [
        'name' => 'voided voucher cannot be posted',
        'voucher' => ['status' => 'AUDITED', 'void_flag' => '1'],
        'period_status' => 'OPEN',
        'blocked' => true,
    ],
];

foreach ($postCases as $case) {
    $reason = $postMethod->invoke($voucher, $case['voucher'], $case['period_status']);
    $isBlocked = $reason !== '';
    if ($isBlocked !== $case['blocked']) {
        fwrite(STDERR, $case['name'] . ' failed, reason: ' . $reason . PHP_EOL);
        exit(1);
    }
}

$unpostCases = [
    [
        'name' => 'posted voucher in open period can be unposted',
        'voucher' => ['status' => 'POSTED'],
        'period_status' => 'OPEN',
        'blocked' => false,
    ],
    [
        'name' => 'audited voucher cannot be unposted',
        'voucher' => ['status' => 'AUDITED'],
        'period_status' => 'OPEN',
        'blocked' => true,
    ],
    [
        'name' => 'closed period voucher cannot be unposted',
        'voucher' => ['status' => 'POSTED'],
        'period_status' => 'CLOSED',
        'blocked' => true,
    ],
];

foreach ($unpostCases as $case) {
    $reason = $unpostMethod->invoke($voucher, $case['voucher'], $case['period_status']);
    $isBlocked = $reason !== '';
    if ($isBlocked !== $case['blocked']) {
        fwrite(STDERR, $case['name'] . ' failed, reason: ' . $reason . PHP_EOL);
        exit(1);
    }
}

echo 'Voucher post/unpost eligibility test passed' . PHP_EOL;
