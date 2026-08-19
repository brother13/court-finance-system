<?php

namespace app\finance\service\casefund\support;

class LegacySpreadsheetReader
{
    const MAX_BIFF_RECORDS = 300000;
    const MAX_BIFF_SHARED_STRINGS = 150000;
    const MAX_BIFF_ROWS = 10000;
    const MAX_BIFF_CELLS = 150000;

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
        $streamLength = strlen($stream);
        $firstSheetOffset = null;
        $sstSegments = [];
        $collectSstContinues = false;
        $sstFound = false;
        $recordCount = 0;
        $pos = 0;
        // 第一遍只记录 Workbook 元数据位置，不为每一条 BIFF 记录创建嵌套数组
        // 和 data 子串副本。稠密工作簿可能有数十万条小记录，这是低内存环境
        // 最主要的解析峰值来源。
        while ($pos + 4 <= $streamLength) {
            $recordOffset = $pos;
            $type = $this->u16($stream, $pos);
            $length = $this->u16($stream, $pos + 2);
            $dataOffset = $pos + 4;
            if ($dataOffset + $length > $streamLength) {
                throw new \Exception('Excel BIFF 记录长度超出文件范围');
            }
            $recordCount++;
            if ($recordCount > self::MAX_BIFF_RECORDS) {
                throw new \Exception('Excel 文件记录数过多，请拆分后导入');
            }
            if ($firstSheetOffset === null && $type === 0x0085 && $length >= 8) {
                $firstSheetOffset = $this->u32($stream, $dataOffset);
            }
            if (!$sstFound && $type === 0x00FC) {
                $sstFound = true;
                $collectSstContinues = true;
                $sstSegments[] = [$dataOffset, $length];
            } elseif ($collectSstContinues && $type === 0x003C) {
                $sstSegments[] = [$dataOffset, $length];
            } else {
                $collectSstContinues = false;
            }
            $pos = $dataOffset + $length;
        }

        $sst = $this->parseBiffSharedStringsFromSegments($stream, $sstSegments);
        unset($sstSegments);

        $cells = [];
        $cellCount = 0;
        $pos = $firstSheetOffset === null ? 0 : (int)$firstSheetOffset;
        $inFirstSheet = $firstSheetOffset === null;
        while ($pos + 4 <= $streamLength) {
            $recordOffset = $pos;
            $type = $this->u16($stream, $pos);
            $length = $this->u16($stream, $pos + 2);
            $dataOffset = $pos + 4;
            if ($dataOffset + $length > $streamLength) {
                throw new \Exception('Excel BIFF 工作表记录长度超出文件范围');
            }
            $pos = $dataOffset + $length;

            if ($firstSheetOffset !== null && !$inFirstSheet) {
                if ($recordOffset === $firstSheetOffset && $type === 0x0809) {
                    $inFirstSheet = true;
                }
                continue;
            }
            if ($type === 0x000A) {
                break;
            }
            if ($type === 0x00FD && $length >= 10) {
                $row = $this->u16($stream, $dataOffset);
                $col = $this->u16($stream, $dataOffset + 2);
                $sstIndex = $this->u32($stream, $dataOffset + 6);
                $this->putBiffCell($cells, $row, $col, $sst[$sstIndex] ?? '', $cellCount);
            } elseif ($type === 0x0204 && $length >= 8) {
                $row = $this->u16($stream, $dataOffset);
                $col = $this->u16($stream, $dataOffset + 2);
                $data = substr($stream, $dataOffset, $length);
                list($value,) = $this->readBiffString($data, 6);
                $this->putBiffCell($cells, $row, $col, $value, $cellCount);
            } elseif ($type === 0x0203 && $length >= 14) {
                $row = $this->u16($stream, $dataOffset);
                $col = $this->u16($stream, $dataOffset + 2);
                $value = unpack('e', substr($stream, $dataOffset + 6, 8))[1];
                $this->putBiffCell($cells, $row, $col, $value, $cellCount);
            } elseif ($type === 0x027E && $length >= 10) {
                $row = $this->u16($stream, $dataOffset);
                $col = $this->u16($stream, $dataOffset + 2);
                $value = $this->decodeRkNumber($this->u32($stream, $dataOffset + 6));
                $this->putBiffCell($cells, $row, $col, $value, $cellCount);
            } elseif ($type === 0x00BD && $length >= 10) {
                // BIFF8 MULRK：同一行连续多个 RK 数值。
                // 0x00BE 是 MULBLANK（连续空白单元格），必须保持为空，不能按数字解码。
                $row = $this->u16($stream, $dataOffset);
                $firstCol = $this->u16($stream, $dataOffset + 2);
                $lastCol = $this->u16($stream, $dataOffset + $length - 2);
                $offset = 4;
                for ($col = $firstCol; $col <= $lastCol && $offset + 6 <= $length; $col++) {
                    $value = $this->decodeRkNumber($this->u32($stream, $dataOffset + $offset + 2));
                    $this->putBiffCell($cells, $row, $col, $value, $cellCount);
                    $offset += 6;
                }
            }
        }
        unset($sst);
        return $cells;
    }

    protected function putBiffCell(array &$cells, $row, $col, $value, &$cellCount)
    {
        $row = (int)$row;
        $col = (int)$col;
        if ($row < 0 || $row >= self::MAX_BIFF_ROWS) {
            throw new \Exception('Excel 文件行数超过' . self::MAX_BIFF_ROWS . '行，请拆分后导入');
        }
        $key = $row . ':' . $col;
        if (!array_key_exists($key, $cells)) {
            $cellCount++;
            if ($cellCount > self::MAX_BIFF_CELLS) {
                throw new \Exception('Excel 文件单元格数量过多，请拆分后导入');
            }
        }
        $cells[$key] = $value;
    }

    protected function parseBiffSharedStringsFromSegments($stream, array $segments)
    {
        if (empty($segments) || ($segments[0][1] ?? 0) < 8) {
            return [];
        }
        $unique = $this->u32($stream, $segments[0][0] + 4);
        if ($unique > self::MAX_BIFF_SHARED_STRINGS) {
            throw new \Exception('Excel 共享字符串数量过多，请拆分后导入');
        }
        $segmentIndex = 0;
        $offset = 8;
        $sst = [];
        for ($index = 0; $index < $unique; $index++) {
            $sst[] = $this->readBiffSstStringFromSegments(
                $stream,
                $segments,
                $segmentIndex,
                $offset
            );
        }
        return $sst;
    }

    protected function readBiffSstStringFromSegments(
        $stream,
        array $segments,
        &$segmentIndex,
        &$offset
    ) {
        $charCount = $this->u16(
            $this->readBiffSegmentBytes($stream, $segments, $segmentIndex, $offset, 2),
            0
        );
        $flagsRaw = $this->readBiffSegmentBytes($stream, $segments, $segmentIndex, $offset, 1);
        $flags = ord($flagsRaw);
        $hasRichText = ($flags & 0x08) !== 0;
        $hasExt = ($flags & 0x04) !== 0;
        $isUtf16 = ($flags & 0x01) !== 0;
        $richCount = $hasRichText
            ? $this->u16($this->readBiffSegmentBytes($stream, $segments, $segmentIndex, $offset, 2), 0)
            : 0;
        $extLength = $hasExt
            ? $this->u32($this->readBiffSegmentBytes($stream, $segments, $segmentIndex, $offset, 4), 0)
            : 0;

        $text = '';
        $readChars = 0;
        while ($readChars < $charCount && $segmentIndex < count($segments)) {
            $segmentLength = (int)$segments[$segmentIndex][1];
            if ($offset >= $segmentLength) {
                $segmentIndex++;
                $offset = 0;
                if ($segmentIndex >= count($segments)) {
                    break;
                }
                $continuationFlag = $this->readBiffSegmentBytes(
                    $stream,
                    $segments,
                    $segmentIndex,
                    $offset,
                    1
                );
                $isUtf16 = (ord($continuationFlag) & 0x01) !== 0;
                $segmentLength = (int)$segments[$segmentIndex][1];
            }
            $bytesPerChar = $isUtf16 ? 2 : 1;
            $availableChars = intdiv($segmentLength - $offset, $bytesPerChar);
            if ($availableChars <= 0) {
                $segmentIndex++;
                $offset = 0;
                continue;
            }
            $takeChars = min($charCount - $readChars, $availableChars);
            $segmentStart = (int)$segments[$segmentIndex][0];
            $raw = substr($stream, $segmentStart + $offset, $takeChars * $bytesPerChar);
            $offset += $takeChars * $bytesPerChar;
            $readChars += $takeChars;
            $text .= $isUtf16 ? iconv('UTF-16LE', 'UTF-8//IGNORE', $raw) : $raw;
        }
        if ($readChars !== $charCount) {
            throw new \Exception('Excel 共享字符串数据不完整');
        }
        $this->skipBiffSegmentBytes(
            $segments,
            $segmentIndex,
            $offset,
            $richCount * 4 + $extLength
        );
        return $text;
    }

    protected function readBiffSegmentBytes(
        $stream,
        array $segments,
        &$segmentIndex,
        &$offset,
        $length
    ) {
        $required = (int)$length;
        $result = '';
        while ($length > 0 && $segmentIndex < count($segments)) {
            $segmentStart = (int)$segments[$segmentIndex][0];
            $segmentLength = (int)$segments[$segmentIndex][1];
            if ($offset >= $segmentLength) {
                $segmentIndex++;
                $offset = 0;
                continue;
            }
            $take = min($length, $segmentLength - $offset);
            $result .= substr($stream, $segmentStart + $offset, $take);
            $offset += $take;
            $length -= $take;
        }
        if (strlen($result) !== $required) {
            throw new \Exception('Excel 共享字符串记录不完整');
        }
        return $result;
    }

    protected function skipBiffSegmentBytes(array $segments, &$segmentIndex, &$offset, $length)
    {
        while ($length > 0 && $segmentIndex < count($segments)) {
            $segmentLength = (int)$segments[$segmentIndex][1];
            if ($offset >= $segmentLength) {
                $segmentIndex++;
                $offset = 0;
                continue;
            }
            $take = min($length, $segmentLength - $offset);
            $offset += $take;
            $length -= $take;
        }
        if ($length > 0) {
            throw new \Exception('Excel 共享字符串附加数据不完整');
        }
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
