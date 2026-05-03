<?php

namespace app\finance\model;

use think\Db;

class Auth extends Common
{
    public function index($method, $data = [])
    {
        switch ($method) {
            case 'unitList':
                return $this->unitList($data);
            case 'accountSetList':
                return $this->accountSetList($data);
            case 'login':
                return $this->login($data);
            default:
                return $this->error('认证操作不存在');
        }
    }

    protected function accountSetList($data)
    {
        if (!empty($this->userid) && !in_array($this->userid, ['system', 'anonymous'])) {
            $allowed = $this->userAccountSets($this->userid);
            return $this->ok($allowed);
        }

        $rows = Db::name('fin_account_set')
            ->where(['status' => 1, 'del_flag' => 0])
            ->field('account_set_id,set_code,set_name,biz_type,enabled_year,remark')
            ->order('biz_type asc,set_code asc')
            ->select();

        return $this->ok($rows);
    }

    protected function unitList($data)
    {
        $rows = Db::name('sys_unit')
            ->where(['status' => 1, 'del_flag' => 0])
            ->field('unit_id,unit_code,unit_name')
            ->order('sort_no asc, unit_code asc')
            ->select();

        return $this->ok($rows);
    }

    protected function login($data)
    {
        $unitId = isset($data['unit_id']) ? trim($data['unit_id']) : '';
        $username = isset($data['username']) ? trim($data['username']) : '';
        $password = isset($data['password']) ? (string)$data['password'] : '';

        if ($unitId === '' || $username === '' || $password === '') {
            return $this->error('单位、用户名和密码不能为空');
        }

        $unit = Db::name('sys_unit')
            ->where(['unit_id' => $unitId, 'status' => 1, 'del_flag' => 0])
            ->find();
        if (!$unit) {
            return $this->error('单位不存在或已停用');
        }

        $user = Db::name('sys_user')
            ->where([
                'unit_id' => $unitId,
                'username' => $username,
                'del_flag' => 0,
            ])
            ->find();

        if (!$user) {
            return $this->error('用户名或密码错误');
        }
        if ((int)$user['status'] !== 1) {
            return $this->error('用户已被禁用');
        }
        if (!password_verify($password, $user['password_hash'])) {
            return $this->error('用户名或密码错误');
        }

        Db::name('sys_user')
            ->where('user_id', $user['user_id'])
            ->update([
                'last_login_time' => $this->now(),
                'updated_time' => $this->now(),
            ]);

        $roles = $this->userRoles($user['user_id']);
        $permissions = $this->userPermissions($user['user_id']);
        $accountSets = $this->userAccountSets($user['user_id']);
        foreach ($accountSets as &$accountSet) {
            $accountSet['id'] = $accountSet['account_set_id'];
            $accountSet['code'] = $accountSet['set_code'];
            $accountSet['name'] = $accountSet['set_name'];
        }

        return $this->ok([
            'user_id' => $user['user_id'],
            'username' => $user['username'],
            'real_name' => $user['real_name'],
            'unit_id' => $unit['unit_id'],
            'unit_code' => $unit['unit_code'],
            'unit_name' => $unit['unit_name'],
            'permissions' => $permissions,
            'view_scope' => $this->userViewScope($user['user_id']),
            'must_change_password' => (int)($user['must_change_password'] ?? 1),
            'roles' => $roles,
            'account_sets' => $accountSets,
        ], '登录成功');
    }
}
