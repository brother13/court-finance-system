<?php

namespace app\finance\service\casefund\policy;

class CaseFundBusinessPolicy
{
    /**
     * 数据导入白名单，历史科目配置别名严禁加入这里。
     */
    public function allowedPaymentBusinessTypes($bizType)
    {
        $map = [
            'CASE_FUND' => ['执行、调解款'],
            'LITIGATION_FEE' => ['诉讼费直接结算', '诉讼费预收'],
        ];
        return $map[$bizType] ?? [];
    }

    public function allowedRefundOutTypes($bizType)
    {
        $map = [
            'CASE_FUND' => ['执行、调解款发放'],
            'LITIGATION_FEE' => ['诉讼费退费'],
        ];
        return $map[$bizType] ?? [];
    }

    public function allowedDistributionInvoiceTypes($bizType)
    {
        $map = [
            'CASE_FUND' => ['执行、调解款'],
        ];
        return $map[$bizType] ?? [];
    }

    public function allowedSubjectConfigItems($bizType, $voucherBizType)
    {
        if ($voucherBizType === 'PAYMENT') {
            return $this->subjectConfigPaymentBusinessTypes($bizType);
        }
        if ($voucherBizType === 'PAYMENT_METHOD') {
            return array_keys($this->paymentMethodOptions());
        }
        if ($voucherBizType === 'REFUND') {
            return $this->subjectConfigRefundBusinessTypes($bizType);
        }
        if ($voucherBizType === 'UNKNOWN_PAYMENT') {
            return $bizType === 'CASE_FUND' ? ['不明款'] : [];
        }
        if ($voucherBizType === 'DISTRIBUTION') {
            return $this->allowedDistributionInvoiceTypes($bizType);
        }
        return [];
    }

    public function subjectConfigPaymentBusinessTypes($bizType)
    {
        $map = [
            'CASE_FUND' => ['缴费登记'],
            'LITIGATION_FEE' => ['缴费登记'],
        ];
        return $map[$bizType] ?? [];
    }

    public function subjectConfigRefundBusinessTypes($bizType)
    {
        $map = [
            'CASE_FUND' => ['出账查询'],
            'LITIGATION_FEE' => ['出账查询'],
        ];
        return $map[$bizType] ?? [];
    }

    public function voucherSubjectConfigAliases($bizType, $voucherBizType)
    {
        // 这些别名只用于读取历史科目配置，不属于数据导入业务类型。
        $map = [
            'PAYMENT' => [
                'CASE_FUND' => [
                    '执行、调解款' => '缴费登记',
                ],
                'LITIGATION_FEE' => [
                    '诉讼费直接结算' => '缴费登记',
                    '诉讼费预收' => '缴费登记',
                    '预收诉讼费' => '缴费登记',
                    '诉讼费收款' => '缴费登记',
                ],
            ],
            'REFUND' => [
                'CASE_FUND' => [
                    '执行、调解款发放' => '出账查询',
                ],
                'LITIGATION_FEE' => [
                    '诉讼费退费' => '出账查询',
                ],
            ],
        ];
        return $map[$voucherBizType][$bizType] ?? [];
    }

    public function voucherSubjectConfigKey($bizType, $voucherBizType, $businessItemType)
    {
        $businessItemType = trim((string)$businessItemType);
        $aliases = $this->voucherSubjectConfigAliases($bizType, $voucherBizType);
        return $aliases[$businessItemType] ?? $businessItemType;
    }

    public function subjectConfigLookupItems($bizType, $voucherBizType, $businessItems)
    {
        if (!in_array($voucherBizType, ['PAYMENT', 'REFUND'], true)) {
            return $businessItems;
        }
        return array_values(array_unique(array_merge($businessItems, array_keys($this->voucherSubjectConfigAliases($bizType, $voucherBizType)))));
    }

    public function normalizeVoucherBizType($value)
    {
        $value = strtoupper(trim((string)$value));
        return in_array($value, ['PAYMENT', 'PAYMENT_METHOD', 'REFUND', 'UNKNOWN_PAYMENT', 'DISTRIBUTION'], true) ? $value : '';
    }

    public function paymentMethodOptions()
    {
        return [
            'POS' => 'POS 收款',
            'TRANSFER' => '银行转账',
            'UNKNOWN' => '不明款认领',
        ];
    }

    public function normalizePaymentMethodCode($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return 'UNKNOWN';
        }
        $upper = strtoupper($value);
        if (in_array($upper, ['POS', 'TRANSFER', 'UNKNOWN'], true)) {
            return $upper;
        }
        $compact = preg_replace('/\s+/u', '', $value);
        if (in_array($compact, ['POS收款', 'POS刷卡'], true)) {
            return 'POS';
        }
        if (in_array($compact, ['银行转账', '转账'], true)) {
            return 'TRANSFER';
        }
        if (in_array($compact, ['不明款认领', '不明款', '未知'], true)) {
            return 'UNKNOWN';
        }
        return $value;
    }

    public function inferPaymentMethodByBankSerialNo($bankSerialNo, $rawPaymentMethod = '')
    {
        $serial = preg_replace('/\s+/u', '', trim((string)$bankSerialNo));
        if ($serial === '') {
            return 'UNKNOWN';
        }
        $length = strlen($serial);
        if (in_array($length, [10, 12], true)) {
            return 'POS';
        }
        if ($length > 15) {
            return 'TRANSFER';
        }
        return trim((string)$rawPaymentMethod);
    }

    public function paymentMethodLabel($method)
    {
        $code = $this->normalizePaymentMethodCode($method);
        $options = $this->paymentMethodOptions();
        return $options[$code] ?? (string)$method;
    }
}
