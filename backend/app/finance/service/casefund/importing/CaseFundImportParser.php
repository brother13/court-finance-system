<?php

namespace app\finance\service\casefund\importing;

/**
 * 只负责已读取表格的表头识别、行跳过和字段映射；不查询数据库，也不执行导入落库。
 */
class CaseFundImportParser
{
    protected $hooks;

    public function __construct(array $hooks)
    {
        $requiredHooks = [
            'normalize_cell',
            'is_empty_row',
            'is_malformed_row',
            'is_non_detail_payment_row',
            'is_non_detail_refund_row',
            'nullable_date',
            'normalize_datetime',
            'normalize_date',
            'canonical_amount',
            'yes_no_flag',
            'infer_payment_method',
            'refund_required_headers',
            'payment_fingerprint',
            'refund_fingerprint',
            'unknown_payment_fingerprint',
            'distribution_fingerprint',
        ];
        foreach ($requiredHooks as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款导入解析器缺少回调：' . $name);
            }
        }
        $this->hooks = $hooks;
    }

    public function parsePaymentCells($cells)
    {
        if (empty($cells)) {
            throw new \Exception('文件中没有可识别的工作表数据');
        }
        list($maxRow, $maxCol) = $this->biffBounds($cells);
        $headers = $this->normalizedBiffHeaders($cells, $maxCol);
        $required = [
            '案号', '确', '可', '业务类型', '缴费人', '当事人', '开票抬头', '金额',
            '登记类型', '前审案号', '缴费日期', '票据号码', '开票日期', '开票员',
            '收费方式', '收费员', '承办法官', '书记员', '承办部门', '收款账号',
            '银行流水号', '缴费单号', '内转支出票号', '是否提存撤销',
        ];
        $index = $this->headerIndex($headers, $required);

        $rows = [];
        for ($row = 1; $row <= $maxRow; $row++) {
            $raw = $this->normalizedBiffRow($cells, $row, $index);
            if ($this->callHook('is_empty_row', [$raw])) {
                continue;
            }
            if ($this->callHook('is_malformed_row', [$raw])) {
                continue;
            }
            if ($this->callHook('is_non_detail_payment_row', [$raw])) {
                continue;
            }
            $invoiceDate = $this->callHook('nullable_date', [$raw['开票日期']]);
            $paymentTime = $this->callHook('normalize_datetime', [$raw['缴费日期']]);
            $paymentDate = $paymentTime === '' ? '' : substr($paymentTime, 0, 10);
            $amount = $this->callHook('canonical_amount', [$raw['金额']]);
            $period = $paymentDate === '' ? '' : substr($paymentDate, 0, 7);
            $parsed = [
                'fiscal_year' => $paymentDate === '' ? '' : substr($paymentDate, 0, 4),
                'period' => $period,
                'case_no' => $raw['案号'],
                'confirmed_flag' => $this->callHook('yes_no_flag', [$raw['确']]),
                'available_flag' => $this->callHook('yes_no_flag', [$raw['可']]),
                'business_type' => $raw['业务类型'],
                'payer_name' => $raw['缴费人'],
                'party_name' => $raw['当事人'],
                'invoice_title' => $raw['开票抬头'],
                'payment_amount' => $amount,
                'register_type' => $raw['登记类型'],
                'trial_case_no' => $raw['前审案号'],
                'payment_date' => $paymentDate,
                'payment_time' => $paymentTime,
                'receipt_no' => $raw['票据号码'],
                'invoice_date' => $invoiceDate,
                'invoice_operator' => $raw['开票员'],
                'payment_method' => $this->callHook('infer_payment_method', [$raw['银行流水号'], $raw['收费方式']]),
                'cashier_name' => $raw['收费员'],
                'judge_name' => $raw['承办法官'],
                'clerk_name' => $raw['书记员'],
                'department_name' => $raw['承办部门'],
                'bank_account_no' => $raw['收款账号'],
                'bank_serial_no' => $raw['银行流水号'],
                'payment_order_no' => $raw['缴费单号'],
                'internal_transfer_ticket_no' => $raw['内转支出票号'],
                'deposit_revoke_flag' => $this->callHook('yes_no_flag', [$raw['是否提存撤销']]),
                'source_row_no' => $row + 1,
                'source_raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE),
            ];
            $parsed['source_fingerprint'] = $this->callHook('payment_fingerprint', [$parsed]);
            $rows[] = $parsed;
        }
        return $rows;
    }

    public function parseRefundCells($cells, $bizType = '', $receiptTraceRequired = null)
    {
        if (empty($cells)) {
            throw new \Exception('文件中没有可识别的工作表数据');
        }
        list($maxRow, $maxCol) = $this->biffBounds($cells);
        $headers = $this->normalizedBiffHeaders($cells, $maxCol);
        $requiredHeaders = $this->callHook('refund_required_headers', [$bizType, $receiptTraceRequired]);
        $optional = ['总出账金额'];
        if ($bizType === 'LITIGATION_FEE' || $receiptTraceRequired === false) {
            foreach (['来源票据号码', '来源票据开票日期'] as $name) {
                $optional[] = $name;
            }
        }
        if ($bizType === 'CASE_FUND' && $receiptTraceRequired === false) {
            $optional[] = '出账单号';
        }
        $index = $this->headerIndex($headers, $requiredHeaders, $optional);

        $rows = [];
        for ($row = 1; $row <= $maxRow; $row++) {
            $raw = $this->normalizedBiffRow($cells, $row, $index);
            foreach ($optional as $name) {
                if (!isset($raw[$name])) {
                    $raw[$name] = '';
                }
            }
            if ($this->callHook('is_empty_row', [$raw])) {
                continue;
            }
            if ($this->callHook('is_malformed_row', [$raw])) {
                continue;
            }
            if ($this->callHook('is_non_detail_refund_row', [$raw])) {
                continue;
            }
            $refundDate = $this->callHook('normalize_date', [$raw['出账日期']]);
            $invoiceDate = $this->callHook('nullable_date', [$raw['开票日期']]);
            $sourceReceiptDate = $this->callHook('nullable_date', [$raw['来源票据开票日期']]);
            $refundAmount = $this->callHook('canonical_amount', [$raw['出账金额']]);
            $totalRefundAmount = $this->callHook('canonical_amount', [$raw['总出账金额']]);
            $period = $refundDate === '' ? '' : substr($refundDate, 0, 7);
            $parsed = [
                'fiscal_year' => $refundDate === '' ? '' : substr($refundDate, 0, 4),
                'period' => $period,
                'case_no' => $raw['案号'],
                'handler_name' => $raw['经办人'],
                'clerk_name' => $raw['书记员'],
                'receipt_no' => $raw['票据号码'],
                'invoice_date' => $invoiceDate,
                'refund_date' => $refundDate,
                'source_receipt_no' => $raw['来源票据号码'],
                'source_receipt_date' => $sourceReceiptDate,
                'out_order_no' => $raw['出账单号'],
                'out_status' => $raw['出账状态'],
                'out_type' => $raw['出账种类'],
                'litigation_position' => $raw['诉讼地位'],
                'party_name' => $raw['当事人'],
                'refund_amount' => $refundAmount,
                'total_refund_amount' => $totalRefundAmount,
                'payee_party_relation' => $raw['收款人与当事人关系'],
                'payment_method' => $raw['支付方式'],
                'actual_payee_name' => $raw['实际收款人'],
                'payee_identity_no' => $raw['身份证号/企业'],
                'payee_bank_account_name' => $raw['收款人银行户名'],
                'payee_bank_account_no' => $raw['收款账号'],
                'payee_bank_name' => $raw['开户银行'],
                'unionpay_no' => $raw['银联号'],
                'same_bank_flag' => $raw['是否本行'],
                'handler_note' => $raw['承办人情况说明'],
                'applicant_name' => $raw['申请人'],
                'source_row_no' => $row + 1,
                'source_raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE),
            ];
            $parsed['source_fingerprint'] = $this->callHook('refund_fingerprint', [$parsed]);
            $rows[] = $parsed;
        }
        return $rows;
    }

    public function parseUnknownPaymentCells($cells)
    {
        if (empty($cells)) {
            throw new \Exception('文件中没有可识别的工作表数据');
        }
        list($maxRow, $maxCol) = $this->biffBounds($cells);
        $headers = $this->normalizedBiffHeaders($cells, $maxCol);
        $required = [
            '法院名称', '认领状态', '缴款人', '金额', '银行摘要', '银行备注',
            '交易日期', '法院银行账户', '到账方式', '缴款人开户行', '缴款人账号',
            '认领人姓名', '认领日期', '最后修改时间',
        ];
        $index = $this->headerIndex($headers, $required);

        $rows = [];
        for ($row = 1; $row <= $maxRow; $row++) {
            $raw = $this->normalizedBiffRow($cells, $row, $index);
            if ($this->callHook('is_empty_row', [$raw])) {
                continue;
            }
            if ($this->callHook('is_malformed_row', [$raw])) {
                continue;
            }
            $transactionTime = $this->callHook('normalize_datetime', [$raw['交易日期']]);
            $transactionDate = $transactionTime === '' ? '' : substr($transactionTime, 0, 10);
            $lastModifiedTime = $this->callHook('normalize_datetime', [$raw['最后修改时间']]);
            $unknownAmount = $this->callHook('canonical_amount', [$raw['金额']]);
            $parsed = [
                'fiscal_year' => $transactionDate === '' ? '' : substr($transactionDate, 0, 4),
                'period' => $transactionDate === '' ? '' : substr($transactionDate, 0, 7),
                'court_name' => $raw['法院名称'],
                'claim_status' => $raw['认领状态'],
                'payer_name' => $raw['缴款人'],
                'unknown_amount' => $unknownAmount,
                'bank_summary' => $raw['银行摘要'],
                'bank_remark' => $raw['银行备注'],
                'transaction_date' => $transactionDate,
                'transaction_time' => $transactionTime,
                'court_bank_account_no' => $raw['法院银行账户'],
                'arrival_method' => $raw['到账方式'],
                'payer_bank_name' => $raw['缴款人开户行'],
                'payer_account_no' => $raw['缴款人账号'],
                'claim_user_name' => $raw['认领人姓名'],
                'claim_date' => $this->callHook('nullable_date', [$raw['认领日期']]),
                'last_modified_time' => $lastModifiedTime === '' ? null : $lastModifiedTime,
                'source_row_no' => $row + 1,
                'source_raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE),
            ];
            $parsed['source_fingerprint'] = $this->callHook('unknown_payment_fingerprint', [$parsed]);
            $rows[] = $parsed;
        }
        return $rows;
    }

    public function parseDistributionTableRows($tableRows)
    {
        if (empty($tableRows)) {
            throw new \Exception('文件中没有可识别的工作表数据');
        }
        $headers = [];
        foreach ($tableRows[0] as $col => $value) {
            $headers[$col] = $this->callHook('normalize_cell', [$value]);
        }
        $required = [
            '序号', '分配单号', '转入案号', '转入承办法官', '转入当事人',
            '转出案号', '转出承办法官', '转出当事人', '开票种类', '单据号',
            '分配金额', '申请人', '申请日期', '接收日期', '状态', '情况说明',
            '原开票日期',
        ];
        $index = $this->headerIndex($headers, $required);

        $rows = [];
        for ($row = 1; $row < count($tableRows); $row++) {
            $raw = [];
            foreach ($index as $name => $col) {
                $raw[$name] = $this->callHook('normalize_cell', [$tableRows[$row][$col] ?? '']);
            }
            if ($this->callHook('is_empty_row', [$raw])) {
                continue;
            }
            if ($this->callHook('is_malformed_row', [$raw])) {
                continue;
            }
            $receiveDate = $this->callHook('normalize_date', [$raw['接收日期']]);
            $amount = $this->callHook('canonical_amount', [$raw['分配金额']]);
            $parsed = [
                'fiscal_year' => $receiveDate === '' ? '' : substr($receiveDate, 0, 4),
                'period' => $receiveDate === '' ? '' : substr($receiveDate, 0, 7),
                'distribution_no' => $raw['分配单号'],
                'in_case_no' => $raw['转入案号'],
                'in_judge_name' => $raw['转入承办法官'],
                'in_party_name' => $raw['转入当事人'],
                'out_case_no' => $raw['转出案号'],
                'out_judge_name' => $raw['转出承办法官'],
                'out_party_name' => $raw['转出当事人'],
                'invoice_type' => $raw['开票种类'],
                'receipt_no' => $raw['单据号'],
                'distribution_amount' => $amount,
                'applicant_name' => $raw['申请人'],
                'apply_date' => $this->callHook('nullable_date', [$raw['申请日期']]),
                'receive_date' => $receiveDate,
                'business_status' => $raw['状态'],
                'description' => $raw['情况说明'],
                'original_invoice_date' => $this->callHook('nullable_date', [$raw['原开票日期']]),
                'source_row_no' => $row + 1,
                'source_raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE),
            ];
            $parsed['source_fingerprint'] = $this->callHook('distribution_fingerprint', [$parsed]);
            $rows[] = $parsed;
        }
        return $rows;
    }

    protected function biffBounds($cells)
    {
        $maxRow = -1;
        $maxCol = -1;
        foreach ($cells as $key => $value) {
            list($row, $col) = array_map('intval', explode(':', $key));
            $maxRow = max($maxRow, $row);
            $maxCol = max($maxCol, $col);
        }
        return [$maxRow, $maxCol];
    }

    protected function normalizedBiffHeaders($cells, $maxCol)
    {
        $headers = [];
        for ($col = 0; $col <= $maxCol; $col++) {
            $headers[$col] = $this->callHook('normalize_cell', [$cells['0:' . $col] ?? '']);
        }
        return $headers;
    }

    protected function headerIndex($headers, $required, $optional = [])
    {
        $optionalMap = array_fill_keys($optional, true);
        $index = [];
        foreach (array_merge($required, $optional) as $name) {
            $found = array_search($name, $headers, true);
            if ($found === false) {
                if (isset($optionalMap[$name])) {
                    continue;
                }
                throw new \Exception('缺少表头：' . $name);
            }
            $index[$name] = $found;
        }
        return $index;
    }

    protected function normalizedBiffRow($cells, $row, $index)
    {
        $raw = [];
        foreach ($index as $name => $col) {
            $raw[$name] = $this->callHook('normalize_cell', [$cells[$row . ':' . $col] ?? '']);
        }
        return $raw;
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
