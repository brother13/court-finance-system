<?php
require __DIR__ . '/CaseFundVoucherRuleTest.php';

$originalAux = [['aux_type_code' => 'case_no', 'aux_value' => '原缴款人｜原银行备注｜BMK-F35DB825', 'aux_label' => '原不明款']];
$probe->setSubjectAuxConfigs([
    '20030108' => [['aux_type_code' => 'case_no', 'required_flag' => 1, 'verification_flag' => 0]],
    '20030107' => [['aux_type_code' => 'case_no', 'required_flag' => 1, 'verification_flag' => 0]],
]);
$rule = $probe->ruleService();
$property = new ReflectionProperty($rule, 'hooks');
$property->setAccessible(true);
$hooks = $property->getValue($rule);
$calls = 0;
$hooks['resolve_unknown_claim_aux'] = function ($row, $subject) use ($originalAux, &$calls) {
    voucherRuleAssertSame('20030108', $subject, 'Resolve the configured debit subject');
    $calls++;
    return $originalAux;
};
$property->setValue($rule, $hooks);
$row = ['payment_id' => 'claim-1', 'case_no' => '（2023）辽0106执5065号', 'receipt_no' => '0001779625', 'business_type' => '执行、调解款', 'payment_amount' => '9503.33', 'payment_method' => 'UNKNOWN', 'party_name' => '当事人'];
foreach ([false, true] as $byMethod) {
    $result = $rule->buildPaymentVoucherDetailsData([$row], ['缴费登记' => ['debit_subject_code' => '20030108', 'credit_subject_code' => '20030107']], ['UNKNOWN' => ['debit_subject_code' => '20030108']], $byMethod, 'CASE_FUND');
    voucherRuleAssertSame($originalAux, $result['details'][0]['aux_values'], 'Unknown claim debit must reuse original auxiliary identity and label');
    voucherRuleAssertSame($row['case_no'], $result['details'][1]['aux_values'][0]['aux_value'], 'Claim credit must retain the new case number');
    voucherRuleAssertSame('9503.33', $result['details'][0]['debit_amount'], 'Claim amount remains unchanged');
}
voucherRuleAssertSame(2, $calls, 'Both generation modes must resolve original auxiliary values');
echo "Unknown claim auxiliary regression passed\n";

$probe->setSubjectAuxConfigs(['20030108' => [['aux_type_code' => 'receipt_no', 'required_flag' => 1, 'verification_flag' => 0]]]);
try {
    $rule->buildPaymentVoucherDetailsData([$row], ['缴费登记' => ['debit_subject_code' => '20030108', 'credit_subject_code' => '20030107']], [], false, 'CASE_FUND');
    throw new Exception('Missing original dimension must fail');
} catch (Exception $e) {
    voucherRuleAssertSame('不明款认领原入账分录缺少辅助核算：receipt_no', $e->getMessage(), 'Never fill missing original dimension with claim receipt');
}
foreach (['payment_time', 'payer_name', 'bank_account_no'] as $field) {
    voucherRuleAssertSame(true, in_array($field, \app\finance\model\CaseFund::PAYMENT_VOUCHER_FIELD, true), 'Worker must load claim source field ' . $field);
}
