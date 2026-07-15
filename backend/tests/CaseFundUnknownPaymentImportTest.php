<?php

date_default_timezone_set('Asia/Shanghai');

function input($key = '', $default = null) { return $default; }
function config($key = '') {
    if ($key === 'default_account_set_id') return '00000000-0000-0000-0000-000000000101';
    if ($key === 'default_year') return '2026';
    return null;
}
function getNowTime() { return date('Y-m-d H:i:s'); }
function uuid() { return '12345678-1234-4000-8000-123456789abc'; }
function unknownPaymentImportCents($amount) {
    $text = trim((string)$amount);
    if ($text === '') return 0;
    $negative = false;
    if (substr($text, 0, 1) === '-') {
        $negative = true;
        $text = substr($text, 1);
    }
    $parts = explode('.', $text, 2);
    $yuan = preg_replace('/\D/', '', $parts[0]);
    $cent = isset($parts[1]) ? preg_replace('/\D/', '', $parts[1]) : '';
    $cent = substr(str_pad($cent, 2, '0'), 0, 2);
    $cents = ((int)$yuan) * 100 + (int)$cent;
    return $negative ? -$cents : $cents;
}

function unknownPaymentImportFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function unknownPaymentImportAssertSame($expected, $actual, $message)
{
    if ($actual !== $expected) {
        unknownPaymentImportFail(
            $message
            . '; expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

function unknownPaymentNumberCell($value)
{
    return ['type' => 'Number', 'value' => (string)$value];
}

function unknownPaymentExcelXml($headers, $rows)
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
        . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
    $xml .= '<Worksheet ss:Name="AnonymousUnknownPayments"><Table>';
    $allRows = [array_combine($headers, $headers)];
    foreach ($rows as $row) {
        $allRows[] = $row;
    }
    foreach ($allRows as $row) {
        $xml .= '<Row>';
        foreach ($headers as $header) {
            $cell = $row[$header] ?? '';
            $type = 'String';
            $value = $cell;
            if (is_array($cell) && array_key_exists('value', $cell)) {
                $type = $cell['type'] ?? 'String';
                $value = $cell['value'];
            }
            $xml .= '<Cell><Data ss:Type="' . $type . '">'
                . htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8')
                . '</Data></Cell>';
        }
        $xml .= '</Row>';
    }
    return $xml . '</Table></Worksheet></Workbook>';
}

function unknownPaymentParsedProfileHash($rows)
{
    $profile = [];
    foreach ($rows as $row) {
        $profile[] = [
            'source_row_no' => (int)($row['source_row_no'] ?? 0),
            'fiscal_year' => (string)($row['fiscal_year'] ?? ''),
            'period' => (string)($row['period'] ?? ''),
            'claim_status' => (string)($row['claim_status'] ?? ''),
            'unknown_amount' => (string)($row['unknown_amount'] ?? ''),
            'transaction_date' => (string)($row['transaction_date'] ?? ''),
            'transaction_time' => (string)($row['transaction_time'] ?? ''),
            'arrival_method' => (string)($row['arrival_method'] ?? ''),
            'claim_date' => $row['claim_date'] ?? null,
            'last_modified_time' => $row['last_modified_time'] ?? null,
            'source_fingerprint' => (string)($row['source_fingerprint'] ?? ''),
        ];
    }
    return hash('sha256', json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$ddlPath = __DIR__ . '/../database/court_finance_ddl.sql';
$patchPath = __DIR__ . '/../database/finance_standard_patch.sql';
require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

$ddlSource = file_get_contents($ddlPath);
if (strpos($ddlSource, 'uk_case_fund_unknown_payment_source on fin_case_fund_unknown_payment (account_set_id, source_fingerprint, del_flag)') === false) {
    fwrite(STDERR, 'Unknown payment DDL unique index should include del_flag for soft-delete reimport' . PHP_EOL);
    exit(1);
}

$patchSource = file_get_contents($patchPath);
if (strpos($patchSource, 'unique key uk_case_fund_unknown_payment_source (account_set_id, source_fingerprint, del_flag)') === false) {
    fwrite(STDERR, 'Unknown payment patch unique key should include del_flag for soft-delete reimport' . PHP_EOL);
    exit(1);
}
if (strpos($patchSource, 'drop index uk_case_fund_unknown_payment_source on fin_case_fund_unknown_payment') === false
    || strpos($patchSource, 'alter table fin_case_fund_unknown_payment add unique key uk_case_fund_unknown_payment_source (account_set_id, source_fingerprint, del_flag)') === false) {
    fwrite(STDERR, 'Unknown payment patch should migrate existing unique index to include del_flag' . PHP_EOL);
    exit(1);
}

use app\finance\model\CaseFund;

class CaseFundUnknownPaymentXmlProbe extends CaseFund
{
    protected function readOleWorkbookStream($binary)
    {
        return $binary;
    }

    protected function parseBiffCells($stream)
    {
        $tableRows = $this->parseSpreadsheetRows($stream);
        $cells = [];
        foreach ($tableRows as $row => $values) {
            foreach ($values as $column => $value) {
                $cells[$row . ':' . $column] = $value;
            }
        }
        return $cells;
    }
}

$headers = [
    '法院名称', '认领状态', '缴款人', '金额', '银行摘要', '银行备注',
    '交易日期', '法院银行账户', '到账方式', '缴款人开户行', '缴款人账号',
    '认领人姓名', '认领日期', '最后修改时间',
];
$syntheticRows = [
    [
        '法院名称' => '匿名测试法院甲',
        '认领状态' => '待认领',
        '缴款人' => '匿名付款人甲',
        '金额' => '1234.56',
        '银行摘要' => '电子汇入',
        '银行备注' => '匿名备注甲',
        '交易日期' => '2026/07/03 08:09:10',
        '法院银行账户' => 'COURT-ACCOUNT-TEST-001',
        '到账方式' => '其他方式',
        '缴款人开户行' => '匿名银行甲',
        '缴款人账号' => 'PAYER-ACCOUNT-TEST-001',
        '认领人姓名' => '匿名认领人甲',
        '认领日期' => '2026-07-04',
        '最后修改时间' => '2026/07/05 09:10:11',
    ],
    [
        '法院名称' => '匿名测试法院乙',
        '认领状态' => '已认领',
        '缴款人' => '匿名付款人乙',
        '金额' => unknownPaymentNumberCell('78.90'),
        '银行摘要' => '柜面转入',
        '银行备注' => '',
        '交易日期' => unknownPaymentNumberCell('46207.5'),
        '法院银行账户' => 'COURT-ACCOUNT-TEST-002',
        '到账方式' => '银行转账',
        '缴款人开户行' => '匿名银行乙',
        '缴款人账号' => 'PAYER-ACCOUNT-TEST-002',
        '认领人姓名' => '匿名认领人乙',
        '认领日期' => unknownPaymentNumberCell('46208'),
        '最后修改时间' => unknownPaymentNumberCell('46209.5'),
    ],
    [],
];

$caseFund = new CaseFundUnknownPaymentXmlProbe();
$parseMethod = new ReflectionMethod(CaseFund::class, 'parseUnknownPaymentImportRowsFromXls');
$parseMethod->setAccessible(true);
$rows = $parseMethod->invoke($caseFund, unknownPaymentExcelXml($headers, $syntheticRows));

unknownPaymentImportAssertSame(2, count($rows), 'Synthetic Excel XML should parse two non-empty unknown-payment rows');

$first = $rows[0];
$expectedFirst = [
    'fiscal_year' => '2026',
    'period' => '2026-07',
    'court_name' => '匿名测试法院甲',
    'claim_status' => '待认领',
    'payer_name' => '匿名付款人甲',
    'unknown_amount' => '1234.56',
    'bank_summary' => '电子汇入',
    'bank_remark' => '匿名备注甲',
    'transaction_date' => '2026-07-03',
    'transaction_time' => '2026-07-03 08:09:10',
    'court_bank_account_no' => 'COURT-ACCOUNT-TEST-001',
    'arrival_method' => '其他方式',
    'payer_bank_name' => '匿名银行甲',
    'payer_account_no' => 'PAYER-ACCOUNT-TEST-001',
    'claim_user_name' => '匿名认领人甲',
    'claim_date' => '2026-07-04',
    'last_modified_time' => '2026-07-05 09:10:11',
    'source_row_no' => 2,
];
foreach ($expectedFirst as $field => $expected) {
    unknownPaymentImportAssertSame($expected, $first[$field] ?? null, 'Unexpected synthetic first row field: ' . $field);
}

$second = $rows[1];
$expectedSecond = [
    'fiscal_year' => '2026',
    'period' => '2026-07',
    'court_name' => '匿名测试法院乙',
    'claim_status' => '已认领',
    'payer_name' => '匿名付款人乙',
    'unknown_amount' => '78.90',
    'bank_summary' => '柜面转入',
    'bank_remark' => '',
    'transaction_date' => '2026-07-04',
    'transaction_time' => '2026-07-04 20:00:00',
    'court_bank_account_no' => 'COURT-ACCOUNT-TEST-002',
    'arrival_method' => '银行转账',
    'payer_bank_name' => '匿名银行乙',
    'payer_account_no' => 'PAYER-ACCOUNT-TEST-002',
    'claim_user_name' => '匿名认领人乙',
    'claim_date' => '2026-07-05',
    'last_modified_time' => '2026-07-06 20:00:00',
    'source_row_no' => 3,
];
foreach ($expectedSecond as $field => $expected) {
    unknownPaymentImportAssertSame($expected, $second[$field] ?? null, 'Unexpected synthetic serial-date row field: ' . $field);
}

$totalCents = 0;
foreach ($rows as $row) {
    $totalCents += unknownPaymentImportCents($row['unknown_amount']);
}
unknownPaymentImportAssertSame(131346, $totalCents, 'Synthetic unknown-payment amounts should total by integer cents');

unknownPaymentImportAssertSame(
    hash('sha256', implode('|', [
        '2026-07-03 08:09:10',
        '1234.56',
        'COURT-ACCOUNT-TEST-001',
        'PAYER-ACCOUNT-TEST-001',
        '匿名付款人甲',
        '匿名备注甲',
        '电子汇入',
    ])),
    $first['source_fingerprint'] ?? '',
    'Synthetic unknown-payment fingerprint should cover every configured trace component'
);
unknownPaymentImportAssertSame(64, strlen($second['source_fingerprint'] ?? ''), 'Serial-date row should have a sha256 source fingerprint');

$rawFirst = json_decode($first['source_raw_json'] ?? '', true);
unknownPaymentImportAssertSame($headers, array_keys(is_array($rawFirst) ? $rawFirst : []), 'Synthetic raw JSON should retain all unknown-payment columns in header order');

$missingHeaderXml = unknownPaymentExcelXml(array_slice($headers, 0, -1), [array_slice($syntheticRows[0], 0, -1, true)]);
try {
    $parseMethod->invoke($caseFund, $missingHeaderXml);
    unknownPaymentImportFail('Unknown-payment Excel XML should reject a missing required header');
} catch (Exception $exception) {
    if (strpos($exception->getMessage(), '缺少表头：最后修改时间') === false) {
        unknownPaymentImportFail('Unexpected missing-header error: ' . $exception->getMessage());
    }
}

$fingerprintMethod = new ReflectionMethod(CaseFund::class, 'unknownPaymentFingerprint');
$fingerprintMethod->setAccessible(true);
$payerOnlyA = $first;
$payerOnlyA['payer_name'] = '匿名追溯人甲';
$payerOnlyA['court_bank_account_no'] = '';
$payerOnlyA['payer_account_no'] = '';
$payerOnlyA['bank_remark'] = '';
$payerOnlyA['bank_summary'] = '';
$payerOnlyB = $payerOnlyA;
$payerOnlyB['payer_name'] = '匿名追溯人乙';
if ($fingerprintMethod->invoke($caseFund, $payerOnlyA) === $fingerprintMethod->invoke($caseFund, $payerOnlyB)) {
    fwrite(STDERR, 'Unknown payment fingerprint should include payer_name for payer-only trace rows' . PHP_EOL);
    exit(1);
}

$validateMethod = new ReflectionMethod(CaseFund::class, 'validateUnknownPaymentRows');
$validateMethod->setAccessible(true);
$errors = $validateMethod->invoke($caseFund, $rows, 'CASE_FUND');
if (!empty($errors)) {
    fwrite(STDERR, 'Sample unknown payments should pass CASE_FUND validation: ' . $errors[0] . PHP_EOL);
    exit(1);
}

$payerOnlyTrace = $first;
$payerOnlyTrace['source_row_no'] = 9001;
$payerOnlyTrace['bank_remark'] = '';
$payerOnlyTrace['bank_summary'] = '';
$payerOnlyTrace['court_bank_account_no'] = '';
$payerOnlyTrace['payer_account_no'] = '';
$payerOnlyTraceErrors = $validateMethod->invoke($caseFund, [$payerOnlyTrace], 'CASE_FUND');
if (!empty($payerOnlyTraceErrors)) {
    fwrite(STDERR, 'Unknown payment validation should allow payer_name as the only trace field: ' . $payerOnlyTraceErrors[0] . PHP_EOL);
    exit(1);
}

$bankRemarkOnlyTrace = $first;
$bankRemarkOnlyTrace['source_row_no'] = 9002;
$bankRemarkOnlyTrace['payer_name'] = '';
$bankRemarkOnlyTrace['bank_summary'] = '';
$bankRemarkOnlyTrace['court_bank_account_no'] = '';
$bankRemarkOnlyTrace['payer_account_no'] = '';
$bankRemarkOnlyTraceErrors = $validateMethod->invoke($caseFund, [$bankRemarkOnlyTrace], 'CASE_FUND');
if (!empty($bankRemarkOnlyTraceErrors)) {
    fwrite(STDERR, 'Unknown payment validation should allow bank_remark as the only trace field: ' . $bankRemarkOnlyTraceErrors[0] . PHP_EOL);
    exit(1);
}

$bankSummaryOnlyTrace = $first;
$bankSummaryOnlyTrace['source_row_no'] = 9003;
$bankSummaryOnlyTrace['payer_name'] = '';
$bankSummaryOnlyTrace['bank_remark'] = '';
$bankSummaryOnlyTrace['bank_summary'] = '电子汇入';
$bankSummaryOnlyTrace['court_bank_account_no'] = '';
$bankSummaryOnlyTrace['payer_account_no'] = '';
$bankSummaryOnlyTraceErrors = $validateMethod->invoke($caseFund, [$bankSummaryOnlyTrace], 'CASE_FUND');
if (!empty($bankSummaryOnlyTraceErrors)) {
    fwrite(STDERR, 'Unknown payment validation should allow bank_summary as the only trace field: ' . $bankSummaryOnlyTraceErrors[0] . PHP_EOL);
    exit(1);
}

$courtAccountOnlyTrace = $first;
$courtAccountOnlyTrace['source_row_no'] = 9004;
$courtAccountOnlyTrace['payer_name'] = '';
$courtAccountOnlyTrace['bank_remark'] = '';
$courtAccountOnlyTrace['bank_summary'] = '';
$courtAccountOnlyTrace['payer_account_no'] = '';
$courtAccountOnlyTraceErrors = $validateMethod->invoke($caseFund, [$courtAccountOnlyTrace], 'CASE_FUND');
if (!empty($courtAccountOnlyTraceErrors)) {
    fwrite(STDERR, 'Unknown payment validation should allow court_bank_account_no as the only trace field: ' . $courtAccountOnlyTraceErrors[0] . PHP_EOL);
    exit(1);
}

$payerAccountOnlyTrace = $first;
$payerAccountOnlyTrace['source_row_no'] = 9005;
$payerAccountOnlyTrace['payer_name'] = '';
$payerAccountOnlyTrace['bank_remark'] = '';
$payerAccountOnlyTrace['bank_summary'] = '';
$payerAccountOnlyTrace['court_bank_account_no'] = '';
$payerAccountOnlyTraceErrors = $validateMethod->invoke($caseFund, [$payerAccountOnlyTrace], 'CASE_FUND');
if (!empty($payerAccountOnlyTraceErrors)) {
    fwrite(STDERR, 'Unknown payment validation should allow payer_account_no as the only trace field: ' . $payerAccountOnlyTraceErrors[0] . PHP_EOL);
    exit(1);
}

$missingTrace = $first;
$missingTrace['source_row_no'] = 9006;
foreach (['payer_name', 'bank_remark', 'bank_summary', 'court_bank_account_no', 'payer_account_no'] as $traceField) {
    $missingTrace[$traceField] = '';
}
$missingTraceErrors = $validateMethod->invoke($caseFund, [$missingTrace], 'CASE_FUND');
if (empty($missingTraceErrors)
    || strpos(implode("\n", $missingTraceErrors), '至少填写一项用于追溯') === false
) {
    fwrite(STDERR, 'Unknown payment validation should reject rows without any trace field' . PHP_EOL);
    exit(1);
}

$badDate = $first;
$badDate['source_row_no'] = 9007;
$badDate['transaction_date'] = 'not-a-date';
$badDateErrors = $validateMethod->invoke($caseFund, [$badDate], 'CASE_FUND');
if (empty($badDateErrors) || strpos(implode("\n", $badDateErrors), '交易日期不能为空或格式不正确') === false) {
    fwrite(STDERR, 'Unknown payment validation should reject invalid transaction_date' . PHP_EOL);
    exit(1);
}

$wrongBizErrors = $validateMethod->invoke($caseFund, [$first], 'LITIGATION_FEE');
if (empty($wrongBizErrors) || strpos(implode("\n", $wrongBizErrors), '当前账套类型不支持不明款业务') === false) {
    fwrite(STDERR, 'Non case-fund account sets should reject unknown payment import' . PHP_EOL);
    exit(1);
}

$summaryMethod = new ReflectionMethod(CaseFund::class, 'buildUnknownPaymentVoucherSummary');
$summaryMethod->setAccessible(true);
$summary = $summaryMethod->invoke($caseFund, $first);
if ($summary !== '收 不明款 匿名付款人甲 匿名备注甲') {
    fwrite(STDERR, 'Unexpected unknown payment voucher summary: ' . $summary . PHP_EOL);
    exit(1);
}

$fallback = $first;
$fallback['bank_remark'] = '';
$fallback['bank_summary'] = '电子汇入';
if ($summaryMethod->invoke($caseFund, $fallback) !== '收 不明款 匿名付款人甲 电子汇入') {
    fwrite(STDERR, 'Unknown payment summary should fall back to bank summary' . PHP_EOL);
    exit(1);
}

$realSamplePath = null;
$configuredRealSamplePath = getenv('CASE_FUND_UNKNOWN_PAYMENT_SAMPLE_XLS');
if ($configuredRealSamplePath !== false && trim($configuredRealSamplePath) !== '') {
    $configuredRealSamplePath = trim($configuredRealSamplePath);
    if (!is_file($configuredRealSamplePath)) {
        unknownPaymentImportFail('Configured unknown-payment sample XLS is not a readable file');
    }
    $realSamplePath = $configuredRealSamplePath;
} else {
    $rootRealSamplePath = __DIR__ . '/../../不明款查询 (4).xls';
    if (is_file($rootRealSamplePath)) {
        $realSamplePath = $rootRealSamplePath;
    }
}

if ($realSamplePath !== null) {
    unknownPaymentImportAssertSame(
        '07f01aee6c4bab9195cd3edc5b0a4e384a51cd3a4b049c5570a599880963aaa5',
        hash_file('sha256', $realSamplePath),
        'Optional real unknown-payment sample file hash mismatch'
    );
    $realCaseFund = new CaseFund();
    $realRows = $parseMethod->invoke($realCaseFund, file_get_contents($realSamplePath));
    unknownPaymentImportAssertSame(37, count($realRows), 'Optional real unknown-payment sample row count mismatch');
    $realTotalCents = 0;
    foreach ($realRows as $realRow) {
        $realTotalCents += unknownPaymentImportCents($realRow['unknown_amount'] ?? '0');
    }
    unknownPaymentImportAssertSame(377613674, $realTotalCents, 'Optional real unknown-payment sample total cents mismatch');
    unknownPaymentImportAssertSame(
        '458c3b1f41f00e09def8d070807ecd5c02595b8249e300aa371d7bf8ca533ea4',
        unknownPaymentParsedProfileHash($realRows),
        'Optional real unknown-payment parsed profile mismatch'
    );
    $realErrors = $validateMethod->invoke($realCaseFund, $realRows, 'CASE_FUND');
    if (!empty($realErrors)) {
        unknownPaymentImportFail('Optional real unknown-payment sample should pass CASE_FUND validation: ' . $realErrors[0]);
    }
}

$source = file_get_contents($modelPath);
foreach ([
    "case 'unknownPaymentList'" => 'CaseFund should route unknownPaymentList',
    "case 'unknownPaymentImport'" => 'CaseFund should route unknownPaymentImport',
    'public function unknownPaymentList' => 'CaseFund should expose unknownPaymentList',
    'public function unknownPaymentImport' => 'CaseFund should expose unknownPaymentImport',
    "requirePermission('case_fund:view')" => 'Unknown payment list should check view permission',
    "requirePermission('case_fund:import')" => 'Unknown payment import should check import permission',
    'CASE_FUND_UNKNOWN_PAYMENT' => 'Unknown payment writes should use audit biz type',
] as $needle => $message) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Case fund unknown payment import test passed' . PHP_EOL;
