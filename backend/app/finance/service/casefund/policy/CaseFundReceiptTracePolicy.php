<?php

namespace app\finance\service\casefund\policy;

use app\finance\service\casefund\subject\SubjectConfigResolver;

/**
 * 缴费票据查重与退付收据追溯策略。
 *
 * 两种判断语义不同：缴费只检查贷方科目，退付检查借贷双方科目。
 * 只有明确取得有效科目配置且确认相关科目均未挂收据号辅助核算时，才放宽导入校验。
 */
class CaseFundReceiptTracePolicy
{
    protected $businessPolicy;
    protected $subjectConfigResolver;
    protected $hooks;

    public function __construct(
        CaseFundBusinessPolicy $businessPolicy,
        SubjectConfigResolver $subjectConfigResolver,
        array $hooks
    ) {
        foreach (['get_db', 'account_set_id'] as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款收据追溯策略缺少回调：' . $name);
            }
        }
        $this->businessPolicy = $businessPolicy;
        $this->subjectConfigResolver = $subjectConfigResolver;
        $this->hooks = $hooks;
    }

    public function paymentImportRequiresReceiptNoDuplicateCheck($bizType)
    {
        $businessItems = $this->businessPolicy->allowedSubjectConfigItems($bizType, 'PAYMENT');
        if (empty($businessItems)) {
            return true;
        }

        $configs = $this->subjectConfigResolver->configsByBusinessItems(
            $bizType,
            'PAYMENT',
            $businessItems
        );
        if (empty($configs)) {
            return true;
        }

        $creditSubjectCodes = [];
        foreach ($businessItems as $businessItem) {
            $creditSubjectCode = trim((string)($configs[$businessItem]['credit_subject_code'] ?? ''));
            if ($creditSubjectCode !== '') {
                $creditSubjectCodes[] = $creditSubjectCode;
            }
        }
        $creditSubjectCodes = array_values(array_unique($creditSubjectCodes));
        if (empty($creditSubjectCodes)) {
            return true;
        }

        return $this->subjectsUseReceiptNo($creditSubjectCodes);
    }

    public function refundImportRequiresReceiptTrace($bizType, array $rows = [])
    {
        if ($bizType === 'LITIGATION_FEE') {
            return false;
        }
        if ($bizType !== 'CASE_FUND') {
            return true;
        }

        $configs = $this->refundSubjectConfigsForImport($bizType, $rows);
        if (empty($configs)) {
            return true;
        }

        $subjectCodes = [];
        foreach ($configs as $config) {
            if (!empty($config['debit_subject_code'])) {
                $subjectCodes[] = $config['debit_subject_code'];
            }
            if (!empty($config['credit_subject_code'])) {
                $subjectCodes[] = $config['credit_subject_code'];
            }
        }
        $subjectCodes = array_values(array_unique(array_filter($subjectCodes)));
        if (empty($subjectCodes)) {
            return true;
        }

        return $this->subjectsUseReceiptNo($subjectCodes);
    }

    protected function refundSubjectConfigsForImport($bizType, array $rows)
    {
        $businessItems = [];
        foreach ($rows as $row) {
            $outType = trim((string)($row['out_type'] ?? ''));
            if ($outType !== '') {
                $businessItems[] = $this->businessPolicy->voucherSubjectConfigKey(
                    $bizType,
                    'REFUND',
                    $outType
                );
            }
        }
        if (empty($businessItems)) {
            $businessItems = $this->businessPolicy->allowedSubjectConfigItems($bizType, 'REFUND');
        }
        if (empty($businessItems)) {
            return [];
        }

        return $this->subjectConfigResolver->orderedConfigsByBusinessItems(
            $bizType,
            'REFUND',
            $businessItems
        );
    }

    protected function subjectsUseReceiptNo(array $subjectCodes)
    {
        $exists = $this->db('fin_subject_aux_config')->where([
            'account_set_id' => $this->accountSetId(),
            'aux_type_code' => 'receipt_no',
            'del_flag' => 0,
        ])->where('subject_code', 'in', $subjectCodes)->find();
        return !empty($exists);
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
