<?php

namespace app\finance\service\casefund\voucher;

/**
 * 案款业务凭证持久化服务。
 *
 * 调用方负责事务、凭证号、业务校验、借贷平衡、辅助档案准备、源记录回写和审计；
 * 本服务只按统一结构依次写入凭证头、凭证明细和辅助核算值。
 */
class CaseFundVoucherPersistenceService
{
    protected $hooks;

    public function __construct(array $hooks)
    {
        $requiredHooks = [
            'get_db',
            'account_set_id',
            'cents_to_decimal',
            'new_id',
            'prepare_create',
            'current_user_id',
            'now',
        ];
        foreach ($requiredHooks as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款凭证持久化服务缺少回调：' . $name);
            }
        }
        $this->hooks = $hooks;
    }

    /**
     * 调用方必须在数据库事务内执行。
     *
     * $context 必填：voucher_id、fiscal_year、period、voucher_date、voucher_no、summary、total_cents。
     */
    public function persistVoucher(array $context, array $detailsData)
    {
        foreach (['voucher_id', 'fiscal_year', 'period', 'voucher_date', 'voucher_no', 'summary', 'total_cents'] as $field) {
            if (!array_key_exists($field, $context)) {
                throw new \InvalidArgumentException('案款凭证持久化缺少字段：' . $field);
            }
        }

        $accountSetId = $this->callHook('account_set_id');
        $voucherId = $context['voucher_id'];
        $fiscalYear = $context['fiscal_year'];
        $period = $context['period'];
        $totalAmount = $this->callHook('cents_to_decimal', [$context['total_cents']]);

        $voucher = [
            'voucher_id' => $voucherId,
            'account_set_id' => $accountSetId,
            'fiscal_year' => $fiscalYear,
            'period' => $period,
            'voucher_date' => $context['voucher_date'],
            'voucher_word' => '记',
            'voucher_no' => $context['voucher_no'],
            'summary' => $context['summary'],
            'debit_amount' => $totalAmount,
            'credit_amount' => $totalAmount,
            'attachment_count' => 0,
            'status' => 'SUBMITTED',
            'source_type' => 'BUSINESS',
            'printed_flag' => '0',
            'prepared_by' => $this->callHook('current_user_id'),
            'prepared_time' => $this->callHook('now'),
            'audit_by' => null,
            'audit_time' => null,
            'posted_by' => null,
            'posted_time' => null,
            'void_flag' => '0',
            'remark' => '',
        ];
        $voucher = $this->callHook('prepare_create', [$voucher]);
        $this->db('fin_voucher')->insert($voucher);

        foreach ($detailsData as $detail) {
            $detailRow = [
                'detail_id' => $detail['detail_id'],
                'account_set_id' => $accountSetId,
                'fiscal_year' => $fiscalYear,
                'period' => $period,
                'voucher_id' => $voucherId,
                'line_no' => $detail['line_no'],
                'subject_code' => $detail['subject_code'],
                'summary' => $detail['summary'],
                'debit_amount' => $detail['debit_amount'],
                'credit_amount' => $detail['credit_amount'],
                'verification_status' => $detail['verification_status'],
                'aux_desc' => $detail['aux_desc'],
                'remark' => '',
            ];
            $detailRow = $this->callHook('prepare_create', [$detailRow]);
            $this->db('fin_voucher_detail')->insert($detailRow);

            foreach (($detail['aux_values'] ?? []) as $aux) {
                if (empty($aux['aux_type_code']) || !isset($aux['aux_value']) || $aux['aux_value'] === '') {
                    continue;
                }
                $auxRow = [
                    'id' => $this->callHook('new_id'),
                    'account_set_id' => $accountSetId,
                    'fiscal_year' => $fiscalYear,
                    'period' => $period,
                    'voucher_id' => $voucherId,
                    'detail_id' => $detail['detail_id'],
                    'aux_type_code' => $aux['aux_type_code'],
                    'aux_value' => $aux['aux_value'],
                    'aux_label' => $aux['aux_label'] ?? $aux['aux_value'],
                    'remark' => '',
                ];
                $auxRow = $this->callHook('prepare_create', [$auxRow]);
                $this->db('fin_voucher_aux_value')->insert($auxRow);
            }
        }
    }

    protected function db($table)
    {
        return $this->callHook('get_db', [$table]);
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
