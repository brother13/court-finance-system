<?php

namespace app\finance\service\casefund\voucher;

/**
 * 缴费登记凭证生成应用服务。
 *
 * 权限、事务、审计和接口响应由调用方负责；本服务负责取数、校验、分组和事务内落库编排。
 */
class PaymentVoucherGenerationService
{
    protected $policy;
    protected $resolver;
    protected $manager;
    protected $rule;
    protected $persistence;
    protected $hooks;
    protected $paymentTable;
    protected $paymentField;

    public function __construct($policy, $resolver, $manager, $rule, $persistence, array $hooks, $paymentTable, array $paymentField)
    {
        foreach ([$policy, $resolver, $manager, $rule, $persistence] as $dependency) {
            if (!is_object($dependency)) {
                throw new \InvalidArgumentException('缴费凭证生成服务缺少协作对象');
            }
        }
        if (!method_exists($persistence, 'persistVoucher')) {
            throw new \InvalidArgumentException('缴费凭证生成服务缺少凭证持久化能力');
        }
        $requiredHooks = [
            'get_db',
            'account_where',
            'account_set_id',
            'current_year',
            'current_biz_type',
            'assert_rows_can_generate',
            'fiscal_year',
            'generate_next_voucher_no',
            'ensure_aux_archives',
            'new_id',
            'current_user_id',
            'now',
        ];
        foreach ($requiredHooks as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('缴费凭证生成服务缺少回调：' . $name);
            }
        }

        $this->policy = $policy;
        $this->resolver = $resolver;
        $this->manager = $manager;
        $this->rule = $rule;
        $this->persistence = $persistence;
        $this->hooks = $hooks;
        $this->paymentTable = $paymentTable;
        $this->paymentField = $paymentField;
    }

    public function selectedRows($paymentIds)
    {
        $paymentIds = $this->normalizeIds($paymentIds);
        if (empty($paymentIds)) {
            return $this->errorResult('请选择要生成凭证的缴费记录');
        }

        $rows = $this->db($this->paymentTable)
            ->where([
                'account_set_id' => $this->callHook('account_set_id'),
                'fiscal_year' => $this->callHook('current_year'),
                'del_flag' => 0,
            ])
            ->where('payment_id', 'in', $paymentIds)
            ->field($this->paymentField)
            ->order('payment_date asc, source_row_no asc')
            ->select();

        if (count($rows) !== count($paymentIds)) {
            return $this->errorResult('所选缴费记录不存在或已被删除，请刷新后重试');
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
        $paymentMethodFilter = trim((string)($data['payment_method'] ?? ($data['paymentMethod'] ?? '')));
        if ($paymentMethodFilter !== '') {
            $paymentMethod = $this->policy->normalizePaymentMethodCode($paymentMethodFilter);
            if (isset($this->policy->paymentMethodOptions()[$paymentMethod])) {
                $where['payment_method'] = $paymentMethod;
            }
        }
        if (!empty($data['date_start'])) {
            $where['payment_date'][] = ['>=', $data['date_start']];
        }
        if (!empty($data['date_end'])) {
            $where['payment_date'][] = ['<=', $data['date_end']];
        }

        $bizType = $this->callHook('current_biz_type');
        $allowedBusinessTypes = $this->policy->allowedPaymentBusinessTypes($bizType);
        if (empty($allowedBusinessTypes)) {
            return $this->errorResult('当前账套类型不支持案款缴费登记：' . $bizType);
        }
        $where['business_type'] = ['in', $allowedBusinessTypes];

        $keyword = trim((string)($data['keyword'] ?? ''));
        $query = $this->db($this->paymentTable)->where($where);
        if ($keyword !== '') {
            $query->where(
                'case_no|trial_case_no|payer_name|party_name|receipt_no|bank_serial_no|payment_order_no',
                'like',
                '%' . $keyword . '%'
            );
        }
        $rows = $query->field($this->paymentField)
            ->order('payment_date asc, source_row_no asc')
            ->select();
        return ['error' => '', 'rows' => $rows];
    }

    public function prepareGeneration($rows, $generateScope = 'SELECTED')
    {
        $generationCheck = $this->callHook('assert_rows_can_generate', [$rows, '缴费记录']);
        if ($generationCheck !== null) {
            return $this->errorResult($generationCheck);
        }

        $context = $this->resolveGenerationContext($rows);
        if (!empty($context['error'])) {
            return $this->errorResult($context['error']);
        }

        $byDayFlag = $this->manager->subjectConfigAccountSetFlag();
        $groups = $this->rule->groupPaymentRowsForVoucher(
            $rows,
            $byDayFlag,
            $context['by_payment_method_flag']
        );

        return [
            'error' => '',
            'rows' => $rows,
            'source_ids' => $this->rowIds($rows, 'payment_id'),
            'generate_scope' => $generateScope,
            'biz_type' => $context['biz_type'],
            'subject_config_map' => $context['subject_config_map'],
            'payment_method_config_map' => $context['payment_method_config_map'],
            'by_day_flag' => $byDayFlag,
            'by_payment_method_flag' => $context['by_payment_method_flag'],
            'by_payment_method' => $context['by_payment_method'],
            'groups' => $groups,
        ];
    }

    /**
     * 调用方必须在数据库事务内执行。
     */
    public function generatePrepared($prepared)
    {
        $rows = $this->lockPreparedRows($prepared);
        $generationCheck = $this->callHook('assert_rows_can_generate', [$rows, '缴费记录']);
        if ($generationCheck !== null) {
            throw new \Exception($generationCheck);
        }

        // 科目配置和生成参数可能在事务开始前发生变化，必须基于锁定后的源数据重新读取并校验。
        $context = $this->resolveGenerationContext($rows);
        if (!empty($context['error'])) {
            throw new \Exception($context['error']);
        }
        $byDayFlag = $this->manager->subjectConfigAccountSetFlag();
        $groups = $this->rule->groupPaymentRowsForVoucher(
            $rows,
            $byDayFlag,
            $context['by_payment_method_flag']
        );

        $voucherPlans = [];
        $allDetailsData = [];
        foreach ($groups as $groupRows) {
            $firstRow = $groupRows[0];
            $period = substr($firstRow['payment_date'], 0, 7);
            $voucherDate = $firstRow['payment_date'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $voucherDate)) {
                throw new \Exception('缴费日期格式不正确：' . $voucherDate);
            }
            if (substr($voucherDate, 0, 7) !== $period) {
                throw new \Exception('缴费日期不在会计期间内：' . $voucherDate);
            }

            $detailBuildResult = $this->rule->buildPaymentVoucherDetailsData(
                $groupRows,
                $context['subject_config_map'],
                $context['payment_method_config_map'],
                $context['by_payment_method'],
                $context['biz_type']
            );
            $totalDebit = $detailBuildResult['total_debit'];
            $detailsData = $detailBuildResult['details'];
            if ($totalDebit <= 0) {
                throw new \Exception('凭证金额必须大于0');
            }

            $voucherPlans[] = [
                'group_rows' => $groupRows,
                'first_row' => $firstRow,
                'period' => $period,
                'voucher_date' => $voucherDate,
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
                $voucherPlans[$index]['voucher_id'] = $this->newId();
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
                'summary' => $detailsData[0]['summary'] ?? $this->rule->buildPaymentVoucherSummary($firstRow),
                'total_cents' => $totalDebit,
            ], $detailsData);

            foreach ($groupRows as $row) {
                // 不限定 UNGENERATED：原凭证已删除或缺失时，GENERATED 源记录允许在锁后复核通过后重制。
                $affectedRows = $this->db($this->paymentTable)->where([
                    'payment_id' => $row['payment_id'],
                    'account_set_id' => $this->callHook('account_set_id'),
                    'fiscal_year' => $this->callHook('current_year'),
                    'del_flag' => 0,
                ])->update([
                    'voucher_status' => 'GENERATED',
                    'voucher_id' => $voucherId,
                    'voucher_no' => $voucherNo,
                    'voucher_period' => $period,
                    'voucher_generated_time' => $this->callHook('now'),
                    'updated_by' => $this->callHook('current_user_id'),
                    'updated_time' => $this->callHook('now'),
                ]);
                if ((int)$affectedRows !== 1) {
                    throw new \Exception('缴费记录状态已变化，请刷新后重试');
                }
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
            'payment_count' => count($rows),
            'generate_scope' => $prepared['generate_scope'],
            'vouchers' => $voucherInfos,
        ];
    }

    protected function lockPreparedRows($prepared)
    {
        $paymentIds = $this->normalizeIds($prepared['source_ids'] ?? []);
        if (empty($paymentIds)) {
            throw new \Exception('所选缴费记录不存在或已被删除，请刷新后重试');
        }

        $rows = $this->db($this->paymentTable)
            ->where([
                'account_set_id' => $this->callHook('account_set_id'),
                'fiscal_year' => $this->callHook('current_year'),
                'del_flag' => 0,
            ])
            ->where('payment_id', 'in', $paymentIds)
            ->field($this->paymentField)
            ->order('payment_date asc, source_row_no asc')
            ->lock(true)
            ->select();
        if (count($rows) !== count($paymentIds)) {
            throw new \Exception('所选缴费记录不存在或已被删除，请刷新后重试');
        }
        return $rows;
    }

    protected function resolveGenerationContext($rows)
    {
        $bizType = $this->callHook('current_biz_type');
        $subjectConfigMap = $this->resolver->configsByBusinessItems($bizType, 'PAYMENT');
        $byPaymentMethodFlag = (int)$this->manager->generatePaymentVoucherByMethodFlag();
        $paymentMethodConfigMap = $byPaymentMethodFlag === 1
            ? $this->resolver->paymentMethodConfigMap($bizType)
            : [];

        foreach ($rows as $row) {
            $businessTypeConfigKey = $this->policy->voucherSubjectConfigKey(
                $bizType,
                'PAYMENT',
                $row['business_type']
            );
            if (!isset($subjectConfigMap[$businessTypeConfigKey])) {
                return ['error' => '缴费登记未配置借方/贷方科目'];
            }
            $debitCheck = $this->manager->validateVoucherSubjectCode(
                $subjectConfigMap[$businessTypeConfigKey]['debit_subject_code'],
                '借方科目'
            );
            if ($debitCheck !== null) {
                return ['error' => '案号【' . $row['case_no'] . '】' . $debitCheck];
            }
            $creditCheck = $this->manager->validateVoucherSubjectCode(
                $subjectConfigMap[$businessTypeConfigKey]['credit_subject_code'],
                '贷方科目'
            );
            if ($creditCheck !== null) {
                return ['error' => '案号【' . $row['case_no'] . '】' . $creditCheck];
            }
            if ($byPaymentMethodFlag === 1) {
                $paymentMethod = $this->policy->normalizePaymentMethodCode($row['payment_method'] ?? '');
                if (!isset($paymentMethodConfigMap[$paymentMethod])) {
                    return [
                        'error' => '案号【' . $row['case_no'] . '】的收费方式【'
                            . $this->policy->paymentMethodLabel($paymentMethod)
                            . '】未配置借方科目',
                    ];
                }
                $methodDebitCheck = $this->manager->validateVoucherSubjectCode(
                    $paymentMethodConfigMap[$paymentMethod]['debit_subject_code'],
                    $this->policy->paymentMethodLabel($paymentMethod) . '借方科目'
                );
                if ($methodDebitCheck !== null) {
                    return ['error' => '案号【' . $row['case_no'] . '】' . $methodDebitCheck];
                }
            }
        }

        return [
            'error' => '',
            'biz_type' => $bizType,
            'subject_config_map' => $subjectConfigMap,
            'payment_method_config_map' => $paymentMethodConfigMap,
            'by_payment_method_flag' => $byPaymentMethodFlag,
            'by_payment_method' => $byPaymentMethodFlag === 1,
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

    protected function newId()
    {
        return $this->callHook('new_id');
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
