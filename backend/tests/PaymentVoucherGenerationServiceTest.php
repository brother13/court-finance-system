<?php

require_once __DIR__ . '/../app/finance/service/casefund/policy/CaseFundBusinessPolicy.php';
require_once __DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherPersistenceService.php';
require_once __DIR__ . '/../app/finance/service/casefund/voucher/PaymentVoucherGenerationService.php';

use app\finance\service\casefund\policy\CaseFundBusinessPolicy;
use app\finance\service\casefund\voucher\CaseFundVoucherPersistenceService;
use app\finance\service\casefund\voucher\PaymentVoucherGenerationService;

class PaymentVoucherResolverStub
{
    public function configsByBusinessItems($bizType, $voucherBizType)
    {
        return [
            '缴费登记' => [
                'debit_subject_code' => '100201',
                'credit_subject_code' => '224101',
            ],
        ];
    }

    public function paymentMethodConfigMap($bizType)
    {
        return [
            'POS' => ['debit_subject_code' => '100202'],
            'TRANSFER' => ['debit_subject_code' => '100201'],
        ];
    }
}

class PaymentVoucherManagerStub
{
    public $byDay = 1;
    public $byMethod = 1;

    public function subjectConfigAccountSetFlag()
    {
        return $this->byDay;
    }

    public function generatePaymentVoucherByMethodFlag()
    {
        return $this->byMethod;
    }

    public function validateVoucherSubjectCode($subjectCode, $label)
    {
        return null;
    }
}

class PaymentVoucherRuleStub
{
    public $receivedFlags = [];
    public $groupRowsHistory = [];

    public function groupPaymentRowsForVoucher($rows, $byDayFlag, $byMethodFlag)
    {
        $this->receivedFlags = [$byDayFlag, $byMethodFlag];
        $this->groupRowsHistory[] = $rows;
        $groups = [];
        foreach ($rows as $row) {
            $key = $byDayFlag
                ? $row['payment_date'] . '|' . ($byMethodFlag ? $row['payment_method'] : 'ALL')
                : $row['payment_id'] . '|ONE';
            $groups[$key][] = $row;
        }
        return $groups;
    }

    public function buildPaymentVoucherDetailsData($rows, $subjectConfigMap, $paymentMethodConfigMap, $byMethod, $bizType)
    {
        $total = 0;
        foreach ($rows as $row) {
            $total += $this->amountToCents($row['payment_amount']);
        }
        return [
            'total_debit' => $total,
            'details' => [
                [
                    'detail_id' => 'payment-detail-1',
                    'line_no' => 1,
                    'subject_code' => '100201',
                    'summary' => '收案款',
                    'debit_amount' => number_format($total / 100, 2, '.', ''),
                    'credit_amount' => '0.00',
                    'verification_status' => 'NOT_REQUIRED',
                    'aux_desc' => '',
                    'aux_values' => [],
                ],
                [
                    'detail_id' => 'payment-detail-2',
                    'line_no' => 2,
                    'subject_code' => '224101',
                    'summary' => '收案款',
                    'debit_amount' => '0.00',
                    'credit_amount' => number_format($total / 100, 2, '.', ''),
                    'verification_status' => 'NOT_REQUIRED',
                    'aux_desc' => '',
                    'aux_values' => [],
                ],
            ],
        ];
    }

    public function buildPaymentVoucherSummary($row)
    {
        return '收案款';
    }

    protected function amountToCents($amount)
    {
        $parts = explode('.', trim((string)$amount), 2);
        $yuan = (int)$parts[0];
        $fraction = str_pad($parts[1] ?? '', 2, '0');
        return $yuan * 100 + (int)substr($fraction, 0, 2);
    }
}

class PaymentVoucherDbStub
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

class PaymentVoucherRuntimeState
{
    public $guardSnapshots = [];
    public $voucherNoCalls = 0;
    public $idSequence = 0;
    public $lockEvents = [];
    public $auxiliaryDetailCounts = [];
    public $periodChecks = [];
}

class PaymentFailingPersistenceStub
{
    public function persistVoucher(array $context, array $detailsData)
    {
        throw new \RuntimeException('payment persistence failed');
    }
}

function paymentServiceAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function paymentServiceAssertThrows($expectedMessage, $callback, $message)
{
    try {
        $callback();
    } catch (\Exception $e) {
        paymentServiceAssertSame($expectedMessage, $e->getMessage(), $message);
        return;
    }
    fwrite(STDERR, $message . '; expected exception was not thrown' . PHP_EOL);
    exit(1);
}

function paymentRuntimeFixture($lockedRows, $blockLockedGenerated = false, $updateResults = [], $failPersistence = false)
{
    $policy = new CaseFundBusinessPolicy();
    $resolver = new PaymentVoucherResolverStub();
    $manager = new PaymentVoucherManagerStub();
    $rule = new PaymentVoucherRuleStub();
    $state = new PaymentVoucherRuntimeState();
    $dbs = [
        'fin_case_fund_payment' => new PaymentVoucherDbStub(),
        'fin_voucher' => new PaymentVoucherDbStub(),
        'fin_voucher_detail' => new PaymentVoucherDbStub(),
        'fin_voucher_aux_value' => new PaymentVoucherDbStub(),
    ];
    $dbs['fin_case_fund_payment']->selectRows = $lockedRows;
    $dbs['fin_case_fund_payment']->updateResults = $updateResults;
    $hooks = [
        'get_db' => function ($table) use (&$dbs) { return $dbs[$table]; },
        'account_where' => function () { return ['account_set_id' => 'set-1', 'del_flag' => 0]; },
        'account_set_id' => function () { return 'set-1'; },
        'current_year' => function () { return '2026'; },
        'current_biz_type' => function () { return 'CASE_FUND'; },
        'assert_rows_can_generate' => function ($rows, $label) use ($state, $blockLockedGenerated) {
            $state->guardSnapshots[] = $rows;
            if ($blockLockedGenerated && count($state->guardSnapshots) > 1) {
                return '案号【案二】的缴费记录已生成凭证且原凭证仍有效，不允许重复生成';
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
        'new_id' => function () use ($state) { return 'payment-id-' . (++$state->idSequence); },
        'prepare_create' => function ($row) { return $row; },
        'current_user_id' => function () { return 'user-1'; },
        'now' => function () { return '2026-07-14 12:00:00'; },
    ];
    $persistence = $failPersistence
        ? new PaymentFailingPersistenceStub()
        : new CaseFundVoucherPersistenceService($hooks);
    $service = new PaymentVoucherGenerationService(
        $policy,
        $resolver,
        $manager,
        $rule,
        $persistence,
        $hooks,
        'fin_case_fund_payment',
        ['payment_id', 'payment_date', 'payment_amount', 'payment_method', 'business_type', 'case_no', 'voucher_status', 'voucher_id']
    );
    return [$service, $dbs, $rule, $state];
}

$policy = new CaseFundBusinessPolicy();
$resolver = new PaymentVoucherResolverStub();
$manager = new PaymentVoucherManagerStub();
$rule = new PaymentVoucherRuleStub();
$hooks = [
    'get_db' => function ($table) {
        throw new \RuntimeException('This unit test should not query database');
    },
    'account_where' => function () { return ['account_set_id' => 'set-1', 'del_flag' => 0]; },
    'account_set_id' => function () { return 'set-1'; },
    'current_year' => function () { return '2026'; },
    'current_biz_type' => function () { return 'CASE_FUND'; },
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
    new PaymentVoucherGenerationService($policy, $resolver, $manager, $rule, $persistence, [], 'payment', []);
    fwrite(STDERR, 'Payment voucher service should reject incomplete hooks' . PHP_EOL);
    exit(1);
} catch (\InvalidArgumentException $e) {
    paymentServiceAssertSame('缴费凭证生成服务缺少回调：get_db', $e->getMessage(), 'Missing hook should be explicit');
}

$service = new PaymentVoucherGenerationService(
    $policy,
    $resolver,
    $manager,
    $rule,
    $persistence,
    $hooks,
    'fin_case_fund_payment',
    ['payment_id']
);
$rows = [
    [
        'payment_id' => 'payment-1',
        'payment_date' => '2026-07-14',
        'payment_method' => 'POS',
        'business_type' => '执行、调解款',
        'case_no' => '案一',
        'voucher_status' => 'UNGENERATED',
    ],
    [
        'payment_id' => 'payment-2',
        'payment_date' => '2026-07-14',
        'payment_method' => 'TRANSFER',
        'business_type' => '执行、调解款',
        'case_no' => '案二',
        'voucher_status' => 'UNGENERATED',
    ],
];
$prepared = $service->prepareGeneration($rows, 'QUERY');
paymentServiceAssertSame('', $prepared['error'], 'Valid payment rows should be prepared');
paymentServiceAssertSame([1, 1], $rule->receivedFlags, 'Payment grouping flags should be delegated to voucher rule');
paymentServiceAssertSame(['2026-07-14|POS', '2026-07-14|TRANSFER'], array_keys($prepared['groups']), 'Payment groups should preserve rule order');
paymentServiceAssertSame('QUERY', $prepared['generate_scope'], 'Generation scope should survive preparation');

$initialRows = [
    [
        'payment_id' => 'payment-1',
        'payment_date' => '2026-08-14',
        'payment_amount' => '10.00',
        'payment_method' => 'POS',
        'business_type' => '执行、调解款',
        'case_no' => '案一',
        'voucher_status' => 'UNGENERATED',
    ],
    [
        'payment_id' => 'payment-2',
        'payment_date' => '2026-07-14',
        'payment_amount' => '20.00',
        'payment_method' => 'TRANSFER',
        'business_type' => '执行、调解款',
        'case_no' => '案二',
        'voucher_status' => 'UNGENERATED',
    ],
];
$lockedRows = $initialRows;
$lockedRows[1]['voucher_status'] = 'GENERATED';
$lockedRows[1]['voucher_id'] = 'deleted-voucher';
[$runtimeService, $runtimeDbs, $runtimeRule, $runtimeState] = paymentRuntimeFixture($lockedRows);
$runtimePrepared = $runtimeService->prepareGeneration($initialRows, 'QUERY');
$runtimeResult = $runtimeService->generatePrepared($runtimePrepared);
paymentServiceAssertSame(
    ['voucher_no:2026-07', 'voucher_no:2026-08', 'aux_archives'],
    $runtimeState->lockEvents,
    'Payment voucher numbers must lock by stable period before complete auxiliary preparation'
);
paymentServiceAssertSame([4], $runtimeState->auxiliaryDetailCounts, 'All payment voucher groups should prepare auxiliary archives in one call');
paymentServiceAssertSame([true], $runtimeDbs['fin_case_fund_payment']->lockValues, 'Payment source rows should be locked inside generation');
paymentServiceAssertSame(
    [['payment_date', '缴费', 2]],
    $runtimeState->periodChecks,
    'Payment periods must be rechecked after locking source rows'
);
paymentServiceAssertSame('GENERATED', $runtimeState->guardSnapshots[1][0]['voucher_status'], 'Locked payment status must be rechecked');
$sortedLockedRows = [$lockedRows[1], $lockedRows[0]];
paymentServiceAssertSame($sortedLockedRows, $runtimeRule->groupRowsHistory[1], 'Payment groups must be rebuilt in stable date/source order from locked rows');
paymentServiceAssertSame(2, $runtimeResult['generated_count'], 'Payment method split should create two vouchers from locked rows');
paymentServiceAssertSame(2, $runtimeResult['payment_count'], 'Payment count should come from locked rows');
paymentServiceAssertSame(2, count($runtimeDbs['fin_voucher']->inserts), 'Each payment group should persist one voucher');
paymentServiceAssertSame('收案款', $runtimeDbs['fin_voucher']->inserts[0]['summary'], 'Payment persistence should keep detail summary');
paymentServiceAssertSame('20.00', $runtimeDbs['fin_voucher']->inserts[0]['debit_amount'], 'Payment persistence should follow stable date/source order and keep group total');
paymentServiceAssertSame(2, count($runtimeDbs['fin_case_fund_payment']->updates), 'Every locked payment row should be updated once');
$paymentUpdateScopeFound = false;
$paymentIdBatchFound = false;
foreach ($runtimeDbs['fin_case_fund_payment']->whereCalls as $whereCall) {
    if (isset($whereCall[0]) && is_array($whereCall[0]) && isset($whereCall[0]['account_set_id'])) {
        $paymentUpdateScopeFound = $whereCall[0]['account_set_id'] === 'set-1'
            && $whereCall[0]['fiscal_year'] === '2026'
            && $whereCall[0]['del_flag'] === 0;
    }
    if (($whereCall[0] ?? '') === 'payment_id' && ($whereCall[1] ?? '') === 'in') {
        $paymentIdBatchFound = $paymentIdBatchFound || in_array('payment-1', $whereCall[2] ?? [], true);
    }
}
paymentServiceAssertSame(true, $paymentUpdateScopeFound, 'Payment source update must retain account, year and soft-delete scope');
paymentServiceAssertSame(true, $paymentIdBatchFound, 'Payment source updates should batch ids by voucher group');

[$queryService, $queryDbs] = paymentRuntimeFixture([$initialRows[0]]);
$queryResult = $queryService->rowsByQuery([
    'period' => '2026-08',
    'amount_min' => '10.00',
    'amount_max' => '20.00',
    'scope_snapshot_time' => '2026-08-03 12:34:56',
]);
paymentServiceAssertSame('', $queryResult['error'], 'Payment query selection should succeed below the safety limit');
paymentServiceAssertSame([5001], $queryDbs['fin_case_fund_payment']->limits, 'Payment query selection must cap memory with one overflow row');
$paymentSnapshotFound = false;
$paymentAmountRangeFound = false;
foreach ($queryDbs['fin_case_fund_payment']->whereCalls as $whereCall) {
    if (isset($whereCall[0]) && is_array($whereCall[0])) {
        $paymentSnapshotFound = ($whereCall[0]['created_time'] ?? null) === ['<=', '2026-08-03 12:34:56'];
        $paymentAmountRangeFound = ($whereCall[0]['payment_amount'] ?? null) === [
            ['>=', '10.00'],
            ['<=', '20.00'],
        ];
    }
}
paymentServiceAssertSame(true, $paymentSnapshotFound, 'Payment query scope must use the submission snapshot time');
paymentServiceAssertSame(true, $paymentAmountRangeFound, 'Payment query generation must preserve the visible amount range');
$queryDbs['fin_case_fund_payment']->selectRows = array_fill(0, 5001, ['payment_id' => 'overflow']);
$tooLargeQuery = $queryService->rowsByQuery(['period' => '2026-08']);
paymentServiceAssertSame(
    '单个后台制证任务最多处理5000条记录，请缩小查询范围后重新提交',
    $tooLargeQuery['error'],
    'Payment query selection should fail explicitly instead of loading an unbounded scope'
);

[$missingService, $missingDbs, $missingRule, $missingState] = paymentRuntimeFixture([$initialRows[0]]);
$missingPrepared = $missingService->prepareGeneration($initialRows, 'SELECTED');
paymentServiceAssertThrows(
    '所选缴费记录不存在或已被删除，请刷新后重试',
    function () use ($missingService, $missingPrepared) { $missingService->generatePrepared($missingPrepared); },
    'Missing locked payment rows should abort the whole batch'
);
paymentServiceAssertSame(0, $missingState->voucherNoCalls, 'Missing payment rows should fail before voucher number allocation');
paymentServiceAssertSame(0, count($missingDbs['fin_voucher']->inserts), 'Missing payment rows should not insert voucher headers');

[$blockedService, $blockedDbs, $blockedRule, $blockedState] = paymentRuntimeFixture($lockedRows, true);
$blockedPrepared = $blockedService->prepareGeneration($initialRows, 'SELECTED');
paymentServiceAssertThrows(
    '案号【案二】的缴费记录已生成凭证且原凭证仍有效，不允许重复生成',
    function () use ($blockedService, $blockedPrepared) { $blockedService->generatePrepared($blockedPrepared); },
    'Payment status that changes to an active generated voucher should be blocked after locking'
);
paymentServiceAssertSame(0, $blockedState->voucherNoCalls, 'Blocked concurrent payment generation should not allocate voucher number');
paymentServiceAssertSame(0, count($blockedDbs['fin_voucher']->inserts), 'Blocked concurrent payment generation should not insert vouchers');

[$failedUpdateService, $failedUpdateDbs] = paymentRuntimeFixture([$initialRows[0]], false, [0]);
$failedUpdatePrepared = $failedUpdateService->prepareGeneration([$initialRows[0]], 'SELECTED');
paymentServiceAssertThrows(
    '缴费记录状态已变化，请刷新后重试',
    function () use ($failedUpdateService, $failedUpdatePrepared) { $failedUpdateService->generatePrepared($failedUpdatePrepared); },
    'Payment source update must affect exactly one locked row'
);

[$failedPersistenceService, $failedPersistenceDbs] = paymentRuntimeFixture(
    [$initialRows[0]],
    false,
    [],
    true
);
$failedPersistencePrepared = $failedPersistenceService->prepareGeneration([$initialRows[0]], 'SELECTED');
paymentServiceAssertThrows(
    'payment persistence failed',
    function () use ($failedPersistenceService, $failedPersistencePrepared) {
        $failedPersistenceService->generatePrepared($failedPersistencePrepared);
    },
    'Payment persistence errors should propagate to the caller transaction'
);
paymentServiceAssertSame(
    0,
    count($failedPersistenceDbs['fin_case_fund_payment']->updates),
    'Payment source rows should only update after voucher persistence succeeds'
);

$source = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/PaymentVoucherGenerationService.php');
foreach (['requirePermission', 'Db::startTrans', 'Db::commit', 'Db::rollback', 'logAudit'] as $forbidden) {
    if (strpos($source, $forbidden) !== false) {
        fwrite(STDERR, 'Payment voucher service should not own boundary concern: ' . $forbidden . PHP_EOL);
        exit(1);
    }
}

echo 'Payment voucher generation service test passed' . PHP_EOL;
