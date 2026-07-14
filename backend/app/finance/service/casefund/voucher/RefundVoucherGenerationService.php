<?php

namespace app\finance\service\casefund\voucher;

/**
 * 退付登记凭证生成应用服务。
 *
 * 权限、事务、审计和接口响应由调用方负责；本服务负责取数、校验、分组和事务内落库编排。
 */
class RefundVoucherGenerationService
{
    protected $policy;
    protected $resolver;
    protected $manager;
    protected $rule;
    protected $hooks;
    protected $refundTable;
    protected $refundField;

    public function __construct($policy, $resolver, $manager, $rule, array $hooks, $refundTable, array $refundField)
    {
        foreach ([$policy, $resolver, $manager, $rule] as $dependency) {
            if (!is_object($dependency)) {
                throw new \InvalidArgumentException('退付凭证生成服务缺少协作对象');
            }
        }
        $requiredHooks = [
            'get_db',
            'account_where',
            'account_set_id',
            'current_year',
            'current_biz_type',
            'normalize_date_filter',
            'assert_rows_can_generate',
            'fiscal_year',
            'generate_next_voucher_no',
            'ensure_aux_archives',
            'cents_to_decimal',
            'new_id',
            'prepare_create',
            'current_user_id',
            'now',
        ];
        foreach ($requiredHooks as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('退付凭证生成服务缺少回调：' . $name);
            }
        }

        $this->policy = $policy;
        $this->resolver = $resolver;
        $this->manager = $manager;
        $this->rule = $rule;
        $this->hooks = $hooks;
        $this->refundTable = $refundTable;
        $this->refundField = $refundField;
    }

    public function selectedRows($refundIds)
    {
        if (!is_array($refundIds) || empty($refundIds)) {
            return $this->errorResult('请选择要生成凭证的退付记录');
        }

        $rows = $this->db($this->refundTable)
            ->where([
                'account_set_id' => $this->callHook('account_set_id'),
                'fiscal_year' => $this->callHook('current_year'),
                'del_flag' => 0,
            ])
            ->where('refund_id', 'in', $refundIds)
            ->field($this->refundField)
            ->order('refund_date asc, source_row_no asc')
            ->select();
        if (empty($rows)) {
            return $this->errorResult('所选退付记录不存在');
        }
        return ['error' => '', 'rows' => $rows];
    }

    public function rowsByQuery($data)
    {
        $where = $this->callHook('account_where');
        $where['fiscal_year'] = $this->callHook('current_year');
        $where['voucher_status'] = 'UNGENERATED';
        if (!empty($data['period'])) {
            $where['period'] = $data['period'];
        }
        $dateStart = $this->callHook('normalize_date_filter', [
            $data['date_start'] ?? ($data['startDate'] ?? ''),
        ]);
        $dateEnd = $this->callHook('normalize_date_filter', [
            $data['date_end'] ?? ($data['endDate'] ?? ''),
        ]);
        if ($dateStart !== '') {
            $where['refund_date'][] = ['>=', $dateStart];
        }
        if ($dateEnd !== '') {
            $where['refund_date'][] = ['<=', $dateEnd];
        }
        if (!empty($data['out_status'])) {
            $where['out_status'] = $data['out_status'];
        }

        $bizType = $this->callHook('current_biz_type');
        $allowedOutTypes = $this->policy->allowedRefundOutTypes($bizType);
        if (empty($allowedOutTypes)) {
            return $this->errorResult('当前账套类型不支持案款退付登记：' . $bizType);
        }
        $where['out_type'] = ['in', $allowedOutTypes];

        $caseNo = trim((string)($data['case_no'] ?? ($data['caseNo'] ?? '')));
        $partyName = trim((string)($data['party_name'] ?? ($data['partyName'] ?? '')));
        $keyword = trim((string)($data['keyword'] ?? ''));
        $query = $this->db($this->refundTable)->where($where);
        if ($caseNo !== '') {
            $query->where('case_no', 'like', '%' . $caseNo . '%');
        }
        if ($partyName !== '') {
            $query->where(
                'party_name|actual_payee_name|payee_bank_account_name',
                'like',
                '%' . $partyName . '%'
            );
        }
        if ($keyword !== '') {
            $query->where(
                'case_no|party_name|actual_payee_name|receipt_no|source_receipt_no|out_order_no|payee_bank_account_no',
                'like',
                '%' . $keyword . '%'
            );
        }
        $rows = $query->field($this->refundField)
            ->order('refund_date asc, source_row_no asc')
            ->select();
        return ['error' => '', 'rows' => $rows];
    }

    public function prepareGeneration($rows, $generateScope = 'SELECTED')
    {
        $generationCheck = $this->callHook('assert_rows_can_generate', [$rows, '退付记录']);
        if ($generationCheck !== null) {
            return $this->errorResult($generationCheck);
        }

        $bizType = $this->callHook('current_biz_type');
        $subjectConfigMap = $this->resolver->configsByBusinessItems($bizType, 'REFUND');
        foreach ($rows as $row) {
            $outTypeConfigKey = $this->policy->voucherSubjectConfigKey(
                $bizType,
                'REFUND',
                $row['out_type']
            );
            if (!isset($subjectConfigMap[$outTypeConfigKey])) {
                return $this->errorResult('出账查询未配置借方/贷方科目');
            }
            $debitCheck = $this->manager->validateVoucherSubjectCode(
                $subjectConfigMap[$outTypeConfigKey]['debit_subject_code'],
                '借方科目'
            );
            if ($debitCheck !== null) {
                return $this->errorResult('案号【' . $row['case_no'] . '】' . $debitCheck);
            }
            $creditCheck = $this->manager->validateVoucherSubjectCode(
                $subjectConfigMap[$outTypeConfigKey]['credit_subject_code'],
                '贷方科目'
            );
            if ($creditCheck !== null) {
                return $this->errorResult('案号【' . $row['case_no'] . '】' . $creditCheck);
            }
        }

        $byDayFlag = $this->manager->subjectConfigAccountSetFlag();
        $byOutOrderNoFlag = $this->manager->generateRefundVoucherByOutOrderNoFlag();
        try {
            $groups = $this->rule->groupRefundRowsForVoucher($rows, $byDayFlag, $byOutOrderNoFlag);
        } catch (\Exception $e) {
            return $this->errorResult($e->getMessage());
        }

        return [
            'error' => '',
            'rows' => $rows,
            'generate_scope' => $generateScope,
            'biz_type' => $bizType,
            'subject_config_map' => $subjectConfigMap,
            'by_day_flag' => $byDayFlag,
            'by_out_order_no_flag' => $byOutOrderNoFlag,
            'groups' => $groups,
        ];
    }

    /**
     * 调用方必须在数据库事务内执行。
     */
    public function generatePrepared($prepared)
    {
        $generatedCount = 0;
        $voucherInfos = [];

        foreach ($prepared['groups'] as $groupRows) {
            $firstRow = $groupRows[0];
            $period = substr($firstRow['refund_date'], 0, 7);
            $voucherDate = $firstRow['refund_date'];
            $aggregateByOutOrderNo = $prepared['by_day_flag'] === 1
                && $prepared['by_out_order_no_flag'] === 1;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $voucherDate)) {
                throw new \Exception('出账日期格式不正确：' . $voucherDate);
            }
            if (substr($voucherDate, 0, 7) !== $period) {
                throw new \Exception('出账日期不在会计期间内：' . $voucherDate);
            }
            if ($aggregateByOutOrderNo) {
                foreach ($groupRows as $row) {
                    if (substr($row['refund_date'], 0, 7) !== $period) {
                        throw new \Exception(
                            '同一出账单号【' . $firstRow['out_order_no']
                            . '】跨会计期间，不允许汇总生成一张凭证'
                        );
                    }
                }
            }

            $fiscalYear = $this->callHook('fiscal_year', [$period]);
            $voucherId = $this->callHook('new_id');
            $voucherNo = $this->callHook('generate_next_voucher_no', [$period]);
            $detailBuildResult = $this->rule->buildRefundVoucherDetailsData(
                $groupRows,
                $prepared['subject_config_map'],
                $aggregateByOutOrderNo,
                $prepared['biz_type']
            );
            $detailsData = $detailBuildResult['details'];
            $totalDebit = $detailBuildResult['total_cents'];
            if ($totalDebit <= 0) {
                throw new \Exception('凭证金额必须大于0');
            }
            $this->callHook('ensure_aux_archives', [$detailsData]);

            $voucher = [
                'voucher_id' => $voucherId,
                'account_set_id' => $this->callHook('account_set_id'),
                'fiscal_year' => $fiscalYear,
                'period' => $period,
                'voucher_date' => $voucherDate,
                'voucher_word' => '记',
                'voucher_no' => $voucherNo,
                'summary' => $this->rule->buildRefundVoucherHeaderSummary(
                    $firstRow,
                    $aggregateByOutOrderNo,
                    $voucherDate
                ),
                'debit_amount' => $this->callHook('cents_to_decimal', [$totalDebit]),
                'credit_amount' => $this->callHook('cents_to_decimal', [$totalDebit]),
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
                $auxValues = $detail['aux_values'];
                $detailRow = [
                    'detail_id' => $detail['detail_id'],
                    'account_set_id' => $this->callHook('account_set_id'),
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

                foreach ($auxValues as $aux) {
                    if (empty($aux['aux_type_code']) || !isset($aux['aux_value']) || $aux['aux_value'] === '') {
                        continue;
                    }
                    $auxRow = [
                        'id' => $this->callHook('new_id'),
                        'account_set_id' => $this->callHook('account_set_id'),
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

            foreach ($groupRows as $row) {
                $this->db($this->refundTable)->where([
                    'refund_id' => $row['refund_id'],
                    'account_set_id' => $this->callHook('account_set_id'),
                ])->update([
                    'voucher_status' => 'GENERATED',
                    'voucher_id' => $voucherId,
                    'voucher_no' => $voucherNo,
                    'voucher_period' => $period,
                    'voucher_generated_time' => $this->callHook('now'),
                    'updated_by' => $this->callHook('current_user_id'),
                    'updated_time' => $this->callHook('now'),
                ]);
            }

            $generatedCount++;
            $voucherInfos[] = [
                'voucher_id' => $voucherId,
                'voucher_no' => $voucherNo,
                'period' => $period,
            ];
        }

        return [
            'generated_count' => $generatedCount,
            'refund_count' => count($prepared['rows']),
            'generate_scope' => $prepared['generate_scope'],
            'vouchers' => $voucherInfos,
        ];
    }

    protected function errorResult($message)
    {
        return ['error' => $message, 'rows' => []];
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
