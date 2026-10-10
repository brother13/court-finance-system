<?php

namespace app\finance\service\casefund\banking;

/**
 * 银行对账候选、范围和结果汇总查询；不负责事务、匹配规则或审计。
 */
class CaseFundBankReconcileRepository
{
    // 自动对账只读取匹配、结果摘要和稳定排序所需字段。导入原文可能是 longtext，
    // 不能在范围重算时随每条流水/业务记录反复载入内存。
    const RECONCILE_STATEMENT_FIELDS = [
        'statement_id', 'bank_code', 'transaction_date', 'transaction_time', 'direction',
        'debit_amount', 'credit_amount', 'counterparty_account_no', 'counterparty_account_name',
        'purpose', 'postscript', 'bank_serial_no', 'reconcile_status', 'source_row_no',
    ];
    const RECONCILE_PAYMENT_FIELDS = [
        'payment_id', 'payment_date', 'payment_amount', 'bank_serial_no', 'case_no',
        'payer_name', 'party_name', 'source_row_no',
    ];
    const RECONCILE_UNKNOWN_PAYMENT_FIELDS = [
        'unknown_payment_id', 'transaction_date', 'transaction_time', 'unknown_amount',
        'claim_status', 'payer_account_no', 'payer_name', 'bank_summary', 'bank_remark',
        'source_row_no',
    ];
    const RECONCILE_REFUND_FIELDS = [
        'refund_id', 'refund_date', 'refund_amount', 'payee_bank_account_no',
        'payee_bank_account_name', 'actual_payee_name', 'case_no', 'party_name',
        'out_order_no', 'source_row_no',
    ];

    protected $hooks;
    protected $tables;
    protected $fields;

    public function __construct(array $hooks, array $tables, array $fields)
    {
        foreach (['get_db', 'account_set_id', 'current_year'] as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款银行对账仓储缺少回调：' . $name);
            }
        }
        foreach (['payment', 'refund', 'unknown_payment', 'statement', 'reconcile'] as $name) {
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
    }

    public function paymentCandidatesByBankStatement($statement, $forUpdate = false)
    {
        if (empty($statement['transaction_date'])) {
            return [];
        }
        $query = $this->db($this->tables['payment'])
            ->where($this->activeYearWhere())
            ->where('payment_date', $statement['transaction_date']);
        if ($forUpdate) {
            $query->lock(true);
        }
        $rows = $query->field($this->fields['payment'])->select();
        $matches = [];
        foreach ($rows as $row) {
            $paymentSerialNo = trim((string)($row['bank_serial_no'] ?? ''));
            // 已认领不明款进入缴费登记后仍没有流水号，本期不参与自动对账。
            if ($paymentSerialNo === '' || !$this->bankSerialsMatch($statement, $paymentSerialNo)) {
                continue;
            }
            $row['__match_rule'] = 'SERIAL_NO_DATE';
            $matches[] = $row;
        }
        return $matches;
    }

    public function unknownPaymentCandidatesByBankStatement($statement, $forUpdate = false)
    {
        $accountNo = $this->normalizeAccountNo($statement['counterparty_account_no'] ?? '');
        if (empty($statement['transaction_date']) || $accountNo === '') {
            return [];
        }
        $query = $this->db($this->tables['unknown_payment'])
            ->where($this->activeYearWhere())
            ->where('transaction_date', $statement['transaction_date']);
        if ($forUpdate) {
            $query->lock(true);
        }
        $rows = $query->field($this->fields['unknown_payment'])->select();
        $matches = [];
        foreach ($rows as $row) {
            if (!$this->isUnclaimedUnknownPayment($row)) {
                continue;
            }
            if ($this->normalizeAccountNo($row['payer_account_no'] ?? '') !== $accountNo) {
                continue;
            }
            $row['__match_rule'] = 'PAYER_ACCOUNT_DATE';
            $matches[] = $row;
        }
        return $matches;
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
        $reconcileStatus = strtoupper(trim((string)($data['reconcile_status'] ?? '')));
        if (in_array($reconcileStatus, ['UNMATCHED', 'MATCHED', 'AMOUNT_DIFF', 'BANK_ONLY', 'DUPLICATE', 'IGNORED'], true)) {
            $where['reconcile_status'] = $reconcileStatus;
        }
        $this->applyDateScopeToWhere(
            $where,
            'transaction_date',
            $data['date_start'] ?? '',
            $data['date_end'] ?? ''
        );
        $purpose = trim((string)($data['purpose'] ?? ''));
        $keyword = trim($data['keyword'] ?? '');
        $query = $this->bankStatementFilteredQuery($where, $keyword, $purpose);
        $aggregate = $this->bankStatementFilteredQuery($where, $keyword, $purpose)
            ->field('count(*) as total,sum(debit_amount) as debit_amount,sum(credit_amount) as credit_amount')
            ->find();
        $total = (int)($aggregate['total'] ?? 0);
        $debitTotal = $aggregate['debit_amount'] ?? 0;
        $creditTotal = $aggregate['credit_amount'] ?? 0;
        $rows = $query->field($this->fields['statement'])
            ->order('transaction_time asc, source_row_no asc')
            ->page($page, $pageSize)
            ->select();
        $rows = $this->attachReconcileItems($rows);
        return [
            'items' => $rows,
            'total' => $total,
            'debit_amount' => $debitTotal,
            'credit_amount' => $creditTotal,
        ];
    }

    protected function attachReconcileItems($rows)
    {
        $statementIds = [];
        foreach ($rows as $row) {
            $statementId = trim((string)($row['statement_id'] ?? ''));
            if ($statementId !== '') {
                $statementIds[$statementId] = true;
            }
        }
        $grouped = [];
        if (!empty($statementIds)) {
            $reconcileRows = $this->db($this->tables['reconcile'])
                ->where($this->activeYearWhere())
                ->where('statement_id', 'in', array_keys($statementIds))
                ->field($this->fields['reconcile'])
                ->order('statement_id asc, match_status asc, biz_type asc, biz_no asc')
                ->select();
            foreach ($reconcileRows as $reconcileRow) {
                $statementId = trim((string)($reconcileRow['statement_id'] ?? ''));
                if ($statementId === '' || !isset($statementIds[$statementId])) {
                    continue;
                }
                $grouped[$statementId][] = $reconcileRow;
            }
        }
        foreach ($rows as &$row) {
            $statementId = trim((string)($row['statement_id'] ?? ''));
            $row['reconcile_items'] = $grouped[$statementId] ?? [];
        }
        unset($row);
        return $rows;
    }

    public function existingBankStatementFingerprints($fingerprints)
    {
        $fingerprints = array_values(array_unique($fingerprints));
        if (empty($fingerprints)) {
            return [];
        }
        $rows = $this->db($this->tables['statement'])->where([
            'account_set_id' => $this->accountSetId(),
            'del_flag' => 0,
        ])->where('source_fingerprint', 'in', $fingerprints)
            ->field(['source_fingerprint'])
            ->select();
        $existing = [];
        foreach ($rows as $row) {
            $existing[$row['source_fingerprint']] = true;
        }
        return $existing;
    }

    public function bankStatementsForDeletion($ids)
    {
        $where = $this->activeYearWhere();
        $where['reconcile_status'] = 'UNMATCHED';
        return $this->db($this->tables['statement'])
            ->where($where)
            ->where('statement_id', 'in', $ids)
            ->field($this->fields['statement'])
            ->lock(true)
            ->select();
    }

    public function activeBankReconcileCountForStatements($ids)
    {
        return (int)$this->db($this->tables['reconcile'])->where([
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'del_flag' => 0,
        ])->where('statement_id', 'in', $ids)->count();
    }

    public function bankStatementsInScope($bankCode, $dateStart, $dateEnd, $forUpdate = false)
    {
        $query = $this->db($this->tables['statement'])->where($this->activeYearWhere());
        if ($bankCode !== '') {
            $query->where('bank_code', $bankCode);
        }
        $this->applyDateScope($query, 'transaction_date', $dateStart, $dateEnd);
        $query->field($this->fields['statement'])
            ->order('transaction_time asc, source_row_no asc');
        if ($forUpdate) {
            $query->lock(true);
        }
        return $query->select();
    }

    /**
     * 一次性锁定并预取自动对账所需的完整范围。调用方应先锁账套且持有外层事务，
     * 然后把返回值交给 CaseFundBankReconcileMatcher::matchPrefetched()。这样查询数
     * 固定为四条，不再随银行流水或业务记录数量线性增长。
     */
    public function prefetchReconcileScope($bankCode, $dateStart, $dateEnd, $forUpdate = false)
    {
        return [
            'statements' => $this->prefetchScopeRows(
                'statement',
                'transaction_date',
                $dateStart,
                $dateEnd,
                self::RECONCILE_STATEMENT_FIELDS,
                'transaction_time asc, source_row_no asc, statement_id asc',
                $forUpdate,
                $bankCode
            ),
            'payments' => $this->prefetchScopeRows(
                'payment',
                'payment_date',
                $dateStart,
                $dateEnd,
                self::RECONCILE_PAYMENT_FIELDS,
                'payment_date asc, source_row_no asc, payment_id asc',
                $forUpdate
            ),
            'unknown_payments' => $this->prefetchScopeRows(
                'unknown_payment',
                'transaction_date',
                $dateStart,
                $dateEnd,
                self::RECONCILE_UNKNOWN_PAYMENT_FIELDS,
                'transaction_time asc, source_row_no asc, unknown_payment_id asc',
                $forUpdate
            ),
            'refunds' => $this->prefetchScopeRows(
                'refund',
                'refund_date',
                $dateStart,
                $dateEnd,
                self::RECONCILE_REFUND_FIELDS,
                'refund_date asc, source_row_no asc, refund_id asc',
                $forUpdate
            ),
        ];
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
            ->order('reconcile_date asc, bank_serial_no asc, reconcile_id asc')
            ->page($page, $pageSize)
            ->select();
        return [
            'items' => $rows,
            'total' => $total,
            // 保持原口径：状态汇总跟随结构化筛选，但不跟随关键词。
            'summary' => $this->bankReconcileSummary($where, $emptyCounts),
        ];
    }

    public function refundCandidatesByBankStatement($statement, $forUpdate = false)
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
        if ($forUpdate) {
            $query->lock(true);
        }
        return $query->field($this->fields['refund'])->select();
    }

    public function paymentCandidatesInScope($dateStart, $dateEnd, $forUpdate = false)
    {
        $query = $this->db($this->tables['payment'])->where($this->activeYearWhere());
        $this->applyDateScope($query, 'payment_date', $dateStart, $dateEnd);
        if ($forUpdate) {
            $query->lock(true);
        }
        $rows = $query->field($this->fields['payment'])->select();
        // 无流水号业务也需进入 BIZ_ONLY 待核查清单。
        return array_values($rows);
    }

    public function unknownPaymentCandidatesInScope($dateStart, $dateEnd, $forUpdate = false)
    {
        $query = $this->db($this->tables['unknown_payment'])->where($this->activeYearWhere());
        $this->applyDateScope($query, 'transaction_date', $dateStart, $dateEnd);
        if ($forUpdate) {
            $query->lock(true);
        }
        $rows = $query->field($this->fields['unknown_payment'])->select();
        return array_values(array_filter($rows, function ($row) {
            return $this->isUnclaimedUnknownPayment($row);
        }));
    }

    public function refundCandidatesInScope($dateStart, $dateEnd, $forUpdate = false)
    {
        $query = $this->db($this->tables['refund'])->where($this->activeYearWhere());
        $this->applyDateScope($query, 'refund_date', $dateStart, $dateEnd);
        if ($forUpdate) {
            $query->lock(true);
        }
        return $query->field($this->fields['refund'])->select();
    }

    public function bankStatementExistsByPayment($payment, $bankCode = '', $dateStart = '', $dateEnd = '')
    {
        $paymentDate = trim((string)($payment['payment_date'] ?? ''));
        if ($paymentDate === '') {
            return false;
        }
        $query = $this->db($this->tables['statement'])->where([
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'direction' => 'CREDIT',
            'transaction_date' => $paymentDate,
            'del_flag' => 0,
        ]);
        $this->applyBankAndDateScope($query, $bankCode, $dateStart, $dateEnd);
        $statements = $query->field($this->fields['statement'])->select();
        $paymentSerialNo = trim((string)($payment['bank_serial_no'] ?? ''));
        if ($paymentSerialNo === '') {
            return false;
        }
        foreach ($statements as $statement) {
            if ($this->bankSerialsMatch($statement, $paymentSerialNo)) {
                return true;
            }
        }
        return false;
    }

    public function bankStatementExistsByUnknownPayment($unknownPayment, $bankCode = '', $dateStart = '', $dateEnd = '')
    {
        $transactionDate = trim((string)($unknownPayment['transaction_date'] ?? ''));
        $payerAccountNo = $this->normalizeAccountNo($unknownPayment['payer_account_no'] ?? '');
        if ($transactionDate === '' || $payerAccountNo === '') {
            return false;
        }
        $query = $this->db($this->tables['statement'])->where([
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'direction' => 'CREDIT',
            'transaction_date' => $transactionDate,
            'del_flag' => 0,
        ]);
        $this->applyBankAndDateScope($query, $bankCode, $dateStart, $dateEnd);
        foreach ($query->field($this->fields['statement'])->select() as $statement) {
            if ($this->normalizeAccountNo($statement['counterparty_account_no'] ?? '') === $payerAccountNo) {
                return true;
            }
        }
        return false;
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
            'debit_amount' => $refund['refund_amount'] ?? '0',
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

    protected function bankSerialsMatch($statement, $paymentSerialNo)
    {
        $statementSerialNo = $this->normalizeStatementSerialNo($statement['bank_serial_no'] ?? '');
        $paymentSerialNo = $this->normalizeSerialNo($paymentSerialNo);
        if ($statementSerialNo === '' || $paymentSerialNo === '') {
            return false;
        }
        if ($statementSerialNo === $paymentSerialNo) {
            return true;
        }
        // 建设银行对账单保存 26 位完整流水号，缴费登记保存其后 19 位业务流水号。
        return strtoupper(trim((string)($statement['bank_code'] ?? ''))) === 'CCB'
            && strlen($statementSerialNo) === 26
            && strlen($paymentSerialNo) === 19
            && substr($statementSerialNo, -19) === $paymentSerialNo;
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
            // 银行对账单保存“明细编号-交易流水号”，缴费登记保存最后一个“-”后的完整流水号。
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

    protected function bankStatementFilteredQuery($where, $keyword, $purpose = '')
    {
        $query = $this->db($this->tables['statement'])->where($where);
        if ($purpose !== '') {
            $query->where('purpose', 'like', '%' . $purpose . '%');
        }
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

    protected function prefetchScopeRows(
        $tableKey,
        $dateField,
        $dateStart,
        $dateEnd,
        array $fields,
        $order,
        $forUpdate,
        $bankCode = ''
    ) {
        $query = $this->db($this->tables[$tableKey])->where($this->activeYearWhere());
        if ($tableKey === 'statement' && trim((string)$bankCode) !== '') {
            $query->where('bank_code', trim((string)$bankCode));
        }
        $this->applyDateScope($query, $dateField, $dateStart, $dateEnd);
        $query->field($fields)->order($order);
        if ($forUpdate) {
            $query->lock(true);
        }
        $rows = $query->select();
        return is_array($rows) ? $rows : [];
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
