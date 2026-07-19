<?php

function input($key = '', $default = null)
{
    if ($key === 'param.account_set_id') {
        return 'account-set-1';
    }
    if ($key === 'param.year') {
        return '2026';
    }
    return $default;
}

function config($key = '')
{
    return $key === 'default_year' ? '2026' : 'account-set-1';
}

function getNowTime()
{
    return '2026-07-16 12:00:00';
}

function uuid()
{
    return '00000000-0000-4000-8000-000000000000';
}

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/Voucher.php';

use app\finance\model\Voucher;

class VoucherBusinessSourceDbStub
{
    public $where = [];
    public $updates = [];
    private $affectedRows;

    public function __construct($affectedRows)
    {
        $this->affectedRows = $affectedRows;
    }

    public function where($where)
    {
        $this->where[] = $where;
        return $this;
    }

    public function update($update)
    {
        $this->updates[] = $update;
        return $this->affectedRows;
    }
}

class VoucherBusinessSourceResetProbe extends Voucher
{
    public static $dbs = [];

    public static function getdb($table = '')
    {
        return self::$dbs[$table];
    }

    public function resetSources($voucher)
    {
        return $this->resetGeneratedBusinessSources($voucher);
    }
}

function resetAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . '; expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$tables = [
    'fin_case_fund_payment',
    'fin_case_fund_refund',
    'fin_case_fund_unknown_payment',
    'fin_case_fund_distribution',
];
foreach ($tables as $index => $table) {
    VoucherBusinessSourceResetProbe::$dbs[$table] = new VoucherBusinessSourceDbStub($index + 1);
}

$probe = new VoucherBusinessSourceResetProbe();
$counts = $probe->resetSources([
    'voucher_id' => 'voucher-1',
    'fiscal_year' => 2025,
    'period' => '2025-01',
]);

resetAssertSame([
    'fin_case_fund_payment' => 1,
    'fin_case_fund_refund' => 2,
    'fin_case_fund_unknown_payment' => 3,
    'fin_case_fund_distribution' => 4,
], $counts, 'Voucher delete should report every reset business source');

foreach ($tables as $table) {
    $db = VoucherBusinessSourceResetProbe::$dbs[$table];
    resetAssertSame([[
        'account_set_id' => 'account-set-1',
        'fiscal_year' => 2025,
        'voucher_id' => 'voucher-1',
        'voucher_status' => 'GENERATED',
        'del_flag' => 0,
    ]], $db->where, $table . ' reset should retain account/year/voucher/status scope');
    resetAssertSame('UNGENERATED', $db->updates[0]['voucher_status'], $table . ' should return to ungenerated');
    resetAssertSame(null, $db->updates[0]['voucher_id'], $table . ' should clear voucher id');
    resetAssertSame(null, $db->updates[0]['voucher_no'], $table . ' should clear voucher number');
    resetAssertSame(null, $db->updates[0]['voucher_period'], $table . ' should clear voucher period');
    resetAssertSame(null, $db->updates[0]['voucher_generated_time'], $table . ' should clear generated time');
    resetAssertSame('system', $db->updates[0]['updated_by'], $table . ' should keep updater audit field');
    resetAssertSame('2026-07-16 12:00:00', $db->updates[0]['updated_time'], $table . ' should keep update time');
}

resetAssertSame([], $probe->resetSources([]), 'Voucher without id should not touch business tables');

echo 'Voucher business source reset test passed' . PHP_EOL;
