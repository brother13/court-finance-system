<?php

namespace app\finance\service\casefund\importing;

class CaseFundImportNormalizer
{
    public function yesNoFlagFromText($text)
    {
        $text = trim((string)$text);
        if ($text === '') {
            return 0;
        }
        if (in_array($text, ['√', '是', 'Y', 'YES', '1', 'true', 'TRUE'], true)) {
            return 1;
        }
        return 0;
    }

    public function normalizeImportCellText($value)
    {
        if (is_float($value) || is_int($value)) {
            return floor((float)$value) == (float)$value ? (string)(int)$value : (string)$value;
        }
        $text = trim((string)$value);
        if (preg_match('/^\d+\.0$/', $text)) {
            return substr($text, 0, -2);
        }
        return $text;
    }

    public function normalizeDateTimeValue($value)
    {
        $text = $this->normalizeImportCellText($value);
        return $this->normalizeDateTimeText($text);
    }

    public function normalizeDateTimeText($text)
    {
        if ($text === '') {
            return '';
        }
        if (is_numeric($text) && (float)$text > 1000) {
            return date('Y-m-d H:i:s', ((float)$text - 25569) * 86400);
        }
        $text = str_replace('/', '-', $text);
        $timestamp = strtotime($text);
        if ($timestamp === false) {
            return '';
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    public function normalizeDateValue($value)
    {
        $datetime = $this->normalizeDateTimeValue($value);
        return $this->dateValueFromDateTime($datetime);
    }

    public function dateValueFromDateTime($datetime)
    {
        return $datetime === '' ? '' : substr($datetime, 0, 10);
    }

    public function nullableDateValue($value)
    {
        $date = $this->normalizeDateValue($value);
        return $this->nullableDateFromDate($date);
    }

    public function nullableDateFromDate($date)
    {
        return $date === '' ? null : $date;
    }

    public function isEmptyPaymentRawRow($raw)
    {
        foreach ($raw as $value) {
            if ($value !== '') {
                return false;
            }
        }
        return true;
    }

    public function isNonDetailPaymentRawRow($raw)
    {
        $caseNo = trim((string)($raw['案号'] ?? ''));
        $paymentDate = trim((string)($raw['缴费日期'] ?? ''));
        $receiptNo = trim((string)($raw['票据号码'] ?? ''));
        $bankSerialNo = trim((string)($raw['银行流水号'] ?? ''));
        return $caseNo === '' && $paymentDate === '' && $receiptNo === '' && $bankSerialNo === '';
    }

    public function isNonDetailRefundRawRow($raw)
    {
        $caseNo = trim((string)($raw['案号'] ?? ''));
        $refundDate = trim((string)($raw['出账日期'] ?? ''));
        $sourceReceiptNo = trim((string)($raw['来源票据号码'] ?? ''));
        $outOrderNo = trim((string)($raw['出账单号'] ?? ''));
        return $caseNo === '' && $refundDate === '' && $sourceReceiptNo === '' && $outOrderNo === '';
    }

    public function isMalformedPaymentRawRow($raw)
    {
        foreach ($raw as $value) {
            $text = (string)$value;
            if (strpos($text, "\0") !== false) {
                return true;
            }
        }
        return false;
    }
}
