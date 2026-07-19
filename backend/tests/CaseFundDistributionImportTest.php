<?php

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$ddlPath = __DIR__ . '/../database/court_finance_ddl.sql';
$patchPath = __DIR__ . '/../database/finance_standard_patch.sql';
$source = file_get_contents($modelPath);
$ddl = file_get_contents($ddlPath);
$patch = file_get_contents($patchPath);

foreach ([
    "case 'distributionList'" => 'CaseFund should route distributionList',
    "case 'distributionImport'" => 'CaseFund should route distributionImport',
    "case 'distributionDelete'" => 'CaseFund should route distributionDelete',
    'public function distributionList' => 'CaseFund should expose distributionList',
    'public function distributionImport' => 'CaseFund should expose distributionImport',
    'public function distributionDelete' => 'CaseFund should expose distributionDelete',
    "requirePermission('case_fund:view')" => 'Distribution list should check view permission',
    "requirePermission('case_fund:import')" => 'Distribution import should check import permission',
    'TABLE_DISTRIBUTION' => 'CaseFund should define distribution table constant',
    'parseDistributionImportRowsFromXls' => 'CaseFund should parse distribution xls rows',
    'validateDistributionRows' => 'CaseFund should validate distribution rows',
    'distributionFingerprint' => 'CaseFund should generate distribution fingerprint',
    'allowedDistributionInvoiceTypes' => 'CaseFund should define allowed distribution invoice types',
    'CASE_FUND_DISTRIBUTION' => 'Distribution import should write audit log',
] as $needle => $message) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

if (strpos($ddl, 'create table fin_case_fund_distribution') === false) {
    fwrite(STDERR, 'DDL should create fin_case_fund_distribution' . PHP_EOL);
    exit(1);
}
if (strpos($patch, 'create table if not exists fin_case_fund_distribution') === false) {
    fwrite(STDERR, 'Finance standard patch should create fin_case_fund_distribution idempotently' . PHP_EOL);
    exit(1);
}
if (strpos($ddl, 'uk_case_fund_distribution_source') === false || strpos($ddl, 'source_fingerprint, del_flag') === false) {
    fwrite(STDERR, 'Distribution DDL should keep soft-delete-aware source fingerprint index' . PHP_EOL);
    exit(1);
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

function distributionImportCents($row)
{
    $amount = trim((string)($row['distribution_amount'] ?? ''));
    if (!preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/', $amount, $matches)) {
        throw new RuntimeException('Invalid distribution amount: ' . $amount);
    }
    $fraction = isset($matches[2]) ? str_pad($matches[2], 2, '0') : '00';
    return ((int)$matches[1] * 100) + (int)$fraction;
}

function distributionImportExcelXml($headers, $rows)
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
        . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
        . '<Worksheet ss:Name="匿名合成样例"><Table>';
    $orderedRows = [$headers];
    foreach ($rows as $row) {
        $ordered = [];
        foreach ($headers as $header) {
            $ordered[] = $row[$header] ?? '';
        }
        $orderedRows[] = $ordered;
    }
    foreach ($orderedRows as $row) {
        $xml .= '<Row>';
        foreach ($row as $cell) {
            $type = 'String';
            $value = $cell;
            if (is_array($cell)) {
                $type = (string)($cell['type'] ?? 'String');
                $value = $cell['value'] ?? '';
            }
            $xml .= '<Cell><Data ss:Type="' . htmlspecialchars($type, ENT_QUOTES | ENT_XML1, 'UTF-8') . '">'
                . htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8')
                . '</Data></Cell>';
        }
        $xml .= '</Row>';
    }
    return $xml . '</Table></Worksheet></Workbook>';
}

function distributionImportAssertProfile($rows, $profile, $profileKey)
{
    if (empty($rows)) {
        fwrite(STDERR, 'Distribution import should parse at least one row for ' . $profileKey . PHP_EOL);
        exit(1);
    }
    if (count($rows) !== $profile['row_count']) {
        fwrite(STDERR, 'Distribution import row count mismatch for ' . $profileKey . ': expected '
            . $profile['row_count'] . ', got ' . count($rows) . PHP_EOL);
        exit(1);
    }

    $first = $rows[0];
    $expectedFirst = $profile['first'] ?? [];
    foreach ($expectedFirst as $field => $expected) {
        if (($first[$field] ?? null) !== $expected) {
            fwrite(STDERR, "First distribution row {$field} mismatch for {$profileKey}" . PHP_EOL);
            exit(1);
        }
    }
    if (isset($profile['first_sha256'])) {
        $firstValues = [];
        foreach ($profile['first_fields'] as $field) {
            $firstValues[] = array_key_exists($field, $first) ? $first[$field] : null;
        }
        $firstSha256 = hash('sha256', json_encode($firstValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($firstSha256 !== $profile['first_sha256']) {
            fwrite(STDERR, 'First distribution row digest mismatch for ' . $profileKey . ': got ' . $firstSha256 . PHP_EOL);
            exit(1);
        }
    }

    $totalCents = 0;
    foreach ($rows as $row) {
        $totalCents += distributionImportCents($row);
    }
    if ($totalCents !== $profile['total_cents']) {
        fwrite(STDERR, 'Distribution import total cents mismatch for ' . $profileKey . ': expected '
            . $profile['total_cents'] . ', got ' . $totalCents . PHP_EOL);
        exit(1);
    }
    return $first;
}

date_default_timezone_set('Asia/Shanghai');

$caseFund = new CaseFund();
$parseMethod = new ReflectionMethod(CaseFund::class, 'parseDistributionImportRowsFromXls');
$parseMethod->setAccessible(true);
$parseWithReceiptPolicyMethod = new ReflectionMethod(CaseFund::class, 'parseDistributionImportRowsFromXlsForReceiptPolicy');
$parseWithReceiptPolicyMethod->setAccessible(true);
$validateMethod = new ReflectionMethod(CaseFund::class, 'validateDistributionRows');
$validateMethod->setAccessible(true);
$validateWithReceiptPolicyMethod = new ReflectionMethod(CaseFund::class, 'validateDistributionRowsForReceiptPolicy');
$validateWithReceiptPolicyMethod->setAccessible(true);
$fingerprintMethod = new ReflectionMethod(CaseFund::class, 'distributionFingerprint');
$fingerprintMethod->setAccessible(true);

$distributionHeaders = [
    '序号', '分配单号', '转入案号', '转入承办法官', '转入当事人',
    '转出案号', '转出承办法官', '转出当事人', '开票种类', '单据号',
    '分配金额', '申请人', '申请日期', '接收日期', '状态', '情况说明',
    '原开票日期',
];
$syntheticXml = distributionImportExcelXml($distributionHeaders, [
    [
        '序号' => ['type' => 'Number', 'value' => '1'],
        '分配单号' => 'SYN-DIST-001',
        '转入案号' => 'SYN-IN-001',
        '转入承办法官' => '匿名人员A',
        '转入当事人' => '匿名主体A',
        '转出案号' => 'SYN-OUT-001',
        '转出承办法官' => '匿名人员B',
        '转出当事人' => '匿名主体B',
        '开票种类' => '执行、调解款',
        '单据号' => 'SYN-REC-001',
        '分配金额' => ['type' => 'Number', 'value' => '123.45'],
        '申请人' => '匿名申请人A',
        '申请日期' => '2026/07/03',
        '接收日期' => '2026-07-04',
        '状态' => '正常可操作',
        '情况说明' => '合成样例-字符串日期',
        '原开票日期' => '2026/07/01',
    ],
    [
        '序号' => ['type' => 'Number', 'value' => '2'],
        '分配单号' => 'SYN-DIST-002',
        '转入案号' => 'SYN-IN-002',
        '转入承办法官' => '匿名人员C',
        '转入当事人' => '匿名主体C',
        '转出案号' => 'SYN-OUT-002',
        '转出承办法官' => '匿名人员D',
        '转出当事人' => '匿名主体D',
        '开票种类' => '执行、调解款',
        '单据号' => 'SYN-REC-002',
        '分配金额' => ['type' => 'Number', 'value' => '76.55'],
        '申请人' => '匿名申请人B',
        '申请日期' => ['type' => 'Number', 'value' => '46172'],
        '接收日期' => ['type' => 'Number', 'value' => '46182'],
        '状态' => '正常可操作',
        '情况说明' => '合成样例-序列日期',
        '原开票日期' => ['type' => 'Number', 'value' => '46110'],
    ],
]);
$syntheticRows = $parseMethod->invoke($caseFund, $syntheticXml);
$syntheticProfile = [
    'row_count' => 2,
    'total_cents' => 20000,
    'first' => [
        'source_row_no' => 2,
        'distribution_no' => 'SYN-DIST-001',
        'in_case_no' => 'SYN-IN-001',
        'in_judge_name' => '匿名人员A',
        'in_party_name' => '匿名主体A',
        'out_case_no' => 'SYN-OUT-001',
        'out_judge_name' => '匿名人员B',
        'out_party_name' => '匿名主体B',
        'invoice_type' => '执行、调解款',
        'receipt_no' => 'SYN-REC-001',
        'distribution_amount' => '123.45',
        'applicant_name' => '匿名申请人A',
        'apply_date' => '2026-07-03',
        'receive_date' => '2026-07-04',
        'period' => '2026-07',
        'fiscal_year' => '2026',
        'business_status' => '正常可操作',
        'description' => '合成样例-字符串日期',
        'original_invoice_date' => '2026-07-01',
        'source_fingerprint' => 'cbdce40542313114f4ce2838ddb62a5d3c4e9d41a5bf3ba5bf9679fc566c4938',
    ],
];
$first = distributionImportAssertProfile($syntheticRows, $syntheticProfile, 'inline-anonymous-excel-xml');
$second = $syntheticRows[1] ?? [];
foreach ([
    'source_row_no' => 3,
    'distribution_amount' => '76.55',
    'apply_date' => '2026-05-30',
    'receive_date' => '2026-06-09',
    'original_invoice_date' => '2026-03-29',
    'period' => '2026-06',
    'fiscal_year' => '2026',
] as $field => $expected) {
    if (($second[$field] ?? null) !== $expected) {
        fwrite(STDERR, 'Synthetic Excel serial date row mismatch: ' . $field . PHP_EOL);
        exit(1);
    }
}
if ($validateMethod->invoke($caseFund, $syntheticRows, 'CASE_FUND') !== []) {
    fwrite(STDERR, 'Synthetic CASE_FUND distribution rows should validate cleanly' . PHP_EOL);
    exit(1);
}

$noReceiptHeaders = [
    '转入案号', '承办法官', '转入当事人', '转出案号', '转出当事人',
    '开票种类', '分配金额', '申请人', '申请日期', '接收日期', '状态',
    '分配单号', '情况说明',
];
$noReceiptXml = distributionImportExcelXml($noReceiptHeaders, [[
    '转入案号' => 'NO-RECEIPT-IN-001',
    '承办法官' => '匿名人员E',
    '转入当事人' => '匿名主体E',
    '转出案号' => 'NO-RECEIPT-OUT-001',
    '转出当事人' => '匿名主体F',
    '开票种类' => '执行、调解款',
    '分配金额' => ['type' => 'Number', 'value' => '88.80'],
    '申请人' => '',
    '申请日期' => '2026-07-18',
    '接收日期' => '2026-07-19',
    '状态' => '正常可操作',
    '分配单号' => '',
    '情况说明' => '无单据号样例',
]]);
$noReceiptRows = $parseWithReceiptPolicyMethod->invoke($caseFund, $noReceiptXml, false);
$noReceiptFirst = $noReceiptRows[0] ?? [];
foreach ([
    'distribution_no' => '',
    'in_case_no' => 'NO-RECEIPT-IN-001',
    'in_judge_name' => '匿名人员E',
    'out_case_no' => 'NO-RECEIPT-OUT-001',
    'out_judge_name' => '',
    'receipt_no' => '',
    'distribution_amount' => '88.80',
    'receive_date' => '2026-07-19',
    'original_invoice_date' => null,
] as $field => $expected) {
    if (($noReceiptFirst[$field] ?? null) !== $expected) {
        fwrite(STDERR, 'No-receipt distribution row mismatch: ' . $field . PHP_EOL);
        exit(1);
    }
}
if ($validateWithReceiptPolicyMethod->invoke($caseFund, $noReceiptRows, 'CASE_FUND', false) !== []) {
    fwrite(STDERR, 'Distribution rows without document numbers should validate when receipt trace is not configured' . PHP_EOL);
    exit(1);
}
$strictNoReceiptErrors = $validateWithReceiptPolicyMethod->invoke($caseFund, $noReceiptRows, 'CASE_FUND', true);
if ($strictNoReceiptErrors !== [
    '第2行：分配单号不能为空',
    '第2行：单据号不能为空',
]) {
    fwrite(STDERR, 'Distribution rows without document numbers must remain strict when receipt trace is configured' . PHP_EOL);
    exit(1);
}
try {
    $parseWithReceiptPolicyMethod->invoke($caseFund, $noReceiptXml, true);
    fwrite(STDERR, 'Strict distribution parser should reject the no-receipt header profile' . PHP_EOL);
    exit(1);
} catch (ReflectionException $e) {
    throw $e;
} catch (Exception $e) {
    if (strpos($e->getMessage(), '缺少表头') === false) {
        throw $e;
    }
}

$legacyProfiles = [
    'ZXFP60726051900001' => [
        'row_count' => 32,
        'total_cents' => 327331559,
        'first' => [
            'source_row_no' => 2,
            'distribution_no' => 'ZXFP60726051900001',
            'in_case_no' => '（2024）辽0111执3548号',
            'in_judge_name' => '赵之泽',
            'in_party_name' => '吴健',
            'out_case_no' => '（2017）辽0111执611号',
            'out_judge_name' => '',
            'out_party_name' => '李洁',
            'invoice_type' => '执行、调解款',
            'receipt_no' => '0001759490',
            'distribution_amount' => '5084.00',
            'applicant_name' => '赵之泽',
            'apply_date' => '2026-05-19',
            'receive_date' => '2026-05-20',
            'period' => '2026-05',
            'fiscal_year' => '2026',
            'business_status' => '正常可操作',
            'description' => '',
            'original_invoice_date' => '2026-02-09',
        ],
    ],
];
$newSampleSha256 = 'd4b6b1d12dac0e1ddd8fa15c87f491a9009f6a707b06b617f8aee1949a734b70';
$noReceiptSampleSha256 = 'cafe0a5cd2671679a1e8c5a0f0732d41b83bf1a11fcb79d0c6ebf0a0b4a5177b';
$rootSamplePath = __DIR__ . '/../../分配清单(带单据号) (3).xls';
$envSamplePath = trim((string)getenv('CASE_FUND_DISTRIBUTION_SAMPLE_XLS'));
$realSamplePath = '';
if ($envSamplePath !== '') {
    if (!is_file($envSamplePath)) {
        fwrite(STDERR, 'CASE_FUND_DISTRIBUTION_SAMPLE_XLS does not point to a file' . PHP_EOL);
        exit(1);
    }
    $realSamplePath = $envSamplePath;
} elseif (is_file($rootSamplePath)) {
    $realSamplePath = $rootSamplePath;
}
if ($realSamplePath !== '') {
    $realSampleContents = file_get_contents($realSamplePath);
    $realSampleSha256 = hash('sha256', $realSampleContents);
    $realReceiptTraceRequired = $realSampleSha256 !== $noReceiptSampleSha256;
    $realRows = $parseWithReceiptPolicyMethod->invoke(
        $caseFund,
        $realSampleContents,
        $realReceiptTraceRequired
    );
    if ($realSampleSha256 === $noReceiptSampleSha256) {
        $realProfileKey = 'sha256:' . $realSampleSha256;
        $realProfile = [
            'row_count' => 620,
            'total_cents' => 5146971319,
            'first' => [
                'source_row_no' => 2,
                'distribution_no' => '',
                'in_case_no' => '（2023）辽0111执3220号',
                'in_judge_name' => '安福杰',
                'in_party_name' => '吴亚琴',
                'out_case_no' => '（2023）辽0111执2618号',
                'out_judge_name' => '',
                'out_party_name' => '中国人民解放军联勤保障部队第四储备资产管理局沈阳管理站',
                'invoice_type' => '执行、调解款',
                'receipt_no' => '',
                'distribution_amount' => '1026444.20',
                'applicant_name' => '',
                'apply_date' => '2023-11-30',
                'receive_date' => '2023-11-30',
                'period' => '2023-11',
                'fiscal_year' => '2023',
                'business_status' => '正常可操作',
                'description' => '从【（2023）辽0111执2618号】转入',
                'original_invoice_date' => null,
                'source_fingerprint' => 'ef0ad6db271626fa1a229b14c83e27febb60835cc65b2247dbda8cc5e1de85d1',
            ],
        ];
    } elseif ($realSampleSha256 === $newSampleSha256) {
        $realProfileKey = 'sha256:' . $realSampleSha256;
        $realProfile = [
            'row_count' => 7,
            'total_cents' => 2770324,
            'first_fields' => [
                'source_row_no',
                'distribution_no',
                'in_case_no',
                'in_judge_name',
                'in_party_name',
                'out_case_no',
                'out_judge_name',
                'out_party_name',
                'invoice_type',
                'receipt_no',
                'distribution_amount',
                'applicant_name',
                'apply_date',
                'receive_date',
                'period',
                'fiscal_year',
                'business_status',
                'description',
                'original_invoice_date',
                'source_fingerprint',
            ],
            'first_sha256' => 'c3042aef7ead2b65be873ac136522e6ceafeb44d6f73330e7dbcf751eb3438b4',
        ];
    } else {
        $realProfileKey = (string)($realRows[0]['distribution_no'] ?? '');
        if (!isset($legacyProfiles[$realProfileKey])) {
            fwrite(STDERR, 'Unexpected distribution sample profile: sha256:' . $realSampleSha256 . PHP_EOL);
            exit(1);
        }
        $realProfile = $legacyProfiles[$realProfileKey];
    }
    distributionImportAssertProfile($realRows, $realProfile, $realProfileKey);
    if ($validateWithReceiptPolicyMethod->invoke(
        $caseFund,
        $realRows,
        'CASE_FUND',
        $realReceiptTraceRequired
    ) !== []) {
        fwrite(STDERR, 'Real CASE_FUND distribution rows should validate cleanly' . PHP_EOL);
        exit(1);
    }
}

$litigationErrors = $validateMethod->invoke($caseFund, $syntheticRows, 'LITIGATION_FEE');
if (empty($litigationErrors) || strpos($litigationErrors[0], '当前账套类型不支持案款分配') === false) {
    fwrite(STDERR, 'Distribution import should reject non-CASE_FUND account sets' . PHP_EOL);
    exit(1);
}

$badRows = $syntheticRows;
$badRows[0]['receipt_no'] = '';
$badRows[0]['distribution_amount'] = '0.00';
$badErrors = $validateMethod->invoke($caseFund, [$badRows[0]], 'CASE_FUND');
if ($badErrors !== [
    '第2行：单据号不能为空',
    '第2行：分配金额必须大于0',
]) {
    fwrite(STDERR, 'Distribution validation should reject missing receipt and zero amount' . PHP_EOL);
    exit(1);
}

$changedReceipt = $first;
$changedReceipt['receipt_no'] = 'DIFFERENT';
if ($fingerprintMethod->invoke($caseFund, $first) === $fingerprintMethod->invoke($caseFund, $changedReceipt)) {
    fwrite(STDERR, 'Distribution fingerprint should include receipt_no' . PHP_EOL);
    exit(1);
}
if (strlen($first['source_fingerprint']) !== 64) {
    fwrite(STDERR, 'Distribution fingerprint should be sha256 hex' . PHP_EOL);
    exit(1);
}

echo 'Case fund distribution import test passed' . PHP_EOL;
