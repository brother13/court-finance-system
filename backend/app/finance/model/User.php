<?php

namespace app\finance\model;

use think\Db;

class User extends Common
{
    const ACTION = 'user';
    const TABLE = 'sys_user';

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
            case 'toggleStatus':
                return $this->toggleStatus($data);
            case 'resetPassword':
                return $this->resetPassword($data);
            case 'changePassword':
                return $this->changePassword($data);
            case 'assignRoles':
                return $this->assignRoles($data);
            case 'assignAccountSets':
                return $this->assignAccountSets($data);
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function getList($data = [])
    {
        $auth = $this->requirePermission('system:user:view');
        if ($auth) {
            return $auth;
        }
        $page = $data['page'] ?? input('param.page', 1);
        $pagesize = $data['size'] ?? ($data['pagesize'] ?? input('param.pagesize', 50));
        $total = $this->buildListQuery($data)->count();
        $rows = $this->buildListQuery($data)
            ->field('u.user_id,u.unit_id,u.username,u.real_name,u.mobile,u.email,u.status,u.must_change_password,u.last_login_time,u.created_time,unit.unit_name')
            ->order('u.created_time desc,u.username asc')
            ->page($page, $pagesize)
            ->select();
        foreach ($rows as &$row) {
            $roleNames = Db::name('fin_user_role')->alias('ur')
                ->join('fin_role r', 'r.role_id=ur.role_id')
                ->where('ur.user_id', $row['user_id'])
                ->order('r.role_code asc')
                ->column('r.role_name');
            $accountSetNames = Db::name('fin_user_account_set')->alias('ua')
                ->join('fin_account_set a', 'a.account_set_id=ua.account_set_id')
                ->where('ua.user_id', $row['user_id'])
                ->order('a.set_code asc')
                ->column('a.set_name');
            $row['role_names'] = implode('、', $roleNames);
            $row['account_set_names'] = implode('、', $accountSetNames);
        }
        return $this->ok(['items' => $rows, 'total' => $total], 'OK', $total);
    }

    protected function buildListQuery($data)
    {
        $query = $this->getdb(self::TABLE)->alias('u')
            ->join('sys_unit unit', 'unit.unit_id=u.unit_id', 'LEFT')
            ->where('u.del_flag', 0);
        $keyword = trim($data['keyword'] ?? '');
        if ($keyword !== '') {
            $query->where('u.username|u.real_name|unit.unit_name', 'like', '%' . $keyword . '%');
        }
        if (isset($data['status']) && $data['status'] !== '') {
            $query->where('u.status', (int)$data['status']);
        }
        return $query;
    }

    public function getInfo($data = [])
    {
        $auth = $this->requirePermission('system:user:view');
        if ($auth) {
            return $auth;
        }
        $userId = trim($data['user_id'] ?? '');
        if ($userId === '') {
            return $this->error('用户ID不能为空');
        }
        $row = $this->getdb(self::TABLE)->where(['user_id' => $userId, 'del_flag' => 0])->find();
        if (!$row) {
            return $this->error('用户不存在');
        }
        unset($row['password_hash']);
        $row['role_ids'] = Db::name('fin_user_role')->where('user_id', $userId)->column('role_id');
        $row['account_set_ids'] = Db::name('fin_user_account_set')->where('user_id', $userId)->column('account_set_id');
        return $this->ok($row);
    }

    public function add($data = [])
    {
        $auth = $this->requirePermission('system:user:add');
        if ($auth) {
            return $auth;
        }
        $username = trim($data['username'] ?? '');
        $realName = trim($data['real_name'] ?? '');
        $unitId = trim($data['unit_id'] ?? '');
        $password = (string)($data['init_password'] ?? '');
        if ($username === '' || $realName === '' || $unitId === '' || $password === '') {
            return $this->error('用户名、姓名、单位和初始密码不能为空');
        }
        if (strlen($password) < 6) {
            return $this->error('初始密码至少 6 位');
        }
        if ($this->getdb(self::TABLE)->where(['unit_id' => $unitId, 'username' => $username, 'del_flag' => 0])->count() > 0) {
            return $this->error('同单位下用户名已存在');
        }
        $row = [
            'user_id' => uuid(),
            'unit_id' => $unitId,
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'real_name' => $realName,
            'mobile' => $data['mobile'] ?? '',
            'email' => $data['email'] ?? '',
            'status' => 1,
            'must_change_password' => 1,
            'remark' => $data['remark'] ?? '',
        ];
        $this->fillCreate($row);
        Db::startTrans();
        try {
            $this->getdb(self::TABLE)->insert($row);
            $this->replaceUserRoles($row['user_id'], $data['role_ids'] ?? []);
            $this->replaceUserAccountSets($row['user_id'], $data['account_set_ids'] ?? []);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('新增用户失败：' . $e->getMessage());
        }
        return $this->ok($row['user_id'], '操作成功');
    }

    public function edit($data = [])
    {
        $auth = $this->requirePermission('system:user:edit');
        if ($auth) {
            return $auth;
        }
        $userId = trim($data['user_id'] ?? '');
        if ($userId === '') {
            return $this->error('用户ID不能为空');
        }
        $before = $this->getdb(self::TABLE)->where(['user_id' => $userId, 'del_flag' => 0])->find();
        if (!$before) {
            return $this->error('用户不存在');
        }
        $row = [
            'real_name' => trim($data['real_name'] ?? $before['real_name']),
            'unit_id' => trim($data['unit_id'] ?? $before['unit_id']),
            'mobile' => $data['mobile'] ?? ($before['mobile'] ?? ''),
            'email' => $data['email'] ?? ($before['email'] ?? ''),
            'remark' => $data['remark'] ?? ($before['remark'] ?? ''),
        ];
        $this->fillUpdate($row);
        $this->getdb(self::TABLE)->where('user_id', $userId)->update($row);
        return $this->ok($userId, '操作成功');
    }

    public function delete($data = [])
    {
        $auth = $this->requirePermission('system:user:delete');
        if ($auth) {
            return $auth;
        }
        $userId = trim($data['user_id'] ?? '');
        if ($userId === '') {
            return $this->error('用户ID不能为空');
        }
        if ($this->isSystemAdminUser($userId) && $this->activeSystemAdminCount() <= 1) {
            return $this->error('不能删除最后一个系统管理员');
        }
        $row = ['del_flag' => 1, 'status' => 0];
        $this->fillUpdate($row);
        $this->getdb(self::TABLE)->where('user_id', $userId)->update($row);
        return $this->ok($userId, '删除成功');
    }

    public function toggleStatus($data = [])
    {
        $auth = $this->requirePermission('system:user:edit');
        if ($auth) {
            return $auth;
        }
        $userId = trim($data['user_id'] ?? '');
        $status = isset($data['status']) ? (int)$data['status'] : 1;
        if ($userId === '') {
            return $this->error('用户ID不能为空');
        }
        if ($status === 0 && $this->isSystemAdminUser($userId) && $this->activeSystemAdminCount() <= 1) {
            return $this->error('不能禁用最后一个系统管理员');
        }
        $row = ['status' => $status === 1 ? 1 : 0];
        $this->fillUpdate($row);
        $this->getdb(self::TABLE)->where(['user_id' => $userId, 'del_flag' => 0])->update($row);
        return $this->ok($userId, '操作成功');
    }

    public function resetPassword($data = [])
    {
        $auth = $this->requirePermission('system:user:reset_password');
        if ($auth) {
            return $auth;
        }
        $userId = trim($data['user_id'] ?? '');
        $password = (string)($data['new_password'] ?? '');
        if ($userId === '' || $password === '') {
            return $this->error('用户ID和新密码不能为空');
        }
        if (strlen($password) < 6) {
            return $this->error('新密码至少 6 位');
        }
        $row = [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => 1,
        ];
        $this->fillUpdate($row);
        $this->getdb(self::TABLE)->where(['user_id' => $userId, 'del_flag' => 0])->update($row);
        return $this->ok($userId, '密码已重置');
    }

    public function changePassword($data = [])
    {
        $oldPassword = (string)($data['old_password'] ?? '');
        $newPassword = (string)($data['new_password'] ?? '');
        if ($oldPassword === '' || $newPassword === '') {
            return $this->error('旧密码和新密码不能为空');
        }
        if ($oldPassword === $newPassword) {
            return $this->error('新密码不能与旧密码相同');
        }
        if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $newPassword)) {
            return $this->error('新密码至少 8 位，且必须包含字母和数字');
        }
        $user = $this->currentUser();
        if (!$user || !password_verify($oldPassword, $user['password_hash'])) {
            return $this->error('旧密码不正确');
        }
        $row = [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'must_change_password' => 0,
        ];
        $this->fillUpdate($row);
        $this->getdb(self::TABLE)->where('user_id', $user['user_id'])->update($row);
        return $this->ok($user['user_id'], '密码已修改');
    }

    public function assignRoles($data = [])
    {
        $auth = $this->requirePermission('system:user:edit');
        if ($auth) {
            return $auth;
        }
        $userId = trim($data['user_id'] ?? '');
        $roleIds = $data['role_ids'] ?? [];
        if ($userId === '') {
            return $this->error('用户ID不能为空');
        }
        if (!is_array($roleIds)) {
            return $this->error('角色格式不正确');
        }
        if ($this->isSystemAdminUser($userId) && !$this->roleIdsContainSystemAdmin($roleIds) && $this->activeSystemAdminCount() <= 1) {
            return $this->error('不能移除最后一个系统管理员角色');
        }
        $this->replaceUserRoles($userId, $roleIds);
        return $this->ok($userId, '角色已分配');
    }

    public function assignAccountSets($data = [])
    {
        $auth = $this->requirePermission('system:user:edit');
        if ($auth) {
            return $auth;
        }
        $userId = trim($data['user_id'] ?? '');
        $accountSetIds = $data['account_set_ids'] ?? [];
        if ($userId === '') {
            return $this->error('用户ID不能为空');
        }
        if (!is_array($accountSetIds)) {
            return $this->error('账套格式不正确');
        }
        $this->replaceUserAccountSets($userId, $accountSetIds);
        return $this->ok($userId, '账套已分配');
    }

    protected function replaceUserRoles($userId, $roleIds)
    {
        Db::name('fin_user_role')->where('user_id', $userId)->delete();
        foreach (array_values(array_unique($roleIds)) as $roleId) {
            $roleId = trim((string)$roleId);
            if ($roleId === '') {
                continue;
            }
            Db::name('fin_user_role')->insert(['user_id' => $userId, 'role_id' => $roleId]);
        }
    }

    protected function replaceUserAccountSets($userId, $accountSetIds)
    {
        Db::name('fin_user_account_set')->where('user_id', $userId)->delete();
        foreach (array_values(array_unique($accountSetIds)) as $accountSetId) {
            $accountSetId = trim((string)$accountSetId);
            if ($accountSetId === '') {
                continue;
            }
            Db::name('fin_user_account_set')->insert(['user_id' => $userId, 'account_set_id' => $accountSetId]);
        }
    }

    protected function systemAdminRoleId()
    {
        return (string)Db::name('fin_role')->where('role_code', 'system_admin')->value('role_id');
    }

    protected function roleIdsContainSystemAdmin($roleIds)
    {
        $adminRoleId = $this->systemAdminRoleId();
        return $adminRoleId !== '' && in_array($adminRoleId, $roleIds, true);
    }

    protected function isSystemAdminUser($userId)
    {
        $adminRoleId = $this->systemAdminRoleId();
        if ($adminRoleId === '') {
            return false;
        }
        return Db::name('fin_user_role')->where(['user_id' => $userId, 'role_id' => $adminRoleId])->count() > 0;
    }

    protected function activeSystemAdminCount()
    {
        $adminRoleId = $this->systemAdminRoleId();
        if ($adminRoleId === '') {
            return 0;
        }
        return Db::name(self::TABLE)->alias('u')
            ->join('fin_user_role ur', 'ur.user_id=u.user_id')
            ->where([
                'ur.role_id' => $adminRoleId,
                'u.status' => 1,
                'u.del_flag' => 0,
            ])
            ->count();
    }
}
