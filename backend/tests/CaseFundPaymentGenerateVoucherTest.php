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
    ['assertRowsCanGenerateVoucher', 'Payment voucher generation should validate whether selected generated rows can be regenerated'],
    ['hasActiveGeneratedVoucher', 'Payment voucher generation should block duplicate generation when the original voucher is still active'],
    ["'GENERATED' &&", 'Payment voucher generation should inspect generated rows before allowing regeneration'],
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

if (!function_exists('input')) {
    function input($key = '', $default = null)
    {
        return $default;
    }
}

if (!function_exists('config')) {
    function config($key = '')
    {
        if ($key === 'default_account_set_id') {
            return '00000000-0000-0000-0000-000000000101';
        }
        if ($key === 'default_year') {
            return '2026';
        }
        return null;
    }
}

if (!function_exists('getNowTime')) {
    function getNowTime()
    {
        return date('Y-m-d H:i:s');
    }
}

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once $modelPath;

use app\finance\model\CaseFund;

class CaseFundGenerationGuardProbe extends CaseFund
{
    private $activeVoucher;

    public function __construct($activeVoucher)
    {
        $this->activeVoucher = $activeVoucher;
    }

    protected function hasActiveGeneratedVoucher($row)
    {
        return $this->activeVoucher;
    }

    public function checkRows($rows)
    {
        return $this->assertRowsCanGenerateVoucher($rows, '缴费记录');
    }
}

$generatedRow = ['voucher_status' => 'GENERATED', 'voucher_id' => 'voucher-1', 'case_no' => '案一'];
$inactiveGuard = new CaseFundGenerationGuardProbe(false);
if ($inactiveGuard->checkRows([$generatedRow]) !== null) {
    fwrite(STDERR, 'Generated payment rows should be regeneratable when the original voucher is deleted or missing' . PHP_EOL);
    exit(1);
}

$activeGuard = new CaseFundGenerationGuardProbe(true);
$activeMessage = $activeGuard->checkRows([$generatedRow]);
if (strpos((string)$activeMessage, '原凭证仍有效') === false) {
    fwrite(STDERR, 'Generated payment rows should still be blocked when the original voucher remains active' . PHP_EOL);
    exit(1);
}

$voidedMessage = $inactiveGuard->checkRows([['voucher_status' => 'VOIDED', 'case_no' => '案二']]);
if (strpos((string)$voidedMessage, '不允许生成凭证') === false) {
    fwrite(STDERR, 'Unsupported payment voucher statuses should not be regeneratable' . PHP_EOL);
    exit(1);
}

echo 'Case fund payment generate voucher test passed' . PHP_EOL;
