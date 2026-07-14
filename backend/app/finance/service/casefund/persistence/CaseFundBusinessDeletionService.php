<?php

namespace app\finance\service\casefund\persistence;

/**
 * 案款业务数据通用删除校验与执行；事务、审计和接口返回由调用方负责。
 */
class CaseFundBusinessDeletionService
{
    protected $hooks;
    protected $reconcileTable;
    protected $fieldsByBizType;

    public function __construct(array $hooks, $reconcileTable, array $fieldsByBizType)
    {
        foreach ([
            'get_db',
            'account_where',
            'account_set_id',
            'current_year',
            'prepare_update',
            'has_active_generated_voucher',
            'assert_rows_can_delete',
            'should_physical_delete',
        ] as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款删除服务缺少回调：' . $name);
            }
        }
        $this->hooks = $hooks;
        $this->reconcileTable = $reconcileTable;
        $this->fieldsByBizType = $fieldsByBizType;
    }

    public function prepareBusinessDeletion($table, $idField, $ids, $bizType, $label)
    {
        if (!isset($this->fieldsByBizType[$bizType])) {
            return ['error' => '不支持删除该业务类型：' . $bizType];
        }
        $where = $this->callHook('account_where');
        $where['fiscal_year'] = $this->callHook('current_year');
        $rows = $this->db($table)
            ->where($where)
            ->where($idField, 'in', $ids)
            ->field($this->fieldsByBizType[$bizType])
            ->lock(true)
            ->select();
        if (count($rows) !== count($ids)) {
            return ['error' => $label . '不存在或已删除'];
        }
        $deleteCheck = $this->callHook('assert_rows_can_delete', [$rows, $label]);
        if ($deleteCheck !== null) {
            return ['error' => $deleteCheck];
        }
        $reconcileCount = $this->db($this->reconcileTable)->where([
            'account_set_id' => $this->callHook('account_set_id'),
            'biz_type' => $bizType,
            'del_flag' => 0,
        ])->where('biz_id', 'in', $ids)->count();
        if ((int)$reconcileCount > 0) {
            return ['error' => '只能删除未对账的' . $label];
        }
        return [
            'rows' => $rows,
            'hard_delete' => (bool)$this->callHook('should_physical_delete', [$bizType]),
        ];
    }

    public function executeBusinessDeletion($table, $idField, $ids, $hardDelete)
    {
        $where = [
            'account_set_id' => $this->callHook('account_set_id'),
            'fiscal_year' => $this->callHook('current_year'),
            'del_flag' => 0,
        ];
        if ($hardDelete) {
            $affectedRows = $this->db($table)
                ->where($where)
                ->where($idField, 'in', $ids)
                ->delete();
        } else {
            $update = $this->callHook('prepare_update', [['del_flag' => 1]]);
            $affectedRows = $this->db($table)
                ->where($where)
                ->where($idField, 'in', $ids)
                ->update($update);
        }
        if ((int)$affectedRows !== count($ids)) {
            throw new \RuntimeException('案款业务记录状态已变化，请刷新后重试');
        }
        return $affectedRows;
    }

    public function shouldPhysicalDeleteCaseFundBusinessRows($bizType)
    {
        return in_array($bizType, ['PAYMENT', 'REFUND', 'UNKNOWN_PAYMENT', 'DISTRIBUTION'], true);
    }

    public function assertRowsCanDeleteBusinessData($rows, $label)
    {
        foreach ($rows as $row) {
            $status = $row['voucher_status'] ?? 'UNGENERATED';
            if ($status === 'UNGENERATED') {
                continue;
            }
            if ($status === 'GENERATED' && !$this->callHook('has_active_generated_voucher', [$row])) {
                continue;
            }
            if ($status === 'GENERATED') {
                return '案号【' . ($row['case_no'] ?? '') . '】的' . $label . '已生成凭证且原凭证仍有效，不允许删除';
            }
            return '案号【' . ($row['case_no'] ?? '') . '】的' . $label . '制证状态为【' . $status . '】，不允许删除';
        }
        return null;
    }

    protected function db($table)
    {
        return $this->callHook('get_db', [$table]);
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
