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
    return '00000000-0000-4000-8000-000000000000';
}

function assertTrue($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/Voucher.php';

use app\finance\model\Voucher;

class VoucherRenumberProbe extends Voucher
{
    public function mappedItems($rows, $startNo = 1)
    {
        return $this->buildVoucherRenumberItems($rows, $startNo);
    }

    public function blockReason($voucher, $periodStatus)
    {
        return $this->voucherRenumberBlockReason($voucher, $periodStatus);
    }

    public function businessSort($voucher)
    {
        return $this->voucherRenumberBusinessSort($voucher);
    }

    public function negativeNumbers($count, $usedNumbers)
    {
        return $this->allocateNegativeVoucherNumbers($count, $usedNumbers);
    }

    public function deletedConflicts($rows, $finalNumbers)
    {
        return $this->deletedVoucherRenumberConflicts($rows, $finalNumbers);
    }
}

$voucherSource = file_get_contents(__DIR__ . '/../app/finance/model/Voucher.php');
assertTrue(strpos($voucherSource, "case 'renumberPreview'") !== false, 'Voucher should route renumberPreview');
assertTrue(strpos($voucherSource, "case 'renumber'") !== false, 'Voucher should route renumber');
assertTrue(strpos($voucherSource, "requirePermission('voucher:renumber')") !== false, 'Voucher renumber should require voucher:renumber');
assertTrue(strpos($voucherSource, 'fin_voucher_no_adjust_log') !== false, 'Voucher renumber should write adjustment detail logs');

$ddl = file_get_contents(__DIR__ . '/../database/court_finance_ddl.sql');
$patch = file_get_contents(__DIR__ . '/../database/finance_standard_patch.sql');
$rbac = file_get_contents(__DIR__ . '/../database/migration_rbac.sql');
assertTrue(strpos($ddl, 'create table fin_voucher_no_adjust_log') !== false, 'DDL should create fin_voucher_no_adjust_log');
assertTrue(strpos($patch, 'fin_voucher_no_adjust_log') !== false, 'Patch should create fin_voucher_no_adjust_log');
assertTrue(strpos($rbac, "'voucher:renumber'") !== false, 'RBAC should define voucher:renumber');

$probe = new VoucherRenumberProbe();
$rows = [
    [
        'voucher_id' => 'payment-2026-05-01',
        'period' => '2026-05',
        'voucher_date' => '2026-05-01',
        'voucher_word' => '记',
        'voucher_no' => 1,
        'summary' => '收 案号1 张三',
        'source_type' => 'BUSINESS',
        'status' => 'SUBMITTED',
        'printed_flag' => '0',
        'created_time' => '2026-05-02 09:00:00',
        'del_flag' => 0,
    ],
    [
        'voucher_id' => 'payment-2026-05-02',
        'period' => '2026-05',
        'voucher_date' => '2026-05-02',
        'voucher_word' => '记',
        'voucher_no' => 2,
        'summary' => '收 案号2 李四',
        'source_type' => 'BUSINESS',
        'status' => 'SUBMITTED',
        'printed_flag' => '0',
        'created_time' => '2026-05-02 10:00:00',
        'del_flag' => 0,
    ],
    [
        'voucher_id' => 'refund-2026-05-01',
        'period' => '2026-05',
        'voucher_date' => '2026-05-01',
        'voucher_word' => '记',
        'voucher_no' => 5,
        'summary' => '付 案号1 张三',
        'source_type' => 'BUSINESS',
        'status' => 'SUBMITTED',
        'printed_flag' => '0',
        'created_time' => '2026-05-03 09:00:00',
        'del_flag' => 0,
    ],
    [
        'voucher_id' => 'deleted-gap',
        'period' => '2026-05',
        'voucher_date' => '2026-05-01',
        'voucher_word' => '记',
        'voucher_no' => 3,
        'summary' => '收 已删除',
        'source_type' => 'BUSINESS',
        'status' => 'SUBMITTED',
        'printed_flag' => '0',
        'created_time' => '2026-05-02 11:00:00',
        'del_flag' => 1,
    ],
];

$mapped = $probe->mappedItems($rows, 1);
$byId = [];
foreach ($mapped as $item) {
    $byId[$item['voucher_id']] = $item;
}

assertSameValue(3, count($mapped), 'Deleted vouchers should be ignored by renumber mapping');
assertSameValue(1, $byId['payment-2026-05-01']['new_voucher_no'], 'First same-day payment should keep first number');
assertSameValue(2, $byId['refund-2026-05-01']['new_voucher_no'], 'Same-day refund should be placed next to payment');
assertSameValue(3, $byId['payment-2026-05-02']['new_voucher_no'], 'Next-day payment should move after same-day refund');
assertSameValue(true, $byId['refund-2026-05-01']['changed'], 'Refund voucher should be marked changed');
assertSameValue(1, $probe->businessSort(['summary' => '收 案号1 张三', 'source_type' => 'BUSINESS']), 'Payment summaries should sort first');
assertSameValue(2, $probe->businessSort(['summary' => '付 案号1 张三', 'source_type' => 'BUSINESS']), 'Refund summaries should sort second');
assertSameValue(9, $probe->businessSort(['summary' => '手工凭证', 'source_type' => 'MANUAL']), 'Unknown manual vouchers should sort last');
assertSameValue([-2, -4, -5], $probe->negativeNumbers(3, [-1, -3, 1, 2]), 'Temporary negative voucher numbers should avoid numbers already held by soft-deleted vouchers');

$deletedConflicts = $probe->deletedConflicts([
    ['voucher_id' => 'deleted-2', 'voucher_no' => 2, 'del_flag' => 1],
    ['voucher_id' => 'deleted-8', 'voucher_no' => 8, 'del_flag' => 1],
    ['voucher_id' => 'active-3', 'voucher_no' => 3, 'del_flag' => 0],
], [1, 2, 3]);
assertSameValue(1, count($deletedConflicts), 'Only soft-deleted vouchers occupying final numbers should be evacuated');
assertSameValue('deleted-2', $deletedConflicts[0]['voucher_id'], 'Soft-deleted voucher number 2 should be detected as a final-number conflict');

assertSameValue('', $probe->blockReason(['status' => 'SUBMITTED', 'printed_flag' => '0', 'void_flag' => '0'], 'OPEN'), 'Submitted unprinted vouchers in open period should be renumberable');
assertTrue($probe->blockReason(['status' => 'POSTED', 'printed_flag' => '0', 'void_flag' => '0'], 'OPEN') !== '', 'Posted vouchers should block renumber');
assertTrue($probe->blockReason(['status' => 'SUBMITTED', 'printed_flag' => '1', 'void_flag' => '0'], 'OPEN') !== '', 'Printed vouchers should block first implementation');
assertTrue($probe->blockReason(['status' => 'SUBMITTED', 'printed_flag' => '0', 'void_flag' => '0'], 'CLOSED') !== '', 'Closed periods should block renumber');

echo 'Voucher renumber test passed' . PHP_EOL;
