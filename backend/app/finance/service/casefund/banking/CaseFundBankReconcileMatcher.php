<?php

namespace app\finance\service\casefund\banking;

/**
 * 银行流水与缴费/退付业务的自动匹配编排；不负责查询实现、事务或落库。
 */
class CaseFundBankReconcileMatcher
{
    protected $hooks;

    public function __construct(array $hooks)
    {
        foreach ([
            'amount_to_cents',
            'payment_candidates_by_statement',
            'unknown_payment_candidates_by_statement',
            'refund_candidates_by_statement',
            'payment_candidates_in_scope',
            'unknown_payment_candidates_in_scope',
            'refund_candidates_in_scope',
            'bank_statement_exists_by_payment',
            'bank_statement_exists_by_unknown_payment',
            'bank_statement_exists_by_refund',
            'build_reconcile_row',
            'biz_amount_cents',
            'biz_id',
        ] as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款银行对账匹配器缺少回调：' . $name);
            }
        }
        $this->hooks = $hooks;
    }

    public function match($statements, $bankCode, $dateStart, $dateEnd)
    {
        $statementIds = [];
        $resultRows = [];
        $matchedBiz = [];
        $candidateBiz = [];
        $statementStatuses = [];

        foreach ($statements as $statement) {
            $statementIds[] = $statement['statement_id'];
            $direction = $statement['direction'];
            $bankAmount = $direction === 'CREDIT'
                ? $statement['credit_amount']
                : $statement['debit_amount'];
            $bankCents = $this->callHook('amount_to_cents', [$bankAmount]);
            if ($direction === 'CREDIT') {
                $candidates = $this->callHook('payment_candidates_by_statement', [$statement]);
                $bizType = 'PAYMENT';
                $matchRule = 'SERIAL_NO_DATE';
                // 缴费登记优先；仅在没有流水号候选时再匹配未认领不明款。
                if (empty($candidates)) {
                    $candidates = $this->callHook('unknown_payment_candidates_by_statement', [$statement]);
                    if (!empty($candidates)) {
                        $bizType = 'UNKNOWN_PAYMENT';
                        $matchRule = 'PAYER_ACCOUNT_DATE';
                    }
                }
            } else {
                $candidates = $this->callHook('refund_candidates_by_statement', [$statement]);
                $bizType = 'REFUND';
                $matchRule = 'PAYEE_ACCOUNT_DATE';
            }

            if (!empty($candidates)) {
                $matchRule = trim((string)($candidates[0]['__match_rule'] ?? $matchRule));
                foreach ($candidates as $candidate) {
                    $candidateBiz[$this->bizKey($bizType, $candidate)] = true;
                }
                if ($direction === 'CREDIT') {
                    $candidates = array_values(array_filter($candidates, function ($candidate) use ($bizType, $matchedBiz) {
                        return !isset($matchedBiz[$this->bizKey($bizType, $candidate)]);
                    }));
                }
            }

            if (empty($candidates)) {
                $resultRows[] = $this->callHook('build_reconcile_row', [
                    $statement,
                    $bizType,
                    null,
                    'BANK_ONLY',
                    $bankCents,
                    0,
                    $matchRule,
                ]);
                $statementStatuses[$statement['statement_id']] = 'BANK_ONLY';
                continue;
            }

            // 同一识别条件命中多条业务数据时，金额只用于消除歧义，不替代流水号/账号识别。
            if ($direction === 'CREDIT' && count($candidates) > 1) {
                $amountMatches = array_values(array_filter($candidates, function ($candidate) use ($bizType, $bankCents) {
                    return $this->callHook('biz_amount_cents', [$bizType, $candidate]) === $bankCents;
                }));
                if (!empty($amountMatches)) {
                    $candidates = $amountMatches;
                }
            }

            if (count($candidates) > 1) {
                foreach ($candidates as $candidate) {
                    $bizCents = $this->callHook('biz_amount_cents', [$bizType, $candidate]);
                    $resultRows[] = $this->callHook('build_reconcile_row', [
                        $statement,
                        $bizType,
                        $candidate,
                        'DUPLICATE',
                        $bankCents,
                        $bizCents,
                        $matchRule,
                    ]);
                    $matchedBiz[$this->bizKey($bizType, $candidate)] = true;
                }
                $statementStatuses[$statement['statement_id']] = 'DUPLICATE';
                continue;
            }

            $candidate = $candidates[0];
            $bizCents = $this->callHook('biz_amount_cents', [$bizType, $candidate]);
            $status = $bankCents === $bizCents ? 'MATCHED' : 'AMOUNT_DIFF';
            $resultRows[] = $this->callHook('build_reconcile_row', [
                $statement,
                $bizType,
                $candidate,
                $status,
                $bankCents,
                $bizCents,
                $matchRule,
            ]);
            $statementStatuses[$statement['statement_id']] = $status;
            $matchedBiz[$this->bizKey($bizType, $candidate)] = true;
        }

        foreach ($this->callHook('payment_candidates_in_scope', [$dateStart, $dateEnd]) as $payment) {
            $key = $this->bizKey('PAYMENT', $payment);
            if (isset($matchedBiz[$key])) {
                continue;
            }
            $bankExists = $this->callHook('bank_statement_exists_by_payment', [
                $payment,
                $bankCode,
                $dateStart,
                $dateEnd,
            ]);
            if (!$bankExists || isset($candidateBiz[$key])) {
                $bizCents = $this->callHook('biz_amount_cents', ['PAYMENT', $payment]);
                $resultRows[] = $this->callHook('build_reconcile_row', [
                    null,
                    'PAYMENT',
                    $payment,
                    'BIZ_ONLY',
                    0,
                    $bizCents,
                    'SERIAL_NO_DATE',
                ]);
            }
        }

        foreach ($this->callHook('unknown_payment_candidates_in_scope', [$dateStart, $dateEnd]) as $unknownPayment) {
            $key = $this->bizKey('UNKNOWN_PAYMENT', $unknownPayment);
            if (isset($matchedBiz[$key])) {
                continue;
            }
            $bankExists = $this->callHook('bank_statement_exists_by_unknown_payment', [
                $unknownPayment,
                $bankCode,
                $dateStart,
                $dateEnd,
            ]);
            if (!$bankExists || isset($candidateBiz[$key])) {
                $bizCents = $this->callHook('biz_amount_cents', ['UNKNOWN_PAYMENT', $unknownPayment]);
                $resultRows[] = $this->callHook('build_reconcile_row', [
                    null,
                    'UNKNOWN_PAYMENT',
                    $unknownPayment,
                    'BIZ_ONLY',
                    0,
                    $bizCents,
                    'PAYER_ACCOUNT_DATE',
                ]);
            }
        }

        foreach ($this->callHook('refund_candidates_in_scope', [$dateStart, $dateEnd]) as $refund) {
            $key = $this->bizKey('REFUND', $refund);
            if (isset($matchedBiz[$key])) {
                continue;
            }
            $bankExists = $this->callHook('bank_statement_exists_by_refund', [
                $refund,
                $bankCode,
                $dateStart,
                $dateEnd,
            ]);
            if (!$bankExists) {
                $bizCents = $this->callHook('biz_amount_cents', ['REFUND', $refund]);
                $resultRows[] = $this->callHook('build_reconcile_row', [
                    null,
                    'REFUND',
                    $refund,
                    'BIZ_ONLY',
                    0,
                    $bizCents,
                    'PAYEE_ACCOUNT_DATE',
                ]);
            }
        }

        return [
            'statement_ids' => $statementIds,
            'result_rows' => $resultRows,
            'statement_statuses' => $statementStatuses,
        ];
    }

    protected function bizKey($bizType, $biz)
    {
        return $bizType . ':' . $this->callHook('biz_id', [$bizType, $biz]);
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
