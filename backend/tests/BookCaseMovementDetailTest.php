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
        return '2027';
    }
    return null;
}

function getNowTime()
{
    return '2027-06-30 12:00:00';
}

function uuid()
{
    return '00000000-0000-0000-0000-000000000998';
}

$book = file_get_contents(__DIR__ . '/../app/finance/model/Book.php');
$ddl = file_get_contents(__DIR__ . '/../database/court_finance_ddl.sql');
$patch = file_get_contents(__DIR__ . '/../database/2026-07-22-case-movement-snapshot.sql');
$rbac = file_get_contents(__DIR__ . '/../database/migration_rbac.sql');

$checks = [
    [$book, "case 'caseMovementDetail'", 'Book should route the case movement detail report'],
    [$book, 'public function caseMovementDetail', 'Book should expose the case movement detail report'],
    [$book, "requirePermission('book:view')", 'Case movement detail should require book view permission'],
    [$book, 'accountSetEnabledPeriod()', 'Cross-year report should start from the account set enabled period'],
    [$book, 'ensureCaseMovementSnapshot($refreshSnapshot)', 'Normal queries should ensure a reusable persistent snapshot'],
    [$book, 'caseMovementSnapshotFresh()', 'Case movement snapshot should check source freshness'],
    [$book, 'CASE_MOVEMENT_SNAPSHOT_FRESH_SECONDS', 'Rapid paging and filtering should avoid repeated source freshness scans'],
    [$book, 'caseMovementSourceUpdatedTime()', 'Case movement snapshot should track voucher source changes'],
    [$book, 'rebuildCaseMovementSnapshot()', 'Case movement snapshot should support an explicit rebuild'],
    [$book, "db('fin_case_movement_snapshot')", 'Case movement detail should query a persistent snapshot table'],
    [$book, 'insertAll($batch)', 'Snapshot rebuild should insert source rows in batches'],
    [$book, "'v.void_flag' => 0", 'Case movement detail should exclude voided vouchers'],
    [$book, "'case_aux.aux_type_code' => 'case_no'", 'Case movement detail should only include case-number auxiliary entries'],
    [$book, "->where('d.fiscal_year', '>=', \$startFiscalYear)", 'Snapshot source should explicitly span fiscal years from enablement'],
    [$book, "->where('d.period', '>=', \$startPeriod)", 'Snapshot source should span periods from enablement'],
    [$book, "'fiscal_year' => ['between'", 'Paged reads should explicitly constrain the requested fiscal-year range'],
    [$book, "'period' => ['between'", 'Paged reads should explicitly constrain the requested period range'],
    [$book, "'page' => \$page", 'Case movement response should expose server pagination'],
    [$book, "'debit_total'", 'Case movement response should expose filtered debit totals'],
    [$book, "'credit_total'", 'Case movement response should expose filtered credit totals'],
    [$book, "'start_period' => \$startPeriod", 'Case movement response should disclose its cross-year range'],
    [$ddl, 'create table fin_case_movement_snapshot', 'Core DDL should define the case movement snapshot'],
    [$ddl, 'uk_case_movement_snapshot_detail', 'Core DDL should prevent duplicate voucher detail snapshots'],
    [$patch, 'create table if not exists fin_case_movement_snapshot', 'Dated patch should create the cache idempotently'],
    [$patch, "'menu:book:case_movement_detail'", 'Dated patch should install the report menu permission'],
    [$rbac, "'menu:book:case_movement_detail'", 'RBAC initialization should define the report menu permission'],
    [$rbac, "'案号收支明细表'", 'RBAC should use the approved report name'],
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
$buildRow = new ReflectionMethod(app\finance\model\Book::class, 'buildCaseMovementSnapshotInsertRow');
$buildRow->setAccessible(true);
$row = $buildRow->invoke($model, [
    'fiscal_year' => 2027,
    'period' => '2027-03',
    'voucher_id' => 'voucher-1',
    'voucher_date' => '2027-03-06',
    'voucher_word' => '记',
    'voucher_no' => 8,
    'voucher_status' => 'AUDITED',
    'detail_id' => 'detail-1',
    'line_no' => 2,
    'subject_code' => '220101',
    'subject_name' => '执行案款',
    'direction' => 'CREDIT',
    'case_no' => ' （2025）辽0111执1号 ',
    'receipt_no' => ' SJ001 ',
    'summary' => '退付案款',
    'debit_amount' => '10.005',
    'credit_amount' => '0',
], '2027-06-30 12:00:00', '2027-06-30 11:59:00');

if ($row['case_no'] !== '（2025）辽0111执1号' || $row['receipt_no'] !== 'SJ001') {
    fwrite(STDERR, 'Snapshot should normalize displayed case and receipt labels' . PHP_EOL);
    exit(1);
}
if ($row['debit_amount'] !== '10.01' || $row['credit_amount'] !== '0.00') {
    fwrite(STDERR, 'Snapshot amounts should be normalized through integer cents' . PHP_EOL);
    exit(1);
}
if ($row['fiscal_year'] !== 2027 || $row['voucher_no'] !== 8 || $row['line_no'] !== 2) {
    fwrite(STDERR, 'Snapshot should preserve voucher tracing fields' . PHP_EOL);
    exit(1);
}

echo 'Book case movement detail test passed' . PHP_EOL;
