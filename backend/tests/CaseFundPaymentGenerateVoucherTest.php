<?php

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$source = file_get_contents($modelPath);
$serviceSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/PaymentVoucherGenerationService.php');
$persistenceSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherPersistenceService.php');
$auxiliarySource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherAuxiliaryService.php');
$implementationSource = $source . "\n" . $serviceSource . "\n" . $persistenceSource . "\n" . $auxiliarySource;

$checks = [
    ["case 'paymentGenerateVoucher'", 'CaseFund should route paymentGenerateVoucher'],
    ['public function paymentGenerateVoucher', 'CaseFund should expose paymentGenerateVoucher method'],
    ['ensureArchivesForVoucherDetails', 'CaseFund should ensure aux archives before voucher generation'],
    ['ensureAuxTypeAndLock', 'CaseFund should ensure and lock aux type'],
    ['ensureArchivesByType', 'CaseFund should ensure aux archives by type'],
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
    ['persistVoucher', 'Payment voucher generation should use common voucher persistence'],
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
    if (strpos($implementationSource, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

foreach (["db('fin_voucher')", "db('fin_voucher_detail')", "db('fin_voucher_aux_value')"] as $directWrite) {
    if (strpos($serviceSource, $directWrite) !== false) {
        fwrite(STDERR, 'Payment generation service should not directly write common voucher tables: ' . $directWrite . PHP_EOL);
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

if (!function_exists('uuid')) {
    function uuid()
    {
        return '00000000-0000-4000-8000-000000000000';
    }
}

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\model\CaseFund;

class CaseFundGenerationGuardProbe extends CaseFund
{
    private $activeVoucher;
    private $subjectAuxConfigMap = [];

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

    public function paymentSummary($row)
    {
        return $this->buildPaymentVoucherSummary($row);
    }

    public function setSubjectAuxConfigMap($map)
    {
        $this->subjectAuxConfigMap = $map;
    }

    protected function getSubjectAuxConfigs($subjectCode)
    {
        return $this->subjectAuxConfigMap[$subjectCode] ?? [];
    }

    public function buildPaymentDetails($rows, $subjectConfigMap)
    {
        return $this->buildPaymentVoucherDetailsData($rows, $subjectConfigMap, [], false, 'LITIGATION_FEE');
    }
}

$summaryProbe = new CaseFundGenerationGuardProbe(false);
if ($summaryProbe->paymentSummary(['case_no' => '执恢123号', 'party_name' => '张三']) !== '收 执恢123号 张三') {
    fwrite(STDERR, 'Payment voucher summary should be 收 + case_no + party_name' . PHP_EOL);
    exit(1);
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

$auxGuard = new CaseFundGenerationGuardProbe(false);
$auxGuard->setSubjectAuxConfigMap([]);
$paymentDetails = $auxGuard->buildPaymentDetails([
    [
        'business_type' => '诉讼费预收',
        'case_no' => '（2026）辽0111民初4157号',
        'receipt_no' => '0000147531',
        'payment_amount' => '25.00',
        'payment_method' => 'TRANSFER',
        'payment_date' => '2026-05-01',
        'party_name' => '张三',
    ],
], [
    '缴费登记' => [
        'business_item_type' => '缴费登记',
        'debit_subject_code' => '100201',
        'credit_subject_code' => '210301',
    ],
]);
foreach ($paymentDetails['details'] as $detail) {
    if ($detail['aux_desc'] !== '') {
        fwrite(STDERR, 'Payment details without subject aux config should not write aux_desc' . PHP_EOL);
        exit(1);
    }
    if (!empty($detail['aux_values'])) {
        fwrite(STDERR, 'Payment details without subject aux config should not write voucher aux values' . PHP_EOL);
        exit(1);
    }
}

echo 'Case fund payment generate voucher test passed' . PHP_EOL;
