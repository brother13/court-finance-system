<?php

namespace app\finance\service\casefund\importing;

class CaseFundImportIdentity
{
    protected $canonicalAmount;

    public function __construct(callable $canonicalAmount)
    {
        $this->canonicalAmount = $canonicalAmount;
    }

    public function paymentImportDuplicateKey($row, $useReceiptNoAsDuplicateKey = true)
    {
        $receiptNo = trim((string)($row['receipt_no'] ?? ''));
        if ($useReceiptNoAsDuplicateKey && $receiptNo !== '') {
            return 'receipt:' . $receiptNo;
        }
        $fingerprint = trim((string)($row['source_fingerprint'] ?? ''));
        if (!$useReceiptNoAsDuplicateKey && $receiptNo !== '' && $fingerprint !== '') {
            return 'fingerprint:' . $fingerprint;
        }
        $bankAccountNo = trim((string)($row['bank_account_no'] ?? ''));
        if ($bankAccountNo !== '') {
            $amount = call_user_func($this->canonicalAmount, $row['payment_amount'] ?? '0');
            return 'account_amount:' . $bankAccountNo . '|' . $amount;
        }
        return $fingerprint === '' ? '' : 'fingerprint:' . $fingerprint;
    }

    public function rememberPaymentImportExistingRow(&$existing, $row, $useReceiptNoAsDuplicateKey = true)
    {
        $key = $this->paymentImportDuplicateKey($row, $useReceiptNoAsDuplicateKey);
        return $this->rememberPaymentImportExistingRowByKey($existing, $key, $row);
    }

    public function rememberPaymentImportExistingRowByKey(&$existing, $key, $row)
    {
        if ($key === '') {
            return;
        }
        if (!isset($existing[$key])) {
            $existing[$key] = $row;
            return;
        }
        if ($existing[$key]['voucher_status'] === 'UNGENERATED' && $row['voucher_status'] !== 'UNGENERATED') {
            $existing[$key] = $row;
        }
    }

    public function paymentFingerprint($row)
    {
        $parts = [
            $row['case_no'] ?? '',
            $row['payment_time'] ?? '',
            $row['payment_amount'] ?? '',
            $row['receipt_no'] ?? '',
            $row['bank_serial_no'] ?? '',
            $row['payment_order_no'] ?? '',
        ];
        return md5(implode('|', $parts));
    }

    public function refundFingerprint($row)
    {
        $parts = [
            $row['case_no'] ?? '',
            $row['refund_date'] ?? '',
            $row['out_type'] ?? '',
            $row['refund_amount'] ?? '',
            $row['source_receipt_no'] ?? '',
            $row['out_order_no'] ?? '',
            $row['actual_payee_name'] ?? '',
            $row['payee_bank_account_no'] ?? '',
        ];
        return hash('sha256', implode('|', $parts));
    }

    public function unknownPaymentFingerprint($row)
    {
        $parts = [
            $row['transaction_time'] ?? '',
            $row['unknown_amount'] ?? '',
            $row['court_bank_account_no'] ?? '',
            $row['payer_account_no'] ?? '',
            $row['payer_name'] ?? '',
            $row['bank_remark'] ?? '',
            $row['bank_summary'] ?? '',
        ];
        return hash('sha256', implode('|', $parts));
    }

    public function distributionFingerprint($row)
    {
        $parts = [
            $row['distribution_no'] ?? '',
            $row['in_case_no'] ?? '',
            $row['out_case_no'] ?? '',
            $row['invoice_type'] ?? '',
            $row['receipt_no'] ?? '',
            $row['distribution_amount'] ?? '',
            $row['receive_date'] ?? '',
        ];
        return hash('sha256', implode('|', $parts));
    }
}
