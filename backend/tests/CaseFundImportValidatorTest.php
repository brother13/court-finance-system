<?php

$backendRoot = dirname(__DIR__);
$policyPath = $backendRoot . '/app/finance/service/casefund/policy/CaseFundBusinessPolicy.php';
$validatorPath = $backendRoot . '/app/finance/service/casefund/validation/CaseFundImportValidator.php';

if (!is_file($validatorPath)) {
    fwrite(STDERR, 'CaseFundImportValidator service does not exist' . PHP_EOL);
    exit(1);
}

require_once $policyPath;
require_once $validatorPath;

use app\finance\service\casefund\policy\CaseFundBusinessPolicy;
use app\finance\service\casefund\validation\CaseFundImportValidator;
use app\finance\model\CaseFund;

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

function importValidatorAssertSame($expected, $actual, $message)
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

function importValidatorAssertContains($needle, $haystack, $message)
{
    if (!in_array($needle, $haystack, true)) {
        fwrite(STDERR, $message . '; missing ' . var_export($needle, true) . PHP_EOL);
        exit(1);
    }
}

function importValidatorAssertNotContains($needle, $haystack, $message)
{
    if (in_array($needle, $haystack, true)) {
        fwrite(STDERR, $message . '; unexpected ' . var_export($needle, true) . PHP_EOL);
        exit(1);
    }
}

function importValidatorAmountToCents($amount)
{
    $amount = trim((string)$amount);
    if ($amount === '') {
        return 0;
    }
    $amount = str_replace([',', ' '], '', $amount);
    $negative = false;
    if ($amount[0] === '-') {
        $negative = true;
        $amount = substr($amount, 1);
    } elseif ($amount[0] === '+') {
        $amount = substr($amount, 1);
    }
    $parts = explode('.', $amount, 2);
    $yuan = preg_replace('/\D/', '', $parts[0]);
    $cent = isset($parts[1]) ? preg_replace('/\D/', '', $parts[1]) : '';
    $cent = str_pad($cent, 3, '0');
    $value = ((int)$yuan) * 100 + (int)substr($cent, 0, 2);
    if ((int)$cent[2] >= 5) {
        $value++;
    }
    return $negative ? -$value : $value;
}

function validPaymentImportRow($rowNo = 2)
{
    return [
        'source_row_no' => $rowNo,
        'case_no' => '（2026）辽0111执1号',
        'business_type' => '执行、调解款',
        'payer_name' => '缴费人甲',
        'party_name' => '',
        'payment_amount' => '100.00',
        'payment_date' => '2026-05-01',
        'receipt_no' => 'R001',
        'bank_serial_no' => '',
        'payment_order_no' => '',
        'bank_account_no' => '',
    ];
}

function validRefundImportRow($rowNo = 2)
{
    return [
        'source_row_no' => $rowNo,
        'case_no' => '（2026）辽0111执1号',
        'out_type' => '执行、调解款发放',
        'party_name' => '当事人甲',
        'actual_payee_name' => '',
        'refund_amount' => '100.00',
        'refund_date' => '2026-05-02',
        'receipt_no' => '',
        'source_receipt_no' => 'SR001',
        'out_order_no' => '',
    ];
}

function validUnknownPaymentImportRow($rowNo = 2)
{
    return [
        'source_row_no' => $rowNo,
        'unknown_amount' => '100.00',
        'transaction_date' => '2026-05-03',
        'payer_name' => '缴款人甲',
        'bank_remark' => '',
        'bank_summary' => '',
        'court_bank_account_no' => '',
        'payer_account_no' => '',
    ];
}

function validDistributionImportRow($rowNo = 2)
{
    return [
        'source_row_no' => $rowNo,
        'distribution_no' => 'D001',
        'in_case_no' => '（2026）辽0111执1号',
        'out_case_no' => '（2026）辽0111执2号',
        'invoice_type' => '执行、调解款',
        'receipt_no' => 'R001',
        'distribution_amount' => '100.00',
        'receive_date' => '2026-05-04',
    ];
}

$validator = new CaseFundImportValidator(
    new CaseFundBusinessPolicy(),
    function ($amount) {
        return importValidatorAmountToCents($amount);
    }
);

// 缴费登记：严格白名单、逐行错误顺序和精确文案。
importValidatorAssertSame(
    [],
    $validator->validatePaymentRows([validPaymentImportRow()], 'CASE_FUND'),
    'A valid CASE_FUND payment row should pass validation'
);

$litigationPayment = validPaymentImportRow(3);
$litigationPayment['business_type'] = '诉讼费预收';
importValidatorAssertSame(
    [],
    $validator->validatePaymentRows([$litigationPayment], 'LITIGATION_FEE'),
    'The canonical litigation-fee prepaid label should pass validation'
);

$badPayment = validPaymentImportRow(7);
$badPayment['case_no'] = '';
$badPayment['business_type'] = '执行、调节款';
$badPayment['payer_name'] = '';
$badPayment['party_name'] = '';
$badPayment['payment_amount'] = '0';
$badPayment['payment_date'] = '';
$badPayment['receipt_no'] = '';
$badPayment['bank_serial_no'] = '';
$badPayment['payment_order_no'] = '';
$badPayment['bank_account_no'] = '';

$scientificSerialPayment = validPaymentImportRow(8);
$scientificSerialPayment['receipt_no'] = '';
$scientificSerialPayment['bank_serial_no'] = '1.23E+19';

importValidatorAssertSame([
    '第7行：案号不能为空',
    '第7行：当前账套不允许导入业务类型【执行、调节款】，允许类型：执行、调解款',
    '第7行：缴费人和当事人不能同时为空',
    '第7行：金额必须大于0',
    '第7行：缴费日期不能为空或格式不正确',
    '第7行：票据号码、银行流水号、缴费单号、收款账号+金额至少填写一项用于追溯',
    '第8行：银行流水号格式异常，请将银行流水号列设置为文本后重新导入',
], $validator->validatePaymentRows([$badPayment, $scientificSerialPayment], 'CASE_FUND'), 'Payment errors should keep row and rule order with exact messages');

$missingBusinessType = validPaymentImportRow(9);
$missingBusinessType['business_type'] = '';
importValidatorAssertSame(
    ['第9行：业务类型不能为空'],
    $validator->validatePaymentRows([$missingBusinessType], 'CASE_FUND'),
    'A missing payment business type should use the required-field message'
);

$paymentTraceRows = [];
foreach (['receipt_no', 'bank_serial_no', 'payment_order_no', 'bank_account_no'] as $index => $traceField) {
    $row = validPaymentImportRow(20 + $index);
    $row['receipt_no'] = '';
    $row['bank_serial_no'] = '';
    $row['payment_order_no'] = '';
    $row['bank_account_no'] = '';
    $row[$traceField] = 'TRACE-' . $index;
    $paymentTraceRows[] = $row;
}
importValidatorAssertSame(
    [],
    $validator->validatePaymentRows($paymentTraceRows, 'CASE_FUND'),
    'Each supported payment trace-key form should be accepted'
);

$legacyLitigationPayment = $litigationPayment;
$legacyLitigationPayment['source_row_no'] = 30;
$legacyLitigationPayment['business_type'] = '预收诉讼费';
importValidatorAssertSame(
    ['第30行：当前账套不允许导入业务类型【预收诉讼费】，允许类型：诉讼费直接结算、诉讼费预收'],
    $validator->validatePaymentRows([$legacyLitigationPayment], 'LITIGATION_FEE'),
    'Legacy subject-config aliases must not broaden the payment import whitelist'
);

// 退付：出账白名单、追溯开关和错误顺序。
importValidatorAssertSame(
    [],
    $validator->validateRefundRows([validRefundImportRow()], 'CASE_FUND', true),
    'A valid CASE_FUND refund row should pass validation'
);

$badRefund = validRefundImportRow(11);
$badRefund['case_no'] = '';
$badRefund['out_type'] = '执行、调节款发放';
$badRefund['party_name'] = '';
$badRefund['actual_payee_name'] = '';
$badRefund['refund_amount'] = '0';
$badRefund['refund_date'] = '';
$badRefund['receipt_no'] = '';
$badRefund['source_receipt_no'] = '';
$badRefund['out_order_no'] = '';
importValidatorAssertSame([
    '第11行：案号不能为空',
    '第11行：当前账套不允许导入出账种类【执行、调节款发放】，允许类型：执行、调解款发放',
    '第11行：当事人和实际收款人不能同时为空',
    '第11行：出账金额必须大于0',
    '第11行：出账日期不能为空或格式不正确',
    '第11行：票据号码、来源票据号码、出账单号至少填写一项用于追溯',
], $validator->validateRefundRows([$badRefund], 'CASE_FUND', true), 'Refund errors should keep rule order with exact messages');

$refundWithoutTrace = validRefundImportRow(12);
$refundWithoutTrace['receipt_no'] = '';
$refundWithoutTrace['source_receipt_no'] = '';
$refundWithoutTrace['out_order_no'] = '';
importValidatorAssertSame(
    [],
    $validator->validateRefundRows([$refundWithoutTrace], 'CASE_FUND', false),
    'CASE_FUND refund trace fields should be optional when receipt auxiliary accounting is disabled'
);
importValidatorAssertSame(
    ['第12行：票据号码、来源票据号码、出账单号至少填写一项用于追溯'],
    $validator->validateRefundRows([$refundWithoutTrace], 'CASE_FUND', true),
    'CASE_FUND refund trace fields should be required when receipt auxiliary accounting is enabled'
);

$litigationRefund = $refundWithoutTrace;
$litigationRefund['source_row_no'] = 13;
$litigationRefund['out_type'] = '诉讼费退费';
importValidatorAssertSame(
    [],
    $validator->validateRefundRows([$litigationRefund], 'LITIGATION_FEE', true),
    'Litigation-fee refunds should not require receipt trace fields'
);

// 不明款：仅案款账套、严格日期及五种可追溯字段。
$unknownTraceFields = ['payer_name', 'bank_remark', 'bank_summary', 'court_bank_account_no', 'payer_account_no'];
$unknownTraceRows = [];
foreach ($unknownTraceFields as $index => $traceField) {
    $row = validUnknownPaymentImportRow(40 + $index);
    foreach ($unknownTraceFields as $field) {
        $row[$field] = '';
    }
    $row[$traceField] = 'TRACE-' . $index;
    $unknownTraceRows[] = $row;
}
importValidatorAssertSame(
    [],
    $validator->validateUnknownPaymentRows($unknownTraceRows, 'CASE_FUND'),
    'Each supported unknown-payment trace field should be accepted independently'
);

$badUnknownPayment = validUnknownPaymentImportRow(50);
$badUnknownPayment['unknown_amount'] = '0';
$badUnknownPayment['transaction_date'] = '2026-02-29';
foreach ($unknownTraceFields as $field) {
    $badUnknownPayment[$field] = '';
}
importValidatorAssertSame([
    '第50行：金额必须大于0',
    '第50行：交易日期不能为空或格式不正确',
    '第50行：缴款人、银行备注、银行摘要、法院银行账户、缴款人账号至少填写一项用于追溯',
], $validator->validateUnknownPaymentRows([$badUnknownPayment], 'CASE_FUND'), 'Unknown-payment errors should keep rule order and reject invalid calendar dates');

$validLeapDayUnknownPayment = validUnknownPaymentImportRow(51);
$validLeapDayUnknownPayment['transaction_date'] = '2024-02-29';
importValidatorAssertSame(
    [],
    $validator->validateUnknownPaymentRows([$validLeapDayUnknownPayment], 'CASE_FUND'),
    'A valid leap-day transaction date should pass validation'
);

foreach (['2026-2-03', '2026-05-03 10:00:00', 'not-a-date'] as $index => $invalidDate) {
    $row = validUnknownPaymentImportRow(52 + $index);
    $row['transaction_date'] = $invalidDate;
    importValidatorAssertSame(
        ['第' . (52 + $index) . '行：交易日期不能为空或格式不正确'],
        $validator->validateUnknownPaymentRows([$row], 'CASE_FUND'),
        'Unknown-payment dates should require an exact valid YYYY-MM-DD value'
    );
}

$unsupportedUnknownPayment = $badUnknownPayment;
$unsupportedUnknownPayment['source_row_no'] = 56;
importValidatorAssertSame(
    ['第56行：当前账套类型不支持不明款业务：LITIGATION_FEE'],
    $validator->validateUnknownPaymentRows([$unsupportedUnknownPayment], 'LITIGATION_FEE'),
    'Unsupported account sets should stop unknown-payment row validation after the account-set error'
);

// 案款分配：账套、必填字段、白名单、金额及严格日期。
importValidatorAssertSame(
    [],
    $validator->validateDistributionRows([validDistributionImportRow()], 'CASE_FUND'),
    'A valid CASE_FUND distribution row should pass validation'
);

$badDistribution = validDistributionImportRow(60);
$badDistribution['distribution_no'] = '';
$badDistribution['in_case_no'] = '';
$badDistribution['out_case_no'] = '';
$badDistribution['invoice_type'] = '';
$badDistribution['receipt_no'] = '';
$badDistribution['distribution_amount'] = '0';
$badDistribution['receive_date'] = '2026-02-29';
importValidatorAssertSame([
    '第60行：分配单号不能为空',
    '第60行：转入案号不能为空',
    '第60行：转出案号不能为空',
    '第60行：开票种类不能为空',
    '第60行：单据号不能为空',
    '第60行：分配金额必须大于0',
    '第60行：接收日期不能为空或格式不正确',
], $validator->validateDistributionRows([$badDistribution], 'CASE_FUND'), 'Distribution errors should keep rule order with exact messages');

$wrongDistributionType = validDistributionImportRow(61);
$wrongDistributionType['invoice_type'] = '诉讼费预收';
importValidatorAssertSame(
    ['第61行：当前账套不允许导入开票种类【诉讼费预收】，允许类型：执行、调解款'],
    $validator->validateDistributionRows([$wrongDistributionType], 'CASE_FUND'),
    'Distribution invoice types should use the strict CASE_FUND whitelist'
);

$validLeapDayDistribution = validDistributionImportRow(62);
$validLeapDayDistribution['receive_date'] = '2024-02-29';
importValidatorAssertSame(
    [],
    $validator->validateDistributionRows([$validLeapDayDistribution], 'CASE_FUND'),
    'A valid leap-day distribution date should pass validation'
);

$unsupportedDistribution = $badDistribution;
$unsupportedDistribution['source_row_no'] = 63;
importValidatorAssertSame(
    ['第63行：当前账套类型不支持案款分配：LITIGATION_FEE'],
    $validator->validateDistributionRows([$unsupportedDistribution], 'LITIGATION_FEE'),
    'Unsupported account sets should stop distribution row validation after the account-set error'
);

// 顶层提示必须继续与逐行明细同时返回，不能让历史配置别名变成导入口径。
importValidatorAssertSame(
    '导入的不是案款数据',
    $validator->paymentImportErrorMessage([
        '第2行：当前账套不允许导入业务类型【诉讼费预收】，允许类型：执行、调解款',
    ], 'CASE_FUND'),
    'CASE_FUND payment type mismatch should use the case-fund import title'
);
importValidatorAssertSame(
    '导入的不是诉讼费数据',
    $validator->paymentImportErrorMessage([
        '第2行：当前账套不允许导入业务类型【执行、调解款】，允许类型：诉讼费直接结算、诉讼费预收',
    ], 'LITIGATION_FEE'),
    'Litigation-fee payment type mismatch should use the litigation-fee import title'
);
importValidatorAssertSame(
    '导入校验失败',
    $validator->paymentImportErrorMessage(['第2行：案号不能为空'], 'CASE_FUND'),
    'Ordinary payment validation errors should use the generic import title'
);
importValidatorAssertSame(
    '导入的不是案款数据',
    $validator->refundImportErrorMessage([
        '第2行：当前账套不允许导入出账种类【诉讼费退费】，允许类型：执行、调解款发放',
    ], 'CASE_FUND'),
    'CASE_FUND refund type mismatch should use the case-fund import title'
);
importValidatorAssertSame(
    '导入的不是诉讼费数据',
    $validator->refundImportErrorMessage([
        '第2行：当前账套不允许导入出账种类【执行、调解款发放】，允许类型：诉讼费退费',
    ], 'LITIGATION_FEE'),
    'Litigation-fee refund type mismatch should use the litigation-fee import title'
);
importValidatorAssertSame(
    '导入校验失败',
    $validator->refundImportErrorMessage(['第2行：案号不能为空'], 'CASE_FUND'),
    'Ordinary refund validation errors should use the generic import title'
);

// 退付表头策略固定三种业务矩阵。
$caseFundReceiptHeaders = $validator->refundImportRequiredHeaders('CASE_FUND', true);
importValidatorAssertContains('来源票据号码', $caseFundReceiptHeaders, 'CASE_FUND should require source receipt number when receipt tracing is enabled');
importValidatorAssertContains('来源票据开票日期', $caseFundReceiptHeaders, 'CASE_FUND should require source receipt date when receipt tracing is enabled');
importValidatorAssertContains('出账单号', $caseFundReceiptHeaders, 'CASE_FUND should require out-order number when receipt tracing is enabled');
importValidatorAssertNotContains('总出账金额', $caseFundReceiptHeaders, 'Total refund amount should remain an optional header');

$caseFundNoReceiptHeaders = $validator->refundImportRequiredHeaders('CASE_FUND', false);
importValidatorAssertNotContains('来源票据号码', $caseFundNoReceiptHeaders, 'CASE_FUND should not require source receipt number when receipt tracing is disabled');
importValidatorAssertNotContains('来源票据开票日期', $caseFundNoReceiptHeaders, 'CASE_FUND should not require source receipt date when receipt tracing is disabled');
importValidatorAssertNotContains('出账单号', $caseFundNoReceiptHeaders, 'CASE_FUND should not require out-order number when receipt tracing is disabled');

$litigationHeaders = $validator->refundImportRequiredHeaders('LITIGATION_FEE', true);
importValidatorAssertNotContains('来源票据号码', $litigationHeaders, 'Litigation-fee refunds should not require source receipt number');
importValidatorAssertNotContains('来源票据开票日期', $litigationHeaders, 'Litigation-fee refunds should not require source receipt date');
importValidatorAssertContains('出账单号', $litigationHeaders, 'Litigation-fee refunds should continue requiring out-order number');
importValidatorAssertNotContains('总出账金额', $litigationHeaders, 'Total refund amount should remain optional for litigation-fee refunds');

// 四类金额校验必须统一走注入的整数分转换器。
$convertedAmounts = [];
$spyValidator = new CaseFundImportValidator(
    new CaseFundBusinessPolicy(),
    function ($amount) use (&$convertedAmounts) {
        $convertedAmounts[] = $amount;
        return 100;
    }
);
$spyPayment = validPaymentImportRow(70);
$spyPayment['payment_amount'] = 'PAYMENT_AMOUNT';
$spyRefund = validRefundImportRow(71);
$spyRefund['refund_amount'] = 'REFUND_AMOUNT';
$spyUnknown = validUnknownPaymentImportRow(72);
$spyUnknown['unknown_amount'] = 'UNKNOWN_AMOUNT';
$spyDistribution = validDistributionImportRow(73);
$spyDistribution['distribution_amount'] = 'DISTRIBUTION_AMOUNT';
$spyValidator->validatePaymentRows([$spyPayment], 'CASE_FUND');
$spyValidator->validateRefundRows([$spyRefund], 'CASE_FUND', true);
$spyValidator->validateUnknownPaymentRows([$spyUnknown], 'CASE_FUND');
$spyValidator->validateDistributionRows([$spyDistribution], 'CASE_FUND');
importValidatorAssertSame(
    ['PAYMENT_AMOUNT', 'REFUND_AMOUNT', 'UNKNOWN_AMOUNT', 'DISTRIBUTION_AMOUNT'],
    $convertedAmounts,
    'All import amount rules should use the injected integer-cent converter'
);

// CaseFund 保留原 protected 方法作为兼容包装，且结果必须与纯校验器一致。
require_once __DIR__ . '/support/CaseFundTestBootstrap.php';
$caseFund = new CaseFund();
$wrapperCases = [
    [
        'method' => 'validatePaymentRows',
        'args' => [[$badPayment, $scientificSerialPayment], 'CASE_FUND', false],
        'expected' => $validator->validatePaymentRows([$badPayment, $scientificSerialPayment], 'CASE_FUND'),
    ],
    [
        'method' => 'validateRefundRows',
        'args' => [[$refundWithoutTrace], 'CASE_FUND', false],
        'expected' => $validator->validateRefundRows([$refundWithoutTrace], 'CASE_FUND', false),
    ],
    [
        'method' => 'validateUnknownPaymentRows',
        'args' => [[$badUnknownPayment], 'CASE_FUND'],
        'expected' => $validator->validateUnknownPaymentRows([$badUnknownPayment], 'CASE_FUND'),
    ],
    [
        'method' => 'validateDistributionRows',
        'args' => [[$badDistribution], 'CASE_FUND'],
        'expected' => $validator->validateDistributionRows([$badDistribution], 'CASE_FUND'),
    ],
    [
        'method' => 'refundImportRequiredHeaders',
        'args' => ['CASE_FUND', false],
        'expected' => $validator->refundImportRequiredHeaders('CASE_FUND', false),
    ],
];
foreach ($wrapperCases as $wrapperCase) {
    $method = new ReflectionMethod(CaseFund::class, $wrapperCase['method']);
    $method->setAccessible(true);
    importValidatorAssertSame(
        $wrapperCase['expected'],
        $method->invokeArgs($caseFund, $wrapperCase['args']),
        'CaseFund compatibility wrapper should preserve validator behavior: ' . $wrapperCase['method']
    );
}

echo 'Case fund import validator test passed' . PHP_EOL;
