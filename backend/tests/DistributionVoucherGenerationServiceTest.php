<?php

require_once __DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherPersistenceService.php';
require_once __DIR__ . '/../app/finance/service/casefund/voucher/DistributionVoucherGenerationService.php';

use app\finance\service\casefund\voucher\CaseFundVoucherPersistenceService;
use app\finance\service\casefund\voucher\DistributionVoucherGenerationService;

class DistributionVoucherResolverStub
{
    public function directConfigsByBusinessItems($bizType, $voucherBizType)
    {
        return [
            '执行、调解款' => [
                'debit_subject_code' => '224101',
                'credit_subject_code' => '224101',
            ],
        ];
    }
}

class DistributionVoucherManagerStub
{
    public function validateVoucherSubjectCode($subjectCode, $label) { return null; }
}

class DistributionVoucherRuleStub
{
    public $groupRows = [];

    public function groupDistributionRowsForVoucher($rows)
    {
        $this->groupRows = $rows;
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['distribution_no']][] = $row;
        }
        return $groups;
    }

    public function buildDistributionVoucherDetailsData($rows, $subjectConfigMap)
    {
        $details = [];
        $lineNo = 1;
        $total = 0;
        foreach ($rows as $row) {
            $cents = (int)round((float)$row['distribution_amount'] * 100);
            $total += $cents;
            $config = $subjectConfigMap[$row['invoice_type']];
            foreach ([
                ['subject' => $config['debit_subject_code'], 'debit' => number_format($cents / 100, 2, '.', ''), 'credit' => '0.00', 'case_no' => $row['out_case_no']],
                ['subject' => $config['credit_subject_code'], 'debit' => '0.00', 'credit' => number_format($cents / 100, 2, '.', ''), 'case_no' => $row['in_case_no']],
            ] as $side) {
                $details[] = [
                    'detail_id' => 'detail-' . $lineNo,
                    'line_no' => $lineNo++,
                    'subject_code' => $side['subject'],
                    'summary' => '分配摘要',
                    'debit_amount' => $side['debit'],
                    'credit_amount' => $side['credit'],
                    'verification_status' => 'NOT_REQUIRED',
                    'aux_desc' => 'case_no:' . $side['case_no'],
                    'aux_values' => [
                        ['aux_type_code' => 'case_no', 'aux_value' => $side['case_no'], 'aux_label' => $side['case_no']],
                        ['aux_type_code' => 'receipt_no', 'aux_value' => $row['receipt_no'], 'aux_label' => $row['receipt_no']],
                    ],
                ];
            }
        }
        return ['details' => $details, 'total_cents' => $total];
    }
}

class DistributionVoucherDbStub
{
    public $selectRows = [];
    public $lockValues = [];
    public $orders = [];
    public $inserts = [];
    public $updates = [];
    public $updateResult = 1;

    public function where($field, $operator = null, $value = null) { return $this; }
    public function field($fields) { return $this; }
    public function order($order) { $this->orders[] = $order; return $this; }
    public function lock($value) { $this->lockValues[] = $value; return $this; }
    public function select() { return $this->selectRows; }
    public function insert($row) { $this->inserts[] = $row; return 1; }
    public function update($row) { $this->updates[] = $row; return $this->updateResult; }
}

class DistributionFailingPersistenceStub
{
    public function persistVoucher(array $context, array $detailsData)
    {
        throw new \RuntimeException('distribution persistence failed');
    }
}

function distributionServiceAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$resolver = new DistributionVoucherResolverStub();
$manager = new DistributionVoucherManagerStub();
$rule = new DistributionVoucherRuleStub();
$dbs = [
    'fin_case_fund_distribution' => new DistributionVoucherDbStub(),
    'fin_voucher' => new DistributionVoucherDbStub(),
    'fin_voucher_detail' => new DistributionVoucherDbStub(),
    'fin_voucher_aux_value' => new DistributionVoucherDbStub(),
];
$dbs['fin_case_fund_distribution']->selectRows = [
    [
        'distribution_id' => 'd1',
        'distribution_no' => 'D-001',
        'receive_date' => '2026-07-14',
        'period' => '2026-07',
        'invoice_type' => '执行、调解款',
        'out_case_no' => '出案一',
        'in_case_no' => '入案一',
        'receipt_no' => 'R1',
        'distribution_amount' => '10.00',
        'voucher_status' => 'UNGENERATED',
    ],
    [
        'distribution_id' => 'd2',
        'distribution_no' => 'D-001',
        'receive_date' => '2026-07-14',
        'period' => '2026-07',
        'invoice_type' => '执行、调解款',
        'out_case_no' => '出案二',
        'in_case_no' => '入案二',
        'receipt_no' => 'R2',
        'distribution_amount' => '20.00',
        'voucher_status' => 'UNGENERATED',
    ],
];
$events = [];
$ensuredDetailCount = 0;
$idSequence = 0;
$hooks = [
    'get_db' => function ($table) use (&$dbs) { return $dbs[$table]; },
    'account_set_id' => function () { return 'set-1'; },
    'current_year' => function () { return '2026'; },
    'current_biz_type' => function () { return 'CASE_FUND'; },
    'normalize_id_list' => function ($value) {
        if (is_string($value)) $value = explode(',', $value);
        $ids = [];
        foreach ((array)$value as $item) {
            $id = trim((string)$item);
            if ($id !== '') $ids[$id] = true;
        }
        return array_keys($ids);
    },
    'assert_rows_can_generate' => function ($rows, $label) { return null; },
    'validate_period' => function ($row, $period) use (&$events) { $events[] = 'period:' . $row['distribution_id']; return null; },
    'build_header_summary' => function ($row) { return '分配 ' . $row['distribution_no'] . ' 合计'; },
    'ensure_aux_archives' => function ($details) use (&$ensuredDetailCount, &$events) {
        $events[] = 'aux_archives';
        $ensuredDetailCount = count($details);
    },
    'fiscal_year' => function ($period) { return substr($period, 0, 4); },
    'generate_next_voucher_no' => function ($period) use (&$events) { $events[] = 'voucher_no'; return 8; },
    'cents_to_decimal' => function ($cents) { return number_format($cents / 100, 2, '.', ''); },
    'new_id' => function () use (&$idSequence) { return 'id-' . (++$idSequence); },
    'prepare_create' => function ($row) { return $row; },
    'current_user_id' => function () { return 'user-1'; },
    'now' => function () { return '2026-07-14 12:00:00'; },
];
$persistence = new CaseFundVoucherPersistenceService($hooks);

try {
    new DistributionVoucherGenerationService($resolver, $manager, $rule, $persistence, [], 'distribution', []);
    fwrite(STDERR, 'Distribution voucher service should reject incomplete hooks' . PHP_EOL);
    exit(1);
} catch (\InvalidArgumentException $e) {
    distributionServiceAssertSame('案款分配凭证生成服务缺少回调：get_db', $e->getMessage(), 'Missing hook should be explicit');
}

$service = new DistributionVoucherGenerationService(
    $resolver,
    $manager,
    $rule,
    $persistence,
    $hooks,
    'fin_case_fund_distribution',
    ['distribution_id']
);
$prepared = $service->prepareGeneration('d1,d1,d2');
distributionServiceAssertSame(['d1', 'd2'], $prepared['distribution_ids'], 'Distribution ids should be normalized and deduplicated before transaction');
$result = $service->generatePrepared($prepared);
distributionServiceAssertSame([true], $dbs['fin_case_fund_distribution']->lockValues, 'Distribution source rows should be locked inside generation');
distributionServiceAssertSame(['receive_date asc, distribution_no asc, source_row_no asc'], $dbs['fin_case_fund_distribution']->orders, 'Distribution source order should remain stable');
distributionServiceAssertSame('出案一', $rule->groupRows[0]['case_no'], 'Transfer-out case number should feed common regeneration errors');
distributionServiceAssertSame(['period:d1', 'period:d2', 'voucher_no', 'aux_archives'], $events, 'Period validation and voucher number allocation should finish before complete auxiliary preparation');
distributionServiceAssertSame(1, $result['generated_count'], 'One distribution number should create one voucher');
distributionServiceAssertSame(2, $result['distribution_count'], 'Result should report locked source row count');
distributionServiceAssertSame(4, $ensuredDetailCount, 'Aux archives should be prepared from final voucher details');
distributionServiceAssertSame(1, count($dbs['fin_voucher']->inserts), 'Distribution generation should insert one voucher header');
distributionServiceAssertSame('分配 D-001 合计', $dbs['fin_voucher']->inserts[0]['summary'], 'Distribution persistence should keep group summary');
distributionServiceAssertSame('30.00', $dbs['fin_voucher']->inserts[0]['debit_amount'], 'Distribution persistence should keep group total');
distributionServiceAssertSame(4, count($dbs['fin_voucher_detail']->inserts), 'Distribution generation should insert balanced debit-credit details');
distributionServiceAssertSame(8, count($dbs['fin_voucher_aux_value']->inserts), 'Distribution generation should persist configured case and receipt aux values');
distributionServiceAssertSame(2, count($dbs['fin_case_fund_distribution']->updates), 'Every distribution row should be marked generated');

$dbs['fin_case_fund_distribution']->updates = [];
$failedPersistenceService = new DistributionVoucherGenerationService(
    $resolver,
    $manager,
    $rule,
    new DistributionFailingPersistenceStub(),
    $hooks,
    'fin_case_fund_distribution',
    ['distribution_id']
);
$failedPersistencePrepared = $failedPersistenceService->prepareGeneration('d1,d2');
try {
    $failedPersistenceService->generatePrepared($failedPersistencePrepared);
    fwrite(STDERR, 'Distribution persistence errors should propagate' . PHP_EOL);
    exit(1);
} catch (\RuntimeException $e) {
    distributionServiceAssertSame('distribution persistence failed', $e->getMessage(), 'Distribution persistence error should be unchanged');
}
distributionServiceAssertSame(
    0,
    count($dbs['fin_case_fund_distribution']->updates),
    'Distribution source rows should only update after voucher persistence succeeds'
);

$source = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/DistributionVoucherGenerationService.php');
foreach (['requirePermission', 'Db::startTrans', 'Db::commit', 'Db::rollback', 'logAudit'] as $forbidden) {
    if (strpos($source, $forbidden) !== false) {
        fwrite(STDERR, 'Distribution voucher service should not own boundary concern: ' . $forbidden . PHP_EOL);
        exit(1);
    }
}

echo 'Distribution voucher generation service test passed' . PHP_EOL;
