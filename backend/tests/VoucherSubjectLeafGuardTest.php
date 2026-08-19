<?php

function input($key = '', $default = null)
{
    return $default;
}

function config($key = '')
{
    if ($key === 'default_account_set_id') {
        return 'account-set-leaf-guard';
    }
    if ($key === 'default_year') {
        return '2026';
    }
    return null;
}

function getNowTime()
{
    return '2026-07-31 12:00:00';
}

function uuid()
{
    return 'leaf-guard-uuid';
}

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/Voucher.php';

use app\finance\model\Voucher;

class VoucherSubjectLeafGuardQuery
{
    private $rows;
    private $conditions = [];

    public function __construct($rows)
    {
        $this->rows = $rows;
    }

    public function where($conditions)
    {
        foreach ($conditions as $field => $value) {
            $this->conditions[$field] = $value;
        }
        return $this;
    }

    public function find()
    {
        $rows = $this->filteredRows();
        return reset($rows) ?: null;
    }

    public function count()
    {
        return count($this->filteredRows());
    }

    private function filteredRows()
    {
        return array_filter($this->rows, function ($row) {
            foreach ($this->conditions as $field => $value) {
                if (($row[$field] ?? null) !== $value) {
                    return false;
                }
            }
            return true;
        });
    }
}

class VoucherSubjectLeafGuardModel extends Voucher
{
    public static $subjectRows = [];

    public static function getdb($table = '')
    {
        return new VoucherSubjectLeafGuardQuery(
            $table === 'fin_subject' ? self::$subjectRows : []
        );
    }

    public function validateLine($line)
    {
        return $this->checkLine($line);
    }
}

function leafGuardAssertSame($expected, $actual, $message)
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

VoucherSubjectLeafGuardModel::$subjectRows = [
    [
        'account_set_id' => 'account-set-leaf-guard',
        'subject_code' => '100201',
        'parent_code' => '1002',
        'status' => 1,
        'leaf_flag' => 1,
        'voucher_entry_flag' => 1,
        'del_flag' => 0,
    ],
    [
        'account_set_id' => 'account-set-leaf-guard',
        'subject_code' => '10020101',
        'parent_code' => '100201',
        'status' => 1,
        'leaf_flag' => 1,
        'voucher_entry_flag' => 1,
        'del_flag' => 0,
    ],
];
$model = new VoucherSubjectLeafGuardModel();
$result = $model->validateLine([
    'subject_code' => '100201',
    'summary' => '诉讼费收款',
    'debit_amount' => '100.00',
    'credit_amount' => '0.00',
]);
leafGuardAssertSame(0, $result['code'], 'Voucher line should reject a parent subject whose leaf flag is stale');
leafGuardAssertSame(
    '非末级科目不允许录入凭证：100201',
    $result['message'],
    'Voucher line should report the non-leaf subject'
);

$leafModel = new VoucherSubjectLeafGuardModel();
$leafResult = $leafModel->validateLine([
    'subject_code' => '10020101',
    'summary' => '诉讼费收款',
    'debit_amount' => '100.00',
    'credit_amount' => '0.00',
]);
leafGuardAssertSame(20000, $leafResult['code'], 'Voucher line should accept the active real leaf subject');

VoucherSubjectLeafGuardModel::$subjectRows[1]['del_flag'] = 1;
$parentAfterDeleteModel = new VoucherSubjectLeafGuardModel();
$parentAfterDeleteResult = $parentAfterDeleteModel->validateLine([
    'subject_code' => '100201',
    'summary' => '诉讼费收款',
    'debit_amount' => '100.00',
    'credit_amount' => '0.00',
]);
leafGuardAssertSame(
    20000,
    $parentAfterDeleteResult['code'],
    'Soft-deleted children should not keep a subject from becoming a real leaf'
);

echo 'Voucher subject leaf guard test passed' . PHP_EOL;
