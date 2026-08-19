<?php

namespace app\finance\service\casefund\banking;

/**
 * 案款银行流水及自动对账结果持久化。
 *
 * 调用方负责事务、权限、业务匹配、审计和接口响应；本服务负责统一数据范围、
 * 写入顺序、并发锁及实际影响行数校验。
 */
class CaseFundBankingPersistenceService
{
    const RESULT_STATUSES = ['MATCHED', 'AMOUNT_DIFF', 'BANK_ONLY', 'BIZ_ONLY', 'DUPLICATE'];
    const STATEMENT_STATUSES = ['MATCHED', 'AMOUNT_DIFF', 'BANK_ONLY', 'DUPLICATE'];
    const RECONCILE_INSERT_BATCH_SIZE = 200;
    const STATEMENT_UPDATE_BATCH_SIZE = 500;

    protected $hooks;
    protected $tables;
    protected $autoMatchRules;

    public function __construct(array $hooks, array $tables, array $autoMatchRules)
    {
        foreach (['get_db', 'account_set_id', 'current_year', 'prepare_create', 'prepare_update'] as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款银行持久化服务缺少回调：' . $name);
            }
        }
        foreach (['account_set', 'statement', 'reconcile'] as $name) {
            if (empty($tables[$name])) {
                throw new \InvalidArgumentException('案款银行持久化服务缺少数据表：' . $name);
            }
        }
        if (empty($autoMatchRules)) {
            throw new \InvalidArgumentException('案款银行持久化服务缺少自动对账规则');
        }
        $this->hooks = $hooks;
        $this->tables = $tables;
        $this->autoMatchRules = array_values(array_unique($autoMatchRules));
    }

    /**
     * 在外层事务内锁定账套，串行化同一账套的自动对账重算。
     */
    public function lockReconcileScope()
    {
        $row = $this->db($this->tables['account_set'])->where([
            'account_set_id' => $this->accountSetId(),
            'del_flag' => 0,
        ])->lock(true)->find();
        if (!$row) {
            throw new \RuntimeException('当前账套不存在或已删除');
        }
    }

    public function insertStatements(array $rows)
    {
        $this->assertRowsHaveUniqueId($rows, 'statement_id', '银行流水');
        foreach ($rows as $index => $row) {
            $transactionDate = trim((string)($row['transaction_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionDate)) {
                throw new \InvalidArgumentException('银行流水第' . ($index + 1) . '行交易日期格式不正确');
            }
            $expectedYear = (int)substr($transactionDate, 0, 4);
            $expectedPeriod = substr($transactionDate, 0, 7);
            if ((int)($row['fiscal_year'] ?? 0) !== $expectedYear
                || trim((string)($row['period'] ?? '')) !== $expectedPeriod
                || $expectedYear !== (int)$this->currentYear()
            ) {
                throw new \InvalidArgumentException('银行流水第' . ($index + 1) . '行年度期间与交易日期不一致');
            }
        }
        foreach ($rows as $row) {
            $statementId = trim((string)$row['statement_id']);
            $row['account_set_id'] = $this->accountSetId();
            $row = $this->callHook('prepare_create', [$row]);
            $this->assertInserted(
                $this->db($this->tables['statement'])->insert($row),
                '银行流水写入失败：' . $statementId
            );
        }
        return count($rows);
    }

    public function deleteStatements(array $ids)
    {
        if (empty($ids)) {
            throw new \InvalidArgumentException('银行流水ID不能为空');
        }
        $update = $this->callHook('prepare_update', [['del_flag' => 1]]);
        $affectedRows = $this->db($this->tables['statement'])->where([
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'reconcile_status' => 'UNMATCHED',
            'del_flag' => 0,
        ])->where('statement_id', 'in', $ids)->update($update);
        if ((int)$affectedRows !== count($ids)) {
            throw new \RuntimeException('银行对账单流水状态已变化，请刷新后重试');
        }
        return (int)$affectedRows;
    }

    public function persistReconcileResult(
        $dateStart,
        $dateEnd,
        array $statementIds,
        array $resultRows,
        array $statementStatuses,
        array $currentStatementStatuses
    ) {
        $statementScope = [];
        foreach ($statementIds as $statementId) {
            $statementId = trim((string)$statementId);
            if ($statementId === '' || isset($statementScope[$statementId])) {
                throw new \InvalidArgumentException('本次对账银行流水ID为空或重复');
            }
            $statementScope[$statementId] = true;
        }
        foreach ($statementStatuses as $statementId => $status) {
            if (!isset($statementScope[$statementId])) {
                throw new \InvalidArgumentException('银行流水状态回写超出本次对账范围：' . $statementId);
            }
            if (trim((string)$status) === '') {
                throw new \InvalidArgumentException('银行流水对账状态不能为空：' . $statementId);
            }
            if (!in_array($status, self::STATEMENT_STATUSES, true)) {
                throw new \InvalidArgumentException('银行流水对账状态不支持：' . $status);
            }
        }
        if (count($statementStatuses) !== count($statementScope)) {
            throw new \InvalidArgumentException('本次对账银行流水状态回写不完整');
        }
        if (count($currentStatementStatuses) !== count($statementScope)) {
            throw new \InvalidArgumentException('本次对账银行流水锁定状态不完整');
        }
        foreach ($currentStatementStatuses as $statementId => $status) {
            if (!isset($statementScope[$statementId])) {
                throw new \InvalidArgumentException('银行流水锁定状态超出本次对账范围：' . $statementId);
            }
        }
        $this->assertRowsHaveUniqueId($resultRows, 'reconcile_id', '银行对账结果');
        foreach ($resultRows as $row) {
            $statementId = trim((string)($row['statement_id'] ?? ''));
            if ($statementId !== '' && !isset($statementScope[$statementId])) {
                throw new \InvalidArgumentException('银行对账结果关联流水超出本次对账范围：' . $statementId);
            }
            $matchStatus = trim((string)($row['match_status'] ?? ''));
            if (!in_array($matchStatus, self::RESULT_STATUSES, true)) {
                throw new \InvalidArgumentException('银行对账结果状态不支持：' . $matchStatus);
            }
            $matchRule = trim((string)($row['match_rule'] ?? ''));
            if (!in_array($matchRule, $this->autoMatchRules, true)) {
                throw new \InvalidArgumentException('银行对账匹配规则不支持：' . $matchRule);
            }
        }

        $this->softDeleteReconcileScope($dateStart, $dateEnd);

        $this->insertReconcileRows($resultRows);
        $this->updateStatementStatuses($statementStatuses, $currentStatementStatuses);
    }

    protected function insertReconcileRows(array $resultRows)
    {
        if (empty($resultRows)) {
            return;
        }
        $chunk = [];
        foreach ($resultRows as $row) {
            $row['account_set_id'] = $this->accountSetId();
            $row['fiscal_year'] = $this->currentYear();
            $chunk[] = $this->callHook('prepare_create', [$row]);
            if (count($chunk) >= self::RECONCILE_INSERT_BATCH_SIZE) {
                $this->insertReconcileChunk($chunk);
                $chunk = [];
            }
        }
        $this->insertReconcileChunk($chunk);
    }

    protected function insertReconcileChunk(array $chunk)
    {
        if (empty($chunk)) {
            return;
        }
        $affectedRows = $this->db($this->tables['reconcile'])->insertAll($chunk);
        if ($affectedRows === false || (int)$affectedRows !== count($chunk)) {
            $firstId = trim((string)($chunk[0]['reconcile_id'] ?? 'UNKNOWN'));
            $lastId = trim((string)($chunk[count($chunk) - 1]['reconcile_id'] ?? $firstId));
            $range = $firstId === $lastId ? $firstId : $firstId . '...' . $lastId;
            throw new \RuntimeException('银行对账结果写入失败：' . $range);
        }
    }

    protected function updateStatementStatuses(array $statementStatuses, array $currentStatementStatuses)
    {
        $transitionGroups = [];
        foreach ($statementStatuses as $statementId => $status) {
            $oldStatus = (string)$currentStatementStatuses[$statementId];
            $groupKey = $oldStatus . "\0" . (string)$status;
            if (!isset($transitionGroups[$groupKey])) {
                $transitionGroups[$groupKey] = [
                    'old_status' => $oldStatus,
                    'new_status' => (string)$status,
                    'statement_ids' => [],
                ];
            }
            $transitionGroups[$groupKey]['statement_ids'][] = $statementId;
        }

        foreach ($transitionGroups as $group) {
            foreach (array_chunk($group['statement_ids'], self::STATEMENT_UPDATE_BATCH_SIZE) as $statementIds) {
                $oldStatus = $group['old_status'];
                $newStatus = $group['new_status'];
                // 状态不变时 MySQL 可能返回0行；先独立 count 严格确认完整范围，
                // 再允许更新时间批量写入返回0..N。查询对象不可在 count 后复用。
                if ($oldStatus === $newStatus) {
                    $expectedRows = (int)$this->statementTransitionQuery($statementIds, $oldStatus)->count();
                    if ($expectedRows !== count($statementIds)) {
                        throw new \RuntimeException('银行流水对账状态写入失败：' . $statementIds[0]);
                    }
                }
                $update = $this->callHook('prepare_update', [['reconcile_status' => $newStatus]]);
                $affectedRows = $this->statementTransitionQuery($statementIds, $oldStatus)->update($update);
                if ($affectedRows === false) {
                    throw new \RuntimeException('银行流水对账状态写入失败：' . $statementIds[0]);
                }
                $affectedRows = (int)$affectedRows;
                if (($oldStatus !== $newStatus && $affectedRows !== count($statementIds))
                    || ($oldStatus === $newStatus && ($affectedRows < 0 || $affectedRows > count($statementIds)))
                ) {
                    throw new \RuntimeException('银行流水对账状态写入失败：' . $statementIds[0]);
                }
            }
        }
    }

    protected function statementTransitionQuery(array $statementIds, $oldStatus)
    {
        return $this->db($this->tables['statement'])->where([
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'reconcile_status' => $oldStatus,
            'del_flag' => 0,
        ])->where('statement_id', 'in', $statementIds);
    }

    protected function softDeleteReconcileScope($dateStart, $dateEnd)
    {
        $expectedRows = (int)$this->reconcileScopeQuery($dateStart, $dateEnd)->count();
        $update = $this->callHook('prepare_update', [['del_flag' => 1]]);
        $affectedRows = (int)$this->reconcileScopeQuery($dateStart, $dateEnd)->update($update);
        if ($affectedRows !== $expectedRows) {
            throw new \RuntimeException('原银行对账结果状态已变化，请刷新后重试');
        }
    }

    protected function reconcileScopeQuery($dateStart, $dateEnd)
    {
        $query = $this->db($this->tables['reconcile'])->where([
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'del_flag' => 0,
        ])->where('match_rule', 'in', $this->autoMatchRules);
        if ($dateStart !== '') {
            $query->where('reconcile_date', '>=', $dateStart);
        }
        if ($dateEnd !== '') {
            $query->where('reconcile_date', '<=', $dateEnd);
        }
        return $query;
    }

    protected function assertInserted($affectedRows, $message)
    {
        if ($affectedRows !== 1) {
            throw new \RuntimeException($message);
        }
    }

    protected function assertRowsHaveUniqueId(array $rows, $idField, $label)
    {
        $ids = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException($label . '第' . ($index + 1) . '行格式不正确');
            }
            $id = trim((string)($row[$idField] ?? ''));
            if ($id === '') {
                throw new \InvalidArgumentException($label . '第' . ($index + 1) . '行ID不能为空');
            }
            if (isset($ids[$id])) {
                throw new \InvalidArgumentException($label . 'ID重复：' . $id);
            }
            $ids[$id] = true;
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
