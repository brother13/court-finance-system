<?php

require_once __DIR__ . '/../app/finance/service/casefund/policy/CaseFundBusinessPolicy.php';
require_once __DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherPersistenceService.php';
require_once __DIR__ . '/../app/finance/service/casefund/voucher/RefundVoucherGenerationService.php';

use app\finance\service\casefund\policy\CaseFundBusinessPolicy;
use app\finance\service\casefund\voucher\CaseFundVoucherPersistenceService;
use app\finance\service\casefund\voucher\RefundVoucherGenerationService;

class RefundVoucherResolverStub
{
    public function configsByBusinessItems($bizType, $voucherBizType)
    {
        return [
            '出账查询' => [
                'debit_subject_code' => '224101',
                'credit_subject_code' => '100201',
            ],
        ];
    }
}

class RefundVoucherManagerStub
{
    public $skipConfirmedReturnFlag = 0;

    public function subjectConfigAccountSetFlag() { return 1; }
    public function generateRefundVoucherByOutOrderNoFlag() { return 1; }
    public function skipConfirmedReturnVoucherFlag() { return $this->skipConfirmedReturnFlag; }
    public function validateVoucherSubjectCode($subjectCode, $label) { return null; }
}

class RefundVoucherRuleStub
{
    public $receivedFlags = [];
    public $groupRowsHistory = [];

    public function groupRefundRowsForVoucher($rows, $byDayFlag, $byOutOrderNoFlag)
    {
        $this->receivedFlags = [$byDayFlag, $byOutOrderNoFlag];
        $this->groupRowsHistory[] = $rows;
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['refund_date']][] = $row;
        }
        return $groups;
    }

    public function buildRefundVoucherDetailsData($rows, $subjectConfigMap, $aggregateByOutOrderNo, $bizType)
    {
        $total = 0;
        foreach ($rows as $row) {
            $total += $this->amountToCents($row['refund_amount']);
        }
        return [
            'total_cents' => $total,
            'details' => [
                [
                    'detail_id' => 'refund-detail-1',
                    'line_no' => 1,
                    'subject_code' => '224101',
                    'summary' => '退案款',
                    'debit_amount' => number_format($total / 100, 2, '.', ''),
                    'credit_amount' => '0.00',
                    'verification_status' => 'NOT_REQUIRED',
                    'aux_desc' => '',
                    'aux_values' => [],
                ],
                [
                    'detail_id' => 'refund-detail-2',
                    'line_no' => 2,
                    'subject_code' => '100201',
                    'summary' => '退案款',
                    'debit_amount' => '0.00',
                    'credit_amount' => number_format($total / 100, 2, '.', ''),
                    'verification_status' => 'NOT_REQUIRED',
                    'aux_desc' => '',
                    'aux_values' => [],
                ],
            ],
        ];
    }

    public function buildRefundVoucherHeaderSummary($row, $aggregateByOutOrderNo, $voucherDate)
    {
        return '退案款';
    }

    protected function amountToCents($amount)
    {
        $parts = explode('.', trim((string)$amount), 2);
        $yuan = (int)$parts[0];
        $fraction = str_pad($parts[1] ?? '', 2, '0');
        return $yuan * 100 + (int)substr($fraction, 0, 2);
    }
}

class RefundVoucherDbStub
{
    public $selectRows = [];
    public $lockValues = [];
    public $orders = [];
    public $limits = [];
    public $whereCalls = [];
    public $inserts = [];
    public $updates = [];
    public $updateResults = [];

    public function where($field, $operator = null, $value = null)
    {
        $this->whereCalls[] = func_get_args();
        return $this;
    }

    public function field($fields) { return $this; }
    public function order($order) { $this->orders[] = $order; return $this; }
    public function limit($limit) { $this->limits[] = $limit; return $this; }
    public function lock($value) { $this->lockValues[] = $value; return $this; }
    public function select() { return $this->selectRows; }
    public function insert($row) { $this->inserts[] = $row; return 1; }

    public function update($row)
    {
        $this->updates[] = $row;
        if (!empty($this->updateResults)) {
            return array_shift($this->updateResults);
        }
        foreach (array_reverse($this->whereCalls) as $whereCall) {
            if (($whereCall[1] ?? null) === 'in' && is_array($whereCall[2] ?? null)) {
                return count($whereCall[2]);
            }
        }
        return 1;
    }
}

class RefundVoucherRuntimeState
{
    public $guardSnapshots = [];
    public $voucherNoCalls = 0;
    public $idSequence = 0;
    public $lockEvents = [];
    public $auxiliaryDetailCounts = [];
    public $periodChecks = [];
}

class RefundFailingPersistenceStub
{
    public function persistVoucher(array $context, array $detailsData)
    {
        throw new \RuntimeException('refund persistence failed');
    }
}

function refundServiceAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function refundServiceAssertThrows($expectedMessage, $callback, $message)
{
    try {
        $callback();
    } catch (\Exception $e) {
        refundServiceAssertSame($expectedMessage, $e->getMessage(), $message);
        return;
    }
    fwrite(STDERR, $message . '; expected exception was not thrown' . PHP_EOL);
    exit(1);
}

function refundRuntimeFixture(
    $lockedRows,
    $blockLockedGenerated = false,
    $updateResults = [],
    $failPersistence = false,
    $skipConfirmedReturnFlag = false,
    $confirmedRefundIds = []
)
{
    $policy = new CaseFundBusinessPolicy();
    $resolver = new RefundVoucherResolverStub();
    $manager = new RefundVoucherManagerStub();
    $manager->skipConfirmedReturnFlag = $skipConfirmedReturnFlag ? 1 : 0;
    $rule = new RefundVoucherRuleStub();
    $state = new RefundVoucherRuntimeState();
    $dbs = [
        'fin_case_fund_refund' => new RefundVoucherDbStub(),
        'fin_case_fund_unknown_refund_match' => new RefundVoucherDbStub(),
        'fin_voucher' => new RefundVoucherDbStub(),
        'fin_voucher_detail' => new RefundVoucherDbStub(),
        'fin_voucher_aux_value' => new RefundVoucherDbStub(),
    ];
    $dbs['fin_case_fund_refund']->selectRows = $lockedRows;
    $dbs['fin_case_fund_refund']->updateResults = $updateResults;
    $dbs['fin_case_fund_unknown_refund_match']->selectRows = array_map(function ($refundId) {
        return ['refund_id' => $refundId, 'match_status' => 'CONFIRMED'];
    }, $confirmedRefundIds);
    $hooks = [
        'get_db' => function ($table) use (&$dbs) { return $dbs[$table]; },
        'account_where' => function () { return ['account_set_id' => 'set-1', 'del_flag' => 0]; },
        'account_set_id' => function () { return 'set-1'; },
        'current_year' => function () { return '2026'; },
        'current_biz_type' => function () { return 'CASE_FUND'; },
        'normalize_date_filter' => function ($value) { return trim((string)$value); },
        'assert_rows_can_generate' => function ($rows, $label) use ($state, $blockLockedGenerated) {
            $state->guardSnapshots[] = $rows;
            if ($blockLockedGenerated && count($state->guardSnapshots) > 1) {
                return '案号【案二】的退付记录已生成凭证且原凭证仍有效，不允许重复生成';
            }
            return null;
        },
        'assert_periods_open' => function ($rows, $dateField, $label) use ($state) {
            $state->periodChecks[] = [$dateField, $label, count($rows)];
        },
        'fiscal_year' => function ($period) { return substr($period, 0, 4); },
        'generate_next_voucher_no' => function ($period) use ($state) {
            $state->lockEvents[] = 'voucher_no:' . $period;
            $state->voucherNoCalls++;
            return $state->voucherNoCalls;
        },
        'ensure_aux_archives' => function ($details) use ($state) {
            $state->lockEvents[] = 'aux_archives';
            $state->auxiliaryDetailCounts[] = count($details);
        },
        'cents_to_decimal' => function ($cents) { return number_format($cents / 100, 2, '.', ''); },
        'new_id' => function () use ($state) { return 'refund-id-' . (++$state->idSequence); },
        'prepare_create' => function ($row) { return $row; },
        'current_user_id' => function () { return 'user-1'; },
        'now' => function () { return '2026-07-14 12:00:00'; },
    ];
    $persistence = $failPersistence
        ? new RefundFailingPersistenceStub()
        : new CaseFundVoucherPersistenceService($hooks);
    $service = new RefundVoucherGenerationService(
        $policy,
        $resolver,
        $manager,
        $rule,
        $persistence,
        $hooks,
        'fin_case_fund_refund',
        ['refund_id', 'refund_date', 'refund_amount', 'out_order_no', 'out_type', 'case_no', 'payee_bank_account_name', 'voucher_status', 'voucher_id']
    );
    return [$service, $dbs, $rule, $state];
}

$policy = new CaseFundBusinessPolicy();
$resolver = new RefundVoucherResolverStub();
$manager = new RefundVoucherManagerStub();
$rule = new RefundVoucherRuleStub();
$hooks = [
    'get_db' => function ($table) { throw new \RuntimeException('This unit test should not query database'); },
    'account_where' => function () { return ['account_set_id' => 'set-1', 'del_flag' => 0]; },
    'account_set_id' => function () { return 'set-1'; },
    'current_year' => function () { return '2026'; },
    'current_biz_type' => function () { return 'CASE_FUND'; },
    'normalize_date_filter' => function ($value) { return trim((string)$value); },
    'assert_rows_can_generate' => function ($rows, $label) { return null; },
    'assert_periods_open' => function ($rows, $dateField, $label) {},
    'fiscal_year' => function ($period) { return substr($period, 0, 4); },
    'generate_next_voucher_no' => function ($period) { return 1; },
    'ensure_aux_archives' => function ($details) {},
    'cents_to_decimal' => function ($cents) { return number_format($cents / 100, 2, '.', ''); },
    'new_id' => function () { return 'test-id'; },
    'prepare_create' => function ($row) { return $row; },
    'current_user_id' => function () { return 'user-1'; },
    'now' => function () { return '2026-07-14 12:00:00'; },
];
$persistence = new CaseFundVoucherPersistenceService($hooks);

try {
    new RefundVoucherGenerationService($policy, $resolver, $manager, $rule, $persistence, [], 'refund', []);
    fwrite(STDERR, 'Refund voucher service should reject incomplete hooks' . PHP_EOL);
    exit(1);
} catch (\InvalidArgumentException $e) {
    refundServiceAssertSame('退付凭证生成服务缺少回调：get_db', $e->getMessage(), 'Missing hook should be explicit');
}

$service = new RefundVoucherGenerationService(
    $policy,
    $resolver,
    $manager,
    $rule,
    $persistence,
    $hooks,
    'fin_case_fund_refund',
    ['refund_id']
);
$rows = [
    [
        'refund_id' => 'refund-1',
        'refund_date' => '2026-07-14',
        'out_order_no' => 'OUT-1',
        'out_type' => '执行、调解款发放',
        'case_no' => '案一',
        'payee_bank_account_name' => '银行户名甲',
        'voucher_status' => 'UNGENERATED',
    ],
    [
        'refund_id' => 'refund-2',
        'refund_date' => '2026-07-14',
        'out_order_no' => 'OUT-1',
        'out_type' => '执行、调解款发放',
        'case_no' => '案二',
        'payee_bank_account_name' => '银行户名乙',
        'voucher_status' => 'UNGENERATED',
    ],
];
$prepared = $service->prepareGeneration($rows, 'QUERY');
refundServiceAssertSame('', $prepared['error'], 'Valid refund rows should be prepared');
refundServiceAssertSame([1, 1], $rule->receivedFlags, 'Refund grouping flags should be delegated to voucher rule');
refundServiceAssertSame(['2026-07-14'], array_keys($prepared['groups']), 'Daily refund groups should preserve source order');
refundServiceAssertSame('QUERY', $prepared['generate_scope'], 'Generation scope should survive preparation');

$fallbackPayeeRows = $rows;
$fallbackPayeeRows[0]['payee_bank_account_name'] = '';
$fallbackPayeeRows[0]['actual_payee_name'] = '实际收款人甲';
$fallbackPayeePrepared = $service->prepareGeneration($fallbackPayeeRows, 'SELECTED');
refundServiceAssertSame(
    '',
    $fallbackPayeePrepared['error'],
    'Refund voucher generation should accept actual payee name when bank account name is blank'
);

$missingBankNameRows = $rows;
$missingBankNameRows[0]['payee_bank_account_name'] = '';
$missingBankNamePrepared = $service->prepareGeneration($missingBankNameRows, 'SELECTED');
refundServiceAssertSame(
    '案号【案一】收款人银行户名和实际收款人不能同时为空，不能生成凭证',
    $missingBankNamePrepared['error'],
    'Refund voucher generation should reject rows with neither bank account name nor actual payee name'
);

$initialRows = [
    [
        'refund_id' => 'refund-1',
        'refund_date' => '2026-07-14',
        'refund_amount' => '10.00',
        'out_order_no' => 'OUT-1',
        'out_type' => '执行、调解款发放',
        'case_no' => '案一',
        'payee_bank_account_name' => '银行户名甲',
        'voucher_status' => 'UNGENERATED',
    ],
    [
        'refund_id' => 'refund-2',
        'refund_date' => '2026-07-14',
        'refund_amount' => '20.00',
        'out_order_no' => 'OUT-1',
        'out_type' => '执行、调解款发放',
        'case_no' => '案二',
        'payee_bank_account_name' => '银行户名乙',
        'voucher_status' => 'UNGENERATED',
    ],
];

[$skipService, $skipDbs] = refundRuntimeFixture(
    $initialRows,
    false,
    [],
    false,
    true,
    ['refund-1']
);
$skipSelected = $skipService->selectedRows(['refund-1', 'refund-2']);
refundServiceAssertSame('', $skipSelected['error'], 'Mixed selected rows should still generate eligible refunds');
refundServiceAssertSame(
    ['refund-2'],
    array_column($skipSelected['rows'], 'refund_id'),
    'Selected generation should exclude confirmed returns when the config is enabled'
);
[$skipOnlyService] = refundRuntimeFixture(
    [$initialRows[0]],
    false,
    [],
    false,
    true,
    ['refund-1']
);
$skipOnlyConfirmed = $skipOnlyService->selectedRows(['refund-1']);
refundServiceAssertSame(
    '所选出账记录均为已确认退汇，当前科目配置不生成凭证',
    $skipOnlyConfirmed['error'],
    'Selected generation should explain when every selected refund is excluded'
);
$skipQuery = $skipService->rowsByQuery(['period' => '2026-07']);
refundServiceAssertSame('', $skipQuery['error'], 'Query generation should retain eligible refunds');
$confirmedNotInFound = false;
foreach ($skipDbs['fin_case_fund_refund']->whereCalls as $whereCall) {
    if (($whereCall[0] ?? '') === 'refund_id' && ($whereCall[1] ?? '') === 'not in') {
        $confirmedNotInFound = ($whereCall[2] ?? []) === ['refund-1'];
    }
}
refundServiceAssertSame(
    true,
    $confirmedNotInFound,
    'Query generation should exclude confirmed return ids before applying its safety limit'
);
$skipPrepared = $skipService->prepareGeneration([$initialRows[0]], 'SELECTED');
refundServiceAssertSame(
    '案号【案一】的出账记录已确认退汇，当前科目配置不生成凭证',
    $skipPrepared['error'],
    'Preparation should reject a confirmed return supplied outside the normal selection path'
);

[$returnRaceService, $returnRaceDbs, $returnRaceRule, $returnRaceState] = refundRuntimeFixture(
    [$initialRows[0]],
    false,
    [],
    false,
    true,
    []
);
$returnRacePrepared = $returnRaceService->prepareGeneration([$initialRows[0]], 'SELECTED');
refundServiceAssertSame('', $returnRacePrepared['error'], 'Unconfirmed refund should prepare normally');
$returnRaceDbs['fin_case_fund_unknown_refund_match']->selectRows = [[
    'refund_id' => 'refund-1',
    'match_status' => 'CONFIRMED',
]];
refundServiceAssertThrows(
    '案号【案一】的出账记录已确认退汇，当前科目配置不生成凭证',
    function () use ($returnRaceService, $returnRacePrepared) {
        $returnRaceService->generatePrepared($returnRacePrepared);
    },
    'A return confirmed after preparation should be blocked inside the generation transaction'
);
refundServiceAssertSame(
    [true],
    $returnRaceDbs['fin_case_fund_unknown_refund_match']->lockValues,
    'Transactional generation should lock return-match rows before creating vouchers'
);
refundServiceAssertSame(0, $returnRaceState->voucherNoCalls, 'Confirmed return race should fail before voucher number allocation');

$lockedRows = $initialRows;
$lockedRows[1]['voucher_status'] = 'GENERATED';
$lockedRows[1]['voucher_id'] = 'deleted-voucher';
[$runtimeService, $runtimeDbs, $runtimeRule, $runtimeState] = refundRuntimeFixture($lockedRows);
$runtimePrepared = $runtimeService->prepareGeneration($initialRows, 'QUERY');
$runtimeResult = $runtimeService->generatePrepared($runtimePrepared);
refundServiceAssertSame(
    ['voucher_no:2026-07', 'aux_archives'],
    $runtimeState->lockEvents,
    'Refund voucher numbers must lock before complete auxiliary preparation'
);
refundServiceAssertSame([2], $runtimeState->auxiliaryDetailCounts, 'All refund voucher groups should prepare auxiliary archives in one call');
refundServiceAssertSame([true], $runtimeDbs['fin_case_fund_refund']->lockValues, 'Refund source rows should be locked inside generation');
refundServiceAssertSame(
    [['refund_date', '退付', 2]],
    $runtimeState->periodChecks,
    'Refund periods must be rechecked after locking source rows'
);
refundServiceAssertSame('GENERATED', $runtimeState->guardSnapshots[1][1]['voucher_status'], 'Locked refund status must be rechecked');
refundServiceAssertSame($lockedRows, $runtimeRule->groupRowsHistory[1], 'Refund groups must be rebuilt from locked rows');
refundServiceAssertSame(1, $runtimeResult['generated_count'], 'Daily refund rows should create one voucher from locked rows');
refundServiceAssertSame(2, $runtimeResult['refund_count'], 'Refund count should come from locked rows');
refundServiceAssertSame(1, count($runtimeDbs['fin_voucher']->inserts), 'Each refund group should persist one voucher');
refundServiceAssertSame('退案款', $runtimeDbs['fin_voucher']->inserts[0]['summary'], 'Refund persistence should keep rule header summary');
refundServiceAssertSame('30.00', $runtimeDbs['fin_voucher']->inserts[0]['debit_amount'], 'Refund persistence should keep daily total');
refundServiceAssertSame(1, count($runtimeDbs['fin_case_fund_refund']->updates), 'Refund source rows should update once per voucher group');
$refundUpdateScopeFound = false;
$refundIdBatchFound = false;
foreach ($runtimeDbs['fin_case_fund_refund']->whereCalls as $whereCall) {
    if (isset($whereCall[0]) && is_array($whereCall[0]) && isset($whereCall[0]['account_set_id'])) {
        $refundUpdateScopeFound = $whereCall[0]['account_set_id'] === 'set-1'
            && $whereCall[0]['fiscal_year'] === '2026'
            && $whereCall[0]['del_flag'] === 0;
    }
    if (($whereCall[0] ?? '') === 'refund_id' && ($whereCall[1] ?? '') === 'in') {
        $refundIdBatchFound = ($whereCall[2] ?? []) === ['refund-1', 'refund-2'];
    }
}
refundServiceAssertSame(true, $refundUpdateScopeFound, 'Refund source update must retain account, year and soft-delete scope');
refundServiceAssertSame(true, $refundIdBatchFound, 'Refund source updates should batch ids by voucher group');

[$queryService, $queryDbs] = refundRuntimeFixture([$initialRows[0]]);
$queryResult = $queryService->rowsByQuery([
    'period' => '2026-07',
    'amount_min' => '10.00',
    'amount_max' => '20.00',
    'scope_snapshot_time' => '2026-08-03 12:34:56',
]);
refundServiceAssertSame('', $queryResult['error'], 'Refund query selection should succeed below the safety limit');
refundServiceAssertSame([5001], $queryDbs['fin_case_fund_refund']->limits, 'Refund query selection must cap memory with one overflow row');
$refundSnapshotFound = false;
$refundAmountRangeFound = false;
foreach ($queryDbs['fin_case_fund_refund']->whereCalls as $whereCall) {
    if (isset($whereCall[0]) && is_array($whereCall[0])) {
        $refundSnapshotFound = ($whereCall[0]['created_time'] ?? null) === ['<=', '2026-08-03 12:34:56'];
        $refundAmountRangeFound = ($whereCall[0]['refund_amount'] ?? null) === [
            ['>=', '10.00'],
            ['<=', '20.00'],
        ];
    }
}
refundServiceAssertSame(true, $refundSnapshotFound, 'Refund query scope must use the submission snapshot time');
refundServiceAssertSame(true, $refundAmountRangeFound, 'Refund query generation must preserve the visible amount range');
$queryDbs['fin_case_fund_refund']->selectRows = array_fill(0, 5001, ['refund_id' => 'overflow']);
$tooLargeQuery = $queryService->rowsByQuery(['period' => '2026-07']);
refundServiceAssertSame(
    '单个后台制证任务最多处理5000条记录，请缩小查询范围后重新提交',
    $tooLargeQuery['error'],
    'Refund query selection should fail explicitly instead of loading an unbounded scope'
);

[$missingService, $missingDbs, $missingRule, $missingState] = refundRuntimeFixture([$initialRows[0]]);
$missingPrepared = $missingService->prepareGeneration($initialRows, 'SELECTED');
refundServiceAssertThrows(
    '所选退付记录不存在或已被删除，请刷新后重试',
    function () use ($missingService, $missingPrepared) { $missingService->generatePrepared($missingPrepared); },
    'Missing locked refund rows should abort the whole batch'
);
refundServiceAssertSame(0, $missingState->voucherNoCalls, 'Missing refund rows should fail before voucher number allocation');
refundServiceAssertSame(0, count($missingDbs['fin_voucher']->inserts), 'Missing refund rows should not insert voucher headers');

[$blockedService, $blockedDbs, $blockedRule, $blockedState] = refundRuntimeFixture($lockedRows, true);
$blockedPrepared = $blockedService->prepareGeneration($initialRows, 'SELECTED');
refundServiceAssertThrows(
    '案号【案二】的退付记录已生成凭证且原凭证仍有效，不允许重复生成',
    function () use ($blockedService, $blockedPrepared) { $blockedService->generatePrepared($blockedPrepared); },
    'Refund status that changes to an active generated voucher should be blocked after locking'
);
refundServiceAssertSame(0, $blockedState->voucherNoCalls, 'Blocked concurrent refund generation should not allocate voucher number');
refundServiceAssertSame(0, count($blockedDbs['fin_voucher']->inserts), 'Blocked concurrent refund generation should not insert vouchers');

[$failedUpdateService, $failedUpdateDbs] = refundRuntimeFixture([$initialRows[0]], false, [0]);
$failedUpdatePrepared = $failedUpdateService->prepareGeneration([$initialRows[0]], 'SELECTED');
refundServiceAssertThrows(
    '退付记录状态已变化，请刷新后重试',
    function () use ($failedUpdateService, $failedUpdatePrepared) { $failedUpdateService->generatePrepared($failedUpdatePrepared); },
    'Refund source update must affect exactly one locked row'
);

[$failedPersistenceService, $failedPersistenceDbs] = refundRuntimeFixture(
    [$initialRows[0]],
    false,
    [],
    true
);
$failedPersistencePrepared = $failedPersistenceService->prepareGeneration([$initialRows[0]], 'SELECTED');
refundServiceAssertThrows(
    'refund persistence failed',
    function () use ($failedPersistenceService, $failedPersistencePrepared) {
        $failedPersistenceService->generatePrepared($failedPersistencePrepared);
    },
    'Refund persistence errors should propagate to the caller transaction'
);
refundServiceAssertSame(
    0,
    count($failedPersistenceDbs['fin_case_fund_refund']->updates),
    'Refund source rows should only update after voucher persistence succeeds'
);

$source = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/RefundVoucherGenerationService.php');
foreach (['requirePermission', 'Db::startTrans', 'Db::commit', 'Db::rollback', 'logAudit'] as $forbidden) {
    if (strpos($source, $forbidden) !== false) {
        fwrite(STDERR, 'Refund voucher service should not own boundary concern: ' . $forbidden . PHP_EOL);
        exit(1);
    }
}

echo 'Refund voucher generation service test passed' . PHP_EOL;
