<?php

namespace app\finance\service\casefund\importing;

/**
 * 案款导入查重和持久化编排；事务、校验和审计由调用方控制。
 */
class CaseFundImportPersistence
{
    protected $hooks;
    protected $tables;
    protected $paymentFields;

    public function __construct(array $hooks, array $tables, array $paymentFields)
    {
        $requiredHooks = [
            'get_db',
            'account_set_id',
            'new_id',
            'prepare_create',
            'prepare_update',
            'payment_duplicate_key',
            'remember_payment_existing',
            'has_active_generated_voucher',
        ];
        foreach ($requiredHooks as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款导入持久化服务缺少回调：' . $name);
            }
        }
        foreach (['payment', 'refund', 'unknown_payment', 'distribution'] as $name) {
            if (empty($tables[$name])) {
                throw new \InvalidArgumentException('案款导入持久化服务缺少数据表：' . $name);
            }
        }
        $this->hooks = $hooks;
        $this->tables = $tables;
        $this->paymentFields = $paymentFields;
    }

    public function loadExistingPaymentsForImport($rows, $useReceiptNoAsDuplicateKey = true)
    {
        $fingerprints = [];
        $receiptNos = [];
        $accountNos = [];
        foreach ($rows as $row) {
            $fingerprint = trim((string)($row['source_fingerprint'] ?? ''));
            if ($fingerprint !== '') {
                $fingerprints[] = $fingerprint;
            }
            $receiptNo = trim((string)($row['receipt_no'] ?? ''));
            if ($useReceiptNoAsDuplicateKey && $receiptNo !== '') {
                $receiptNos[] = $receiptNo;
                continue;
            }
            if (!$useReceiptNoAsDuplicateKey && $receiptNo !== '' && $fingerprint !== '') {
                continue;
            }
            $accountNo = trim((string)($row['bank_account_no'] ?? ''));
            if ($accountNo !== '') {
                $accountNos[] = $accountNo;
            }
        }

        $existing = [];
        if (!empty($fingerprints)) {
            // 支付来源指纹有唯一索引，必须包含软删除行，以便重新导入时恢复原记录。
            $fingerprintRows = $this->db($this->tables['payment'])->where([
                'account_set_id' => $this->accountSetId(),
            ])->where('source_fingerprint', 'in', array_values(array_unique($fingerprints)))
                ->field($this->paymentFields)
                ->select();
            foreach ($fingerprintRows as $row) {
                $this->rememberPaymentExisting($existing, $row, $useReceiptNoAsDuplicateKey);
            }
        }
        if (!empty($receiptNos)) {
            $receiptRows = $this->db($this->tables['payment'])->where([
                'account_set_id' => $this->accountSetId(),
                'del_flag' => 0,
            ])->where('receipt_no', 'in', array_values(array_unique($receiptNos)))
                ->field($this->paymentFields)
                ->select();
            foreach ($receiptRows as $row) {
                $this->rememberPaymentExisting($existing, $row, $useReceiptNoAsDuplicateKey);
            }
        }
        if (!empty($accountNos)) {
            $accountRows = $this->db($this->tables['payment'])->where([
                'account_set_id' => $this->accountSetId(),
                'del_flag' => 0,
            ])->where('bank_account_no', 'in', array_values(array_unique($accountNos)))
                ->field($this->paymentFields)
                ->select();
            foreach ($accountRows as $row) {
                $this->rememberPaymentExisting($existing, $row, $useReceiptNoAsDuplicateKey);
            }
        }
        return $existing;
    }

    public function loadExistingPaymentsByReceiptNos($receiptNos)
    {
        $receiptNos = array_values(array_unique(array_filter(array_map(function ($value) {
            return trim((string)$value);
        }, $receiptNos))));
        if (empty($receiptNos)) {
            return [];
        }
        return $this->db($this->tables['payment'])->where([
            'account_set_id' => $this->accountSetId(),
            'del_flag' => 0,
        ])->where('receipt_no', 'in', $receiptNos)
            ->field('payment_id,account_set_id,fiscal_year,period,receipt_no,case_no,payer_name,party_name,payment_amount,payment_date,payment_time,source_file_name,source_row_no,source_fingerprint,voucher_status,voucher_id,voucher_no,voucher_period,del_flag')
            ->select();
    }

    public function paymentReceiptNoDuplicateErrors($rows)
    {
        $errors = [];
        $firstRowByReceiptNo = [];
        $rowsByReceiptNo = [];
        foreach ($rows as $row) {
            $receiptNo = trim((string)($row['receipt_no'] ?? ''));
            if ($receiptNo === '') {
                continue;
            }
            $rowNo = $row['source_row_no'] ?? '?';
            if (isset($firstRowByReceiptNo[$receiptNo])) {
                $errors[] = '第' . $rowNo . '行：票据号码【' . $receiptNo . '】与第' . $firstRowByReceiptNo[$receiptNo] . '行重复，请核对导入文件';
            } else {
                $firstRowByReceiptNo[$receiptNo] = $rowNo;
            }
            if (!isset($rowsByReceiptNo[$receiptNo])) {
                $rowsByReceiptNo[$receiptNo] = [];
            }
            $rowsByReceiptNo[$receiptNo][] = $row;
        }

        if (empty($rowsByReceiptNo)) {
            return $errors;
        }

        foreach ($this->loadExistingPaymentsByReceiptNos(array_keys($rowsByReceiptNo)) as $existingRow) {
            if ($this->canOverwriteGeneratedBusinessRow($existingRow)) {
                continue;
            }
            $receiptNo = trim((string)($existingRow['receipt_no'] ?? ''));
            if ($receiptNo === '' || !isset($rowsByReceiptNo[$receiptNo])) {
                continue;
            }
            foreach ($rowsByReceiptNo[$receiptNo] as $importRow) {
                $rowNo = $importRow['source_row_no'] ?? '?';
                $errors[] = '第' . $rowNo . '行：票据号码【' . $receiptNo . '】已存在'
                    . $this->paymentExistingDuplicateSummary($existingRow)
                    . '，请核对后重新导入';
            }
        }
        return $errors;
    }

    public function paymentExistingDuplicateSummary($row)
    {
        $parts = [];
        if (trim((string)($row['source_file_name'] ?? '')) !== '') {
            $parts[] = '来源文件：' . $row['source_file_name'];
        }
        if (trim((string)($row['source_row_no'] ?? '')) !== '') {
            $parts[] = '原导入第' . $row['source_row_no'] . '行';
        }
        if (trim((string)($row['case_no'] ?? '')) !== '') {
            $parts[] = '案号：' . $row['case_no'];
        }
        if (trim((string)($row['payment_amount'] ?? '')) !== '') {
            $parts[] = '金额：' . $row['payment_amount'];
        }
        $paymentTime = trim((string)($row['payment_time'] ?? ''));
        if ($paymentTime === '') {
            $paymentTime = trim((string)($row['payment_date'] ?? ''));
        }
        if ($paymentTime !== '') {
            $parts[] = '缴费时间：' . $paymentTime;
        }
        $person = trim((string)($row['payer_name'] ?? ''));
        if ($person === '') {
            $person = trim((string)($row['party_name'] ?? ''));
        }
        if ($person !== '') {
            $parts[] = '缴费人/当事人：' . $person;
        }
        if (trim((string)($row['voucher_no'] ?? '')) !== '') {
            $parts[] = '凭证号：' . $row['voucher_no'];
        } elseif (trim((string)($row['voucher_status'] ?? '')) !== '') {
            $parts[] = '凭证状态：' . $row['voucher_status'];
        }
        return empty($parts) ? '' : '（' . implode('，', $parts) . '）';
    }

    public function loadExistingRowsByFingerprint($tableKey, $rows, $storeRows = false)
    {
        $fingerprints = [];
        foreach ($rows as $row) {
            $fingerprint = trim((string)($row['source_fingerprint'] ?? ''));
            if ($fingerprint !== '') {
                $fingerprints[] = $fingerprint;
            }
        }
        $fingerprints = array_values(array_unique($fingerprints));
        if (empty($fingerprints)) {
            return [];
        }
        if (!isset($this->tables[$tableKey])) {
            throw new \InvalidArgumentException('未知案款导入数据表：' . $tableKey);
        }
        $existingRows = $this->db($this->tables[$tableKey])->where([
            'account_set_id' => $this->accountSetId(),
            'del_flag' => 0,
        ])->where('source_fingerprint', 'in', $fingerprints)->select();
        $existing = [];
        foreach ($existingRows as $row) {
            $existing[$row['source_fingerprint']] = $storeRows ? $row : true;
        }
        return $existing;
    }

    public function persistPaymentRows($rows, $existing, $data)
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seen = [];
        $useReceiptNoAsDuplicateKey = (bool)($data['use_receipt_no_as_duplicate_key'] ?? true);
        foreach ($rows as $row) {
            $duplicateKey = $this->callHook('payment_duplicate_key', [$row, $useReceiptNoAsDuplicateKey]);
            if ($duplicateKey !== '' && isset($seen[$duplicateKey])) {
                $skipped++;
                continue;
            }
            if ($duplicateKey !== '') {
                $seen[$duplicateKey] = true;
            }
            if ($duplicateKey !== '' && isset($existing[$duplicateKey])) {
                $existingRow = $existing[$duplicateKey];
                if (!$this->canOverwriteGeneratedBusinessRow($existingRow)) {
                    $skipped++;
                    continue;
                }
                $this->updateExistingPaymentFromImport($existingRow, $row, $data);
                $updated++;
                continue;
            }
            $insert = $this->buildImportInsert($row, 'payment_id', $data);
            $this->db($this->tables['payment'])->insert($insert);
            $created++;
        }
        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    public function persistRefundRows($rows, $existing, $data)
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seen = [];
        foreach ($rows as $row) {
            $fingerprint = $row['source_fingerprint'];
            if (isset($seen[$fingerprint])) {
                $skipped++;
                continue;
            }
            $seen[$fingerprint] = true;
            if (isset($existing[$fingerprint])) {
                $existingRow = $existing[$fingerprint];
                if (!$this->canOverwriteGeneratedBusinessRow($existingRow)) {
                    $skipped++;
                    continue;
                }
                $this->updateExistingRefundFromImport($existingRow, $row, $data);
                $updated++;
                continue;
            }
            $insert = $this->buildImportInsert($row, 'refund_id', $data);
            $this->db($this->tables['refund'])->insert($insert);
            $created++;
        }
        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    public function persistUniqueFingerprintRows($tableKey, $idField, $rows, $existing, $data)
    {
        if (!isset($this->tables[$tableKey])) {
            throw new \InvalidArgumentException('未知案款导入数据表：' . $tableKey);
        }
        $created = 0;
        $skipped = 0;
        $seen = [];
        foreach ($rows as $row) {
            $fingerprint = $row['source_fingerprint'];
            if (isset($existing[$fingerprint]) || isset($seen[$fingerprint])) {
                $skipped++;
                $seen[$fingerprint] = true;
                continue;
            }
            $seen[$fingerprint] = true;
            $insert = $this->buildImportInsert($row, $idField, $data);
            $this->db($this->tables[$tableKey])->insert($insert);
            $created++;
        }
        return ['created' => $created, 'skipped' => $skipped];
    }

    public function updateExistingPaymentFromImport($existingRow, $row, $data)
    {
        $update = $this->buildImportUpdate($row, 'payment_id', $data);
        // 来源指纹命中软删除行时恢复原行，避免支付来源唯一索引冲突。
        $update['del_flag'] = 0;
        $this->db($this->tables['payment'])->where([
            'payment_id' => $existingRow['payment_id'],
            'account_set_id' => $this->accountSetId(),
        ])->update($update);
    }

    public function updateExistingRefundFromImport($existingRow, $row, $data)
    {
        $update = $this->buildImportUpdate($row, 'refund_id', $data);
        $this->db($this->tables['refund'])->where([
            'refund_id' => $existingRow['refund_id'],
            'account_set_id' => $this->accountSetId(),
            'del_flag' => 0,
        ])->update($update);
    }

    public function canOverwriteGeneratedBusinessRow($row)
    {
        $status = $row['voucher_status'] ?? 'UNGENERATED';
        if ($status === 'UNGENERATED') {
            return true;
        }
        return $status === 'GENERATED' && !$this->callHook('has_active_generated_voucher', [$row]);
    }

    protected function buildImportInsert($row, $idField, $data)
    {
        $insert = $row;
        $insert[$idField] = $this->callHook('new_id');
        $insert['account_set_id'] = $this->accountSetId();
        $insert['source_file_name'] = $data['filename'] ?? '';
        $this->resetVoucherFields($insert);
        $insert['remark'] = $data['remark'] ?? '';
        return $this->callHook('prepare_create', [$insert]);
    }

    protected function buildImportUpdate($row, $idField, $data)
    {
        $update = $row;
        unset($update[$idField], $update['account_set_id'], $update['created_by'], $update['created_time']);
        $update['source_file_name'] = $data['filename'] ?? '';
        $this->resetVoucherFields($update);
        $update['remark'] = $data['remark'] ?? '';
        return $this->callHook('prepare_update', [$update]);
    }

    protected function resetVoucherFields(&$row)
    {
        $row['voucher_status'] = 'UNGENERATED';
        $row['voucher_id'] = null;
        $row['voucher_no'] = null;
        $row['voucher_period'] = null;
        $row['voucher_generated_time'] = null;
    }

    protected function rememberPaymentExisting(&$existing, $row, $useReceiptNoAsDuplicateKey)
    {
        $args = [&$existing, $row, $useReceiptNoAsDuplicateKey];
        call_user_func_array($this->hooks['remember_payment_existing'], $args);
    }

    protected function db($table)
    {
        return $this->callHook('get_db', [$table]);
    }

    protected function accountSetId()
    {
        return $this->callHook('account_set_id');
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
