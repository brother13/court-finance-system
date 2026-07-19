<?php
/**
 * 检查并修复辅助期初引用的辅助档案。
 *
 * 默认只检查：
 *   php backend/database/2026-07-17-repair-aux-opening-archives.php
 * 确认清单后执行修复：
 *   php backend/database/2026-07-17-repair-aux-opening-archives.php --apply
 *
 * 仅自动补齐 value_source=MANUAL 的档案；ARCHIVE 类型只输出异常，
 * 必须由用户确认正确的档案编码和名称后在基础资料中维护。
 */

$apply = in_array('--apply', $argv, true);
$config = require __DIR__ . '/../app/database.php';
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $config['hostname'] === 'localhost' ? '127.0.0.1' : $config['hostname'],
    $config['hostport'],
    $config['database'],
    $config['charset']
);
$pdo = new PDO($dsn, $config['username'], $config['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function repairAuxUuid()
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function repairAuxAudit(PDO $pdo, array $row, $operation, $before)
{
    $stmt = $pdo->prepare(
        'insert into sys_audit_log '
        . '(log_id,account_set_id,biz_type,biz_id,operation,before_json,after_json,operator_id,operator_ip,created_time) '
        . 'values (?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        repairAuxUuid(),
        $row['account_set_id'],
        'AUX_ARCHIVE',
        $row['archive_id'],
        $operation,
        $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        json_encode($row, JSON_UNESCAPED_UNICODE),
        'system',
        '127.0.0.1',
        date('Y-m-d H:i:s'),
    ]);
}

$openingRows = $pdo->query(
    'select balance_id,account_set_id,period,subject_code,aux_values_json '
    . 'from fin_aux_opening_balance where del_flag=0 order by account_set_id,period,subject_code,balance_id'
)->fetchAll();
$typeStmt = $pdo->prepare(
    'select aux_type_code,aux_type_name,value_source,status,del_flag from fin_aux_type '
    . 'where account_set_id=? and aux_type_code=? order by del_flag asc limit 1'
);
$archiveStmt = $pdo->prepare(
    'select * from fin_aux_archive where account_set_id=? and aux_type_code=? and archive_code=? '
    . 'order by del_flag asc,updated_time desc limit 1'
);
$insertStmt = $pdo->prepare(
    'insert into fin_aux_archive '
    . '(archive_id,account_set_id,aux_type_code,archive_code,archive_name,status,created_by,created_time,updated_by,updated_time,del_flag,version,remark) '
    . 'values (?,?,?,?,?,?,?,?,?,?,?,?,?)'
);
$restoreStmt = $pdo->prepare(
    'update fin_aux_archive set archive_name=?,status=1,updated_by=?,updated_time=?,del_flag=0,remark=? where archive_id=?'
);

$seen = [];
$manualMissing = [];
$archiveMissing = [];
$invalid = [];
foreach ($openingRows as $opening) {
    $values = json_decode($opening['aux_values_json'] ?? '', true);
    if (!is_array($values)) {
        $invalid[] = $opening + ['reason' => 'aux_values_json不是有效对象'];
        continue;
    }
    foreach ($values as $typeCode => $archiveCode) {
        $typeCode = trim((string)$typeCode);
        $archiveCode = trim((string)$archiveCode);
        if ($typeCode === '' || $archiveCode === '') {
            continue;
        }
        $key = $opening['account_set_id'] . '|' . $typeCode . '|' . $archiveCode;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $typeStmt->execute([$opening['account_set_id'], $typeCode]);
        $type = $typeStmt->fetch();
        if (!$type || (int)$type['del_flag'] !== 0 || (int)$type['status'] !== 1) {
            $invalid[] = $opening + ['aux_type_code' => $typeCode, 'archive_code' => $archiveCode, 'reason' => '辅助维度不存在或已停用'];
            continue;
        }
        $archiveStmt->execute([$opening['account_set_id'], $typeCode, $archiveCode]);
        $archive = $archiveStmt->fetch();
        if ($archive && (int)$archive['del_flag'] === 0 && (int)$archive['status'] === 1) {
            continue;
        }
        $item = $opening + [
            'aux_type_code' => $typeCode,
            'archive_code' => $archiveCode,
            'value_source' => strtoupper((string)$type['value_source']),
            'existing_archive' => $archive ?: null,
        ];
        if ($item['value_source'] === 'MANUAL') {
            $manualMissing[] = $item;
        } else {
            $archiveMissing[] = $item;
        }
    }
}

echo '辅助期初有效行：' . count($openingRows) . PHP_EOL;
echo '手工型待补档：' . count($manualMissing) . PHP_EOL;
echo '档案型异常：' . count($archiveMissing) . PHP_EOL;
echo '无效数据：' . count($invalid) . PHP_EOL;
$manualCounts = [];
foreach ($manualMissing as $item) {
    $manualCounts[$item['aux_type_code']] = ($manualCounts[$item['aux_type_code']] ?? 0) + 1;
}
ksort($manualCounts, SORT_STRING);
foreach ($manualCounts as $typeCode => $count) {
    echo '  - ' . $typeCode . '：' . $count . PHP_EOL;
}

foreach ($archiveMissing as $item) {
    echo '[需人工确认] 账套=' . $item['account_set_id'] . ' 科目=' . $item['subject_code']
        . ' 期间=' . $item['period'] . ' 维度=' . $item['aux_type_code'] . ' 编码=' . $item['archive_code'] . PHP_EOL;
}
foreach ($invalid as $item) {
    echo '[无效] balance_id=' . $item['balance_id'] . ' 原因=' . $item['reason'] . PHP_EOL;
}

if (!$apply) {
    echo '当前为只读检查；确认后增加 --apply，仅自动修复手工型档案。' . PHP_EOL;
    exit(0);
}

$pdo->beginTransaction();
try {
    $created = 0;
    $restored = 0;
    foreach ($manualMissing as $item) {
        $now = date('Y-m-d H:i:s');
        $before = $item['existing_archive'];
        if ($before) {
            $restoreStmt->execute([$item['archive_code'], 'system', $now, '辅助期初历史修复恢复档案', $before['archive_id']]);
            $after = $before;
            $after['archive_name'] = $item['archive_code'];
            $after['status'] = 1;
            $after['updated_by'] = 'system';
            $after['updated_time'] = $now;
            $after['del_flag'] = 0;
            $after['remark'] = '辅助期初历史修复恢复档案';
            repairAuxAudit($pdo, $after, 'RESTORE', $before);
            $restored++;
            continue;
        }
        $row = [
            'archive_id' => repairAuxUuid(),
            'account_set_id' => $item['account_set_id'],
            'aux_type_code' => $item['aux_type_code'],
            'archive_code' => $item['archive_code'],
            'archive_name' => $item['archive_code'],
            'status' => 1,
            'created_by' => 'system',
            'created_time' => $now,
            'updated_by' => 'system',
            'updated_time' => $now,
            'del_flag' => 0,
            'version' => 0,
            'remark' => '辅助期初历史修复自动建档',
        ];
        $insertStmt->execute(array_values($row));
        repairAuxAudit($pdo, $row, 'AUTO_CREATE', null);
        $created++;
    }
    $pdo->commit();
    echo '修复完成：新建' . $created . '条，恢复' . $restored . '条。档案型异常未自动处理。' . PHP_EOL;
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, '修复失败，已回滚：' . $e->getMessage() . PHP_EOL);
    exit(1);
}
