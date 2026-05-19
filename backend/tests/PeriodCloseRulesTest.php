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
require_once __DIR__ . '/../app/finance/model/Period.php';

use app\finance\model\Period;

$period = new Period();

$blockMethod = new ReflectionMethod(Period::class, 'periodCloseBlockItems');
$blockMethod->setAccessible(true);
$blocks = $blockMethod->invoke($period, '2026-05', 'OPEN', [
    'DRAFT' => 1,
    'SUBMITTED' => 2,
    'AUDITED' => 3,
    'POSTED' => 9,
]);
if (count($blocks) !== 3) {
    fwrite(STDERR, 'Period close should block draft, submitted and audited vouchers' . PHP_EOL);
    exit(1);
}

$openBlocks = $blockMethod->invoke($period, '2026-05', 'OPEN', [
    'POSTED' => 9,
    'VOIDED' => 1,
]);
if (count($openBlocks) !== 0) {
    fwrite(STDERR, 'Period close should allow posted and voided vouchers' . PHP_EOL);
    exit(1);
}

$closedBlocks = $blockMethod->invoke($period, '2026-05', 'CLOSED', []);
if (empty($closedBlocks) || strpos($closedBlocks[0]['message'], '不是开放状态') === false) {
    fwrite(STDERR, 'Period close should block non-open fiscal periods' . PHP_EOL);
    exit(1);
}

$nextPeriod = new ReflectionMethod(Period::class, 'nextPeriodValue');
$nextPeriod->setAccessible(true);
if ($nextPeriod->invoke($period, '2026-12') !== '2027-01') {
    fwrite(STDERR, 'Next period after December should be next year January' . PHP_EOL);
    exit(1);
}
if ($nextPeriod->invoke($period, '2026-05') !== '2026-06') {
    fwrite(STDERR, 'Next period inside a year should increment the month' . PHP_EOL);
    exit(1);
}

$yearPeriods = new ReflectionMethod(Period::class, 'yearPeriodValues');
$yearPeriods->setAccessible(true);
$values = $yearPeriods->invoke($period, 2027);
if (count($values) !== 12 || $values[0] !== '2027-01' || $values[11] !== '2027-12') {
    fwrite(STDERR, 'Year generation should produce 12 monthly periods' . PHP_EOL);
    exit(1);
}

$yearBlock = new ReflectionMethod(Period::class, 'yearEndBlockReason');
$yearBlock->setAccessible(true);
if ($yearBlock->invoke($period, '2026-11', 'CLOSED') === '') {
    fwrite(STDERR, 'Year-end generation should require December period' . PHP_EOL);
    exit(1);
}
if ($yearBlock->invoke($period, '2026-12', 'OPEN') === '') {
    fwrite(STDERR, 'Year-end generation should require closed December period' . PHP_EOL);
    exit(1);
}
if ($yearBlock->invoke($period, '2026-12', 'CLOSED') !== '') {
    fwrite(STDERR, 'Closed December period should allow year-end generation' . PHP_EOL);
    exit(1);
}

echo 'Period close rules test passed' . PHP_EOL;
