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
$subjectBalanceView = file_get_contents(__DIR__ . '/../../frontend/src/views/books/SubjectBalanceView.vue');

$checks = [
    [$book, 'loadSubjectBalanceVoucherRows', 'Subject balance should load voucher rows through a shared helper'],
    [$book, 'reportYearStartPeriod', 'Subject balance should calculate the fiscal year start period for cumulative amounts'],
    [$book, 'openingPeriodFor', 'Subject balance should locate the enabled/opening period instead of using only current period'],
    [$book, 'addSubjectBalanceAmount', 'Subject balance should roll amounts up to ancestor subjects'],
    [$book, "'opening_debit_amount'", 'Subject balance should expose opening debit amount'],
    [$book, "'opening_credit_amount'", 'Subject balance should expose opening credit amount'],
    [$book, "'year_debit_amount'", 'Subject balance should expose year-to-date debit amount'],
    [$book, "'year_credit_amount'", 'Subject balance should expose year-to-date credit amount'],
    [$book, "'ending_debit_amount'", 'Subject balance should expose ending debit balance'],
    [$book, "'ending_credit_amount'", 'Subject balance should expose ending credit balance'],
    [$book, 'ancestorSubjectCodes', 'Subject balance should derive ancestor subject codes from the configured code rule'],
    [$book, 'formatSubjectBalanceRow', 'Subject balance should format standard balance columns in one place'],
    [$book, "'start_period'", 'Subject balance should accept a start accounting period'],
    [$book, "'end_period'", 'Subject balance should accept an end accounting period'],
    [$book, "'subject_start_code'", 'Subject balance should accept a starting subject code filter'],
    [$book, "'subject_end_code'", 'Subject balance should accept an ending subject code filter'],
    [$book, "'level_start'", 'Subject balance should accept a starting subject level filter'],
    [$book, "'level_end'", 'Subject balance should accept an ending subject level filter'],
    [$subjectBalanceView, '期初余额', 'Subject balance view should show opening balance columns'],
    [$subjectBalanceView, '本年累计', 'Subject balance view should show year-to-date columns'],
    [$subjectBalanceView, '期末余额', 'Subject balance view should show ending balance columns'],
    [$subjectBalanceView, 'ending_debit_amount', 'Subject balance view should render ending debit amount'],
    [$subjectBalanceView, 'ending_credit_amount', 'Subject balance view should render ending credit amount'],
    [$subjectBalanceView, 'summaryRows', 'Subject balance totals should avoid double-counting parent and child subjects'],
];

foreach ($checks as [$haystack, $needle, $message]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$openingRead = strpos($book, '$openingRows = db(\'fin_opening_balance\')');
$periodOnlyOpeningSkip = strpos($book, 'if (!$periodOnly) {', max(0, $openingRead - 80));
if ($openingRead === false || ($periodOnlyOpeningSkip !== false && $periodOnlyOpeningSkip < $openingRead)) {
    fwrite(STDERR, 'Selected-period-only subject balance should still load opening balances' . PHP_EOL);
    exit(1);
}

if (strpos($book, '$priorEndPeriod = $this->previousPeriod($startPeriod);') !== false) {
    fwrite(STDERR, 'Subject balance opening should only come from configured opening balances' . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/Book.php';

$model = new app\finance\model\Book();
$ancestors = new ReflectionMethod(app\finance\model\Book::class, 'ancestorSubjectCodes');
$ancestors->setAccessible(true);
$format = new ReflectionMethod(app\finance\model\Book::class, 'formatSubjectBalanceRow');
$format->setAccessible(true);
$visible = new ReflectionMethod(app\finance\model\Book::class, 'subjectBalanceRowVisible');
$visible->setAccessible(true);

$subjectMap = [
    '1002' => ['subject_code' => '1002'],
    '100201' => ['subject_code' => '100201'],
    '10020101' => ['subject_code' => '10020101'],
];

if ($ancestors->invoke($model, '10020101', $subjectMap, [4, 2, 2, 2]) !== ['1002', '100201', '10020101']) {
    fwrite(STDERR, 'Subject balance should roll a detail subject to every configured ancestor level' . PHP_EOL);
    exit(1);
}

$row = $format->invoke($model, [
    'subject_code' => '2003',
    'subject_name' => '执行款',
    'direction' => 'CREDIT',
    'level_no' => 1,
    'leaf_flag' => 0,
    'opening_debit_cents' => 0,
    'opening_credit_cents' => 0,
    'current_debit_cents' => 0,
    'current_credit_cents' => 59412012,
    'year_debit_cents' => 0,
    'year_credit_cents' => 59412012,
]);

if ($row['ending_debit_amount'] !== '0.00' || $row['ending_credit_amount'] !== '594120.12') {
    fwrite(STDERR, 'Credit balance should be shown in ending credit amount for standard subject balance rows' . PHP_EOL);
    exit(1);
}

$row = $format->invoke($model, [
    'subject_code' => '1002',
    'subject_name' => '银行存款',
    'direction' => 'DEBIT',
    'level_no' => 1,
    'leaf_flag' => 0,
    'opening_debit_cents' => 100000,
    'opening_credit_cents' => 0,
    'current_debit_cents' => 0,
    'current_credit_cents' => 20000,
    'year_debit_cents' => 0,
    'year_credit_cents' => 50000,
]);

if ($row['ending_debit_amount'] !== '800.00' || $row['ending_credit_amount'] !== '0.00') {
    fwrite(STDERR, 'Ending balance should be calculated from selected-range opening plus selected-range activity' . PHP_EOL);
    exit(1);
}

if ($row['year_debit_amount'] !== $row['debit_amount'] || $row['year_credit_amount'] !== $row['credit_amount']) {
    fwrite(STDERR, 'Subject balance year-to-date amounts should equal selected-range current amounts' . PHP_EOL);
    exit(1);
}

$visibleRow = [
    'subject_code' => '200301',
    'level_no' => 2,
    'opening_debit_amount' => '0.00',
    'opening_credit_amount' => '0.00',
    'debit_amount' => '0.00',
    'credit_amount' => '0.00',
    'year_debit_amount' => '0.00',
    'year_credit_amount' => '0.00',
    'ending_debit_amount' => '0.00',
    'ending_credit_amount' => '0.00',
];

if ($visible->invoke($model, $visibleRow, '1002', '1002', 1, 4, 10, 'all')) {
    fwrite(STDERR, 'Subject balance filters should compare numeric-looking subject codes as strings' . PHP_EOL);
    exit(1);
}

echo 'Book subject balance standard test passed' . PHP_EOL;
