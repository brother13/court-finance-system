<?php

namespace app\finance\service\casefund\support;

class LegacySpreadsheetReader
{
    public function parseRows($binary)
    {
        if (substr($binary, 0, 8) === hex2bin('d0cf11e0a1b11ae1')) {
            $cells = $this->parseBiffCells($this->readOleWorkbookStream($binary));
            $rows = [];
            foreach ($cells as $key => $value) {
                list($row, $col) = array_map('intval', explode(':', $key));
                if (!isset($rows[$row])) {
                    $rows[$row] = [];
                }
                $rows[$row][$col] = $value;
            }
            ksort($rows);
            return array_values($rows);
        }

        $xml = @simplexml_load_string($binary);
        if ($xml === false) {
            throw new \Exception('仅支持 Excel 97-2003 .xls 或 Excel XML 文件');
        }
        $ns = 'urn:schemas-microsoft-com:office:spreadsheet';
        $xml->registerXPathNamespace('ss', $ns);
        $rowNodes = $xml->xpath('//ss:Worksheet[1]/ss:Table/ss:Row');
        if (!$rowNodes) {
            throw new \Exception('文件中没有可识别的工作表数据');
        }

        $rows = [];
        foreach ($rowNodes as $rowNode) {
            $rowData = [];
            $colIndex = 0;
            foreach ($rowNode->children($ns)->Cell as $cellNode) {
                $attrs = $cellNode->attributes($ns);
                if (isset($attrs['Index'])) {
                    $colIndex = max(0, (int)$attrs['Index'] - 1);
                }
                $dataNodes = $cellNode->children($ns)->Data;
                $rowData[$colIndex] = isset($dataNodes[0]) ? (string)$dataNodes[0] : '';
                $colIndex++;
            }
            $rows[] = $rowData;
        }
        return $rows;
    }

    public function readOleWorkbookStream($binary)
    {
        if (substr($binary, 0, 8) !== hex2bin('d0cf11e0a1b11ae1')) {
            throw new \Exception('仅支持 Excel 97-2003 .xls 文件');
        }
        $sectorSize = 1 << $this->u16($binary, 30);
        $dirStart = $this->u32($binary, 48);
        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $sid = $this->u32($binary, 76 + $i * 4);
            if ($sid < 0xFFFFFFF0) {
                $fatSectors[] = $sid;
            }
        }
        $fat = [];
        foreach ($fatSectors as $sid) {
            $sector = $this->oleSector($binary, $sectorSize, $sid);
            for ($pos = 0; $pos + 4 <= strlen($sector); $pos += 4) {
                $fat[] = $this->u32($sector, $pos);
            }
        }
        $dirStream = $this->oleFatStream($binary, $sectorSize, $fat, $dirStart, 1048576);
        $workbook = null;
        for ($pos = 0; $pos + 128 <= strlen($dirStream); $pos += 128) {
            $entry = substr($dirStream, $pos, 128);
            $nameLen = $this->u16($entry, 64);
            if ($nameLen < 2) {
                continue;
            }
            $name = iconv('UTF-16LE', 'UTF-8//IGNORE', substr($entry, 0, $nameLen - 2));
            if ($name !== 'Workbook' && $name !== 'Book') {
                continue;
            }
            $start = $this->u32($entry, 116);
            $size = $this->u64($entry, 120);
            $workbook = $this->oleFatStream($binary, $sectorSize, $fat, $start, $size);
            break;
        }
        if ($workbook === null) {
            throw new \Exception('未找到 Workbook 数据流');
        }
        return $workbook;
    }

    public function parseBiffCells($stream)
    {
        $records = [];
        $pos = 0;
        while ($pos + 4 <= strlen($stream)) {
            $recordOffset = $pos;
            $type = $this->u16($stream, $pos);
            $length = $this->u16($stream, $pos + 2);
            $pos += 4;
            $records[] = [$recordOffset, $type, substr($stream, $pos, $length)];
            $pos += $length;
        }
        $sst = $this->parseBiffSharedStrings($records);
        $firstSheetOffset = $this->firstBiffWorksheetOffset($records);

        $cells = [];
        $inFirstSheet = $firstSheetOffset === null;
        foreach ($records as $record) {
            list($offset, $type, $data) = $record;
            if ($firstSheetOffset !== null) {
                if ($offset === $firstSheetOffset && $type === 0x0809) {
                    $inFirstSheet = true;
                    continue;
                }
                if (!$inFirstSheet) {
                    continue;
                }
                if ($type === 0x000A) {
                    break;
                }
            }
            if ($type === 0x00FD && strlen($data) >= 10) {
                $row = $this->u16($data, 0);
                $col = $this->u16($data, 2);
                $sstIndex = $this->u32($data, 6);
                $cells[$row . ':' . $col] = $sst[$sstIndex] ?? '';
            } elseif ($type === 0x0204 && strlen($data) >= 8) {
                $row = $this->u16($data, 0);
                $col = $this->u16($data, 2);
                list($text,) = $this->readBiffString($data, 6);
                $cells[$row . ':' . $col] = $text;
            } elseif ($type === 0x0203 && strlen($data) >= 14) {
                $row = $this->u16($data, 0);
                $col = $this->u16($data, 2);
                $value = unpack('e', substr($data, 6, 8))[1];
                $cells[$row . ':' . $col] = $value;
            } elseif ($type === 0x027E && strlen($data) >= 10) {
                $row = $this->u16($data, 0);
                $col = $this->u16($data, 2);
                $cells[$row . ':' . $col] = $this->decodeRkNumber($this->u32($data, 6));
            } elseif ($type === 0x00BE && strlen($data) >= 6) {
                $row = $this->u16($data, 0);
                $firstCol = $this->u16($data, 2);
                $lastCol = $this->u16($data, strlen($data) - 2);
                $offset = 4;
                for ($col = $firstCol; $col <= $lastCol && $offset + 6 <= strlen($data); $col++) {
                    $cells[$row . ':' . $col] = $this->decodeRkNumber($this->u32($data, $offset + 2));
                    $offset += 6;
                }
            }
        }
        return $cells;
    }

    protected function firstBiffWorksheetOffset($records)
    {
        foreach ($records as $record) {
            list(, $type, $data) = $record;
            if ($type === 0x0085 && strlen($data) >= 8) {
                return $this->u32($data, 0);
            }
        }
        return null;
    }

    protected function parseBiffSharedStrings($records)
    {
        for ($i = 0; $i < count($records); $i++) {
            if ($records[$i][1] !== 0x00FC) {
                continue;
            }
            $chunks = [$records[$i][2]];
            for ($j = $i + 1; $j < count($records) && $records[$j][1] === 0x003C; $j++) {
                $chunks[] = $records[$j][2];
            }
            $unique = $this->u32($chunks[0], 4);
            $chunkIndex = 0;
            $offset = 8;
            $sst = [];
            for ($k = 0; $k < $unique; $k++) {
                $sst[] = $this->readBiffSstString($chunks, $chunkIndex, $offset);
            }
            return $sst;
        }
        return [];
    }

    protected function readBiffSstString($chunks, &$chunkIndex, &$offset)
    {
        $charCount = $this->u16($this->readBiffChunkBytes($chunks, $chunkIndex, $offset, 2), 0);
        $flags = ord($this->readBiffChunkBytes($chunks, $chunkIndex, $offset, 1));
        $hasRichText = ($flags & 0x08) !== 0;
        $hasExt = ($flags & 0x04) !== 0;
        $isUtf16 = ($flags & 0x01) !== 0;
        $richCount = 0;
        $extLength = 0;
        if ($hasRichText) {
            $richCount = $this->u16($this->readBiffChunkBytes($chunks, $chunkIndex, $offset, 2), 0);
        }
        if ($hasExt) {
            $extLength = $this->u32($this->readBiffChunkBytes($chunks, $chunkIndex, $offset, 4), 0);
        }

        $text = '';
        $readChars = 0;
        while ($readChars < $charCount && $chunkIndex < count($chunks)) {
            if ($offset >= strlen($chunks[$chunkIndex])) {
                $chunkIndex++;
                $offset = 0;
                if ($chunkIndex >= count($chunks)) {
                    break;
                }
                $isUtf16 = (ord($this->readBiffChunkBytes($chunks, $chunkIndex, $offset, 1)) & 0x01) !== 0;
            }
            $bytesPerChar = $isUtf16 ? 2 : 1;
            $availableChars = intdiv(strlen($chunks[$chunkIndex]) - $offset, $bytesPerChar);
            if ($availableChars <= 0) {
                $chunkIndex++;
                $offset = 0;
                continue;
            }
            $takeChars = min($charCount - $readChars, $availableChars);
            $raw = substr($chunks[$chunkIndex], $offset, $takeChars * $bytesPerChar);
            $offset += $takeChars * $bytesPerChar;
            $readChars += $takeChars;
            $text .= $isUtf16 ? iconv('UTF-16LE', 'UTF-8//IGNORE', $raw) : $raw;
        }

        $this->skipBiffChunkBytes($chunks, $chunkIndex, $offset, $richCount * 4 + $extLength);
        return $text;
    }

    protected function readBiffChunkBytes($chunks, &$chunkIndex, &$offset, $length)
    {
        $result = '';
        while ($length > 0 && $chunkIndex < count($chunks)) {
            if ($offset >= strlen($chunks[$chunkIndex])) {
                $chunkIndex++;
                $offset = 0;
                continue;
            }
            $take = min($length, strlen($chunks[$chunkIndex]) - $offset);
            $result .= substr($chunks[$chunkIndex], $offset, $take);
            $offset += $take;
            $length -= $take;
        }
        return $result;
    }

    protected function skipBiffChunkBytes($chunks, &$chunkIndex, &$offset, $length)
    {
        while ($length > 0 && $chunkIndex < count($chunks)) {
            if ($offset >= strlen($chunks[$chunkIndex])) {
                $chunkIndex++;
                $offset = 0;
                continue;
            }
            $take = min($length, strlen($chunks[$chunkIndex]) - $offset);
            $offset += $take;
            $length -= $take;
        }
    }

    protected function readBiffString($data, $offset)
    {
        if ($offset + 3 > strlen($data)) {
            return ['', strlen($data)];
        }
        $charCount = $this->u16($data, $offset);
        $offset += 2;
        $flags = ord($data[$offset]);
        $offset++;
        $hasRichText = ($flags & 0x08) !== 0;
        $hasExt = ($flags & 0x04) !== 0;
        $isUtf16 = ($flags & 0x01) !== 0;
        $richCount = 0;
        $extLength = 0;
        if ($hasRichText) {
            $richCount = $this->u16($data, $offset);
            $offset += 2;
        }
        if ($hasExt) {
            $extLength = $this->u32($data, $offset);
            $offset += 4;
        }
        $byteLength = $charCount * ($isUtf16 ? 2 : 1);
        $raw = substr($data, $offset, $byteLength);
        $offset += $byteLength;
        $text = $isUtf16 ? iconv('UTF-16LE', 'UTF-8//IGNORE', $raw) : $raw;
        $offset += $richCount * 4 + $extLength;
        return [$text, $offset];
    }

    protected function u16($data, $offset)
    {
        return unpack('v', substr($data, $offset, 2))[1];
    }

    protected function u32($data, $offset)
    {
        return unpack('V', substr($data, $offset, 4))[1];
    }

    protected function u64($data, $offset)
    {
        $low = $this->u32($data, $offset);
        $high = $this->u32($data, $offset + 4);
        return $high * 4294967296 + $low;
    }

    protected function oleSector($binary, $sectorSize, $sid)
    {
        $offset = 512 + $sid * $sectorSize;
        return substr($binary, $offset, $sectorSize);
    }

    protected function oleFatStream($binary, $sectorSize, $fat, $start, $size)
    {
        $parts = [];
        $seen = [];
        $sid = $start;
        while ($sid < 0xFFFFFFF0 && isset($fat[$sid]) && !isset($seen[$sid])) {
            $seen[$sid] = true;
            $parts[] = $this->oleSector($binary, $sectorSize, $sid);
            $sid = $fat[$sid];
        }
        return substr(implode('', $parts), 0, $size);
    }

    protected function decodeRkNumber($rk)
    {
        $isMultiplied = ($rk & 0x01) !== 0;
        $isInteger = ($rk & 0x02) !== 0;
        if ($isInteger) {
            $value = $rk >> 2;
            if ($value & 0x20000000) {
                $value -= 0x40000000;
            }
        } else {
            $value = unpack('e', pack('V2', 0, $rk & 0xFFFFFFFC))[1];
        }
        return $isMultiplied ? $value / 100 : $value;
    }
}
