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
    [$book, "case 'auxBalance'", 'Book should route auxBalance'],
    [$book, "case 'auxBalanceSubjects'", 'Book should route auxBalanceSubjects'],
    [$book, 'public function auxBalance', 'Book should expose auxBalance'],
    [$book, 'public function auxBalanceSubjects', 'Book should expose auxiliary balance subject options'],
    [$book, "requirePermission('book:view')", 'Aux balance should require book view permission'],
    [$book, "'case_cfg.aux_type_code' => 'case_no'", 'Aux balance subject options should require case number auxiliary config'],
    [$book, "'receipt_cfg.aux_type_code' => 'receipt_no'", 'Aux balance subject options should require receipt number auxiliary config'],
    [$book, 'fin_aux_opening_balance', 'Aux balance should include auxiliary opening balance'],
    [$book, 'openingPeriodFor($period, \'fin_aux_opening_balance\')', 'Aux balance should use the enabled/opening period for auxiliary opening balances'],
    [$book, 'fin_voucher_aux_value', 'Aux balance should read voucher auxiliary values'],
    [$book, 'reportYearStartPeriod($period)', 'Aux balance should accumulate voucher movements through the selected period'],
    [$book, "'case_no'", 'Aux balance should group by case number'],
    [$book, "'receipt_no'", 'Aux balance should group by receipt number'],
    [$book, 'normalBalanceCents', 'Aux balance should calculate ending balance by subject direction'],
    [$book, "'children'", 'Aux balance should return receipt rows under case rows'],
    [$book, "'monitor_flag'", 'Aux balance should mark non-zero receipt balances for monitoring'],
    [$book, "v.status' => ['in', ['AUDITED', 'PRINTED']]", 'Aux balance should use audited and printed vouchers'],
    [$rbac, "'menu:book:aux_balance'", 'RBAC should define auxiliary balance menu permission'],
    [$rbac, "'辅助核算余额表'", 'RBAC should name auxiliary balance menu'],
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
$normalBalance = new ReflectionMethod(app\finance\model\Book::class, 'normalBalanceCents');
$normalBalance->setAccessible(true);
$auxKey = new ReflectionMethod(app\finance\model\Book::class, 'auxBalanceKey');
$auxKey->setAccessible(true);

if ($normalBalance->invoke($model, 'CREDIT', 1000, 7000) !== 6000) {
    fwrite(STDERR, 'Credit subject balance should be credit minus debit' . PHP_EOL);
    exit(1);
}

if ($normalBalance->invoke($model, 'DEBIT', 1000, 7000) !== -6000) {
    fwrite(STDERR, 'Debit subject balance should be debit minus credit' . PHP_EOL);
    exit(1);
}

if ($auxKey->invoke($model, '220101', '（2026）辽0111执1号', 'SJ001') !== '220101|（2026）辽0111执1号|SJ001') {
    fwrite(STDERR, 'Aux balance key should combine subject, case no and receipt no' . PHP_EOL);
    exit(1);
}

echo 'Book aux balance test passed' . PHP_EOL;
