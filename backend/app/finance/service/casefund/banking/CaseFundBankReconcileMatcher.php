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
        $plans = [];
        $exactUsage = [];
        // 先收集整个范围的候选。候选和金额差异不占用业务，避免流水顺序影响结果。
        foreach ($statements as $statement) {
            $income = $statement['direction'] === 'CREDIT';
            $bankCents = $this->callHook('amount_to_cents', [
                $income ? $statement['credit_amount'] : $statement['debit_amount'],
            ]);
            $bizType = $income ? 'PAYMENT' : 'REFUND';
            $rule = $income ? 'SERIAL_NO_DATE' : 'PAYEE_ACCOUNT_DATE_AMOUNT';
            $candidates = $this->callHook($income ? 'payment_candidates_by_statement' : 'refund_candidates_by_statement', [$statement]);
            // 保持缴费流水号优先的业务规则，不在本次修复中扩大模糊匹配范围。
            if ($income && empty($candidates)) {
                $candidates = $this->callHook('unknown_payment_candidates_by_statement', [$statement]);
                if (!empty($candidates)) {
                    $bizType = 'UNKNOWN_PAYMENT';
                    $rule = 'PAYER_ACCOUNT_DATE';
                }
            }
            $byKey = [];
            $exact = [];
            foreach ($candidates as $candidate) {
                $key = $this->bizKey($bizType, $candidate);
                $byKey[$key] = $candidate;
                if ($this->callHook('biz_amount_cents', [$bizType, $candidate]) === $bankCents) {
                    $exact[$key] = $candidate;
                }
            }
            foreach ($exact as $key => $candidate) {
                $exactUsage[$key] = ($exactUsage[$key] ?? 0) + 1;
            }
            $plans[] = [
                'statement' => $statement, 'biz_type' => $bizType, 'rule' => $rule,
                'bank_cents' => $bankCents, 'candidates' => $byKey, 'exact' => $exact,
            ];
        }

        $confirmed = [];
        $matchedBiz = [];
        foreach ($plans as $index => $plan) {
            if (count($plan['exact']) !== 1) {
                continue;
            }
            $key = key($plan['exact']);
            // 银行侧和业务侧都唯一才自动确认；两笔流水争用同一业务时全部留待核查。
            if ($exactUsage[$key] === 1) {
                $confirmed[$index] = $key;
                $matchedBiz[$key] = true;
            }
        }

        $statementIds = [];
        $statementStatuses = [];
        $resultRows = [];
        $representedBiz = [];
        foreach ($plans as $index => $plan) {
            $statement = $plan['statement'];
            $bizType = $plan['biz_type'];
            $statementIds[] = $statement['statement_id'];
            if (isset($confirmed[$index])) {
                $key = $confirmed[$index];
                $candidates = [$key => $plan['exact'][$key]];
                $status = 'MATCHED';
            } else {
                $available = array_diff_key($plan['candidates'], $matchedBiz);
                $exact = array_diff_key($plan['exact'], $matchedBiz);
                $candidates = !empty($exact) ? $exact : $available;
                $status = empty($candidates) ? 'BANK_ONLY'
                    : ((!empty($exact) || count($candidates) > 1) ? 'DUPLICATE' : 'AMOUNT_DIFF');
            }
            $statementStatuses[$statement['statement_id']] = $status;
            if (empty($candidates)) {
                $resultRows[] = $this->callHook('build_reconcile_row', [
                    $statement, $bizType, null, $status, $plan['bank_cents'], 0, $plan['rule'],
                ]);
                continue;
            }
            foreach ($candidates as $key => $candidate) {
                $resultRows[] = $this->callHook('build_reconcile_row', [
                    $statement, $bizType, $candidate, $status, $plan['bank_cents'],
                    $this->callHook('biz_amount_cents', [$bizType, $candidate]),
                    trim((string)($candidate['__match_rule'] ?? $plan['rule'])),
                ]);
                // 已在差异/候选结果中出现不等于已匹配，仅避免再生成重复的业务单边行。
                $representedBiz[$key] = true;
            }
        }

        foreach ([
            ['PAYMENT', 'payment_candidates_in_scope', 'SERIAL_NO_DATE'],
            ['UNKNOWN_PAYMENT', 'unknown_payment_candidates_in_scope', 'PAYER_ACCOUNT_DATE'],
            ['REFUND', 'refund_candidates_in_scope', 'PAYEE_ACCOUNT_DATE_AMOUNT'],
        ] as $source) {
            list($bizType, $hook, $rule) = $source;
            foreach ($this->callHook($hook, [$dateStart, $dateEnd]) as $biz) {
                if (isset($representedBiz[$this->bizKey($bizType, $biz)])) {
                    continue;
                }
                // 存在同日同账号的银行记录不能证明这笔业务已对账，必须保留待核查项。
                $resultRows[] = $this->callHook('build_reconcile_row', [
                    null, $bizType, $biz, 'BIZ_ONLY', 0,
                    $this->callHook('biz_amount_cents', [$bizType, $biz]), $rule,
                ]);
            }
        }
        return [
            'statement_ids' => $statementIds, 'result_rows' => $resultRows,
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
        // 缺少流水号只影响候选匹配资格，不影响业务侧完整性检查。
        $payments = array_values($scope['payments']);
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
