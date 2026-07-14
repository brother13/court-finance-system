<?php

require_once __DIR__ . '/../app/finance/service/casefund/policy/CaseFundBusinessPolicy.php';
require_once __DIR__ . '/../app/finance/service/casefund/voucher/RefundVoucherGenerationService.php';

use app\finance\service\casefund\policy\CaseFundBusinessPolicy;
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
    public function subjectConfigAccountSetFlag() { return 1; }
    public function generateRefundVoucherByOutOrderNoFlag() { return 1; }
    public function validateVoucherSubjectCode($subjectCode, $label) { return null; }
}

class RefundVoucherRuleStub
{
    public $receivedFlags = [];

    public function groupRefundRowsForVoucher($rows, $byDayFlag, $byOutOrderNoFlag)
    {
        $this->receivedFlags = [$byDayFlag, $byOutOrderNoFlag];
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['refund_date']][] = $row;
        }
        return $groups;
    }
}

function refundServiceAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
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
    'fiscal_year' => function ($period) { return substr($period, 0, 4); },
    'generate_next_voucher_no' => function ($period) { return 1; },
    'ensure_aux_archives' => function ($details) {},
    'cents_to_decimal' => function ($cents) { return number_format($cents / 100, 2, '.', ''); },
    'new_id' => function () { return 'test-id'; },
    'prepare_create' => function ($row) { return $row; },
    'current_user_id' => function () { return 'user-1'; },
    'now' => function () { return '2026-07-14 12:00:00'; },
];

try {
    new RefundVoucherGenerationService($policy, $resolver, $manager, $rule, [], 'refund', []);
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
        'voucher_status' => 'UNGENERATED',
    ],
    [
        'refund_id' => 'refund-2',
        'refund_date' => '2026-07-14',
        'out_order_no' => 'OUT-1',
        'out_type' => '执行、调解款发放',
        'case_no' => '案二',
        'voucher_status' => 'UNGENERATED',
    ],
];
$prepared = $service->prepareGeneration($rows, 'QUERY');
refundServiceAssertSame('', $prepared['error'], 'Valid refund rows should be prepared');
refundServiceAssertSame([1, 1], $rule->receivedFlags, 'Refund grouping flags should be delegated to voucher rule');
refundServiceAssertSame(['2026-07-14'], array_keys($prepared['groups']), 'Daily refund groups should preserve source order');
refundServiceAssertSame('QUERY', $prepared['generate_scope'], 'Generation scope should survive preparation');

$source = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/RefundVoucherGenerationService.php');
foreach (['requirePermission', 'Db::startTrans', 'Db::commit', 'Db::rollback', 'logAudit'] as $forbidden) {
    if (strpos($source, $forbidden) !== false) {
        fwrite(STDERR, 'Refund voucher service should not own boundary concern: ' . $forbidden . PHP_EOL);
        exit(1);
    }
}

echo 'Refund voucher generation service test passed' . PHP_EOL;
