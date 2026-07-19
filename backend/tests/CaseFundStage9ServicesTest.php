<?php

function input($key = '', $default = null)
{
    return $default;
}

function config($key = '')
{
    if ($key === 'default_account_set_id') {
        return 'account-set-stage9';
    }
    if ($key === 'default_year') {
        return '2026';
    }
    return null;
}

function getNowTime()
{
    return '2026-07-14 12:00:00';
}

function uuid()
{
    return 'stage9-uuid';
}

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\service\casefund\banking\CaseFundBankReconcileRepository;
use app\finance\service\casefund\persistence\CaseFundBusinessDeletionService;
use app\finance\service\casefund\subject\SubjectConfigManager;

class CaseFundStage9Store
{
    public $rows = [];
    public $queries = [];
    public $updates = [];
    public $inserts = [];
    public $deletes = [];

    public function db($table)
    {
        return new CaseFundStage9Query($this, $table);
    }
}

class CaseFundStage9Query
{
    private $store;
    private $table;
    private $conditions = [];
    private $groupField = '';

    public function __construct($store, $table)
    {
        $this->store = $store;
        $this->table = $table;
    }

    public function where($field, $operator = null, $value = null)
    {
        if (is_array($field)) {
            foreach ($field as $key => $item) {
                $this->conditions[] = [$key, '=', $item];
            }
        } elseif (func_num_args() === 2) {
            $this->conditions[] = [$field, '=', $operator];
        } else {
            $this->conditions[] = [$field, $operator, $value];
        }
        return $this;
    }

    public function field($fields)
    {
        return $this;
    }

    public function group($field)
    {
        $this->groupField = $field;
        return $this;
    }

    public function lock($lock = true)
    {
        return $this;
    }

    public function select()
    {
        $this->remember('select');
        $rows = $this->filteredRows();
        if ($this->groupField === 'match_status') {
            $counts = [];
            foreach ($rows as $row) {
                $status = $row['match_status'];
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
            $rows = [];
            foreach ($counts as $status => $total) {
                $rows[] = ['match_status' => $status, 'total' => (string)$total];
            }
        }
        return array_values($rows);
    }

    public function find()
    {
        $this->remember('find');
        $rows = $this->filteredRows();
        return reset($rows) ?: null;
    }

    public function count()
    {
        $this->remember('count');
        return count($this->filteredRows());
    }

    public function update($row)
    {
        $this->remember('update');
        $this->store->updates[] = ['table' => $this->table, 'conditions' => $this->conditions, 'row' => $row];
        return 1;
    }

    public function insert($row)
    {
        $this->store->inserts[] = ['table' => $this->table, 'row' => $row];
        return 1;
    }

    public function delete()
    {
        $this->remember('delete');
        $this->store->deletes[] = ['table' => $this->table, 'conditions' => $this->conditions];
        return 1;
    }

    private function remember($operation)
    {
        $this->store->queries[] = [
            'table' => $this->table,
            'operation' => $operation,
            'conditions' => $this->conditions,
        ];
    }

    private function filteredRows()
    {
        return array_filter($this->store->rows[$this->table] ?? [], function ($row) {
            foreach ($this->conditions as [$field, $operator, $value]) {
                $actual = $row[$field] ?? null;
                if ($operator === '=' && $actual !== $value) {
                    return false;
                }
                if ($operator === 'in' && !in_array($actual, $value, true)) {
                    return false;
                }
                if ($operator === 'neq' && $actual === $value) {
                    return false;
                }
                if ($operator === '>=' && $actual < $value) {
                    return false;
                }
                if ($operator === '<=' && $actual > $value) {
                    return false;
                }
                if ($operator === 'like') {
                    $needle = trim($value, '%');
                    $matched = false;
                    foreach (explode('|', $field) as $candidateField) {
                        if (strpos((string)($row[$candidateField] ?? ''), $needle) !== false) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        return false;
                    }
                }
            }
            return true;
        });
    }
}

function stage9AssertSame($expected, $actual, $message)
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

$store = new CaseFundStage9Store();
$prepareUpdate = function ($row) {
    $row['updated_by'] = 'stage9-user';
    return $row;
};

$store->rows['payment'] = [
    [
        'account_set_id' => 'account-set-stage9',
        'fiscal_year' => 2026,
        'del_flag' => 0,
        'payment_id' => 'payment-hyphen',
        'payment_date' => '2026-07-10',
        'bank_serial_no' => '210001500985TVNOPGN',
    ],
    [
        'account_set_id' => 'account-set-stage9',
        'fiscal_year' => 2026,
        'del_flag' => 0,
        'payment_id' => 'payment-ccb-suffix',
        'payment_date' => '2026-07-11',
        'bank_serial_no' => '1234567890123456789',
    ],
    [
        'account_set_id' => 'account-set-stage9',
        'fiscal_year' => 2026,
        'del_flag' => 0,
        'payment_id' => 'payment-claimed-without-serial',
        'payment_date' => '2026-07-12',
        'bank_serial_no' => '',
        'payment_method' => 'UNKNOWN',
        'payer_name' => '已认领缴费人',
    ],
];
$store->rows['unknown_payment'] = [
    [
        'account_set_id' => 'account-set-stage9',
        'fiscal_year' => 2026,
        'del_flag' => 0,
        'unknown_payment_id' => 'unknown-pending',
        'transaction_date' => '2026-07-13',
        'unknown_amount' => '88.00',
        'payer_account_no' => '6222 0002',
        'claim_status' => '待认领',
    ],
    [
        'account_set_id' => 'account-set-stage9',
        'fiscal_year' => 2026,
        'del_flag' => 0,
        'unknown_payment_id' => 'unknown-claimed',
        'transaction_date' => '2026-07-13',
        'unknown_amount' => '88.00',
        'payer_account_no' => '62220002',
        'claim_status' => '已认领',
    ],
];
$store->rows['refund'] = [[
    'account_set_id' => 'account-set-stage9',
    'fiscal_year' => 2026,
    'del_flag' => 0,
    'refund_id' => 'refund-1',
    'refund_date' => '2026-07-10',
    'payee_bank_account_no' => '62220001',
    'payee_bank_account_name' => '张三',
]];
$store->rows['statement'] = [
    [
        'account_set_id' => 'account-set-stage9',
        'fiscal_year' => 2026,
        'del_flag' => 0,
        'direction' => 'CREDIT',
        'transaction_date' => '2026-07-10',
        'bank_serial_no' => '106007-210001500985TVNOPGN',
        'bank_code' => 'CCB',
    ],
    [
        'account_set_id' => 'account-set-stage9',
        'fiscal_year' => 2026,
        'del_flag' => 0,
        'direction' => 'CREDIT',
        'transaction_date' => '2026-07-11',
        'bank_serial_no' => '76543211234567890123456789',
        'bank_code' => 'CCB',
    ],
    [
        'account_set_id' => 'account-set-stage9',
        'fiscal_year' => 2026,
        'del_flag' => 0,
        'direction' => 'CREDIT',
        'transaction_date' => '2026-07-12',
        'bank_serial_no' => 'CCB-20260712-0001880000',
        'counterparty_account_name' => '已认领缴费人',
        'bank_code' => 'CCB',
    ],
    [
        'account_set_id' => 'account-set-stage9',
        'fiscal_year' => 2026,
        'del_flag' => 0,
        'direction' => 'CREDIT',
        'transaction_date' => '2026-07-13',
        'bank_serial_no' => 'CCB-20260713-0001990000',
        'counterparty_account_no' => '6222-0002',
        'bank_code' => 'CCB',
    ],
    [
        'account_set_id' => 'account-set-stage9',
        'fiscal_year' => 2026,
        'del_flag' => 0,
        'direction' => 'DEBIT',
        'transaction_date' => '2026-07-10',
        'counterparty_account_no' => '62220001',
        'counterparty_account_name' => '张三',
        'bank_code' => 'CCB',
    ],
];
$store->rows['reconcile'] = [
    ['account_set_id' => 'account-set-stage9', 'fiscal_year' => 2026, 'del_flag' => 0, 'match_rule' => 'SERIAL_NO_DATE', 'reconcile_date' => '2026-07-10', 'match_status' => 'MATCHED'],
    ['account_set_id' => 'account-set-stage9', 'fiscal_year' => 2026, 'del_flag' => 0, 'match_rule' => 'PAYEE_ACCOUNT_DATE', 'reconcile_date' => '2026-07-10', 'match_status' => 'BANK_ONLY'],
];

$repository = new CaseFundBankReconcileRepository([
    'get_db' => function ($table) use ($store) {
        return $store->db($table);
    },
    'account_set_id' => function () {
        return 'account-set-stage9';
    },
    'current_year' => function () {
        return 2026;
    },
], [
    'payment' => 'payment',
    'refund' => 'refund',
    'unknown_payment' => 'unknown_payment',
    'statement' => 'statement',
    'reconcile' => 'reconcile',
], [
    'payment' => ['payment_id'],
    'refund' => ['refund_id'],
    'unknown_payment' => ['unknown_payment_id'],
    'statement' => ['statement_id'],
    'reconcile' => ['reconcile_id'],
]);

stage9AssertSame('payment-hyphen', $repository->paymentCandidatesByBankStatement([
    'transaction_date' => '2026-07-10',
    'bank_serial_no' => '106007-210001500985TVNOPGN',
    'bank_code' => 'CCB',
])[0]['payment_id'], 'Payment candidate should compare the complete alphanumeric segment after the final bank-serial hyphen');
stage9AssertSame('payment-ccb-suffix', $repository->paymentCandidatesByBankStatement([
    'transaction_date' => '2026-07-11',
    'bank_serial_no' => '76543211234567890123456789',
    'bank_code' => 'CCB',
])[0]['payment_id'], 'CCB payment candidate should still support the 26-character statement to 19-character payment suffix');
stage9AssertSame([], $repository->paymentCandidatesByBankStatement(['transaction_date' => '2026-07-10']), 'Payment candidate query should reject an empty serial');
stage9AssertSame([], $repository->paymentCandidatesByBankStatement([
    'transaction_date' => '2026-07-12',
    'bank_serial_no' => 'CCB-20260712-0001880000',
    'bank_code' => 'CCB',
    'counterparty_account_name' => '已认领缴费人',
]), 'Claimed unknown payments without a bank serial should not participate by payer name');
stage9AssertSame(
    ['payment-hyphen', 'payment-ccb-suffix'],
    array_column($repository->paymentCandidatesInScope('2026-07-01', '2026-07-31'), 'payment_id'),
    'Payment business-only scope should skip every payment registration without a bank serial'
);
stage9AssertSame('unknown-pending', $repository->unknownPaymentCandidatesByBankStatement([
    'transaction_date' => '2026-07-13',
    'counterparty_account_no' => '6222-0002',
])[0]['unknown_payment_id'], 'Unclaimed unknown payment should match by exact normalized counterparty account and date');
stage9AssertSame(
    ['unknown-pending'],
    array_column($repository->unknownPaymentCandidatesInScope('2026-07-01', '2026-07-31'), 'unknown_payment_id'),
    'Claimed unknown payments should not participate in business-only reconciliation scope'
);
stage9AssertSame('refund-1', $repository->refundCandidatesByBankStatement([
    'transaction_date' => '2026-07-10',
    'counterparty_account_no' => '62220001',
])[0]['refund_id'], 'Refund candidate query should prefer exact payee account matching');
stage9AssertSame(true, $repository->bankStatementExistsByPayment($store->rows['payment'][0], 'CCB', '2026-07-01', '2026-07-31'), 'Payment bank existence should use the same final-hyphen serial normalization');
stage9AssertSame(false, $repository->bankStatementExistsByPayment($store->rows['payment'][2], 'CCB', '2026-07-01', '2026-07-31'), 'Payment bank existence should skip claimed registrations without a serial');
stage9AssertSame(true, $repository->bankStatementExistsByUnknownPayment($store->rows['unknown_payment'][0], 'CCB', '2026-07-01', '2026-07-31'), 'Unknown-payment bank existence should match normalized payer account and date');
stage9AssertSame(true, $repository->bankStatementExistsByRefundPayee($store->rows['refund'][0], 'CCB', '2026-07-01', '2026-07-31'), 'Refund bank existence query should preserve payee, bank and date scope');
$summary = $repository->bankReconcileSummary([
    'account_set_id' => 'account-set-stage9',
    'fiscal_year' => 2026,
    'del_flag' => 0,
], ['MATCHED' => 0, 'BANK_ONLY' => 0, 'BIZ_ONLY' => 0]);
stage9AssertSame(['MATCHED' => 1, 'BANK_ONLY' => 1, 'BIZ_ONLY' => 0], $summary, 'Reconcile summary should merge grouped rows into the complete status dictionary');

$store->rows['business'] = [[
    'account_set_id' => 'account-set-stage9',
    'fiscal_year' => 2026,
    'del_flag' => 0,
    'payment_id' => 'payment-delete-1',
    'case_no' => 'CASE-DELETE',
    'voucher_status' => 'UNGENERATED',
]];
$store->rows['delete_reconcile'] = [];
$deletion = null;
$deletion = new CaseFundBusinessDeletionService([
    'get_db' => function ($table) use ($store) {
        return $store->db($table);
    },
    'account_where' => function () {
        return ['account_set_id' => 'account-set-stage9', 'del_flag' => 0];
    },
    'account_set_id' => function () {
        return 'account-set-stage9';
    },
    'current_year' => function () {
        return 2026;
    },
    'prepare_update' => $prepareUpdate,
    'has_active_generated_voucher' => function ($row) {
        return !empty($row['active_voucher']);
    },
    'assert_rows_can_delete' => function ($rows, $label) use (&$deletion) {
        return $deletion->assertRowsCanDeleteBusinessData($rows, $label);
    },
    'should_physical_delete' => function ($bizType) use (&$deletion) {
        return $deletion->shouldPhysicalDeleteCaseFundBusinessRows($bizType);
    },
], 'delete_reconcile', [
    'PAYMENT' => ['payment_id', 'voucher_status'],
]);
$prepared = $deletion->prepareBusinessDeletion('business', 'payment_id', ['payment-delete-1'], 'PAYMENT', '缴费记录');
stage9AssertSame(true, $prepared['hard_delete'], 'Imported payment deletion should preserve physical delete mode');
$deletion->executeBusinessDeletion('business', 'payment_id', ['payment-delete-1'], true);
stage9AssertSame('business', $store->deletes[0]['table'], 'Prepared business deletion should execute against the selected table');
stage9AssertSame(true, strpos($deletion->assertRowsCanDeleteBusinessData([[
    'case_no' => 'CASE-ACTIVE',
    'voucher_status' => 'GENERATED',
    'active_voucher' => true,
]], '缴费记录'), '原凭证仍有效') !== false, 'Deletion guard should block an active generated voucher');
stage9AssertSame(null, $deletion->assertRowsCanDeleteBusinessData([[
    'case_no' => 'CASE-INACTIVE',
    'voucher_status' => 'GENERATED',
    'active_voucher' => false,
]], '缴费记录'), 'Deletion guard should allow rows whose original voucher is inactive');

$store->rows['fin_subject'] = [
    ['account_set_id' => 'account-set-stage9', 'subject_code' => '100201', 'subject_name' => '银行存款', 'status' => 1, 'leaf_flag' => 1, 'voucher_entry_flag' => 1, 'del_flag' => 0],
    ['account_set_id' => 'account-set-stage9', 'subject_code' => '224101', 'subject_name' => '其他应付款', 'status' => 1, 'leaf_flag' => 1, 'voucher_entry_flag' => 1, 'del_flag' => 0],
];
$store->rows['subject_config'] = [[
    'config_id' => 'config-existing',
    'account_set_id' => 'account-set-stage9',
    'biz_type' => 'CASE_FUND',
    'voucher_biz_type' => 'PAYMENT',
    'business_item_type' => '缴费登记',
    'debit_subject_code' => '100201',
    'credit_subject_code' => '224101',
    'del_flag' => 0,
]];
$store->rows['fin_account_set'] = [[
    'account_set_id' => 'account-set-stage9',
    'generate_voucher_by_day_flag' => 1,
    'generate_payment_voucher_by_method_flag' => 0,
    'generate_refund_voucher_by_out_order_no_flag' => 0,
    'del_flag' => 0,
]];
$manager = null;
$idSequence = 0;
$manager = new SubjectConfigManager([
    'get_db' => function ($table) use ($store) {
        return $store->db($table);
    },
    'account_set_id' => function () {
        return 'account-set-stage9';
    },
    'new_id' => function () use (&$idSequence) {
        $idSequence++;
        return 'subject-config-new-' . $idSequence;
    },
    'prepare_create' => function ($row) {
        $row['created_by'] = 'stage9-user';
        return $row;
    },
    'prepare_update' => $prepareUpdate,
    'payment_method_options' => function () {
        return ['POS' => 'POS', 'TRANSFER' => '转账', 'UNKNOWN' => '不明款认领'];
    },
    'normalize_payment_method' => function ($method) {
        return strtoupper(trim((string)$method));
    },
    'payment_method_config_map' => function ($bizType) {
        return [];
    },
    'subject_name_map' => function ($subjectCodes) use (&$manager) {
        return $manager->subjectNameMap($subjectCodes);
    },
    'validate_subject_code' => function ($subjectCode, $label) use (&$manager) {
        return $manager->validateVoucherSubjectCode($subjectCode, $label);
    },
    'normalize_flag' => function ($value) {
        return (int)$value === 0 ? 0 : 1;
    },
], 'subject_config');

stage9AssertSame(null, $manager->validateVoucherSubjectCode('100201', '借方科目'), 'Subject config should accept enabled leaf voucher subjects');
stage9AssertSame('贷方科目不存在：999999', $manager->validateVoucherSubjectCode('999999', '贷方科目'), 'Subject config should reject a missing subject');
$businessSave = $manager->saveBusinessSubjectConfigs('CASE_FUND', 'PAYMENT', [[
    'business_item_type' => '缴费登记',
    'debit_subject_code' => '100201',
    'credit_subject_code' => '224101',
    'remark' => 'updated',
]], ['缴费登记']);
stage9AssertSame(1, count($businessSave['before']), 'Subject config update should retain the before image for audit');
stage9AssertSame('stage9-user', $businessSave['after'][0]['updated_by'], 'Subject config update should use update metadata hook');
$methodItems = $manager->paymentMethodSubjectConfigItems('CASE_FUND', [[
    'debit_subject_code' => '100201',
]]);
stage9AssertSame(['POS', 'TRANSFER', 'UNKNOWN'], array_column($methodItems, 'business_item_type'), 'Payment method subject items should preserve configured dictionary order');
stage9AssertSame('银行存款', $methodItems[0]['debit_subject_name'], 'Payment method subject item should resolve the debit subject name');
stage9AssertSame(1, $manager->subjectConfigAccountSetFlag(), 'Daily voucher flag should preserve its default enabled value');
[$accountBefore, $accountAfter] = $manager->saveSubjectConfigAccountSetFlag(0, 1, 1);
stage9AssertSame(1, $accountBefore['generate_voucher_by_day_flag'], 'Account-set flag save should retain before image');
stage9AssertSame(0, $accountAfter['generate_voucher_by_day_flag'], 'Account-set flag save should normalize and persist daily flag');
stage9AssertSame(1, $accountAfter['generate_payment_voucher_by_method_flag'], 'Account-set flag save should persist payment-method flag');

echo 'Case fund stage 9 services test passed' . PHP_EOL;
