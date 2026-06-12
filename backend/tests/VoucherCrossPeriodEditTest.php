<?php

$source = file_get_contents(__DIR__ . '/../app/finance/model/Voucher.php');
$component = file_get_contents(__DIR__ . '/../../frontend/src/views/voucher/VoucherEditorView.vue');

$checks = [
    [$source, 'resolveVoucherEditPeriods', 'Voucher edit should resolve original and target periods separately'],
    [$source, 'loadVoucherByIdForEdit', 'Voucher edit should load existing vouchers by voucher_id before moving periods'],
    [$source, '$targetPeriod !== $sourcePeriod', 'Voucher edit should detect cross-period date changes'],
    [$source, "'period' => \$targetPeriod", 'Voucher header should move to the target accounting period'],
    [$source, "'fiscal_year' => \$targetFiscalYear", 'Voucher header fiscal year should move with the target period'],
    [$source, '$voucher[\'voucher_no\'] = $this->nextVoucherNo($targetPeriod)', 'Cross-period voucher edits should allocate a voucher number in the target period'],
    [$source, "'period' => \$sourcePeriod", 'Old voucher detail rows should be deleted from the source period'],
    [$source, "'period' => \$targetPeriod", 'New voucher detail rows should be inserted into the target period'],
    [$component, 'const payloadPeriod = periodFromVoucherDate(form.voucherDate) || form.period', 'Voucher editor payload should derive accounting period from voucher date'],
    [$component, 'period: payloadPeriod', 'Voucher editor should submit the date-derived period'],
];

foreach ($checks as [$haystack, $needle, $message]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Voucher cross-period edit test passed' . PHP_EOL;
