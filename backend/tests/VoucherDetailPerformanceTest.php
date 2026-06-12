<?php

$source = file_get_contents(__DIR__ . '/../app/finance/model/Voucher.php');

if (strpos($source, 'loadVoucherDetailAuxValues') === false) {
    fwrite(STDERR, 'Voucher detail loading should use a bulk aux-value loader' . PHP_EOL);
    exit(1);
}

if (strpos($source, "where('detail_id', 'in'") === false) {
    fwrite(STDERR, 'Voucher detail aux values should be loaded by detail_id IN instead of one query per detail line' . PHP_EOL);
    exit(1);
}

$getInfoStart = strpos($source, 'public function getInfo');
$getInfoEnd = strpos($source, 'protected function fillUserDisplayNames');
$getInfoBody = $getInfoStart !== false && $getInfoEnd !== false ? substr($source, $getInfoStart, $getInfoEnd - $getInfoStart) : '';

if (strpos($getInfoBody, "\$this->getdb(\$auxTable)->where") !== false) {
    fwrite(STDERR, 'Voucher getInfo should not query aux values inside the detail loop' . PHP_EOL);
    exit(1);
}

echo 'Voucher detail performance test passed' . PHP_EOL;
