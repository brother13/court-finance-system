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
    return '2026-07-16 16:00:00';
}

function uuid()
{
    return '00000000-0000-4000-8000-000000000000';
}

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/Voucher.php';

use app\finance\model\Voucher;

class VoucherBusinessSourceNoDbStub
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

class VoucherBusinessSourceNoSyncProbe extends Voucher
{
    public static $dbs = [];

    public static function getdb($table = '')
    {
        return self::$dbs[$table];
    }

    public function syncSourceVoucherNo($voucherId, $period, $voucherNo)
    {
        return $this->syncGeneratedBusinessSourceVoucherNo($voucherId, $period, $voucherNo);
    }
}

function voucherNoSyncAssertSame($expected, $actual, $message)
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
    VoucherBusinessSourceNoSyncProbe::$dbs[$table] = new VoucherBusinessSourceNoDbStub($index + 1);
}

$probe = new VoucherBusinessSourceNoSyncProbe();
$counts = $probe->syncSourceVoucherNo('voucher-1', '2026-05', 7);

voucherNoSyncAssertSame([
    'fin_case_fund_payment' => 1,
    'fin_case_fund_refund' => 2,
    'fin_case_fund_unknown_payment' => 3,
    'fin_case_fund_distribution' => 4,
], $counts, 'Voucher renumber should report every synchronized business source');

foreach ($tables as $table) {
    $db = VoucherBusinessSourceNoSyncProbe::$dbs[$table];
    voucherNoSyncAssertSame([[
        'account_set_id' => 'account-set-1',
        'fiscal_year' => 2026,
        'voucher_id' => 'voucher-1',
        'voucher_status' => 'GENERATED',
        'del_flag' => 0,
    ]], $db->where, $table . ' synchronization should retain account/year/voucher/status scope');
    voucherNoSyncAssertSame(7, $db->updates[0]['voucher_no'], $table . ' should synchronize voucher number');
    voucherNoSyncAssertSame('2026-05', $db->updates[0]['voucher_period'], $table . ' should synchronize voucher period');
    voucherNoSyncAssertSame('system', $db->updates[0]['updated_by'], $table . ' should keep updater audit field');
    voucherNoSyncAssertSame('2026-07-16 16:00:00', $db->updates[0]['updated_time'], $table . ' should keep update time');
}

voucherNoSyncAssertSame([], $probe->syncSourceVoucherNo('', '2026-05', 7), 'Empty voucher id should not update sources');
voucherNoSyncAssertSame([], $probe->syncSourceVoucherNo('voucher-1', '', 7), 'Empty period should not update sources');
voucherNoSyncAssertSame([], $probe->syncSourceVoucherNo('voucher-1', '2026-05', 0), 'Invalid voucher number should not update sources');

$source = file_get_contents(__DIR__ . '/../app/finance/model/Voucher.php');
if (strpos($source, 'syncGeneratedBusinessSourceVoucherNo(') === false) {
    fwrite(STDERR, 'Voucher renumber should synchronize generated business source numbers' . PHP_EOL);
    exit(1);
}

echo 'Voucher business source number sync test passed' . PHP_EOL;
