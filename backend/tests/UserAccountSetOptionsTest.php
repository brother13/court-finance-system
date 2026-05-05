<?php

function input($key = '', $default = null)
{
    return $default;
}

function config($key = '')
{
    if ($key === 'default_account_set_id') {
        return '00000000-0000-0000-0000-000000000101';
    }
    if ($key === 'default_year') {
        return '2026';
    }
    return null;
}

function getNowTime()
{
    return date('Y-m-d H:i:s');
}

function uuid()
{
    return '00000000-0000-4000-8000-000000000000';
}

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/User.php';

use app\finance\model\User;

$user = new User();
$method = new ReflectionMethod(User::class, 'accountSetOptionWhere');
$method->setAccessible(true);

$where = $method->invoke($user);

if (($where['status'] ?? null) !== 1) {
    fwrite(STDERR, 'Expected assignable account set options to include only enabled account sets' . PHP_EOL);
    exit(1);
}

if (($where['del_flag'] ?? null) !== 0) {
    fwrite(STDERR, 'Expected assignable account set options to exclude deleted account sets' . PHP_EOL);
    exit(1);
}

if (isset($where['user_id']) || isset($where['current_user_id'])) {
    fwrite(STDERR, 'Assignable account set options must not be filtered by current user assignments' . PHP_EOL);
    exit(1);
}

echo 'User account set options test passed' . PHP_EOL;
