<?php

namespace app\finance\model;

class Log extends Common
{
    const ACTION = 'log';
    const TABLE = 'sys_audit_log';

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'list':
                return $this->getList($data);
            case 'info':
                return $this->getInfo($data);
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function recordRequest($actionArr, $postdata)
    {
        return true;
    }

    public function getList($data = [])
    {
        $auth = $this->requirePermission('menu:system:audit_log');
        if ($auth) {
            return $auth;
        }
        $page = $data['page'] ?? input('param.page', 1);
        $pagesize = $data['pagesize'] ?? input('param.pagesize', 50);
        $where = ['account_set_id' => $this->accountSetId];
        if (!empty($data['biz_type'])) {
            $where['biz_type'] = $data['biz_type'];
        }
        $num = $this->getdb(self::TABLE)->where($where)->count();
        $rows = $this->getdb(self::TABLE)->where($where)->order('created_time asc')->page($page, $pagesize)->select();
        return $this->ok(['items' => $rows, 'total' => $num], 'OK', $num);
    }

    public function getInfo($data = [])
    {
        $auth = $this->requirePermission('menu:system:audit_log');
        if ($auth) {
            return $auth;
        }
        $id = $data['log_id'] ?? '';
        if ($id === '') {
            return $this->error('日志ID不能为空');
        }
        $row = $this->getdb(self::TABLE)->where(['log_id' => $id, 'account_set_id' => $this->accountSetId])->find();
        if (!$row) {
            return $this->error('日志不存在');
        }
        return $this->ok($row);
    }
}
