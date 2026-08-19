<?php

require_once __DIR__ . '/../app/finance/service/casefund/support/LegacySpreadsheetReader.php';

use app\finance\service\casefund\support\LegacySpreadsheetReader;

function legacyReaderBoundedAssertThrows($expectedMessage, $callback, $label)
{
    try {
        $callback();
    } catch (Exception $e) {
        if ($e->getMessage() === $expectedMessage) {
            return;
        }
        fwrite(STDERR, $label . ': unexpected message: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    fwrite(STDERR, $label . ': expected exception was not thrown' . PHP_EOL);
    exit(1);
}

$reader = new LegacySpreadsheetReader();

// SST header: total string count + unique string count. It must be rejected before
// allocating the declared number of PHP strings.
$oversizedSstData = pack('V2', LegacySpreadsheetReader::MAX_BIFF_SHARED_STRINGS + 1, LegacySpreadsheetReader::MAX_BIFF_SHARED_STRINGS + 1);
$oversizedSst = pack('vv', 0x00FC, strlen($oversizedSstData)) . $oversizedSstData;
legacyReaderBoundedAssertThrows(
    'Excel 共享字符串数量过多，请拆分后导入',
    function () use ($reader, $oversizedSst) {
        $reader->parseBiffCells($oversizedSst);
    },
    'Shared-string bound'
);

// One NUMBER record placed beyond the accepted row range must fail before it can
// expand the sparse row/cell map.
$numberData = pack('vvv', LegacySpreadsheetReader::MAX_BIFF_ROWS, 0, 0) . pack('e', 1.0);
$oversizedRow = pack('vv', 0x0203, strlen($numberData)) . $numberData;
legacyReaderBoundedAssertThrows(
    'Excel 文件行数超过' . LegacySpreadsheetReader::MAX_BIFF_ROWS . '行，请拆分后导入',
    function () use ($reader, $oversizedRow) {
        $reader->parseBiffCells($oversizedRow);
    },
    'Row bound'
);

fwrite(STDOUT, 'Legacy spreadsheet reader bounded test passed' . PHP_EOL);
