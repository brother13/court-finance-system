<?php

require_once __DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherPersistenceService.php';
require_once __DIR__ . '/../app/finance/service/casefund/voucher/UnknownPaymentVoucherGenerationService.php';

use app\finance\service\casefund\voucher\CaseFundVoucherPersistenceService;
use app\finance\service\casefund\voucher\UnknownPaymentVoucherGenerationService;

class UnknownVoucherManagerStub
{
    public function validateVoucherSubjectCode($subjectCode, $label) { return null; }
    public function subjectConfigAccountSetFlag() { return 1; }
}

class UnknownVoucherRuleStub
{
    public $groupRows = [];
    public $byDayFlag = null;
    public $debitConfigs = [];
    public $creditConfigs = [];

    public function groupUnknownPaymentRowsForVoucher($rows, $byDayFlag)
    {
        $this->groupRows = $rows;
        $this->byDayFlag = $byDayFlag;
        return ['2026-07-14' => $rows];
    }

    public function buildUnknownPaymentVoucherDetailsData($rows, $subjectConfig, $debitConfigs = [], $creditConfigs = [])
    {
        $this->debitConfigs = $debitConfigs;
        $this->creditConfigs = $creditConfigs;
        $details = [];
        $lineNo = 1;
        $total = 0;
        foreach ($rows as $row) {
            $cents = (int)round((float)$row['unknown_amount'] * 100);
            $total += $cents;
            $details[] = [
                'detail_id' => 'detail-' . $lineNo,
                'line_no' => $lineNo++,
                'subject_code' => $subjectConfig['debit_subject_code'],
                'summary' => 'summary',
                'debit_amount' => number_format($cents / 100, 2, '.', ''),
                'credit_amount' => '0.00',
                'verification_status' => 'NOT_REQUIRED',
                'aux_desc' => '',
                'aux_values' => [],
            ];
            $caseNo = (string)($row['unknown_aux_case_no'] ?? '');
            $details[] = [
                'detail_id' => 'detail-' . $lineNo,
                'line_no' => $lineNo++,
                'subject_code' => $subjectConfig['credit_subject_code'],
                'summary' => 'summary',
                'debit_amount' => '0.00',
                'credit_amount' => number_format($cents / 100, 2, '.', ''),
                'verification_status' => 'NOT_REQUIRED',
                'aux_desc' => $caseNo === '' ? '' : 'case_no:' . $caseNo,
                'aux_values' => $caseNo === '' ? [] : [[
                    'aux_type_code' => 'case_no',
                    'aux_value' => $caseNo,
                    'aux_label' => $caseNo,
                ]],
            ];
        }
        return ['details' => $details, 'total_cents' => $total];
    }
}

class UnknownAuxiliaryCaseServiceStub
{
    public $ensureCalls = [];

    public function ensurePersistedCodes(array $rows)
    {
        $this->ensureCalls[] = $rows;
        foreach ($rows as &$row) {
            $row['aux_case_code'] = $row['aux_case_code'] ?? ('BMK-' . strtoupper(str_pad($row['unknown_payment_id'], 8, '0')));
            $payer = trim((string)($row['payer_name'] ?? ''));
            $remark = trim((string)($row['bank_remark'] ?? ''));
            $parts = array_values(array_filter([$payer, $remark, $row['aux_case_code']], function ($value) {
                return $value !== '';
            }));
            $row['unknown_aux_case_no'] = implode('｜', $parts);
        }
        unset($row);
        return $rows;
    }
}

class UnknownVoucherDbStub
{
    public $findRow = null;
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
    public function find() { return $this->findRow; }
    public function select() { return $this->selectRows; }
    public function insert($row) { $this->inserts[] = $row; return 1; }
    public function update($row) { $this->updates[] = $row; return $this->updateResult; }
}

class UnknownFailingPersistenceStub
{
    public function persistVoucher(array $context, array $detailsData)
    {
        throw new \RuntimeException('unknown persistence failed');
    }
}

function unknownServiceAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$manager = new UnknownVoucherManagerStub();
$rule = new UnknownVoucherRuleStub();
$dbs = [
    'fin_case_fund_subject_config' => new UnknownVoucherDbStub(),
    'fin_case_fund_unknown_payment' => new UnknownVoucherDbStub(),
    'fin_voucher' => new UnknownVoucherDbStub(),
    'fin_voucher_detail' => new UnknownVoucherDbStub(),
    'fin_voucher_aux_value' => new UnknownVoucherDbStub(),
];
$dbs['fin_case_fund_subject_config']->findRow = [
    'debit_subject_code' => '100201',
    'credit_subject_code' => '224101',
];
$dbs['fin_case_fund_unknown_payment']->selectRows = [
    [
        'unknown_payment_id' => 'u1',
        'transaction_date' => '2026-07-14',
        'period' => '2026-07',
        'unknown_amount' => '10.00',
        'voucher_status' => 'UNGENERATED',
        'payer_name' => '甲',
        'bank_remark' => '备注一',
        'aux_case_code' => 'BMK-A82F193B',
    ],
    [
        'unknown_payment_id' => 'u2',
        'transaction_date' => '2026-07-14',
        'period' => '2026-07',
        'unknown_amount' => '20.00',
        'voucher_status' => 'UNGENERATED',
        'payer_name' => '乙',
        'bank_remark' => '',
        'aux_case_code' => 'BMK-C0FFEE01',
    ],
];
$events = [];
$idSequence = 0;
$subjectAuxConfigs = [
    '100201' => [],
    '224101' => [['aux_type_code' => 'case_no', 'required_flag' => 1, 'verification_flag' => 0]],
];
$ensuredAuxDetails = [];
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
    'get_subject_aux_configs' => function ($subjectCode) use (&$subjectAuxConfigs) { return $subjectAuxConfigs[$subjectCode] ?? []; },
    'has_required_aux_configs' => function ($configs) {
        foreach ($configs as $config) {
            if ((int)($config['required_flag'] ?? 0) === 1) return true;
        }
        return false;
    },
    'assert_rows_can_generate' => function ($rows, $label) { return null; },
    'validate_period' => function ($row, $period) use (&$events) { $events[] = 'period:' . $row['unknown_payment_id']; return null; },
    'build_summary' => function ($row) { return '收 不明款'; },
    'fiscal_year' => function ($period) { return substr($period, 0, 4); },
    'generate_next_voucher_no' => function ($period) use (&$events) { $events[] = 'voucher_no'; return 7; },
    'ensure_aux_archives' => function ($detailsData) use (&$ensuredAuxDetails) { $ensuredAuxDetails[] = $detailsData; },
    'cents_to_decimal' => function ($cents) { return number_format($cents / 100, 2, '.', ''); },
    'new_id' => function () use (&$idSequence) { return 'id-' . (++$idSequence); },
    'prepare_create' => function ($row) { return $row; },
    'current_user_id' => function () { return 'user-1'; },
    'now' => function () { return '2026-07-14 12:00:00'; },
];
$persistence = new CaseFundVoucherPersistenceService($hooks);
$auxiliaryCaseService = new UnknownAuxiliaryCaseServiceStub();

try {
    new UnknownPaymentVoucherGenerationService($manager, $rule, $persistence, $auxiliaryCaseService, [], 'unknown', [], 'config', []);
    fwrite(STDERR, 'Unknown-payment voucher service should reject incomplete hooks' . PHP_EOL);
    exit(1);
} catch (\InvalidArgumentException $e) {
    unknownServiceAssertSame('不明款凭证生成服务缺少回调：get_db', $e->getMessage(), 'Missing hook should be explicit');
}

$service = new UnknownPaymentVoucherGenerationService(
    $manager,
    $rule,
    $persistence,
    $auxiliaryCaseService,
    $hooks,
    'fin_case_fund_unknown_payment',
    ['unknown_payment_id'],
    'fin_case_fund_subject_config',
    ['debit_subject_code', 'credit_subject_code']
);
$prepared = $service->prepareGeneration('u1,u1,u2');
unknownServiceAssertSame(['u1', 'u2'], $prepared['unknown_payment_ids'], 'Unknown-payment ids should be normalized and deduplicated before transaction');
unknownServiceAssertSame(
    [['aux_type_code' => 'case_no', 'required_flag' => 1, 'verification_flag' => 0]],
    $prepared['credit_configs'],
    'Required case_no configuration should be supported instead of blocking automatic voucher generation'
);
$result = $service->generatePrepared($prepared);
unknownServiceAssertSame([true], $dbs['fin_case_fund_unknown_payment']->lockValues, 'Unknown-payment source rows should be locked inside generation');
unknownServiceAssertSame(['transaction_date asc, source_row_no asc'], $dbs['fin_case_fund_unknown_payment']->orders, 'Unknown-payment source order should remain stable');
unknownServiceAssertSame('不明款', $rule->groupRows[0]['case_no'], 'Temporary case number should support common regeneration errors only');
unknownServiceAssertSame('甲｜备注一｜BMK-A82F193B', $rule->groupRows[0]['unknown_aux_case_no'], 'Generation should pass the persisted composite case_no to the voucher rule');
unknownServiceAssertSame([], $rule->debitConfigs, 'Voucher rule should receive debit subject auxiliary configuration separately');
unknownServiceAssertSame($prepared['credit_configs'], $rule->creditConfigs, 'Voucher rule should receive credit subject auxiliary configuration separately');
unknownServiceAssertSame(['period:u1', 'period:u2', 'voucher_no'], $events, 'Period validation should finish before voucher number allocation');
unknownServiceAssertSame(1, $result['generated_count'], 'Daily unknown-payment rows should create one voucher');
unknownServiceAssertSame(2, $result['unknown_payment_count'], 'Result should report locked source row count');
unknownServiceAssertSame(1, count($dbs['fin_voucher']->inserts), 'Unknown-payment generation should insert one voucher header');
unknownServiceAssertSame('收 不明款', $dbs['fin_voucher']->inserts[0]['summary'], 'Unknown-payment persistence should keep fixed summary');
unknownServiceAssertSame('30.00', $dbs['fin_voucher']->inserts[0]['debit_amount'], 'Unknown-payment persistence should keep daily total');
unknownServiceAssertSame(4, count($dbs['fin_voucher_detail']->inserts), 'Unknown-payment generation should insert balanced debit-credit details');
unknownServiceAssertSame(2, count($dbs['fin_voucher_aux_value']->inserts), 'Configured case_no should be persisted for each matching unknown-payment credit detail');
unknownServiceAssertSame(1, count($ensuredAuxDetails), 'Unknown-payment generation should prepare common case_no archives before voucher persistence');
unknownServiceAssertSame(2, count($dbs['fin_case_fund_unknown_payment']->updates), 'Every unknown-payment row should be marked generated');

$subjectAuxConfigs['100201'] = [['aux_type_code' => 'department', 'required_flag' => 1, 'verification_flag' => 0]];
$unsupportedPrepared = $service->prepareGeneration('u1');
unknownServiceAssertSame(
    '不明款自动制证无法生成必填辅助核算：department',
    $unsupportedPrepared['error'],
    'Required auxiliary dimensions other than case_no should remain blocked'
);
$subjectAuxConfigs['100201'] = [];
$subjectAuxConfigs['224101'] = [];
$unconfiguredPrepared = $service->prepareGeneration('u1');
unknownServiceAssertSame(false, $unconfiguredPrepared['uses_case_no'], 'Unconfigured customers should retain the original no-case-number generation path');
$subjectAuxConfigs['224101'] = [['aux_type_code' => 'case_no', 'required_flag' => 1, 'verification_flag' => 0]];

$dbs['fin_case_fund_unknown_payment']->updates = [];
$failedPersistenceService = new UnknownPaymentVoucherGenerationService(
    $manager,
    $rule,
    new UnknownFailingPersistenceStub(),
    $auxiliaryCaseService,
    $hooks,
    'fin_case_fund_unknown_payment',
    ['unknown_payment_id'],
    'fin_case_fund_subject_config',
    ['debit_subject_code', 'credit_subject_code']
);
$failedPersistencePrepared = $failedPersistenceService->prepareGeneration('u1,u2');
try {
    $failedPersistenceService->generatePrepared($failedPersistencePrepared);
    fwrite(STDERR, 'Unknown-payment persistence errors should propagate' . PHP_EOL);
    exit(1);
} catch (\RuntimeException $e) {
    unknownServiceAssertSame('unknown persistence failed', $e->getMessage(), 'Unknown-payment persistence error should be unchanged');
}
unknownServiceAssertSame(
    0,
    count($dbs['fin_case_fund_unknown_payment']->updates),
    'Unknown-payment source rows should only update after voucher persistence succeeds'
);

$source = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/UnknownPaymentVoucherGenerationService.php');
foreach (['requirePermission', 'Db::startTrans', 'Db::commit', 'Db::rollback', 'logAudit'] as $forbidden) {
    if (strpos($source, $forbidden) !== false) {
        fwrite(STDERR, 'Unknown-payment voucher service should not own boundary concern: ' . $forbidden . PHP_EOL);
        exit(1);
    }
}

echo 'Unknown-payment voucher generation service test passed' . PHP_EOL;
