<?php

$base = dirname(__DIR__);
$model = file_get_contents($base . '/app/finance/model/AccountSet.php');
$common = file_get_contents($base . '/app/finance/model/Common.php');
$auth = file_get_contents($base . '/app/finance/model/Auth.php');
$user = file_get_contents($base . '/app/finance/model/User.php');
$ddl = file_get_contents($base . '/database/court_finance_ddl.sql');
$preset = file_get_contents($base . '/database/account_sets_preset.sql');
$legacyPatch = file_get_contents($base . '/database/finance_standard_patch.sql');
$datedPatchPath = $base . '/database/2026-06-03-account-set-bank-account.sql';

function assertContainsText($haystack, $needle, $message)
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

if (!file_exists($datedPatchPath)) {
    fwrite(STDERR, 'Dated account-set bank account patch should exist' . PHP_EOL);
    exit(1);
}

$datedPatch = file_get_contents($datedPatchPath);
$fields = [
    'bank_account_name',
    'bank_account_no',
    'bank_name',
    'bank_branch_name',
];

foreach ($fields as $field) {
    assertContainsText($ddl, $field . ' varchar', 'DDL should define ' . $field);
    assertContainsText($datedPatch, 'column_name = \'' . $field . '\'', 'Dated patch should check ' . $field);
    assertContainsText($datedPatch, 'alter table fin_account_set add column ' . $field, 'Dated patch should add ' . $field);
    assertContainsText($model, "'" . $field . "'", 'AccountSet model should save ' . $field);
    assertContainsText($model, $field, 'AccountSet model should return ' . $field);
    assertContainsText($common, 'a.' . $field, 'User account-set context should return ' . $field);
    assertContainsText($auth, $field, 'Auth account-set list should return ' . $field);
    assertContainsText($user, $field, 'User account-set options should return ' . $field);
    assertContainsText($preset, $field, 'Preset account sets should include ' . $field);
}

assertContainsText($ddl, 'idx_account_set_bank_account', 'DDL should index account-set bank account number');
assertContainsText($datedPatch, 'idx_account_set_bank_account', 'Dated patch should create bank account index idempotently');

if (strpos($legacyPatch, 'alter table fin_account_set add column bank_account') !== false) {
    fwrite(STDERR, 'Legacy finance_standard_patch.sql should not contain this dated database change' . PHP_EOL);
    exit(1);
}

echo 'Account set bank account test passed' . PHP_EOL;
