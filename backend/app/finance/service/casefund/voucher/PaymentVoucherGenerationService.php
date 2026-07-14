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
    protected $hooks;
    protected $paymentTable;
    protected $paymentField;

    public function __construct($policy, $resolver, $manager, $rule, array $hooks, $paymentTable, array $paymentField)
    {
        foreach ([$policy, $resolver, $manager, $rule] as $dependency) {
            if (!is_object($dependency)) {
                throw new \InvalidArgumentException('缴费凭证生成服务缺少协作对象');
            }
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
            'cents_to_decimal',
            'new_id',
            'prepare_create',
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
        $this->hooks = $hooks;
        $this->paymentTable = $paymentTable;
        $this->paymentField = $paymentField;
    }

    public function selectedRows($paymentIds)
    {
        if (!is_array($paymentIds) || empty($paymentIds)) {
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
            ->select();

        if (empty($rows)) {
            return $this->errorResult('所选缴费记录不存在');
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

        $bizType = $this->callHook('current_biz_type');
        $subjectConfigMap = $this->resolver->configsByBusinessItems($bizType, 'PAYMENT');
        $byPaymentMethodFlag = $this->manager->generatePaymentVoucherByMethodFlag();
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
                return $this->errorResult('缴费登记未配置借方/贷方科目');
            }
            $debitCheck = $this->manager->validateVoucherSubjectCode(
                $subjectConfigMap[$businessTypeConfigKey]['debit_subject_code'],
                '借方科目'
            );
            if ($debitCheck !== null) {
                return $this->errorResult('案号【' . $row['case_no'] . '】' . $debitCheck);
            }
            $creditCheck = $this->manager->validateVoucherSubjectCode(
                $subjectConfigMap[$businessTypeConfigKey]['credit_subject_code'],
                '贷方科目'
            );
            if ($creditCheck !== null) {
                return $this->errorResult('案号【' . $row['case_no'] . '】' . $creditCheck);
            }
            if ($byPaymentMethodFlag === 1) {
                $paymentMethod = $this->policy->normalizePaymentMethodCode($row['payment_method'] ?? '');
                if (!isset($paymentMethodConfigMap[$paymentMethod])) {
                    return $this->errorResult(
                        '案号【' . $row['case_no'] . '】的收费方式【'
                        . $this->policy->paymentMethodLabel($paymentMethod)
                        . '】未配置借方科目'
                    );
                }
                $methodDebitCheck = $this->manager->validateVoucherSubjectCode(
                    $paymentMethodConfigMap[$paymentMethod]['debit_subject_code'],
                    $this->policy->paymentMethodLabel($paymentMethod) . '借方科目'
                );
                if ($methodDebitCheck !== null) {
                    return $this->errorResult('案号【' . $row['case_no'] . '】' . $methodDebitCheck);
                }
            }
        }

        $byDayFlag = $this->manager->subjectConfigAccountSetFlag();
        $groups = $this->rule->groupPaymentRowsForVoucher(
            $rows,
            $byDayFlag,
            $byPaymentMethodFlag
        );

        return [
            'error' => '',
            'rows' => $rows,
            'generate_scope' => $generateScope,
            'biz_type' => $bizType,
            'subject_config_map' => $subjectConfigMap,
            'payment_method_config_map' => $paymentMethodConfigMap,
            'by_payment_method' => $byPaymentMethodFlag === 1,
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
            $period = substr($firstRow['payment_date'], 0, 7);
            $voucherDate = $firstRow['payment_date'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $voucherDate)) {
                throw new \Exception('缴费日期格式不正确：' . $voucherDate);
            }
            if (substr($voucherDate, 0, 7) !== $period) {
                throw new \Exception('缴费日期不在会计期间内：' . $voucherDate);
            }

            $fiscalYear = $this->callHook('fiscal_year', [$period]);
            $voucherId = $this->newId();
            $voucherNo = $this->callHook('generate_next_voucher_no', [$period]);
            $detailBuildResult = $this->rule->buildPaymentVoucherDetailsData(
                $groupRows,
                $prepared['subject_config_map'],
                $prepared['payment_method_config_map'],
                $prepared['by_payment_method'],
                $prepared['biz_type']
            );
            $totalDebit = $detailBuildResult['total_debit'];
            $detailsData = $detailBuildResult['details'];
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
                'summary' => $detailsData[0]['summary'] ?? $this->rule->buildPaymentVoucherSummary($firstRow),
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
                        'id' => $this->newId(),
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
                $this->db($this->paymentTable)->where([
                    'payment_id' => $row['payment_id'],
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
            'payment_count' => count($prepared['rows']),
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

    protected function newId()
    {
        return $this->callHook('new_id');
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
