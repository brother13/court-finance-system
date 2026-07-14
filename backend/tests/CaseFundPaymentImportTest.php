<?php

function input($key = '', $default = null)
{
    return $default;
}

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

function getNowTime()
{
    return date('Y-m-d H:i:s');
}

function uuid()
{
    return '12345678-1234-4000-8000-123456789abc';
}

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
if (!file_exists($modelPath)) {
    fwrite(STDERR, 'CaseFund model does not exist' . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\model\CaseFund;

class CaseFundPaymentImportFakeQuery
{
    private $rows;
    private $count;

    public function __construct($rows, $count = 0)
    {
        $this->rows = $rows;
        $this->count = $count;
    }

    public function where()
    {
        return $this;
    }

    public function field()
    {
        return $this;
    }

    public function select()
    {
        return $this->rows;
    }

    public function find()
    {
        return $this->rows[0] ?? null;
    }

    public function count()
    {
        return $this->count;
    }
}

class CaseFundPaymentImportDuplicateProbe extends CaseFund
{
    public static $existingPaymentRows = [];
    public static $activeVoucherCount = 0;

    public static function getdb($table = '')
    {
        if ($table === 'fin_voucher') {
            return new CaseFundPaymentImportFakeQuery([], self::$activeVoucherCount);
        }
        return new CaseFundPaymentImportFakeQuery(self::$existingPaymentRows);
    }
}

class CaseFundPaymentReceiptConfigProbe extends CaseFund
{
    public static $subjectConfigRows = [];
    public static $subjectAuxConfigRows = [];

    public static function getdb($table = '')
    {
        if ($table === 'fin_case_fund_subject_config') {
            return new CaseFundPaymentImportFakeQuery(self::$subjectConfigRows);
        }
        if ($table === 'fin_subject_aux_config') {
            return new CaseFundPaymentImportFakeQuery(self::$subjectAuxConfigRows);
        }
        return new CaseFundPaymentImportFakeQuery([]);
    }
}

$xlsPath = '/Users/apple/Documents/缴费登记.xls';
if (!file_exists($xlsPath)) {
    fwrite(STDERR, 'Sample payment xls file does not exist' . PHP_EOL);
    exit(1);
}
$litigationXlsPath = '/Users/apple/Documents/缴费登记 su.xls';
if (!file_exists($litigationXlsPath)) {
    fwrite(STDERR, 'Sample litigation fee payment xls file does not exist' . PHP_EOL);
    exit(1);
}

$caseFund = new CaseFundPaymentImportDuplicateProbe();
$parseMethod = new ReflectionMethod(CaseFund::class, 'parsePaymentImportRowsFromXls');
$parseMethod->setAccessible(true);
$rows = $parseMethod->invoke($caseFund, file_get_contents($xlsPath));

if (count($rows) !== 19) {
    fwrite(STDERR, 'Expected 19 payment rows, got ' . count($rows) . PHP_EOL);
    exit(1);
}

$multiSheetLitigationPath = '/Users/apple/Desktop/缴费登记 (3).xls';
if (file_exists($multiSheetLitigationPath)) {
    $multiSheetRows = $parseMethod->invoke($caseFund, file_get_contents($multiSheetLitigationPath));
    if (empty($multiSheetRows)) {
        fwrite(STDERR, 'Expected multi-sheet litigation fee payment file to parse rows from the first worksheet' . PHP_EOL);
        exit(1);
    }
    $multiSheetFirst = $multiSheetRows[0];
    if (($multiSheetFirst['case_no'] ?? '') !== '（2026）辽0111民初5590号'
        || ($multiSheetFirst['business_type'] ?? '') !== '诉讼费预收'
        || ($multiSheetFirst['receipt_no'] ?? '') !== '0000149648'
    ) {
        fwrite(STDERR, 'Payment import should read the first worksheet when an xls file contains multiple sheets' . PHP_EOL);
        exit(1);
    }
}

$first = $rows[0];
$expected = [
    'case_no' => '（2025）辽0103执1348号',
    'business_type' => '执行、调解款',
    'payer_name' => '刘丹宁',
    'party_name' => '王楠',
    'payment_amount' => '551788.00',
    'payment_date' => '2025-06-30',
    'payment_time' => '2025-06-30 16:48:01',
    'receipt_no' => '0000665970',
    'bank_account_no' => '0334210102000370000717900',
    'bank_serial_no' => '20250630900481000173',
    'deposit_revoke_flag' => 0,
];

foreach ($expected as $field => $value) {
    if (($first[$field] ?? null) !== $value) {
        fwrite(STDERR, 'Unexpected first row ' . $field . ': ' . var_export($first[$field] ?? null, true) . PHP_EOL);
        exit(1);
    }
}

if (empty($first['source_fingerprint']) || strlen($first['source_fingerprint']) !== 32) {
    fwrite(STDERR, 'Expected stable source fingerprint' . PHP_EOL);
    exit(1);
}

$third = $rows[2];
if ($third['payment_amount'] !== '117.70' || $third['trial_case_no'] !== '(2024)辽0103民初21275号') {
    fwrite(STDERR, 'Expected decimals and half-width case number to remain parseable' . PHP_EOL);
    exit(1);
}

$duplicateKeyMethod = new ReflectionMethod(CaseFund::class, 'paymentImportDuplicateKey');
$duplicateKeyMethod->setAccessible(true);
$receiptKey = $duplicateKeyMethod->invoke($caseFund, [
    'receipt_no' => '0000665970',
    'bank_account_no' => 'A001',
    'payment_amount' => '100.00',
]);
if ($receiptKey !== 'receipt:0000665970') {
    fwrite(STDERR, 'Payment import duplicate key should prefer receipt no' . PHP_EOL);
    exit(1);
}
$accountAmountKeyWithoutReceiptCheck = $duplicateKeyMethod->invoke($caseFund, [
    'receipt_no' => '0000665970',
    'bank_account_no' => 'A001',
    'payment_amount' => '100.00',
], false);
if ($accountAmountKeyWithoutReceiptCheck !== 'account_amount:A001|100.00') {
    fwrite(STDERR, 'Payment import duplicate key should ignore receipt no when the credit subject has no receipt auxiliary config' . PHP_EOL);
    exit(1);
}
$fingerprintKeyWithoutReceiptCheck = $duplicateKeyMethod->invoke($caseFund, [
    'receipt_no' => '0000665970',
    'bank_account_no' => 'A001',
    'payment_amount' => '100.00',
    'source_fingerprint' => 'fingerprint-one',
], false);
if ($fingerprintKeyWithoutReceiptCheck !== 'fingerprint:fingerprint-one') {
    fwrite(STDERR, 'Payment import should use the source fingerprint instead of receipt no when receipt duplicate checking is disabled' . PHP_EOL);
    exit(1);
}
$accountAmountKey = $duplicateKeyMethod->invoke($caseFund, [
    'receipt_no' => '',
    'bank_account_no' => '0334210102000370000717900',
    'payment_amount' => '551788.00',
]);
if ($accountAmountKey !== 'account_amount:0334210102000370000717900|551788.00') {
    fwrite(STDERR, 'Payment import duplicate key should use bank account plus amount when receipt no is empty' . PHP_EOL);
    exit(1);
}

$decimalToCentsMethod = new ReflectionMethod(CaseFund::class, 'decimalToCents');
$decimalToCentsMethod->setAccessible(true);
if ($decimalToCentsMethod->invoke($caseFund, '97.349999999999') !== 9735) {
    fwrite(STDERR, 'Amount conversion should round Excel float artifacts to the nearest cent' . PHP_EOL);
    exit(1);
}
if ($decimalToCentsMethod->invoke($caseFund, '97.345') !== 9735) {
    fwrite(STDERR, 'Amount conversion should round half cents up' . PHP_EOL);
    exit(1);
}
if ($decimalToCentsMethod->invoke($caseFund, '99.999') !== 10000) {
    fwrite(STDERR, 'Amount conversion should carry rounded cents into yuan' . PHP_EOL);
    exit(1);
}

$source = file_get_contents($modelPath);
foreach ([
    'loadExistingPaymentsForImport' => 'Payment import should load existing rows by duplicate key',
    "'source_fingerprint', 'in'" => 'Payment import should load existing rows by source fingerprint to avoid uk_case_fund_payment_source conflicts',
    'updateExistingPaymentFromImport' => 'Payment import should overwrite ungenerated existing rows',
    "\$update['del_flag'] = 0" => 'Payment import should restore legacy soft-deleted rows when reimporting the same source fingerprint',
    'canOverwriteGeneratedBusinessRow' => 'Payment import should allow overwriting generated rows only when original voucher is deleted or missing',
    'hasActiveGeneratedVoucher($row)' => 'Payment import should keep active generated voucher rows from being overwritten',
    "'updated' => \$updated" => 'Payment import result should report updated rows',
] as $needle => $message) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$allowedMethod = new ReflectionMethod(CaseFund::class, 'allowedPaymentBusinessTypes');
$allowedMethod->setAccessible(true);
$caseFundTypes = $allowedMethod->invoke($caseFund, 'CASE_FUND');
$litigationFeeTypes = $allowedMethod->invoke($caseFund, 'LITIGATION_FEE');
if ($caseFundTypes !== ['执行、调解款']) {
    fwrite(STDERR, 'CASE_FUND should only allow execution mediation payment type' . PHP_EOL);
    exit(1);
}
if ($litigationFeeTypes !== ['诉讼费直接结算', '诉讼费预收']) {
    fwrite(STDERR, 'LITIGATION_FEE should only allow litigation fee direct settlement and prepaid payment types' . PHP_EOL);
    exit(1);
}

$validateMethod = new ReflectionMethod(CaseFund::class, 'validatePaymentRows');
$validateMethod->setAccessible(true);
$wrongBizRows = [$rows[0]];
$wrongBizRows[0]['business_type'] = '执行、调节款';
$wrongBizErrors = $validateMethod->invoke($caseFund, $wrongBizRows, 'CASE_FUND');
if (empty($wrongBizErrors) || strpos(implode("\n", $wrongBizErrors), '当前账套不允许导入业务类型') === false) {
    fwrite(STDERR, 'CASE_FUND validation should reject non-standard execution mediation payment rows' . PHP_EOL);
    exit(1);
}

$noReceiptRows = [$rows[0]];
$noReceiptRows[0]['receipt_no'] = '';
$noReceiptRows[0]['bank_serial_no'] = '';
$noReceiptRows[0]['payment_order_no'] = '';
$noReceiptRows[0]['bank_account_no'] = '0334210102000370000717900';
$errors = $validateMethod->invoke($caseFund, $noReceiptRows, 'CASE_FUND');
if (!empty($errors)) {
    fwrite(STDERR, 'Payment import validation should allow bank account plus amount as trace key' . PHP_EOL);
    exit(1);
}

$duplicateFileRows = [$rows[0], $rows[1]];
$duplicateFileRows[1]['receipt_no'] = $duplicateFileRows[0]['receipt_no'];
$duplicateFileErrors = $validateMethod->invoke($caseFund, $duplicateFileRows, 'CASE_FUND');
if (empty($duplicateFileErrors) || strpos(implode("\n", $duplicateFileErrors), '与第' . $duplicateFileRows[0]['source_row_no'] . '行重复') === false) {
    fwrite(STDERR, 'Payment import validation should report duplicate receipt numbers inside the same file' . PHP_EOL);
    exit(1);
}
$duplicateFileErrorsWithoutReceiptCheck = $validateMethod->invoke($caseFund, $duplicateFileRows, 'CASE_FUND', false);
if (strpos(implode("\n", $duplicateFileErrorsWithoutReceiptCheck), '票据号码') !== false) {
    fwrite(STDERR, 'Payment import should skip receipt-number duplicate validation when the credit subject has no receipt auxiliary config' . PHP_EOL);
    exit(1);
}

$receiptConfigMethod = new ReflectionMethod(CaseFund::class, 'paymentImportRequiresReceiptNoDuplicateCheck');
$receiptConfigMethod->setAccessible(true);
$receiptConfigProbe = new CaseFundPaymentReceiptConfigProbe();
CaseFundPaymentReceiptConfigProbe::$subjectConfigRows = [[
    'business_item_type' => '缴费登记',
    'credit_subject_code' => '220301',
]];
CaseFundPaymentReceiptConfigProbe::$subjectAuxConfigRows = [];
if ($receiptConfigMethod->invoke($receiptConfigProbe, 'CASE_FUND') !== false) {
    fwrite(STDERR, 'Payment import should not check duplicate receipt numbers when the configured credit subject has no receipt auxiliary config' . PHP_EOL);
    exit(1);
}
CaseFundPaymentReceiptConfigProbe::$subjectAuxConfigRows = [[
    'subject_code' => '220301',
    'aux_type_code' => 'receipt_no',
]];
if ($receiptConfigMethod->invoke($receiptConfigProbe, 'CASE_FUND') !== true) {
    fwrite(STDERR, 'Payment import should check duplicate receipt numbers when the configured credit subject has a receipt auxiliary config' . PHP_EOL);
    exit(1);
}
CaseFundPaymentReceiptConfigProbe::$subjectConfigRows = [];
CaseFundPaymentReceiptConfigProbe::$subjectAuxConfigRows = [];
if ($receiptConfigMethod->invoke($receiptConfigProbe, 'CASE_FUND') !== true) {
    fwrite(STDERR, 'Payment import should keep duplicate receipt-number validation when payment subject config is missing' . PHP_EOL);
    exit(1);
}

$duplicateProbe = new CaseFundPaymentImportDuplicateProbe();
CaseFundPaymentImportDuplicateProbe::$existingPaymentRows = [[
    'payment_id' => 'existing-payment-id',
    'receipt_no' => $rows[0]['receipt_no'],
    'case_no' => '（2026）辽0111执1号',
    'payer_name' => '已存在缴费人',
    'party_name' => '',
    'payment_amount' => '88.00',
    'payment_date' => '2026-05-01',
    'payment_time' => '2026-05-01 09:00:00',
    'source_file_name' => '历史缴费登记.xls',
    'source_row_no' => 12,
    'voucher_status' => 'UNGENERATED',
    'voucher_no' => '',
]];
$databaseDuplicateErrors = $validateMethod->invoke($duplicateProbe, [$rows[0]], 'CASE_FUND');
$databaseDuplicateText = implode("\n", $databaseDuplicateErrors);
if (!empty($databaseDuplicateErrors)) {
    fwrite(STDERR, 'Payment import validation should allow overwriting ungenerated existing database rows' . PHP_EOL);
    exit(1);
}
CaseFundPaymentImportDuplicateProbe::$existingPaymentRows[0]['voucher_status'] = 'GENERATED';
CaseFundPaymentImportDuplicateProbe::$existingPaymentRows[0]['voucher_id'] = 'active-voucher-id';
CaseFundPaymentImportDuplicateProbe::$existingPaymentRows[0]['voucher_period'] = '2026-05';
CaseFundPaymentImportDuplicateProbe::$existingPaymentRows[0]['voucher_no'] = 9;
CaseFundPaymentImportDuplicateProbe::$activeVoucherCount = 1;
$databaseDuplicateErrors = $validateMethod->invoke($duplicateProbe, [$rows[0]], 'CASE_FUND');
$databaseDuplicateText = implode("\n", $databaseDuplicateErrors);
if (empty($databaseDuplicateErrors)
    || strpos($databaseDuplicateText, '票据号码【' . $rows[0]['receipt_no'] . '】已存在') === false
    || strpos($databaseDuplicateText, '历史缴费登记.xls') === false
    || strpos($databaseDuplicateText, '原导入第12行') === false
) {
    fwrite(STDERR, 'Payment import validation should report active generated database receipt-number duplicates with source detail' . PHP_EOL);
    exit(1);
}
$databaseDuplicateErrorsWithoutReceiptCheck = $validateMethod->invoke($duplicateProbe, [$rows[0]], 'CASE_FUND', false);
if (strpos(implode("\n", $databaseDuplicateErrorsWithoutReceiptCheck), '票据号码') !== false) {
    fwrite(STDERR, 'Payment import should skip existing receipt-number duplicate validation when the credit subject has no receipt auxiliary config' . PHP_EOL);
    exit(1);
}
CaseFundPaymentImportDuplicateProbe::$existingPaymentRows = [];
CaseFundPaymentImportDuplicateProbe::$activeVoucherCount = 0;

$messageMethod = new ReflectionMethod(CaseFund::class, 'paymentImportErrorMessage');
$messageMethod->setAccessible(true);
if ($messageMethod->invoke($caseFund, $wrongBizErrors, 'CASE_FUND') !== '导入的不是案款数据') {
    fwrite(STDERR, 'CASE_FUND wrong business type should use case fund specific import error message' . PHP_EOL);
    exit(1);
}

$litigationRows = [$rows[0]];
$litigationRows[0]['business_type'] = '预收诉讼费';
$errors = $validateMethod->invoke($caseFund, $litigationRows, 'LITIGATION_FEE');
if (empty($errors) || strpos(implode("\n", $errors), '当前账套不允许导入业务类型') === false) {
    fwrite(STDERR, 'LITIGATION_FEE validation should reject non-standard prepaid litigation fee alias rows' . PHP_EOL);
    exit(1);
}

$litigationRows[0]['business_type'] = '诉讼费预收';
$errors = $validateMethod->invoke($caseFund, $litigationRows, 'LITIGATION_FEE');
if (!empty($errors)) {
    fwrite(STDERR, 'LITIGATION_FEE validation should accept exported litigation fee prepaid rows' . PHP_EOL);
    exit(1);
}

$largeCaseFundPath = '/Users/apple/Documents/苏家屯/缴费登记 暂存款1-4.xls';
if (file_exists($largeCaseFundPath)) {
    $largeRows = $parseMethod->invoke($caseFund, file_get_contents($largeCaseFundPath));
    if (count($largeRows) !== 1119) {
        fwrite(STDERR, 'Expected 1119 rows from large case fund payment xls, got ' . count($largeRows) . PHP_EOL);
        exit(1);
    }
    $largeRowByNo = [];
    foreach ($largeRows as $row) {
        $largeRowByNo[$row['source_row_no']] = $row;
    }
    if (($largeRowByNo[69]['payment_time'] ?? '') !== '2026-04-24 15:21:30'
        || ($largeRowByNo[69]['receipt_no'] ?? '') !== '0001776218'
        || ($largeRowByNo[69]['bank_serial_no'] ?? '') !== '210001500EZ9A1YLXJK') {
        fwrite(STDERR, 'Large case fund payment xls should parse SST continued strings' . PHP_EOL);
        exit(1);
    }
    $errors = $validateMethod->invoke($caseFund, $largeRows, 'CASE_FUND');
    if (!empty($errors)) {
        fwrite(STDERR, 'Large case fund payment xls should pass CASE_FUND validation: ' . $errors[0] . PHP_EOL);
        exit(1);
    }
}

$wrongLitigationRows = [$rows[0]];
$wrongLitigationRows[0]['business_type'] = '执行、调解款';
$errors = $validateMethod->invoke($caseFund, $wrongLitigationRows, 'LITIGATION_FEE');
if (empty($errors) || $messageMethod->invoke($caseFund, $errors, 'LITIGATION_FEE') !== '导入的不是诉讼费数据') {
    fwrite(STDERR, 'LITIGATION_FEE wrong business type should use litigation fee specific import error message' . PHP_EOL);
    exit(1);
}

$litigationImportRows = $parseMethod->invoke($caseFund, file_get_contents($litigationXlsPath));
if (count($litigationImportRows) !== 99) {
    fwrite(STDERR, 'Expected 99 valid litigation fee payment rows after parsing continued SST strings, got ' . count($litigationImportRows) . PHP_EOL);
    exit(1);
}
$errors = $validateMethod->invoke($caseFund, $litigationImportRows, 'LITIGATION_FEE');
if (!empty($errors)) {
    fwrite(STDERR, 'Litigation fee payment sample should validate after skipping non-detail rows: ' . implode('; ', array_slice($errors, 0, 5)) . PHP_EOL);
    exit(1);
}

echo 'Case fund payment import test passed' . PHP_EOL;
