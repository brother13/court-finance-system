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
$method = new ReflectionMethod(Voucher::class, 'voucherDeleteBlockReason');
$method->setAccessible(true);

$cases = [
    [
        'name' => 'submitted manual voucher in open period can be deleted',
        'voucher' => ['status' => 'SUBMITTED', 'source_type' => 'MANUAL'],
        'period_status' => 'OPEN',
        'blocked' => false,
    ],
    [
        'name' => 'draft voucher with empty source in open period can be deleted',
        'voucher' => ['status' => 'DRAFT', 'source_type' => ''],
        'period_status' => 'OPEN',
        'blocked' => false,
    ],
    [
        'name' => 'audited voucher cannot be deleted',
        'voucher' => ['status' => 'AUDITED', 'source_type' => 'MANUAL'],
        'period_status' => 'OPEN',
        'blocked' => true,
    ],
    [
        'name' => 'closed period voucher cannot be deleted',
        'voucher' => ['status' => 'SUBMITTED', 'source_type' => 'MANUAL'],
        'period_status' => 'CLOSED',
        'blocked' => true,
    ],
    [
        'name' => 'auto carry voucher cannot be deleted',
        'voucher' => ['status' => 'SUBMITTED', 'source_type' => 'AUTO_CARRY'],
        'period_status' => 'OPEN',
        'blocked' => true,
    ],
];

foreach ($cases as $case) {
    $reason = $method->invoke($voucher, $case['voucher'], $case['period_status']);
    $isBlocked = $reason !== '';
    if ($isBlocked !== $case['blocked']) {
        fwrite(STDERR, $case['name'] . ' failed, reason: ' . $reason . PHP_EOL);
        exit(1);
    }
}

echo 'Voucher delete eligibility test passed' . PHP_EOL;
