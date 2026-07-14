<?php

namespace app\finance\service\casefund\banking;

/**
 * 银行流水标准化和对账结果字段映射；不查询数据库，也不负责事务或审计。
 */
class CaseFundBankingRule
{
    protected $hooks;

    public function __construct(array $hooks)
    {
        $requiredHooks = [
            'normalize_datetime',
            'canonical_amount',
            'amount_to_cents',
            'cents_to_decimal',
            'bank_statement_fingerprint',
            'new_id',
            'account_set_id',
            'current_user_id',
            'now',
            'bank_reconcile_biz_id',
            'bank_reconcile_biz_no',
            'bank_reconcile_biz_date',
            'bank_reconcile_biz_summary',
        ];
        foreach ($requiredHooks as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款银行规则缺少回调：' . $name);
            }
        }
        $this->hooks = $hooks;
    }

    public function supportedBankStatementBanks()
    {
        return [
            'SHENGJING' => '盛京银行',
            'CCB' => '建设银行',
        ];
    }

    public function normalizeBankStatementRow($row, $bankCode, $bankName, $defaultRowNo)
    {
        $sourceRowNo = (int)($row['source_row_no'] ?? $defaultRowNo);
        $prefix = '第' . $sourceRowNo . '行：';
        $transactionTime = $this->callHook('normalize_datetime', [$row['transaction_time'] ?? '']);
        $transactionDate = $transactionTime === '' ? '' : substr($transactionTime, 0, 10);
        $debitAmount = $this->callHook('canonical_amount', [$row['debit_amount'] ?? '0']);
        $creditAmount = $this->callHook('canonical_amount', [$row['credit_amount'] ?? '0']);
        $balanceAmount = $this->callHook('canonical_amount', [$row['balance_amount'] ?? '0']);
        $debitCents = $this->callHook('amount_to_cents', [$debitAmount]);
        $creditCents = $this->callHook('amount_to_cents', [$creditAmount]);
        if ($transactionTime === '') {
            return ['error' => $prefix . '交易时间不能为空或格式不正确'];
        }
        if ($debitCents < 0 || $creditCents < 0) {
            return ['error' => $prefix . '支出和收入金额不能为负数'];
        }
        if (($debitCents > 0 && $creditCents > 0) || ($debitCents === 0 && $creditCents === 0)) {
            return ['error' => $prefix . '支出和收入必须且只能填写一项'];
        }
        $bankSerialNo = trim((string)($row['bank_serial_no'] ?? ''));
        if ($bankSerialNo === '') {
            return ['error' => $prefix . '交易流水号不能为空'];
        }
        $parsed = [
            'fiscal_year' => (int)substr($transactionDate, 0, 4),
            'period' => substr($transactionDate, 0, 7),
            'bank_code' => $bankCode,
            'bank_name' => $bankName,
            'transaction_date' => $transactionDate,
            'transaction_time' => $transactionTime,
            'direction' => $debitCents > 0 ? 'DEBIT' : 'CREDIT',
            'debit_amount' => $debitAmount,
            'credit_amount' => $creditAmount,
            'balance_amount' => $balanceAmount,
            'counterparty_account_no' => trim((string)($row['counterparty_account_no'] ?? '')),
            'counterparty_account_name' => trim((string)($row['counterparty_account_name'] ?? '')),
            'counterparty_bank_name' => trim((string)($row['counterparty_bank_name'] ?? '')),
            'purpose' => trim((string)($row['purpose'] ?? '')),
            'postscript' => trim((string)($row['postscript'] ?? '')),
            'bank_serial_no' => $bankSerialNo,
            'source_row_no' => $sourceRowNo,
            'source_raw_json' => json_encode($row, JSON_UNESCAPED_UNICODE),
        ];
        $parsed['source_fingerprint'] = $this->callHook('bank_statement_fingerprint', [$parsed]);
        return $parsed;
    }

    public function bankStatementFingerprint($row)
    {
        $parts = [
            $row['bank_code'] ?? '',
            $row['transaction_time'] ?? '',
            $row['debit_amount'] ?? '',
            $row['credit_amount'] ?? '',
            $row['balance_amount'] ?? '',
            $row['counterparty_account_no'] ?? '',
            $row['counterparty_account_name'] ?? '',
            $row['bank_serial_no'] ?? '',
        ];
        return md5(implode('|', $parts));
    }

    public function buildBankReconcileRow($statement, $bizType, $biz, $status, $bankCents, $bizCents, $matchRule = 'SERIAL_NO')
    {
        $reconcileDate = $statement
            ? $statement['transaction_date']
            : $this->callHook('bank_reconcile_biz_date', [$bizType, $biz]);
        $bankSummary = $statement ? trim(($statement['purpose'] ?? '') . ' ' . ($statement['postscript'] ?? '')) : '';
        return [
            'reconcile_id' => $this->callHook('new_id'),
            'account_set_id' => $this->callHook('account_set_id'),
            'fiscal_year' => (int)substr($reconcileDate, 0, 4),
            'period' => substr($reconcileDate, 0, 7),
            'reconcile_date' => $reconcileDate,
            'statement_id' => $statement['statement_id'] ?? null,
            'biz_type' => $bizType,
            'biz_id' => $biz ? $this->callHook('bank_reconcile_biz_id', [$bizType, $biz]) : null,
            'biz_no' => $biz ? $this->callHook('bank_reconcile_biz_no', [$bizType, $biz]) : '',
            'bank_serial_no' => $statement['bank_serial_no'] ?? ($bizType === 'PAYMENT' ? ($biz['bank_serial_no'] ?? '') : ($biz['out_order_no'] ?? '')),
            'bank_amount' => $this->callHook('cents_to_decimal', [$bankCents]),
            'biz_amount' => $this->callHook('cents_to_decimal', [$bizCents]),
            'diff_amount' => $this->callHook('cents_to_decimal', [$bankCents - $bizCents]),
            'match_status' => $status,
            'match_rule' => $matchRule,
            'matched_by' => $this->callHook('current_user_id'),
            'matched_time' => $this->callHook('now'),
            'bank_direction' => $statement['direction'] ?? ($bizType === 'PAYMENT' ? 'CREDIT' : 'DEBIT'),
            'bank_summary' => $bankSummary,
            'biz_summary' => $biz ? $this->callHook('bank_reconcile_biz_summary', [$bizType, $biz]) : '',
            'remark' => '',
        ];
    }

    public function bankReconcileBizAmountCents($bizType, $biz)
    {
        if ($bizType === 'PAYMENT') {
            return $this->callHook('amount_to_cents', [$biz['payment_amount'] ?? '0']);
        }
        return $this->callHook('amount_to_cents', [$biz['refund_amount'] ?? '0']);
    }

    public function bankReconcileBizId($bizType, $biz)
    {
        return $bizType === 'PAYMENT' ? ($biz['payment_id'] ?? '') : ($biz['refund_id'] ?? '');
    }

    public function bankReconcileBizNo($bizType, $biz)
    {
        return $bizType === 'PAYMENT' ? ($biz['bank_serial_no'] ?? '') : ($biz['out_order_no'] ?? '');
    }

    public function bankReconcileBizDate($bizType, $biz)
    {
        return $bizType === 'PAYMENT' ? ($biz['payment_date'] ?? '') : ($biz['refund_date'] ?? '');
    }

    public function bankReconcileBizSummary($bizType, $biz)
    {
        if ($bizType === 'PAYMENT') {
            return trim(($biz['case_no'] ?? '') . ' ' . ($biz['payer_name'] ?? '') . ' ' . ($biz['party_name'] ?? ''));
        }
        return trim(($biz['case_no'] ?? '') . ' ' . ($biz['actual_payee_name'] ?? '') . ' ' . ($biz['party_name'] ?? '') . ' 出账单号:' . ($biz['out_order_no'] ?? ''));
    }

    public function emptyBankReconcileCounts()
    {
        return [
            'MATCHED' => 0,
            'AMOUNT_DIFF' => 0,
            'BANK_ONLY' => 0,
            'BIZ_ONLY' => 0,
            'DUPLICATE' => 0,
        ];
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
