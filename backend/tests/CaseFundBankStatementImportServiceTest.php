<?php

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\service\casefund\banking\CaseFundBankStatementImportService;

function bankImportAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            $message
            . '; expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
            . PHP_EOL
        );
        exit(1);
    }
}

try {
    new CaseFundBankStatementImportService([]);
    fwrite(STDERR, 'Bank statement import service should reject incomplete hook configuration' . PHP_EOL);
    exit(1);
} catch (\InvalidArgumentException $e) {
    bankImportAssertSame(
        '案款银行流水导入服务缺少回调：normalize_row',
        $e->getMessage(),
        'Bank statement import service should report the first missing hook'
    );
}

$existingCalls = 0;
$invalidService = new CaseFundBankStatementImportService([
    'normalize_row' => function ($row, $bankCode, $bankName, $defaultRowNo) {
        bankImportAssertSame('CCB', $bankCode, 'Normalizer should receive selected bank code');
        bankImportAssertSame('建设银行', $bankName, 'Normalizer should receive selected bank name');
        if (!empty($row['invalid'])) {
            return ['error' => '第' . $defaultRowNo . '行：测试错误'];
        }
        return ['source_fingerprint' => 'VALID-' . $defaultRowNo];
    },
    'existing_fingerprints' => function () use (&$existingCalls) {
        $existingCalls++;
        return [];
    },
    'new_id' => function () {
        return 'unused-id';
    },
    'account_set_id' => function () {
        return 'unused-account';
    },
]);
$invalid = $invalidService->prepare([
    ['invalid' => false],
    ['invalid' => true],
], 'CCB', '建设银行', 'invalid.xls', 'invalid');
bankImportAssertSame(['第2行：测试错误'], $invalid['errors'], 'Any invalid row should preserve row-level validation details');
bankImportAssertSame(1, $invalid['parsed_count'], 'Valid rows should still be counted before the batch is rejected');
bankImportAssertSame([], $invalid['insert_rows'], 'A batch with validation errors should prepare no inserts');
bankImportAssertSame(0, $existingCalls, 'A rejected batch should not query existing fingerprints');

$normalizedCalls = [];
$existingFingerprints = [];
$nextId = 0;
$service = new CaseFundBankStatementImportService([
    'normalize_row' => function ($row, $bankCode, $bankName, $defaultRowNo) use (&$normalizedCalls) {
        $normalizedCalls[] = [$row['fingerprint'], $bankCode, $bankName, $defaultRowNo];
        return [
            'source_fingerprint' => $row['fingerprint'],
            'source_row_no' => $defaultRowNo,
            'bank_serial_no' => $row['serial'],
        ];
    },
    'existing_fingerprints' => function ($fingerprints) use (&$existingFingerprints) {
        $existingFingerprints = $fingerprints;
        return ['FP-EXISTING' => true];
    },
    'new_id' => function () use (&$nextId) {
        $nextId++;
        return 'STATEMENT-' . $nextId;
    },
    'account_set_id' => function () {
        return 'account-bank-import';
    },
]);

$prepared = $service->prepare([
    ['fingerprint' => 'FP-NEW', 'serial' => 'SERIAL-1'],
    ['fingerprint' => 'FP-NEW', 'serial' => 'SERIAL-1-DUP'],
    ['fingerprint' => 'FP-EXISTING', 'serial' => 'SERIAL-2'],
    ['fingerprint' => 'FP-LAST', 'serial' => 'SERIAL-3'],
], 'SHENGJING', '盛京银行', 'statement.xls', '批次备注');

bankImportAssertSame([
    ['FP-NEW', 'SHENGJING', '盛京银行', 1],
    ['FP-NEW', 'SHENGJING', '盛京银行', 2],
    ['FP-EXISTING', 'SHENGJING', '盛京银行', 3],
    ['FP-LAST', 'SHENGJING', '盛京银行', 4],
], $normalizedCalls, 'Import service should normalize every source row in source order');
bankImportAssertSame(
    ['FP-NEW', 'FP-NEW', 'FP-EXISTING', 'FP-LAST'],
    $existingFingerprints,
    'Import service should pass normalized fingerprints to the repository hook'
);
bankImportAssertSame([], $prepared['errors'], 'Valid bank rows should have no validation errors');
bankImportAssertSame(4, $prepared['parsed_count'], 'Parsed count should include duplicate and existing rows');
bankImportAssertSame(2, $prepared['created'], 'Only new unique fingerprints should be prepared for insert');
bankImportAssertSame(2, $prepared['skipped'], 'Existing and in-file duplicate fingerprints should both be skipped');
bankImportAssertSame(['FP-NEW', 'FP-LAST'], array_column($prepared['insert_rows'], 'source_fingerprint'), 'Prepared rows should keep first-seen source order');
bankImportAssertSame(['STATEMENT-1', 'STATEMENT-2'], array_column($prepared['insert_rows'], 'statement_id'), 'Ids should only be allocated for rows that will be inserted');
foreach ($prepared['insert_rows'] as $row) {
    bankImportAssertSame('account-bank-import', $row['account_set_id'], 'Prepared rows should use the current account set');
    bankImportAssertSame('statement.xls', $row['source_file_name'], 'Prepared rows should keep the import filename');
    bankImportAssertSame('UNMATCHED', $row['reconcile_status'], 'New bank rows should start unmatched');
    bankImportAssertSame('批次备注', $row['remark'], 'Prepared rows should keep the batch remark');
}

$serviceSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/banking/CaseFundBankStatementImportService.php');
foreach (['getdb(', 'think\\Db', 'Db::', '->insert(', '->update(', 'startTrans'] as $databaseNeedle) {
    if (strpos($serviceSource, $databaseNeedle) !== false) {
        fwrite(STDERR, 'Bank statement import service should remain persistence-free: ' . $databaseNeedle . PHP_EOL);
        exit(1);
    }
}

echo 'Case fund bank statement import service test passed' . PHP_EOL;
