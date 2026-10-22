<?php
require_once __DIR__ . '/VoucherPrintTotalsTest.php';

// Exercise mPDF itself: HTML assertions cannot detect an extra physical sheet.
printInvoke('registerMpdfAutoloader', __DIR__ . '/../vendor');
$layoutVoucher = $fixture;
$layoutVoucher['details'] = [];
for ($i = 0; $i < 60; $i++) {
    $layoutVoucher['details'][] = [
        'summary' => '收 （2026）测试执1234号 虚拟测试单位名称',
        'subject_code' => $i % 2 === 0 ? '100201' : '20030107',
        'subject_name' => $i % 2 === 0 ? '建设银行' : '正常执行款',
        'aux_desc' => $i % 2 === 0 ? '' : '案号：（2026）测试执1234号',
        'debit_amount' => $i % 2 === 0 ? '172218.07' : '0.00',
        'credit_amount' => $i % 2 === 1 ? '172218.07' : '0.00',
    ];
}
foreach ([['A4', 20, $layoutVoucher], ['A5', 8, $layoutVoucher], ['A5', 8, $fixture]] as [$paper, $rowCount, $item]) {
    $pages = printInvoke('splitVoucherPrintPages', [$item], $rowCount);
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'zh-CN+aCJK', 'format' => $paper . '-L',
        'tempDir' => sys_get_temp_dir() . '/court-voucher-print-test',
        'margin_left' => 22, 'margin_right' => 16, 'margin_top' => 7, 'margin_bottom' => 12,
        'autoScriptToLang' => true, 'autoLangToFont' => true, 'useAdobeCJK' => true,
    ]);
    $mpdf->WriteHTML(printInvoke('voucherPrintPdfStyles'));
    foreach ($pages as $index => $page) {
        if ($index > 0) $mpdf->AddPage();
        $mpdf->WriteHTML(printInvoke('buildVoucherPrintPdfHtml', $page, ['paper_size' => $paper, 'set_name' => '测试账套'], $rowCount));
    }
    printCheck($mpdf->page === count($pages), $paper . ' totals/signatures overflowed onto extra sheets');
    printCheck(strpos($mpdf->Output('', 'S'), '%PDF-') === 0, 'mPDF must produce a valid PDF');
}
echo 'Voucher print PDF layout test passed' . PHP_EOL;
