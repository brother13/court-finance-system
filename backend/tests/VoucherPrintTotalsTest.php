<?php
require_once __DIR__ . '/VoucherAmountSummaryTest.php';

function printCheck($condition, $message) {
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}
function printInvoke($name, ...$args) {
    global $voucher;
    $method = new ReflectionMethod(get_class($voucher), $name);
    $method->setAccessible(true);
    return $method->invokeArgs($voucher, $args);
}
$fixture = [
    'voucher_no' => 33, 'voucher_date' => '2026-08-07',
    // Page one deliberately has unequal sides; the whole voucher balances.
    'details' => [
        ['debit_amount' => '10000.10', 'summary' => '收款一'],
        ['credit_amount' => '10000.10', 'summary' => '收款一'],
        ['debit_amount' => '0.20', 'summary' => '收款二'],
        ['credit_amount' => '0.20', 'summary' => '收款二'],
    ],
];
$config = ['paper_size' => 'A5', 'set_name' => '测试账套'];
$pages = printInvoke('splitVoucherPrintPages', [$fixture], 3);
$first = printInvoke('buildVoucherPrintPdfHtml', $pages[0], $config, 3);
$last = printInvoke('buildVoucherPrintPdfHtml', $pages[1], $config, 3);
printCheck(strpos($first, '本页合计') !== false, 'Every split page must identify its own subtotal');
printCheck(strpos($first, '凭证总合计') === false, 'Only the final page may show the voucher grand total');
printCheck(strpos($last, '本页合计') !== false, 'Final split page must retain its subtotal');
printCheck(strpos($last, '凭证总合计') !== false, 'Final page must add the voucher grand total');
printCheck(substr_count($last, '10,000.30') === 2, 'Grand totals must sum all details separately in cents');
printCheck(strpos($last, '凭证总合计：壹万元叁角') !== false, 'Grand total uppercase money must follow the label on the same line');
printCheck(strpos($first, '本页合计：借方壹万元叁角；贷方壹万元壹角') !== false, 'Unequal page sides must each show their own uppercase total');
printCheck(strpos($last, '本页合计：借方零元整；贷方零元贰角') !== false, 'Final page uppercase money must use only that page');
printCheck(strpos($last, '人民币（大写）') === false && strpos($last, '<br') === false, 'Totals must not contain an uppercase prefix or forced line break');
$balancedPages = printInvoke('splitVoucherPrintPages', [$fixture], 2);
$balancedHtml = printInvoke('buildVoucherPrintPdfHtml', $balancedPages[0], $config, 2);
printCheck(strpos($balancedHtml, '本页合计：壹万元壹角') !== false, 'Balanced page must show its uppercase amount directly after the subtotal label');
$single = printInvoke('splitVoucherPrintPages', [$fixture], 8);
$html = printInvoke('buildVoucherPrintPdfHtml', $single[0], $config, 8);
printCheck(substr_count($html, '凭证总合计') === 1 && strpos($html, '本页合计') === false, 'Single page must show the grand total once');
foreach ([0 => '零元整', 1 => '零元壹分', 10 => '零元壹角', 101 => '壹元零壹分', 10001 => '壹佰元零壹分', 1000000 => '壹万元整', 100010001 => '壹佰万零壹佰元零壹分', 10000000100 => '壹亿零壹元整', -123 => '负壹元贰角叁分'] as $amount => $expected) {
    printCheck(printInvoke('voucherPrintUppercaseMoney', $amount) === $expected, 'Incorrect uppercase money for cents: ' . $amount);
}
// Batch printing must calculate totals independently for each voucher.
$second = $fixture;
$second['details'] = [['debit_amount' => '2.00'], ['credit_amount' => '2.00']];
$batch = printInvoke('splitVoucherPrintPages', [$fixture, $second], 3);
$other = printInvoke('buildVoucherPrintPdfHtml', $batch[2], $config, 3);
printCheck(strpos($other, '贰元整') !== false && strpos($other, '10,000.30') === false, 'Batch totals must not leak between vouchers');
echo 'Voucher print totals test passed' . PHP_EOL;
