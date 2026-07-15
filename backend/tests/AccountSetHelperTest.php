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

require_once __DIR__ . '/../app/finance/model/Common.php';

$modelPath = __DIR__ . '/../app/finance/model/AccountSet.php';
if (!file_exists($modelPath)) {
    fwrite(STDERR, 'AccountSet model does not exist' . PHP_EOL);
    exit(1);
}

require_once $modelPath;

use app\finance\model\AccountSet;

class AccountSetHelperFiscalPeriodQuery
{
    public $whereValues = [];
    public $minimumPeriod = '';
    public $orderBy = '';
    public $valueField = '';

    private $rows = [];

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function where($field, $operator = null, $value = null)
    {
        if (is_array($field)) {
            $this->whereValues = array_merge($this->whereValues, $field);
            return $this;
        }
        if ($field === 'period' && $operator === '>=') {
            $this->minimumPeriod = (string)$value;
        }
        return $this;
    }

    public function order($orderBy)
    {
        $this->orderBy = (string)$orderBy;
        return $this;
    }

    public function value($field)
    {
        $this->valueField = (string)$field;
        $rows = array_values(array_filter($this->rows, function ($row) {
            foreach ($this->whereValues as $key => $expected) {
                if (!array_key_exists($key, $row) || $row[$key] !== $expected) {
                    return false;
                }
            }
            if ($this->minimumPeriod !== '' && ($row['period'] ?? '') < $this->minimumPeriod) {
                return false;
            }
            return true;
        }));
        usort($rows, function ($left, $right) {
            return strcmp($left['period'] ?? '', $right['period'] ?? '');
        });
        return empty($rows) ? null : ($rows[0][$field] ?? null);
    }
}

class AccountSetHelperProbe extends AccountSet
{
    public static $lastQuery;

    private static $fiscalPeriodRows = [];

    public static function useFiscalPeriods(array $rows)
    {
        self::$fiscalPeriodRows = $rows;
        self::$lastQuery = null;
    }

    public static function getdb($table = '')
    {
        if ($table !== 'fin_fiscal_period') {
            throw new RuntimeException('Unexpected table requested by account set helper test: ' . $table);
        }
        self::$lastQuery = new AccountSetHelperFiscalPeriodQuery(self::$fiscalPeriodRows);
        return self::$lastQuery;
    }
}

$accountSet = new AccountSetHelperProbe();

$formatMethod = new ReflectionMethod(AccountSet::class, 'formatPeriodLabel');
$formatMethod->setAccessible(true);
if ($formatMethod->invoke($accountSet, '2026-05') !== '2026年05月') {
    fwrite(STDERR, 'Expected 2026-05 to format as 2026年05月' . PHP_EOL);
    exit(1);
}

$currentMethod = new ReflectionMethod(AccountSet::class, 'currentPeriodValue');
$currentMethod->setAccessible(true);
AccountSetHelperProbe::useFiscalPeriods([]);
if ($currentMethod->invoke($accountSet, 'account-set-001', '2026-03') !== '2026-03') {
    fwrite(STDERR, 'Expected missing open fiscal period to fall back to enabled period' . PHP_EOL);
    exit(1);
}
if (!(AccountSetHelperProbe::$lastQuery instanceof AccountSetHelperFiscalPeriodQuery)
    || AccountSetHelperProbe::$lastQuery->whereValues !== [
        'account_set_id' => 'account-set-001',
        'status' => 'OPEN',
        'del_flag' => 0,
    ]
    || AccountSetHelperProbe::$lastQuery->minimumPeriod !== '2026-03'
    || AccountSetHelperProbe::$lastQuery->orderBy !== 'period asc'
    || AccountSetHelperProbe::$lastQuery->valueField !== 'period'
) {
    fwrite(STDERR, 'Expected current period lookup to query the account set open periods in ascending order' . PHP_EOL);
    exit(1);
}

AccountSetHelperProbe::useFiscalPeriods([
    ['account_set_id' => 'other-set', 'status' => 'OPEN', 'del_flag' => 0, 'period' => '2026-04'],
    ['account_set_id' => 'account-set-001', 'status' => 'CLOSED', 'del_flag' => 0, 'period' => '2026-05'],
    ['account_set_id' => 'account-set-001', 'status' => 'OPEN', 'del_flag' => 1, 'period' => '2026-06'],
    ['account_set_id' => 'account-set-001', 'status' => 'OPEN', 'del_flag' => 0, 'period' => '2026-02'],
    ['account_set_id' => 'account-set-001', 'status' => 'OPEN', 'del_flag' => 0, 'period' => '2026-12'],
    ['account_set_id' => 'account-set-001', 'status' => 'OPEN', 'del_flag' => 0, 'period' => '2026-11'],
]);
if ($currentMethod->invoke($accountSet, 'account-set-001', '2026-03') !== '2026-11') {
    fwrite(STDERR, 'Expected the earliest matching open fiscal period to determine current period' . PHP_EOL);
    exit(1);
}

$codeMethod = new ReflectionMethod(AccountSet::class, 'generateSetCode');
$codeMethod->setAccessible(true);
$code = $codeMethod->invoke($accountSet, 'CASE_FUND', '2026-05');
if (strpos($code, 'CASE_FUND_202605_') !== 0) {
    fwrite(STDERR, 'Expected generated set code to include biz type and enabled period, got ' . $code . PHP_EOL);
    exit(1);
}

echo 'Account set helper test passed' . PHP_EOL;
