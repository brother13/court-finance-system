<?php

namespace app\finance\service\casefund\voucher;

/**
 * 案款凭证分组、摘要和分录构造规则；不查询数据库，也不负责凭证号、事务或落库。
 */
class CaseFundVoucherRule
{
    protected $hooks;

    public function __construct(array $hooks)
    {
        $requiredHooks = [
            'amount_to_cents',
            'cents_to_decimal',
            'new_id',
            'normalize_payment_method',
            'subject_config_key',
            'get_subject_aux_configs',
            'need_verification',
            'has_required_aux_configs',
            'filter_aux_values',
            'build_aux_desc',
            'build_payment_summary',
            'build_payment_method_summary',
            'build_unknown_payment_summary',
            'build_refund_debit_detail',
            'build_refund_normal_summary',
            'build_refund_aggregate_credit_summary',
            'build_refund_aggregate_credit_detail',
            'build_distribution_out_summary',
            'build_distribution_in_summary',
        ];
        foreach ($requiredHooks as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款凭证规则缺少回调：' . $name);
            }
        }
        $this->hooks = $hooks;
    }

    public function buildPaymentVoucherDetailsData($groupRows, $subjectConfigMap, $paymentMethodConfigMap, $byPaymentMethod, $bizType)
    {
        $detailsData = [];
        $totalDebit = 0;
        $lineNo = 1;
        $firstRow = $groupRows[0];
        $paymentMethod = $this->callHook('normalize_payment_method', [$firstRow['payment_method'] ?? '']);

        if ($byPaymentMethod && $paymentMethod === 'POS') {
            $methodConfig = $paymentMethodConfigMap[$paymentMethod];
            $posTotalCents = 0;
            foreach ($groupRows as $row) {
                $posTotalCents += $this->callHook('amount_to_cents', [$row['payment_amount']]);
            }
            $totalDebit = $posTotalCents;
            $debitConfigs = $this->callHook('get_subject_aux_configs', [$methodConfig['debit_subject_code']]);
            $detailsData[] = [
                'detail_id' => $this->callHook('new_id'),
                'line_no' => $lineNo++,
                'subject_code' => $methodConfig['debit_subject_code'],
                'summary' => $this->callHook('build_payment_method_summary', [$paymentMethod, $groupRows]),
                'debit_amount' => $this->callHook('cents_to_decimal', [$posTotalCents]),
                'credit_amount' => '0.00',
                'verification_status' => $this->callHook('need_verification', [$debitConfigs]) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                'aux_desc' => '',
                'aux_values' => [],
            ];

            foreach ($groupRows as $row) {
                $businessTypeConfigKey = $this->callHook('subject_config_key', [$bizType, 'PAYMENT', $row['business_type']]);
                $config = $subjectConfigMap[$businessTypeConfigKey];
                $summary = $this->callHook('build_payment_summary', [$row]);
                $amountCents = $this->callHook('amount_to_cents', [$row['payment_amount']]);
                $amountDecimal = $this->callHook('cents_to_decimal', [$amountCents]);
                $rawAuxValues = $this->paymentVoucherAuxValues($row);
                $creditConfigs = $this->callHook('get_subject_aux_configs', [$config['credit_subject_code']]);
                $creditAuxValues = $this->callHook('filter_aux_values', [$rawAuxValues, $creditConfigs]);
                $detailsData[] = [
                    'detail_id' => $this->callHook('new_id'),
                    'line_no' => $lineNo++,
                    'subject_code' => $config['credit_subject_code'],
                    'summary' => $summary,
                    'debit_amount' => '0.00',
                    'credit_amount' => $amountDecimal,
                    'verification_status' => $this->callHook('need_verification', [$creditConfigs]) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                    'aux_desc' => $this->callHook('build_aux_desc', [$creditAuxValues]),
                    'aux_values' => $creditAuxValues,
                ];
            }

            return ['total_debit' => $totalDebit, 'details' => $detailsData];
        }

        foreach ($groupRows as $row) {
            $businessTypeConfigKey = $this->callHook('subject_config_key', [$bizType, 'PAYMENT', $row['business_type']]);
            $config = $subjectConfigMap[$businessTypeConfigKey];
            $summary = $this->callHook('build_payment_summary', [$row]);
            $amountCents = $this->callHook('amount_to_cents', [$row['payment_amount']]);
            $amountDecimal = $this->callHook('cents_to_decimal', [$amountCents]);
            $totalDebit += $amountCents;

            $debitSubjectCode = $config['debit_subject_code'];
            if ($byPaymentMethod) {
                $rowPaymentMethod = $this->callHook('normalize_payment_method', [$row['payment_method'] ?? '']);
                $debitSubjectCode = $paymentMethodConfigMap[$rowPaymentMethod]['debit_subject_code'];
            }

            $rawAuxValues = $this->paymentVoucherAuxValues($row);
            $debitConfigs = $this->callHook('get_subject_aux_configs', [$debitSubjectCode]);
            $debitAuxValues = $this->callHook('filter_aux_values', [$rawAuxValues, $debitConfigs]);
            $detailsData[] = [
                'detail_id' => $this->callHook('new_id'),
                'line_no' => $lineNo++,
                'subject_code' => $debitSubjectCode,
                'summary' => $summary,
                'debit_amount' => $amountDecimal,
                'credit_amount' => '0.00',
                'verification_status' => $this->callHook('need_verification', [$debitConfigs]) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                'aux_desc' => $this->callHook('build_aux_desc', [$debitAuxValues]),
                'aux_values' => $debitAuxValues,
            ];

            $creditConfigs = $this->callHook('get_subject_aux_configs', [$config['credit_subject_code']]);
            $creditAuxValues = $this->callHook('filter_aux_values', [$rawAuxValues, $creditConfigs]);
            $detailsData[] = [
                'detail_id' => $this->callHook('new_id'),
                'line_no' => $lineNo++,
                'subject_code' => $config['credit_subject_code'],
                'summary' => $summary,
                'debit_amount' => '0.00',
                'credit_amount' => $amountDecimal,
                'verification_status' => $this->callHook('need_verification', [$creditConfigs]) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                'aux_desc' => $this->callHook('build_aux_desc', [$creditAuxValues]),
                'aux_values' => $creditAuxValues,
            ];
        }

        return ['total_debit' => $totalDebit, 'details' => $detailsData];
    }

    public function paymentVoucherAuxValues($row)
    {
        return [
            ['aux_type_code' => 'case_no', 'aux_value' => $row['case_no'], 'aux_label' => $row['case_no']],
            ['aux_type_code' => 'receipt_no', 'aux_value' => $row['receipt_no'] ?? '', 'aux_label' => $row['receipt_no'] ?? ''],
        ];
    }

    public function filterAuxValuesBySubjectConfigs($auxValues, $configs)
    {
        $allowed = [];
        foreach ($configs as $config) {
            $typeCode = trim((string)($config['aux_type_code'] ?? ''));
            if ($typeCode !== '') {
                $allowed[$typeCode] = true;
            }
        }
        if (empty($allowed)) {
            return [];
        }

        $result = [];
        foreach ($auxValues as $aux) {
            $typeCode = trim((string)($aux['aux_type_code'] ?? ''));
            if ($typeCode === '' || empty($allowed[$typeCode])) {
                continue;
            }
            if (!isset($aux['aux_value']) || $aux['aux_value'] === '') {
                continue;
            }
            $result[] = $aux;
        }
        return $result;
    }

    public function buildPaymentMethodVoucherSummary($paymentMethod, $groupRows)
    {
        $date = $groupRows[0]['payment_date'] ?? '';
        $label = $this->callHook('normalize_payment_method', [$paymentMethod]);
        return trim($date . ' 收 ' . $label . ' 合计');
    }

    public function groupPaymentRowsForVoucher($rows, $byDayFlag, $byPaymentMethodFlag)
    {
        $groups = [];
        if ((int)$byDayFlag === 1) {
            foreach ($rows as $row) {
                $methodKey = (int)$byPaymentMethodFlag === 1
                    ? $this->callHook('normalize_payment_method', [$row['payment_method'] ?? ''])
                    : 'ALL';
                $groupKey = $row['payment_date'] . '|' . $methodKey;
                if (!isset($groups[$groupKey])) {
                    $groups[$groupKey] = [];
                }
                $groups[$groupKey][] = $row;
            }
            return $groups;
        }

        foreach ($rows as $row) {
            $methodKey = (int)$byPaymentMethodFlag === 1
                ? $this->callHook('normalize_payment_method', [$row['payment_method'] ?? ''])
                : 'ALL';
            $groups[$row['payment_id'] . '|' . $methodKey] = [$row];
        }
        return $groups;
    }

    public function groupUnknownPaymentRowsForVoucher($rows, $byDayFlag)
    {
        $groups = [];
        if ((int)$byDayFlag === 1) {
            foreach ($rows as $row) {
                $date = $row['transaction_date'];
                if (!isset($groups[$date])) {
                    $groups[$date] = [];
                }
                $groups[$date][] = $row;
            }
            return $groups;
        }

        foreach ($rows as $row) {
            $groups[$row['unknown_payment_id']] = [$row];
        }
        return $groups;
    }

    public function buildUnknownPaymentVoucherDetailsData($groupRows, $subjectConfig)
    {
        $detailsData = [];
        $totalCents = 0;
        $lineNo = 1;
        foreach ($groupRows as $row) {
            $summary = $this->callHook('build_unknown_payment_summary', [$row]);
            $amountCents = $this->callHook('amount_to_cents', [$row['unknown_amount']]);
            $amountDecimal = $this->callHook('cents_to_decimal', [$amountCents]);
            $totalCents += $amountCents;
            $detailsData[] = [
                'detail_id' => $this->callHook('new_id'),
                'line_no' => $lineNo++,
                'subject_code' => $subjectConfig['debit_subject_code'],
                'summary' => $summary,
                'debit_amount' => $amountDecimal,
                'credit_amount' => '0.00',
                'verification_status' => 'NOT_REQUIRED',
                'aux_desc' => '',
            ];
            $detailsData[] = [
                'detail_id' => $this->callHook('new_id'),
                'line_no' => $lineNo++,
                'subject_code' => $subjectConfig['credit_subject_code'],
                'summary' => $summary,
                'debit_amount' => '0.00',
                'credit_amount' => $amountDecimal,
                'verification_status' => 'NOT_REQUIRED',
                'aux_desc' => '',
            ];
        }
        return ['details' => $detailsData, 'total_cents' => $totalCents];
    }

    public function groupRefundRowsForVoucher($rows, $byDayFlag, $byOutOrderNoFlag)
    {
        $groups = [];
        if ((int)$byDayFlag === 1) {
            foreach ($rows as $row) {
                $date = $row['refund_date'];
                if (!isset($groups[$date])) {
                    $groups[$date] = [];
                }
                $groups[$date][] = $row;
            }
            return $groups;
        }

        foreach ($rows as $row) {
            $groups[$row['refund_id']] = [$row];
        }
        return $groups;
    }

    public function groupDistributionRowsForVoucher($rows)
    {
        $groups = [];
        foreach ($rows as $row) {
            $distributionNo = trim((string)($row['distribution_no'] ?? ''));
            if ($distributionNo === '') {
                $distributionNo = $row['distribution_id'] ?? $this->callHook('new_id');
            }
            if (!isset($groups[$distributionNo])) {
                $groups[$distributionNo] = [];
            }
            $groups[$distributionNo][] = $row;
        }
        return $groups;
    }

    public function buildRefundVoucherDetailsData($groupRows, $subjectConfigMap, $aggregateByOutOrderNo, $bizType = '')
    {
        $detailsData = [];
        $totalDebit = 0;
        $lineNo = 1;
        $outOrderCounts = [];
        $processedOutOrders = [];

        if ($aggregateByOutOrderNo) {
            foreach ($groupRows as $row) {
                $outOrderNo = trim((string)($row['out_order_no'] ?? ''));
                if ($outOrderNo === '') {
                    continue;
                }
                $outOrderCounts[$outOrderNo] = ($outOrderCounts[$outOrderNo] ?? 0) + 1;
            }
        }

        foreach ($groupRows as $row) {
            $outOrderNo = trim((string)($row['out_order_no'] ?? ''));
            $shouldAggregate = $aggregateByOutOrderNo
                && $outOrderNo !== ''
                && ($outOrderCounts[$outOrderNo] ?? 0) > 1;

            if ($shouldAggregate) {
                if (isset($processedOutOrders[$outOrderNo])) {
                    continue;
                }
                $processedOutOrders[$outOrderNo] = true;
                $aggregateAmountCents = 0;
                $aggregateCreditSubjectCode = '';
                $aggregateCreditConfigs = [];

                foreach ($groupRows as $bucketRow) {
                    if (trim((string)($bucketRow['out_order_no'] ?? '')) !== $outOrderNo) {
                        continue;
                    }
                    $outTypeConfigKey = $this->callHook('subject_config_key', [$bizType, 'REFUND', $bucketRow['out_type']]);
                    $config = $subjectConfigMap[$outTypeConfigKey];
                    $creditConfigs = $this->callHook('get_subject_aux_configs', [$config['credit_subject_code']]);
                    if ($aggregateCreditSubjectCode === '') {
                        $aggregateCreditSubjectCode = $config['credit_subject_code'];
                        $aggregateCreditConfigs = $creditConfigs;
                    } elseif ($aggregateCreditSubjectCode !== $config['credit_subject_code']) {
                        throw new \Exception('同一出账单号【' . $bucketRow['out_order_no'] . '】存在不同贷方科目配置，不允许汇总生成一张凭证');
                    }
                    if ($this->callHook('has_required_aux_configs', [$creditConfigs])) {
                        throw new \Exception('贷方科目【' . $config['credit_subject_code'] . '】存在必填辅助核算，不允许按出账单号合并为一条贷方分录');
                    }

                    $debitResult = $this->callHook('build_refund_debit_detail', [$bucketRow, $config, $lineNo++, true]);
                    $detailsData[] = $debitResult['detail'];
                    $aggregateAmountCents += $debitResult['amount_cents'];
                    $totalDebit += $debitResult['amount_cents'];
                }

                $detailsData[] = $this->callHook('build_refund_aggregate_credit_detail', [
                    $this->callHook('new_id'),
                    $lineNo++,
                    $aggregateCreditSubjectCode,
                    $this->callHook('build_refund_aggregate_credit_summary', [$outOrderNo]),
                    $aggregateAmountCents,
                    $aggregateCreditConfigs,
                ]);
                continue;
            }

            $outTypeConfigKey = $this->callHook('subject_config_key', [$bizType, 'REFUND', $row['out_type']]);
            $config = $subjectConfigMap[$outTypeConfigKey];
            $debitResult = $this->callHook('build_refund_debit_detail', [$row, $config, $lineNo++, true]);
            $detailsData[] = $debitResult['detail'];
            $totalDebit += $debitResult['amount_cents'];

            $creditConfigs = $this->callHook('get_subject_aux_configs', [$config['credit_subject_code']]);
            $creditAuxValues = $this->callHook('filter_aux_values', [$debitResult['raw_aux_values'], $creditConfigs]);
            $detailsData[] = [
                'detail_id' => $this->callHook('new_id'),
                'line_no' => $lineNo++,
                'subject_code' => $config['credit_subject_code'],
                'summary' => $debitResult['summary'],
                'debit_amount' => '0.00',
                'credit_amount' => $debitResult['amount_decimal'],
                'verification_status' => $this->callHook('need_verification', [$creditConfigs]) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                'aux_desc' => $this->callHook('build_aux_desc', [$creditAuxValues]),
                'aux_values' => $creditAuxValues,
            ];
        }

        return ['details' => $detailsData, 'total_cents' => $totalDebit];
    }

    public function buildDistributionVoucherDetailsData($groupRows, $subjectConfigMap)
    {
        $detailsData = [];
        $totalDebit = 0;
        $lineNo = 1;

        foreach ($groupRows as $row) {
            $config = $subjectConfigMap[$row['invoice_type']];
            $amountCents = $this->callHook('amount_to_cents', [$row['distribution_amount']]);
            $amountDecimal = $this->callHook('cents_to_decimal', [$amountCents]);
            $totalDebit += $amountCents;

            $outAuxValues = [
                ['aux_type_code' => 'case_no', 'aux_value' => $row['out_case_no'], 'aux_label' => $row['out_case_no']],
                ['aux_type_code' => 'receipt_no', 'aux_value' => $row['receipt_no'], 'aux_label' => $row['receipt_no']],
            ];
            $inAuxValues = [
                ['aux_type_code' => 'case_no', 'aux_value' => $row['in_case_no'], 'aux_label' => $row['in_case_no']],
                ['aux_type_code' => 'receipt_no', 'aux_value' => $row['receipt_no'], 'aux_label' => $row['receipt_no']],
            ];

            $debitConfigs = $this->callHook('get_subject_aux_configs', [$config['debit_subject_code']]);
            $outAuxValues = $this->callHook('filter_aux_values', [$outAuxValues, $debitConfigs]);
            $detailsData[] = [
                'detail_id' => $this->callHook('new_id'),
                'line_no' => $lineNo++,
                'subject_code' => $config['debit_subject_code'],
                'summary' => $this->callHook('build_distribution_out_summary', [$row]),
                'debit_amount' => $amountDecimal,
                'credit_amount' => '0.00',
                'verification_status' => $this->callHook('need_verification', [$debitConfigs]) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                'aux_desc' => $this->callHook('build_aux_desc', [$outAuxValues]),
                'aux_values' => $outAuxValues,
            ];

            $creditConfigs = $this->callHook('get_subject_aux_configs', [$config['credit_subject_code']]);
            $inAuxValues = $this->callHook('filter_aux_values', [$inAuxValues, $creditConfigs]);
            $detailsData[] = [
                'detail_id' => $this->callHook('new_id'),
                'line_no' => $lineNo++,
                'subject_code' => $config['credit_subject_code'],
                'summary' => $this->callHook('build_distribution_in_summary', [$row]),
                'debit_amount' => '0.00',
                'credit_amount' => $amountDecimal,
                'verification_status' => $this->callHook('need_verification', [$creditConfigs]) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                'aux_desc' => $this->callHook('build_aux_desc', [$inAuxValues]),
                'aux_values' => $inAuxValues,
            ];
        }

        return ['details' => $detailsData, 'total_cents' => $totalDebit];
    }

    public function buildRefundDebitDetailData($row, $config, $lineNo, $useNormalSummary = false)
    {
        $summary = $useNormalSummary
            ? $this->callHook('build_refund_normal_summary', [$row])
            : trim($row['case_no'] . ' ' . ($row['party_name'] ?: $row['actual_payee_name']) . ' 退付');
        $amountCents = $this->callHook('amount_to_cents', [$row['refund_amount']]);
        $amountDecimal = $this->callHook('cents_to_decimal', [$amountCents]);
        $receiptNo = $row['source_receipt_no'] ?: ($row['receipt_no'] ?? '');
        $auxValues = [
            ['aux_type_code' => 'case_no', 'aux_value' => $row['case_no'], 'aux_label' => $row['case_no']],
            ['aux_type_code' => 'receipt_no', 'aux_value' => $receiptNo, 'aux_label' => $receiptNo],
        ];
        $debitConfigs = $this->callHook('get_subject_aux_configs', [$config['debit_subject_code']]);
        $filteredAuxValues = $this->callHook('filter_aux_values', [$auxValues, $debitConfigs]);
        $auxDesc = $this->callHook('build_aux_desc', [$filteredAuxValues]);

        return [
            'detail' => [
                'detail_id' => $this->callHook('new_id'),
                'line_no' => $lineNo,
                'subject_code' => $config['debit_subject_code'],
                'summary' => $summary,
                'debit_amount' => $amountDecimal,
                'credit_amount' => '0.00',
                'verification_status' => $this->callHook('need_verification', [$debitConfigs]) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                'aux_desc' => $auxDesc,
                'aux_values' => $filteredAuxValues,
            ],
            'summary' => $summary,
            'amount_cents' => $amountCents,
            'amount_decimal' => $amountDecimal,
            'aux_desc' => $auxDesc,
            'aux_values' => $filteredAuxValues,
            'raw_aux_values' => $auxValues,
        ];
    }

    public function buildPaymentVoucherSummary($row)
    {
        return trim('收 ' . ($row['case_no'] ?? '') . ' ' . ($row['party_name'] ?? ''));
    }

    public function buildRefundVoucherHeaderSummary($row, $aggregateByOutOrderNo, $voucherDate)
    {
        if ($aggregateByOutOrderNo) {
            return $voucherDate . ' 付合计';
        }
        return $this->callHook('build_refund_normal_summary', [$row]);
    }

    public function buildRefundNormalVoucherSummary($row)
    {
        return trim('付 ' . ($row['case_no'] ?? '') . ' ' . (($row['party_name'] ?? '') ?: ($row['actual_payee_name'] ?? '')));
    }

    public function buildRefundAggregateCreditSummary($outOrderNo)
    {
        return trim('付 出账单号' . $outOrderNo . ' 合计');
    }

    public function buildDistributionVoucherHeaderSummary($row)
    {
        return trim('分配 ' . ($row['distribution_no'] ?? '') . ' 合计');
    }

    public function buildDistributionTransferOutSummary($row)
    {
        return trim('分配 ' . ($row['distribution_no'] ?? '') . ' 转出 ' . ($row['out_case_no'] ?? '') . ' ' . ($row['out_party_name'] ?? ''));
    }

    public function buildDistributionTransferInSummary($row)
    {
        return trim('分配 ' . ($row['distribution_no'] ?? '') . ' 转入 ' . ($row['in_case_no'] ?? '') . ' ' . ($row['in_party_name'] ?? ''));
    }

    public function buildUnknownPaymentVoucherSummary($row)
    {
        $remark = trim((string)($row['bank_remark'] ?? ''));
        if ($remark === '') {
            $remark = trim((string)($row['bank_summary'] ?? ''));
        }
        return trim('收 不明款 ' . ($row['payer_name'] ?? '') . ' ' . $remark);
    }

    public function buildRefundAggregateCreditDetail($detailId, $lineNo, $subjectCode, $summary, $amountCents, $configs)
    {
        return [
            'detail_id' => $detailId,
            'line_no' => $lineNo,
            'subject_code' => $subjectCode,
            'summary' => $summary,
            'debit_amount' => '0.00',
            'credit_amount' => $this->callHook('cents_to_decimal', [$amountCents]),
            'verification_status' => $this->callHook('need_verification', [$configs]) ? 'UNVERIFIED' : 'NOT_REQUIRED',
            'aux_desc' => '',
            'aux_values' => [],
        ];
    }

    public function buildAuxDesc($auxValues)
    {
        $parts = [];
        foreach ($auxValues as $aux) {
            if (empty($aux['aux_type_code']) || !isset($aux['aux_value']) || $aux['aux_value'] === '') {
                continue;
            }
            $parts[] = $aux['aux_type_code'] . ':' . ($aux['aux_label'] ?? $aux['aux_value']);
        }
        return implode('; ', $parts);
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
