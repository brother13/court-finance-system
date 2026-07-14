<?php

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$source = file_get_contents($modelPath);

$checks = [
    ["case 'unknownPaymentGenerateVoucher'", 'CaseFund should route unknownPaymentGenerateVoucher'],
    ['public function unknownPaymentGenerateVoucher', 'CaseFund should expose unknownPaymentGenerateVoucher'],
    ["requirePermission('case_fund:generate_voucher')", 'Unknown payment generation should check permission'],
    ["voucher_biz_type' => 'UNKNOWN_PAYMENT'", 'Unknown payment generation should read UNKNOWN_PAYMENT subject config'],
    ['buildUnknownPaymentVoucherSummary', 'Unknown payment generation should use fixed summary builder'],
    ['assertRowsCanGenerateVoucher', 'Unknown payment generation should reuse regeneration guard'],
    ['normalizeIdList', 'Unknown payment generation should normalize and deduplicate selected ids'],
    ['count($rows) !== count($unknownPaymentIds)', 'Unknown payment generation should fail when selected rows are missing'],
    ['lock(true)', 'Unknown payment generation should lock selected rows before creating vouchers'],
    ["order('transaction_date asc, source_row_no asc')", 'Unknown payment generation should keep transaction-date/source-row order'],
    ['validateUnknownPaymentVoucherPeriod', 'Unknown payment generation should validate source period and period status'],
    ['fin_fiscal_period', 'Unknown payment generation should check fiscal period status'],
    ["'status' => 'OPEN'", 'Unknown payment generation should require an open fiscal period'],
    ['hasRequiredAuxConfigs', 'Unknown payment generation should reject required aux subjects'],
    ['fin_voucher', 'Unknown payment generation should write voucher table'],
    ['fin_voucher_detail', 'Unknown payment generation should write voucher detail table'],
    ["'BUSINESS'", 'Unknown payment vouchers should use BUSINESS source type'],
    ["'SUBMITTED'", 'Unknown payment vouchers should be submitted'],
    ['CASE_FUND_UNKNOWN_PAYMENT', 'Unknown payment generation should write audit log'],
];
foreach ($checks as [$needle, $message]) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

if (!function_exists('input')) {
    function input($key = '', $default = null) { return $default; }
}
if (!function_exists('config')) {
    function config($key = '') {
        if ($key === 'default_account_set_id') return '00000000-0000-0000-0000-000000000101';
        if ($key === 'default_year') return '2026';
        return null;
    }
}
if (!function_exists('getNowTime')) {
    function getNowTime() { return date('Y-m-d H:i:s'); }
}

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\model\CaseFund;

class UnknownPaymentGuardProbe extends CaseFund
{
    private $activeVoucher;
    public function __construct($activeVoucher) { $this->activeVoucher = $activeVoucher; }
    protected function hasActiveGeneratedVoucher($row) { return $this->activeVoucher; }
    public static function getdb($table = '') {
        if ($table === 'fin_fiscal_period') return new UnknownPaymentPeriodDbProbe();
        return parent::getdb($table);
    }
    public function checkRows($rows) { return $this->assertRowsCanGenerateVoucher($rows, '不明款记录'); }
    public function summary($row) { return $this->buildUnknownPaymentVoucherSummary($row); }
    public function periodError($row, $period) { return $this->validateUnknownPaymentVoucherPeriod($row, $period); }
}

class UnknownPaymentPeriodDbProbe
{
    public function where($where) {
        if (array_key_exists('fiscal_year', $where)) {
            throw new \Exception('fin_fiscal_period should be queried by account_set_id and period, not fiscal_year');
        }
        return $this;
    }
    public function find() { return ['status' => 'CLOSED']; }
}

$probe = new UnknownPaymentGuardProbe(false);
if ($probe->summary(['payer_name' => '王雪梅', 'bank_remark' => '账号不存在账号错误']) !== '收 不明款 王雪梅 账号不存在账号错误') {
    fwrite(STDERR, 'Unknown payment voucher summary should include payer and bank remark' . PHP_EOL);
    exit(1);
}

$generatedRow = ['voucher_status' => 'GENERATED', 'voucher_id' => 'voucher-1', 'case_no' => '不明款'];
if ($probe->checkRows([$generatedRow]) !== null) {
    fwrite(STDERR, 'Generated unknown payment rows should be regeneratable when original voucher is deleted or missing' . PHP_EOL);
    exit(1);
}

$activeProbe = new UnknownPaymentGuardProbe(true);
if (strpos((string)$activeProbe->checkRows([$generatedRow]), '原凭证仍有效') === false) {
    fwrite(STDERR, 'Generated unknown payment rows should be blocked when original voucher remains active' . PHP_EOL);
    exit(1);
}

if (strpos((string)$probe->periodError(['period' => '2026-02', 'transaction_date' => '2026-01-15'], '2026-01'), '交易日期与会计期间不一致') === false) {
    fwrite(STDERR, 'Unknown payment generation should reject inconsistent source period' . PHP_EOL);
    exit(1);
}

if (strpos((string)$probe->periodError(['period' => '2026-01', 'transaction_date' => '2026-01-15'], '2026-01'), '会计期间不是打开状态') === false) {
    fwrite(STDERR, 'Unknown payment generation should reject closed periods' . PHP_EOL);
    exit(1);
}

echo 'Case fund unknown payment generate voucher test passed' . PHP_EOL;
