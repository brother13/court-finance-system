<?php

$root = realpath(__DIR__ . '/../..');

function readFileOrFail($path)
{
    if (!is_file($path)) {
        fwrite(STDERR, 'Missing file: ' . $path . PHP_EOL);
        exit(1);
    }
    return file_get_contents($path);
}

function assertContainsText($haystack, $needle, $message)
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function assertNotContainsText($haystack, $needle, $message)
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$ddl = readFileOrFail($root . '/backend/database/court_finance_ddl.sql');
$patch = readFileOrFail($root . '/backend/database/finance_standard_patch.sql');

foreach (['fin_voucher', 'fin_voucher_detail', 'fin_voucher_aux_value'] as $table) {
    assertContainsText($ddl, 'create table ' . $table . ' (', 'DDL should create unified table ' . $table);
}

assertContainsText($ddl, 'fiscal_year int not null', 'Unified voucher tables should include fiscal_year');
assertContainsText($ddl, 'idx_voucher_period on fin_voucher (account_set_id, fiscal_year, period)', 'Voucher table should index account set, fiscal year and period');
assertContainsText($ddl, 'uk_voucher_no on fin_voucher (account_set_id, fiscal_year, period, voucher_no)', 'Voucher number should be unique by account set, fiscal year and period');
assertContainsText($patch, 'fin_voucher_', 'Patch should include migration from historical yearly voucher tables');

$filesWithoutYearTables = [
    '/backend/app/finance/model/Voucher.php',
    '/backend/app/finance/model/Book.php',
    '/backend/app/finance/model/CaseFund.php',
    '/backend/app/finance/model/AccountSet.php',
    '/backend/app/finance/model/Auxiliary.php',
    '/backend/app/finance/model/Subject.php',
];

foreach ($filesWithoutYearTables as $relativePath) {
    $source = readFileOrFail($root . $relativePath);
    assertNotContainsText($source, "yearTable('fin_voucher", $relativePath . ' should not resolve voucher yearly tables via yearTable');
    assertNotContainsText($source, 'fin_voucher_detail_', $relativePath . ' should not reference yearly voucher detail tables');
    assertNotContainsText($source, 'fin_voucher_aux_value_', $relativePath . ' should not reference yearly voucher aux tables');
}

$accountSet = readFileOrFail($root . '/backend/app/finance/model/AccountSet.php');
assertNotContainsText($accountSet, 'ensureYearTables($enabledYear)', 'New account set creation should not create yearly voucher tables');

echo 'Voucher unified table architecture test passed' . PHP_EOL;
