<?php

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$bankingRulePath = __DIR__ . '/../app/finance/service/casefund/banking/CaseFundBankingRule.php';
$bankImportPath = __DIR__ . '/../app/finance/service/casefund/banking/CaseFundBankStatementImportService.php';
$bankRepositoryPath = __DIR__ . '/../app/finance/service/casefund/banking/CaseFundBankReconcileRepository.php';
$bankMatcherPath = __DIR__ . '/../app/finance/service/casefund/banking/CaseFundBankReconcileMatcher.php';
$bankPersistencePath = __DIR__ . '/../app/finance/service/casefund/banking/CaseFundBankingPersistenceService.php';
$ddlPath = __DIR__ . '/../database/court_finance_ddl.sql';
$patchPath = __DIR__ . '/../database/finance_standard_patch.sql';
$rbacPath = __DIR__ . '/../database/migration_rbac.sql';

$source = file_get_contents($modelPath);
$bankingRuleSource = file_get_contents($bankingRulePath);
$bankImportSource = file_get_contents($bankImportPath);
$bankRepositorySource = file_get_contents($bankRepositoryPath);
$bankMatcherSource = file_get_contents($bankMatcherPath);
$bankPersistenceSource = file_get_contents($bankPersistencePath);
$bankImplementationSource = $source . "\n" . $bankRepositorySource . "\n" . $bankMatcherSource . "\n" . $bankPersistenceSource;
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
    [$bankImplementationSource, "\$bizType = 'PAYMENT'", 'Income bank statement should reconcile to payment registration'],
    [$bankImplementationSource, "\$bizType = 'REFUND'", 'Debit bank statement should reconcile to refund registration'],
    [$bankImplementationSource, 'paymentCandidatesByBankStatement', 'Income bank statement should narrow payment candidates by transaction date before serial number'],
    [$bankImplementationSource, 'refundCandidatesByBankStatement', 'Debit bank statement should reconcile refund rows by bank statement counterparty fields'],
    [$bankImplementationSource, "->where('payment_date', \$statement['transaction_date'])", 'Payment reconcile should first narrow by bank transaction date'],
    [$bankImplementationSource, "->where('refund_date', \$statement['transaction_date'])", 'Refund reconcile should first narrow by bank transaction date'],
    [$bankImplementationSource, 'PAYEE_ACCOUNT_DATE', 'Refund reconcile should record payee account/date match rule'],
    [$source, "const BANK_RECONCILE_AUTO_MATCH_RULES = ['SERIAL_NO', 'SERIAL_NO_DATE', 'PAYEE_ACCOUNT_DATE']", 'Bank reconcile rerun should clean legacy and current automatic match rules'],
    [$bankPersistenceSource, "->where('match_rule', 'in', \$this->autoMatchRules)", 'Bank reconcile cleanup should cover every automatic match rule before inserting rerun results'],
    [$bankImplementationSource, "'AMOUNT_DIFF'", 'Reconcile should detect amount differences'],
    [$bankImplementationSource, "'BIZ_ONLY'", 'Reconcile should report business rows without bank statement'],
    [$source, 'supportedBankStatementBanks', 'CaseFund should validate supported banks'],
    [$bankingRuleSource, "'SHENGJING' => '盛京银行'", 'CaseFund should support Shengjing Bank'],
    [$bankingRuleSource, "'CCB' => '建设银行'", 'CaseFund should support CCB'],
    [$source, 'bankStatementFingerprint', 'CaseFund should fingerprint bank statement rows'],
    [$bankImportSource, "'reconcile_status'", 'Bank statement import preparation should keep new rows unmatched'],
    [$bankImportSource, "isset(\$existing[\$fingerprint]) || isset(\$seen[\$fingerprint])", 'Bank statement import should skip existing and in-file duplicate rows'],
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

if (strpos($source, "refundCandidatesByOutOrderNo(\$statement['bank_serial_no'])") !== false) {
    fwrite(STDERR, 'Debit bank statement should not match bank serial number against refund out_order_no' . PHP_EOL);
    exit(1);
}

if (strpos($source, "paymentCandidatesByBankSerial(\$statement['bank_serial_no'])") !== false) {
    fwrite(STDERR, 'Income bank statement should not match serial number without narrowing by transaction date' . PHP_EOL);
    exit(1);
}

if (strpos($source, "'match_rule' => 'SERIAL_NO'") !== false) {
    fwrite(STDERR, 'Bank reconcile cleanup should not be limited to the legacy SERIAL_NO rule' . PHP_EOL);
    exit(1);
}

echo 'Case fund bank statement test passed' . PHP_EOL;
