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
            $allowed = $this->fillAvailableYears($allowed);
            $allowed = $this->appendAccountSetPeriodFields($allowed);
            return $this->ok($allowed);
        }

        $rows = Db::name('fin_account_set')
            ->where(['status' => 1, 'del_flag' => 0])
            ->field('account_set_id,set_code,set_name,biz_type,enabled_year,enabled_period,remark')
            ->order('biz_type asc,set_code asc')
            ->select();

        $rows = $this->fillAvailableYears($rows);
        $rows = $this->appendAccountSetPeriodFields($rows);
        return $this->ok($rows);
    }

    protected function appendAccountSetPeriodFields($accountSets)
    {
        foreach ($accountSets as &$accountSet) {
            $enabledPeriod = $this->normalizeEnabledPeriod($accountSet['enabled_period'] ?? '', $accountSet['enabled_year'] ?? '');
            $currentPeriod = $this->currentPeriodValue($enabledPeriod, $this->latestVoucherDate($accountSet['account_set_id']));
            $accountSet['enabled_period'] = $enabledPeriod;
            $accountSet['current_period'] = $currentPeriod;
        }
        return $accountSets;
    }

    protected function normalizeEnabledPeriod($enabledPeriod, $enabledYear)
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$enabledPeriod)) {
            return $enabledPeriod;
        }
        return ((string)$enabledYear) . '-01';
    }

    protected function currentPeriodValue($enabledPeriod, $latestVoucherDate)
    {
        if (!empty($latestVoucherDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $latestVoucherDate)) {
            return substr($latestVoucherDate, 0, 7);
        }
        return $enabledPeriod;
    }

    protected function latestVoucherDate($accountSetId)
    {
        try {
            return Db::name('fin_voucher')->where([
                'account_set_id' => $accountSetId,
                'del_flag' => 0,
            ])->max('voucher_date') ?: '';
        } catch (\Exception $e) {
            return '';
        }
    }

    protected function fillAvailableYears($accountSets)
    {
        foreach ($accountSets as &$accountSet) {
            $years = [];
            try {
                $periods = Db::name('fin_fiscal_period')
                    ->where(['account_set_id' => $accountSet['account_set_id']])
                    ->column('period');
                foreach ($periods as $period) {
                    $year = (int) substr($period, 0, 4);
                    if ($year > 0 && !in_array($year, $years, true)) {
                        $years[] = $year;
                    }
                }
            } catch (\Exception $e) {
                $years = [];
            }
            if (empty($years) && !empty($accountSet['enabled_year'])) {
                $years[] = (int) $accountSet['enabled_year'];
            }
            rsort($years);
            $accountSet['available_years'] = $years;
        }
        return $accountSets;
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
