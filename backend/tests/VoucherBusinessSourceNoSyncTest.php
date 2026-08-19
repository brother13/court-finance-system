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

class VoucherBusinessSourceNoSyncProbe extends Voucher
{
    public $statements = [];
    private $affectedRows = [1, 2, 3, 4];

    public function syncSourceVoucherNos($items, $period)
    {
        return $this->syncGeneratedBusinessSourceVoucherNos($items, $period);
    }

    protected function executeVoucherRenumberStatement($sql, $bind)
    {
        $this->statements[] = ['sql' => $sql, 'bind' => $bind];
        return array_shift($this->affectedRows);
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

$probe = new VoucherBusinessSourceNoSyncProbe();
$counts = $probe->syncSourceVoucherNos([
    ['voucher_id' => 'voucher-1', 'new_voucher_no' => 7, 'changed' => true, 'locked' => false],
    ['voucher_id' => 'voucher-2', 'new_voucher_no' => 8, 'changed' => true, 'locked' => false],
    ['voucher_id' => 'voucher-unchanged', 'new_voucher_no' => 9, 'changed' => false, 'locked' => false],
], '2026-05');

voucherNoSyncAssertSame([
    'fin_case_fund_payment' => 1,
    'fin_case_fund_refund' => 2,
    'fin_case_fund_unknown_payment' => 3,
    'fin_case_fund_distribution' => 4,
], $counts, 'Voucher renumber should report every synchronized business source');
voucherNoSyncAssertSame(4, count($probe->statements), 'Voucher renumber should execute one batch statement per business source table');

foreach ($tables as $index => $table) {
    $statement = $probe->statements[$index];
    if (strpos($statement['sql'], 'UPDATE ' . $table . ' SET voucher_no = CASE voucher_id') === false) {
        fwrite(STDERR, $table . ' should use one CASE UPDATE statement' . PHP_EOL);
        exit(1);
    }
    voucherNoSyncAssertSame('account-set-1', $statement['bind']['account_set_id'], $table . ' should retain account scope');
    voucherNoSyncAssertSame(2026, $statement['bind']['fiscal_year'], $table . ' should retain fiscal year scope');
    voucherNoSyncAssertSame('GENERATED', $statement['bind']['voucher_status'], $table . ' should retain generated status scope');
    voucherNoSyncAssertSame(0, $statement['bind']['del_flag'], $table . ' should retain active-row scope');
    voucherNoSyncAssertSame(7, $statement['bind']['voucher_no_0'], $table . ' should bind the first voucher number');
    voucherNoSyncAssertSame(8, $statement['bind']['voucher_no_1'], $table . ' should bind the second voucher number');
    voucherNoSyncAssertSame('2026-05', $statement['bind']['voucher_period'], $table . ' should synchronize voucher period');
    voucherNoSyncAssertSame('system', $statement['bind']['updated_by'], $table . ' should keep updater audit field');
    voucherNoSyncAssertSame('2026-07-16 16:00:00', $statement['bind']['updated_time'], $table . ' should keep update time');
}

$emptyProbe = new VoucherBusinessSourceNoSyncProbe();
voucherNoSyncAssertSame([
    'fin_case_fund_payment' => 0,
    'fin_case_fund_refund' => 0,
    'fin_case_fund_unknown_payment' => 0,
    'fin_case_fund_distribution' => 0,
], $emptyProbe->syncSourceVoucherNos([], '2026-05'), 'Empty change set should not update sources');
voucherNoSyncAssertSame(0, count($emptyProbe->statements), 'Empty change set should execute no SQL');

$source = file_get_contents(__DIR__ . '/../app/finance/model/Voucher.php');
if (strpos($source, 'syncGeneratedBusinessSourceVoucherNos(') === false) {
    fwrite(STDERR, 'Voucher renumber should synchronize generated business source numbers' . PHP_EOL);
    exit(1);
}

echo 'Voucher business source number sync test passed' . PHP_EOL;
