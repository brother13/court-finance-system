<?php
require __DIR__ . '/../app/finance/service/casefund/voucher/UnknownClaimAuxiliaryResolver.php';
use app\finance\service\casefund\voucher\UnknownClaimAuxiliaryResolver;
class ClaimAuxQuery
{
    private $rows;
    private $where = [];
    private $fields;
    public function __construct($rows) { $this->rows = $rows; }
    public function where($where) { $this->where = $where; return $this; }
    public function lock($lock) { return $this; }
    public function field($fields) { $this->fields = explode(',', $fields); return $this; }
    public function select() {
        $result = [];
        foreach ($this->rows as $row) {
            foreach ($this->where as $key => $value) {
                if (!isset($row[$key]) || (string)$row[$key] !== (string)$value) { continue 2; }
            }
            $result[] = $this->fields ? array_intersect_key($row, array_flip($this->fields)) : $row;
        }
        return $result;
    }
    public function find() { return $this->select()[0] ?? null; }
}
$scope = ['account_set_id' => 'set-a', 'del_flag' => 0];
$voucherScope = $scope + ['voucher_id' => 'v1', 'fiscal_year' => 2025, 'period' => '2025-12'];
$source = $scope + ['unknown_payment_id' => 'u1', 'payer_name' => '原缴款人', 'unknown_amount' => '9503.33', 'transaction_date' => '2025-12-31', 'transaction_time' => '2025-12-31 13:34:58', 'court_bank_account_no' => '12345', 'aux_case_code' => 'BMK-F35DB825', 'voucher_id' => 'v1', 'voucher_period' => '2025-12'];
$aux = ['aux_type_code' => 'case_no', 'aux_value' => '原缴款人｜原备注｜BMK-F35DB825', 'aux_label' => '原标签'];
$tables = [
    'fin_case_fund_unknown_payment' => [$source],
    'fin_voucher' => [$voucherScope + ['status' => 'SUBMITTED']],
    'fin_voucher_detail' => [$voucherScope + ['detail_id' => 'd1', 'subject_code' => '20030108', 'credit_amount' => '9503.33']],
    'fin_voucher_aux_value' => [$voucherScope + ['detail_id' => 'd1'] + $aux],
];
$baseline = $tables;
$resolver = new UnknownClaimAuxiliaryResolver(function ($table) use (&$tables) { return new ClaimAuxQuery($tables[$table]); }, 'set-a');
$payment = ['payment_id' => 'p1', 'receipt_no' => 'r1', 'payer_name' => '原缴款人', 'payment_time' => '2025-12-31 13:34:58', 'payment_amount' => '9503.33', 'bank_account_no' => '12345-231297'];
function claimAssert($condition, $message) { if (!$condition) { throw new Exception($message); } }
function claimReject($callback, $message) {
    try { $callback(); } catch (Exception $e) { claimAssert(strpos($e->getMessage(), $message) !== false, $e->getMessage()); return; }
    throw new Exception('Expected rejection: ' . $message);
}
claimAssert($resolver->resolve($payment, '20030108') === [$aux], 'Reuse exact historical value and label across years');
// 隔离其他账套、软删除来源和凭证行。
$tables['fin_case_fund_unknown_payment'][] = array_replace($source, ['account_set_id' => 'set-b']);
$tables['fin_case_fund_unknown_payment'][] = array_replace($source, ['del_flag' => 1]);
claimAssert($resolver->resolve($payment, '20030108') === [$aux], 'Ignore other accounts and deleted records');
$tables['fin_case_fund_unknown_payment'][] = $source;
claimReject(function () use ($resolver, $payment) { $resolver->resolve($payment, '20030108'); }, '匹配到2条');
$tables = $baseline;
claimReject(function () use ($resolver, $payment) { $resolver->resolve(array_replace($payment, ['payment_time' => '2025-12-31 00:00:00']), '20030108'); }, '匹配到0条');
claimReject(function () use ($resolver, $payment) { $resolver->resolve(array_replace($payment, ['payer_name' => '']), '20030108'); }, '缺少');
claimReject(function () use ($resolver, $payment) { $resolver->resolve($payment, '22030108'); }, '无法唯一确定');
$tables['fin_voucher'][0]['status'] = 'VOIDED';
claimReject(function () use ($resolver, $payment) { $resolver->resolve($payment, '20030108'); }, '状态无效');
$tables = $baseline;
$tables['fin_voucher_aux_value'] = [];
claimReject(function () use ($resolver, $payment) { $resolver->resolve($payment, '20030108'); }, '无法唯一确定');
$tables = $baseline;
$tables['fin_voucher_detail'][] = array_replace($tables['fin_voucher_detail'][0], ['detail_id' => 'd2']);
$tables['fin_voucher_aux_value'][] = array_replace($tables['fin_voucher_aux_value'][0], ['detail_id' => 'd2', 'aux_value' => '他人｜BMK-AAAAAAAA']);
claimAssert($resolver->resolve($payment, '20030108') === [$aux], 'Daily voucher same-amount lines must be distinguished by original BMK');
echo "Unknown claim auxiliary resolver tests passed\n";
