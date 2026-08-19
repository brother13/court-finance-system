<?php

$root = realpath(__DIR__ . '/../..');
$patchPath = $root . '/backend/database/2026-08-03-case-fund-bank-reconcile-query-index.sql';
$ddlPath = $root . '/backend/database/court_finance_ddl.sql';
$standardPatchPath = $root . '/backend/database/finance_standard_patch.sql';

function bankReconcileIndexFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function bankReconcileIndexRead($path)
{
    if (!is_file($path)) {
        bankReconcileIndexFail('Missing file: ' . $path);
    }
    return strtolower(file_get_contents($path));
}

function bankReconcileIndexAssertContains($source, $needle, $message)
{
    if (strpos($source, strtolower($needle)) === false) {
        bankReconcileIndexFail($message . '; missing: ' . $needle);
    }
}

$patch = bankReconcileIndexRead($patchPath);
$ddl = bankReconcileIndexRead($ddlPath);
$standardPatch = bankReconcileIndexRead($standardPatchPath);

$definitions = [
    'idx_case_fund_bank_statement_reconcile_scope' => [
        'fin_case_fund_bank_statement',
        '(account_set_id, fiscal_year, del_flag, transaction_date, direction, counterparty_account_no)',
    ],
    'idx_case_fund_payment_reconcile_scope' => [
        'fin_case_fund_payment',
        '(account_set_id, fiscal_year, del_flag, payment_date, bank_serial_no)',
    ],
    'idx_case_fund_refund_reconcile_scope' => [
        'fin_case_fund_refund',
        '(account_set_id, fiscal_year, del_flag, refund_date, payee_bank_account_no)',
    ],
    'idx_case_fund_unknown_reconcile_scope' => [
        'fin_case_fund_unknown_payment',
        '(account_set_id, fiscal_year, del_flag, transaction_date, claim_status, payer_account_no)',
    ],
    'idx_case_fund_bank_reconcile_replace_scope' => [
        'fin_case_fund_bank_reconcile',
        '(account_set_id, fiscal_year, del_flag, match_rule, reconcile_date)',
    ],
    'idx_case_fund_bank_reconcile_active_statement' => [
        'fin_case_fund_bank_reconcile',
        '(account_set_id, fiscal_year, del_flag, statement_id)',
    ],
];

foreach ($definitions as $indexName => [$tableName, $columns]) {
    bankReconcileIndexAssertContains(
        $patch,
        'alter table ' . $tableName . ' add index ' . $indexName . ' ' . $columns,
        'Standalone patch should create the bank reconcile index with query-aligned columns'
    );
    bankReconcileIndexAssertContains(
        $patch,
        "table_name = '" . $tableName . "'",
        'Standalone patch should guard the expected table'
    );
    if (substr_count($patch, "index_name = '" . $indexName . "'") !== 1) {
        bankReconcileIndexFail('Standalone patch should guard each index exactly once: ' . $indexName);
    }
    bankReconcileIndexAssertContains(
        $ddl,
        'create index ' . $indexName . ' on ' . $tableName . ' ' . $columns,
        'Fresh-install DDL should include every bank reconcile index'
    );
    bankReconcileIndexAssertContains(
        $standardPatch,
        'key ' . $indexName . ' ' . $columns,
        'Consolidated patch table definition should include every bank reconcile index'
    );
    bankReconcileIndexAssertContains(
        $standardPatch,
        'alter table ' . $tableName . ' add index ' . $indexName . ' ' . $columns,
        'Consolidated patch should upgrade existing installations'
    );
}

foreach ([$patch, $standardPatch] as $sqlSource) {
    foreach ([
        'from information_schema.statistics',
        'where table_schema = database()',
        'algorithm=inplace, lock=none',
    ] as $onlineInvariant) {
        bankReconcileIndexAssertContains(
            $sqlSource,
            $onlineInvariant,
            'Bank reconcile indexes should use guarded online MySQL DDL'
        );
    }
}

bankReconcileIndexAssertContains(
    $patch,
    '任务头 period 只是提交上下文',
    'Index design should document why accounting period does not narrow cross-month reconciliation'
);

foreach (['drop table', 'drop index', 'delete from', 'truncate table', 'update fin_case_fund_'] as $forbiddenMutation) {
    if (strpos($patch, $forbiddenMutation) !== false) {
        bankReconcileIndexFail('Index patch must not remove or rewrite business data: ' . $forbiddenMutation);
    }
}

echo 'Case fund bank reconcile performance index test passed' . PHP_EOL;
