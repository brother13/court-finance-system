<?php

namespace app\finance\service\casefund\subject;

/**
 * 案款科目配置校验、展示数据和持久化；事务和审计由调用方负责。
 */
class SubjectConfigManager
{
    protected $hooks;
    protected $subjectConfigTable;
    protected $voucherSubjectCache = [];
    protected $voucherSubjectHasChildCache = [];

    public function __construct(array $hooks, $subjectConfigTable)
    {
        foreach ([
            'get_db',
            'account_set_id',
            'new_id',
            'prepare_create',
            'prepare_update',
            'payment_method_options',
            'normalize_payment_method',
            'payment_method_config_map',
            'subject_name_map',
            'validate_subject_code',
            'normalize_flag',
        ] as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款科目配置管理服务缺少回调：' . $name);
            }
        }
        $this->hooks = $hooks;
        $this->subjectConfigTable = $subjectConfigTable;
    }

    public function saveBusinessSubjectConfigs($bizType, $voucherBizType, $items, $allowedItems)
    {
        $before = [];
        $after = [];
        $seen = [];
        foreach ($items as $item) {
            $businessItemType = trim($item['business_item_type'] ?? ($item['businessItemType'] ?? ''));
            $debitSubjectCode = trim($item['debit_subject_code'] ?? ($item['debitSubjectCode'] ?? ''));
            $creditSubjectCode = trim($item['credit_subject_code'] ?? ($item['creditSubjectCode'] ?? ''));
            if ($businessItemType === '') {
                throw new \Exception('业务种类不能为空');
            }
            if (isset($seen[$businessItemType])) {
                throw new \Exception('业务种类重复配置：' . $businessItemType);
            }
            $seen[$businessItemType] = true;
            if (!in_array($businessItemType, $allowedItems, true)) {
                throw new \Exception('当前账套不允许配置业务种类：' . $businessItemType);
            }
            $this->assertValidSubject($debitSubjectCode, '借方科目');
            $this->assertValidSubject($creditSubjectCode, '贷方科目');

            $where = [
                'account_set_id' => $this->accountSetId(),
                'biz_type' => $bizType,
                'voucher_biz_type' => $voucherBizType,
                'business_item_type' => $businessItemType,
            ];
            $existing = $this->db($this->subjectConfigTable)->where($where)->find();
            $save = [
                'debit_subject_code' => $debitSubjectCode,
                'credit_subject_code' => $creditSubjectCode,
                'remark' => $item['remark'] ?? '',
                'del_flag' => 0,
            ];
            if ($existing) {
                $before[] = $existing;
                $save = $this->callHook('prepare_update', [$save]);
                $this->db($this->subjectConfigTable)->where(['config_id' => $existing['config_id']])->update($save);
                $after[] = array_merge($existing, $save);
            } else {
                $insert = array_merge($where, $save);
                $insert['config_id'] = $this->callHook('new_id');
                $insert = $this->callHook('prepare_create', [$insert]);
                $this->db($this->subjectConfigTable)->insert($insert);
                $after[] = $insert;
            }
        }
        return ['before' => $before, 'after' => $after];
    }

    public function paymentMethodSubjectConfigItems($bizType, $paymentItems)
    {
        $defaultDebitSubjectCode = $this->firstPaymentDebitSubjectCode($paymentItems);
        $configs = $this->callHook('payment_method_config_map', [$bizType]);
        $subjectCodes = [];
        foreach ($configs as $row) {
            if (!empty($row['debit_subject_code'])) {
                $subjectCodes[] = $row['debit_subject_code'];
            }
        }
        if ($defaultDebitSubjectCode !== '') {
            $subjectCodes[] = $defaultDebitSubjectCode;
        }
        $subjectNames = $this->callHook('subject_name_map', [$subjectCodes]);

        $items = [];
        foreach ($this->callHook('payment_method_options') as $code => $label) {
            $config = $configs[$code] ?? [];
            $debitSubjectCode = $config['debit_subject_code'] ?? $defaultDebitSubjectCode;
            $items[] = [
                'config_id' => $config['config_id'] ?? '',
                'account_set_id' => $this->accountSetId(),
                'biz_type' => $bizType,
                'voucher_biz_type' => 'PAYMENT_METHOD',
                'business_item_type' => $code,
                'business_item_label' => $label,
                'debit_subject_code' => $debitSubjectCode,
                'debit_subject_name' => $subjectNames[$debitSubjectCode] ?? '',
                'credit_subject_code' => $config['credit_subject_code'] ?? $debitSubjectCode,
                'credit_subject_name' => '',
            ];
        }
        return $items;
    }

    public function savePaymentMethodSubjectConfigs($bizType, $methodItems, $paymentItems)
    {
        if (!is_array($methodItems)) {
            $methodItems = [];
        }
        $defaultDebitSubjectCode = $this->firstPaymentDebitSubjectCode($paymentItems);
        $options = $this->callHook('payment_method_options');
        $byMethod = [];
        foreach ($methodItems as $item) {
            $methodCode = $this->callHook('normalize_payment_method', [
                $item['business_item_type'] ?? ($item['businessItemType'] ?? ''),
            ]);
            if (isset($options[$methodCode])) {
                $byMethod[$methodCode] = $item;
            }
        }

        $before = [];
        $after = [];
        foreach ($options as $methodCode => $label) {
            $item = $byMethod[$methodCode] ?? [];
            $debitSubjectCode = trim($item['debit_subject_code'] ?? ($item['debitSubjectCode'] ?? $defaultDebitSubjectCode));
            $this->assertValidSubject($debitSubjectCode, $label . '借方科目');
            $where = [
                'account_set_id' => $this->accountSetId(),
                'biz_type' => $bizType,
                'voucher_biz_type' => 'PAYMENT_METHOD',
                'business_item_type' => $methodCode,
            ];
            $existing = $this->db($this->subjectConfigTable)->where($where)->find();
            $save = [
                'debit_subject_code' => $debitSubjectCode,
                'credit_subject_code' => $debitSubjectCode,
                'remark' => $item['remark'] ?? '',
                'del_flag' => 0,
            ];
            if ($existing) {
                $before[] = $existing;
                $save = $this->callHook('prepare_update', [$save]);
                $this->db($this->subjectConfigTable)->where(['config_id' => $existing['config_id']])->update($save);
                $after[] = array_merge($existing, $save);
            } else {
                $insert = array_merge($where, $save);
                $insert['config_id'] = $this->callHook('new_id');
                $insert = $this->callHook('prepare_create', [$insert]);
                $this->db($this->subjectConfigTable)->insert($insert);
                $after[] = $insert;
            }
        }
        return ['before' => $before, 'after' => $after];
    }

    public function validateVoucherSubjectCode($subjectCode, $label)
    {
        if ($subjectCode === '') {
            return $label . '不能为空';
        }
        if (!array_key_exists($subjectCode, $this->voucherSubjectCache)) {
            $this->voucherSubjectCache[$subjectCode] = $this->db('fin_subject')->where([
                'account_set_id' => $this->accountSetId(),
                'subject_code' => $subjectCode,
                'del_flag' => 0,
            ])->find() ?: null;
        }
        $subject = $this->voucherSubjectCache[$subjectCode];
        if (!$subject) {
            return $label . '不存在：' . $subjectCode;
        }
        if ((int)$subject['status'] !== 1) {
            return $label . '未启用：' . $subjectCode;
        }
        if (!array_key_exists($subjectCode, $this->voucherSubjectHasChildCache)) {
            $this->voucherSubjectHasChildCache[$subjectCode] = $this->db('fin_subject')->where([
                'account_set_id' => $this->accountSetId(),
                'parent_code' => $subjectCode,
                'del_flag' => 0,
            ])->count() > 0;
        }
        if ((int)$subject['leaf_flag'] !== 1 || $this->voucherSubjectHasChildCache[$subjectCode]) {
            return $label . '必须为末级科目：' . $subjectCode;
        }
        if (isset($subject['voucher_entry_flag']) && (int)$subject['voucher_entry_flag'] !== 1) {
            return $label . '不允许录入凭证：' . $subjectCode;
        }
        return null;
    }

    public function subjectNameMap($subjectCodes)
    {
        $subjectCodes = array_values(array_unique(array_filter($subjectCodes)));
        if (empty($subjectCodes)) {
            return [];
        }
        $rows = $this->db('fin_subject')->where([
            'account_set_id' => $this->accountSetId(),
            'del_flag' => 0,
        ])->where('subject_code', 'in', $subjectCodes)->field('subject_code,subject_name')->select();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['subject_code']] = $row['subject_name'];
        }
        return $map;
    }

    public function subjectConfigAccountSetFlag()
    {
        return $this->accountSetFlag('generate_voucher_by_day_flag', 1);
    }

    public function generateRefundVoucherByOutOrderNoFlag()
    {
        return $this->accountSetFlag('generate_refund_voucher_by_out_order_no_flag', 0);
    }

    public function generatePaymentVoucherByMethodFlag()
    {
        return $this->accountSetFlag('generate_payment_voucher_by_method_flag', 0);
    }

    public function skipConfirmedReturnVoucherFlag()
    {
        return $this->accountSetFlag('skip_confirmed_return_voucher_flag', 0);
    }

    public function saveSubjectConfigAccountSetFlag(
        $flag,
        $refundOutOrderFlag = null,
        $paymentMethodFlag = null,
        $skipConfirmedReturnVoucherFlag = null
    )
    {
        $before = $this->db('fin_account_set')->where([
            'account_set_id' => $this->accountSetId(),
            'del_flag' => 0,
        ])->field('account_set_id,generate_voucher_by_day_flag,generate_payment_voucher_by_method_flag,generate_refund_voucher_by_out_order_no_flag,skip_confirmed_return_voucher_flag')->find();
        if (!$before) {
            throw new \Exception('账套不存在');
        }
        $update = ['generate_voucher_by_day_flag' => $this->callHook('normalize_flag', [$flag])];
        if ($refundOutOrderFlag !== null) {
            $update['generate_refund_voucher_by_out_order_no_flag'] = $this->callHook('normalize_flag', [$refundOutOrderFlag]);
        }
        if ($paymentMethodFlag !== null) {
            $update['generate_payment_voucher_by_method_flag'] = $this->callHook('normalize_flag', [$paymentMethodFlag]);
        }
        if ($skipConfirmedReturnVoucherFlag !== null) {
            $update['skip_confirmed_return_voucher_flag'] = $this->callHook('normalize_flag', [$skipConfirmedReturnVoucherFlag]);
        }
        $update = $this->callHook('prepare_update', [$update]);
        $this->db('fin_account_set')->where([
            'account_set_id' => $this->accountSetId(),
            'del_flag' => 0,
        ])->update($update);
        return [$before, array_merge($before, $update)];
    }

    protected function accountSetFlag($field, $default)
    {
        $row = $this->db('fin_account_set')->where([
            'account_set_id' => $this->accountSetId(),
            'del_flag' => 0,
        ])->field($field)->find();
        if (!$row || !array_key_exists($field, $row)) {
            return $default;
        }
        return (int)$row[$field] === 0 ? 0 : 1;
    }

    protected function firstPaymentDebitSubjectCode($paymentItems)
    {
        foreach ($paymentItems as $item) {
            $subjectCode = trim($item['debit_subject_code'] ?? ($item['debitSubjectCode'] ?? ''));
            if ($subjectCode !== '') {
                return $subjectCode;
            }
        }
        return '';
    }

    protected function assertValidSubject($subjectCode, $label)
    {
        $error = $this->callHook('validate_subject_code', [$subjectCode, $label]);
        if ($error !== null) {
            throw new \Exception($error);
        }
    }

    protected function db($table)
    {
        return $this->callHook('get_db', [$table]);
    }

    protected function accountSetId()
    {
        return $this->callHook('account_set_id');
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
