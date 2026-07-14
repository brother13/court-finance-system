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

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\model\CaseFund;
use app\finance\service\casefund\importing\CaseFundImportParser;

class CaseFundImportParserProbe extends CaseFund
{
    public $biffCells = [];
    public $tableRows = [];
    public $readerCalls = [];

    protected function readOleWorkbookStream($binary)
    {
        $this->readerCalls[] = ['ole', $binary];
        return 'synthetic-biff-stream';
    }

    protected function parseBiffCells($stream)
    {
        $this->readerCalls[] = ['biff', $stream];
        return $this->biffCells;
    }

    protected function parseSpreadsheetRows($binary)
    {
        $this->readerCalls[] = ['table', $binary];
        return $this->tableRows;
    }

    public function parsePayment($binary)
    {
        return $this->parsePaymentImportRowsFromXls($binary);
    }

    public function parseRefund($binary, $bizType = '', $receiptTraceRequired = null)
    {
        return $this->parseRefundImportRowsFromXls($binary, $bizType, $receiptTraceRequired);
    }

    public function parseUnknownPayment($binary)
    {
        return $this->parseUnknownPaymentImportRowsFromXls($binary);
    }

    public function parseDistribution($binary)
    {
        return $this->parseDistributionImportRowsFromXls($binary);
    }

    public function parserService()
    {
        return $this->importParser();
    }
}

class CaseFundImportParserOverrideProbe extends CaseFundImportParserProbe
{
    protected function yesNoFlagFromText($text)
    {
        return $text === 'HOOK' ? 9 : parent::yesNoFlagFromText($text);
    }

    protected function paymentFingerprint($row)
    {
        return 'override-payment-fingerprint';
    }
}

class CaseFundImportParserRefundHeaderOrderProbe extends CaseFundImportParserProbe
{
    public $requiredHeaderCalls = 0;

    protected function refundImportRequiredHeaders($bizType = '', $receiptTraceRequired = null)
    {
        $this->requiredHeaderCalls++;
        return parent::refundImportRequiredHeaders($bizType, $receiptTraceRequired);
    }
}

function importParserAssertSame($expected, $actual, $message)
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

function importParserAssertThrows($expectedMessage, callable $callback, $message)
{
    try {
        $callback();
    } catch (\Exception $e) {
        importParserAssertSame($expectedMessage, $e->getMessage(), $message);
        return;
    }
    fwrite(STDERR, $message . '; expected exception was not thrown' . PHP_EOL);
    exit(1);
}

function importParserOrderedRow($headers, $values)
{
    $row = [];
    foreach ($headers as $header) {
        $row[] = $values[$header] ?? '';
    }
    return $row;
}

function importParserBiffCells($headers, $rows)
{
    $cells = [];
    foreach ($headers as $col => $header) {
        $cells['0:' . $col] = $header;
    }
    foreach ($rows as $rowIndex => $values) {
        $ordered = importParserOrderedRow($headers, $values);
        foreach ($ordered as $col => $value) {
            $cells[($rowIndex + 1) . ':' . $col] = $value;
        }
    }
    return $cells;
}

function paymentParserHeaders()
{
    return [
        '案号', '确', '可', '业务类型', '缴费人', '当事人', '开票抬头', '金额',
        '登记类型', '前审案号', '缴费日期', '票据号码', '开票日期', '开票员',
        '收费方式', '收费员', '承办法官', '书记员', '承办部门', '收款账号',
        '银行流水号', '缴费单号', '内转支出票号', '是否提存撤销',
    ];
}

$probe = new CaseFundImportParserProbe();
$parser = $probe->parserService();
importParserAssertSame(true, $parser instanceof CaseFundImportParser, 'CaseFund should lazily create the extracted import parser');

importParserAssertThrows(
    '案款导入解析器缺少回调：normalize_cell',
    function () {
        new CaseFundImportParser([]);
    },
    'Import parser should reject incomplete hook configuration'
);

foreach ([
    'parsePaymentImportRowsFromXls' => 1,
    'parseRefundImportRowsFromXls' => 3,
    'parseUnknownPaymentImportRowsFromXls' => 1,
    'parseDistributionImportRowsFromXls' => 1,
] as $methodName => $parameterCount) {
    $method = new ReflectionMethod(CaseFund::class, $methodName);
    importParserAssertSame(true, $method->isProtected(), 'CaseFund parser wrapper should remain protected: ' . $methodName);
    importParserAssertSame($parameterCount, count($method->getParameters()), 'CaseFund parser wrapper signature should remain stable: ' . $methodName);
}

$paymentHeaders = paymentParserHeaders();
$paymentDetail = [
    '案号' => '（2026）辽0111执1号',
    '确' => '是',
    '可' => 'Y',
    '业务类型' => '执行、调解款',
    '缴费人' => '张三',
    '当事人' => '李四',
    '开票抬头' => '张三',
    '金额' => '97.345',
    '登记类型' => '首次登记',
    '前审案号' => '(2025)辽0111民初1号',
    '缴费日期' => '2026/07/01 12:34:56',
    '票据号码' => 'R-PAY-001',
    '开票日期' => '',
    '开票员' => '开票员甲',
    '收费方式' => '原始收费方式',
    '收费员' => '收费员甲',
    '承办法官' => '法官甲',
    '书记员' => '书记员甲',
    '承办部门' => '执行局',
    '收款账号' => 'BANK-ACCOUNT-001',
    '银行流水号' => '1234567890',
    '缴费单号' => 'ORDER-001',
    '内转支出票号' => 'TRANSFER-001',
    '是否提存撤销' => 'TRUE',
];
$paymentMalformed = $paymentDetail;
$paymentMalformed['案号'] = "BAD\0ROW";
$paymentNonDetail = [
    '金额' => '999.00',
    '缴费单号' => 'ORDER-ONLY',
    '收款账号' => 'ACCOUNT-ONLY',
];
$probe->biffCells = importParserBiffCells($paymentHeaders, [
    $paymentDetail,
    [],
    $paymentMalformed,
    $paymentNonDetail,
]);
$paymentRows = $probe->parsePayment('payment-binary');
importParserAssertSame(1, count($paymentRows), 'Payment parser should skip empty, malformed, and non-detail rows');
$payment = $paymentRows[0];
$expectedPayment = [
    'fiscal_year' => '2026',
    'period' => '2026-07',
    'case_no' => '（2026）辽0111执1号',
    'confirmed_flag' => 1,
    'available_flag' => 1,
    'business_type' => '执行、调解款',
    'payer_name' => '张三',
    'party_name' => '李四',
    'invoice_title' => '张三',
    'payment_amount' => '97.35',
    'register_type' => '首次登记',
    'trial_case_no' => '(2025)辽0111民初1号',
    'payment_date' => '2026-07-01',
    'payment_time' => '2026-07-01 12:34:56',
    'receipt_no' => 'R-PAY-001',
    'invoice_date' => null,
    'invoice_operator' => '开票员甲',
    'payment_method' => 'POS',
    'cashier_name' => '收费员甲',
    'judge_name' => '法官甲',
    'clerk_name' => '书记员甲',
    'department_name' => '执行局',
    'bank_account_no' => 'BANK-ACCOUNT-001',
    'bank_serial_no' => '1234567890',
    'payment_order_no' => 'ORDER-001',
    'internal_transfer_ticket_no' => 'TRANSFER-001',
    'deposit_revoke_flag' => 1,
    'source_row_no' => 2,
];
foreach ($expectedPayment as $field => $expected) {
    importParserAssertSame($expected, $payment[$field] ?? null, 'Payment parser field mismatch: ' . $field);
}
$expectedPaymentFingerprint = md5(implode('|', [
    $payment['case_no'],
    $payment['payment_time'],
    $payment['payment_amount'],
    $payment['receipt_no'],
    $payment['bank_serial_no'],
    $payment['payment_order_no'],
]));
importParserAssertSame($expectedPaymentFingerprint, $payment['source_fingerprint'], 'Payment parser should fingerprint the fully mapped row');
importParserAssertSame($paymentDetail, json_decode($payment['source_raw_json'], true), 'Payment parser should preserve normalized raw source values');
importParserAssertSame([
    ['ole', 'payment-binary'],
    ['biff', 'synthetic-biff-stream'],
], $probe->readerCalls, 'Payment wrapper should preserve workbook-reader call order');

$overrideProbe = new CaseFundImportParserOverrideProbe();
$overrideDetail = $paymentDetail;
$overrideDetail['确'] = 'HOOK';
$overrideProbe->biffCells = importParserBiffCells($paymentHeaders, [$overrideDetail]);
$overridePayment = $overrideProbe->parsePayment('override-binary')[0];
importParserAssertSame(9, $overridePayment['confirmed_flag'], 'Parser hooks should preserve CaseFund yes/no overrides');
importParserAssertSame('override-payment-fingerprint', $overridePayment['source_fingerprint'], 'Parser hooks should preserve CaseFund fingerprint overrides');

$refundHeadersWithoutReceiptTrace = [
    '案号', '经办人', '书记员', '票据号码', '开票日期', '出账日期',
    '出账状态', '出账种类', '诉讼地位', '当事人', '出账金额',
    '收款人与当事人关系', '支付方式', '实际收款人', '身份证号/企业',
    '收款人银行户名', '收款账号', '开户银行', '银联号', '是否本行',
    '承办人情况说明', '申请人',
];
$refundDetail = [
    '案号' => '（2026）辽0111执2号',
    '经办人' => '经办人乙',
    '书记员' => '书记员乙',
    '票据号码' => 'R-REFUND-001',
    '开票日期' => '2026/06/30',
    '出账日期' => '2026/07/02',
    '出账状态' => '银行处理中',
    '出账种类' => '执行、调解款发放',
    '诉讼地位' => '申请执行人',
    '当事人' => '王五',
    '出账金额' => '88.505',
    '收款人与当事人关系' => '本人账户',
    '支付方式' => '法银直联',
    '实际收款人' => '王五',
    '身份证号/企业' => 'ID-001',
    '收款人银行户名' => '王五',
    '收款账号' => '62220001',
    '开户银行' => '测试银行',
    '银联号' => 'UNION-001',
    '是否本行' => '是',
    '承办人情况说明' => '案款发放',
    '申请人' => '经办人乙',
];
$refundReceiptOnly = ['票据号码' => 'RECEIPT-ONLY', '出账金额' => '5.00'];
$probe->biffCells = importParserBiffCells($refundHeadersWithoutReceiptTrace, [$refundDetail, $refundReceiptOnly]);
$refundRows = $probe->parseRefund('refund-binary', 'CASE_FUND', false);
importParserAssertSame(1, count($refundRows), 'Refund parser should keep its independent non-detail anchors');
$refund = $refundRows[0];
$expectedRefund = [
    'fiscal_year' => '2026',
    'period' => '2026-07',
    'case_no' => '（2026）辽0111执2号',
    'handler_name' => '经办人乙',
    'clerk_name' => '书记员乙',
    'receipt_no' => 'R-REFUND-001',
    'invoice_date' => '2026-06-30',
    'refund_date' => '2026-07-02',
    'source_receipt_no' => '',
    'source_receipt_date' => null,
    'out_order_no' => '',
    'out_status' => '银行处理中',
    'out_type' => '执行、调解款发放',
    'litigation_position' => '申请执行人',
    'party_name' => '王五',
    'refund_amount' => '88.51',
    'total_refund_amount' => '0.00',
    'payee_party_relation' => '本人账户',
    'payment_method' => '法银直联',
    'actual_payee_name' => '王五',
    'payee_identity_no' => 'ID-001',
    'payee_bank_account_name' => '王五',
    'payee_bank_account_no' => '62220001',
    'payee_bank_name' => '测试银行',
    'unionpay_no' => 'UNION-001',
    'same_bank_flag' => '是',
    'handler_note' => '案款发放',
    'applicant_name' => '经办人乙',
    'source_row_no' => 2,
];
foreach ($expectedRefund as $field => $expected) {
    importParserAssertSame($expected, $refund[$field] ?? null, 'Refund parser field mismatch: ' . $field);
}
$expectedRefundFingerprint = hash('sha256', implode('|', [
    $refund['case_no'],
    $refund['refund_date'],
    $refund['out_type'],
    $refund['refund_amount'],
    $refund['source_receipt_no'],
    $refund['out_order_no'],
    $refund['actual_payee_name'],
    $refund['payee_bank_account_no'],
]));
importParserAssertSame($expectedRefundFingerprint, $refund['source_fingerprint'], 'Refund parser should fingerprint optional trace defaults');
$refundRaw = json_decode($refund['source_raw_json'], true);
foreach (['总出账金额', '来源票据号码', '来源票据开票日期', '出账单号'] as $optionalHeader) {
    importParserAssertSame('', $refundRaw[$optionalHeader] ?? null, 'Missing optional refund header should be preserved as empty raw text: ' . $optionalHeader);
}

$unknownHeaders = [
    '法院名称', '认领状态', '缴款人', '金额', '银行摘要', '银行备注',
    '交易日期', '法院银行账户', '到账方式', '缴款人开户行', '缴款人账号',
    '认领人姓名', '认领日期', '最后修改时间',
];
$unknownDetail = [
    '法院名称' => '测试法院',
    '认领状态' => '待认领',
    '缴款人' => '赵六',
    '金额' => '66.004',
    '银行摘要' => '电子汇入',
    '银行备注' => '测试备注',
    '交易日期' => '2026/07/03 08:09:10',
    '法院银行账户' => 'COURT-001',
    '到账方式' => '其他方式',
    '缴款人开户行' => '付款银行',
    '缴款人账号' => 'PAYER-001',
    '认领人姓名' => '',
    '认领日期' => '',
    '最后修改时间' => 'invalid-date',
];
$probe->biffCells = importParserBiffCells($unknownHeaders, [$unknownDetail, []]);
$unknownRows = $probe->parseUnknownPayment('unknown-binary');
importParserAssertSame(1, count($unknownRows), 'Unknown-payment parser should skip empty rows');
$unknown = $unknownRows[0];
$expectedUnknown = [
    'fiscal_year' => '2026',
    'period' => '2026-07',
    'court_name' => '测试法院',
    'claim_status' => '待认领',
    'payer_name' => '赵六',
    'unknown_amount' => '66.00',
    'bank_summary' => '电子汇入',
    'bank_remark' => '测试备注',
    'transaction_date' => '2026-07-03',
    'transaction_time' => '2026-07-03 08:09:10',
    'court_bank_account_no' => 'COURT-001',
    'arrival_method' => '其他方式',
    'payer_bank_name' => '付款银行',
    'payer_account_no' => 'PAYER-001',
    'claim_user_name' => '',
    'claim_date' => null,
    'last_modified_time' => null,
    'source_row_no' => 2,
];
foreach ($expectedUnknown as $field => $expected) {
    importParserAssertSame($expected, $unknown[$field] ?? null, 'Unknown-payment parser field mismatch: ' . $field);
}
$expectedUnknownFingerprint = hash('sha256', implode('|', [
    $unknown['transaction_time'],
    $unknown['unknown_amount'],
    $unknown['court_bank_account_no'],
    $unknown['payer_account_no'],
    $unknown['payer_name'],
    $unknown['bank_remark'],
    $unknown['bank_summary'],
]));
importParserAssertSame($expectedUnknownFingerprint, $unknown['source_fingerprint'], 'Unknown-payment parser should fingerprint the mapped trace fields');

$distributionHeaders = [
    '序号', '分配单号', '转入案号', '转入承办法官', '转入当事人',
    '转出案号', '转出承办法官', '转出当事人', '开票种类', '单据号',
    '分配金额', '申请人', '申请日期', '接收日期', '状态', '情况说明',
    '原开票日期',
];
$distributionDetail = [
    '序号' => '1',
    '分配单号' => 'D-001',
    '转入案号' => 'CASE-IN',
    '转入承办法官' => '法官入',
    '转入当事人' => '当事人入',
    '转出案号' => 'CASE-OUT',
    '转出承办法官' => '法官出',
    '转出当事人' => '当事人出',
    '开票种类' => '执行、调解款',
    '单据号' => 'R-D-001',
    '分配金额' => '77.705',
    '申请人' => '申请人甲',
    '申请日期' => '2026/07/03',
    '接收日期' => '2026/07/04',
    '状态' => '正常可操作',
    '情况说明' => '测试划拨',
    '原开票日期' => '',
];
$distributionMalformed = $distributionDetail;
$distributionMalformed['分配单号'] = "BAD\0DISTRIBUTION";
$probe->tableRows = [
    $distributionHeaders,
    importParserOrderedRow($distributionHeaders, $distributionDetail),
    importParserOrderedRow($distributionHeaders, $distributionMalformed),
    importParserOrderedRow($distributionHeaders, []),
];
$distributionRows = $probe->parseDistribution('distribution-binary');
importParserAssertSame(1, count($distributionRows), 'Distribution parser should skip malformed and empty rows');
$distribution = $distributionRows[0];
$expectedDistribution = [
    'fiscal_year' => '2026',
    'period' => '2026-07',
    'distribution_no' => 'D-001',
    'in_case_no' => 'CASE-IN',
    'in_judge_name' => '法官入',
    'in_party_name' => '当事人入',
    'out_case_no' => 'CASE-OUT',
    'out_judge_name' => '法官出',
    'out_party_name' => '当事人出',
    'invoice_type' => '执行、调解款',
    'receipt_no' => 'R-D-001',
    'distribution_amount' => '77.71',
    'applicant_name' => '申请人甲',
    'apply_date' => '2026-07-03',
    'receive_date' => '2026-07-04',
    'business_status' => '正常可操作',
    'description' => '测试划拨',
    'original_invoice_date' => null,
    'source_row_no' => 2,
];
foreach ($expectedDistribution as $field => $expected) {
    importParserAssertSame($expected, $distribution[$field] ?? null, 'Distribution parser field mismatch: ' . $field);
}
$expectedDistributionFingerprint = hash('sha256', implode('|', [
    $distribution['distribution_no'],
    $distribution['in_case_no'],
    $distribution['out_case_no'],
    $distribution['invoice_type'],
    $distribution['receipt_no'],
    $distribution['distribution_amount'],
    $distribution['receive_date'],
]));
importParserAssertSame($expectedDistributionFingerprint, $distribution['source_fingerprint'], 'Distribution parser should fingerprint the mapped row');

importParserAssertThrows(
    '文件中没有可识别的工作表数据',
    function () use ($parser) {
        $parser->parsePaymentCells([]);
    },
    'Import parser should preserve empty-workbook errors'
);
$refundHeaderOrderProbe = new CaseFundImportParserRefundHeaderOrderProbe();
$refundHeaderOrderProbe->biffCells = [];
importParserAssertThrows(
    '文件中没有可识别的工作表数据',
    function () use ($refundHeaderOrderProbe) {
        $refundHeaderOrderProbe->parseRefund('empty-refund-binary', 'CASE_FUND', false);
    },
    'Refund parser should reject empty workbooks before resolving dynamic headers'
);
importParserAssertSame(0, $refundHeaderOrderProbe->requiredHeaderCalls, 'Empty refund workbooks should preserve header-resolution call order');
$refundHeaderOrderProbe->biffCells = importParserBiffCells($refundHeadersWithoutReceiptTrace, [$refundDetail]);
importParserAssertSame(
    1,
    count($refundHeaderOrderProbe->parseRefund('non-empty-refund-binary', 'CASE_FUND', false)),
    'Non-empty refund workbooks should resolve dynamic headers and parse normally'
);
importParserAssertSame(1, $refundHeaderOrderProbe->requiredHeaderCalls, 'Refund parser should invoke the CaseFund header hook exactly once');
$missingPaymentHeaders = $paymentHeaders;
array_shift($missingPaymentHeaders);
importParserAssertThrows(
    '缺少表头：案号',
    function () use ($parser, $missingPaymentHeaders) {
        $parser->parsePaymentCells(importParserBiffCells($missingPaymentHeaders, [[]]));
    },
    'Import parser should preserve the first missing-header error'
);

echo 'Case fund import parser test passed' . PHP_EOL;
