<?php

namespace app\finance\service\casefund\subject;

/**
 * 科目配置列表与保存用例编排；事务、接口返回和审计由调用方负责。
 */
class SubjectConfigApplicationService
{
    protected $policy;
    protected $resolver;
    protected $manager;
    protected $hooks;

    public function __construct($policy, $resolver, $manager, array $hooks)
    {
        if (!is_object($policy) || !is_object($resolver) || !is_object($manager)) {
            throw new \InvalidArgumentException('案款科目配置应用服务缺少协作对象');
        }
        if (!isset($hooks['account_set_id']) || !is_callable($hooks['account_set_id'])) {
            throw new \InvalidArgumentException('案款科目配置应用服务缺少回调：account_set_id');
        }
        $this->policy = $policy;
        $this->resolver = $resolver;
        $this->manager = $manager;
        $this->hooks = $hooks;
    }

    public function listConfig($bizType, $voucherBizType)
    {
        $businessItems = $this->policy->allowedSubjectConfigItems($bizType, $voucherBizType);
        if (empty($businessItems)) {
            return $this->errorResult('当前账套类型不支持该科目配置：' . $bizType);
        }
        $configs = $this->resolver->configsByBusinessItems($bizType, $voucherBizType, $businessItems);
        $subjectCodes = [];
        foreach ($configs as $config) {
            if (!empty($config['debit_subject_code'])) {
                $subjectCodes[] = $config['debit_subject_code'];
            }
            if (!empty($config['credit_subject_code'])) {
                $subjectCodes[] = $config['credit_subject_code'];
            }
        }
        $subjectNames = $this->manager->subjectNameMap($subjectCodes);
        $items = [];
        foreach ($businessItems as $businessItem) {
            $config = $configs[$businessItem] ?? [];
            $debitSubjectCode = $config['debit_subject_code'] ?? '';
            $creditSubjectCode = $config['credit_subject_code'] ?? '';
            $items[] = [
                'config_id' => $config['config_id'] ?? '',
                'account_set_id' => $this->accountSetId(),
                'biz_type' => $bizType,
                'voucher_biz_type' => $voucherBizType,
                'business_item_type' => $businessItem,
                'debit_subject_code' => $debitSubjectCode,
                'debit_subject_name' => $subjectNames[$debitSubjectCode] ?? '',
                'credit_subject_code' => $creditSubjectCode,
                'credit_subject_name' => $subjectNames[$creditSubjectCode] ?? '',
            ];
        }
        return [
            'error' => '',
            'total' => count($items),
            'data' => [
                'items' => $items,
                'biz_type' => $bizType,
                'voucher_biz_type' => $voucherBizType,
                'generate_voucher_by_day_flag' => $this->manager->subjectConfigAccountSetFlag(),
                'generate_payment_voucher_by_method_flag' => $this->manager->generatePaymentVoucherByMethodFlag(),
                'generate_refund_voucher_by_out_order_no_flag' => $this->manager->generateRefundVoucherByOutOrderNoFlag(),
                'payment_method_items' => $voucherBizType === 'PAYMENT'
                    ? $this->manager->paymentMethodSubjectConfigItems($bizType, $items)
                    : [],
            ],
        ];
    }

    public function prepareSave($data, $bizType, $voucherBizType)
    {
        $allowedItems = $this->policy->allowedSubjectConfigItems($bizType, $voucherBizType);
        if (empty($allowedItems)) {
            return $this->errorResult('当前账套类型不支持该科目配置：' . $bizType);
        }
        $items = $data['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            return $this->errorResult('科目配置明细不能为空');
        }
        return [
            'error' => '',
            'biz_type' => $bizType,
            'voucher_biz_type' => $voucherBizType,
            'allowed_items' => $allowedItems,
            'items' => $items,
            'payment_method_items' => $data['payment_method_items'] ?? ($data['paymentMethodItems'] ?? []),
            'generate_voucher_by_day_flag' => $this->normalizeFlag(
                $data['generate_voucher_by_day_flag']
                ?? ($data['generateVoucherByDayFlag'] ?? $this->manager->subjectConfigAccountSetFlag())
            ),
            'generate_refund_voucher_by_out_order_no_flag' => $this->normalizeFlag(
                $data['generate_refund_voucher_by_out_order_no_flag']
                ?? ($data['generateRefundVoucherByOutOrderNoFlag'] ?? $this->manager->generateRefundVoucherByOutOrderNoFlag())
            ),
            'generate_payment_voucher_by_method_flag' => $this->normalizeFlag(
                $data['generate_payment_voucher_by_method_flag']
                ?? ($data['generatePaymentVoucherByMethodFlag'] ?? $this->manager->generatePaymentVoucherByMethodFlag())
            ),
        ];
    }

    public function savePrepared($prepared)
    {
        list($accountSetBefore, $accountSetAfter) = $this->manager->saveSubjectConfigAccountSetFlag(
            $prepared['generate_voucher_by_day_flag'],
            $prepared['generate_refund_voucher_by_out_order_no_flag'],
            $prepared['generate_payment_voucher_by_method_flag']
        );
        $saveResult = $this->manager->saveBusinessSubjectConfigs(
            $prepared['biz_type'],
            $prepared['voucher_biz_type'],
            $prepared['items'],
            $prepared['allowed_items']
        );
        $before = $saveResult['before'];
        $after = $saveResult['after'];
        if ($prepared['voucher_biz_type'] === 'PAYMENT') {
            $methodSaveResult = $this->manager->savePaymentMethodSubjectConfigs(
                $prepared['biz_type'],
                $prepared['payment_method_items'],
                $prepared['items']
            );
            $before = array_merge($before, $methodSaveResult['before']);
            $after = array_merge($after, $methodSaveResult['after']);
        }
        return [
            'account_set_before' => $accountSetBefore,
            'account_set_after' => $accountSetAfter,
            'before' => $before,
            'after' => $after,
            'saved' => count($after),
        ];
    }

    protected function errorResult($message)
    {
        return ['error' => $message, 'data' => [], 'total' => 0];
    }

    protected function normalizeFlag($value)
    {
        return (int)$value === 0 ? 0 : 1;
    }

    protected function accountSetId()
    {
        return call_user_func($this->hooks['account_set_id']);
    }
}
