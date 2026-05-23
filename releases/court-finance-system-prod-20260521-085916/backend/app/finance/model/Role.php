<?php

namespace app\finance\model;

use think\Db;

class Role extends Common
{
    const ACTION = 'role';
    const TABLE = 'fin_role';
    const TABLE_PERMISSION = 'fin_role_permission';
    const TABLE_USER_ROLE = 'fin_user_role';

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'list':
                return $this->getList($data);
            case 'info':
                return $this->getInfo($data);
            case 'add':
                return $this->add($data);
            case 'edit':
                return $this->edit($data);
            case 'delete':
                return $this->delete($data);
            case 'assignPermissions':
                return $this->assignPermissions($data);
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
        $query = $this->getdb(self::TABLE)->where([]);
        $keyword = trim($data['keyword'] ?? '');
        if ($keyword !== '') {
            $query->where('role_code|role_name', 'like', '%' . $keyword . '%');
        }
        if (isset($data['status']) && $data['status'] !== '') {
            $query->where('status', (int)$data['status']);
        }
        $rows = $query->order('is_system desc, role_code asc')->select();
        foreach ($rows as &$row) {
            $row['permission_count'] = $this->getdb(self::TABLE_PERMISSION)
                ->where('role_id', $row['role_id'])
                ->count();
            $row['user_count'] = $this->getdb(self::TABLE_USER_ROLE)
                ->where('role_id', $row['role_id'])
                ->count();
        }
        return $this->ok($rows, 'OK', count($rows));
    }

    public function getInfo($data = [])
    {
        $auth = $this->requirePermission('system:role:view');
        if ($auth) {
            return $auth;
        }
        $roleId = trim($data['role_id'] ?? '');
        if ($roleId === '') {
            return $this->error('角色ID不能为空');
        }
        $role = $this->getdb(self::TABLE)->where('role_id', $roleId)->find();
        if (!$role) {
            return $this->error('角色不存在');
        }
        $role['permission_codes'] = $this->getdb(self::TABLE_PERMISSION)
            ->where('role_id', $roleId)
            ->column('permission_code');
        return $this->ok($role);
    }

    public function add($data = [])
    {
        $auth = $this->requirePermission('system:role:add');
        if ($auth) {
            return $auth;
        }
        $code = trim($data['role_code'] ?? '');
        $name = trim($data['role_name'] ?? '');
        if ($code === '' || $name === '') {
            return $this->error('角色编码和角色名称不能为空');
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,49}$/', $code)) {
            return $this->error('角色编码必须以字母开头，只能包含字母、数字和下划线');
        }
        if ($this->getdb(self::TABLE)->where('role_code', $code)->count() > 0) {
            return $this->error('角色编码已存在');
        }
        $row = [
            'role_id' => uuid(),
            'role_code' => $code,
            'role_name' => $name,
            'description' => $data['description'] ?? '',
            'is_system' => 0,
            'view_scope' => $this->normalizeViewScope($data['view_scope'] ?? 'ALL'),
            'status' => isset($data['status']) ? (int)$data['status'] : 1,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ];
        $this->getdb(self::TABLE)->insert($row);
        return $this->ok($row['role_id'], '操作成功');
    }

    public function edit($data = [])
    {
        $auth = $this->requirePermission('system:role:edit');
        if ($auth) {
            return $auth;
        }
        $roleId = trim($data['role_id'] ?? '');
        if ($roleId === '') {
            return $this->error('角色ID不能为空');
        }
        $before = $this->getdb(self::TABLE)->where('role_id', $roleId)->find();
        if (!$before) {
            return $this->error('角色不存在');
        }
        $row = [
            'role_name' => trim($data['role_name'] ?? $before['role_name']),
            'description' => $data['description'] ?? '',
            'view_scope' => $this->normalizeViewScope($data['view_scope'] ?? $before['view_scope']),
            'status' => isset($data['status']) ? (int)$data['status'] : (int)$before['status'],
            'updated_at' => $this->now(),
        ];
        if ((int)$before['is_system'] !== 1 && !empty($data['role_code'])) {
            $code = trim($data['role_code']);
            if ($code !== $before['role_code']) {
                if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,49}$/', $code)) {
                    return $this->error('角色编码必须以字母开头，只能包含字母、数字和下划线');
                }
                if ($this->getdb(self::TABLE)->where(['role_code' => $code, 'role_id' => ['neq', $roleId]])->count() > 0) {
                    return $this->error('角色编码已存在');
                }
                $row['role_code'] = $code;
            }
        }
        $this->getdb(self::TABLE)->where('role_id', $roleId)->update($row);
        return $this->ok($roleId, '操作成功');
    }

    public function delete($data = [])
    {
        $auth = $this->requirePermission('system:role:delete');
        if ($auth) {
            return $auth;
        }
        $roleId = trim($data['role_id'] ?? '');
        if ($roleId === '') {
            return $this->error('角色ID不能为空');
        }
        $role = $this->getdb(self::TABLE)->where('role_id', $roleId)->find();
        if (!$role) {
            return $this->error('角色不存在');
        }
        if ((int)$role['is_system'] === 1) {
            return $this->error('系统预置角色不允许删除');
        }
        if ($this->getdb(self::TABLE_USER_ROLE)->where('role_id', $roleId)->count() > 0) {
            return $this->error('角色已有用户绑定，不允许删除');
        }
        $this->getdb(self::TABLE_PERMISSION)->where('role_id', $roleId)->delete();
        $this->getdb(self::TABLE)->where('role_id', $roleId)->delete();
        return $this->ok($roleId, '删除成功');
    }

    public function assignPermissions($data = [])
    {
        $auth = $this->requirePermission('system:role:assign_permission');
        if ($auth) {
            return $auth;
        }
        $roleId = trim($data['role_id'] ?? '');
        $codes = $data['permission_codes'] ?? [];
        if ($roleId === '') {
            return $this->error('角色ID不能为空');
        }
        if (!is_array($codes)) {
            return $this->error('权限码格式不正确');
        }
        if (!$this->getdb(self::TABLE)->where('role_id', $roleId)->find()) {
            return $this->error('角色不存在');
        }
        Db::startTrans();
        try {
            $this->getdb(self::TABLE_PERMISSION)->where('role_id', $roleId)->delete();
            foreach (array_values(array_unique($codes)) as $code) {
                $code = trim((string)$code);
                if ($code === '') {
                    continue;
                }
                $this->getdb(self::TABLE_PERMISSION)->insert([
                    'role_id' => $roleId,
                    'permission_code' => $code,
                ]);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('保存权限失败：' . $e->getMessage());
        }
        return $this->ok($roleId, '权限已保存');
    }

    protected function normalizeViewScope($scope)
    {
        return strtoupper((string)$scope) === 'SELF' ? 'SELF' : 'ALL';
    }
}
