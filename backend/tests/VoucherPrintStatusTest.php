<?php

$source = file_get_contents(__DIR__ . '/../app/finance/model/Voucher.php');
$view = file_get_contents(__DIR__ . '/../..//frontend/src/views/voucher/VoucherListView.vue');

$checks = [
    [$source, "\$update = ['printed_flag' => '1'];", 'Printing should only persist the printed flag'],
    [$source, 'workflowStatus($before)', 'Audit operations should use workflow status compatibility'],
    [$source, "if (\$data['status'] === 'PRINTED')", 'Printed filter should map to printed flag'],
    [$source, "\$where['printed_flag'] = '1';", 'Printed filter should query printed flag'],
    [$view, 'statusForRow(row)', 'Voucher list should derive status from workflow state'],
    [$view, 'isPrintedRow(row)', 'Voucher list should show printed marker separately'],
    [$view, 'canAuditRow(row)', 'Printed marker should not hide audit action'],
    [$view, 'canUnauditRow(row)', 'Printed marker should not hide unaudit action'],
];

foreach ($checks as [$haystack, $needle, $message]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

if (strpos($source, "'status' => 'PRINTED'") !== false) {
    fwrite(STDERR, 'Printing must not write PRINTED into voucher status' . PHP_EOL);
    exit(1);
}

echo 'Voucher print status test passed' . PHP_EOL;
