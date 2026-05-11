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

$book = file_get_contents(__DIR__ . '/../app/finance/model/Book.php');
$rbac = file_get_contents(__DIR__ . '/../database/migration_rbac.sql');

$checks = [
    [$book, "case 'subjectSummary'", 'Book should route subjectSummary'],
    [$book, 'public function subjectSummary', 'Book should expose subjectSummary'],
    [$book, "requirePermission('book:view')", 'Subject summary should require book view permission'],
    [$book, 'subjectSummaryCode', 'Subject summary should aggregate details by configured subject level'],
    [$book, 'subjectRangeUpperBound', 'Subject summary should include descendants of the ending subject code'],
    [$book, 'subjectRangeUpperBound($subjectCode', 'Detail ledger should include descendants when drilling into a non-leaf subject'],
    [$book, "v.status' => ['in', ['AUDITED', 'PRINTED']]", 'Subject summary should use audited and printed vouchers'],
    [$book, "'entry_count'", 'Subject summary rows should expose entry count'],
    [$rbac, "'menu:book:subject_summary'", 'RBAC should define subject summary menu permission'],
    [$rbac, "'科目汇总表'", 'RBAC should name the subject summary menu'],
];

foreach ($checks as [$haystack, $needle, $message]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/Book.php';

$model = new app\finance\model\Book();
$summaryCode = new ReflectionMethod(app\finance\model\Book::class, 'subjectSummaryCode');
$summaryCode->setAccessible(true);
$upperBound = new ReflectionMethod(app\finance\model\Book::class, 'subjectRangeUpperBound');
$upperBound->setAccessible(true);
$rule = [4, 2, 2, 2];

if ($summaryCode->invoke($model, '10020101', 1, $rule) !== '1002') {
    fwrite(STDERR, 'Subject summary should roll detail code 10020101 up to level 1 code 1002' . PHP_EOL);
    exit(1);
}

if ($summaryCode->invoke($model, '10020101', 2, $rule) !== '100201') {
    fwrite(STDERR, 'Subject summary should roll detail code 10020101 up to level 2 code 100201' . PHP_EOL);
    exit(1);
}

if ($upperBound->invoke($model, '1002', 10) !== '1002999999') {
    fwrite(STDERR, 'Subject range upper bound should include descendants of ending subject code' . PHP_EOL);
    exit(1);
}

echo 'Book subject summary test passed' . PHP_EOL;
