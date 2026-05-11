<?php
/**
 * 审计日志大 payload 测试
 *
 * 运行：/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/AuditLogLargePayloadTest.php
 */

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
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function get_client_ip()
{
    return '127.0.0.1';
}

defined('APP_PATH') or define('APP_PATH', realpath(__DIR__ . '/../app') . DIRECTORY_SEPARATOR);
defined('ROOT_PATH') or define('ROOT_PATH', realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR);
require_once __DIR__ . '/../thinkphp/base.php';
require_once __DIR__ . '/../thinkphp/helper.php';
require_once __DIR__ . '/../app/finance/model/Common.php';

use app\finance\model\Common;
use think\Db;

class AuditLogLargePayloadProbe extends Common
{
    public function writeLargePayload($bizId, $payload)
    {
        $this->logAudit('AUDIT_LOG_TEST', $bizId, 'CREATE', null, $payload);
    }
}

$dbConfig = require __DIR__ . '/../app/database.php';
$dbConfig['hostname'] = $dbConfig['hostname'] === 'localhost' ? '127.0.0.1' : $dbConfig['hostname'];
\think\Config::set('database', $dbConfig);
$db = Db::connect($dbConfig);

$bizId = 'large-payload-test-' . date('YmdHis');
$accountSetId = '00000000-0000-0000-0000-000000000101';
$payload = [
    'summary' => '导入凭证审计日志大 payload 测试',
    'details' => [],
];

for ($i = 1; $i <= 900; $i++) {
    $payload['details'][] = [
        'line_no' => $i,
        'subject_code' => $i % 2 === 0 ? '100201' : '200301',
        'summary' => str_repeat('诉讼费导入明细', 8),
        'debit_amount' => $i % 2 === 0 ? '100.00' : '0.00',
        'credit_amount' => $i % 2 === 0 ? '0.00' : '100.00',
    ];
}

$model = new AuditLogLargePayloadProbe();
$accountSetProperty = new ReflectionProperty(Common::class, 'accountSetId');
$accountSetProperty->setAccessible(true);
$accountSetProperty->setValue($model, $accountSetId);
$userProperty = new ReflectionProperty(Common::class, 'userid');
$userProperty->setAccessible(true);
$userProperty->setValue($model, '00000000-0000-0000-0000-000000000301');

try {
    $model->writeLargePayload($bizId, $payload);
    $row = $db->table('sys_audit_log')
        ->where('biz_type', 'AUDIT_LOG_TEST')
        ->where('biz_id', $bizId)
        ->find();
    $db->table('sys_audit_log')
        ->where('biz_type', 'AUDIT_LOG_TEST')
        ->where('biz_id', $bizId)
        ->delete();
} catch (\Exception $e) {
    $db->table('sys_audit_log')
        ->where('biz_type', 'AUDIT_LOG_TEST')
        ->where('biz_id', $bizId)
        ->delete();
    fwrite(STDERR, '审计日志应支持超过 64KB 的凭证导入 payload：' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!$row || strlen($row['after_json']) < 65536) {
    fwrite(STDERR, '审计日志大 payload 未完整写入' . PHP_EOL);
    exit(1);
}

echo 'Audit log large payload test passed' . PHP_EOL;
