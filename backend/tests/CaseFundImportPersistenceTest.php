<?php

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\service\casefund\importing\CaseFundImportPersistence;

class CaseFundImportPersistenceStore
{
    public $rows = [];
    public $queries = [];
    public $inserts = [];
    public $updates = [];

    public function db($table)
    {
        return new CaseFundImportPersistenceQuery($this, $table);
    }
}

class CaseFundImportPersistenceQuery
{
    private $store;
    private $table;
    private $where = [];
    private $inFilter = null;

    public function __construct($store, $table)
    {
        $this->store = $store;
        $this->table = $table;
    }

    public function where($field, $operator = null, $value = null)
    {
        if (is_array($field)) {
            foreach ($field as $key => $item) {
                $this->where[$key] = $item;
            }
        } elseif ($operator === 'in') {
            $this->inFilter = [$field, $value];
        }
        return $this;
    }

    public function field($fields)
    {
        return $this;
    }

    public function select()
    {
        $this->store->queries[] = [
            'table' => $this->table,
            'where' => $this->where,
            'in' => $this->inFilter,
        ];
        return array_values(array_filter($this->store->rows[$this->table] ?? [], function ($row) {
            foreach ($this->where as $field => $value) {
                if (($row[$field] ?? null) !== $value) {
                    return false;
                }
            }
            if ($this->inFilter !== null) {
                [$field, $values] = $this->inFilter;
                if (!in_array($row[$field] ?? null, $values, true)) {
                    return false;
                }
            }
            return true;
        }));
    }

    public function insert($row)
    {
        $this->store->inserts[] = ['table' => $this->table, 'row' => $row];
        return 1;
    }

    public function update($row)
    {
        $this->store->updates[] = ['table' => $this->table, 'where' => $this->where, 'row' => $row];
        return 1;
    }
}

function importPersistenceAssertSame($expected, $actual, $message)
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
    new CaseFundImportPersistence([], [], []);
    fwrite(STDERR, 'Import persistence should reject incomplete hook configuration' . PHP_EOL);
    exit(1);
} catch (\InvalidArgumentException $e) {
    importPersistenceAssertSame('案款导入持久化服务缺少回调：get_db', $e->getMessage(), 'Import persistence should report the first missing hook');
}

$store = new CaseFundImportPersistenceStore();
$sequence = 0;
$persistence = new CaseFundImportPersistence([
    'get_db' => function ($table) use ($store) {
        return $store->db($table);
    },
    'account_set_id' => function () {
        return 'account-set-import';
    },
    'new_id' => function () use (&$sequence) {
        $sequence++;
        return 'new-id-' . $sequence;
    },
    'prepare_create' => function ($row) {
        $row['created_by'] = 'import-user';
        return $row;
    },
    'prepare_update' => function ($row) {
        $row['updated_by'] = 'import-user';
        return $row;
    },
    'payment_duplicate_key' => function ($row, $useReceiptNo) {
        if ($useReceiptNo && !empty($row['receipt_no'])) {
            return 'receipt:' . $row['receipt_no'];
        }
        return 'fingerprint:' . ($row['source_fingerprint'] ?? '');
    },
    'remember_payment_existing' => function (&$existing, $row, $useReceiptNo) {
        $key = $useReceiptNo && !empty($row['receipt_no'])
            ? 'receipt:' . $row['receipt_no']
            : 'fingerprint:' . ($row['source_fingerprint'] ?? '');
        if (!isset($existing[$key]) || (($existing[$key]['voucher_status'] ?? '') === 'UNGENERATED' && ($row['voucher_status'] ?? '') !== 'UNGENERATED')) {
            $existing[$key] = $row;
        }
    },
    'has_active_generated_voucher' => function ($row) {
        return !empty($row['active_voucher']);
    },
], [
    'payment' => 'fin_case_fund_payment',
    'refund' => 'fin_case_fund_refund',
    'unknown_payment' => 'fin_case_fund_unknown_payment',
    'distribution' => 'fin_case_fund_distribution',
], ['payment_id', 'source_fingerprint', 'receipt_no', 'bank_account_no', 'payment_amount', 'voucher_status', 'del_flag']);

$store->rows['fin_case_fund_payment'] = [
    [
        'payment_id' => 'soft-deleted-payment',
        'account_set_id' => 'account-set-import',
        'source_fingerprint' => 'FP-RESTORE',
        'receipt_no' => 'R-RESTORE',
        'bank_account_no' => 'BANK-1',
        'payment_amount' => '10.00',
        'voucher_status' => 'UNGENERATED',
        'del_flag' => 1,
    ],
    [
        'payment_id' => 'active-payment',
        'account_set_id' => 'account-set-import',
        'source_fingerprint' => 'FP-ACTIVE',
        'receipt_no' => 'R-ACTIVE',
        'bank_account_no' => 'BANK-2',
        'payment_amount' => '20.00',
        'voucher_status' => 'GENERATED',
        'active_voucher' => true,
        'del_flag' => 0,
        'source_file_name' => 'history.xls',
        'source_row_no' => 9,
        'case_no' => 'CASE-ACTIVE',
        'payment_date' => '2026-07-01',
        'payer_name' => '张三',
        'voucher_no' => 8,
    ],
];

$existing = $persistence->loadExistingPaymentsForImport([
    ['source_fingerprint' => 'FP-RESTORE', 'receipt_no' => 'R-RESTORE', 'bank_account_no' => 'BANK-1'],
    ['source_fingerprint' => 'FP-ACTIVE', 'receipt_no' => 'R-ACTIVE', 'bank_account_no' => 'BANK-2'],
], true);
importPersistenceAssertSame(true, isset($existing['receipt:R-RESTORE']), 'Payment fingerprint lookup should include a matching soft-deleted row');
$fingerprintQuery = $store->queries[0];
importPersistenceAssertSame(['account_set_id' => 'account-set-import'], $fingerprintQuery['where'], 'Payment fingerprint query must intentionally omit del_flag');
$receiptQuery = $store->queries[1];
importPersistenceAssertSame(0, $receiptQuery['where']['del_flag'], 'Payment receipt query must only read active rows');

$paymentResult = $persistence->persistPaymentRows([
    [
        'source_fingerprint' => 'FP-RESTORE',
        'receipt_no' => 'R-RESTORE',
        'payment_amount' => '10.00',
        'voucher_status' => 'UNGENERATED',
    ],
    [
        'source_fingerprint' => 'FP-ACTIVE',
        'receipt_no' => 'R-ACTIVE',
        'payment_amount' => '20.00',
        'voucher_status' => 'UNGENERATED',
    ],
    [
        'source_fingerprint' => 'FP-NEW',
        'receipt_no' => 'R-NEW',
        'payment_amount' => '30.00',
    ],
    [
        'source_fingerprint' => 'FP-NEW-REPEAT',
        'receipt_no' => 'R-NEW',
        'payment_amount' => '30.00',
    ],
], $existing, [
    'filename' => 'new.xls',
    'remark' => 'reimport',
    'use_receipt_no_as_duplicate_key' => true,
]);
importPersistenceAssertSame(['created' => 1, 'updated' => 1, 'skipped' => 2], $paymentResult, 'Payment persistence should restore, skip active generated, insert and skip in-file duplicate');
$paymentUpdate = $store->updates[0];
importPersistenceAssertSame(['payment_id' => 'soft-deleted-payment', 'account_set_id' => 'account-set-import'], $paymentUpdate['where'], 'Payment restore update should target the original row without del_flag');
importPersistenceAssertSame(0, $paymentUpdate['row']['del_flag'], 'Payment restore update should clear soft delete');
importPersistenceAssertSame('UNGENERATED', $paymentUpdate['row']['voucher_status'], 'Payment restore should reset voucher status');
importPersistenceAssertSame(null, $paymentUpdate['row']['voucher_id'], 'Payment restore should detach the old voucher');
importPersistenceAssertSame('import-user', $paymentUpdate['row']['updated_by'], 'Payment restore should use the caller update metadata hook');
$paymentInsert = $store->inserts[0];
importPersistenceAssertSame('account-set-import', $paymentInsert['row']['account_set_id'], 'Payment insert should enforce current account set');
importPersistenceAssertSame('new.xls', $paymentInsert['row']['source_file_name'], 'Payment insert should retain source filename');
importPersistenceAssertSame('import-user', $paymentInsert['row']['created_by'], 'Payment insert should use the caller create metadata hook');

$store->queries = [];
$noReceiptAuxExisting = $persistence->loadExistingPaymentsForImport([
    [
        'source_fingerprint' => 'FP-JUNE-NEW',
        'receipt_no' => '',
        'bank_account_no' => 'BANK-2',
        'payment_amount' => '20.00',
    ],
], false);
importPersistenceAssertSame([], $noReceiptAuxExisting, 'No-receipt-aux import should not match an existing row by bank account and amount');
importPersistenceAssertSame(1, count($store->queries), 'No-receipt-aux import should only query existing source fingerprints');
importPersistenceAssertSame(
    ['source_fingerprint', ['FP-JUNE-NEW']],
    $store->queries[0]['in'],
    'No-receipt-aux import should not query receipt numbers or bank accounts'
);

$store->inserts = [];
$noReceiptAuxResult = $persistence->persistPaymentRows([
    [
        'source_fingerprint' => 'FP-SAME-AMOUNT-ONE',
        'receipt_no' => '',
        'bank_account_no' => 'BANK-SHARED',
        'payment_amount' => '7500.00',
    ],
    [
        'source_fingerprint' => 'FP-SAME-AMOUNT-TWO',
        'receipt_no' => '',
        'bank_account_no' => 'BANK-SHARED',
        'payment_amount' => '7500.00',
    ],
], [], [
    'filename' => 'no-receipt-aux.xls',
    'use_receipt_no_as_duplicate_key' => false,
]);
importPersistenceAssertSame(
    ['created' => 2, 'updated' => 0, 'skipped' => 0],
    $noReceiptAuxResult,
    'No-receipt-aux import should keep different source rows even when bank account and amount are identical'
);
importPersistenceAssertSame(2, count($store->inserts), 'No-receipt-aux import should insert both distinct source rows');

$duplicateErrors = $persistence->paymentReceiptNoDuplicateErrors([
    ['source_row_no' => 2, 'receipt_no' => 'R-ACTIVE'],
    ['source_row_no' => 3, 'receipt_no' => 'R-ACTIVE'],
]);
$duplicateText = implode("\n", $duplicateErrors);
importPersistenceAssertSame(true, strpos($duplicateText, '与第2行重复') !== false, 'Receipt validation should report in-file duplicate row numbers');
importPersistenceAssertSame(true, strpos($duplicateText, 'history.xls') !== false, 'Receipt validation should report active existing source details');
importPersistenceAssertSame(true, strpos($duplicateText, '凭证号：8') !== false, 'Receipt validation should report the active voucher number');

$store->rows['fin_case_fund_refund'] = [[
    'refund_id' => 'refund-existing',
    'account_set_id' => 'account-set-import',
    'source_fingerprint' => 'REFUND-FP',
    'voucher_status' => 'GENERATED',
    'active_voucher' => false,
    'del_flag' => 0,
]];
$existingRefunds = $persistence->loadExistingRowsByFingerprint('refund', [
    ['source_fingerprint' => 'REFUND-FP'],
], true);
$refundResult = $persistence->persistRefundRows([
    ['source_fingerprint' => 'REFUND-FP', 'refund_amount' => '40.00'],
], $existingRefunds, ['filename' => 'refund.xls']);
importPersistenceAssertSame(['created' => 0, 'updated' => 1, 'skipped' => 0], $refundResult, 'Refund persistence should overwrite an inactive generated row');
$refundUpdate = $store->updates[1];
importPersistenceAssertSame(0, $refundUpdate['where']['del_flag'], 'Refund update should only target an active source row');

$store->rows['fin_case_fund_unknown_payment'] = [[
    'account_set_id' => 'account-set-import',
    'source_fingerprint' => 'UNKNOWN-EXISTING',
    'del_flag' => 0,
]];
$unknownExisting = $persistence->loadExistingRowsByFingerprint('unknown_payment', [
    ['source_fingerprint' => 'UNKNOWN-EXISTING'],
    ['source_fingerprint' => 'UNKNOWN-NEW'],
]);
$unknownResult = $persistence->persistUniqueFingerprintRows('unknown_payment', 'unknown_payment_id', [
    ['source_fingerprint' => 'UNKNOWN-EXISTING'],
    ['source_fingerprint' => 'UNKNOWN-NEW'],
    ['source_fingerprint' => 'UNKNOWN-NEW'],
], $unknownExisting, ['filename' => 'unknown.xls']);
importPersistenceAssertSame(['created' => 1, 'skipped' => 2], $unknownResult, 'Unique-fingerprint import should skip database and in-file duplicates');

echo 'Case fund import persistence test passed' . PHP_EOL;
