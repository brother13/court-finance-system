<?php

namespace app\finance\service\casefund\banking;

/**
 * 银行流水删除前的完整性与对账占用校验；不负责事务、更新或审计。
 */
class CaseFundBankStatementDeletionService
{
    protected $hooks;

    public function __construct(array $hooks)
    {
        foreach (['unmatched_statements_by_ids', 'active_reconcile_count'] as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款银行流水删除服务缺少回调：' . $name);
            }
        }
        $this->hooks = $hooks;
    }

    public function prepare($ids)
    {
        $rows = $this->callHook('unmatched_statements_by_ids', [$ids]);
        if (count($rows) !== count($ids)) {
            return [
                'error' => '只能删除未对账的银行对账单流水',
                'rows' => [],
            ];
        }
        $reconcileCount = $this->callHook('active_reconcile_count', [$ids]);
        if ((int)$reconcileCount > 0) {
            return [
                'error' => '只能删除未对账的银行对账单流水',
                'rows' => [],
            ];
        }
        return [
            'error' => '',
            'rows' => $rows,
        ];
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
