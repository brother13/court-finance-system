<?php

namespace app\finance\service\casefund\voucher;

/**
 * 不明款凭证生成应用服务。
 *
 * 权限、事务、审计和接口响应由调用方负责；锁行取数和最终写库必须在调用方事务内执行。
 */
class UnknownPaymentVoucherGenerationService
{
    protected $manager;
    protected $rule;
    protected $hooks;
    protected $unknownPaymentTable;
    protected $unknownPaymentField;
    protected $subjectConfigTable;
    protected $subjectConfigField;

    public function __construct(
        $manager,
        $rule,
        array $hooks,
        $unknownPaymentTable,
        array $unknownPaymentField,
        $subjectConfigTable,
        array $subjectConfigField
    ) {
        if (!is_object($manager) || !is_object($rule)) {
            throw new \InvalidArgumentException('不明款凭证生成服务缺少协作对象');
        }
        $requiredHooks = [
            'get_db',
            'account_set_id',
            'current_year',
            'current_biz_type',
            'normalize_id_list',
            'get_subject_aux_configs',
            'has_required_aux_configs',
            'assert_rows_can_generate',
            'validate_period',
            'build_summary',
            'fiscal_year',
            'generate_next_voucher_no',
            'cents_to_decimal',
            'new_id',
            'prepare_create',
            'current_user_id',
            'now',
        ];
        foreach ($requiredHooks as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('不明款凭证生成服务缺少回调：' . $name);
            }
        }

        $this->manager = $manager;
        $this->rule = $rule;
        $this->hooks = $hooks;
        $this->unknownPaymentTable = $unknownPaymentTable;
        $this->unknownPaymentField = $unknownPaymentField;
        $this->subjectConfigTable = $subjectConfigTable;
        $this->subjectConfigField = $subjectConfigField;
    }

    public function prepareGeneration($rawIds)
    {
        $unknownPaymentIds = $this->callHook('normalize_id_list', [$rawIds]);
        if (empty($unknownPaymentIds)) {
            return $this->errorResult('请选择要生成凭证的不明款记录');
        }

        $bizType = $this->callHook('current_biz_type');
        if ($bizType !== 'CASE_FUND') {
            return $this->errorResult('当前账套类型不支持不明款生成凭证：' . $bizType);
        }

        $subjectConfig = $this->db($this->subjectConfigTable)
            ->where([
                'account_set_id' => $this->callHook('account_set_id'),
                'biz_type' => 'CASE_FUND',
                'voucher_biz_type' => 'UNKNOWN_PAYMENT',
                'business_item_type' => '不明款',
                'del_flag' => 0,
            ])
            ->field($this->subjectConfigField)
            ->find();
        if (!$subjectConfig) {
            return $this->errorResult('不明款未配置借方/贷方科目');
        }

        $debitCheck = $this->manager->validateVoucherSubjectCode(
            $subjectConfig['debit_subject_code'],
            '借方科目'
        );
        if ($debitCheck !== null) {
            return $this->errorResult($debitCheck);
        }
        $creditCheck = $this->manager->validateVoucherSubjectCode(
            $subjectConfig['credit_subject_code'],
            '贷方科目'
        );
        if ($creditCheck !== null) {
            return $this->errorResult($creditCheck);
        }

        $debitConfigs = $this->callHook('get_subject_aux_configs', [
            $subjectConfig['debit_subject_code'],
        ]);
        $creditConfigs = $this->callHook('get_subject_aux_configs', [
            $subjectConfig['credit_subject_code'],
        ]);
        if (
            $this->callHook('has_required_aux_configs', [$debitConfigs])
            || $this->callHook('has_required_aux_configs', [$creditConfigs])
        ) {
            return $this->errorResult('不明款借方/贷方科目存在必填辅助核算，不允许自动生成凭证');
        }

        return [
            'error' => '',
            'unknown_payment_ids' => $unknownPaymentIds,
            'subject_config' => $subjectConfig,
            'by_day_flag' => $this->manager->subjectConfigAccountSetFlag(),
        ];
    }

    /**
     * 调用方必须在数据库事务内执行，以保证锁行、凭证号、落库和源记录回写原子化。
     */
    public function generatePrepared($prepared)
    {
        $unknownPaymentIds = $prepared['unknown_payment_ids'];
        $rows = $this->db($this->unknownPaymentTable)
            ->where([
                'account_set_id' => $this->callHook('account_set_id'),
                'fiscal_year' => $this->callHook('current_year'),
                'del_flag' => 0,
            ])
            ->where('unknown_payment_id', 'in', $unknownPaymentIds)
            ->field($this->unknownPaymentField)
            ->order('transaction_date asc, source_row_no asc')
            ->lock(true)
            ->select();
        if (count($rows) !== count($unknownPaymentIds)) {
            throw new \Exception('所选不明款记录不存在或已被删除，请刷新后重试');
        }

        foreach ($rows as &$row) {
            $row['case_no'] = '不明款';
        }
        unset($row);

        $generationCheck = $this->callHook('assert_rows_can_generate', [$rows, '不明款记录']);
        if ($generationCheck !== null) {
            throw new \Exception($generationCheck);
        }
        $groups = $this->rule->groupUnknownPaymentRowsForVoucher(
            $rows,
            $prepared['by_day_flag']
        );

        $generatedCount = 0;
        $voucherInfos = [];
        foreach ($groups as $groupRows) {
            $firstRow = $groupRows[0];
            $period = substr($firstRow['transaction_date'], 0, 7);
            $voucherDate = $firstRow['transaction_date'];
            foreach ($groupRows as $row) {
                $periodCheck = $this->callHook('validate_period', [$row, $period]);
                if ($periodCheck !== null) {
                    throw new \Exception($periodCheck);
                }
            }

            $fiscalYear = $this->callHook('fiscal_year', [$period]);
            $voucherId = $this->callHook('new_id');
            $voucherNo = $this->callHook('generate_next_voucher_no', [$period]);
            $detailBuildResult = $this->rule->buildUnknownPaymentVoucherDetailsData(
                $groupRows,
                $prepared['subject_config']
            );
            $detailsData = $detailBuildResult['details'];
            $totalDebit = $detailBuildResult['total_cents'];
            if ($totalDebit <= 0) {
                throw new \Exception('凭证金额必须大于0');
            }

            $voucher = [
                'voucher_id' => $voucherId,
                'account_set_id' => $this->callHook('account_set_id'),
                'fiscal_year' => $fiscalYear,
                'period' => $period,
                'voucher_date' => $voucherDate,
                'voucher_word' => '记',
                'voucher_no' => $voucherNo,
                'summary' => $this->callHook('build_summary', [$firstRow]),
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
            }

            foreach ($groupRows as $row) {
                $affectedRows = $this->db($this->unknownPaymentTable)->where([
                    'unknown_payment_id' => $row['unknown_payment_id'],
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
                    throw new \Exception('不明款记录状态已变化，请刷新后重试');
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
            'unknown_payment_count' => count($rows),
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
