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

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/Voucher.php';

use app\finance\model\Voucher;

class VoucherExactListFilterProbe extends Voucher
{
    public function headerWhere($periodRange, $data)
    {
        return $this->buildVoucherHeaderWhere($periodRange, $data);
    }
}

function voucherExactFilterAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . '; expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$probe = new VoucherExactListFilterProbe();
$where = $probe->headerWhere([
    'year' => 2026,
    'start' => '2026-05',
    'end' => '2026-05',
], [
    'voucher_id' => ' voucher-2026-05-0007 ',
]);

voucherExactFilterAssertSame('account-set-1', $where['account_set_id'], 'Exact voucher query should retain account-set isolation');
voucherExactFilterAssertSame(2026, $where['fiscal_year'], 'Exact voucher query should retain fiscal-year isolation');
voucherExactFilterAssertSame('voucher-2026-05-0007', $where['voucher_id'], 'Exact voucher query should trim and filter voucher id');
voucherExactFilterAssertSame([
    ['>=', '2026-05'],
    ['<=', '2026-05'],
], $where['period'], 'Exact voucher query should retain period isolation');

$withoutVoucherId = $probe->headerWhere([
    'year' => 2026,
    'start' => '2026-05',
    'end' => '2026-05',
], [
    'voucher_id' => '   ',
]);
voucherExactFilterAssertSame(false, array_key_exists('voucher_id', $withoutVoucherId), 'Blank voucher id should not affect normal list queries');

echo 'Voucher exact list filter test passed' . PHP_EOL;
