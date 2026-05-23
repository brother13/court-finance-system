<?php

namespace app\finance\model;

class ExcelExport extends Common
{
    const ACTION = 'excel';

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'export':
                return $this->export($data);
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function export($data = [])
    {
        try {
            $xml = $this->buildWorkbookXml($data);
            $filename = $this->normalizeFilename($data['filename'] ?? '');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }

        return response($xml, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => $this->contentDisposition($filename),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function buildWorkbookXml($payload)
    {
        $headers = $this->normalizeHeaders($payload['header'] ?? []);
        $rows = $payload['data'] ?? [];
        if (!is_array($rows)) {
            throw new \Exception('data 必须是数组');
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        $xml .= '<Worksheet ss:Name="' . $this->xmlText($this->worksheetName($payload['filename'] ?? '导出数据')) . '"><Table>';
        $xml .= '<Row>';
        foreach ($headers as $header) {
            $xml .= '<Cell><Data ss:Type="String">' . $this->xmlText($header['label']) . '</Data></Cell>';
        }
        $xml .= '</Row>';

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $row = [];
            }
            $xml .= '<Row>';
            foreach ($headers as $header) {
                $value = $row[$header['field']] ?? '';
                $xml .= $this->cellXml($value, $header['type']);
            }
            $xml .= '</Row>';
        }

        $xml .= '</Table></Worksheet></Workbook>';
        return $xml;
    }

    public function normalizeFilename($filename)
    {
        $filename = trim((string)$filename);
        if ($filename === '') {
            $filename = '导出数据';
        }
        $filename = str_replace(['../', '..\\', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $filename);
        $filename = trim($filename);
        if ($filename === '') {
            $filename = '导出数据';
        }
        if (!preg_match('/\.xls$/i', $filename)) {
            $filename .= '.xls';
        }
        return $filename;
    }

    protected function normalizeHeaders($headers)
    {
        if (!is_array($headers) || empty($headers)) {
            throw new \Exception('header 不能为空');
        }

        $normalized = [];
        foreach ($headers as $header) {
            if (!is_array($header)) {
                continue;
            }
            $field = trim((string)($header['field'] ?? ''));
            if ($field === '') {
                continue;
            }
            $label = trim((string)($header['label'] ?? ''));
            $normalized[] = [
                'field' => $field,
                'label' => $label === '' ? $field : $label,
                'type' => strtolower(trim((string)($header['type'] ?? 'string'))),
            ];
        }

        if (empty($normalized)) {
            throw new \Exception('header 至少需要一个有效列');
        }
        return $normalized;
    }

    protected function cellXml($value, $type)
    {
        if ($type === 'number') {
            $number = $this->normalizeNumberValue($value);
            if ($number !== null) {
                return '<Cell><Data ss:Type="Number">' . $number . '</Data></Cell>';
            }
        }
        return '<Cell><Data ss:Type="String">' . $this->xmlText($value) . '</Data></Cell>';
    }

    protected function normalizeNumberValue($value)
    {
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        $value = trim(str_replace([',', ' '], '', (string)$value));
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return null;
        }
        return $value;
    }

    protected function worksheetName($name)
    {
        $name = preg_replace('/[\[\]\:\*\?\/\\\\]/u', '', (string)$name);
        $name = trim($name);
        if ($name === '') {
            $name = '导出数据';
        }
        return mb_substr($name, 0, 31, 'UTF-8');
    }

    protected function contentDisposition($filename)
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        if ($fallback === '' || $fallback === '.xls') {
            $fallback = 'export.xls';
        }
        return 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }

    protected function xmlText($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
