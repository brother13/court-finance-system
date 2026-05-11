<?php

$source = file_get_contents(__DIR__ . '/../app/finance/model/CaseFund.php');
$rbac = file_get_contents(__DIR__ . '/../database/migration_rbac.sql');

$checks = [
    [$source, "case 'paymentDelete'", 'CaseFund should route paymentDelete'],
    [$source, "case 'refundDelete'", 'CaseFund should route refundDelete'],
    [$source, "case 'bankStatementDelete'", 'CaseFund should route bankStatementDelete'],
    [$source, "requirePermission('case_fund:delete')", 'Case fund delete should require delete permission'],
    [$source, "\$where['voucher_status'] = 'UNGENERATED'", 'Payment/refund delete should only load ungenerated rows'],
    [$source, "'PAYMENT',", 'Payment delete should check payment reconciliation rows'],
    [$source, "'REFUND',", 'Refund delete should check refund reconciliation rows'],
    [$source, "\$where['reconcile_status'] = 'UNMATCHED'", 'Bank statement delete should only load unmatched rows'],
    [$source, "'del_flag' => 1", 'Delete should be a soft delete'],
    [$source, "'CASE_FUND_PAYMENT',", 'Payment delete should write audit log'],
    [$source, "'CASE_FUND_REFUND',", 'Refund delete should write audit log'],
    [$source, "logAudit('CASE_FUND_BANK_STATEMENT', 'DELETE'", 'Bank statement delete should write audit log'],
    [$rbac, "'case_fund:delete'", 'RBAC should define case fund delete permission'],
];

foreach ($checks as [$haystack, $needle, $message]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Case fund delete guard test passed' . PHP_EOL;
