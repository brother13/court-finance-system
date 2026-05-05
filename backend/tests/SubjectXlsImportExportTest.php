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
require_once __DIR__ . '/../app/finance/model/Subject.php';

use app\finance\model\Subject;

$xlsPath = '/Users/apple/Downloads/苏家屯法院暂存款账-科目数据.xls';
if (!file_exists($xlsPath)) {
    fwrite(STDERR, 'Sample subject xls file does not exist' . PHP_EOL);
    exit(1);
}

$subject = new Subject();
$parseMethod = new ReflectionMethod(Subject::class, 'parseSubjectImportRowsFromXls');
$parseMethod->setAccessible(true);
$rows = $parseMethod->invoke($subject, file_get_contents($xlsPath));

if (count($rows) !== 10) {
    fwrite(STDERR, 'Expected 10 subject rows, got ' . count($rows) . PHP_EOL);
    exit(1);
}

$first = $rows[0];
if ($first['subject_code'] !== '1002' || $first['subject_name'] !== '银行存款' || $first['subject_type'] !== 'ASSET' || $first['direction'] !== 'DEBIT') {
    fwrite(STDERR, 'First subject row was not parsed as expected' . PHP_EOL);
    exit(1);
}

$last = $rows[9];
if ($last['subject_code'] !== '200301' || $last['aux_names'] !== ['案号', '收据号']) {
    fwrite(STDERR, 'Expected final row to include 案号 and 收据号 aux names' . PHP_EOL);
    exit(1);
}

$exportMethod = new ReflectionMethod(Subject::class, 'buildSubjectExportXml');
$exportMethod->setAccessible(true);
$xml = $exportMethod->invoke($subject, [$first, $last]);
foreach (['科目编码', '科目名称', '科目类别', '余额方向', '辅助核算', '银行存款', '[案号,收据号]'] as $needle) {
    if (strpos($xml, $needle) === false) {
        fwrite(STDERR, 'Expected export xml to include ' . $needle . PHP_EOL);
        exit(1);
    }
}

$hierarchyMethod = new ReflectionMethod(Subject::class, 'importHierarchyCodes');
$hierarchyMethod->setAccessible(true);
$hierarchyCodes = $hierarchyMethod->invoke($subject, [
    ['subject_code' => '10020101', 'del_flag' => 1],
    ['subject_code' => '3001', 'del_flag' => 0],
], [
    ['subject_code' => '1002'],
    ['subject_code' => '100201'],
]);
if ($hierarchyCodes !== ['3001' => true, '1002' => true, '100201' => true]) {
    fwrite(STDERR, 'Expected hierarchy codes to ignore soft-deleted existing children' . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../app/finance/model/Subject.php');
if (strpos($source, "\$existingSubjectWhere = ['account_set_id' => \$this->accountSetId];") === false) {
    fwrite(STDERR, 'Expected import to query active and soft-deleted subjects before deciding insert/update' . PHP_EOL);
    exit(1);
}
if (strpos($source, "'del_flag' => 0,") === false) {
    fwrite(STDERR, 'Expected import update path to reactivate soft-deleted subjects' . PHP_EOL);
    exit(1);
}
if (strpos($source, 'saveSubjectAuxConfigRows') === false) {
    fwrite(STDERR, 'Expected import to reuse duplicate-safe auxiliary config saving' . PHP_EOL);
    exit(1);
}

echo 'Subject xls import/export test passed' . PHP_EOL;
