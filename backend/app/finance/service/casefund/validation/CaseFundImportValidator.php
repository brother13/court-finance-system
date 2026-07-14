<?php

namespace app\finance\service\casefund\validation;

use app\finance\service\casefund\policy\CaseFundBusinessPolicy;

/**
 * 只处理无数据库副作用的导入行规则；票据重复、科目配置和重导覆盖由 CaseFund 编排。
 */
class CaseFundImportValidator
{
    protected $policy;
    protected $amountToCents;

    public function __construct(CaseFundBusinessPolicy $policy, callable $amountToCents)
    {
        $this->policy = $policy;
        $this->amountToCents = $amountToCents;
    }

    public function validatePaymentRows($rows, $bizType = '', $allowedBusinessTypes = null)
    {
        $errors = [];
        $allowedBusinessTypes = $allowedBusinessTypes === null
            ? $this->policy->allowedPaymentBusinessTypes($bizType)
            : $allowedBusinessTypes;
        foreach ($rows as $row) {
            $errors = array_merge(
                $errors,
                $this->paymentRowValidationErrors($row, $bizType, $allowedBusinessTypes)
            );
        }
        return $errors;
    }

    public function paymentRowValidationErrors($row, $bizType, $allowedBusinessTypes = null)
    {
        $errors = [];
        $allowedBusinessTypes = $allowedBusinessTypes === null
            ? $this->policy->allowedPaymentBusinessTypes($bizType)
            : $allowedBusinessTypes;
        $prefix = '第' . $row['source_row_no'] . '行：';
        if ($row['case_no'] === '') {
            $errors[] = $prefix . '案号不能为空';
        }
        if ($row['business_type'] === '') {
            $errors[] = $prefix . '业务类型不能为空';
        } elseif (empty($allowedBusinessTypes)) {
            $errors[] = $prefix . '当前账套类型不支持案款缴费登记：' . $bizType;
        } elseif (!in_array($row['business_type'], $allowedBusinessTypes, true)) {
            $errors[] = $prefix . '当前账套不允许导入业务类型【' . $row['business_type'] . '】，允许类型：' . implode('、', $allowedBusinessTypes);
        }
        if ($row['payer_name'] === '' && $row['party_name'] === '') {
            $errors[] = $prefix . '缴费人和当事人不能同时为空';
        }
        if ($this->toCents($row['payment_amount']) <= 0) {
            $errors[] = $prefix . '金额必须大于0';
        }
        if ($row['payment_date'] === '') {
            $errors[] = $prefix . '缴费日期不能为空或格式不正确';
        }
        if (!$this->paymentRowHasTraceKey($row)) {
            $errors[] = $prefix . '票据号码、银行流水号、缴费单号、收款账号+金额至少填写一项用于追溯';
        }
        if ($this->isSuspiciousBankSerialNo($row['bank_serial_no'] ?? '')) {
            $errors[] = $prefix . '银行流水号格式异常，请将银行流水号列设置为文本后重新导入';
        }
        return $errors;
    }

    public function validateRefundRows($rows, $bizType = '', $receiptTraceRequired = true, $allowedOutTypes = null)
    {
        $errors = [];
        $allowedOutTypes = $allowedOutTypes === null
            ? $this->policy->allowedRefundOutTypes($bizType)
            : $allowedOutTypes;
        foreach ($rows as $row) {
            $prefix = '第' . $row['source_row_no'] . '行：';
            if ($row['case_no'] === '') {
                $errors[] = $prefix . '案号不能为空';
            }
            if ($row['out_type'] === '') {
                $errors[] = $prefix . '出账种类不能为空';
            } elseif (empty($allowedOutTypes)) {
                $errors[] = $prefix . '当前账套类型不支持案款退付登记：' . $bizType;
            } elseif (!in_array($row['out_type'], $allowedOutTypes, true)) {
                $errors[] = $prefix . '当前账套不允许导入出账种类【' . $row['out_type'] . '】，允许类型：' . implode('、', $allowedOutTypes);
            }
            if ($row['party_name'] === '' && $row['actual_payee_name'] === '') {
                $errors[] = $prefix . '当事人和实际收款人不能同时为空';
            }
            if ($this->toCents($row['refund_amount']) <= 0) {
                $errors[] = $prefix . '出账金额必须大于0';
            }
            if ($row['refund_date'] === '') {
                $errors[] = $prefix . '出账日期不能为空或格式不正确';
            }
            if (
                $receiptTraceRequired
                && $bizType !== 'LITIGATION_FEE'
                && $row['receipt_no'] === ''
                && $row['source_receipt_no'] === ''
                && $row['out_order_no'] === ''
            ) {
                $errors[] = $prefix . '票据号码、来源票据号码、出账单号至少填写一项用于追溯';
            }
        }
        return $errors;
    }

    public function validateDistributionRows($rows, $bizType = '', $allowedInvoiceTypes = null)
    {
        $errors = [];
        $allowedInvoiceTypes = $allowedInvoiceTypes === null
            ? $this->policy->allowedDistributionInvoiceTypes($bizType)
            : $allowedInvoiceTypes;
        foreach ($rows as $row) {
            $prefix = '第' . ($row['source_row_no'] ?? '?') . '行：';
            if ($bizType !== 'CASE_FUND') {
                $errors[] = $prefix . '当前账套类型不支持案款分配：' . $bizType;
                continue;
            }
            if (($row['distribution_no'] ?? '') === '') {
                $errors[] = $prefix . '分配单号不能为空';
            }
            if (($row['in_case_no'] ?? '') === '') {
                $errors[] = $prefix . '转入案号不能为空';
            }
            if (($row['out_case_no'] ?? '') === '') {
                $errors[] = $prefix . '转出案号不能为空';
            }
            if (($row['invoice_type'] ?? '') === '') {
                $errors[] = $prefix . '开票种类不能为空';
            } elseif (!in_array($row['invoice_type'], $allowedInvoiceTypes, true)) {
                $errors[] = $prefix . '当前账套不允许导入开票种类【' . $row['invoice_type'] . '】，允许类型：' . implode('、', $allowedInvoiceTypes);
            }
            if (($row['receipt_no'] ?? '') === '') {
                $errors[] = $prefix . '单据号不能为空';
            }
            if ($this->toCents($row['distribution_amount'] ?? '0') <= 0) {
                $errors[] = $prefix . '分配金额必须大于0';
            }
            if (!$this->isValidImportDate($row['receive_date'] ?? '')) {
                $errors[] = $prefix . '接收日期不能为空或格式不正确';
            }
        }
        return $errors;
    }

    public function validateUnknownPaymentRows($rows, $bizType = '')
    {
        $errors = [];
        foreach ($rows as $row) {
            $prefix = '第' . ($row['source_row_no'] ?? '?') . '行：';
            if ($bizType !== 'CASE_FUND') {
                $errors[] = $prefix . '当前账套类型不支持不明款业务：' . $bizType;
                continue;
            }
            if ($this->toCents($row['unknown_amount'] ?? '0') <= 0) {
                $errors[] = $prefix . '金额必须大于0';
            }
            if (!$this->isValidImportDate($row['transaction_date'] ?? '')) {
                $errors[] = $prefix . '交易日期不能为空或格式不正确';
            }
            if (!$this->unknownPaymentRowHasTraceField($row)) {
                $errors[] = $prefix . '缴款人、银行备注、银行摘要、法院银行账户、缴款人账号至少填写一项用于追溯';
            }
        }
        return $errors;
    }

    public function refundImportRequiredHeaders($bizType = '', $receiptTraceRequired = null)
    {
        $headers = [
            '案号', '经办人', '书记员', '票据号码', '开票日期', '出账日期',
            '来源票据号码', '来源票据开票日期', '出账单号', '出账状态', '出账种类',
            '诉讼地位', '当事人', '出账金额', '收款人与当事人关系',
            '支付方式', '实际收款人', '身份证号/企业', '收款人银行户名', '收款账号',
            '开户银行', '银联号', '是否本行', '承办人情况说明', '申请人',
        ];
        if ($bizType === 'LITIGATION_FEE') {
            $headers = array_values(array_diff($headers, ['来源票据号码', '来源票据开票日期']));
        }
        if ($bizType === 'CASE_FUND' && $receiptTraceRequired === false) {
            $headers = array_values(array_diff($headers, ['来源票据号码', '来源票据开票日期', '出账单号']));
        }
        return $headers;
    }

    public function unknownPaymentRowHasTraceField($row)
    {
        foreach (['payer_name', 'bank_remark', 'bank_summary', 'court_bank_account_no', 'payer_account_no'] as $field) {
            if (trim((string)($row[$field] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    public function isValidImportDate($value)
    {
        $text = trim((string)$value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            return false;
        }
        list($year, $month, $day) = array_map('intval', explode('-', $text));
        return checkdate($month, $day, $year);
    }

    public function isSuspiciousBankSerialNo($value)
    {
        $text = trim((string)$value);
        if ($text === '') {
            return false;
        }
        return preg_match('/^[+-]?\d+(?:\.\d+)?E[+-]?\d+$/i', $text) === 1;
    }

    public function paymentRowHasTraceKey($row)
    {
        if ($row['receipt_no'] !== '' || $row['bank_serial_no'] !== '' || $row['payment_order_no'] !== '') {
            return true;
        }
        return trim((string)($row['bank_account_no'] ?? '')) !== ''
            && $this->toCents($row['payment_amount'] ?? '0') > 0;
    }

    public function paymentImportErrorMessage($errors, $bizType = '')
    {
        foreach ($errors as $error) {
            if (strpos($error, '当前账套不允许导入业务类型') !== false) {
                return '导入的不是' . $this->paymentDataLabelByBizType($bizType) . '数据';
            }
        }
        return '导入校验失败';
    }

    public function refundImportErrorMessage($errors, $bizType = '')
    {
        foreach ($errors as $error) {
            if (strpos($error, '当前账套不允许导入出账种类') !== false) {
                return '导入的不是' . $this->paymentDataLabelByBizType($bizType) . '数据';
            }
        }
        return '导入校验失败';
    }

    public function paymentDataLabelByBizType($bizType)
    {
        $map = [
            'CASE_FUND' => '案款',
            'LITIGATION_FEE' => '诉讼费',
        ];
        return $map[$bizType] ?? '当前账套';
    }

    protected function toCents($amount)
    {
        $converter = $this->amountToCents;
        return $converter($amount);
    }
}
