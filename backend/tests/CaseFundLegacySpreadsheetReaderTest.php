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

function uuid()
{
    return '12345678-1234-4000-8000-123456789abc';
}

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\model\CaseFund;
use app\finance\service\casefund\support\LegacySpreadsheetReader;

class CaseFundLegacySpreadsheetReaderProbe extends CaseFund
{
    public function parseRowsForTest($binary)
    {
        return $this->parseSpreadsheetRows($binary);
    }

    public function parseCellsForTest($stream)
    {
        return $this->parseBiffCells($stream);
    }

    public function readerForTest()
    {
        return $this->legacySpreadsheetReader();
    }
}

function biffRecord($type, $data)
{
    return pack('vv', $type, strlen($data)) . $data;
}

$reader = new LegacySpreadsheetReader();
$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
  <Worksheet ss:Name="第一张">
    <Table>
      <Row>
        <Cell><Data ss:Type="String">案号</Data></Cell>
        <Cell ss:Index="3"><Data ss:Type="String">金额</Data></Cell>
      </Row>
      <Row>
        <Cell><Data ss:Type="String">（2026）辽0111执1号</Data></Cell>
        <Cell ss:Index="3"><Data ss:Type="Number">12.50</Data></Cell>
      </Row>
    </Table>
  </Worksheet>
  <Worksheet ss:Name="第二张">
    <Table>
      <Row><Cell><Data ss:Type="String">不应读取</Data></Cell></Row>
    </Table>
  </Worksheet>
</Workbook>
XML;

$expectedRows = [
    [0 => '案号', 2 => '金额'],
    [0 => '（2026）辽0111执1号', 2 => '12.50'],
];
$rows = $reader->parseRows($xml);
if ($rows !== $expectedRows) {
    fwrite(STDERR, 'Legacy spreadsheet reader should preserve sparse Excel XML columns and only read the first worksheet' . PHP_EOL);
    exit(1);
}

$sstData = pack('VV', 1, 1) . pack('vC', 5, 0) . 'HE';
$sstRecord = biffRecord(0x00FC, $sstData);
$continueRecord = biffRecord(0x003C, chr(0) . 'LLO');
$firstSheetOffset = strlen($sstRecord . $continueRecord) + 12;
$boundSheetRecord = biffRecord(0x0085, pack('VvCC', $firstSheetOffset, 0, 0, 0));
$firstSheetBof = biffRecord(0x0809, pack('vvvv', 0x0600, 0x0010, 0, 0));
$labelSst = biffRecord(0x00FD, pack('vvvV', 0, 0, 0, 0));
$number = biffRecord(0x0203, pack('vvv', 1, 1, 0) . pack('e', 123.45));
$rkInteger = biffRecord(0x027E, pack('vvvV', 2, 0, 0, (42 << 2) | 0x02));
$firstSheetEof = biffRecord(0x000A, '');
$secondSheetBof = biffRecord(0x0809, pack('vvvv', 0x0600, 0x0010, 0, 0));
$secondSheetLabel = biffRecord(0x0204, pack('vvv', 0, 0, 0) . pack('vC', 6, 0) . 'SECOND');
$secondSheetEof = biffRecord(0x000A, '');
$biffStream = $sstRecord . $continueRecord . $boundSheetRecord
    . $firstSheetBof . $labelSst . $number . $rkInteger . $firstSheetEof
    . $secondSheetBof . $secondSheetLabel . $secondSheetEof;

$cells = $reader->parseBiffCells($biffStream);
if (($cells['0:0'] ?? '') !== 'HELLO') {
    fwrite(STDERR, 'Legacy spreadsheet reader should preserve SST strings split across CONTINUE records' . PHP_EOL);
    exit(1);
}
if (!isset($cells['1:1']) || abs($cells['1:1'] - 123.45) > 0.000001) {
    fwrite(STDERR, 'Legacy spreadsheet reader should preserve BIFF NUMBER values' . PHP_EOL);
    exit(1);
}
if (($cells['2:0'] ?? null) !== 42) {
    fwrite(STDERR, 'Legacy spreadsheet reader should preserve BIFF RK integer values' . PHP_EOL);
    exit(1);
}
if (in_array('SECOND', $cells, true)) {
    fwrite(STDERR, 'Legacy spreadsheet reader should stop after the first BIFF worksheet' . PHP_EOL);
    exit(1);
}

$probe = new CaseFundLegacySpreadsheetReaderProbe();
if ($probe->parseRowsForTest($xml) !== $expectedRows) {
    fwrite(STDERR, 'CaseFund compatibility wrapper should delegate row parsing to LegacySpreadsheetReader' . PHP_EOL);
    exit(1);
}
if ($probe->parseCellsForTest($biffStream) !== $cells) {
    fwrite(STDERR, 'CaseFund compatibility wrapper should delegate BIFF cell parsing to LegacySpreadsheetReader' . PHP_EOL);
    exit(1);
}
if ($probe->readerForTest() !== $probe->readerForTest()) {
    fwrite(STDERR, 'CaseFund should reuse one stateless LegacySpreadsheetReader per request' . PHP_EOL);
    exit(1);
}

$invalidFormatRejected = false;
try {
    $reader->parseRows('not-a-spreadsheet');
} catch (\Exception $e) {
    $invalidFormatRejected = $e->getMessage() === '仅支持 Excel 97-2003 .xls 或 Excel XML 文件';
}
if (!$invalidFormatRejected) {
    fwrite(STDERR, 'Legacy spreadsheet reader should preserve the invalid spreadsheet error message' . PHP_EOL);
    exit(1);
}

$invalidOleRejected = false;
try {
    $reader->readOleWorkbookStream('not-an-ole-workbook');
} catch (\Exception $e) {
    $invalidOleRejected = $e->getMessage() === '仅支持 Excel 97-2003 .xls 文件';
}
if (!$invalidOleRejected) {
    fwrite(STDERR, 'Legacy spreadsheet reader should preserve the invalid OLE workbook error message' . PHP_EOL);
    exit(1);
}

echo 'Case fund legacy spreadsheet reader test passed' . PHP_EOL;
