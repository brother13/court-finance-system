<?php

namespace app\finance\service\casefund\voucher;

/**
 * 案款业务凭证持久化服务。
 *
 * 调用方负责事务、凭证号、业务校验、辅助档案准备、源记录回写和审计；
 * 本服务在写入前统一复核基础凭证不变量，再依次写入凭证头、凭证明细和辅助核算值。
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
        $this->assertVoucherInvariant($context, $detailsData);

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
        $this->assertInserted(
            $this->db('fin_voucher')->insert($voucher),
            '凭证头写入失败'
        );

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
            $this->assertInserted(
                $this->db('fin_voucher_detail')->insert($detailRow),
                '凭证明细写入失败：' . $detail['detail_id']
            );

            foreach (($detail['aux_values'] ?? []) as $aux) {
                $auxTypeCode = trim((string)($aux['aux_type_code'] ?? ''));
                $auxValue = isset($aux['aux_value']) ? trim((string)$aux['aux_value']) : '';
                if ($auxTypeCode === '' || $auxValue === '') {
                    continue;
                }
                $auxLabel = isset($aux['aux_label']) ? trim((string)$aux['aux_label']) : '';
                $auxRow = [
                    'id' => $this->callHook('new_id'),
                    'account_set_id' => $accountSetId,
                    'fiscal_year' => $fiscalYear,
                    'period' => $period,
                    'voucher_id' => $voucherId,
                    'detail_id' => $detail['detail_id'],
                    'aux_type_code' => $auxTypeCode,
                    'aux_value' => $auxValue,
                    'aux_label' => $auxLabel !== '' ? $auxLabel : $auxValue,
                    'remark' => '',
                ];
                $auxRow = $this->callHook('prepare_create', [$auxRow]);
                $this->assertInserted(
                    $this->db('fin_voucher_aux_value')->insert($auxRow),
                    '凭证辅助核算值写入失败：' . $detail['detail_id'] . '/' . $auxTypeCode
                );
            }
        }
    }

    protected function assertVoucherInvariant(array $context, array $detailsData)
    {
        $totalCents = $this->integerCents($context['total_cents'], '凭证金额');
        if ($totalCents <= 0) {
            throw new \InvalidArgumentException('凭证金额必须大于0');
        }
        if (count($detailsData) < 2) {
            throw new \InvalidArgumentException('凭证明细至少需要两行');
        }

        $detailIds = [];
        $lineNumbers = [];
        $debitTotal = 0;
        $creditTotal = 0;
        $rowNo = 0;
        foreach ($detailsData as $detail) {
            $rowNo++;
            if (!is_array($detail)) {
                throw new \InvalidArgumentException('凭证明细第' . $rowNo . '行格式不正确');
            }
            foreach ([
                'detail_id',
                'line_no',
                'subject_code',
                'summary',
                'debit_amount',
                'credit_amount',
                'verification_status',
                'aux_desc',
            ] as $field) {
                if (!array_key_exists($field, $detail)) {
                    throw new \InvalidArgumentException('凭证明细第' . $rowNo . '行缺少字段：' . $field);
                }
            }

            $detailId = trim((string)$detail['detail_id']);
            if ($detailId === '') {
                throw new \InvalidArgumentException('凭证明细第' . $rowNo . '行ID不能为空');
            }
            if (isset($detailIds[$detailId])) {
                throw new \InvalidArgumentException('凭证明细ID重复：' . $detailId);
            }
            $detailIds[$detailId] = true;

            $lineNo = $this->positiveInteger($detail['line_no'], '凭证明细第' . $rowNo . '行行号');
            if (isset($lineNumbers[$lineNo])) {
                throw new \InvalidArgumentException('凭证明细行号重复：' . $lineNo);
            }
            $lineNumbers[$lineNo] = true;

            if (trim((string)$detail['subject_code']) === '') {
                throw new \InvalidArgumentException('凭证明细第' . $rowNo . '行科目不能为空');
            }
            if (trim((string)$detail['summary']) === '') {
                throw new \InvalidArgumentException('凭证明细第' . $rowNo . '行摘要不能为空');
            }

            $debitCents = $this->decimalAmountToCents($detail['debit_amount'], '凭证明细第' . $rowNo . '行借方金额');
            $creditCents = $this->decimalAmountToCents($detail['credit_amount'], '凭证明细第' . $rowNo . '行贷方金额');
            if ($debitCents < 0 || $creditCents < 0) {
                throw new \InvalidArgumentException('凭证明细第' . $rowNo . '行金额不能为负数');
            }
            if (($debitCents > 0 && $creditCents > 0) || ($debitCents === 0 && $creditCents === 0)) {
                throw new \InvalidArgumentException('凭证明细第' . $rowNo . '行必须且只能填写借方或贷方金额');
            }
            $debitTotal += $debitCents;
            $creditTotal += $creditCents;

            $auxValues = $detail['aux_values'] ?? [];
            if (!is_array($auxValues)) {
                throw new \InvalidArgumentException('凭证明细第' . $rowNo . '行辅助核算值格式不正确');
            }
            $auxTypes = [];
            foreach ($auxValues as $aux) {
                if (!is_array($aux)) {
                    throw new \InvalidArgumentException('凭证明细第' . $rowNo . '行辅助核算值格式不正确');
                }
                $typeCode = trim((string)($aux['aux_type_code'] ?? ''));
                $auxValue = isset($aux['aux_value']) ? trim((string)$aux['aux_value']) : '';
                if ($typeCode === '' || $auxValue === '') {
                    continue;
                }
                if (isset($auxTypes[$typeCode])) {
                    throw new \InvalidArgumentException(
                        '凭证明细第' . $rowNo . '行辅助核算维度重复：' . $typeCode
                    );
                }
                $auxTypes[$typeCode] = true;
            }
        }

        if ($debitTotal !== $creditTotal) {
            throw new \InvalidArgumentException('凭证借贷金额不平衡');
        }
        if ($debitTotal !== $totalCents) {
            throw new \InvalidArgumentException('凭证头金额与明细合计不一致');
        }
    }

    protected function integerCents($value, $label)
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException($label . '必须使用整数分');
        }
        $text = trim((string)$value);
        if ($text === '' || !preg_match('/^-?\d+$/', $text)) {
            throw new \InvalidArgumentException($label . '必须使用整数分');
        }
        $unsigned = ltrim($text, '-0');
        $unsigned = $unsigned === '' ? '0' : $unsigned;
        $max = (string)PHP_INT_MAX;
        if (strlen($unsigned) > strlen($max) || (strlen($unsigned) === strlen($max) && strcmp($unsigned, $max) > 0)) {
            throw new \InvalidArgumentException($label . '超出整数范围');
        }
        return (int)$text;
    }

    protected function positiveInteger($value, $label)
    {
        if (!is_int($value) && !is_string($value)) {
            throw new \InvalidArgumentException($label . '必须为正整数');
        }
        $text = trim((string)$value);
        if ($text === '' || !preg_match('/^\d+$/', $text)) {
            throw new \InvalidArgumentException($label . '必须为正整数');
        }
        $unsigned = ltrim($text, '0');
        if ($unsigned === '') {
            throw new \InvalidArgumentException($label . '必须为正整数');
        }
        $max = (string)PHP_INT_MAX;
        if (strlen($unsigned) > strlen($max) || (strlen($unsigned) === strlen($max) && strcmp($unsigned, $max) > 0)) {
            throw new \InvalidArgumentException($label . '超出整数范围');
        }
        return (int)$unsigned;
    }

    protected function decimalAmountToCents($value, $label)
    {
        if (!is_int($value) && !is_string($value)) {
            throw new \InvalidArgumentException($label . '格式不正确');
        }
        $text = trim((string)$value);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $text, $matches)) {
            throw new \InvalidArgumentException($label . '格式不正确');
        }
        $fraction = str_pad($matches[3] ?? '', 2, '0');
        $centsText = ltrim($matches[2] . $fraction, '0');
        $centsText = $centsText === '' ? '0' : $centsText;
        $max = (string)PHP_INT_MAX;
        if (strlen($centsText) > strlen($max) || (strlen($centsText) === strlen($max) && strcmp($centsText, $max) > 0)) {
            throw new \InvalidArgumentException($label . '超出整数分范围');
        }
        $cents = (int)$centsText;
        return ($matches[1] ?? '') === '-' ? -$cents : $cents;
    }

    protected function assertInserted($affectedRows, $message)
    {
        if ($affectedRows !== 1) {
            throw new \RuntimeException($message);
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
