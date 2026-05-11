<?php

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$ddlPath = __DIR__ . '/../database/court_finance_ddl.sql';
$patchPath = __DIR__ . '/../database/finance_standard_patch.sql';
$rbacPath = __DIR__ . '/../database/migration_rbac.sql';

$source = file_get_contents($modelPath);
$ddl = file_get_contents($ddlPath);
$patch = file_get_contents($patchPath);
$rbac = file_get_contents($rbacPath);

$checks = [
    [$source, "const TABLE_BANK_STATEMENT = 'fin_case_fund_bank_statement'", 'CaseFund should define bank statement table'],
    [$source, "const TABLE_BANK_RECONCILE = 'fin_case_fund_bank_reconcile'", 'CaseFund should define bank reconcile table'],
    [$source, "case 'bankStatementList'", 'CaseFund should route bankStatementList'],
    [$source, "case 'bankStatementImport'", 'CaseFund should route bankStatementImport'],
    [$source, "case 'bankReconcileRun'", 'CaseFund should route bankReconcileRun'],
    [$source, "case 'bankReconcileList'", 'CaseFund should route bankReconcileList'],
    [$source, 'public function bankStatementList', 'CaseFund should expose bankStatementList'],
    [$source, 'public function bankStatementImport', 'CaseFund should expose bankStatementImport'],
    [$source, 'public function bankReconcileRun', 'CaseFund should expose bankReconcileRun'],
    [$source, 'public function bankReconcileList', 'CaseFund should expose bankReconcileList'],
    [$source, "requirePermission('case_fund:reconcile')", 'Bank reconcile run should require reconcile permission'],
    [$source, "\$bizType = 'PAYMENT'", 'Income bank statement should reconcile to payment registration'],
    [$source, "\$bizType = 'REFUND'", 'Debit bank statement should reconcile to refund registration'],
    [$source, "'out_order_no'", 'Refund reconcile should use refund out_order_no'],
    [$source, "'AMOUNT_DIFF'", 'Reconcile should detect amount differences'],
    [$source, "'BIZ_ONLY'", 'Reconcile should report business rows without bank statement'],
    [$source, 'supportedBankStatementBanks', 'CaseFund should validate supported banks'],
    [$source, "'SHENGJING' => '盛京银行'", 'CaseFund should support Shengjing Bank'],
    [$source, "'CCB' => '建设银行'", 'CaseFund should support CCB'],
    [$source, 'bankStatementFingerprint', 'CaseFund should fingerprint bank statement rows'],
    [$source, "logAudit('CASE_FUND_BANK_STATEMENT'", 'Bank statement import should write audit log'],
    [$ddl, 'create table fin_case_fund_bank_statement', 'DDL should create bank statement table'],
    [$ddl, 'create table fin_case_fund_bank_reconcile', 'DDL should create bank reconcile table'],
    [$ddl, 'bank_code varchar(50) not null', 'Bank statement should store bank code'],
    [$ddl, 'transaction_time datetime not null', 'Bank statement should store transaction time'],
    [$ddl, 'debit_amount decimal(18,2) not null default 0', 'Bank statement should store debit amount'],
    [$ddl, 'credit_amount decimal(18,2) not null default 0', 'Bank statement should store credit amount'],
    [$ddl, 'counterparty_account_no varchar(100)', 'Bank statement should store counterparty account'],
    [$ddl, 'bank_serial_no varchar(100)', 'Bank statement should store bank serial no'],
    [$ddl, 'match_status varchar(30) not null', 'Bank reconcile should store match status'],
    [$ddl, 'biz_type varchar(30)', 'Bank reconcile should store matched business type'],
    [$ddl, 'diff_amount decimal(18,2) not null default 0', 'Bank reconcile should store amount difference'],
    [$patch, 'create table if not exists fin_case_fund_bank_statement', 'Patch should create bank statement table idempotently'],
    [$patch, 'create table if not exists fin_case_fund_bank_reconcile', 'Patch should create bank reconcile table idempotently'],
    [$rbac, "'menu:case_fund:bank_statement'", 'RBAC should define bank statement menu permission'],
    [$rbac, "'case_fund:reconcile'", 'RBAC should define bank reconcile permission'],
];

foreach ($checks as [$haystack, $needle, $message]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Case fund bank statement test passed' . PHP_EOL;
