<?php

namespace app\finance\model;

class Permission extends Common
{
    const ACTION = 'permission';
    const TABLE = 'fin_permission';

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'list':
                return $this->getList($data);
            case 'userPermissions':
                return $this->getUserPermissions($data);
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function getList($data = [])
    {
        $auth = $this->requirePermission('system:role:view');
        if ($auth) {
            return $auth;
        }
        $rows = $this->getdb(self::TABLE)
            ->order('module_code asc, permission_type asc, sort_order asc, permission_code asc')
            ->select();
        $groups = [];
        foreach ($rows as $row) {
            $module = $row['module_code'] ?: 'other';
            if (!isset($groups[$module])) {
                $groups[$module] = [];
            }
            $groups[$module][] = $row;
        }
        return $this->ok(['items' => $rows, 'groups' => $groups], 'OK', count($rows));
    }

    public function getUserPermissions($data = [])
    {
        return $this->ok([
            'permissions' => $this->currentPermissions(),
            'view_scope' => $this->currentViewScope(),
            'roles' => $this->currentRoles(),
        ]);
    }
}
