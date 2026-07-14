<?php

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\service\casefund\subject\SubjectConfigApplicationService;

function subjectApplicationAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
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

class SubjectApplicationPolicyFake
{
    public $allowed = [];

    public function allowedSubjectConfigItems($bizType, $voucherBizType)
    {
        return $this->allowed[$bizType . ':' . $voucherBizType] ?? [];
    }
}

class SubjectApplicationResolverFake
{
    public $configs = [];
    public $calls = [];

    public function configsByBusinessItems($bizType, $voucherBizType, $items)
    {
        $this->calls[] = [$bizType, $voucherBizType, $items];
        return $this->configs;
    }
}

class SubjectApplicationManagerFake
{
    public $subjectNames = [];
    public $methodItems = [];
    public $methodItemCalls = [];
    public $savedFlags = [];
    public $businessSaveCalls = [];
    public $methodSaveCalls = [];
    public $dailyFlag = 1;
    public $paymentMethodFlag = 0;
    public $refundOutOrderFlag = 0;

    public function subjectNameMap($codes)
    {
        return $this->subjectNames;
    }

    public function subjectConfigAccountSetFlag()
    {
        return $this->dailyFlag;
    }

    public function generatePaymentVoucherByMethodFlag()
    {
        return $this->paymentMethodFlag;
    }

    public function generateRefundVoucherByOutOrderNoFlag()
    {
        return $this->refundOutOrderFlag;
    }

    public function paymentMethodSubjectConfigItems($bizType, $items)
    {
        $this->methodItemCalls[] = [$bizType, $items];
        return $this->methodItems;
    }

    public function saveSubjectConfigAccountSetFlag($dailyFlag, $refundFlag, $paymentMethodFlag)
    {
        $this->savedFlags[] = [$dailyFlag, $refundFlag, $paymentMethodFlag];
        return [
            ['account_set_id' => 'account-subject-app', 'generate_voucher_by_day_flag' => 1],
            ['account_set_id' => 'account-subject-app', 'generate_voucher_by_day_flag' => $dailyFlag],
        ];
    }

    public function saveBusinessSubjectConfigs($bizType, $voucherBizType, $items, $allowedItems)
    {
        $this->businessSaveCalls[] = [$bizType, $voucherBizType, $items, $allowedItems];
        return [
            'before' => [['config_id' => 'BUSINESS-BEFORE']],
            'after' => [['config_id' => 'BUSINESS-AFTER']],
        ];
    }

    public function savePaymentMethodSubjectConfigs($bizType, $methodItems, $paymentItems)
    {
        $this->methodSaveCalls[] = [$bizType, $methodItems, $paymentItems];
        return [
            'before' => [['config_id' => 'METHOD-BEFORE']],
            'after' => [['config_id' => 'METHOD-AFTER']],
        ];
    }
}

try {
    new SubjectConfigApplicationService(null, null, null, []);
    fwrite(STDERR, 'Subject config application service should reject missing collaborators' . PHP_EOL);
    exit(1);
} catch (\InvalidArgumentException $e) {
    subjectApplicationAssertSame('案款科目配置应用服务缺少协作对象', $e->getMessage(), 'Missing collaborators should have a stable error');
}

$policy = new SubjectApplicationPolicyFake();
$resolver = new SubjectApplicationResolverFake();
$manager = new SubjectApplicationManagerFake();
try {
    new SubjectConfigApplicationService($policy, $resolver, $manager, []);
    fwrite(STDERR, 'Subject config application service should reject a missing account-set callback' . PHP_EOL);
    exit(1);
} catch (\InvalidArgumentException $e) {
    subjectApplicationAssertSame('案款科目配置应用服务缺少回调：account_set_id', $e->getMessage(), 'Missing account callback should have a stable error');
}

$policy->allowed = [
    'CASE_FUND:PAYMENT' => ['缴费登记'],
    'CASE_FUND:REFUND' => ['出账查询'],
];
$resolver->configs = [
    '缴费登记' => [
        'config_id' => 'CONFIG-1',
        'debit_subject_code' => '100201',
        'credit_subject_code' => '224101',
    ],
];
$manager->subjectNames = ['100201' => '银行存款', '224101' => '执行案款'];
$manager->methodItems = [['business_item_type' => 'POS', 'debit_subject_code' => '100201']];
$manager->paymentMethodFlag = 1;
$manager->refundOutOrderFlag = 1;
$service = new SubjectConfigApplicationService($policy, $resolver, $manager, [
    'account_set_id' => function () {
        return 'account-subject-app';
    },
]);

$unsupported = $service->listConfig('CANTEEN', 'PAYMENT');
subjectApplicationAssertSame('当前账套类型不支持该科目配置：CANTEEN', $unsupported['error'], 'Unsupported account-set config should be rejected before querying configs');
subjectApplicationAssertSame([], $resolver->calls, 'Unsupported config should not query the resolver');

$list = $service->listConfig('CASE_FUND', 'PAYMENT');
subjectApplicationAssertSame('', $list['error'], 'Supported config should return without error');
subjectApplicationAssertSame(1, $list['total'], 'List total should match canonical business items');
subjectApplicationAssertSame([
    'config_id' => 'CONFIG-1',
    'account_set_id' => 'account-subject-app',
    'biz_type' => 'CASE_FUND',
    'voucher_biz_type' => 'PAYMENT',
    'business_item_type' => '缴费登记',
    'debit_subject_code' => '100201',
    'debit_subject_name' => '银行存款',
    'credit_subject_code' => '224101',
    'credit_subject_name' => '执行案款',
], $list['data']['items'][0], 'List orchestration should preserve configured subjects and names');
subjectApplicationAssertSame(1, $list['data']['generate_voucher_by_day_flag'], 'List should include daily voucher flag');
subjectApplicationAssertSame(1, $list['data']['generate_payment_voucher_by_method_flag'], 'List should include payment-method flag');
subjectApplicationAssertSame(1, $list['data']['generate_refund_voucher_by_out_order_no_flag'], 'List should include refund grouping flag');
subjectApplicationAssertSame($manager->methodItems, $list['data']['payment_method_items'], 'Payment list should include payment-method subject items');
subjectApplicationAssertSame(1, count($manager->methodItemCalls), 'Payment-method items should be built once for payment config');

$manager->methodItemCalls = [];
$resolver->configs = [];
$refundList = $service->listConfig('CASE_FUND', 'REFUND');
subjectApplicationAssertSame([], $refundList['data']['payment_method_items'], 'Refund config should not expose payment-method subject items');
subjectApplicationAssertSame([], $manager->methodItemCalls, 'Refund config should not invoke payment-method item construction');

$emptySave = $service->prepareSave([], 'CASE_FUND', 'PAYMENT');
subjectApplicationAssertSame('科目配置明细不能为空', $emptySave['error'], 'Empty subject config details should be rejected before transaction');
$unsupportedSave = $service->prepareSave(['items' => [['business_item_type' => '缴费登记']]], 'CANTEEN', 'PAYMENT');
subjectApplicationAssertSame('当前账套类型不支持该科目配置：CANTEEN', $unsupportedSave['error'], 'Unsupported save should keep the existing account-set error');

$paymentItems = [[
    'business_item_type' => '缴费登记',
    'debit_subject_code' => '100201',
    'credit_subject_code' => '224101',
]];
$methodItems = [['business_item_type' => 'POS', 'debit_subject_code' => '100201']];
$prepared = $service->prepareSave([
    'items' => $paymentItems,
    'paymentMethodItems' => $methodItems,
    'generateVoucherByDayFlag' => 0,
    'generateRefundVoucherByOutOrderNoFlag' => 2,
    'generatePaymentVoucherByMethodFlag' => '0',
], 'CASE_FUND', 'PAYMENT');
subjectApplicationAssertSame('', $prepared['error'], 'Valid save input should be prepared');
subjectApplicationAssertSame(['缴费登记'], $prepared['allowed_items'], 'Prepared save should keep canonical allowed items');
subjectApplicationAssertSame(0, $prepared['generate_voucher_by_day_flag'], 'Daily flag should normalize zero');
subjectApplicationAssertSame(1, $prepared['generate_refund_voucher_by_out_order_no_flag'], 'Refund flag should normalize non-zero to one');
subjectApplicationAssertSame(0, $prepared['generate_payment_voucher_by_method_flag'], 'Payment-method flag should normalize string zero');

$saved = $service->savePrepared($prepared);
subjectApplicationAssertSame([[0, 1, 0]], $manager->savedFlags, 'Prepared flags should be saved in the legacy argument order');
subjectApplicationAssertSame([
    ['CASE_FUND', 'PAYMENT', $paymentItems, ['缴费登记']],
], $manager->businessSaveCalls, 'Business config save should preserve canonical allowed-item validation');
subjectApplicationAssertSame([
    ['CASE_FUND', $methodItems, $paymentItems],
], $manager->methodSaveCalls, 'Payment save should preserve payment-method config routing');
subjectApplicationAssertSame([
    ['config_id' => 'BUSINESS-BEFORE'],
    ['config_id' => 'METHOD-BEFORE'],
], $saved['before'], 'Save audit before-state should merge business and payment-method configs');
subjectApplicationAssertSame([
    ['config_id' => 'BUSINESS-AFTER'],
    ['config_id' => 'METHOD-AFTER'],
], $saved['after'], 'Save audit after-state should merge business and payment-method configs');
subjectApplicationAssertSame(2, $saved['saved'], 'Saved count should include business and payment-method configs');

$manager->methodSaveCalls = [];
$refundPrepared = $service->prepareSave([
    'items' => [['business_item_type' => '出账查询']],
], 'CASE_FUND', 'REFUND');
$refundSaved = $service->savePrepared($refundPrepared);
subjectApplicationAssertSame([], $manager->methodSaveCalls, 'Refund save should not persist payment-method configs');
subjectApplicationAssertSame(1, $refundSaved['saved'], 'Refund save count should only include business configs');

$applicationSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/subject/SubjectConfigApplicationService.php');
foreach (['Db::startTrans', 'Db::commit', 'Db::rollback', 'logAudit', 'requirePermission'] as $boundaryNeedle) {
    if (strpos($applicationSource, $boundaryNeedle) !== false) {
        fwrite(STDERR, 'Subject config application service should not own controller or transaction boundary: ' . $boundaryNeedle . PHP_EOL);
        exit(1);
    }
}

echo 'Subject config application service test passed' . PHP_EOL;
