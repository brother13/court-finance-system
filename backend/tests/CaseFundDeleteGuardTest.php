<?php

$source = file_get_contents(__DIR__ . '/../app/finance/model/CaseFund.php');
$deletionSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/persistence/CaseFundBusinessDeletionService.php');
$bankDeletionSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/banking/CaseFundBankStatementDeletionService.php');
$bankRepositorySource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/banking/CaseFundBankReconcileRepository.php');
$implementationSource = $source . "\n" . $deletionSource . "\n" . $bankDeletionSource . "\n" . $bankRepositorySource;
$rbac = file_get_contents(__DIR__ . '/../database/migration_rbac.sql');

$checks = [
    [$source, "case 'paymentDelete'", 'CaseFund should route paymentDelete'],
    [$source, "case 'refundDelete'", 'CaseFund should route refundDelete'],
    [$source, "case 'unknownPaymentDelete'", 'CaseFund should route unknownPaymentDelete'],
    [$source, "case 'distributionDelete'", 'CaseFund should route distributionDelete'],
    [$source, "case 'bankStatementDelete'", 'CaseFund should route bankStatementDelete'],
    [$source, "requirePermission('case_fund:delete')", 'Case fund delete should require delete permission'],
    [$source, 'assertRowsCanDeleteBusinessData', 'Payment/refund delete should validate generated rows against original voucher state'],
    [$implementationSource, '已生成凭证且原凭证仍有效，不允许删除', 'Generated payment/refund rows should only block deletion when original voucher is still active'],
    [$source, "'PAYMENT',", 'Payment delete should check payment reconciliation rows'],
    [$source, "'REFUND',", 'Refund delete should check refund reconciliation rows'],
    [$bankRepositorySource, "\$where['reconcile_status'] = 'UNMATCHED'", 'Bank statement delete should only load unmatched rows'],
    [$bankDeletionSource, '只能删除未对账的银行对账单流水', 'Bank statement delete should reject missing, reconciled or occupied rows'],
    [$source, 'bankStatementDeletionService()->prepare', 'Bank statement delete should delegate read-side validation'],
    [$source, 'shouldPhysicalDeleteCaseFundBusinessRows', 'Business import delete should choose physical delete mode by business type'],
    [$implementationSource, "['PAYMENT', 'REFUND', 'UNKNOWN_PAYMENT', 'DISTRIBUTION']", 'Payment, refund, unknown payment, and distribution deletes should be physical deletes'],
    [$implementationSource, "->delete()", 'Business import delete should physically delete allowed rows'],
    [$source, "'CASE_FUND_PAYMENT',", 'Payment delete should write audit log'],
    [$source, "'CASE_FUND_REFUND',", 'Refund delete should write audit log'],
    [$source, "'CASE_FUND_UNKNOWN_PAYMENT',", 'Unknown payment delete should write audit log'],
    [$source, "'UNKNOWN_PAYMENT',", 'Unknown payment delete should check reconciliation rows'],
    [$source, "'CASE_FUND_DISTRIBUTION',", 'Distribution delete should write audit log'],
    [$source, "'DISTRIBUTION',", 'Distribution delete should check reconciliation rows'],
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
