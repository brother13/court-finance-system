<?php
/**
 * 凭证批量导入接口测试
 *
 * 运行：/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/VoucherImportTest.php
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
require_once __DIR__ . '/../app/finance/model/Voucher.php';

use app\finance\model\Voucher;
use think\Db;

$dbConfig = require __DIR__ . '/../app/database.php';
$dbConfig['hostname'] = $dbConfig['hostname'] === 'localhost' ? '127.0.0.1' : $dbConfig['hostname'];
\think\Config::set('database', $dbConfig);
$db = Db::connect($dbConfig);
$accountSetId = '00000000-0000-0000-0000-000000000101';
$testSubjectCodes = ['100201', '100202', '100203', '100204'];

// 清理测试数据
function cleanupTestVouchers($db, $accountSetId)
{
    $voucherIds = $db->table('fin_voucher')
        ->where('account_set_id', $accountSetId)
        ->where('fiscal_year', 2025)
        ->where('period', '2025-01')
        ->where('voucher_no', 'in', [990, 991, 992])
        ->column('voucher_id');
    foreach ($voucherIds as $vid) {
        $db->execute("DELETE FROM fin_voucher_aux_value WHERE voucher_id = '{$vid}'");
        $db->execute("DELETE FROM fin_voucher_detail WHERE voucher_id = '{$vid}'");
        $db->execute("DELETE FROM fin_voucher WHERE voucher_id = '{$vid}'");
    }
}

function captureSubjectEntryFlags($db, $accountSetId, $subjectCodes)
{
    $rows = $db->table('fin_subject')
        ->where('account_set_id', $accountSetId)
        ->where('subject_code', 'in', $subjectCodes)
        ->field('subject_code,voucher_entry_flag')
        ->select();
    $flags = [];
    foreach ($rows as $row) {
        $flags[$row['subject_code']] = (int)$row['voucher_entry_flag'];
    }
    return $flags;
}

function setSubjectEntryFlags($db, $accountSetId, $subjectCodes, $flag)
{
    $db->table('fin_subject')
        ->where('account_set_id', $accountSetId)
        ->where('subject_code', 'in', $subjectCodes)
        ->update(['voucher_entry_flag' => (int)$flag]);
}

function restoreSubjectEntryFlags($db, $accountSetId, $flags)
{
    foreach ($flags as $subjectCode => $flag) {
        $db->table('fin_subject')
            ->where('account_set_id', $accountSetId)
            ->where('subject_code', $subjectCode)
            ->update(['voucher_entry_flag' => (int)$flag]);
    }
}

cleanupTestVouchers($db, $accountSetId);
$originalSubjectEntryFlags = captureSubjectEntryFlags($db, $accountSetId, $testSubjectCodes);
setSubjectEntryFlags($db, $accountSetId, $testSubjectCodes, 0);

// 构造测试数据
$vouchers = [
    [
        'period' => '2025-01',
        'voucher_date' => '2025-01-15',
        'voucher_word' => '记',
        'voucher_no' => 990,
        'summary' => '导入测试凭证1',
        'attachment_count' => 2,
        'prepared_by_name' => 'admin',
        'audit_by_name' => 'admin',
        'details' => [
            [
                'subject_code' => '100201',
                'summary' => '收到执行款',
                'debit_amount' => '500.00',
                'credit_amount' => '0',
                'aux_values' => [],
            ],
            [
                'subject_code' => '100202',
                'summary' => '开户费收入',
                'debit_amount' => '0',
                'credit_amount' => '500.00',
                'aux_values' => [],
            ],
        ],
    ],
    [
        'period' => '2025-01',
        'voucher_date' => '2025-01-20',
        'voucher_word' => '记',
        'voucher_no' => 991,
        'summary' => '导入测试凭证2',
        'attachment_count' => 1,
        'prepared_by_name' => 'admin',
        'audit_by_name' => 'admin',
        'details' => [
            [
                'subject_code' => '100203',
                'summary' => '利息收入',
                'debit_amount' => '200.00',
                'credit_amount' => '0',
                'aux_values' => [],
            ],
            [
                'subject_code' => '100204',
                'summary' => '账户管理费',
                'debit_amount' => '0',
                'credit_amount' => '200.00',
                'aux_values' => [],
            ],
        ],
    ],
];

// 执行导入
$model = new Voucher();
$accountSetProperty = new ReflectionProperty(Voucher::class, 'accountSetId');
$accountSetProperty->setAccessible(true);
$accountSetProperty->setValue($model, $accountSetId);
$userProperty = new ReflectionProperty(Voucher::class, 'userid');
$userProperty->setAccessible(true);
$userProperty->setValue($model, '00000000-0000-0000-0000-000000000301');
$permissionProperty = new ReflectionProperty(Voucher::class, 'permissionCache');
$permissionProperty->setAccessible(true);
$permissionProperty->setValue($model, ['*']);

$result = $model->importVouchers(['vouchers' => $vouchers]);
restoreSubjectEntryFlags($db, $accountSetId, $originalSubjectEntryFlags);

echo "=== 导入结果 ===\n";
echo "Code: {$result['code']}\n";
echo "Message: {$result['message']}\n";
echo "Success: {$result['data']['success']}\n";
echo "Failed: {$result['data']['failed']}\n";

if (!empty($result['data']['errors'])) {
    echo "Errors:\n";
    foreach ($result['data']['errors'] as $err) {
        echo "  - $err\n";
    }
}

if ($result['code'] !== 20000 || (int)$result['data']['success'] !== count($vouchers) || (int)$result['data']['failed'] !== 0) {
    cleanupTestVouchers($db, $accountSetId);
    fwrite(STDERR, '凭证导入不应再受 fin_subject.voucher_entry_flag=0 影响' . PHP_EOL);
    exit(1);
}

// 验证导入结果
if ($result['code'] === 20000 && $result['data']['success'] > 0) {
    echo "\n=== 数据库验证 ===\n";
    $imported = $db->table('fin_voucher')
        ->where('account_set_id', $accountSetId)
        ->where('fiscal_year', 2025)
        ->where('period', '2025-01')
        ->where('voucher_no', 'in', [990, 991])
        ->where('source_type', 'IMPORT')
        ->where('status', 'AUDITED')
        ->select();

    foreach ($imported as $v) {
        $details = $db->table('fin_voucher_detail')
            ->where('voucher_id', $v['voucher_id'])
            ->where('fiscal_year', 2025)
            ->where('period', '2025-01')
            ->order('line_no')
            ->select();
        echo "凭证 {$v['voucher_word']}-{$v['voucher_no']} ({$v['period']}): {$v['summary']}\n";
        echo "  状态: {$v['status']}, 来源: {$v['source_type']}\n";
        foreach ($details as $d) {
            echo "  分录{$d['line_no']}: {$d['subject_code']} {$d['summary']} 借{$d['debit_amount']} 贷{$d['credit_amount']}\n";
        }
    }
}

// 清理
cleanupTestVouchers($db, $accountSetId);
echo "\n测试完成，已清理测试数据。\n";
