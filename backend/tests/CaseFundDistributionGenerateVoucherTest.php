<?php

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$source = file_get_contents($modelPath);
$serviceSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/DistributionVoucherGenerationService.php');
$voucherRuleSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherRule.php');
$persistenceSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherPersistenceService.php');
$auxiliarySource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherAuxiliaryService.php');
$implementationSource = $source . "\n" . $serviceSource . "\n" . $voucherRuleSource . "\n" . $persistenceSource . "\n" . $auxiliarySource;

$checks = [
    ["case 'distributionGenerateVoucher'", 'CaseFund should route distributionGenerateVoucher'],
    ['public function distributionGenerateVoucher', 'CaseFund should expose distributionGenerateVoucher'],
    ["requirePermission('case_fund:generate_voucher')", 'Distribution generation should check permission'],
    ['directConfigsByBusinessItems', 'Distribution generation should preserve direct subject-config keys through the resolver'],
    ['groupDistributionRowsForVoucher', 'Distribution generation should group rows by distribution number'],
    ['buildDistributionVoucherDetailsData', 'Distribution generation should use distribution detail builder'],
    ['validateDistributionVoucherPeriod', 'Distribution generation should validate source period and period status'],
    ['ensureArchivesForVoucherDetails', 'Distribution generation should create case/receipt auxiliary archives'],
    ['CASE_FUND_DISTRIBUTION', 'Distribution generation should write audit log'],
    ["'DISTRIBUTION'", 'Distribution generation should use DISTRIBUTION business type'],
    ["'BUSINESS'", 'Distribution vouchers should use BUSINESS source type'],
    ["'SUBMITTED'", 'Distribution vouchers should be submitted'],
    ['fin_voucher_aux_value', 'Distribution generation should write voucher auxiliary values'],
    ['persistVoucher', 'Distribution generation should use common voucher persistence'],
    ['distribution_no', 'Distribution generation should keep distribution number in grouping and summaries'],
    ['in_case_no', 'Distribution generation should use transfer-in case number'],
    ['out_case_no', 'Distribution generation should use transfer-out case number'],
    ['receipt_no', 'Distribution generation should keep original receipt number as auxiliary value'],
];
foreach ($checks as [$needle, $message]) {
    if (strpos($implementationSource, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

foreach (["db('fin_voucher')", "db('fin_voucher_detail')", "db('fin_voucher_aux_value')"] as $directWrite) {
    if (strpos($serviceSource, $directWrite) !== false) {
        fwrite(STDERR, 'Distribution generation service should not directly write common voucher tables: ' . $directWrite . PHP_EOL);
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

if (!function_exists('app\finance\model\uuid')) {
    eval('namespace app\finance\model; function uuid() { return uniqid("test-", true); }');
}

use app\finance\model\CaseFund;

class DistributionVoucherProbe extends CaseFund
{
    public function details($rows, $configMap)
    {
        return $this->voucherRule()->buildDistributionVoucherDetailsData($rows, $configMap);
    }
    public function groups($rows)
    {
        return $this->voucherRule()->groupDistributionRowsForVoucher($rows);
    }
    public function summary($row)
    {
        return $this->buildDistributionVoucherHeaderSummary($row);
    }
    public function periodError($row, $period)
    {
        return $this->validateDistributionVoucherPeriod($row, $period);
    }
    protected function getSubjectAuxConfigs($subjectCode)
    {
        return [
            ['aux_type_code' => 'case_no', 'required_flag' => 1, 'verification_flag' => 0],
            ['aux_type_code' => 'receipt_no', 'required_flag' => 1, 'verification_flag' => 0],
        ];
    }
    public static function getdb($table = '') {
        if ($table === 'fin_fiscal_period') return new DistributionPeriodDbProbe();
        return parent::getdb($table);
    }
}

class DistributionPeriodDbProbe
{
    public function where($where) {
        if (array_key_exists('fiscal_year', $where)) {
            throw new \Exception('fin_fiscal_period should be queried by account_set_id and period, not fiscal_year');
        }
        return $this;
    }
    public function find() { return ['status' => 'CLOSED']; }
}

$rowA = [
    'distribution_id' => 'dist-1',
    'distribution_no' => 'ZXFP60726051900001',
    'in_case_no' => '（2024）辽0111执3548号',
    'in_party_name' => '吴健',
    'out_case_no' => '（2017）辽0111执611号',
    'out_party_name' => '李洁',
    'invoice_type' => '执行、调解款',
    'receipt_no' => '0001759490',
    'distribution_amount' => '5084.00',
    'period' => '2026-05',
    'receive_date' => '2026-05-20',
    'voucher_status' => 'UNGENERATED',
];
$rowB = array_merge($rowA, [
    'distribution_id' => 'dist-2',
    'in_case_no' => '（2024）辽0111执3550号',
    'in_party_name' => '王某',
    'distribution_amount' => '12.34',
]);
$configMap = [
    '执行、调解款' => [
        'debit_subject_code' => '224101',
        'credit_subject_code' => '224101',
    ],
];

$probe = new DistributionVoucherProbe();
$groups = $probe->groups([$rowA, $rowB]);
if (count($groups) !== 1 || count(reset($groups)) !== 2) {
    fwrite(STDERR, 'Distribution rows should be merged by distribution number' . PHP_EOL);
    exit(1);
}

$details = $probe->details([$rowA], $configMap);
if ($details['total_cents'] !== 508400 || count($details['details']) !== 2) {
    fwrite(STDERR, 'Distribution voucher should create one debit and one credit detail per row' . PHP_EOL);
    exit(1);
}
$debit = $details['details'][0];
$credit = $details['details'][1];
if ($debit['debit_amount'] !== '5084.00' || $debit['credit_amount'] !== '0.00') {
    fwrite(STDERR, 'Distribution transfer-out detail should be debit amount' . PHP_EOL);
    exit(1);
}
if ($credit['debit_amount'] !== '0.00' || $credit['credit_amount'] !== '5084.00') {
    fwrite(STDERR, 'Distribution transfer-in detail should be credit amount' . PHP_EOL);
    exit(1);
}
if ($debit['aux_values'][0]['aux_value'] !== $rowA['out_case_no'] || $credit['aux_values'][0]['aux_value'] !== $rowA['in_case_no']) {
    fwrite(STDERR, 'Distribution voucher should debit out case and credit in case' . PHP_EOL);
    exit(1);
}
if ($debit['aux_values'][1]['aux_value'] !== '0001759490' || $credit['aux_values'][1]['aux_value'] !== '0001759490') {
    fwrite(STDERR, 'Distribution voucher should keep original receipt number on both sides' . PHP_EOL);
    exit(1);
}
if ($probe->summary($rowA) !== '分配 ZXFP60726051900001 合计') {
    fwrite(STDERR, 'Distribution voucher header summary should use distribution number' . PHP_EOL);
    exit(1);
}
if (strpos((string)$probe->periodError(array_merge($rowA, ['period' => '2026-04']), '2026-05'), '接收日期与会计期间不一致') === false) {
    fwrite(STDERR, 'Distribution generation should reject inconsistent source period' . PHP_EOL);
    exit(1);
}
if (strpos((string)$probe->periodError($rowA, '2026-05'), '会计期间不是打开状态') === false) {
    fwrite(STDERR, 'Distribution generation should reject closed periods' . PHP_EOL);
    exit(1);
}

echo 'Case fund distribution generate voucher test passed' . PHP_EOL;
