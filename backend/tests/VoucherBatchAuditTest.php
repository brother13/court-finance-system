<?php

$source = file_get_contents(__DIR__ . '/../app/finance/model/Voucher.php');

$checks = [
    [$source, "case 'batchAudit':", 'Voucher router should expose /voucher/batchAudit'],
    [$source, 'batchAuditVouchers($data)', 'Voucher should implement batchAuditVouchers'],
    [$source, "return \$this->changeStatusIds(\$data['period'] ?? '', \$voucherIds, 'SUBMITTED', 'AUDITED', 'AUDIT');", 'Batch audit should reuse the same submitted-to-audited status transition'],
    [$source, '制单人不能审核自己制单的凭证', 'Batch audit should keep maker-reviewer separation rule'],
    [$source, "'audited_count'", 'Batch audit should return audited_count'],
    [$source, "'审核成功'", 'Batch audit should return audit success message'],
    [$source, "case 'batchUnaudit':", 'Voucher router should expose /voucher/batchUnaudit'],
    [$source, 'batchUnauditVouchers($data)', 'Voucher should implement batchUnauditVouchers'],
    [$source, "return \$this->changeStatusIds(\$data['period'] ?? '', \$voucherIds, 'AUDITED', 'SUBMITTED', 'UNAUDIT');", 'Batch unaudit should reuse the audited-to-submitted status transition'],
    [$source, "'unaudited_count'", 'Batch unaudit should return unaudited_count'],
    [$source, "'反审核成功'", 'Batch unaudit should return unaudit success message'],
];

foreach ($checks as [$haystack, $needle, $message]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Voucher batch audit test passed' . PHP_EOL;
