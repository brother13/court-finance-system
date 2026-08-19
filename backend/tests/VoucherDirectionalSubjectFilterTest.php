<?php

function input($key = '', $default = null)
{
    if ($key === 'param.account_set_id') {
        return 'account-set-filter';
    }
    if ($key === 'param.year') {
        return '2026';
    }
    return $default;
}

function config($key = '')
{
    if ($key === 'default_account_set_id') {
        return 'account-set-filter';
    }
    if ($key === 'default_year') {
        return '2026';
    }
    return null;
}

function getNowTime()
{
    return '2026-08-10 12:00:00';
}

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/Voucher.php';

use app\finance\model\Voucher;

class VoucherDirectionalSubjectQuery
{
    private $rows;
    private $conditions = [];

    public function __construct($rows)
    {
        $this->rows = $rows;
    }

    public function where($field, $operator = null, $value = null)
    {
        if (is_array($field)) {
            foreach ($field as $name => $expected) {
                $this->conditions[] = [$name, '=', $expected];
            }
            return $this;
        }
        $this->conditions[] = [$field, $operator, $value];
        return $this;
    }

    public function group($field)
    {
        return $this;
    }

    public function column($field)
    {
        $values = [];
        foreach ($this->filteredRows() as $row) {
            $values[] = $row[$field] ?? null;
        }
        return array_values(array_unique($values));
    }

    private function filteredRows()
    {
        return array_values(array_filter($this->rows, function ($row) {
            foreach ($this->conditions as [$field, $operator, $expected]) {
                $actual = $row[$field] ?? null;
                if ($operator === '=' && $actual != $expected) {
                    return false;
                }
                if ($operator === '>' && !($actual > $expected)) {
                    return false;
                }
                if ($operator === '>=' && !($actual >= $expected)) {
                    return false;
                }
                if ($operator === '<=' && !($actual <= $expected)) {
                    return false;
                }
            }
            return true;
        }));
    }
}

class VoucherDirectionalSubjectFilterProbe extends Voucher
{
    public static $detailRows = [];

    public static function getdb($table = '')
    {
        return new VoucherDirectionalSubjectQuery(
            $table === 'fin_voucher_detail' ? self::$detailRows : []
        );
    }

    public function filteredVoucherIds($periodRange, $data)
    {
        return $this->voucherIdsByDetailFilters($periodRange, $data);
    }

    public function directionalVoucherIds($periodRange, $subjectCode, $amountField)
    {
        return $this->voucherIdsByDirectionalSubject($periodRange, $subjectCode, $amountField);
    }
}

function directionalFilterAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . '; expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

VoucherDirectionalSubjectFilterProbe::$detailRows = [
    ['voucher_id' => 'voucher-match', 'account_set_id' => 'account-set-filter', 'fiscal_year' => 2026, 'period' => '2026-05', 'subject_code' => '20030108', 'debit_amount' => '100.00', 'credit_amount' => '0.00', 'del_flag' => 0],
    ['voucher_id' => 'voucher-match', 'account_set_id' => 'account-set-filter', 'fiscal_year' => 2026, 'period' => '2026-05', 'subject_code' => '20030107', 'debit_amount' => '0.00', 'credit_amount' => '100.00', 'del_flag' => 0],
    ['voucher_id' => 'voucher-debit-only', 'account_set_id' => 'account-set-filter', 'fiscal_year' => 2026, 'period' => '2026-05', 'subject_code' => '20030108', 'debit_amount' => '50.00', 'credit_amount' => '0.00', 'del_flag' => 0],
    ['voucher_id' => 'voucher-debit-only', 'account_set_id' => 'account-set-filter', 'fiscal_year' => 2026, 'period' => '2026-05', 'subject_code' => '20030106', 'debit_amount' => '0.00', 'credit_amount' => '50.00', 'del_flag' => 0],
    ['voucher_id' => 'voucher-credit-only', 'account_set_id' => 'account-set-filter', 'fiscal_year' => 2026, 'period' => '2026-05', 'subject_code' => '20030106', 'debit_amount' => '30.00', 'credit_amount' => '0.00', 'del_flag' => 0],
    ['voucher_id' => 'voucher-credit-only', 'account_set_id' => 'account-set-filter', 'fiscal_year' => 2026, 'period' => '2026-05', 'subject_code' => '20030107', 'debit_amount' => '0.00', 'credit_amount' => '30.00', 'del_flag' => 0],
    ['voucher_id' => 'voucher-wrong-period', 'account_set_id' => 'account-set-filter', 'fiscal_year' => 2026, 'period' => '2026-04', 'subject_code' => '20030108', 'debit_amount' => '20.00', 'credit_amount' => '0.00', 'del_flag' => 0],
    ['voucher_id' => 'voucher-wrong-period', 'account_set_id' => 'account-set-filter', 'fiscal_year' => 2026, 'period' => '2026-04', 'subject_code' => '20030107', 'debit_amount' => '0.00', 'credit_amount' => '20.00', 'del_flag' => 0],
    ['voucher_id' => 'voucher-deleted', 'account_set_id' => 'account-set-filter', 'fiscal_year' => 2026, 'period' => '2026-05', 'subject_code' => '20030108', 'debit_amount' => '10.00', 'credit_amount' => '0.00', 'del_flag' => 1],
    ['voucher_id' => 'voucher-deleted', 'account_set_id' => 'account-set-filter', 'fiscal_year' => 2026, 'period' => '2026-05', 'subject_code' => '20030107', 'debit_amount' => '0.00', 'credit_amount' => '10.00', 'del_flag' => 1],
];

$periodRange = ['year' => 2026, 'start' => '2026-05', 'end' => '2026-05'];
$probe = new VoucherDirectionalSubjectFilterProbe();

directionalFilterAssertSame(
    ['voucher-match'],
    $probe->filteredVoucherIds($periodRange, [
        'debit_subject_code' => ' 20030108 ',
        'credit_subject_code' => '20030107',
    ]),
    'Directional subject filters should intersect on the same voucher'
);
directionalFilterAssertSame(
    ['voucher-match', 'voucher-debit-only'],
    $probe->filteredVoucherIds($periodRange, ['debit_subject_code' => '20030108']),
    'Debit subject filter should require a positive debit amount'
);
directionalFilterAssertSame(
    null,
    $probe->directionalVoucherIds($periodRange, '   ', 'debit_amount'),
    'Blank subject filter should not affect the voucher list'
);
directionalFilterAssertSame(
    [],
    $probe->directionalVoucherIds($periodRange, '20030108', 'unsafe_field'),
    'Directional subject filter should reject unknown amount fields'
);

echo 'Voucher directional subject filter test passed' . PHP_EOL;
