<?php

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$source = file_get_contents($modelPath);

$checks = [
    ["case 'paymentGenerateVoucher'", 'CaseFund should route paymentGenerateVoucher'],
    ['public function paymentGenerateVoucher', 'CaseFund should expose paymentGenerateVoucher method'],
    ['ensureAuxArchives', 'CaseFund should ensure aux archives before voucher generation'],
    ['ensureAuxType', 'CaseFund should ensure aux type exists'],
    ['ensureAuxArchivesByType', 'CaseFund should ensure aux archives by type'],
    ['generateNextVoucherNo', 'CaseFund should generate next voucher number'],
    ['getSubjectAuxConfigs', 'CaseFund should get subject aux configs'],
    ['needVerification', 'CaseFund should check verification need'],
    ['buildAuxDesc', 'CaseFund should build aux desc'],
    ["requirePermission('case_fund:generate_voucher')", 'CaseFund should check generate_voucher permission'],
    ["'UNGENERATED'", 'CaseFund should check UNGENERATED status'],
    ["'GENERATED'", 'CaseFund should set GENERATED status'],
    ['Db::startTrans', 'CaseFund should start transaction'],
    ['Db::commit', 'CaseFund should commit transaction'],
    ['Db::rollback', 'CaseFund should rollback transaction'],
    ['fin_voucher', 'CaseFund should write to voucher table'],
    ['fin_voucher_detail', 'CaseFund should write to voucher detail table'],
    ['fin_voucher_aux_value', 'CaseFund should write to voucher aux value table'],
    ['fiscal_year', 'CaseFund voucher generation should save fiscal_year'],
    ['fin_aux_archive', 'CaseFund should check/create aux archive'],
    ['fin_aux_type', 'CaseFund should check/create aux type'],
    ['case_no', 'CaseFund should handle case_no aux'],
    ['receipt_no', 'CaseFund should handle receipt_no aux'],
    ['subjectConfigAccountSetFlag', 'CaseFund should read daily voucher flag'],
    ['validateVoucherSubjectCode', 'CaseFund should validate voucher subject codes'],
    ["'BUSINESS'", 'CaseFund should set source_type to BUSINESS'],
    ["'SUBMITTED'", 'CaseFund should set voucher status to SUBMITTED'],
    ['logAudit', 'CaseFund should write audit log'],
];

foreach ($checks as [$needle, $message]) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Case fund payment generate voucher test passed' . PHP_EOL;
