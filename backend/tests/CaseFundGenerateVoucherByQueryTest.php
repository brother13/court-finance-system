<?php

$source = file_get_contents(__DIR__ . '/../app/finance/model/CaseFund.php');
$paymentServiceSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/PaymentVoucherGenerationService.php');
$implementationSource = $source . "\n" . $paymentServiceSource;

$checks = [
    ["case 'paymentGenerateVoucherByQuery'", 'CaseFund should route paymentGenerateVoucherByQuery'],
    ["case 'refundGenerateVoucherByQuery'", 'CaseFund should route refundGenerateVoucherByQuery'],
    ['public function paymentGenerateVoucherByQuery', 'CaseFund should expose paymentGenerateVoucherByQuery'],
    ['public function refundGenerateVoucherByQuery', 'CaseFund should expose refundGenerateVoucherByQuery'],
    ["\$where['voucher_status'] = 'UNGENERATED';", 'Generate-by-query should force ungenerated source rows'],
    ['paymentRowsByGenerateQuery', 'Payment generate-by-query should load rows from query filters'],
    ['refundRowsByGenerateQuery', 'Refund generate-by-query should load rows from query filters'],
    ["order('payment_date asc, source_row_no asc')", 'Payment generate-by-query should keep date/source-row order'],
    ["order('refund_date asc, source_row_no asc')", 'Refund generate-by-query should keep refund date/source-row order'],
    ["'generate_scope' => \$generateScope", 'Audit log should mark voucher generation scope'],
    ["paymentGenerateVoucherFromRows(\$rows, 'QUERY')", 'Payment query generation should mark QUERY scope'],
    ["refundGenerateVoucherFromRows(\$rows, 'QUERY')", 'Refund query generation should mark QUERY scope'],
];

foreach ($checks as [$needle, $message]) {
    if (strpos($implementationSource, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Case fund generate voucher by query test passed' . PHP_EOL;
