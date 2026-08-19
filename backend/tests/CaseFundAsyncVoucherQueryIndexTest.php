<?php

$root = realpath(__DIR__ . '/../..');
$patchPath = $root . '/backend/database/2026-08-03-case-fund-async-voucher-query-index.sql';
$paymentServicePath = $root . '/backend/app/finance/service/casefund/voucher/PaymentVoucherGenerationService.php';
$refundServicePath = $root . '/backend/app/finance/service/casefund/voucher/RefundVoucherGenerationService.php';
$caseFundPath = $root . '/backend/app/finance/model/CaseFund.php';

function asyncVoucherIndexFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function asyncVoucherIndexRead($path)
{
    if (!is_file($path)) {
        asyncVoucherIndexFail('Missing file: ' . $path);
    }
    return strtolower(file_get_contents($path));
}

function asyncVoucherIndexAssertContains($source, $needle, $message)
{
    if (strpos($source, strtolower($needle)) === false) {
        asyncVoucherIndexFail($message . '; missing: ' . $needle);
    }
}

$patch = asyncVoucherIndexRead($patchPath);
$paymentService = asyncVoucherIndexRead($paymentServicePath);
$refundService = asyncVoucherIndexRead($refundServicePath);
$caseFund = asyncVoucherIndexRead($caseFundPath);

asyncVoucherIndexAssertContains(
    $paymentService,
    "where['fiscal_year'] = \$this->callhook('current_year')",
    'Payment QUERY generation should filter the current fiscal year'
);
asyncVoucherIndexAssertContains(
    $paymentService,
    "where['voucher_status'] = 'ungenerated'",
    'Payment QUERY generation should only freeze ungenerated records'
);
asyncVoucherIndexAssertContains(
    $paymentService,
    "where['period'] = \$data['period']",
    'Payment QUERY generation should accept the task period filter'
);
asyncVoucherIndexAssertContains(
    $paymentService,
    "order('payment_date asc, source_row_no asc')",
    'Payment QUERY generation should preserve import order within date'
);

asyncVoucherIndexAssertContains(
    $refundService,
    "where['fiscal_year'] = \$this->callhook('current_year')",
    'Refund QUERY generation should filter the current fiscal year'
);
asyncVoucherIndexAssertContains(
    $refundService,
    "where['voucher_status'] = 'ungenerated'",
    'Refund QUERY generation should only freeze ungenerated records'
);
asyncVoucherIndexAssertContains(
    $refundService,
    "where['period'] = \$data['period']",
    'Refund QUERY generation should accept the task period filter'
);
asyncVoucherIndexAssertContains(
    $refundService,
    "order('refund_date asc, source_row_no asc')",
    'Refund QUERY generation should preserve import order within date'
);

asyncVoucherIndexAssertContains(
    $caseFund,
    "'period' => \$this->normalizeasyncjobperiod",
    'Async job creation should require a normalized accounting period'
);
asyncVoucherIndexAssertContains(
    $caseFund,
    'self::payment_voucher_field',
    'Payment worker should use a narrow field projection for large query scopes'
);
asyncVoucherIndexAssertContains(
    $caseFund,
    'self::refund_voucher_field',
    'Refund worker should use a narrow field projection for large query scopes'
);
if (!preg_match('/const\s+refund_voucher_field\s*=\s*\[(.*?)\];/s', $caseFund, $refundFieldMatch)) {
    asyncVoucherIndexFail('Refund voucher field projection should be declared explicitly');
}
asyncVoucherIndexAssertContains(
    $refundFieldMatch[1],
    "'payee_bank_account_name'",
    'Refund voucher field projection should include the bank account name used by voucher summaries'
);

$expectedIndexes = [
    'idx_case_fund_payment_async_voucher (account_set_id, fiscal_year, period, voucher_status, del_flag, payment_date, source_row_no)',
    'idx_case_fund_refund_async_voucher (account_set_id, fiscal_year, period, voucher_status, del_flag, refund_date, source_row_no)',
];
foreach ($expectedIndexes as $indexDefinition) {
    asyncVoucherIndexAssertContains(
        $patch,
        'add index ' . $indexDefinition,
        'Async voucher patch should cover equality filters before date/source-row ordering'
    );
}

foreach ([
    "from information_schema.statistics",
    "where table_schema = database()",
    "index_name = 'idx_case_fund_payment_async_voucher'",
    "index_name = 'idx_case_fund_refund_async_voucher'",
    'algorithm=inplace, lock=none',
] as $onlineInvariant) {
    asyncVoucherIndexAssertContains(
        $patch,
        $onlineInvariant,
        'Index patch should be idempotent and request online MySQL DDL'
    );
}

foreach (['drop table', 'drop index', 'delete from', 'truncate table', 'update fin_case_fund_'] as $forbiddenMutation) {
    if (strpos($patch, $forbiddenMutation) !== false) {
        asyncVoucherIndexFail('Index patch must not remove or rewrite business data: ' . $forbiddenMutation);
    }
}

echo 'Case fund async voucher query index test passed' . PHP_EOL;
