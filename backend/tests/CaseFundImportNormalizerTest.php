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

$backendRoot = dirname(__DIR__);
$normalizerPath = $backendRoot . '/app/finance/service/casefund/importing/CaseFundImportNormalizer.php';
if (!is_file($normalizerPath)) {
    fwrite(STDERR, 'CaseFundImportNormalizer service does not exist' . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\model\CaseFund;
use app\finance\service\casefund\importing\CaseFundImportNormalizer;

class CaseFundImportNormalizerBankStatementProbe extends CaseFund
{
    public $normalizedDateTimeInputs = [];

    protected function normalizeDateTimeValue($value)
    {
        $this->normalizedDateTimeInputs[] = $value;
        return '2026-06-07 08:09:10';
    }

    public function normalizeBankRow($row, $bankCode, $bankName, $defaultRowNo)
    {
        return $this->normalizeBankStatementRow($row, $bankCode, $bankName, $defaultRowNo);
    }
}

class CaseFundImportNormalizerCompositionProbe extends CaseFund
{
    protected function normalizeImportCellText($value)
    {
        if ($value === 'CELL-HOOK') {
            return '2026-06-08 09:10:11';
        }
        return parent::normalizeImportCellText($value);
    }

    protected function normalizeDateTimeValue($value)
    {
        if ($value === 'DATETIME-HOOK') {
            return '2026-06-09 10:11:12';
        }
        return parent::normalizeDateTimeValue($value);
    }

    protected function normalizeDateValue($value)
    {
        if ($value === 'DATE-HOOK') {
            return '2026-06-10';
        }
        return parent::normalizeDateValue($value);
    }

    public function dateTimeThroughParent($value)
    {
        return parent::normalizeDateTimeValue($value);
    }

    public function dateThroughParent($value)
    {
        return parent::normalizeDateValue($value);
    }

    public function nullableDateThroughParent($value)
    {
        return parent::nullableDateValue($value);
    }
}

function importNormalizerAssertSame($expected, $actual, $message)
{
    if ($actual !== $expected) {
        fwrite(
            STDERR,
            $message
            . '; expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
            . PHP_EOL
        );
        exit(1);
    }
}

function invokeCaseFundImportNormalizerWrapper($caseFund, $methodName, $args = [])
{
    $method = new ReflectionMethod(CaseFund::class, $methodName);
    if (!$method->isProtected()) {
        fwrite(STDERR, 'CaseFund compatibility wrapper should remain protected: ' . $methodName . PHP_EOL);
        exit(1);
    }
    $method->setAccessible(true);
    return $method->invokeArgs($caseFund, $args);
}

$normalizer = new CaseFundImportNormalizer();

// 是否标记：先 trim，再严格匹配既有七种真值，不能扩大别名集合。
foreach (['√', '是', 'Y', 'YES', '1', 'true', 'TRUE'] as $trueText) {
    importNormalizerAssertSame(
        1,
        $normalizer->yesNoFlagFromText($trueText),
        'Canonical yes/no true value should remain accepted: ' . $trueText
    );
}
importNormalizerAssertSame(1, $normalizer->yesNoFlagFromText('  是  '), 'Yes/no text should be trimmed before strict matching');
importNormalizerAssertSame(1, $normalizer->yesNoFlagFromText(1), 'Integer one should normalize to the canonical string one');
foreach (['', '否', 'N', 'NO', 'yes', 'Yes', 'True', '0', '√√', '是的'] as $falseText) {
    importNormalizerAssertSame(
        0,
        $normalizer->yesNoFlagFromText($falseText),
        'Non-canonical yes/no value should remain false: ' . $falseText
    );
}
importNormalizerAssertSame(0, $normalizer->yesNoFlagFromText(null), 'Null should remain a false yes/no value');

// 单元格文本：仅数学整数数值和“纯数字+.0”字符串允许去除小数部分。
$cellCases = [
    [123, '123'],
    [-123, '-123'],
    [123.0, '123'],
    [-123.0, '-123'],
    [123.45, '123.45'],
    ['  text  ', 'text'],
    ['001.0', '001'],
    [' 001.0 ', '001'],
    ['1.00', '1.00'],
    ['-1.0', '-1.0'],
    ['1.0E+3', '1.0E+3'],
    ['1.23E+19', '1.23E+19'],
    [true, '1'],
    [false, ''],
    [null, ''],
];
foreach ($cellCases as $index => $cellCase) {
    list($value, $expected) = $cellCase;
    importNormalizerAssertSame(
        $expected,
        $normalizer->normalizeImportCellText($value),
        'Import cell normalization should preserve compatibility case ' . $index
    );
}

// 日期时间按生产 PRC 时区刻画 Excel serial、普通文本、宽松日期和非法值，并恢复进程原时区。
$originalTimezone = date_default_timezone_get();
date_default_timezone_set('PRC');
$dateTimeActual = [
    'empty' => $normalizer->normalizeDateTimeValue(''),
    'excel_epoch' => $normalizer->normalizeDateTimeValue(25569),
    'excel_half_day' => $normalizer->normalizeDateTimeValue(25569.5),
    'excel_45000' => $normalizer->normalizeDateTimeValue('45000'),
    'slash_text' => $normalizer->normalizeDateTimeValue(' 2026/05/06 07:08:09 '),
    'rolled_date' => $normalizer->normalizeDateTimeValue('2026-02-30'),
    'invalid' => $normalizer->normalizeDateTimeValue('not-a-date'),
    'date_only' => $normalizer->normalizeDateValue('2026/05/06 07:08:09'),
    'nullable_valid' => $normalizer->nullableDateValue('2026/05/06'),
    'nullable_invalid' => $normalizer->nullableDateValue('not-a-date'),
    'nullable_empty' => $normalizer->nullableDateValue(null),
];
date_default_timezone_set($originalTimezone);

importNormalizerAssertSame([
    'empty' => '',
    'excel_epoch' => '1970-01-01 08:00:00',
    'excel_half_day' => '1970-01-01 20:00:00',
    'excel_45000' => '2023-03-15 08:00:00',
    'slash_text' => '2026-05-06 07:08:09',
    'rolled_date' => '2026-03-02 00:00:00',
    'invalid' => '',
    'date_only' => '2026-05-06',
    'nullable_valid' => '2026-05-06',
    'nullable_invalid' => null,
    'nullable_empty' => null,
], $dateTimeActual, 'Import date normalization should preserve existing PRC and strtotime semantics');
importNormalizerAssertSame($originalTimezone, date_default_timezone_get(), 'Date normalization test should restore the original timezone');

// 空行判断是严格空字符串语义；null、0、false 和空白字符串都不是空单元格。
foreach ([[], [''], ['', '']] as $emptyRow) {
    importNormalizerAssertSame(true, $normalizer->isEmptyPaymentRawRow($emptyRow), 'Only empty-string rows should be considered empty');
}
foreach ([[null], [0], [false], [' '], ['', null]] as $nonEmptyRow) {
    importNormalizerAssertSame(false, $normalizer->isEmptyPaymentRawRow($nonEmptyRow), 'Strict empty-row semantics should reject non-empty-string values');
}

// 缴费非明细行只看四个锚点；金额、缴费单号和收款账号不能单独让标题/合计行成为明细。
$paymentNonDetail = [
    '案号' => ' ',
    '缴费日期' => '',
    '票据号码' => '',
    '银行流水号' => '',
    '缴费单号' => 'ORDER-ONLY',
    '收款账号' => 'ACCOUNT-ONLY',
    '金额' => '100.00',
];
importNormalizerAssertSame(true, $normalizer->isNonDetailPaymentRawRow($paymentNonDetail), 'Payment rows with only non-anchor values should remain non-detail rows');
foreach (['案号', '缴费日期', '票据号码', '银行流水号'] as $anchor) {
    $row = $paymentNonDetail;
    $row[$anchor] = 'ANCHOR';
    importNormalizerAssertSame(false, $normalizer->isNonDetailPaymentRawRow($row), 'Payment anchor should make the row a detail row: ' . $anchor);
}

// 退付非明细行使用独立四锚点；普通票据号和金额不是判断依据。
$refundNonDetail = [
    '案号' => '',
    '出账日期' => ' ',
    '来源票据号码' => '',
    '出账单号' => '',
    '票据号码' => 'RECEIPT-ONLY',
    '退付金额' => '100.00',
];
importNormalizerAssertSame(true, $normalizer->isNonDetailRefundRawRow($refundNonDetail), 'Refund rows with only non-anchor values should remain non-detail rows');
foreach (['案号', '出账日期', '来源票据号码', '出账单号'] as $anchor) {
    $row = $refundNonDetail;
    $row[$anchor] = 'ANCHOR';
    importNormalizerAssertSame(false, $normalizer->isNonDetailRefundRawRow($row), 'Refund anchor should make the row a detail row: ' . $anchor);
}

importNormalizerAssertSame(false, $normalizer->isMalformedPaymentRawRow([]), 'An empty raw row should not be malformed');
importNormalizerAssertSame(false, $normalizer->isMalformedPaymentRawRow(['案号' => '正常文本', '金额' => 100]), 'Ordinary scalar cells should not be malformed');
importNormalizerAssertSame(true, $normalizer->isMalformedPaymentRawRow(['案号' => "正常\0异常"]), 'A NUL byte in any cell should mark the row malformed');

// CaseFund 保留 protected 兼容包装，所有纯规范化结果必须与独立服务一致。
$caseFund = new CaseFund();
$wrapperCases = [
    ['yesNoFlagFromText', ['  YES  ']],
    ['normalizeImportCellText', [' 001.0 ']],
    ['normalizeDateTimeValue', ['2026/05/06 07:08:09']],
    ['normalizeDateValue', ['2026/05/06 07:08:09']],
    ['nullableDateValue', ['not-a-date']],
    ['isEmptyPaymentRawRow', [['', '']]],
    ['isNonDetailPaymentRawRow', [$paymentNonDetail]],
    ['isNonDetailRefundRawRow', [$refundNonDetail]],
    ['isMalformedPaymentRawRow', [['案号' => "A\0B"]]],
];
foreach ($wrapperCases as $wrapperCase) {
    list($methodName, $args) = $wrapperCase;
    importNormalizerAssertSame(
        call_user_func_array([$normalizer, $methodName], $args),
        invokeCaseFundImportNormalizerWrapper($caseFund, $methodName, $args),
        'CaseFund compatibility wrapper should match CaseFundImportNormalizer: ' . $methodName
    );
}

$compositionProbe = new CaseFundImportNormalizerCompositionProbe();
importNormalizerAssertSame(
    '2026-06-08 09:10:11',
    $compositionProbe->dateTimeThroughParent('CELL-HOOK'),
    'CaseFund date-time wrapper should preserve normalizeImportCellText overrides'
);
importNormalizerAssertSame(
    '2026-06-09',
    $compositionProbe->dateThroughParent('DATETIME-HOOK'),
    'CaseFund date wrapper should preserve normalizeDateTimeValue overrides'
);
importNormalizerAssertSame(
    '2026-06-10',
    $compositionProbe->nullableDateThroughParent('DATE-HOOK'),
    'CaseFund nullable-date wrapper should preserve normalizeDateValue overrides'
);

// 银行流水标准化仍应调用 CaseFund 的日期时间包装，避免旁路改变既有扩展点。
$bankProbe = new CaseFundImportNormalizerBankStatementProbe();
$bankRow = $bankProbe->normalizeBankRow([
    'source_row_no' => 9,
    'transaction_time' => 'RAW-BANK-DATE',
    'debit_amount' => '0',
    'credit_amount' => '12.30',
    'balance_amount' => '100.00',
    'bank_serial_no' => ' BANK-001 ',
], 'CCB', '建设银行', 2);
importNormalizerAssertSame(['RAW-BANK-DATE'], $bankProbe->normalizedDateTimeInputs, 'Bank statement normalization should call the CaseFund date-time wrapper exactly once');
importNormalizerAssertSame('2026-06-07 08:09:10', $bankRow['transaction_time'] ?? null, 'Bank statement should use the wrapped normalized date-time');
importNormalizerAssertSame('2026-06-07', $bankRow['transaction_date'] ?? null, 'Bank statement date should derive from the wrapped normalized date-time');
importNormalizerAssertSame(2026, $bankRow['fiscal_year'] ?? null, 'Bank statement fiscal year should derive from the normalized date');
importNormalizerAssertSame('2026-06', $bankRow['period'] ?? null, 'Bank statement period should derive from the normalized date');
importNormalizerAssertSame('CREDIT', $bankRow['direction'] ?? null, 'A credit-only bank row should remain income direction');
importNormalizerAssertSame('BANK-001', $bankRow['bank_serial_no'] ?? null, 'Bank statement serial number should remain trimmed');

echo 'Case fund import normalizer test passed' . PHP_EOL;
