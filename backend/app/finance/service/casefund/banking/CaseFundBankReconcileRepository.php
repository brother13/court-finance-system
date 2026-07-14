<?php

namespace app\finance\service\casefund\banking;

/**
 * 银行对账候选、范围和结果汇总查询；不负责事务、匹配规则或审计。
 */
class CaseFundBankReconcileRepository
{
    protected $hooks;
    protected $tables;
    protected $fields;
    protected $autoMatchRules;

    public function __construct(array $hooks, array $tables, array $fields, array $autoMatchRules)
    {
        foreach (['get_db', 'account_set_id', 'current_year', 'prepare_update'] as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款银行对账仓储缺少回调：' . $name);
            }
        }
        foreach (['payment', 'refund', 'statement', 'reconcile'] as $name) {
            if (empty($tables[$name])) {
                throw new \InvalidArgumentException('案款银行对账仓储缺少数据表：' . $name);
            }
            if (empty($fields[$name])) {
                throw new \InvalidArgumentException('案款银行对账仓储缺少字段清单：' . $name);
            }
        }
        $this->hooks = $hooks;
        $this->tables = $tables;
        $this->fields = $fields;
        $this->autoMatchRules = $autoMatchRules;
    }

    public function paymentCandidatesByBankStatement($statement)
    {
        $bankSerialNo = trim((string)($statement['bank_serial_no'] ?? ''));
        if ($bankSerialNo === '' || empty($statement['transaction_date'])) {
            return [];
        }
        return $this->db($this->tables['payment'])
            ->where($this->activeYearWhere())
            ->where('payment_date', $statement['transaction_date'])
            ->where('bank_serial_no', $bankSerialNo)
            ->field($this->fields['payment'])
            ->select();
    }

    public function bankStatementList($data, $page, $pageSize)
    {
        $where = $this->activeYearWhere();
        if (!empty($data['period'])) {
            $where['period'] = $data['period'];
        }
        if (!empty($data['bank_code'])) {
            $where['bank_code'] = $data['bank_code'];
        }
        if (!empty($data['direction'])) {
            $where['direction'] = $data['direction'];
        }
        $this->applyDateScopeToWhere(
            $where,
            'transaction_date',
            $data['date_start'] ?? '',
            $data['date_end'] ?? ''
        );
        $keyword = trim($data['keyword'] ?? '');
        $query = $this->bankStatementFilteredQuery($where, $keyword);
        $totalQuery = $this->bankStatementFilteredQuery($where, $keyword);
        $debitQuery = $this->bankStatementFilteredQuery($where, $keyword);
        $creditQuery = $this->bankStatementFilteredQuery($where, $keyword);
        $total = $totalQuery->count();
        $debitTotal = $debitQuery->sum('debit_amount');
        $creditTotal = $creditQuery->sum('credit_amount');
        $rows = $query->field($this->fields['statement'])
            ->order('transaction_time asc, source_row_no asc')
            ->page($page, $pageSize)
            ->select();
        return [
            'items' => $rows,
            'total' => $total,
            'debit_amount' => $debitTotal,
            'credit_amount' => $creditTotal,
        ];
    }

    public function bankStatementsInScope($bankCode, $dateStart, $dateEnd)
    {
        $query = $this->db($this->tables['statement'])->where($this->activeYearWhere());
        if ($bankCode !== '') {
            $query->where('bank_code', $bankCode);
        }
        $this->applyDateScope($query, 'transaction_date', $dateStart, $dateEnd);
        return $query->field($this->fields['statement'])
            ->order('transaction_time asc, source_row_no asc')
            ->select();
    }

    public function bankReconcileList($data, $page, $pageSize, $emptyCounts)
    {
        $where = $this->activeYearWhere();
        if (!empty($data['status'])) {
            $where['match_status'] = $data['status'];
        }
        if (!empty($data['biz_type'])) {
            $where['biz_type'] = $data['biz_type'];
        }
        $this->applyDateScopeToWhere(
            $where,
            'reconcile_date',
            $data['date_start'] ?? '',
            $data['date_end'] ?? ''
        );
        $keyword = trim($data['keyword'] ?? '');
        $query = $this->bankReconcileFilteredQuery($where, $keyword);
        $totalQuery = $this->bankReconcileFilteredQuery($where, $keyword);
        $total = $totalQuery->count();
        $rows = $query->field($this->fields['reconcile'])
            ->order('reconcile_date asc, bank_serial_no asc')
            ->page($page, $pageSize)
            ->select();
        return [
            'items' => $rows,
            'total' => $total,
            // 保持原口径：状态汇总跟随结构化筛选，但不跟随关键词。
            'summary' => $this->bankReconcileSummary($where, $emptyCounts),
        ];
    }

    public function refundCandidatesByBankStatement($statement)
    {
        if (empty($statement['transaction_date'])) {
            return [];
        }
        $accountNo = trim((string)($statement['counterparty_account_no'] ?? ''));
        $accountName = trim((string)($statement['counterparty_account_name'] ?? ''));
        $query = $this->db($this->tables['refund'])
            ->where($this->activeYearWhere())
            ->where('refund_date', $statement['transaction_date']);
        if ($accountNo !== '') {
            $query->where('payee_bank_account_no', $accountNo);
        } elseif ($accountName !== '') {
            $query->where('payee_bank_account_name|actual_payee_name', 'like', '%' . $accountName . '%');
        } else {
            return [];
        }
        return $query->field($this->fields['refund'])->select();
    }

    public function paymentCandidatesInScope($dateStart, $dateEnd)
    {
        $query = $this->db($this->tables['payment'])
            ->where($this->activeYearWhere())
            ->where('bank_serial_no', 'neq', '');
        $this->applyDateScope($query, 'payment_date', $dateStart, $dateEnd);
        return $query->field($this->fields['payment'])->select();
    }

    public function refundCandidatesInScope($dateStart, $dateEnd)
    {
        $query = $this->db($this->tables['refund'])->where($this->activeYearWhere());
        $this->applyDateScope($query, 'refund_date', $dateStart, $dateEnd);
        return $query->field($this->fields['refund'])->select();
    }

    public function bankStatementExistsByPayment($payment, $bankCode = '', $dateStart = '', $dateEnd = '')
    {
        $bankSerialNo = trim((string)($payment['bank_serial_no'] ?? ''));
        $paymentDate = trim((string)($payment['payment_date'] ?? ''));
        if ($bankSerialNo === '' || $paymentDate === '') {
            return false;
        }
        $query = $this->db($this->tables['statement'])->where([
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'direction' => 'CREDIT',
            'transaction_date' => $paymentDate,
            'bank_serial_no' => $bankSerialNo,
            'del_flag' => 0,
        ]);
        $this->applyBankAndDateScope($query, $bankCode, $dateStart, $dateEnd);
        return $query->count() > 0;
    }

    public function bankStatementExistsByRefundPayee($refund, $bankCode = '', $dateStart = '', $dateEnd = '')
    {
        $refundDate = trim((string)($refund['refund_date'] ?? ''));
        if ($refundDate === '') {
            return false;
        }
        $accountNo = trim((string)($refund['payee_bank_account_no'] ?? ''));
        $accountName = trim((string)($refund['payee_bank_account_name'] ?? ''));
        if ($accountName === '') {
            $accountName = trim((string)($refund['actual_payee_name'] ?? ''));
        }
        if ($accountNo === '' && $accountName === '') {
            return false;
        }
        $query = $this->db($this->tables['statement'])->where([
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'direction' => 'DEBIT',
            'transaction_date' => $refundDate,
            'del_flag' => 0,
        ]);
        $this->applyBankAndDateScope($query, $bankCode, $dateStart, $dateEnd);
        if ($accountNo !== '') {
            $query->where('counterparty_account_no', $accountNo);
        } else {
            $query->where('counterparty_account_name', 'like', '%' . $accountName . '%');
        }
        return $query->count() > 0;
    }

    public function softDeleteBankReconcileScope($dateStart, $dateEnd)
    {
        $query = $this->db($this->tables['reconcile'])
            ->where($this->activeYearWhere())
            ->where('match_rule', 'in', $this->autoMatchRules);
        $this->applyDateScope($query, 'reconcile_date', $dateStart, $dateEnd);
        $update = $this->callHook('prepare_update', [['del_flag' => 1]]);
        $query->update($update);
    }

    public function bankReconcileSummary($where, $emptyCounts)
    {
        $rows = $this->db($this->tables['reconcile'])
            ->where($where)
            ->field('match_status,count(*) as total')
            ->group('match_status')
            ->select();
        $summary = $emptyCounts;
        foreach ($rows as $row) {
            $summary[$row['match_status']] = (int)$row['total'];
        }
        return $summary;
    }

    protected function activeYearWhere()
    {
        return [
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'del_flag' => 0,
        ];
    }

    protected function bankStatementFilteredQuery($where, $keyword)
    {
        $query = $this->db($this->tables['statement'])->where($where);
        if ($keyword !== '') {
            $query->where(
                'counterparty_account_no|counterparty_account_name|counterparty_bank_name|purpose|postscript|bank_serial_no',
                'like',
                '%' . $keyword . '%'
            );
        }
        return $query;
    }

    protected function bankReconcileFilteredQuery($where, $keyword)
    {
        $query = $this->db($this->tables['reconcile'])->where($where);
        if ($keyword !== '') {
            $query->where('bank_serial_no|biz_no|bank_summary|biz_summary', 'like', '%' . $keyword . '%');
        }
        return $query;
    }

    protected function applyBankAndDateScope($query, $bankCode, $dateStart, $dateEnd)
    {
        if ($bankCode !== '') {
            $query->where('bank_code', $bankCode);
        }
        $this->applyDateScope($query, 'transaction_date', $dateStart, $dateEnd);
    }

    protected function applyDateScope($query, $field, $dateStart, $dateEnd)
    {
        if ($dateStart !== '') {
            $query->where($field, '>=', $dateStart);
        }
        if ($dateEnd !== '') {
            $query->where($field, '<=', $dateEnd);
        }
    }

    protected function applyDateScopeToWhere(&$where, $field, $dateStart, $dateEnd)
    {
        if (!empty($dateStart)) {
            $where[$field][] = ['>=', $dateStart];
        }
        if (!empty($dateEnd)) {
            $where[$field][] = ['<=', $dateEnd];
        }
    }

    protected function db($table)
    {
        return $this->callHook('get_db', [$table]);
    }

    protected function accountSetId()
    {
        return $this->callHook('account_set_id');
    }

    protected function currentYear()
    {
        return $this->callHook('current_year');
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
