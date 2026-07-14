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
    return '12345678-1234-4000-8000-123456789abc';
}

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\model\CaseFund;

class CaseFundReceiptPolicyFakeQuery
{
    private $rows;

    public function __construct($rows)
    {
        $this->rows = $rows;
    }

    public function where($field = null, $operator = null, $value = null)
    {
        if (is_array($field)) {
            $conditions = $field;
            $this->rows = array_values(array_filter($this->rows, function ($row) use ($conditions) {
                foreach ($conditions as $key => $expected) {
                    if (array_key_exists($key, $row) && $row[$key] != $expected) {
                        return false;
                    }
                }
                return true;
            }));
        } elseif ($operator === 'in' && is_array($value)) {
            $this->rows = array_values(array_filter($this->rows, function ($row) use ($field, $value) {
                return array_key_exists($field, $row) && in_array($row[$field], $value, true);
            }));
        }
        return $this;
    }

    public function field()
    {
        return $this;
    }

    public function select()
    {
        return $this->rows;
    }

    public function find()
    {
        return $this->rows[0] ?? null;
    }
}

class CaseFundReceiptPolicyProbe extends CaseFund
{
    public static $subjectConfigRows = [];
    public static $subjectAuxConfigRows = [];

    public static function getdb($table = '')
    {
        if ($table === self::TABLE_SUBJECT_CONFIG) {
            return new CaseFundReceiptPolicyFakeQuery(self::$subjectConfigRows);
        }
        if ($table === 'fin_subject_aux_config') {
            return new CaseFundReceiptPolicyFakeQuery(self::$subjectAuxConfigRows);
        }
        return new CaseFundReceiptPolicyFakeQuery([]);
    }

    public function requiresPaymentReceiptDuplicateCheck($bizType)
    {
        return $this->paymentImportRequiresReceiptNoDuplicateCheck($bizType);
    }

    public function requiresRefundReceiptTrace($bizType, $rows = [])
    {
        return $this->refundImportRequiresReceiptTrace($bizType, $rows);
    }
}

function assertSameValue($expected, $actual, $message)
{
    if ($actual !== $expected) {
        fwrite(STDERR, $message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$probe = new CaseFundReceiptPolicyProbe();

CaseFundReceiptPolicyProbe::$subjectConfigRows = [[
    'business_item_type' => '缴费登记',
    'credit_subject_code' => '220301',
]];
CaseFundReceiptPolicyProbe::$subjectAuxConfigRows = [];
assertSameValue(false, $probe->requiresPaymentReceiptDuplicateCheck('CASE_FUND'), 'Payment import should skip receipt duplicate checks when the configured credit subject has no receipt auxiliary config');

CaseFundReceiptPolicyProbe::$subjectAuxConfigRows = [[
    'subject_code' => '220301',
    'aux_type_code' => 'receipt_no',
]];
assertSameValue(true, $probe->requiresPaymentReceiptDuplicateCheck('CASE_FUND'), 'Payment import should check receipt duplicates when the configured credit subject uses receipt auxiliary accounting');

CaseFundReceiptPolicyProbe::$subjectConfigRows = [];
CaseFundReceiptPolicyProbe::$subjectAuxConfigRows = [];
assertSameValue(true, $probe->requiresPaymentReceiptDuplicateCheck('CASE_FUND'), 'Payment import should conservatively check receipt duplicates when subject config is missing');

CaseFundReceiptPolicyProbe::$subjectConfigRows = [
    [
        'business_item_type' => '执行、调解款',
        'credit_subject_code' => '220399',
    ],
    [
        'business_item_type' => '缴费登记',
        'credit_subject_code' => '220301',
    ],
];
CaseFundReceiptPolicyProbe::$subjectAuxConfigRows = [[
    'subject_code' => '220399',
    'aux_type_code' => 'receipt_no',
]];
assertSameValue(false, $probe->requiresPaymentReceiptDuplicateCheck('CASE_FUND'), 'Canonical payment config should override a legacy alias config');

CaseFundReceiptPolicyProbe::$subjectConfigRows = [];
CaseFundReceiptPolicyProbe::$subjectAuxConfigRows = [];
assertSameValue(false, $probe->requiresRefundReceiptTrace('LITIGATION_FEE'), 'Litigation-fee refund import should not require receipt auxiliary trace');
assertSameValue(true, $probe->requiresRefundReceiptTrace('UNKNOWN'), 'Unknown account-set types should conservatively require refund receipt trace');
assertSameValue(true, $probe->requiresRefundReceiptTrace('CASE_FUND'), 'Case-fund refund import should conservatively require receipt trace when subject config is missing');

CaseFundReceiptPolicyProbe::$subjectConfigRows = [[
    'business_item_type' => '出账查询',
    'debit_subject_code' => '224101',
    'credit_subject_code' => '100201',
]];
CaseFundReceiptPolicyProbe::$subjectAuxConfigRows = [];
assertSameValue(false, $probe->requiresRefundReceiptTrace('CASE_FUND'), 'Case-fund refund import should skip receipt trace when neither configured subject uses receipt auxiliary accounting');

CaseFundReceiptPolicyProbe::$subjectAuxConfigRows = [[
    'subject_code' => '224101',
    'aux_type_code' => 'receipt_no',
]];
assertSameValue(true, $probe->requiresRefundReceiptTrace('CASE_FUND'), 'Case-fund refund import should require receipt trace when either configured subject uses receipt auxiliary accounting');

echo 'Case fund receipt duplicate policy test passed' . PHP_EOL;
