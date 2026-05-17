<?php

namespace app\finance\model;

use think\Db;

class Common
{
    const CODE_SUCCESS = 20000;
    const CODE_ERROR = 0;

    protected $accountSetId = '';
    protected $year = '';
    protected $userid = 'system';
    protected $username = 'system';
    protected $permissionCache = null;
    protected $roleCache = null;
    protected $viewScopeCache = null;

    public function __construct()
    {
        $this->accountSetId = input('param.account_set_id', config('default_account_set_id'));
        $this->year = input('param.year', config('default_year'));
        $this->userid = input('server.HTTP_X_USER_ID', 'system');
        $this->username = input('server.HTTP_X_USER_NAME', $this->userid);
    }

    protected function _rt()
    {
        return [
            'code' => self::CODE_ERROR,
            'action' => input('param.action', '/sys/info'),
            'message' => '',
            'page' => input('param.page', 1),
            'pagesize' => input('param.pagesize', 100),
            'time' => getNowTime(),
            'total' => 0,
            'data' => '',
        ];
    }

    public static function getdb($table = '')
    {
        return db($table);
    }

    protected function ok($data = '', $message = 'OK', $total = null)
    {
        $rt = $this->_rt();
        $rt['code'] = self::CODE_SUCCESS;
        $rt['message'] = $message;
        $rt['data'] = $data;
        if ($total !== null) {
            $rt['total'] = $total;
        }
        return $rt;
    }

    protected function error($message = '操作失败', $data = '')
    {
        $rt = $this->_rt();
        $rt['message'] = $message;
        $rt['data'] = $data;
        return $rt;
    }

    protected function accountWhere()
    {
        return [
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ];
    }

    protected function now()
    {
        return getNowTime();
    }

    protected function fillCreate(&$data)
    {
        $data['created_by'] = $this->userid;
        $data['created_time'] = $this->now();
        $data['updated_by'] = $this->userid;
        $data['updated_time'] = $this->now();
        $data['del_flag'] = 0;
        $data['version'] = 0;
    }

    protected function fillUpdate(&$data)
    {
        $data['updated_by'] = $this->userid;
        $data['updated_time'] = $this->now();
    }

    protected function currentYear()
    {
        return (int)$this->year;
    }

    protected function periodYear($period)
    {
        if (empty($period) || strlen($period) < 4) {
            return $this->year ?: config('default_year');
        }
        return substr($period, 0, 4);
    }

    protected function fiscalYear($period)
    {
        return (int)$this->periodYear($period);
    }

    protected function yearTable($baseTable, $period)
    {
        return $baseTable . '_' . $this->periodYear($period);
    }

    protected function decimalToCents($amount)
    {
        $amount = trim((string)$amount);
        if ($amount === '') {
            return 0;
        }
        $negative = false;
        if ($amount[0] === '-') {
            $negative = true;
            $amount = substr($amount, 1);
        }
        $parts = explode('.', $amount, 2);
        $yuan = preg_replace('/\D/', '', $parts[0]);
        $cent = isset($parts[1]) ? preg_replace('/\D/', '', $parts[1]) : '';
        $cent = substr(str_pad($cent, 2, '0'), 0, 2);
        $value = ((int)$yuan) * 100 + (int)$cent;
        return $negative ? -$value : $value;
    }

    protected function centsToDecimal($cents)
    {
        $negative = $cents < 0;
        $cents = abs((int)$cents);
        $yuan = intdiv($cents, 100);
        $cent = $cents % 100;
        return ($negative ? '-' : '') . $yuan . '.' . str_pad((string)$cent, 2, '0', STR_PAD_LEFT);
    }

    protected function requireAccountSet()
    {
        if (empty($this->accountSetId)) {
            return $this->error('账套不能为空');
        }
        return null;
    }

    protected function logAudit($bizType, $bizId, $operation, $beforeData, $afterData)
    {
        $data = [
            'log_id' => uuid(),
            'account_set_id' => $this->accountSetId,
            'biz_type' => $bizType,
            'biz_id' => $bizId,
            'operation' => $operation,
            'before_json' => $beforeData === null ? null : json_encode($beforeData, JSON_UNESCAPED_UNICODE),
            'after_json' => $afterData === null ? null : json_encode($afterData, JSON_UNESCAPED_UNICODE),
            'operator_id' => $this->userid,
            'operator_ip' => get_client_ip(),
            'created_time' => $this->now(),
        ];
        Db::name('sys_audit_log')->insert($data);
    }

    protected function currentUser()
    {
        if (empty($this->userid) || in_array($this->userid, ['system', 'anonymous'])) {
            return null;
        }
        try {
            return Db::name('sys_user')
                ->where(['user_id' => $this->userid, 'del_flag' => 0])
                ->find();
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function currentRoles()
    {
        if ($this->roleCache !== null) {
            return $this->roleCache;
        }
        if (empty($this->userid) || in_array($this->userid, ['system', 'anonymous'])) {
            $this->roleCache = [];
            return $this->roleCache;
        }
        try {
            $this->roleCache = Db::name('fin_user_role')->alias('ur')
                ->join('fin_role r', 'r.role_id=ur.role_id')
                ->where([
                    'ur.user_id' => $this->userid,
                    'r.status' => 1,
                ])
                ->field('r.role_id,r.role_code,r.role_name,r.view_scope,r.is_system,r.status')
                ->order('r.role_code asc')
                ->select();
        } catch (\Exception $e) {
            $this->roleCache = [];
        }
        return $this->roleCache;
    }

    protected function currentPermissions()
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }
        $roles = $this->currentRoles();
        if (empty($roles)) {
            $this->permissionCache = [];
            return $this->permissionCache;
        }
        $roleIds = [];
        foreach ($roles as $role) {
            $roleIds[] = $role['role_id'];
        }
        try {
            $codes = Db::name('fin_role_permission')
                ->where('role_id', 'in', array_values(array_unique($roleIds)))
                ->column('permission_code');
        } catch (\Exception $e) {
            $codes = [];
        }
        $this->permissionCache = array_values(array_unique($codes));
        return $this->permissionCache;
    }

    protected function hasPermission($code)
    {
        $permissions = $this->currentPermissions();
        return in_array('*', $permissions, true) || in_array($code, $permissions, true);
    }

    protected function requirePermission($code)
    {
        if ($this->hasPermission($code)) {
            return null;
        }
        return $this->error('无权限执行当前操作：' . $code);
    }

    protected function currentViewScope()
    {
        if ($this->viewScopeCache !== null) {
            return $this->viewScopeCache;
        }
        $scope = 'SELF';
        foreach ($this->currentRoles() as $role) {
            if (($role['view_scope'] ?? 'SELF') === 'ALL') {
                $scope = 'ALL';
                break;
            }
        }
        $this->viewScopeCache = $scope;
        return $scope;
    }

    protected function userPermissions($userId)
    {
        if (empty($userId)) {
            return [];
        }
        try {
            $codes = Db::name('fin_user_role')->alias('ur')
                ->join('fin_role r', 'r.role_id=ur.role_id and r.status=1')
                ->join('fin_role_permission rp', 'rp.role_id=r.role_id')
                ->where('ur.user_id', $userId)
                ->column('rp.permission_code');
        } catch (\Exception $e) {
            $codes = [];
        }
        return array_values(array_unique($codes));
    }

    protected function userRoles($userId)
    {
        if (empty($userId)) {
            return [];
        }
        try {
            return Db::name('fin_user_role')->alias('ur')
                ->join('fin_role r', 'r.role_id=ur.role_id')
                ->where('ur.user_id', $userId)
                ->field('r.role_id,r.role_code,r.role_name,r.view_scope,r.status')
                ->order('r.role_code asc')
                ->select();
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function userViewScope($userId)
    {
        $scope = 'SELF';
        foreach ($this->userRoles($userId) as $role) {
            if (($role['view_scope'] ?? 'SELF') === 'ALL') {
                $scope = 'ALL';
                break;
            }
        }
        return $scope;
    }

    protected function userAccountSets($userId)
    {
        if (empty($userId)) {
            return [];
        }
        try {
            return Db::name('fin_user_account_set')->alias('ua')
                ->join('fin_account_set a', 'a.account_set_id=ua.account_set_id')
                ->where([
                    'ua.user_id' => $userId,
                    'a.status' => 1,
                    'a.del_flag' => 0,
                ])
                ->field('a.account_set_id,a.set_code,a.set_name,a.biz_type,a.enabled_year,a.remark')
                ->order('a.biz_type asc,a.set_code asc')
                ->select();
        } catch (\Exception $e) {
            return [];
        }
    }
}
