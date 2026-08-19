<?php

namespace app\finance\service\casefund\voucher;

/**
 * 退付登记凭证生成应用服务。
 *
 * 权限、事务、审计和接口响应由调用方负责；本服务负责取数、校验、分组和事务内落库编排。
 */
class RefundVoucherGenerationService
{
    const MAX_QUERY_ROWS = 5000;
    const RETURN_MATCH_TABLE = 'fin_case_fund_unknown_refund_match';

    protected $policy;
    protected $resolver;
    protected $manager;
    protected $rule;
    protected $persistence;
    protected $hooks;
    protected $refundTable;
    protected $refundField;

    public function __construct($policy, $resolver, $manager, $rule, $persistence, array $hooks, $refundTable, array $refundField)
    {
        foreach ([$policy, $resolver, $manager, $rule, $persistence] as $dependency) {
            if (!is_object($dependency)) {
                throw new \InvalidArgumentException('退付凭证生成服务缺少协作对象');
            }
        }
        if (!method_exists($persistence, 'persistVoucher')) {
            throw new \InvalidArgumentException('退付凭证生成服务缺少凭证持久化能力');
        }
        $requiredHooks = [
            'get_db',
            'account_where',
            'account_set_id',
            'current_year',
            'current_biz_type',
            'normalize_date_filter',
            'assert_rows_can_generate',
            'assert_periods_open',
            'fiscal_year',
            'generate_next_voucher_no',
            'ensure_aux_archives',
            'new_id',
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
        $this->persistence = $persistence;
        $this->hooks = $hooks;
        $this->refundTable = $refundTable;
        $this->refundField = $refundField;
    }

    public function selectedRows($refundIds, $excludeConfirmedReturns = true)
    {
        $refundIds = $this->normalizeIds($refundIds);
        if (empty($refundIds)) {
            return $this->errorResult('请选择要生成凭证的退付记录');
        }

        $rows = $this->rowsByIds($refundIds, false);
        if (count($rows) !== count($refundIds)) {
            return $this->errorResult('所选退付记录不存在或已被删除，请刷新后重试');
        }
        if ($excludeConfirmedReturns && $this->skipConfirmedReturns()) {
            $confirmedIds = $this->confirmedReturnRefundIds($refundIds, false);
            if (!empty($confirmedIds)) {
                $rows = array_values(array_filter($rows, function ($row) use ($confirmedIds) {
                    return !isset($confirmedIds[$row['refund_id'] ?? '']);
                }));
            }
            if (empty($rows)) {
                return $this->errorResult('所选出账记录均为已确认退汇，当前科目配置不生成凭证');
            }
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
        $this->applyAmountRange($where, 'refund_amount', $data);
        $snapshotTime = trim((string)($data['scope_snapshot_time'] ?? ''));
        if ($snapshotTime !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $snapshotTime)) {
            $where['created_time'] = ['<=', $snapshotTime];
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
        if ($this->skipConfirmedReturns()) {
            $confirmedIds = array_keys($this->confirmedReturnRefundIds([], false));
            if (!empty($confirmedIds)) {
                $query->where('refund_id', 'not in', $confirmedIds);
            }
        }
        $rows = $query->field($this->refundField)
            ->order('refund_date asc, source_row_no asc')
            ->limit(self::MAX_QUERY_ROWS + 1)
            ->select();
        if (count($rows) > self::MAX_QUERY_ROWS) {
            return $this->errorResult(
                '单个后台制证任务最多处理' . self::MAX_QUERY_ROWS . '条记录，请缩小查询范围后重新提交'
            );
        }
        return ['error' => '', 'rows' => $rows];
    }

    protected function applyAmountRange(&$where, $field, $data)
    {
        $amountMin = trim((string)($data['amount_min'] ?? ($data['amountMin'] ?? '')));
        $amountMax = trim((string)($data['amount_max'] ?? ($data['amountMax'] ?? '')));
        if ($amountMin !== '' && $amountMax !== '') {
            $where[$field] = [['>=', $amountMin], ['<=', $amountMax]];
        } elseif ($amountMin !== '') {
            $where[$field] = ['>=', $amountMin];
        } elseif ($amountMax !== '') {
            $where[$field] = ['<=', $amountMax];
        }
    }

    public function prepareGeneration($rows, $generateScope = 'SELECTED')
    {
        $confirmedReturnError = $this->confirmedReturnVoucherError($rows, false);
        if ($confirmedReturnError !== null) {
            return $this->errorResult($confirmedReturnError);
        }
        $generationCheck = $this->callHook('assert_rows_can_generate', [$rows, '退付记录']);
        if ($generationCheck !== null) {
            return $this->errorResult($generationCheck);
        }

        $context = $this->resolveGenerationContext($rows);
        if (!empty($context['error'])) {
            return $this->errorResult($context['error']);
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
            'source_ids' => $this->rowIds($rows, 'refund_id'),
            'generate_scope' => $generateScope,
            'biz_type' => $context['biz_type'],
            'subject_config_map' => $context['subject_config_map'],
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
        $confirmedReturnError = $this->confirmedReturnVoucherError($prepared['rows'] ?? [], true);
        if ($confirmedReturnError !== null) {
            throw new \Exception($confirmedReturnError);
        }
        $rows = $this->lockPreparedRows($prepared);
        $this->callHook('assert_periods_open', [$rows, 'refund_date', '退付']);
        $generationCheck = $this->callHook('assert_rows_can_generate', [$rows, '退付记录']);
        if ($generationCheck !== null) {
            throw new \Exception($generationCheck);
        }

        // 科目配置和分组参数可能在事务开始前发生变化，必须基于锁定后的源数据重新读取并校验。
        $context = $this->resolveGenerationContext($rows);
        if (!empty($context['error'])) {
            throw new \Exception($context['error']);
        }
        $byDayFlag = (int)$this->manager->subjectConfigAccountSetFlag();
        $byOutOrderNoFlag = (int)$this->manager->generateRefundVoucherByOutOrderNoFlag();
        $groups = $this->rule->groupRefundRowsForVoucher($rows, $byDayFlag, $byOutOrderNoFlag);

        $voucherPlans = [];
        $allDetailsData = [];
        foreach ($groups as $groupRows) {
            $firstRow = $groupRows[0];
            $period = substr($firstRow['refund_date'], 0, 7);
            $voucherDate = $firstRow['refund_date'];
            $aggregateByOutOrderNo = $byDayFlag === 1 && $byOutOrderNoFlag === 1;

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

            $detailBuildResult = $this->rule->buildRefundVoucherDetailsData(
                $groupRows,
                $context['subject_config_map'],
                $aggregateByOutOrderNo,
                $context['biz_type']
            );
            $detailsData = $detailBuildResult['details'];
            $totalDebit = $detailBuildResult['total_cents'];
            if ($totalDebit <= 0) {
                throw new \Exception('凭证金额必须大于0');
            }

            $voucherPlans[] = [
                'group_rows' => $groupRows,
                'first_row' => $firstRow,
                'period' => $period,
                'voucher_date' => $voucherDate,
                'aggregate_by_out_order_no' => $aggregateByOutOrderNo,
                'total_cents' => $totalDebit,
                'details' => $detailsData,
            ];
            $allDetailsData = array_merge($allDetailsData, $detailsData);
        }

        // 与通用凭证保存保持一致：先按期间稳定顺序取完本批凭证号，再准备辅助档案。
        $planIndexesByPeriod = [];
        foreach ($voucherPlans as $index => $plan) {
            $planIndexesByPeriod[$plan['period']][] = $index;
        }
        ksort($planIndexesByPeriod, SORT_STRING);
        foreach ($planIndexesByPeriod as $period => $planIndexes) {
            foreach ($planIndexes as $index) {
                $voucherPlans[$index]['fiscal_year'] = $this->callHook('fiscal_year', [$period]);
                $voucherPlans[$index]['voucher_id'] = $this->callHook('new_id');
                $voucherPlans[$index]['voucher_no'] = $this->callHook('generate_next_voucher_no', [$period]);
            }
        }
        $this->callHook('ensure_aux_archives', [$allDetailsData]);

        $generatedCount = 0;
        $voucherInfos = [];
        foreach ($voucherPlans as $plan) {
            $groupRows = $plan['group_rows'];
            $firstRow = $plan['first_row'];
            $period = $plan['period'];
            $voucherDate = $plan['voucher_date'];
            $aggregateByOutOrderNo = $plan['aggregate_by_out_order_no'];
            $totalDebit = $plan['total_cents'];
            $detailsData = $plan['details'];
            $fiscalYear = $plan['fiscal_year'];
            $voucherId = $plan['voucher_id'];
            $voucherNo = $plan['voucher_no'];

            $this->persistence->persistVoucher([
                'voucher_id' => $voucherId,
                'fiscal_year' => $fiscalYear,
                'period' => $period,
                'voucher_date' => $voucherDate,
                'voucher_no' => $voucherNo,
                'summary' => $this->rule->buildRefundVoucherHeaderSummary(
                    $firstRow,
                    $aggregateByOutOrderNo,
                    $voucherDate
                ),
                'total_cents' => $totalDebit,
            ], $detailsData);

            $groupRefundIds = $this->rowIds($groupRows, 'refund_id');
            // 不限定 UNGENERATED：原凭证已删除或缺失时，GENERATED 源记录允许在锁后复核通过后重制。
            $affectedRows = $this->db($this->refundTable)->where([
                'account_set_id' => $this->callHook('account_set_id'),
                'fiscal_year' => $this->callHook('current_year'),
                'del_flag' => 0,
            ])->where('refund_id', 'in', $groupRefundIds)->update([
                'voucher_status' => 'GENERATED',
                'voucher_id' => $voucherId,
                'voucher_no' => $voucherNo,
                'voucher_period' => $period,
                'voucher_generated_time' => $this->callHook('now'),
                'updated_by' => $this->callHook('current_user_id'),
                'updated_time' => $this->callHook('now'),
            ]);
            if ((int)$affectedRows !== count($groupRefundIds)) {
                throw new \Exception('退付记录状态已变化，请刷新后重试');
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
            'refund_count' => count($rows),
            'generate_scope' => $prepared['generate_scope'],
            'vouchers' => $voucherInfos,
        ];
    }

    protected function lockPreparedRows($prepared)
    {
        $refundIds = $this->normalizeIds($prepared['source_ids'] ?? []);
        if (empty($refundIds)) {
            throw new \Exception('所选退付记录不存在或已被删除，请刷新后重试');
        }

        $rows = $this->rowsByIds($refundIds, true);
        if (count($rows) !== count($refundIds)) {
            throw new \Exception('所选退付记录不存在或已被删除，请刷新后重试');
        }
        return $rows;
    }

    protected function rowsByIds(array $refundIds, $lock)
    {
        $rows = [];
        foreach (array_chunk($refundIds, 500) as $batch) {
            $query = $this->db($this->refundTable)
                ->where([
                    'account_set_id' => $this->callHook('account_set_id'),
                    'fiscal_year' => $this->callHook('current_year'),
                    'del_flag' => 0,
                ])
                ->where('refund_id', 'in', $batch)
                ->field($this->refundField);
            if ($lock) {
                $query->lock(true);
            }
            $rows = array_merge($rows, $query->select());
        }
        usort($rows, function ($left, $right) {
            foreach (['refund_date', 'source_row_no', 'refund_id'] as $field) {
                $comparison = ($left[$field] ?? '') <=> ($right[$field] ?? '');
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return 0;
        });
        return $rows;
    }

    protected function resolveGenerationContext($rows)
    {
        $bizType = $this->callHook('current_biz_type');
        $subjectConfigMap = $this->resolver->configsByBusinessItems($bizType, 'REFUND');
        foreach ($rows as $row) {
            if (trim((string)($row['payee_bank_account_name'] ?? '')) === '') {
                return ['error' => '案号【' . ($row['case_no'] ?? '') . '】收款人银行户名不能为空，不能生成凭证'];
            }
            $outTypeConfigKey = $this->policy->voucherSubjectConfigKey(
                $bizType,
                'REFUND',
                $row['out_type']
            );
            if (!isset($subjectConfigMap[$outTypeConfigKey])) {
                return ['error' => '出账查询未配置借方/贷方科目'];
            }
            $debitCheck = $this->manager->validateVoucherSubjectCode(
                $subjectConfigMap[$outTypeConfigKey]['debit_subject_code'],
                '借方科目'
            );
            if ($debitCheck !== null) {
                return ['error' => '案号【' . $row['case_no'] . '】' . $debitCheck];
            }
            $creditCheck = $this->manager->validateVoucherSubjectCode(
                $subjectConfigMap[$outTypeConfigKey]['credit_subject_code'],
                '贷方科目'
            );
            if ($creditCheck !== null) {
                return ['error' => '案号【' . $row['case_no'] . '】' . $creditCheck];
            }
        }

        return [
            'error' => '',
            'biz_type' => $bizType,
            'subject_config_map' => $subjectConfigMap,
        ];
    }

    protected function rowIds($rows, $idField)
    {
        $ids = [];
        foreach ((array)$rows as $row) {
            if (isset($row[$idField])) {
                $ids[] = $row[$idField];
            }
        }
        return $this->normalizeIds($ids);
    }

    protected function skipConfirmedReturns()
    {
        return (int)$this->manager->skipConfirmedReturnVoucherFlag() === 1;
    }

    protected function confirmedReturnVoucherError($rows, $lock)
    {
        if (!$this->skipConfirmedReturns()) {
            return null;
        }
        $refundIds = $this->rowIds($rows, 'refund_id');
        if (empty($refundIds)) {
            return null;
        }
        $confirmedIds = $this->confirmedReturnRefundIds($refundIds, $lock);
        if (empty($confirmedIds)) {
            return null;
        }
        foreach ($rows as $row) {
            $refundId = trim((string)($row['refund_id'] ?? ''));
            if ($refundId !== '' && isset($confirmedIds[$refundId])) {
                $caseNo = trim((string)($row['case_no'] ?? ''));
                return '案号【' . ($caseNo !== '' ? $caseNo : $refundId)
                    . '】的出账记录已确认退汇，当前科目配置不生成凭证';
            }
        }
        return '存在已确认退汇的出账记录，当前科目配置不生成凭证';
    }

    protected function confirmedReturnRefundIds(array $refundIds = [], $lock = false)
    {
        $query = $this->db(self::RETURN_MATCH_TABLE)->where([
            'account_set_id' => $this->callHook('account_set_id'),
            'fiscal_year' => $this->callHook('current_year'),
            'del_flag' => 0,
        ]);
        if (!empty($refundIds)) {
            $query->where('refund_id', 'in', $refundIds);
        }
        if (!$lock) {
            $query->where('match_status', 'CONFIRMED');
        } else {
            $query->lock(true);
        }
        $rows = $query->field('refund_id,match_status')->select();
        $confirmedIds = [];
        foreach ($rows as $row) {
            if (($row['match_status'] ?? '') === 'CONFIRMED' && !empty($row['refund_id'])) {
                $confirmedIds[$row['refund_id']] = true;
            }
        }
        return $confirmedIds;
    }

    protected function normalizeIds($ids)
    {
        if (!is_array($ids)) {
            return [];
        }
        $normalized = [];
        foreach ($ids as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $normalized[$id] = true;
            }
        }
        return array_keys($normalized);
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
