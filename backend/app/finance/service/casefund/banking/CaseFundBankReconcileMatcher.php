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
                $matchRule = 'PAYEE_ACCOUNT_DATE_AMOUNT';
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

            // 同一识别条件命中多条业务时，以“分”为单位按金额消除歧义；出账因此
            // 同时执行交易日期、收款账号/户名和出账金额匹配。单条候选仍保留
            // 金额不符状态，便于财务人员定位差异。
            if (count($candidates) > 1) {
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
                    'PAYEE_ACCOUNT_DATE_AMOUNT',
                ]);
            }
        }

        return [
            'statement_ids' => $statementIds,
            'result_rows' => $resultRows,
            'statement_statuses' => $statementStatuses,
        ];
    }

    /**
     * 使用仓储一次性预取的范围数据执行完全相同的匹配算法。这里只替换查询型
     * hook，结果构造、金额换算、候选占用和 BIZ_ONLY/DUPLICATE 判定仍复用 match()，
     * 避免高性能路径与原规则长期分叉。
     */
    public function matchPrefetched(array $scope, $bankCode, $dateStart, $dateEnd)
    {
        foreach (['statements', 'payments', 'unknown_payments', 'refunds'] as $name) {
            if (!isset($scope[$name]) || !is_array($scope[$name])) {
                throw new \InvalidArgumentException('银行对账预取范围缺少数据集：' . $name);
            }
        }

        $statements = array_values($scope['statements']);
        $payments = array_values(array_filter($scope['payments'], function ($row) {
            return trim((string)($row['bank_serial_no'] ?? '')) !== '';
        }));
        $unknownPayments = array_values(array_filter($scope['unknown_payments'], function ($row) {
            return $this->isUnclaimedUnknownPayment($row);
        }));
        $refunds = array_values($scope['refunds']);

        $paymentByDateSerial = [];
        foreach ($payments as $position => $payment) {
            $date = trim((string)($payment['payment_date'] ?? ''));
            $serial = $this->normalizeSerialNo($payment['bank_serial_no'] ?? '');
            if ($date !== '' && $serial !== '') {
                $paymentByDateSerial[$date][$serial][] = $position;
            }
        }

        $unknownByDateAccount = [];
        foreach ($unknownPayments as $position => $unknownPayment) {
            $date = trim((string)($unknownPayment['transaction_date'] ?? ''));
            $accountNo = $this->normalizeAccountNo($unknownPayment['payer_account_no'] ?? '');
            if ($date !== '' && $accountNo !== '') {
                $unknownByDateAccount[$date][$accountNo][] = $position;
            }
        }

        $refundByDateAccount = [];
        $refundsByDate = [];
        foreach ($refunds as $position => $refund) {
            $date = trim((string)($refund['refund_date'] ?? ''));
            if ($date === '') {
                continue;
            }
            $refundsByDate[$date][] = $position;
            $accountNo = trim((string)($refund['payee_bank_account_no'] ?? ''));
            if ($accountNo !== '') {
                $refundByDateAccount[$date][$accountNo][] = $position;
            }
        }

        $creditStatementByDateSerial = [];
        $ccbCreditStatementByDateSuffix = [];
        $creditStatementByDateAccount = [];
        $debitStatementByDateAccountAmount = [];
        $debitStatementsByDate = [];
        foreach ($statements as $position => $statement) {
            $date = trim((string)($statement['transaction_date'] ?? ''));
            $direction = strtoupper(trim((string)($statement['direction'] ?? '')));
            if ($date === '' || !in_array($direction, ['CREDIT', 'DEBIT'], true)) {
                continue;
            }
            if ($direction === 'CREDIT') {
                $serial = $this->normalizeStatementSerialNo($statement['bank_serial_no'] ?? '');
                if ($serial !== '') {
                    $creditStatementByDateSerial[$date][$serial] = true;
                    if (strtoupper(trim((string)($statement['bank_code'] ?? ''))) === 'CCB'
                        && strlen($serial) === 26
                    ) {
                        $ccbCreditStatementByDateSuffix[$date][substr($serial, -19)] = true;
                    }
                }
                $accountNo = $this->normalizeAccountNo($statement['counterparty_account_no'] ?? '');
                if ($accountNo !== '') {
                    $creditStatementByDateAccount[$date][$accountNo] = true;
                }
                continue;
            }
            $accountNo = trim((string)($statement['counterparty_account_no'] ?? ''));
            $debitCents = $this->callHook('amount_to_cents', [$statement['debit_amount'] ?? '0']);
            if ($accountNo !== '') {
                $debitStatementByDateAccountAmount[$date][$accountNo][$debitCents] = true;
            }
            $debitStatementsByDate[$date][] = $position;
        }

        $hooks = $this->hooks;
        $hooks['payment_candidates_by_statement'] = function ($statement) use ($paymentByDateSerial, $payments) {
            $date = trim((string)($statement['transaction_date'] ?? ''));
            $serial = $this->normalizeStatementSerialNo($statement['bank_serial_no'] ?? '');
            if ($date === '' || $serial === '') {
                return [];
            }
            $entries = $paymentByDateSerial[$date][$serial] ?? [];
            if (strtoupper(trim((string)($statement['bank_code'] ?? ''))) === 'CCB'
                && strlen($serial) === 26
            ) {
                $entries = array_merge($entries, $paymentByDateSerial[$date][substr($serial, -19)] ?? []);
            }
            $byPosition = [];
            foreach ($entries as $position) {
                $byPosition[(int)$position] = $payments[(int)$position];
            }
            ksort($byPosition, SORT_NUMERIC);
            return array_map(function ($row) {
                $row['__match_rule'] = 'SERIAL_NO_DATE';
                return $row;
            }, array_values($byPosition));
        };
        $hooks['unknown_payment_candidates_by_statement'] = function ($statement) use (
            $unknownByDateAccount,
            $unknownPayments
        ) {
            $date = trim((string)($statement['transaction_date'] ?? ''));
            $accountNo = $this->normalizeAccountNo($statement['counterparty_account_no'] ?? '');
            $positions = ($date !== '' && $accountNo !== '')
                ? ($unknownByDateAccount[$date][$accountNo] ?? [])
                : [];
            return array_map(function ($position) use ($unknownPayments) {
                $row = $unknownPayments[$position];
                $row['__match_rule'] = 'PAYER_ACCOUNT_DATE';
                return $row;
            }, $positions);
        };
        $hooks['refund_candidates_by_statement'] = function ($statement) use (
            $refundByDateAccount,
            $refundsByDate,
            $refunds
        ) {
            $date = trim((string)($statement['transaction_date'] ?? ''));
            $accountNo = trim((string)($statement['counterparty_account_no'] ?? ''));
            if ($date === '') {
                return [];
            }
            if ($accountNo !== '') {
                return array_map(function ($position) use ($refunds) {
                    return $refunds[$position];
                }, $refundByDateAccount[$date][$accountNo] ?? []);
            }
            $accountName = trim((string)($statement['counterparty_account_name'] ?? ''));
            if ($accountName === '') {
                return [];
            }
            $positions = array_values(array_filter($refundsByDate[$date] ?? [], function ($position) use (
                $accountName,
                $refunds
            ) {
                $refund = $refunds[$position];
                return $this->containsText($refund['payee_bank_account_name'] ?? '', $accountName)
                    || $this->containsText($refund['actual_payee_name'] ?? '', $accountName);
            }));
            return array_map(function ($position) use ($refunds) {
                return $refunds[$position];
            }, $positions);
        };
        $hooks['payment_candidates_in_scope'] = function () use ($payments) {
            return $payments;
        };
        $hooks['unknown_payment_candidates_in_scope'] = function () use ($unknownPayments) {
            return $unknownPayments;
        };
        $hooks['refund_candidates_in_scope'] = function () use ($refunds) {
            return $refunds;
        };
        $hooks['bank_statement_exists_by_payment'] = function ($payment) use (
            $creditStatementByDateSerial,
            $ccbCreditStatementByDateSuffix
        ) {
            $date = trim((string)($payment['payment_date'] ?? ''));
            $serial = $this->normalizeSerialNo($payment['bank_serial_no'] ?? '');
            if ($date === '' || $serial === '') {
                return false;
            }
            return isset($creditStatementByDateSerial[$date][$serial])
                || isset($ccbCreditStatementByDateSuffix[$date][$serial]);
        };
        $hooks['bank_statement_exists_by_unknown_payment'] = function ($unknownPayment) use ($creditStatementByDateAccount) {
            $date = trim((string)($unknownPayment['transaction_date'] ?? ''));
            $accountNo = $this->normalizeAccountNo($unknownPayment['payer_account_no'] ?? '');
            return $date !== '' && $accountNo !== ''
                && isset($creditStatementByDateAccount[$date][$accountNo]);
        };
        $hooks['bank_statement_exists_by_refund'] = function ($refund) use (
            $debitStatementByDateAccountAmount,
            $debitStatementsByDate,
            $statements
        ) {
            $date = trim((string)($refund['refund_date'] ?? ''));
            if ($date === '') {
                return false;
            }
            $refundCents = $this->callHook('biz_amount_cents', ['REFUND', $refund]);
            $accountNo = trim((string)($refund['payee_bank_account_no'] ?? ''));
            if ($accountNo !== '') {
                return isset($debitStatementByDateAccountAmount[$date][$accountNo][$refundCents]);
            }
            $accountName = trim((string)($refund['payee_bank_account_name'] ?? ''));
            if ($accountName === '') {
                $accountName = trim((string)($refund['actual_payee_name'] ?? ''));
            }
            if ($accountName === '') {
                return false;
            }
            foreach ($debitStatementsByDate[$date] ?? [] as $position) {
                $statement = $statements[$position];
                $statementCents = $this->callHook('amount_to_cents', [$statement['debit_amount'] ?? '0']);
                if ($statementCents === $refundCents
                    && $this->containsText($statement['counterparty_account_name'] ?? '', $accountName)
                ) {
                    return true;
                }
            }
            return false;
        };

        $matcher = new self($hooks);
        return $matcher->match($statements, $bankCode, $dateStart, $dateEnd);
    }

    protected function bizKey($bizType, $biz)
    {
        return $bizType . ':' . $this->callHook('biz_id', [$bizType, $biz]);
    }

    protected function normalizeSerialNo($value)
    {
        return strtoupper((string)preg_replace('/[\s\-]+/u', '', trim((string)$value)));
    }

    protected function normalizeStatementSerialNo($value)
    {
        $value = str_replace(['－', '—', '–'], '-', trim((string)$value));
        $separatorPosition = strrpos($value, '-');
        if ($separatorPosition !== false) {
            $value = substr($value, $separatorPosition + 1);
        }
        return $this->normalizeSerialNo($value);
    }

    protected function normalizeAccountNo($value)
    {
        return strtoupper((string)preg_replace('/[^0-9A-Za-z]/u', '', trim((string)$value)));
    }

    protected function isUnclaimedUnknownPayment($row)
    {
        $status = trim((string)($row['claim_status'] ?? ''));
        return $status === ''
            || in_array($status, ['待认领', '未认领'], true)
            || strtoupper($status) === 'UNCLAIMED';
    }

    protected function containsText($haystack, $needle)
    {
        $haystack = (string)$haystack;
        $needle = (string)$needle;
        if ($needle === '') {
            return true;
        }
        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
        }
        return stripos($haystack, $needle) !== false;
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
