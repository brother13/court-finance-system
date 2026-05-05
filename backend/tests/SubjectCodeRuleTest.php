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

$subject = new Subject();

$normalize = new ReflectionMethod(Subject::class, 'normalizeCodeRule');
$normalize->setAccessible(true);
$rule = $normalize->invoke($subject, '4-2-2-2');
if ($rule !== [4, 2, 2, 2]) {
    fwrite(STDERR, 'Expected code rule 4-2-2-2 to normalize to [4,2,2,2]' . PHP_EOL);
    exit(1);
}

$lengths = new ReflectionMethod(Subject::class, 'codeRuleLengths');
$lengths->setAccessible(true);
if ($lengths->invoke($subject, [4, 2, 2, 2]) !== [4, 6, 8, 10]) {
    fwrite(STDERR, 'Expected cumulative code lengths [4,6,8,10]' . PHP_EOL);
    exit(1);
}

$validate = new ReflectionMethod(Subject::class, 'validateSubjectCodeRule');
$validate->setAccessible(true);

$ok = $validate->invoke($subject, '100201', '1002', [4, 2, 2, 2]);
if ($ok !== null) {
    fwrite(STDERR, 'Expected 100201 under parent 1002 to pass validation' . PHP_EOL);
    exit(1);
}

$error = $validate->invoke($subject, '1002999', '1002', [4, 2, 2, 2]);
if ($error !== '科目编码长度应为6位，且必须以上级科目编码1002开头') {
    fwrite(STDERR, 'Unexpected validation message: ' . $error . PHP_EOL);
    exit(1);
}

$error = $validate->invoke($subject, '100201', '', [4, 2, 2, 2]);
if ($error !== '一级科目编码长度应为4位') {
    fwrite(STDERR, 'Expected top-level length validation, got: ' . $error . PHP_EOL);
    exit(1);
}

echo 'Subject code rule test passed' . PHP_EOL;

