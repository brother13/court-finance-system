<?php

$ddl = file_get_contents(__DIR__ . '/../database/court_finance_ddl.sql');
$patch = file_get_contents(__DIR__ . '/../database/finance_standard_patch.sql');
$book = file_get_contents(__DIR__ . '/../app/finance/model/Book.php');

$checks = [
    [$ddl, 'idx_voucher_detail_ledger_subject on fin_voucher_detail (account_set_id, fiscal_year, del_flag, subject_code, period, voucher_id, line_no)', 'DDL should add a composite detail-ledger subject index'],
    [$ddl, 'idx_voucher_ledger_period on fin_voucher (account_set_id, fiscal_year, period, del_flag, status, voucher_date, voucher_no, voucher_id)', 'DDL should add a composite detail-ledger voucher index'],
    [$patch, 'idx_voucher_detail_ledger_subject', 'Patch should add the detail-ledger subject index for existing databases'],
    [$patch, 'idx_voucher_ledger_period', 'Patch should add the detail-ledger voucher index for existing databases'],
    [$book, "force('idx_voucher_detail_ledger_subject')", 'Detail ledger should force the subject-period composite index on voucher details'],
    [$book, "where('d.period', 'between'", 'Detail ledger should keep period-range filtering so the composite period index is usable'],
    [$book, "'page'", 'Detail ledger should accept a page parameter'],
    [$book, "'pagesize'", 'Detail ledger should accept a page size parameter'],
    [$book, "'items' => \$rows", 'Detail ledger should return paged items'],
    [$book, "'debit_total'", 'Detail ledger should return full-filter debit total for pagination'],
    [$book, "->page(\$page, \$pageSize)", 'Detail ledger should use database pagination'],
    [$book, 'subjectRangeUpperBound($subjectCode', 'Detail ledger should keep descendant subject filtering for selected parent subjects'],
];

foreach ($checks as [$haystack, $needle, $message]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Book detail ledger performance index test passed' . PHP_EOL;
