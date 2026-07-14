<?php

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$source = file_get_contents($modelPath);
$voucherRuleSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherRule.php');
$refundServiceSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/RefundVoucherGenerationService.php');
$persistenceSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherPersistenceService.php');
$auxiliarySource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherAuxiliaryService.php');
$implementationSource = $source . "\n" . $voucherRuleSource . "\n" . $refundServiceSource . "\n" . $persistenceSource . "\n" . $auxiliarySource;
$ddl = file_get_contents(__DIR__ . '/../database/court_finance_ddl.sql');
$patch = file_get_contents(__DIR__ . '/../database/finance_standard_patch.sql');

$checks = [
    ["case 'refundGenerateVoucher'", 'CaseFund should route refundGenerateVoucher'],
    ['public function refundGenerateVoucher', 'CaseFund should expose refundGenerateVoucher method'],
    ["configsByBusinessItems(\$bizType, 'REFUND')", 'Refund voucher generation should use REFUND subject configs'],
    ["self::TABLE_REFUND", 'Refund voucher generation should read refund table'],
    ["'refund_ids'", 'Refund voucher generation should accept refund_ids'],
    ["'refund_date'", 'Refund voucher generation should group by refund date'],
    ["'refund_amount'", 'Refund voucher generation should use refund amount'],
    ["'out_type'", 'Refund voucher generation should use out_type subject config'],
    ["'refund_count'", 'Refund voucher generation should return refund count'],
    ["'CASE_FUND_REFUND'", 'Refund voucher generation should write refund audit log'],
    ["requirePermission('case_fund:generate_voucher')", 'Refund voucher generation should check generate_voucher permission'],
    ["'UNGENERATED'", 'Refund voucher generation should reject generated rows'],
    ["'GENERATED'", 'Refund voucher generation should mark generated rows'],
    ['ensureArchivesForVoucherDetails', 'Refund voucher generation should ensure case and receipt aux archives'],
    ['fin_voucher', 'Refund voucher generation should write voucher table'],
    ['fin_voucher_detail', 'Refund voucher generation should write voucher detail table'],
    ['fin_voucher_aux_value', 'Refund voucher generation should write voucher aux values'],
    ['persistVoucher', 'Refund voucher generation should use common voucher persistence'],
    ['generateRefundVoucherByOutOrderNoFlag', 'Refund voucher generation should read out-order grouping config'],
    ['groupRefundRowsForVoucher', 'Refund voucher generation should group rows through a dedicated helper'],
    ['assertRowsCanGenerateVoucher', 'Refund voucher generation should validate whether selected generated rows can be regenerated'],
    ['hasActiveGeneratedVoucher', 'Refund voucher generation should block duplicate generation when the original voucher is still active'],
    ["'GENERATED' &&", 'Refund voucher generation should inspect generated rows before allowing regeneration'],
    ["'out_order_no'", 'Refund voucher generation should use out_order_no for grouping'],
    ['buildRefundVoucherDetailsData', 'Refund voucher generation should build ordered debit and credit details through a dedicated helper'],
    ['$outOrderCounts[$outOrderNo] ?? 0) > 1', 'Refund voucher generation should only aggregate repeated non-empty out_order_no credit lines'],
    ['buildRefundAggregateCreditDetail', 'Refund voucher generation should build one credit line for an out-order group'],
];

foreach ($checks as [$needle, $message]) {
    if (strpos($implementationSource, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

foreach (["db('fin_voucher')", "db('fin_voucher_detail')", "db('fin_voucher_aux_value')"] as $directWrite) {
    if (strpos($refundServiceSource, $directWrite) !== false) {
        fwrite(STDERR, 'Refund generation service should not directly write common voucher tables: ' . $directWrite . PHP_EOL);
        exit(1);
    }
}

if (strpos($source, '缺少出账单号，不能按出账单号汇总生成凭证') !== false) {
    fwrite(STDERR, 'Empty out_order_no should be processed as a single refund voucher instead of failing' . PHP_EOL);
    exit(1);
}

$schemaChecks = [
    [$ddl, 'generate_refund_voucher_by_out_order_no_flag tinyint not null default 0', 'DDL should add refund out-order grouping flag'],
    [$patch, 'generate_refund_voucher_by_out_order_no_flag', 'Patch should add refund out-order grouping flag idempotently'],
];

foreach ($schemaChecks as [$haystack, $needle, $message]) {
    if (strpos($haystack, $needle) === false) {
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

if (!function_exists('uuid')) {
    function uuid()
    {
        return '00000000-0000-4000-8000-000000000000';
    }
}

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\model\CaseFund;

class CaseFundRefundGroupingProbe extends CaseFund
{
    public function groupRefundRows($rows, $byDayFlag, $byOutOrderNoFlag)
    {
        return $this->voucherRule()->groupRefundRowsForVoucher($rows, $byDayFlag, $byOutOrderNoFlag);
    }
}

$caseFund = new CaseFundRefundGroupingProbe();
$sampleRows = [
    ['refund_id' => 'refund-1', 'refund_date' => '2026-03-02', 'out_order_no' => 'OUT-001', 'case_no' => '案一'],
    ['refund_id' => 'refund-2', 'refund_date' => '2026-03-02', 'out_order_no' => 'OUT-001', 'case_no' => '案二'],
    ['refund_id' => 'refund-3', 'refund_date' => '2026-03-02', 'out_order_no' => '', 'case_no' => '案三'],
    ['refund_id' => 'refund-4', 'refund_date' => '2026-03-03', 'out_order_no' => 'OUT-001', 'case_no' => '案四'],
];

$singleGroups = $caseFund->groupRefundRows($sampleRows, 0, 1);
if (count($singleGroups) !== 4) {
    fwrite(STDERR, 'Refund out-order grouping should require daily voucher generation; expected 4 single groups when by-day is off' . PHP_EOL);
    exit(1);
}
foreach ($singleGroups as $groupRows) {
    if (count($groupRows) !== 1) {
        fwrite(STDERR, 'Refund out-order grouping should not merge rows when by-day is off' . PHP_EOL);
        exit(1);
    }
}

$outOrderGroups = $caseFund->groupRefundRows($sampleRows, 1, 1);
$groupSizes = array_map('count', $outOrderGroups);
sort($groupSizes);
if ($groupSizes !== [1, 3] || count($outOrderGroups['2026-03-02'] ?? []) !== 3 || count($outOrderGroups['2026-03-03'] ?? []) !== 1) {
    fwrite(STDERR, 'Daily refund generation should keep one voucher group per day; out_order_no only aggregates credit lines inside that daily voucher' . PHP_EOL);
    exit(1);
}

class CaseFundRefundDetailProbe extends CaseFund
{
    protected function getSubjectAuxConfigs($subjectCode)
    {
        return [];
    }

    public function buildRefundDetails($rows, $subjectConfigMap, $aggregateByOutOrderNo)
    {
        return $this->voucherRule()->buildRefundVoucherDetailsData(
            $rows,
            $subjectConfigMap,
            $aggregateByOutOrderNo
        );
    }

    public function refundVoucherHeaderSummary($row, $aggregateByOutOrderNo, $voucherDate)
    {
        return $this->voucherRule()->buildRefundVoucherHeaderSummary($row, $aggregateByOutOrderNo, $voucherDate);
    }
}

$detailProbe = new CaseFundRefundDetailProbe();
$subjectConfigMap = [
    '执行款退付' => [
        'debit_subject_code' => '224101',
        'credit_subject_code' => '100201',
    ],
];
$detailResult = $detailProbe->buildRefundDetails([
    [
        'refund_id' => 'refund-a1',
        'case_no' => '案一',
        'party_name' => '张三',
        'actual_payee_name' => '',
        'out_type' => '执行款退付',
        'out_order_no' => 'OUT-001',
        'refund_amount' => '10.00',
        'receipt_no' => 'R001',
        'source_receipt_no' => '',
    ],
    [
        'refund_id' => 'refund-a2',
        'case_no' => '案二',
        'party_name' => '李四',
        'actual_payee_name' => '',
        'out_type' => '执行款退付',
        'out_order_no' => 'OUT-001',
        'refund_amount' => '20.00',
        'receipt_no' => 'R002',
        'source_receipt_no' => '',
    ],
    [
        'refund_id' => 'refund-b1',
        'case_no' => '案三',
        'party_name' => '王五',
        'actual_payee_name' => '',
        'out_type' => '执行款退付',
        'out_order_no' => 'OUT-002',
        'refund_amount' => '30.00',
        'receipt_no' => 'R003',
        'source_receipt_no' => '',
    ],
], $subjectConfigMap, true);
$detailSummaries = array_map(function ($detail) {
    return [
        'summary' => $detail['summary'],
        'debit' => $detail['debit_amount'],
        'credit' => $detail['credit_amount'],
    ];
}, $detailResult['details']);
$expectedDetails = [
    ['summary' => '付 案一 张三', 'debit' => '10.00', 'credit' => '0.00'],
    ['summary' => '付 案二 李四', 'debit' => '20.00', 'credit' => '0.00'],
    ['summary' => '付 出账单号OUT-001 合计', 'debit' => '0.00', 'credit' => '30.00'],
    ['summary' => '付 案三 王五', 'debit' => '30.00', 'credit' => '0.00'],
    ['summary' => '付 案三 王五', 'debit' => '0.00', 'credit' => '30.00'],
];
if ($detailSummaries !== $expectedDetails) {
    fwrite(STDERR, 'Refund voucher details should keep repeated out_order_no as debit...credit group and unique out_order_no as debit-credit pair' . PHP_EOL);
    fwrite(STDERR, var_export($detailSummaries, true) . PHP_EOL);
    exit(1);
}
if ($detailResult['total_cents'] !== 6000) {
    fwrite(STDERR, 'Refund voucher detail helper should return the total debit amount in cents' . PHP_EOL);
    exit(1);
}

$normalDetailResult = $detailProbe->buildRefundDetails([
    [
        'refund_id' => 'refund-normal-1',
        'case_no' => '案四',
        'party_name' => '',
        'actual_payee_name' => '赵六',
        'out_type' => '执行款退付',
        'out_order_no' => 'OUT-003',
        'refund_amount' => '40.00',
        'receipt_no' => 'R004',
        'source_receipt_no' => '',
    ],
], $subjectConfigMap, false);
$normalSummaries = array_map(function ($detail) {
    return $detail['summary'];
}, $normalDetailResult['details']);
if ($normalSummaries !== ['付 案四 赵六', '付 案四 赵六']) {
    fwrite(STDERR, 'Normal refund voucher details should be 付 + case_no + party/payee' . PHP_EOL);
    fwrite(STDERR, var_export($normalSummaries, true) . PHP_EOL);
    exit(1);
}
if ($detailProbe->refundVoucherHeaderSummary(['case_no' => '案五', 'party_name' => '钱七', 'actual_payee_name' => ''], false, '2026-03-05') !== '付 案五 钱七') {
    fwrite(STDERR, 'Normal refund voucher header summary should be 付 + case_no + party/payee' . PHP_EOL);
    exit(1);
}
if ($detailProbe->refundVoucherHeaderSummary(['case_no' => '案五', 'party_name' => '钱七', 'actual_payee_name' => ''], true, '2026-03-05') !== '2026-03-05 付合计') {
    fwrite(STDERR, 'Aggregated refund voucher header summary should be date + 付合计' . PHP_EOL);
    exit(1);
}

$interleavedRows = [];
foreach ([
    ['refund-a1', '案A1', 'A'],
    ['refund-b1', '案B1', 'B'],
    ['refund-a2', '案A2', 'A'],
    ['refund-empty', '案空', ''],
    ['refund-c1', '案C1', 'C'],
] as $item) {
    $interleavedRows[] = [
        'refund_id' => $item[0],
        'case_no' => $item[1],
        'party_name' => '当事人',
        'actual_payee_name' => '',
        'out_type' => '执行款退付',
        'out_order_no' => $item[2],
        'refund_amount' => '1.00',
        'receipt_no' => '',
        'source_receipt_no' => '',
    ];
}
$interleavedResult = $detailProbe->buildRefundDetails($interleavedRows, $subjectConfigMap, true);
$interleavedShape = array_map(function ($detail) {
    return [$detail['summary'], $detail['debit_amount'], $detail['credit_amount']];
}, $interleavedResult['details']);
$expectedInterleavedShape = [
    ['付 案A1 当事人', '1.00', '0.00'],
    ['付 案A2 当事人', '1.00', '0.00'],
    ['付 出账单号A 合计', '0.00', '2.00'],
    ['付 案B1 当事人', '1.00', '0.00'],
    ['付 案B1 当事人', '0.00', '1.00'],
    ['付 案空 当事人', '1.00', '0.00'],
    ['付 案空 当事人', '0.00', '1.00'],
    ['付 案C1 当事人', '1.00', '0.00'],
    ['付 案C1 当事人', '0.00', '1.00'],
];
if ($interleavedShape !== $expectedInterleavedShape) {
    fwrite(STDERR, 'Refund interleaved out-order groups should follow first occurrence and keep each debit-credit bucket adjacent' . PHP_EOL);
    fwrite(STDERR, var_export($interleavedShape, true) . PHP_EOL);
    exit(1);
}

echo 'Case fund refund generate voucher test passed' . PHP_EOL;
