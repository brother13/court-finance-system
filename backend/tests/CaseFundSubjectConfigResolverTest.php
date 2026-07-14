<?php

require_once __DIR__ . '/../app/finance/service/casefund/policy/CaseFundBusinessPolicy.php';
require_once __DIR__ . '/../app/finance/service/casefund/subject/SubjectConfigResolver.php';

use app\finance\service\casefund\policy\CaseFundBusinessPolicy;
use app\finance\service\casefund\subject\SubjectConfigResolver;

class SubjectConfigResolverFakeQuery
{
    public $whereCalls = [];
    public $fieldValue = null;
    private $rows;

    public function __construct($rows)
    {
        $this->rows = $rows;
    }

    public function where($field, $operator = null, $value = null)
    {
        $args = func_get_args();
        $this->whereCalls[] = $args;
        if (is_array($field)) {
            $conditions = $field;
            $this->rows = array_values(array_filter($this->rows, function ($row) use ($conditions) {
                foreach ($conditions as $key => $expected) {
                    if (!array_key_exists($key, $row) || $row[$key] != $expected) {
                        return false;
                    }
                }
                return true;
            }));
        } elseif ($operator === 'in' && is_array($value)) {
            $this->rows = array_values(array_filter($this->rows, function ($row) use ($field, $value) {
                return isset($row[$field]) && in_array($row[$field], $value, true);
            }));
        }
        return $this;
    }

    public function field($value)
    {
        $this->fieldValue = $value;
        return $this;
    }

    public function select()
    {
        return $this->rows;
    }
}

function resolverConfigRow($id, $accountSetId, $bizType, $voucherBizType, $businessItemType, $delFlag = 0)
{
    return [
        'config_id' => $id,
        'account_set_id' => $accountSetId,
        'biz_type' => $bizType,
        'voucher_biz_type' => $voucherBizType,
        'business_item_type' => $businessItemType,
        'debit_subject_code' => '100201',
        'credit_subject_code' => '220301',
        'del_flag' => $delFlag,
    ];
}

function newSubjectConfigResolver($rows, &$tables, &$queries)
{
    $tables = [];
    $queries = [];
    $factory = function ($table) use ($rows, &$tables, &$queries) {
        $tables[] = $table;
        $query = new SubjectConfigResolverFakeQuery($rows);
        $queries[] = $query;
        return $query;
    };
    return new SubjectConfigResolver(
        'account-set-1',
        $factory,
        new CaseFundBusinessPolicy(),
        ['config_id', 'account_set_id', 'biz_type', 'voucher_biz_type', 'business_item_type', 'debit_subject_code', 'credit_subject_code']
    );
}

function assertResolverSame($expected, $actual, $message)
{
    if ($actual !== $expected) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual:   ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$resolver = newSubjectConfigResolver([], $tables, $queries);
assertResolverSame([], $resolver->configsByBusinessItems('CASE_FUND', 'PAYMENT', []), 'An empty business-item list should resolve to an empty map');
assertResolverSame([], $tables, 'An empty business-item list should not execute a database query');

$rows = [
    resolverConfigRow('alias', 'account-set-1', 'CASE_FUND', 'PAYMENT', '执行、调解款'),
    resolverConfigRow('canonical', 'account-set-1', 'CASE_FUND', 'PAYMENT', '缴费登记'),
    resolverConfigRow('wrong-account', 'account-set-2', 'CASE_FUND', 'PAYMENT', '缴费登记'),
    resolverConfigRow('wrong-biz', 'account-set-1', 'LITIGATION_FEE', 'PAYMENT', '缴费登记'),
    resolverConfigRow('wrong-voucher-type', 'account-set-1', 'CASE_FUND', 'REFUND', '出账查询'),
    resolverConfigRow('deleted', 'account-set-1', 'CASE_FUND', 'PAYMENT', '缴费登记', 1),
];
$resolver = newSubjectConfigResolver($rows, $tables, $queries);
$map = $resolver->configsByBusinessItems('CASE_FUND', 'PAYMENT');
assertResolverSame(['缴费登记'], array_keys($map), 'Resolver should apply account-set, business, voucher-type and soft-delete isolation');
assertResolverSame('canonical', $map['缴费登记']['config_id'], 'Canonical subject config should override a legacy alias row');
assertResolverSame(['fin_case_fund_subject_config'], $tables, 'Resolver should query only the subject-config table');
assertResolverSame([
    'account_set_id' => 'account-set-1',
    'biz_type' => 'CASE_FUND',
    'voucher_biz_type' => 'PAYMENT',
    'del_flag' => 0,
], $queries[0]->whereCalls[0][0], 'Resolver should always apply the complete account-set isolation condition');
assertResolverSame(1, count($queries[0]->whereCalls), 'Null business items should read all configs without an IN condition');
assertResolverSame(
    ['config_id', 'account_set_id', 'biz_type', 'voucher_biz_type', 'business_item_type', 'debit_subject_code', 'credit_subject_code'],
    $queries[0]->fieldValue,
    'Resolver should preserve the injected subject-config field projection'
);

$canonical = resolverConfigRow('canonical-first', 'account-set-1', 'CASE_FUND', 'PAYMENT', '缴费登记');
$alias = resolverConfigRow('alias-second', 'account-set-1', 'CASE_FUND', 'PAYMENT', '执行、调解款');
$resolver = newSubjectConfigResolver([$canonical, $alias], $tables, $queries);
assertResolverSame(
    'canonical-first',
    $resolver->configsByBusinessItems('CASE_FUND', 'PAYMENT')['缴费登记']['config_id'],
    'A later alias row must not replace a canonical config row'
);
$resolver = newSubjectConfigResolver([$alias], $tables, $queries);
assertResolverSame(
    'alias-second',
    $resolver->configsByBusinessItems('CASE_FUND', 'PAYMENT')['缴费登记']['config_id'],
    'A legacy alias row should remain a fallback when no canonical config exists'
);

$litigationAlias = resolverConfigRow('litigation-alias', 'account-set-1', 'LITIGATION_FEE', 'PAYMENT', '诉讼费收款');
$resolver = newSubjectConfigResolver([$litigationAlias], $tables, $queries);
$map = $resolver->configsByBusinessItems('LITIGATION_FEE', 'PAYMENT', ['缴费登记']);
assertResolverSame('litigation-alias', $map['缴费登记']['config_id'], 'Resolver should use a valid legacy litigation-fee config alias as fallback');
assertResolverSame('诉讼费收款', $map['缴费登记']['business_item_type'], 'Resolver should preserve the raw alias value in the returned config row');
$lookupItems = $queries[0]->whereCalls[1][2];
foreach (['缴费登记', '诉讼费直接结算', '诉讼费预收', '预收诉讼费', '诉讼费收款'] as $lookupItem) {
    if (!in_array($lookupItem, $lookupItems, true)) {
        fwrite(STDERR, 'Resolver lookup should include subject-config alias: ' . $lookupItem . PHP_EOL);
        exit(1);
    }
}
if (in_array('执行、调节款', $lookupItems, true)) {
    fwrite(STDERR, 'Resolver lookup should not restore the deprecated typo alias' . PHP_EOL);
    exit(1);
}

$refundConfig = resolverConfigRow('refund', 'account-set-1', 'CASE_FUND', 'REFUND', '出账查询');
$resolver = newSubjectConfigResolver([$refundConfig], $tables, $queries);
$ordered = $resolver->orderedConfigsByBusinessItems('CASE_FUND', 'REFUND', ['出账查询', '出账查询']);
assertResolverSame(['refund', 'refund'], array_column($ordered, 'config_id'), 'Ordered resolver output should preserve the requested business-item order');

$customRows = [
    resolverConfigRow('config-a', 'account-set-1', 'CASE_FUND', 'CUSTOM', 'A'),
    resolverConfigRow('config-b', 'account-set-1', 'CASE_FUND', 'CUSTOM', 'B'),
];
$resolver = newSubjectConfigResolver($customRows, $tables, $queries);
$ordered = $resolver->orderedConfigsByBusinessItems('CASE_FUND', 'CUSTOM', ['B', 'MISSING', 'A']);
assertResolverSame(['config-b', 'config-a'], array_column($ordered, 'config_id'), 'Ordered resolver output should follow distinct requested keys and skip missing configs');

$dirtyDistributionRow = resolverConfigRow('dirty-distribution', 'account-set-1', 'CASE_FUND', 'DISTRIBUTION', ' 执行、调解款 ');
$resolver = newSubjectConfigResolver([$dirtyDistributionRow], $tables, $queries);
$directDistributionMap = $resolver->directConfigsByBusinessItems('CASE_FUND', 'DISTRIBUTION');
assertResolverSame([' 执行、调解款 '], array_keys($directDistributionMap), 'Direct resolver reads should preserve malformed raw config keys instead of broadening matching behavior');

$methodRows = [
    resolverConfigRow('pos', 'account-set-1', 'CASE_FUND', 'PAYMENT_METHOD', 'POS'),
    resolverConfigRow('unknown', 'account-set-1', 'CASE_FUND', 'PAYMENT_METHOD', 'UNKNOWN'),
    resolverConfigRow('cash', 'account-set-1', 'CASE_FUND', 'PAYMENT_METHOD', 'CASH'),
];
$resolver = newSubjectConfigResolver($methodRows, $tables, $queries);
$methodMap = $resolver->paymentMethodConfigMap('CASE_FUND');
assertResolverSame(['POS', 'UNKNOWN'], array_keys($methodMap), 'Payment-method config map should contain only real supported database configs');
assertResolverSame(['POS', 'TRANSFER', 'UNKNOWN'], $queries[0]->whereCalls[1][2], 'Payment-method lookup should use the exact supported method codes');

$duplicateRows = [
    resolverConfigRow('first', 'account-set-1', 'CASE_FUND', 'DISTRIBUTION', '执行、调解款'),
    resolverConfigRow('second', 'account-set-1', 'CASE_FUND', 'DISTRIBUTION', '执行、调解款'),
];
$resolver = newSubjectConfigResolver($duplicateRows, $tables, $queries);
$lastWins = $resolver->directConfigsByBusinessItems('CASE_FUND', 'DISTRIBUTION');
assertResolverSame('second', $lastWins['执行、调解款']['config_id'], 'Direct config map should preserve the existing last-row-wins behavior');

echo 'Case fund subject config resolver test passed' . PHP_EOL;
