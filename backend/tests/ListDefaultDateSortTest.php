<?php

$caseFund = file_get_contents(__DIR__ . '/../app/finance/model/CaseFund.php');
$voucher = file_get_contents(__DIR__ . '/../app/finance/model/Voucher.php');
$log = file_get_contents(__DIR__ . '/../app/finance/model/Log.php');
$book = file_get_contents(__DIR__ . '/../app/finance/model/Book.php');
$user = file_get_contents(__DIR__ . '/../app/finance/model/User.php');

$checks = [
    [$caseFund, "order('payment_date asc, source_row_no asc')", 'Payment list should sort by payment date ascending'],
    [$caseFund, "order('refund_date asc, source_row_no asc')", 'Refund list should sort by refund date ascending'],
    [$caseFund, "order('transaction_time asc, source_row_no asc')", 'Bank statement list should sort by transaction time ascending'],
    [$caseFund, "order('reconcile_date asc, bank_serial_no asc')", 'Bank reconcile list should sort by reconcile date ascending'],
    [$voucher, "order('voucher_date asc, voucher_no asc')", 'Voucher list should sort by voucher date ascending'],
    [$log, "order('created_time asc')", 'Audit log list should sort by created time ascending'],
    [$user, "order('u.created_time asc,u.username asc')", 'User list should sort by created time ascending'],
    [$book, "order('v.voucher_date asc,v.voucher_no asc,d.line_no asc')", 'Detail ledger should keep voucher date ascending'],
];

foreach ($checks as [$source, $needle, $message]) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'List default date sort test passed' . PHP_EOL;
