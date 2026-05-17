<?php

namespace app\finance\model;

class Period extends Common
{
    const ACTION = 'period';
    const TABLE = 'fin_fiscal_period';

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'list':
                return $this->getList();
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function getList()
    {
        $where = $this->accountWhere();
        $where['period'] = ['like', $this->year . '-%'];
        $rows = $this->getdb(self::TABLE)->where($where)->order('period asc')->select();
        return $this->ok($rows, 'OK', count($rows));
    }
}
