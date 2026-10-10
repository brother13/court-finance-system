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

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/Subject.php';

use app\finance\model\Subject;

$subject = new Subject();
$method = new ReflectionMethod(Subject::class, 'normalizeImportCellText');
$method->setAccessible(true);

$originalPrecision = ini_get('precision');
ini_set('precision', '30');
try {
    $actual = $method->invoke($subject, 123.45);
} finally {
    ini_set('precision', (string)$originalPrecision);
}

if ($actual !== '123.45') {
    fwrite(STDERR, 'Subject import numeric cells should ignore PHP precision tails; got ' . $actual . PHP_EOL);
    exit(1);
}

echo 'Subject import cell precision test passed' . PHP_EOL;
