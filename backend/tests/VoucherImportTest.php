<?php
/**
 * 凭证批量导入接口测试
 *
 * 运行：/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/VoucherImportTest.php
 *
 * 本测试每次创建独立随机测试账套及正式 OPEN 会计期间，只在该账套中创建固定、
 * 合法的测试科目和连续凭证号，并在 finally 中按主键逆序清理。严禁复用默认业务
 * 账套的科目、辅助类型或凭证号序列，避免测试与开发业务数据互相影响。
 */

function input($key = '', $default = null)
{
    return $default;
}

function config($key = '')
{
    if ($key === 'default_account_set_id') {
        return '';
    }
    if ($key === 'default_year') {
        return '2099';
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

function assertVoucherImportTest($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function insertTestAccountSet($db, $accountSetId, $setCode, $period, $marker, $actor)
{
    $now = date('Y-m-d H:i:s');
    $affected = $db->table('fin_account_set')->insert([
        'account_set_id' => $accountSetId,
        'set_code' => $setCode,
        'set_name' => '凭证导入隔离测试账套',
        'biz_type' => 'CASE_FUND',
        'enabled_year' => (int)substr($period, 0, 4),
        'enabled_period' => $period,
        'finance_manager' => '测试财务负责人',
        'bank_account_name' => '',
        'bank_account_no' => '',
        'bank_name' => '',
        'bank_branch_name' => '',
        'paper_size' => 'A5',
        'voucher_import_auto_no' => 1,
        'voucher_print_line_count' => 8,
        'generate_voucher_by_day_flag' => 1,
        'generate_payment_voucher_by_method_flag' => 0,
        'generate_refund_voucher_by_out_order_no_flag' => 0,
        'subject_code_rule' => '4-2-2-2',
        'status' => 1,
        'created_by' => $actor,
        'created_time' => $now,
        'updated_by' => $actor,
        'updated_time' => $now,
        'del_flag' => 0,
        'version' => 0,
        'remark' => $marker,
    ]);
    assertVoucherImportTest((int)$affected === 1, '创建凭证导入隔离测试账套失败');
}

function insertTestFiscalPeriod($db, $accountSetId, $periodId, $period, $marker, $actor)
{
    $year = (int)substr($period, 0, 4);
    $month = (int)substr($period, 5, 2);
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate = date('Y-m-t', strtotime($startDate));
    $now = date('Y-m-d H:i:s');
    $affected = $db->table('fin_fiscal_period')->insert([
        'period_id' => $periodId,
        'account_set_id' => $accountSetId,
        'period' => $period,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'status' => 'OPEN',
        'created_by' => $actor,
        'created_time' => $now,
        'updated_by' => $actor,
        'updated_time' => $now,
        'del_flag' => 0,
        'version' => 0,
        'remark' => $marker,
    ]);
    assertVoucherImportTest((int)$affected === 1, '创建凭证导入测试会计期间失败');
}

function insertTestSubject($db, $accountSetId, $subjectId, $subjectCode, $subjectName, $direction, $subjectType, $marker, $actor)
{
    $now = date('Y-m-d H:i:s');
    $affected = $db->table('fin_subject')->insert([
        'subject_id' => $subjectId,
        'account_set_id' => $accountSetId,
        'subject_code' => $subjectCode,
        'subject_name' => $subjectName,
        'parent_code' => null,
        'direction' => $direction,
        'subject_type' => $subjectType,
        'level_no' => 1,
        'leaf_flag' => 1,
        // 核心回归条件：IMPORT 来源允许使用 voucher_entry_flag=0 的末级科目。
        'voucher_entry_flag' => 0,
        'status' => 1,
        'created_by' => $actor,
        'created_time' => $now,
        'updated_by' => $actor,
        'updated_time' => $now,
        'del_flag' => 0,
        'version' => 0,
        'remark' => $marker,
    ]);
    assertVoucherImportTest((int)$affected === 1, '创建凭证导入专用测试科目失败：' . $subjectCode);
}

function insertTestSubjectAuxConfig($db, $accountSetId, $configId, $subjectCode, $auxTypeCode, $marker, $actor)
{
    $now = date('Y-m-d H:i:s');
    $affected = $db->table('fin_subject_aux_config')->insert([
        'config_id' => $configId,
        'account_set_id' => $accountSetId,
        'subject_code' => $subjectCode,
        'aux_type_code' => $auxTypeCode,
        'required_flag' => 1,
        'verification_flag' => 1,
        'created_by' => $actor,
        'created_time' => $now,
        'updated_by' => $actor,
        'updated_time' => $now,
        'del_flag' => 0,
        'version' => 0,
        'remark' => $marker,
    ]);
    assertVoucherImportTest((int)$affected === 1, '创建凭证导入专用辅助配置失败：' . $auxTypeCode);
}

function insertSoftDeletedVoucherNoConflict($db, $accountSetId, $voucherId, $fiscalYear, $period, $voucherDate, $voucherNo, $marker, $actor)
{
    $now = date('Y-m-d H:i:s');
    $affected = $db->table('fin_voucher')->insert([
        'voucher_id' => $voucherId,
        'account_set_id' => $accountSetId,
        'fiscal_year' => $fiscalYear,
        'period' => $period,
        'voucher_date' => $voucherDate,
        'voucher_word' => '记',
        'voucher_no' => $voucherNo,
        'summary' => '已删除凭证号占用测试',
        'debit_amount' => '100.00',
        'credit_amount' => '100.00',
        'attachment_count' => 0,
        'status' => 'SUBMITTED',
        'source_type' => 'IMPORT',
        'printed_flag' => '0',
        'prepared_by' => $actor,
        'prepared_time' => $now,
        'audit_by' => null,
        'audit_time' => null,
        'posted_by' => null,
        'posted_time' => null,
        'void_flag' => '0',
        'created_by' => $actor,
        'created_time' => $now,
        'updated_by' => $actor,
        'updated_time' => $now,
        'del_flag' => 1,
        'version' => 0,
        'remark' => $marker,
    ]);
    assertVoucherImportTest((int)$affected === 1, '创建软删除凭证号冲突测试数据失败');
}

function countAuxArchive($db, $accountSetId, $typeCode, $archiveCode)
{
    return (int)$db->table('fin_aux_archive')
        ->where('account_set_id', $accountSetId)
        ->where('aux_type_code', $typeCode)
        ->where('archive_code', $archiveCode)
        ->where('del_flag', 0)
        ->count();
}

function cleanupTestAccountRowsByIds($db, $table, $idField, $accountSetId, $knownIds = [])
{
    $discoveredIds = $db->table($table)
        ->where('account_set_id', $accountSetId)
        ->column($idField);
    $ids = array_values(array_unique(array_filter(array_merge($knownIds, $discoveredIds), function ($id) {
        return trim((string)$id) !== '';
    })));
    if (empty($ids)) {
        return 0;
    }
    return $db->table($table)
        ->where('account_set_id', $accountSetId)
        ->where($idField, 'in', $ids)
        ->delete();
}

function testAccountResidueCounts($db, $accountSetId)
{
    $tables = [
        'sys_audit_log',
        'fin_voucher_aux_value',
        'fin_voucher_detail',
        'fin_voucher',
        'fin_voucher_no_sequence',
        'fin_aux_archive',
        'fin_subject_aux_config',
        'fin_subject',
        'fin_aux_type',
        'fin_fiscal_period',
        'fin_account_set',
    ];
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = (int)$db->table($table)
            ->where('account_set_id', $accountSetId)
            ->count();
    }
    return $counts;
}

$dbConfig = require __DIR__ . '/../app/database.php';
$dbConfig['hostname'] = $dbConfig['hostname'] === 'localhost' ? '127.0.0.1' : $dbConfig['hostname'];
\think\Config::set('database', $dbConfig);
$db = Db::connect($dbConfig);

$runToken = strtoupper(substr(str_replace('-', '', uuid()), 0, 16));
$marker = 'VOUCHER_IMPORT_TEST_' . $runToken;
$actor = 'VIT_' . $runToken;
$accountSetId = uuid();
$accountSetCode = 'VIT_' . $runToken;
$periodId = uuid();
$fiscalYear = 2099;
$period = '2099-12';
$voucherDate = '2099-12-15';
$testCaseNo = 'IMPORT_CASE_' . $runToken;
$testReceiptNo = 'IMPORT_RECEIPT_' . $runToken;
$subjectCodes = ['1001', '2001', '2002'];
$debitSubjectCode = $subjectCodes[0];
$creditSubjectCode = $subjectCodes[1];
$auxCreditSubjectCode = $subjectCodes[2];
$subjectIds = [uuid(), uuid(), uuid()];
$configIds = [uuid(), uuid()];
$voucherNumbers = [1, 2, 3, 4];
$softDeletedConflictVoucherId = uuid();

$testFailure = null;
$cleanupFailures = [];
$result = null;
$accountSetCreated = false;

try {
    insertTestAccountSet($db, $accountSetId, $accountSetCode, $period, $marker, $actor);
    $accountSetCreated = true;
    insertTestFiscalPeriod($db, $accountSetId, $periodId, $period, $marker, $actor);

    $periodFixture = $db->table('fin_fiscal_period')
        ->where('period_id', $periodId)
        ->where('account_set_id', $accountSetId)
        ->find();
    assertVoucherImportTest(
        $periodFixture && $periodFixture['period'] === $period && $periodFixture['status'] === 'OPEN',
        '凭证导入测试必须使用正式 OPEN 会计期间'
    );

    assertVoucherImportTest(countAuxArchive($db, $accountSetId, 'case_no', $testCaseNo) === 0, '隔离测试账套不应预置测试案号');
    assertVoucherImportTest(countAuxArchive($db, $accountSetId, 'receipt_no', $testReceiptNo) === 0, '隔离测试账套不应预置测试收据号');

    insertTestSubject($db, $accountSetId, $subjectIds[0], $debitSubjectCode, '凭证导入测试借方科目', 'DEBIT', 'ASSET', $marker, $actor);
    insertTestSubject($db, $accountSetId, $subjectIds[1], $creditSubjectCode, '凭证导入测试贷方科目', 'CREDIT', 'LIABILITY', $marker, $actor);
    insertTestSubject($db, $accountSetId, $subjectIds[2], $auxCreditSubjectCode, '凭证导入测试辅助贷方科目', 'CREDIT', 'LIABILITY', $marker, $actor);
    insertTestSubjectAuxConfig($db, $accountSetId, $configIds[0], $auxCreditSubjectCode, 'case_no', $marker, $actor);
    insertTestSubjectAuxConfig($db, $accountSetId, $configIds[1], $auxCreditSubjectCode, 'receipt_no', $marker, $actor);

    $initialAuxTypeCount = (int)$db->table('fin_aux_type')
        ->where('account_set_id', $accountSetId)
        ->where('aux_type_code', 'in', ['case_no', 'receipt_no'])
        ->count();
    assertVoucherImportTest($initialAuxTypeCount === 0, '隔离测试账套不应预置案号或收据号辅助类型');

    insertSoftDeletedVoucherNoConflict(
        $db,
        $accountSetId,
        $softDeletedConflictVoucherId,
        $fiscalYear,
        $period,
        '2099-12-28',
        $voucherNumbers[3],
        $marker,
        $actor
    );

    // 用户姓名映射不属于本次核心回归；留空专门验证导入时回退到当前测试用户。
    $vouchers = [
        [
            'period' => $period,
            'voucher_date' => $voucherDate,
            'voucher_word' => '记',
            'voucher_no' => $voucherNumbers[0],
            'summary' => '导入测试凭证1',
            'attachment_count' => 2,
            'prepared_by_name' => '',
            'audit_by_name' => '',
            'remark' => $marker,
            'details' => [
                [
                    'subject_code' => $debitSubjectCode,
                    'summary' => '收到执行款',
                    'debit_amount' => '500.00',
                    'credit_amount' => '0',
                    'aux_values' => [],
                ],
                [
                    'subject_code' => $creditSubjectCode,
                    'summary' => '开户费收入',
                    'debit_amount' => '0',
                    'credit_amount' => '500.00',
                    'aux_values' => [],
                ],
            ],
        ],
        [
            'period' => $period,
            'voucher_date' => '2099-12-20',
            'voucher_word' => '记',
            'voucher_no' => $voucherNumbers[1],
            'summary' => '导入测试凭证2',
            'attachment_count' => 1,
            'prepared_by_name' => '',
            'audit_by_name' => '',
            'remark' => $marker,
            'details' => [
                [
                    'subject_code' => $debitSubjectCode,
                    'summary' => '利息收入',
                    'debit_amount' => '200.00',
                    'credit_amount' => '0',
                    'aux_values' => [],
                ],
                [
                    'subject_code' => $creditSubjectCode,
                    'summary' => '账户管理费',
                    'debit_amount' => '0',
                    'credit_amount' => '200.00',
                    'aux_values' => [],
                ],
            ],
        ],
        [
            'period' => $period,
            'voucher_date' => '2099-12-25',
            'voucher_word' => '记',
            'voucher_no' => $voucherNumbers[2],
            'summary' => '导入测试凭证3',
            'attachment_count' => 1,
            'prepared_by_name' => '',
            'audit_by_name' => '',
            'remark' => $marker,
            'details' => [
                [
                    'subject_code' => $debitSubjectCode,
                    'summary' => '收到暂存款',
                    'debit_amount' => '300.00',
                    'credit_amount' => '0',
                    'aux_values' => [],
                ],
                [
                    'subject_code' => $auxCreditSubjectCode,
                    'summary' => '暂存款挂案号收据号',
                    'debit_amount' => '0',
                    'credit_amount' => '300.00',
                    'aux_values' => [
                        ['aux_type_code' => 'case_no', 'aux_value' => $testCaseNo, 'aux_label' => $testCaseNo],
                        ['aux_type_code' => 'receipt_no', 'aux_value' => $testReceiptNo, 'aux_label' => $testReceiptNo],
                    ],
                ],
            ],
        ],
        [
            'period' => $period,
            'voucher_date' => '2099-12-28',
            'voucher_word' => '记',
            'voucher_no' => $voucherNumbers[3],
            'summary' => '',
            'attachment_count' => 1,
            'prepared_by_name' => '',
            'audit_by_name' => '',
            'remark' => $marker,
            'details' => [
                [
                    'subject_code' => $debitSubjectCode,
                    'summary' => '导入凭证头摘要回填测试',
                    'debit_amount' => '100.00',
                    'credit_amount' => '0',
                    'aux_values' => [],
                ],
                [
                    'subject_code' => $creditSubjectCode,
                    'summary' => '导入凭证头摘要回填测试',
                    'debit_amount' => '0',
                    'credit_amount' => '100.00',
                    'aux_values' => [],
                ],
            ],
        ],
    ];

    $model = new Voucher();
    $accountSetProperty = new ReflectionProperty(Voucher::class, 'accountSetId');
    $accountSetProperty->setAccessible(true);
    $accountSetProperty->setValue($model, $accountSetId);
    $userProperty = new ReflectionProperty(Voucher::class, 'userid');
    $userProperty->setAccessible(true);
    $userProperty->setValue($model, $actor);
    $permissionProperty = new ReflectionProperty(Voucher::class, 'permissionCache');
    $permissionProperty->setAccessible(true);
    $permissionProperty->setValue($model, ['*']);

    $result = $model->importVouchers(['vouchers' => $vouchers]);

    echo "=== 导入结果 ===\n";
    echo 'Code: ' . ($result['code'] ?? '') . "\n";
    echo 'Message: ' . ($result['message'] ?? '') . "\n";
    echo 'Success: ' . ($result['data']['success'] ?? 0) . "\n";
    echo 'Failed: ' . ($result['data']['failed'] ?? 0) . "\n";
    if (!empty($result['data']['errors'])) {
        echo "Errors:\n";
        foreach ($result['data']['errors'] as $error) {
            echo '  - ' . $error . "\n";
        }
    }

    assertVoucherImportTest(
        ($result['code'] ?? 0) === 20000
            && (int)($result['data']['success'] ?? 0) === count($vouchers)
            && (int)($result['data']['failed'] ?? 0) === 0,
        '凭证导入不应受 fin_subject.voucher_entry_flag=0 影响'
    );

    $fixtureSubjects = $db->table('fin_subject')
        ->where('subject_id', 'in', $subjectIds)
        ->field('subject_code,leaf_flag,voucher_entry_flag,status')
        ->select();
    assertVoucherImportTest(count($fixtureSubjects) === 3, '凭证导入测试科目不完整');
    foreach ($fixtureSubjects as $subject) {
        assertVoucherImportTest(
            (int)$subject['leaf_flag'] === 1
                && (int)$subject['voucher_entry_flag'] === 0
                && (int)$subject['status'] === 1,
            '测试科目必须保持为启用的末级科目且 voucher_entry_flag=0'
        );
    }

    assertVoucherImportTest(
        countAuxArchive($db, $accountSetId, 'case_no', $testCaseNo) === 1
            && countAuxArchive($db, $accountSetId, 'receipt_no', $testReceiptNo) === 1,
        '凭证导入应自动补齐案号和收据号辅助核算档案'
    );
    $createdAuxTypes = $db->table('fin_aux_type')
        ->where('account_set_id', $accountSetId)
        ->where('aux_type_code', 'in', ['case_no', 'receipt_no'])
        ->where('del_flag', 0)
        ->field('aux_type_code,created_by,remark')
        ->select();
    assertVoucherImportTest(count($createdAuxTypes) === 2, '凭证导入应在隔离账套自动创建案号和收据号辅助类型');
    foreach ($createdAuxTypes as $createdAuxType) {
        assertVoucherImportTest(
            $createdAuxType['created_by'] === $actor && $createdAuxType['remark'] === '凭证导入自动建档',
            '自动创建的辅助类型必须属于本次隔离测试'
        );
    }

    $summaryVoucher = $db->table('fin_voucher')
        ->where('account_set_id', $accountSetId)
        ->where('fiscal_year', $fiscalYear)
        ->where('period', $period)
        ->where('voucher_no', $voucherNumbers[3])
        ->where('source_type', 'IMPORT')
        ->where('remark', $marker)
        ->find();
    assertVoucherImportTest(
        $summaryVoucher && $summaryVoucher['summary'] === '导入凭证头摘要回填测试',
        '导入凭证应将明细摘要保存到 fin_voucher.summary'
    );

    $softDeletedConflict = $db->table('fin_voucher')
        ->where('voucher_id', $softDeletedConflictVoucherId)
        ->find();
    assertVoucherImportTest(
        $softDeletedConflict
            && (int)$softDeletedConflict['del_flag'] === 1
            && (int)$softDeletedConflict['voucher_no'] !== $voucherNumbers[3]
            && (int)$softDeletedConflict['voucher_no'] < 0,
        '导入应先避让同期间同号的软删除凭证，释放原凭证号'
    );

    $imported = $db->table('fin_voucher')
        ->where('account_set_id', $accountSetId)
        ->where('fiscal_year', $fiscalYear)
        ->where('period', $period)
        ->where('voucher_no', 'in', $voucherNumbers)
        ->where('source_type', 'IMPORT')
        ->where('status', 'AUDITED')
        ->where('remark', $marker)
        ->select();
    assertVoucherImportTest(count($imported) === count($vouchers), '数据库中的已审核导入凭证数量不正确');
    foreach ($imported as $importedVoucher) {
        assertVoucherImportTest(
            $importedVoucher['prepared_by'] === $actor && $importedVoucher['audit_by'] === $actor,
            '导入人员姓名留空时应回退到当前测试用户'
        );
    }

    $sequence = $db->table('fin_voucher_no_sequence')
        ->where('account_set_id', $accountSetId)
        ->where('fiscal_year', $fiscalYear)
        ->where('period', $period)
        ->where('del_flag', 0)
        ->find();
    assertVoucherImportTest(
        $sequence && (int)$sequence['current_no'] === $voucherNumbers[3],
        '隔离测试账套凭证号序列应更新到连续最大号4'
    );

    $auxVoucher = $db->table('fin_voucher')
        ->where('account_set_id', $accountSetId)
        ->where('fiscal_year', $fiscalYear)
        ->where('period', $period)
        ->where('voucher_no', $voucherNumbers[2])
        ->where('remark', $marker)
        ->find();
    $auxValueCount = $auxVoucher
        ? (int)$db->table('fin_voucher_aux_value')
            ->where('voucher_id', $auxVoucher['voucher_id'])
            ->where('aux_type_code', 'in', ['case_no', 'receipt_no'])
            ->where('del_flag', 0)
            ->count()
        : 0;
    assertVoucherImportTest($auxValueCount === 2, '导入凭证应保存案号和收据号辅助核算值');

    echo "\n=== 数据库验证 ===\n";
    foreach ($imported as $voucher) {
        echo '凭证 ' . $voucher['voucher_word'] . '-' . $voucher['voucher_no']
            . ' (' . $voucher['period'] . '): ' . $voucher['summary'] . "\n";
    }
} catch (Throwable $exception) {
    $testFailure = $exception;
} finally {
    // saveVoucher 对 Exception 会自行回滚；此处额外回滚可清除 Error 中断留下的事务层级。
    try {
        Db::rollback();
    } catch (Throwable $exception) {
        $cleanupFailures[] = '回滚未完成的测试事务失败：' . $exception->getMessage();
    }

    $ownedAccountSet = $accountSetCreated;
    if (!$ownedAccountSet) {
        try {
            $ownedAccountSet = (int)$db->table('fin_account_set')
                ->where('account_set_id', $accountSetId)
                ->where('set_code', $accountSetCode)
                ->where('created_by', $actor)
                ->where('remark', $marker)
                ->count() === 1;
        } catch (Throwable $exception) {
            $cleanupFailures[] = '确认隔离测试账套归属失败：' . $exception->getMessage();
        }
    }

    if ($ownedAccountSet) {
        // 每类资源单独尝试清理；任一步失败不影响后续子表或主表的清理尝试。
        $cleanupSteps = [
            ['sys_audit_log', 'log_id', [], '审计日志'],
            ['fin_voucher_aux_value', 'id', [], '凭证辅助核算值'],
            ['fin_voucher_detail', 'detail_id', [], '凭证分录'],
            ['fin_voucher', 'voucher_id', [$softDeletedConflictVoucherId], '凭证'],
            ['fin_voucher_no_sequence', 'sequence_id', [], '凭证号序列'],
            ['fin_aux_archive', 'archive_id', [], '辅助档案'],
            ['fin_subject_aux_config', 'config_id', $configIds, '科目辅助配置'],
            ['fin_subject', 'subject_id', $subjectIds, '测试科目'],
            ['fin_aux_type', 'aux_type_id', [], '辅助类型'],
            ['fin_fiscal_period', 'period_id', [$periodId], '会计期间'],
        ];
        foreach ($cleanupSteps as $cleanupStep) {
            try {
                cleanupTestAccountRowsByIds(
                    $db,
                    $cleanupStep[0],
                    $cleanupStep[1],
                    $accountSetId,
                    $cleanupStep[2]
                );
            } catch (Throwable $exception) {
                $cleanupFailures[] = '清理' . $cleanupStep[3] . '失败：' . $exception->getMessage();
            }
        }
        try {
            $db->table('fin_account_set')
                ->where('account_set_id', $accountSetId)
                ->where('set_code', $accountSetCode)
                ->where('created_by', $actor)
                ->where('remark', $marker)
                ->delete();
        } catch (Throwable $exception) {
            $cleanupFailures[] = '清理隔离测试账套失败：' . $exception->getMessage();
        }
    }

    try {
        $residueCounts = testAccountResidueCounts($db, $accountSetId);
        $nonZeroResidue = array_filter($residueCounts, function ($count) {
            return (int)$count !== 0;
        });
        if (!empty($nonZeroResidue)) {
            $cleanupFailures[] = '隔离测试账套仍有数据残留：'
                . json_encode($nonZeroResidue, JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $exception) {
        $cleanupFailures[] = '检查隔离测试数据残留失败：' . $exception->getMessage();
    }
}

if ($testFailure) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
}
if (!empty($cleanupFailures)) {
    foreach ($cleanupFailures as $cleanupFailure) {
        fwrite(STDERR, $cleanupFailure . PHP_EOL);
    }
}
if ($testFailure || !empty($cleanupFailures)) {
    exit(1);
}

echo "\nVoucherImportTest passed，已清理全部测试数据。\n";
