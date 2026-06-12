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
    return '00000000-0000-0000-0000-000000000999';
}

$book = file_get_contents(__DIR__ . '/../app/finance/model/Book.php');
$rbac = file_get_contents(__DIR__ . '/../database/migration_rbac.sql');
$ddl = file_get_contents(__DIR__ . '/../database/court_finance_ddl.sql');
$patch = file_get_contents(__DIR__ . '/../database/2026-06-12-aux-balance-snapshot.sql');

$checks = [
    [$book, "case 'auxBalance'", 'Book should route auxBalance'],
    [$book, "case 'auxBalanceSubjects'", 'Book should route auxBalanceSubjects'],
    [$book, 'public function auxBalance', 'Book should expose auxBalance'],
    [$book, 'public function auxBalanceSubjects', 'Book should expose auxiliary balance subject options'],
    [$book, "requirePermission('book:view')", 'Aux balance should require book view permission'],
    [$book, "'case_cfg.aux_type_code' => 'case_no'", 'Aux balance subject options should require case number auxiliary config'],
    [$book, "'receipt_cfg.aux_type_code' => 'receipt_no'", 'Aux balance subject options should require receipt number auxiliary config'],
    [$book, 'fin_aux_opening_balance', 'Aux balance should include auxiliary opening balance'],
    [$book, "refresh_snapshot", 'Aux balance should accept an explicit snapshot refresh flag'],
    [$book, 'ensureAuxBalanceSnapshot($period, $refreshSnapshot)', 'Aux balance should ensure a reusable snapshot before reading report rows'],
    [$book, 'auxBalanceSnapshotFresh($period)', 'Aux balance should check whether the snapshot is still fresh'],
    [$book, 'rebuildAuxBalanceSnapshot($period)', 'Aux balance should rebuild stale or missing snapshots'],
    [$book, 'loadAuxBalanceSnapshotRows', 'Aux balance should read rows from the snapshot table for normal queries'],
    [$book, 'openingPeriodFor($period, \'fin_aux_opening_balance\')', 'Aux balance should use the enabled/opening period for auxiliary opening balances'],
    [$book, 'fin_voucher_aux_value', 'Aux balance should read voucher auxiliary values'],
    [$book, 'reportYearStartPeriod($period)', 'Aux balance should accumulate voucher movements through the selected period'],
    [$book, 'sum(d.debit_amount) as debit_amount', 'Aux balance should aggregate voucher debit amount in SQL before PHP grouping'],
    [$book, 'sum(d.credit_amount) as credit_amount', 'Aux balance should aggregate voucher credit amount in SQL before PHP grouping'],
    [$book, 'count(*) as entry_count', 'Aux balance should aggregate voucher entry count in SQL before PHP grouping'],
    [$book, "->group('d.subject_code,s.subject_name,s.direction,case_no,receipt_no')", 'Aux balance should group voucher rows by subject, case number and receipt number in SQL'],
    [$book, "'case_no'", 'Aux balance should group by case number'],
    [$book, "'receipt_no'", 'Aux balance should group by receipt number'],
    [$book, 'normalBalanceCents', 'Aux balance should calculate ending balance by subject direction'],
    [$book, "'children'", 'Aux balance should return receipt rows under case rows'],
    [$book, "'monitor_flag'", 'Aux balance should mark non-zero receipt balances for monitoring'],
    [$book, "v.status' => ['in', ['AUDITED', 'POSTED', 'PRINTED']]", 'Aux balance should use audited, posted and printed vouchers during posting transition'],
    [$rbac, "'menu:book:aux_balance'", 'RBAC should define auxiliary balance menu permission'],
    [$rbac, "'辅助核算余额表'", 'RBAC should name auxiliary balance menu'],
    [$ddl, 'create table fin_aux_balance_snapshot', 'DDL should create auxiliary balance snapshot table'],
    [$ddl, 'uk_aux_balance_snapshot', 'DDL should enforce one snapshot row per account set, period, subject, case and receipt'],
    [$patch, 'create table if not exists fin_aux_balance_snapshot', 'Upgrade patch should create auxiliary balance snapshot table idempotently'],
    [$patch, 'idx_aux_balance_snapshot_monitor', 'Upgrade patch should index monitor flag for unsettled receipt filtering'],
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
$snapshotRow = new ReflectionMethod(app\finance\model\Book::class, 'buildAuxBalanceSnapshotInsertRow');
$snapshotRow->setAccessible(true);

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

$row = $snapshotRow->invoke($model, [
    'subject_code' => '220101',
    'subject_name' => '执行案款',
    'direction' => 'CREDIT',
    'case_no' => '（2026）辽0111执1号',
    'receipt_no' => 'SJ001',
    'opening_debit_cents' => 1000,
    'opening_credit_cents' => 7000,
    'debit_cents' => 1500,
    'credit_cents' => 5000,
    'entry_count' => 3,
], 2026, '2026-05', '2026-06-12 10:00:00', '2026-06-12 09:59:00');

if ($row['opening_balance_amount'] !== '60.00' || $row['ending_balance_amount'] !== '95.00') {
    fwrite(STDERR, 'Aux balance snapshot should persist normal-direction balances: ' . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

if ((int)$row['monitor_flag'] !== 1 || (int)$row['entry_count'] !== 3) {
    fwrite(STDERR, 'Aux balance snapshot should persist monitor flag and entry count' . PHP_EOL);
    exit(1);
}

echo 'Book aux balance test passed' . PHP_EOL;
