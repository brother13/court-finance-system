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
        return getenv('TEST_FISCAL_YEAR') ?: date('Y');
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
$sourceYear = (int)config('default_year');
$nextYearValue = $sourceYear + 1;
$mayPeriod = sprintf('%04d-05', $sourceYear);
$januaryPeriod = sprintf('%04d-01', $sourceYear);
$februaryPeriod = sprintf('%04d-02', $sourceYear);
$marchPeriod = sprintf('%04d-03', $sourceYear);
$novemberPeriod = sprintf('%04d-11', $sourceYear);
$decemberPeriod = sprintf('%04d-12', $sourceYear);
$nextJanuaryPeriod = sprintf('%04d-01', $nextYearValue);
$nextDecemberPeriod = sprintf('%04d-12', $nextYearValue);

$blockMethod = new ReflectionMethod(Period::class, 'periodCloseBlockItems');
$blockMethod->setAccessible(true);
$blocks = $blockMethod->invoke($period, $mayPeriod, 'OPEN', [
    'DRAFT' => 1,
    'SUBMITTED' => 2,
    'AUDITED' => 3,
    'POSTED' => 9,
]);
if (count($blocks) !== 3) {
    fwrite(STDERR, 'Period close should block draft, submitted and audited vouchers' . PHP_EOL);
    exit(1);
}

$openBlocks = $blockMethod->invoke($period, $mayPeriod, 'OPEN', [
    'POSTED' => 9,
    'VOIDED' => 1,
]);
if (count($openBlocks) !== 0) {
    fwrite(STDERR, 'Period close should allow posted and voided vouchers' . PHP_EOL);
    exit(1);
}

$closedBlocks = $blockMethod->invoke($period, $mayPeriod, 'CLOSED', []);
if (empty($closedBlocks) || strpos($closedBlocks[0]['message'], '不是开放状态') === false) {
    fwrite(STDERR, 'Period close should block non-open fiscal periods' . PHP_EOL);
    exit(1);
}

$nextPeriod = new ReflectionMethod(Period::class, 'nextPeriodValue');
$nextPeriod->setAccessible(true);
if ($nextPeriod->invoke($period, $decemberPeriod) !== $nextJanuaryPeriod) {
    fwrite(STDERR, 'Next period after December should be next year January' . PHP_EOL);
    exit(1);
}
if ($nextPeriod->invoke($period, $mayPeriod) !== sprintf('%04d-06', $sourceYear)) {
    fwrite(STDERR, 'Next period inside a year should increment the month' . PHP_EOL);
    exit(1);
}

$yearPeriods = new ReflectionMethod(Period::class, 'yearPeriodValues');
$yearPeriods->setAccessible(true);
$values = $yearPeriods->invoke($period, $nextYearValue);
if (count($values) !== 12 || $values[0] !== $nextJanuaryPeriod || $values[11] !== $nextDecemberPeriod) {
    fwrite(STDERR, 'Year generation should produce 12 monthly periods' . PHP_EOL);
    exit(1);
}

$yearBlock = new ReflectionMethod(Period::class, 'yearEndBlockReason');
$yearBlock->setAccessible(true);
if ($yearBlock->invoke($period, $novemberPeriod, 'CLOSED') === '') {
    fwrite(STDERR, 'Year-end generation should require December period' . PHP_EOL);
    exit(1);
}
if ($yearBlock->invoke($period, $decemberPeriod, 'OPEN') === '') {
    fwrite(STDERR, 'Year-end generation should require closed December period' . PHP_EOL);
    exit(1);
}
if ($yearBlock->invoke($period, $decemberPeriod, 'CLOSED') !== '') {
    fwrite(STDERR, 'Closed December period should allow year-end generation' . PHP_EOL);
    exit(1);
}
$yearStatusBlock = $yearBlock->invoke($period, $decemberPeriod, 'CLOSED', [
    $januaryPeriod => 'CLOSED',
    $februaryPeriod => 'OPEN',
    $marchPeriod => 'CLOSED',
]);
if (strpos($yearStatusBlock, $februaryPeriod) === false || strpos($yearStatusBlock, '不能年结') === false) {
    fwrite(STDERR, 'Year-end generation should require every month in the year to be closed' . PHP_EOL);
    exit(1);
}
$closedStatuses = [];
foreach ($yearPeriods->invoke($period, $sourceYear) as $value) {
    $closedStatuses[$value] = 'CLOSED';
}
if ($yearBlock->invoke($period, $decemberPeriod, 'CLOSED', $closedStatuses) !== '') {
    fwrite(STDERR, 'Year-end generation should allow a fully closed year' . PHP_EOL);
    exit(1);
}

$policyMethod = new ReflectionMethod(Period::class, 'yearEndPolicyForBizType');
$policyMethod->setAccessible(true);
$casePolicy = $policyMethod->invoke($period, 'CASE_FUND');
if ($casePolicy['carry_mode'] !== 'BALANCE_ROLLOVER' || $casePolicy['auto_profit_loss_carry'] !== 0 || $casePolicy['block_profit_loss_balance'] !== 1) {
    fwrite(STDERR, 'Case-fund year-end should roll balances only and block profit/loss balances' . PHP_EOL);
    exit(1);
}
$canteenPolicy = $policyMethod->invoke($period, 'CANTEEN');
if ($canteenPolicy['carry_mode'] !== 'CONFIG_REQUIRED' || $canteenPolicy['auto_profit_loss_carry'] !== 0 || $canteenPolicy['block_profit_loss_balance'] !== 1) {
    fwrite(STDERR, 'Canteen year-end should require configured revenue/expense carry rules before carrying profit/loss balances' . PHP_EOL);
    exit(1);
}

$carrySubject = new ReflectionMethod(Period::class, 'shouldCarrySubjectToNextOpening');
$carrySubject->setAccessible(true);
if ($carrySubject->invoke($period, 'PROFIT_LOSS')) {
    fwrite(STDERR, 'Profit/loss subjects should not be carried into next-year opening balances' . PHP_EOL);
    exit(1);
}
if (!$carrySubject->invoke($period, 'ASSET')) {
    fwrite(STDERR, 'Asset subjects should be carried into next-year opening balances' . PHP_EOL);
    exit(1);
}

echo 'Period close rules test passed' . PHP_EOL;
