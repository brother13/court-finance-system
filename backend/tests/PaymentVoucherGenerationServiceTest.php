<?php

require_once __DIR__ . '/../app/finance/service/casefund/policy/CaseFundBusinessPolicy.php';
require_once __DIR__ . '/../app/finance/service/casefund/voucher/PaymentVoucherGenerationService.php';

use app\finance\service\casefund\policy\CaseFundBusinessPolicy;
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

    public function groupPaymentRowsForVoucher($rows, $byDayFlag, $byMethodFlag)
    {
        $this->receivedFlags = [$byDayFlag, $byMethodFlag];
        $groups = [];
        foreach ($rows as $row) {
            $key = $byDayFlag
                ? $row['payment_date'] . '|' . ($byMethodFlag ? $row['payment_method'] : 'ALL')
                : $row['payment_id'] . '|ONE';
            $groups[$key][] = $row;
        }
        return $groups;
    }
}

function paymentServiceAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
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
    new PaymentVoucherGenerationService($policy, $resolver, $manager, $rule, [], 'payment', []);
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

$source = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/PaymentVoucherGenerationService.php');
foreach (['requirePermission', 'Db::startTrans', 'Db::commit', 'Db::rollback', 'logAudit'] as $forbidden) {
    if (strpos($source, $forbidden) !== false) {
        fwrite(STDERR, 'Payment voucher service should not own boundary concern: ' . $forbidden . PHP_EOL);
        exit(1);
    }
}

echo 'Payment voucher generation service test passed' . PHP_EOL;
