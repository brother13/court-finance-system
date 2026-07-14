<?php

namespace app\finance\service\casefund\voucher;

/**
 * 案款分配凭证生成应用服务。
 *
 * 权限、事务、审计和接口响应由调用方负责；锁行取数和最终写库必须在调用方事务内执行。
 */
class DistributionVoucherGenerationService
{
    protected $resolver;
    protected $manager;
    protected $rule;
    protected $persistence;
    protected $hooks;
    protected $distributionTable;
    protected $distributionField;

    public function __construct(
        $resolver,
        $manager,
        $rule,
        $persistence,
        array $hooks,
        $distributionTable,
        array $distributionField
    ) {
        foreach ([$resolver, $manager, $rule, $persistence] as $dependency) {
            if (!is_object($dependency)) {
                throw new \InvalidArgumentException('案款分配凭证生成服务缺少协作对象');
            }
        }
        if (!method_exists($persistence, 'persistVoucher')) {
            throw new \InvalidArgumentException('案款分配凭证生成服务缺少凭证持久化能力');
        }
        $requiredHooks = [
            'get_db',
            'account_set_id',
            'current_year',
            'current_biz_type',
            'normalize_id_list',
            'assert_rows_can_generate',
            'validate_period',
            'build_header_summary',
            'ensure_aux_archives',
            'fiscal_year',
            'generate_next_voucher_no',
            'new_id',
            'current_user_id',
            'now',
        ];
        foreach ($requiredHooks as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款分配凭证生成服务缺少回调：' . $name);
            }
        }

        $this->resolver = $resolver;
        $this->manager = $manager;
        $this->rule = $rule;
        $this->persistence = $persistence;
        $this->hooks = $hooks;
        $this->distributionTable = $distributionTable;
        $this->distributionField = $distributionField;
    }

    public function prepareGeneration($rawIds)
    {
        $distributionIds = $this->callHook('normalize_id_list', [$rawIds]);
        if (empty($distributionIds)) {
            return $this->errorResult('请选择要生成凭证的案款分配记录');
        }

        $bizType = $this->callHook('current_biz_type');
        if ($bizType !== 'CASE_FUND') {
            return $this->errorResult('当前账套类型不支持案款分配生成凭证：' . $bizType);
        }

        $subjectConfigMap = $this->resolver->directConfigsByBusinessItems(
            'CASE_FUND',
            'DISTRIBUTION'
        );
        if (empty($subjectConfigMap)) {
            return $this->errorResult('案款分配未配置借方/贷方科目');
        }

        return [
            'error' => '',
            'distribution_ids' => $distributionIds,
            'subject_config_map' => $subjectConfigMap,
        ];
    }

    /**
     * 调用方必须在数据库事务内执行，以保证锁行、凭证号、落库和源记录回写原子化。
     */
    public function generatePrepared($prepared)
    {
        $distributionIds = $prepared['distribution_ids'];
        $subjectConfigMap = $prepared['subject_config_map'];
        $rows = $this->db($this->distributionTable)
            ->where([
                'account_set_id' => $this->callHook('account_set_id'),
                'fiscal_year' => $this->callHook('current_year'),
                'del_flag' => 0,
            ])
            ->where('distribution_id', 'in', $distributionIds)
            ->field($this->distributionField)
            ->order('receive_date asc, distribution_no asc, source_row_no asc')
            ->lock(true)
            ->select();
        if (count($rows) !== count($distributionIds)) {
            throw new \Exception('所选案款分配记录不存在或已被删除，请刷新后重试');
        }

        foreach ($rows as &$row) {
            $row['case_no'] = $row['out_case_no'] ?? '';
        }
        unset($row);

        $generationCheck = $this->callHook('assert_rows_can_generate', [$rows, '案款分配记录']);
        if ($generationCheck !== null) {
            throw new \Exception($generationCheck);
        }
        foreach ($rows as $row) {
            $invoiceType = $row['invoice_type'];
            if (!isset($subjectConfigMap[$invoiceType])) {
                throw new \Exception(
                    '分配单号【' . $row['distribution_no'] . '】的开票种类【'
                    . $invoiceType . '】未配置借方/贷方科目'
                );
            }
            $debitCheck = $this->manager->validateVoucherSubjectCode(
                $subjectConfigMap[$invoiceType]['debit_subject_code'],
                '借方科目'
            );
            if ($debitCheck !== null) {
                throw new \Exception('分配单号【' . $row['distribution_no'] . '】' . $debitCheck);
            }
            $creditCheck = $this->manager->validateVoucherSubjectCode(
                $subjectConfigMap[$invoiceType]['credit_subject_code'],
                '贷方科目'
            );
            if ($creditCheck !== null) {
                throw new \Exception('分配单号【' . $row['distribution_no'] . '】' . $creditCheck);
            }
        }

        $groups = $this->rule->groupDistributionRowsForVoucher($rows);
        $generatedCount = 0;
        $voucherInfos = [];
        foreach ($groups as $groupRows) {
            $firstRow = $groupRows[0];
            $period = substr($firstRow['receive_date'], 0, 7);
            $voucherDate = $firstRow['receive_date'];
            foreach ($groupRows as $row) {
                $periodCheck = $this->callHook('validate_period', [$row, $period]);
                if ($periodCheck !== null) {
                    throw new \Exception($periodCheck);
                }
            }

            $fiscalYear = $this->callHook('fiscal_year', [$period]);
            $voucherId = $this->callHook('new_id');
            $voucherNo = $this->callHook('generate_next_voucher_no', [$period]);
            $detailBuildResult = $this->rule->buildDistributionVoucherDetailsData(
                $groupRows,
                $subjectConfigMap
            );
            $detailsData = $detailBuildResult['details'];
            $totalDebit = $detailBuildResult['total_cents'];
            if ($totalDebit <= 0) {
                throw new \Exception('凭证金额必须大于0');
            }
            $this->callHook('ensure_aux_archives', [$detailsData]);

            $this->persistence->persistVoucher([
                'voucher_id' => $voucherId,
                'fiscal_year' => $fiscalYear,
                'period' => $period,
                'voucher_date' => $voucherDate,
                'voucher_no' => $voucherNo,
                'summary' => $this->callHook('build_header_summary', [$firstRow]),
                'total_cents' => $totalDebit,
            ], $detailsData);

            foreach ($groupRows as $row) {
                $affectedRows = $this->db($this->distributionTable)->where([
                    'distribution_id' => $row['distribution_id'],
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
                    throw new \Exception('案款分配记录状态已变化，请刷新后重试');
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
            'distribution_count' => count($rows),
            'vouchers' => $voucherInfos,
        ];
    }

    protected function errorResult($message)
    {
        return ['error' => $message];
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
