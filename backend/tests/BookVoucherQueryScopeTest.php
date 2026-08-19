<?php

$book = file_get_contents(__DIR__ . '/../app/finance/model/Book.php');

$legacyStatusFilter = "'v.status' => ['in', ['AUDITED', 'POSTED', 'PRINTED']]";
if (strpos($book, $legacyStatusFilter) !== false) {
    fwrite(STDERR, 'Book reports must not require audited or posted voucher status' . PHP_EOL);
    exit(1);
}

$subjectBalanceMethodStart = strpos($book, 'protected function loadSubjectBalanceVoucherRows');
$subjectBalanceMethodEnd = strpos($book, 'protected function addSubjectBalanceAmount', $subjectBalanceMethodStart);
if ($subjectBalanceMethodStart === false || $subjectBalanceMethodEnd === false) {
    fwrite(STDERR, 'Missing subject balance voucher query method' . PHP_EOL);
    exit(1);
}
$subjectBalanceMethod = substr($book, $subjectBalanceMethodStart, $subjectBalanceMethodEnd - $subjectBalanceMethodStart);
if (strpos($subjectBalanceMethod, "->where('v.status', 'in', ['SUBMITTED', 'AUDITED', 'POSTED', 'PRINTED'])") === false) {
    fwrite(STDERR, 'Subject balance must include saved normal vouchers and exclude drafts and voided vouchers' . PHP_EOL);
    exit(1);
}
if (strpos($subjectBalanceMethod, "'v.status' => 'POSTED'") !== false) {
    fwrite(STDERR, 'Subject balance must include every normal voucher regardless of posting status' . PHP_EOL);
    exit(1);
}

$nonVoidedFilterCount = substr_count($book, "'v.void_flag' => 0");
if ($nonVoidedFilterCount !== 7) {
    fwrite(STDERR, 'All seven book voucher query paths must exclude voided vouchers; got '
        . $nonVoidedFilterCount . PHP_EOL);
    exit(1);
}

$requiredPaths = [
    'public function detailLedger',
    'public function subjectBalance',
    'public function subjectSummary',
    'protected function buildDetailLedgerRowsQueryForPdf',
    'protected function buildAuxBalanceGroupsFromSources',
    'protected function caseMovementSourceExists',
    'protected function rebuildCaseMovementSnapshot',
    'protected function loadSubjectBalanceVoucherRows',
];
foreach ($requiredPaths as $path) {
    if (strpos($book, $path) === false) {
        fwrite(STDERR, 'Missing book report query path: ' . $path . PHP_EOL);
        exit(1);
    }
}

$snapshotPatch = file_get_contents(__DIR__ . '/../database/2026-07-16-invalidate-book-report-snapshots.sql');
if (strpos($snapshotPatch, 'delete from fin_aux_balance_snapshot') === false) {
    fwrite(STDERR, 'Book report scope migration must invalidate old auxiliary balance snapshots' . PHP_EOL);
    exit(1);
}

echo 'Book voucher query scope test passed' . PHP_EOL;
