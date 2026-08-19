<?php

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/Auxiliary.php';

use app\finance\model\Auxiliary;

class AuxiliaryArchiveShortcutSearchProbe extends Auxiliary
{
    public function __construct()
    {
    }

    public function parseShortcut($keyword, $auxTypeCode = 'case_no')
    {
        return $this->parseCaseNoShortcutKeyword($keyword, $auxTypeCode);
    }

    public function matchesShortcut($archiveCode, $year, $sequence)
    {
        return $this->caseNoShortcutMatches([
            'archive_code' => $archiveCode,
            'archive_name' => $archiveCode,
        ], $year, $sequence);
    }

    public function parseFullCaseNo($keyword, $auxTypeCode = 'case_no')
    {
        return $this->parseFullCaseNoKeyword($keyword, $auxTypeCode);
    }

    public function matchesFullCaseNo($archiveCode, $normalizedKeyword)
    {
        return $this->normalizedCaseNoMatches([
            'archive_code' => $archiveCode,
            'archive_name' => $archiveCode,
        ], $normalizedKeyword);
    }
}

function shortcutSearchFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$probe = new AuxiliaryArchiveShortcutSearchProbe();

foreach (['2025968', '2025 968', '2025+968', '2025-968号'] as $keyword) {
    $parsed = $probe->parseShortcut($keyword);
    if ($parsed !== ['year' => '2025', 'sequence' => '968']) {
        shortcutSearchFail('Expected shortcut keyword to parse: ' . $keyword);
    }
}

if ($probe->parseShortcut('2025') !== null) {
    shortcutSearchFail('A year without a sequence should keep the normal keyword search');
}
if ($probe->parseShortcut('2025968', 'receipt_no') !== null) {
    shortcutSearchFail('Shortcut parsing must only apply to case number archives');
}
if ($probe->parseShortcut('（2025）辽0106执968号') !== null) {
    shortcutSearchFail('A complete case number should keep the normal keyword search');
}

foreach ([
    '（2025）辽0106执968号',
    '（2025）辽0106执恢968号',
    '(2025)辽0111民初968号',
] as $archiveCode) {
    if (!$probe->matchesShortcut($archiveCode, '2025', '968')) {
        shortcutSearchFail('Expected shortcut to match exact case sequence: ' . $archiveCode);
    }
}

foreach ([
    '（2025）辽0106执1968号',
    '（2025）辽0106执0968号',
    '（2024）辽0106执968号',
] as $archiveCode) {
    if ($probe->matchesShortcut($archiveCode, '2025', '968')) {
        shortcutSearchFail('Shortcut should not match a different year or sequence: ' . $archiveCode);
    }
}

$fullCaseFormats = [
    '（2025）辽0106执968号',
    '(2025)辽0106执968号',
    '2025辽0106执968号',
    ' 2025 辽0106执968号 ',
    '（2025）辽 0106执968号',
];
foreach ($fullCaseFormats as $keyword) {
    $parsed = $probe->parseFullCaseNo($keyword);
    if ($parsed !== ['year' => '2025', 'normalized' => '2025辽0106执968号']) {
        shortcutSearchFail('Expected complete case number format to normalize: ' . $keyword);
    }
    if (!$probe->matchesFullCaseNo('（2025）辽0106执968号', $parsed['normalized'])) {
        shortcutSearchFail('Expected normalized complete case number to match: ' . $keyword);
    }
}
if ($probe->parseFullCaseNo('2025') !== null) {
    shortcutSearchFail('A year-only keyword should keep the normal fuzzy search');
}
if ($probe->parseFullCaseNo('2025辽0106执968号', 'receipt_no') !== null) {
    shortcutSearchFail('Complete case number normalization must only apply to case archives');
}

echo 'Auxiliary archive shortcut search test passed' . PHP_EOL;
