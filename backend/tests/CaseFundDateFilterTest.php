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

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
if (!file_exists($modelPath)) {
    fwrite(STDERR, 'CaseFund model does not exist' . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once $modelPath;

use app\finance\model\CaseFund;

$caseFund = new CaseFund();
$normalizeMethod = new ReflectionMethod(CaseFund::class, 'normalizeDateFilterValue');
$normalizeMethod->setAccessible(true);

$cases = [
    [null, ''],
    ['', ''],
    ['   ', ''],
    ['2026-06-01', '2026-06-01'],
];

foreach ($cases as [$inputValue, $expected]) {
    $actual = $normalizeMethod->invoke($caseFund, $inputValue);
    if ($actual !== $expected) {
        fwrite(STDERR, 'Unexpected normalized date value for ' . var_export($inputValue, true) . ': ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$whereMethod = new ReflectionMethod(CaseFund::class, 'applyDateRangeFilter');
$whereMethod->setAccessible(true);
$where = [];
$whereMethod->invokeArgs($caseFund, [&$where, 'refund_date', '2026-06-01', null]);

if (!isset($where['refund_date']) || count($where['refund_date']) !== 1 || $where['refund_date'][0] !== ['>=', '2026-06-01']) {
    fwrite(STDERR, 'Start-only refund date filter should only add >= condition: ' . var_export($where, true) . PHP_EOL);
    exit(1);
}

$where = [];
$whereMethod->invokeArgs($caseFund, [&$where, 'refund_date', null, '2026-06-30']);
if (!isset($where['refund_date']) || count($where['refund_date']) !== 1 || $where['refund_date'][0] !== ['<=', '2026-06-30']) {
    fwrite(STDERR, 'End-only refund date filter should only add <= condition: ' . var_export($where, true) . PHP_EOL);
    exit(1);
}

echo 'Case fund date filter test passed' . PHP_EOL;
