<?php

namespace app\finance\service\voucher;

/**
 * 期间内最小空号分配。调用方负责外层事务，且同一期间的待保存计划须一次批量取号。
 * 不缓存预留状态，避免模型在后台任务的多个事务之间复用时遗留占号。
 */
class VoucherNumberService
{
    protected $hooks;

    public function __construct(array $hooks)
    {
        foreach (['get_db', 'account_set_id', 'fiscal_year', 'prepare_create', 'prepare_update', 'new_id'] as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('凭证取号服务缺少回调：' . $name);
            }
        }
        $this->hooks = $hooks;
    }

    public function peek($period)
    {
        $rows = $this->db('fin_voucher')->where($this->scope($period) + ['del_flag' => 0])
            ->field('voucher_no')->select();
        return $this->availableNumbers($rows, 1)[0];
    }

    public function lock($period)
    {
        $where = $this->scope($period) + ['del_flag' => 0];
        $row = $this->db('fin_voucher_no_sequence')->where($where)->lock(true)->find();
        if (!$row) {
            $row = $this->call('prepare_create', [$where + [
                'sequence_id' => $this->call('new_id'), 'current_no' => 0,
            ]]);
            if ((int)$this->db('fin_voucher_no_sequence')->insert($row) !== 1) {
                throw new \RuntimeException('创建凭证号序列失败');
            }
        }
        return $row;
    }

    public function allocate($period, $count = 1)
    {
        if (!is_int($count) || $count < 1) {
            throw new \InvalidArgumentException('凭证取号数量必须为正整数');
        }
        $sequence = $this->lock($period);
        // 必须当前读：等待序列锁之前，外层业务校验可能已建立 REPEATABLE READ 快照。
        $rows = $this->db('fin_voucher')->where($this->scope($period))
            ->field('voucher_id,voucher_no,del_flag,remark')->order('voucher_no asc,voucher_id asc')
            ->lock(true)->select();
        $active = [];
        $minimum = 0;
        foreach ($rows as $row) {
            $number = (int)$row['voucher_no'];
            $minimum = min($minimum, $number);
            if ((int)$row['del_flag'] === 0) {
                $active[] = $row;
            }
        }
        $numbers = $this->availableNumbers($active, $count);
        $selected = array_fill_keys($numbers, true);
        foreach ($rows as $row) {
            $oldNumber = (int)$row['voucher_no'];
            if ((int)$row['del_flag'] !== 1 || !isset($selected[$oldNumber])) {
                continue;
            }
            if ($minimum <= -2147483648) {
                throw new \RuntimeException('历史凭证号避让范围已耗尽');
            }
            $minimum--;
            $remark = trim((string)($row['remark'] ?? '') . ' 删除凭证号避让原号' . $oldNumber);
            $remark = function_exists('mb_substr') ? mb_substr($remark, 0, 500, 'UTF-8') : substr($remark, 0, 500);
            $update = $this->call('prepare_update', [['voucher_no' => $minimum, 'remark' => $remark]]);
            $affected = $this->db('fin_voucher')->where($this->scope($period) + [
                'voucher_id' => (string)$row['voucher_id'], 'voucher_no' => $oldNumber, 'del_flag' => 1,
            ])->update($update);
            if ((int)$affected !== 1) {
                throw new \RuntimeException('历史凭证号状态已变化，请刷新后重试');
            }
        }
        $maximum = max($numbers);
        foreach ($active as $row) {
            $maximum = max($maximum, (int)$row['voucher_no']);
        }
        $this->updateSequence($period, $sequence, $maximum);
        return $numbers;
    }

    public function synchronize($period)
    {
        $sequence = $this->lock($period);
        $rows = $this->db('fin_voucher')->where($this->scope($period) + ['del_flag' => 0])
            ->field('voucher_no')->lock(true)->select();
        $maximum = 0;
        foreach ($rows as $row) {
            $maximum = max($maximum, (int)$row['voucher_no']);
        }
        $this->updateSequence($period, $sequence, $maximum);
        return $maximum;
    }

    protected function availableNumbers(array $rows, $count)
    {
        $used = [];
        foreach ($rows as $row) {
            $number = (int)$row['voucher_no'];
            if ($number > 0) {
                $used[$number] = true;
            }
        }
        $numbers = [];
        for ($number = 1; count($numbers) < $count; $number++) {
            if ($number > 2147483647) {
                throw new \RuntimeException('凭证号已超过数据库字段范围');
            }
            if (!isset($used[$number])) {
                $numbers[] = $number;
            }
        }
        return $numbers;
    }

    protected function updateSequence($period, array $sequence, $maximum)
    {
        $update = $this->call('prepare_update', [['current_no' => $maximum]]);
        $affected = $this->db('fin_voucher_no_sequence')->where($this->scope($period) + [
            'sequence_id' => (string)$sequence['sequence_id'], 'del_flag' => 0,
            'current_no' => (int)$sequence['current_no'],
        ])->update($update);
        if ($affected === false || (int)$affected < 0 || (int)$affected > 1
            || ((int)$sequence['current_no'] !== $maximum && (int)$affected !== 1)
        ) {
            throw new \RuntimeException('凭证号序列状态已变化，请刷新后重试');
        }
    }

    protected function scope($period)
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$period)) {
            throw new \InvalidArgumentException('会计期间格式不正确');
        }
        return [
            'account_set_id' => $this->call('account_set_id'),
            'fiscal_year' => $this->call('fiscal_year', [$period]),
            'period' => $period,
        ];
    }

    protected function db($table) { return $this->call('get_db', [$table]); }
    protected function call($name, array $arguments = []) { return call_user_func_array($this->hooks[$name], $arguments); }
}
