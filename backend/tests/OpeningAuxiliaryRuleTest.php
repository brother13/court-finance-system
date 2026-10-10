<?php

require_once __DIR__ . '/../app/finance/service/opening/OpeningAuxiliaryRule.php';

use app\finance\service\opening\OpeningAuxiliaryRule;

function openingAuxAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function openingAuxExpectError(callable $callback, $expectedText)
{
    try {
        $callback();
    } catch (InvalidArgumentException $e) {
        openingAuxAssert(strpos($e->getMessage(), $expectedText) !== false, 'Unexpected validation message: ' . $e->getMessage());
        return;
    }
    openingAuxAssert(false, 'Expected validation error containing: ' . $expectedText);
}

$archives = [
    'customer|C001' => ['status' => 1, 'del_flag' => 0],
    'customer|DISABLED' => ['status' => 0, 'del_flag' => 0],
];
$rule = new OpeningAuxiliaryRule(function ($typeCode, $archiveCode) use ($archives) {
    return $archives[$typeCode . '|' . $archiveCode] ?? null;
});
$configs = [
    ['aux_type_code' => 'customer', 'required_flag' => 1],
    ['aux_type_code' => 'case_no', 'required_flag' => 1],
    ['aux_type_code' => 'receipt_no', 'required_flag' => 0],
];
$types = [
    ['aux_type_code' => 'customer', 'value_source' => 'ARCHIVE', 'status' => 1],
    ['aux_type_code' => 'case_no', 'value_source' => 'MANUAL', 'status' => 1],
    ['aux_type_code' => 'receipt_no', 'value_source' => 'MANUAL', 'status' => 1],
];

$result = $rule->prepare([
    [
        'aux_values' => ['customer' => ' C001 ', 'case_no' => ' 案二 ', 'receipt_no' => ' R2 '],
        'debit_amount' => '0.10',
        'credit_amount' => 0,
    ],
    [
        'aux_values' => ['customer' => 'C001', 'case_no' => '案一', 'receipt_no' => ''],
        'debit_amount' => '0.20',
        'credit_amount' => 0,
    ],
    [
        'aux_values' => [],
        'debit_amount' => 0,
        'credit_amount' => 0,
    ],
], $configs, $types);

openingAuxAssert($result['debit_total_cents'] === 30, 'Opening auxiliary amounts should be summed in integer cents');
openingAuxAssert($result['credit_total_cents'] === 0, 'Opening auxiliary credit total should remain zero');
openingAuxAssert(count($result['rows']) === 2, 'Zero amount spare rows should be ignored before required validation');
openingAuxAssert($result['rows'][0]['aux_values']['customer'] === 'C001', 'Archive values should be trimmed');
openingAuxAssert($result['manual_archives']['case_no'] === ['案一', '案二'], 'Manual archive values should be unique and sorted');
openingAuxAssert($result['manual_archives']['receipt_no'] === ['R2'], 'Optional manual archive should be collected when supplied');

openingAuxExpectError(function () use ($rule, $configs, $types) {
    $rule->prepare([[
        'aux_values' => ['customer' => 'MISSING', 'case_no' => '案一'],
        'debit_amount' => '1.00',
    ]], $configs, $types);
}, '辅助档案不存在或已停用');

openingAuxExpectError(function () use ($rule, $configs, $types) {
    $rule->prepare([[
        'aux_values' => ['customer' => 'C001', 'case_no' => '案一', 'department' => 'D01'],
        'debit_amount' => '1.00',
    ]], $configs, $types);
}, '科目未配置的辅助维度');

openingAuxExpectError(function () use ($rule, $configs, $types) {
    $rule->prepare([
        ['aux_values' => ['customer' => 'C001', 'case_no' => '案一'], 'debit_amount' => '1.00'],
        ['aux_values' => ['customer' => 'C001', 'case_no' => '案一'], 'debit_amount' => '2.00'],
    ], $configs, $types);
}, '辅助组合重复');

openingAuxExpectError(function () use ($rule, $configs, $types) {
    $rule->prepare([[
        'aux_values' => ['customer' => 'C001', 'case_no' => '案一'],
        'debit_amount' => '1.001',
    ]], $configs, $types);
}, '金额格式不正确');

$originalPrecision = ini_get('precision');
ini_set('precision', '17');
try {
    $floatAmountResult = $rule->prepare([[
        'aux_values' => ['customer' => 'C001', 'case_no' => '浮点金额'],
        'debit_amount' => 0,
        'credit_amount' => 39806.54,
    ]], $configs, $types);
} finally {
    ini_set('precision', (string)$originalPrecision);
}
openingAuxAssert(
    $floatAmountResult['credit_total_cents'] === 3980654,
    'Numeric amounts should not be rejected because PHP exposes a binary floating-point tail'
);

openingAuxExpectError(function () use ($rule, $configs, $types) {
    $rule->prepare([[
        'aux_values' => ['customer' => 'C001', 'case_no' => '超位小数'],
        'debit_amount' => 1.001,
    ]], $configs, $types);
}, '金额格式不正确');

$openingModel = file_get_contents(__DIR__ . '/../app/finance/model/Opening.php');
openingAuxAssert(strpos($openingModel, 'Db::startTrans()') !== false, 'Auxiliary opening save should run in a transaction');
openingAuxAssert(strpos($openingModel, 'ensureManualAuxArchive') !== false, 'Manual auxiliary opening values should synchronize archives');
openingAuxAssert(strpos($openingModel, '辅助期初明细合计必须等于科目期初余额') !== false, 'Backend should enforce subject and auxiliary opening equality');
openingAuxAssert(strpos($openingModel, "'AUTO_CREATE'") !== false, 'Automatically created auxiliary archives should be audited');

echo 'Opening auxiliary rule test passed' . PHP_EOL;
