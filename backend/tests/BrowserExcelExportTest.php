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

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/ExcelExport.php';

use app\finance\model\ExcelExport;

$payloadPath = dirname(__DIR__, 2) . '/fpqd.json';
if (!file_exists($payloadPath)) {
    fwrite(STDERR, 'fpqd.json sample payload should exist' . PHP_EOL);
    exit(1);
}

$payload = json_decode(file_get_contents($payloadPath), true);
if (!is_array($payload)) {
    fwrite(STDERR, 'fpqd.json should be valid JSON' . PHP_EOL);
    exit(1);
}

$model = new ExcelExport();
$xml = $model->buildWorkbookXml($payload);

foreach (['序号', '分配单号', '转入案号', '分配金额', '（2024）辽0111执3548号', 'ZXFP60726051900001'] as $needle) {
    if (strpos($xml, $needle) === false) {
        fwrite(STDERR, 'Excel XML should include ' . $needle . PHP_EOL);
        exit(1);
    }
}

if (strpos($xml, '<Data ss:Type="Number">5084</Data>') === false) {
    fwrite(STDERR, 'Number header type should export numeric values as Excel numbers' . PHP_EOL);
    exit(1);
}

if (strpos($xml, '<Data ss:Type="String">0001759490</Data>') === false) {
    fwrite(STDERR, 'Plain string fields should preserve leading zero document numbers' . PHP_EOL);
    exit(1);
}

$escapedXml = $model->buildWorkbookXml([
    'filename' => '../坏/文件:*?',
    'header' => [
        ['field' => 'summary', 'label' => '摘要'],
    ],
    'data' => [
        ['summary' => 'A&B <测试>'],
    ],
]);

if (strpos($escapedXml, 'A&amp;B &lt;测试&gt;') === false) {
    fwrite(STDERR, 'Excel XML should escape XML special characters' . PHP_EOL);
    exit(1);
}

if ($model->normalizeFilename('../坏/文件:*?') !== '坏文件.xls') {
    fwrite(STDERR, 'Filename should be sanitized and receive an .xls suffix' . PHP_EOL);
    exit(1);
}

$controller = file_get_contents(__DIR__ . '/../app/finance/controller/Index.php');
if (strpos($controller, 'use app\\finance\\model\\ExcelExport;') === false || strpos($controller, "case 'excel':") === false) {
    fwrite(STDERR, 'Controller should expose the /excel/export route' . PHP_EOL);
    exit(1);
}

if (strpos($controller, "isset(\$param['filename'], \$param['header'], \$param['data'])") === false) {
    fwrite(STDERR, 'Controller should accept root-level JSON payloads from the browser plugin' . PHP_EOL);
    exit(1);
}

echo 'Browser Excel export test passed' . PHP_EOL;
