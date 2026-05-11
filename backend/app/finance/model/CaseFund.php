<?php

namespace app\finance\model;

use think\Db;

class CaseFund extends Common
{
    const ACTION = 'caseFund';
    const TABLE_PAYMENT = 'fin_case_fund_payment';
    const TABLE_REFUND = 'fin_case_fund_refund';
    const TABLE_BANK_STATEMENT = 'fin_case_fund_bank_statement';
    const TABLE_BANK_RECONCILE = 'fin_case_fund_bank_reconcile';
    const TABLE_SUBJECT_CONFIG = 'fin_case_fund_subject_config';
    const PAYMENT_FIELD = [
        'payment_id', 'account_set_id', 'fiscal_year', 'period', 'case_no', 'confirmed_flag',
        'available_flag', 'business_type', 'payer_name', 'party_name', 'invoice_title',
        'payment_amount', 'register_type', 'trial_case_no', 'payment_date', 'payment_time',
        'receipt_no', 'invoice_date', 'invoice_operator', 'payment_method', 'cashier_name',
        'judge_name', 'clerk_name', 'department_name', 'bank_account_no', 'bank_serial_no',
        'payment_order_no', 'internal_transfer_ticket_no', 'deposit_revoke_flag',
        'source_file_name', 'source_row_no', 'source_fingerprint', 'source_raw_json',
        'voucher_status', 'voucher_id', 'voucher_no', 'voucher_period', 'voucher_generated_time',
        'created_by', 'created_time', 'updated_by', 'updated_time', 'del_flag', 'version', 'remark',
    ];
    const REFUND_FIELD = [
        'refund_id', 'account_set_id', 'fiscal_year', 'period', 'case_no', 'handler_name',
        'clerk_name', 'receipt_no', 'invoice_date', 'refund_date', 'source_receipt_no',
        'source_receipt_date', 'out_order_no', 'out_status', 'out_type', 'litigation_position',
        'party_name', 'refund_amount', 'total_refund_amount', 'payee_party_relation',
        'payment_method', 'actual_payee_name', 'payee_identity_no', 'payee_bank_account_name',
        'payee_bank_account_no', 'payee_bank_name', 'unionpay_no', 'same_bank_flag',
        'handler_note', 'applicant_name', 'source_file_name', 'source_row_no',
        'source_fingerprint', 'source_raw_json', 'voucher_status', 'voucher_id', 'voucher_no',
        'voucher_period', 'voucher_generated_time', 'created_by', 'created_time', 'updated_by',
        'updated_time', 'del_flag', 'version', 'remark',
    ];
    const SUBJECT_CONFIG_FIELD = [
        'config_id', 'account_set_id', 'biz_type', 'voucher_biz_type', 'business_item_type',
        'debit_subject_code', 'credit_subject_code', 'created_by', 'created_time',
        'updated_by', 'updated_time', 'del_flag', 'version', 'remark',
    ];
    const BANK_STATEMENT_FIELD = [
        'statement_id', 'account_set_id', 'fiscal_year', 'period', 'bank_code', 'bank_name',
        'transaction_date', 'transaction_time', 'direction', 'debit_amount', 'credit_amount',
        'balance_amount', 'counterparty_account_no', 'counterparty_account_name',
        'counterparty_bank_name', 'purpose', 'postscript', 'bank_serial_no',
        'reconcile_status', 'source_file_name', 'source_row_no', 'source_fingerprint',
        'source_raw_json', 'created_by', 'created_time', 'updated_by', 'updated_time',
        'del_flag', 'version', 'remark',
    ];
    const BANK_RECONCILE_FIELD = [
        'reconcile_id', 'account_set_id', 'fiscal_year', 'period', 'reconcile_date',
        'statement_id', 'biz_type', 'biz_id', 'biz_no', 'bank_serial_no', 'bank_amount',
        'biz_amount', 'diff_amount', 'match_status', 'match_rule', 'matched_by',
        'matched_time', 'bank_direction', 'bank_summary', 'biz_summary',
        'created_by', 'created_time', 'updated_by', 'updated_time', 'del_flag', 'version', 'remark',
    ];

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'paymentList':
                return $this->paymentList($data);
            case 'paymentImport':
                return $this->paymentImport($data);
            case 'paymentGenerateVoucher':
                return $this->paymentGenerateVoucher($data);
            case 'paymentDelete':
                return $this->paymentDelete($data);
            case 'refundList':
                return $this->refundList($data);
            case 'refundImport':
                return $this->refundImport($data);
            case 'refundGenerateVoucher':
                return $this->refundGenerateVoucher($data);
            case 'refundDelete':
                return $this->refundDelete($data);
            case 'bankStatementList':
                return $this->bankStatementList($data);
            case 'bankStatementImport':
                return $this->bankStatementImport($data);
            case 'bankStatementDelete':
                return $this->bankStatementDelete($data);
            case 'bankReconcileRun':
                return $this->bankReconcileRun($data);
            case 'bankReconcileList':
                return $this->bankReconcileList($data);
            case 'subjectConfigList':
                return $this->subjectConfigList($data);
            case 'subjectConfigSave':
                return $this->subjectConfigSave($data);
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function paymentList($data = [])
    {
        $auth = $this->requirePermission('case_fund:view');
        if ($auth) {
            return $auth;
        }
        $page = $data['page'] ?? input('param.page', 1);
        $pagesize = $data['pagesize'] ?? input('param.pagesize', 50);
        $where = $this->accountWhere();
        $where['fiscal_year'] = $this->currentYear();
        if (!empty($data['period'])) {
            $where['period'] = $data['period'];
        }
        if (!empty($data['voucher_status'])) {
            $where['voucher_status'] = $data['voucher_status'];
        }
        if (!empty($data['date_start'])) {
            $where['payment_date'][] = ['>=', $data['date_start']];
        }
        if (!empty($data['date_end'])) {
            $where['payment_date'][] = ['<=', $data['date_end']];
        }
        $bizType = $this->currentAccountSetBizType();
        $allowedBusinessTypes = $this->allowedPaymentBusinessTypes($bizType);
        if (empty($allowedBusinessTypes)) {
            return $this->error('当前账套类型不支持案款缴费登记：' . $bizType);
        }
        $where['business_type'] = ['in', $allowedBusinessTypes];
        $keyword = trim($data['keyword'] ?? '');
        $query = $this->getdb(self::TABLE_PAYMENT)->where($where);
        if ($keyword !== '') {
            $query->where('case_no|trial_case_no|payer_name|party_name|receipt_no|bank_serial_no|payment_order_no', 'like', '%' . $keyword . '%');
        }
        $totalQuery = $this->getdb(self::TABLE_PAYMENT)->where($where);
        if ($keyword !== '') {
            $totalQuery->where('case_no|trial_case_no|payer_name|party_name|receipt_no|bank_serial_no|payment_order_no', 'like', '%' . $keyword . '%');
        }
        $total = $totalQuery->count();
        $rows = $query->field(self::PAYMENT_FIELD)
            ->order('payment_date asc, source_row_no asc')
            ->page($page, $pagesize)
            ->select();
        return $this->ok(['items' => $rows, 'total' => $total], 'OK', $total);
    }

    public function paymentImport($data = [])
    {
        $auth = $this->requirePermission('case_fund:import');
        if ($auth) {
            return $auth;
        }
        $content = $data['content_base64'] ?? '';
        if ($content === '') {
            return $this->error('导入文件不能为空');
        }
        $binary = base64_decode($content, true);
        if ($binary === false || $binary === '') {
            return $this->error('导入文件内容不正确');
        }

        try {
            $rows = $this->parsePaymentImportRowsFromXls($binary);
        } catch (\Exception $e) {
            return $this->error('解析案款缴费登记失败：' . $e->getMessage());
        }
        if (empty($rows)) {
            return $this->error('导入文件没有案款缴费数据');
        }

        $bizType = $this->currentAccountSetBizType();
        $errors = $this->validatePaymentRows($rows, $bizType);
        if (!empty($errors)) {
            return $this->error($this->paymentImportErrorMessage($errors, $bizType), $errors);
        }

        $existing = $this->loadExistingPaymentsForImport($rows);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seen = [];
        Db::startTrans();
        try {
            foreach ($rows as $row) {
                $duplicateKey = $this->paymentImportDuplicateKey($row);
                if ($duplicateKey !== '' && isset($seen[$duplicateKey])) {
                    $skipped++;
                    continue;
                }
                if ($duplicateKey !== '') {
                    $seen[$duplicateKey] = true;
                }
                if ($duplicateKey !== '' && isset($existing[$duplicateKey])) {
                    $existingRow = $existing[$duplicateKey];
                    if ($existingRow['voucher_status'] !== 'UNGENERATED') {
                        $skipped++;
                        continue;
                    }
                    $this->updateExistingPaymentFromImport($existingRow, $row, $data);
                    $updated++;
                    continue;
                }
                $insert = $row;
                $insert['payment_id'] = uuid();
                $insert['account_set_id'] = $this->accountSetId;
                $insert['source_file_name'] = $data['filename'] ?? '';
                $insert['voucher_status'] = 'UNGENERATED';
                $insert['voucher_id'] = null;
                $insert['voucher_no'] = null;
                $insert['voucher_period'] = null;
                $insert['voucher_generated_time'] = null;
                $insert['remark'] = $data['remark'] ?? '';
                $this->fillCreate($insert);
                $this->getdb(self::TABLE_PAYMENT)->insert($insert);
                $created++;
            }
            $this->logAudit('CASE_FUND_PAYMENT', 'IMPORT', 'IMPORT', null, [
                'filename' => $data['filename'] ?? '',
                'total' => count($rows),
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('保存案款缴费登记失败：' . $e->getMessage());
        }

        return $this->ok([
            'total' => count($rows),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ], '导入成功', count($rows));
    }

    public function refundList($data = [])
    {
        $auth = $this->requirePermission('case_fund:view');
        if ($auth) {
            return $auth;
        }
        $page = $data['page'] ?? input('param.page', 1);
        $pagesize = $data['pagesize'] ?? ($data['pageSize'] ?? input('param.pagesize', 50));
        $where = $this->accountWhere();
        $where['fiscal_year'] = $this->currentYear();
        if (!empty($data['period'])) {
            $where['period'] = $data['period'];
        }
        if (!empty($data['voucher_status'])) {
            $where['voucher_status'] = $data['voucher_status'];
        }
        $dateStart = $data['date_start'] ?? ($data['startDate'] ?? '');
        $dateEnd = $data['date_end'] ?? ($data['endDate'] ?? '');
        if ($dateStart !== '') {
            $where['refund_date'][] = ['>=', $dateStart];
        }
        if ($dateEnd !== '') {
            $where['refund_date'][] = ['<=', $dateEnd];
        }
        if (!empty($data['out_status'])) {
            $where['out_status'] = $data['out_status'];
        }
        $bizType = $this->currentAccountSetBizType();
        $allowedOutTypes = $this->allowedRefundOutTypes($bizType);
        if (empty($allowedOutTypes)) {
            return $this->error('当前账套类型不支持案款退付登记：' . $bizType);
        }
        $where['out_type'] = ['in', $allowedOutTypes];
        $caseNo = trim($data['case_no'] ?? ($data['caseNo'] ?? ''));
        $partyName = trim($data['party_name'] ?? ($data['partyName'] ?? ''));
        $keyword = trim($data['keyword'] ?? '');

        $query = $this->getdb(self::TABLE_REFUND)->where($where);
        $totalQuery = $this->getdb(self::TABLE_REFUND)->where($where);
        $amountQuery = $this->getdb(self::TABLE_REFUND)->where($where);
        foreach ([$query, $totalQuery, $amountQuery] as $item) {
            if ($caseNo !== '') {
                $item->where('case_no', 'like', '%' . $caseNo . '%');
            }
            if ($partyName !== '') {
                $item->where('party_name|actual_payee_name|payee_bank_account_name', 'like', '%' . $partyName . '%');
            }
            if ($keyword !== '') {
                $item->where('case_no|party_name|actual_payee_name|receipt_no|source_receipt_no|out_order_no|payee_bank_account_no', 'like', '%' . $keyword . '%');
            }
        }

        $total = $totalQuery->count();
        $totalAmount = $amountQuery->sum('refund_amount');
        $rows = $query->field(self::REFUND_FIELD)
            ->order('refund_date asc, source_row_no asc')
            ->page($page, $pagesize)
            ->select();
        return $this->ok(['items' => $rows, 'total' => $total, 'total_amount' => $totalAmount], 'OK', $total);
    }

    public function refundImport($data = [])
    {
        $auth = $this->requirePermission('case_fund:import');
        if ($auth) {
            return $auth;
        }
        $content = $data['content_base64'] ?? '';
        if ($content === '') {
            return $this->error('导入文件不能为空');
        }
        $binary = base64_decode($content, true);
        if ($binary === false || $binary === '') {
            return $this->error('导入文件内容不正确');
        }

        try {
            $rows = $this->parseRefundImportRowsFromXls($binary);
        } catch (\Exception $e) {
            return $this->error('解析案款退付登记失败：' . $e->getMessage());
        }
        if (empty($rows)) {
            return $this->error('导入文件没有案款退付数据');
        }

        $bizType = $this->currentAccountSetBizType();
        $errors = $this->validateRefundRows($rows, $bizType);
        if (!empty($errors)) {
            return $this->error($this->refundImportErrorMessage($errors, $bizType), $errors);
        }

        $fingerprints = [];
        foreach ($rows as $row) {
            $fingerprints[] = $row['source_fingerprint'];
        }
        $existing = [];
        if (!empty($fingerprints)) {
            $existingRows = $this->getdb(self::TABLE_REFUND)->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])->where('source_fingerprint', 'in', array_values(array_unique($fingerprints)))->select();
            foreach ($existingRows as $row) {
                $existing[$row['source_fingerprint']] = true;
            }
        }

        $created = 0;
        $skipped = 0;
        $seen = [];
        Db::startTrans();
        try {
            foreach ($rows as $row) {
                $fingerprint = $row['source_fingerprint'];
                if (isset($existing[$fingerprint]) || isset($seen[$fingerprint])) {
                    $skipped++;
                    $seen[$fingerprint] = true;
                    continue;
                }
                $seen[$fingerprint] = true;
                $insert = $row;
                $insert['refund_id'] = uuid();
                $insert['account_set_id'] = $this->accountSetId;
                $insert['source_file_name'] = $data['filename'] ?? '';
                $insert['voucher_status'] = 'UNGENERATED';
                $insert['voucher_id'] = null;
                $insert['voucher_no'] = null;
                $insert['voucher_period'] = null;
                $insert['voucher_generated_time'] = null;
                $insert['remark'] = $data['remark'] ?? '';
                $this->fillCreate($insert);
                $this->getdb(self::TABLE_REFUND)->insert($insert);
                $created++;
            }
            $this->logAudit('CASE_FUND_REFUND', 'IMPORT', 'IMPORT', null, [
                'filename' => $data['filename'] ?? '',
                'total' => count($rows),
                'created' => $created,
                'skipped' => $skipped,
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('保存案款退付登记失败：' . $e->getMessage());
        }

        return $this->ok([
            'total' => count($rows),
            'created' => $created,
            'skipped' => $skipped,
        ], '导入成功', count($rows));
    }

    public function bankStatementList($data = [])
    {
        $auth = $this->requirePermission('case_fund:view');
        if ($auth) {
            return $auth;
        }
        $page = $data['page'] ?? input('param.page', 1);
        $pagesize = $data['pagesize'] ?? ($data['pageSize'] ?? input('param.pagesize', 50));
        $where = $this->accountWhere();
        $where['fiscal_year'] = $this->currentYear();
        if (!empty($data['period'])) {
            $where['period'] = $data['period'];
        }
        if (!empty($data['bank_code'])) {
            $where['bank_code'] = $data['bank_code'];
        }
        if (!empty($data['direction'])) {
            $where['direction'] = $data['direction'];
        }
        if (!empty($data['date_start'])) {
            $where['transaction_date'][] = ['>=', $data['date_start']];
        }
        if (!empty($data['date_end'])) {
            $where['transaction_date'][] = ['<=', $data['date_end']];
        }
        $keyword = trim($data['keyword'] ?? '');
        $query = $this->getdb(self::TABLE_BANK_STATEMENT)->where($where);
        $totalQuery = $this->getdb(self::TABLE_BANK_STATEMENT)->where($where);
        $debitQuery = $this->getdb(self::TABLE_BANK_STATEMENT)->where($where);
        $creditQuery = $this->getdb(self::TABLE_BANK_STATEMENT)->where($where);
        foreach ([$query, $totalQuery, $debitQuery, $creditQuery] as $item) {
            if ($keyword !== '') {
                $item->where('counterparty_account_no|counterparty_account_name|counterparty_bank_name|purpose|postscript|bank_serial_no', 'like', '%' . $keyword . '%');
            }
        }

        $total = $totalQuery->count();
        $debitTotal = $debitQuery->sum('debit_amount');
        $creditTotal = $creditQuery->sum('credit_amount');
        $rows = $query->field(self::BANK_STATEMENT_FIELD)
            ->order('transaction_time asc, source_row_no asc')
            ->page($page, $pagesize)
            ->select();
        return $this->ok([
            'items' => $rows,
            'total' => $total,
            'debit_amount' => $debitTotal,
            'credit_amount' => $creditTotal,
            'banks' => $this->supportedBankStatementBanks(),
        ], 'OK', $total);
    }

    public function bankStatementImport($data = [])
    {
        $auth = $this->requirePermission('case_fund:import');
        if ($auth) {
            return $auth;
        }
        $bankCode = trim((string)($data['bank_code'] ?? ''));
        $banks = $this->supportedBankStatementBanks();
        if ($bankCode === '' || !isset($banks[$bankCode])) {
            return $this->error('请选择正确的银行');
        }
        $rows = $data['rows'] ?? [];
        if (!is_array($rows) || empty($rows)) {
            return $this->error('导入文件没有银行对账单数据');
        }

        $parsedRows = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            $normalized = $this->normalizeBankStatementRow($row, $bankCode, $banks[$bankCode], $index + 1);
            if (!empty($normalized['error'])) {
                $errors[] = $normalized['error'];
                continue;
            }
            $parsedRows[] = $normalized;
        }
        if (!empty($errors)) {
            return $this->error('导入校验失败', $errors);
        }
        if (empty($parsedRows)) {
            return $this->error('导入文件没有可保存的银行对账单数据');
        }

        $fingerprints = [];
        foreach ($parsedRows as $row) {
            $fingerprints[] = $row['source_fingerprint'];
        }
        $existing = [];
        $existingRows = $this->getdb(self::TABLE_BANK_STATEMENT)->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->where('source_fingerprint', 'in', array_values(array_unique($fingerprints)))->select();
        foreach ($existingRows as $row) {
            $existing[$row['source_fingerprint']] = true;
        }

        $created = 0;
        $skipped = 0;
        $seen = [];
        Db::startTrans();
        try {
            foreach ($parsedRows as $row) {
                $fingerprint = $row['source_fingerprint'];
                if (isset($existing[$fingerprint]) || isset($seen[$fingerprint])) {
                    $skipped++;
                    $seen[$fingerprint] = true;
                    continue;
                }
                $seen[$fingerprint] = true;
                $insert = $row;
                $insert['statement_id'] = uuid();
                $insert['account_set_id'] = $this->accountSetId;
                $insert['source_file_name'] = $data['filename'] ?? '';
                $insert['reconcile_status'] = 'UNMATCHED';
                $insert['remark'] = $data['remark'] ?? '';
                $this->fillCreate($insert);
                $this->getdb(self::TABLE_BANK_STATEMENT)->insert($insert);
                $created++;
            }
            $this->logAudit('CASE_FUND_BANK_STATEMENT', 'IMPORT', 'IMPORT', null, [
                'bank_code' => $bankCode,
                'bank_name' => $banks[$bankCode],
                'filename' => $data['filename'] ?? '',
                'total' => count($parsedRows),
                'created' => $created,
                'skipped' => $skipped,
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('保存银行对账单失败：' . $e->getMessage());
        }

        return $this->ok([
            'total' => count($parsedRows),
            'created' => $created,
            'skipped' => $skipped,
        ], '导入成功', count($parsedRows));
    }

    public function paymentDelete($data = [])
    {
        $auth = $this->requirePermission('case_fund:delete');
        if ($auth) {
            return $auth;
        }
        $ids = $this->normalizeIdList($data['payment_ids'] ?? ($data['ids'] ?? []));
        if (empty($ids)) {
            return $this->error('请选择要删除的缴费记录');
        }
        return $this->deleteCaseFundBusinessRows(
            self::TABLE_PAYMENT,
            'payment_id',
            $ids,
            'PAYMENT',
            'CASE_FUND_PAYMENT',
            '缴费记录'
        );
    }

    public function refundDelete($data = [])
    {
        $auth = $this->requirePermission('case_fund:delete');
        if ($auth) {
            return $auth;
        }
        $ids = $this->normalizeIdList($data['refund_ids'] ?? ($data['ids'] ?? []));
        if (empty($ids)) {
            return $this->error('请选择要删除的退付记录');
        }
        return $this->deleteCaseFundBusinessRows(
            self::TABLE_REFUND,
            'refund_id',
            $ids,
            'REFUND',
            'CASE_FUND_REFUND',
            '退付记录'
        );
    }

    public function bankStatementDelete($data = [])
    {
        $auth = $this->requirePermission('case_fund:delete');
        if ($auth) {
            return $auth;
        }
        $ids = $this->normalizeIdList($data['statement_ids'] ?? ($data['ids'] ?? []));
        if (empty($ids)) {
            return $this->error('请选择要删除的银行对账单流水');
        }

        $where = $this->accountWhere();
        $where['fiscal_year'] = $this->currentYear();
        $where['reconcile_status'] = 'UNMATCHED';
        $rows = $this->getdb(self::TABLE_BANK_STATEMENT)
            ->where($where)
            ->where('statement_id', 'in', $ids)
            ->field(self::BANK_STATEMENT_FIELD)
            ->select();
        if (count($rows) !== count($ids)) {
            return $this->error('只能删除未对账的银行对账单流水');
        }
        $reconcileCount = $this->getdb(self::TABLE_BANK_RECONCILE)->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->where('statement_id', 'in', $ids)->count();
        if ((int)$reconcileCount > 0) {
            return $this->error('只能删除未对账的银行对账单流水');
        }

        $update = ['del_flag' => 1];
        $this->fillUpdate($update);
        Db::startTrans();
        try {
            $this->getdb(self::TABLE_BANK_STATEMENT)->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])->where('statement_id', 'in', $ids)->update($update);
            $this->logAudit('CASE_FUND_BANK_STATEMENT', 'DELETE', 'DELETE', $rows, [
                'statement_ids' => $ids,
                'deleted_count' => count($rows),
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('删除银行对账单流水失败：' . $e->getMessage());
        }

        return $this->ok(['deleted_count' => count($rows)], '删除成功', count($rows));
    }

    public function bankReconcileRun($data = [])
    {
        $auth = $this->requirePermission('case_fund:reconcile');
        if ($auth) {
            return $auth;
        }
        $bankCode = trim((string)($data['bank_code'] ?? ''));
        $dateStart = trim((string)($data['date_start'] ?? ''));
        $dateEnd = trim((string)($data['date_end'] ?? ''));
        $where = $this->accountWhere();
        $where['fiscal_year'] = $this->currentYear();
        if ($bankCode !== '') {
            $where['bank_code'] = $bankCode;
        }
        if ($dateStart !== '') {
            $where['transaction_date'][] = ['>=', $dateStart];
        }
        if ($dateEnd !== '') {
            $where['transaction_date'][] = ['<=', $dateEnd];
        }

        $statements = $this->getdb(self::TABLE_BANK_STATEMENT)
            ->where($where)
            ->field(self::BANK_STATEMENT_FIELD)
            ->order('transaction_time asc, source_row_no asc')
            ->select();

        $statementIds = [];
        foreach ($statements as $statement) {
            $statementIds[] = $statement['statement_id'];
        }

        $resultRows = [];
        $matchedBiz = [];
        $statementStatuses = [];

        foreach ($statements as $statement) {
            $direction = $statement['direction'];
            $bankAmount = $direction === 'CREDIT' ? $statement['credit_amount'] : $statement['debit_amount'];
            $bankCents = $this->decimalToCents($bankAmount);
            if ($direction === 'CREDIT') {
                $candidates = $this->paymentCandidatesByBankSerial($statement['bank_serial_no']);
                $bizType = 'PAYMENT';
            } else {
                $candidates = $this->refundCandidatesByOutOrderNo($statement['bank_serial_no']);
                $bizType = 'REFUND';
            }

            if (empty($candidates)) {
                $status = 'BANK_ONLY';
                $resultRows[] = $this->buildBankReconcileRow($statement, $bizType, null, $status, $bankCents, 0);
                $statementStatuses[$statement['statement_id']] = $status;
                continue;
            }

            if (count($candidates) > 1) {
                foreach ($candidates as $candidate) {
                    $bizCents = $this->bankReconcileBizAmountCents($bizType, $candidate);
                    $resultRows[] = $this->buildBankReconcileRow($statement, $bizType, $candidate, 'DUPLICATE', $bankCents, $bizCents);
                    $matchedBiz[$bizType . ':' . $this->bankReconcileBizId($bizType, $candidate)] = true;
                }
                $statementStatuses[$statement['statement_id']] = 'DUPLICATE';
                continue;
            }

            $candidate = $candidates[0];
            $bizCents = $this->bankReconcileBizAmountCents($bizType, $candidate);
            $status = $bankCents === $bizCents ? 'MATCHED' : 'AMOUNT_DIFF';
            $resultRows[] = $this->buildBankReconcileRow($statement, $bizType, $candidate, $status, $bankCents, $bizCents);
            $statementStatuses[$statement['statement_id']] = $status;
            $matchedBiz[$bizType . ':' . $this->bankReconcileBizId($bizType, $candidate)] = true;
        }

        foreach ($this->paymentCandidatesInScope($dateStart, $dateEnd) as $payment) {
            $key = 'PAYMENT:' . $payment['payment_id'];
            if (!isset($matchedBiz[$key]) && !$this->bankStatementExistsBySerial($payment['bank_serial_no'], $bankCode, $dateStart, $dateEnd)) {
                $bizCents = $this->decimalToCents($payment['payment_amount']);
                $resultRows[] = $this->buildBankReconcileRow(null, 'PAYMENT', $payment, 'BIZ_ONLY', 0, $bizCents);
            }
        }

        foreach ($this->refundCandidatesInScope($dateStart, $dateEnd) as $refund) {
            $key = 'REFUND:' . $refund['refund_id'];
            if (!isset($matchedBiz[$key]) && !$this->bankStatementExistsBySerial($refund['out_order_no'], $bankCode, $dateStart, $dateEnd)) {
                $bizCents = $this->decimalToCents($refund['refund_amount']);
                $resultRows[] = $this->buildBankReconcileRow(null, 'REFUND', $refund, 'BIZ_ONLY', 0, $bizCents);
            }
        }

        $counts = $this->emptyBankReconcileCounts();
        Db::startTrans();
        try {
            $this->softDeleteBankReconcileScope($dateStart, $dateEnd);
            if (!empty($statementIds)) {
                $this->getdb(self::TABLE_BANK_STATEMENT)
                    ->where(['account_set_id' => $this->accountSetId])
                    ->where('statement_id', 'in', $statementIds)
                    ->update([
                        'reconcile_status' => 'UNMATCHED',
                        'updated_by' => $this->userid,
                        'updated_time' => $this->now(),
                    ]);
            }

            foreach ($resultRows as $row) {
                $this->fillCreate($row);
                $this->getdb(self::TABLE_BANK_RECONCILE)->insert($row);
                $counts[$row['match_status']] = ($counts[$row['match_status']] ?? 0) + 1;
            }
            foreach ($statementStatuses as $statementId => $status) {
                $this->getdb(self::TABLE_BANK_STATEMENT)
                    ->where(['account_set_id' => $this->accountSetId, 'statement_id' => $statementId])
                    ->update([
                        'reconcile_status' => $status,
                        'updated_by' => $this->userid,
                        'updated_time' => $this->now(),
                    ]);
            }
            $this->logAudit('CASE_FUND_BANK_RECONCILE', 'RUN', 'RECONCILE', null, [
                'bank_code' => $bankCode,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'counts' => $counts,
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('银行对账失败：' . $e->getMessage());
        }

        return $this->ok([
            'total' => count($resultRows),
            'counts' => $counts,
        ], '自动对账完成', count($resultRows));
    }

    public function bankReconcileList($data = [])
    {
        $auth = $this->requirePermission('case_fund:view');
        if ($auth) {
            return $auth;
        }
        $page = $data['page'] ?? input('param.page', 1);
        $pagesize = $data['pagesize'] ?? ($data['pageSize'] ?? input('param.pagesize', 50));
        $where = $this->accountWhere();
        $where['fiscal_year'] = $this->currentYear();
        if (!empty($data['status'])) {
            $where['match_status'] = $data['status'];
        }
        if (!empty($data['biz_type'])) {
            $where['biz_type'] = $data['biz_type'];
        }
        if (!empty($data['date_start'])) {
            $where['reconcile_date'][] = ['>=', $data['date_start']];
        }
        if (!empty($data['date_end'])) {
            $where['reconcile_date'][] = ['<=', $data['date_end']];
        }
        $keyword = trim($data['keyword'] ?? '');
        $query = $this->getdb(self::TABLE_BANK_RECONCILE)->where($where);
        $totalQuery = $this->getdb(self::TABLE_BANK_RECONCILE)->where($where);
        foreach ([$query, $totalQuery] as $item) {
            if ($keyword !== '') {
                $item->where('bank_serial_no|biz_no|bank_summary|biz_summary', 'like', '%' . $keyword . '%');
            }
        }
        $total = $totalQuery->count();
        $rows = $query->field(self::BANK_RECONCILE_FIELD)
            ->order('reconcile_date asc, bank_serial_no asc')
            ->page($page, $pagesize)
            ->select();
        return $this->ok([
            'items' => $rows,
            'total' => $total,
            'summary' => $this->bankReconcileSummary($where),
        ], 'OK', $total);
    }

    public function subjectConfigList($data = [])
    {
        $auth = $this->requirePermission('case_fund:subject_config');
        if ($auth) {
            return $auth;
        }
        $voucherBizType = $this->normalizeVoucherBizType($data['voucher_biz_type'] ?? ($data['voucherBizType'] ?? ''));
        if ($voucherBizType === '') {
            return $this->error('科目配置类型不能为空');
        }
        $bizType = $this->currentAccountSetBizType();
        $businessItems = $this->allowedSubjectConfigItems($bizType, $voucherBizType);
        if (empty($businessItems)) {
            return $this->error('当前账套类型不支持该科目配置：' . $bizType);
        }
        $generateVoucherByDayFlag = $this->subjectConfigAccountSetFlag();

        $configs = [];
        $rows = $this->getdb(self::TABLE_SUBJECT_CONFIG)->where([
            'account_set_id' => $this->accountSetId,
            'biz_type' => $bizType,
            'voucher_biz_type' => $voucherBizType,
            'del_flag' => 0,
        ])->where('business_item_type', 'in', $businessItems)->field(self::SUBJECT_CONFIG_FIELD)->select();
        foreach ($rows as $row) {
            $configs[$row['business_item_type']] = $row;
        }

        $subjectCodes = [];
        foreach ($configs as $config) {
            if (!empty($config['debit_subject_code'])) {
                $subjectCodes[] = $config['debit_subject_code'];
            }
            if (!empty($config['credit_subject_code'])) {
                $subjectCodes[] = $config['credit_subject_code'];
            }
        }
        $subjectNames = $this->subjectNameMap($subjectCodes);

        $items = [];
        foreach ($businessItems as $businessItem) {
            $config = $configs[$businessItem] ?? [];
            $debitSubjectCode = $config['debit_subject_code'] ?? '';
            $creditSubjectCode = $config['credit_subject_code'] ?? '';
            $items[] = [
                'config_id' => $config['config_id'] ?? '',
                'account_set_id' => $this->accountSetId,
                'biz_type' => $bizType,
                'voucher_biz_type' => $voucherBizType,
                'business_item_type' => $businessItem,
                'debit_subject_code' => $debitSubjectCode,
                'debit_subject_name' => $subjectNames[$debitSubjectCode] ?? '',
                'credit_subject_code' => $creditSubjectCode,
                'credit_subject_name' => $subjectNames[$creditSubjectCode] ?? '',
            ];
        }
        return $this->ok([
            'items' => $items,
            'biz_type' => $bizType,
            'voucher_biz_type' => $voucherBizType,
            'generate_voucher_by_day_flag' => $generateVoucherByDayFlag,
        ], 'OK', count($items));
    }

    public function subjectConfigSave($data = [])
    {
        $auth = $this->requirePermission('case_fund:subject_config');
        if ($auth) {
            return $auth;
        }
        $voucherBizType = $this->normalizeVoucherBizType($data['voucher_biz_type'] ?? ($data['voucherBizType'] ?? ''));
        if ($voucherBizType === '') {
            return $this->error('科目配置类型不能为空');
        }
        $bizType = $this->currentAccountSetBizType();
        $allowedItems = $this->allowedSubjectConfigItems($bizType, $voucherBizType);
        if (empty($allowedItems)) {
            return $this->error('当前账套类型不支持该科目配置：' . $bizType);
        }
        $items = $data['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            return $this->error('科目配置明细不能为空');
        }
        $generateVoucherByDayFlag = $this->normalizeDailyVoucherFlag($data['generate_voucher_by_day_flag'] ?? ($data['generateVoucherByDayFlag'] ?? $this->subjectConfigAccountSetFlag()));

        $before = [];
        $after = [];
        $accountSetBefore = null;
        $accountSetAfter = null;
        $seen = [];
        Db::startTrans();
        try {
            list($accountSetBefore, $accountSetAfter) = $this->saveSubjectConfigAccountSetFlag($generateVoucherByDayFlag);
            foreach ($items as $item) {
                $businessItemType = trim($item['business_item_type'] ?? ($item['businessItemType'] ?? ''));
                $debitSubjectCode = trim($item['debit_subject_code'] ?? ($item['debitSubjectCode'] ?? ''));
                $creditSubjectCode = trim($item['credit_subject_code'] ?? ($item['creditSubjectCode'] ?? ''));
                if ($businessItemType === '') {
                    throw new \Exception('业务种类不能为空');
                }
                if (isset($seen[$businessItemType])) {
                    throw new \Exception('业务种类重复配置：' . $businessItemType);
                }
                $seen[$businessItemType] = true;
                if (!in_array($businessItemType, $allowedItems, true)) {
                    throw new \Exception('当前账套不允许配置业务种类：' . $businessItemType);
                }
                $debitCheck = $this->validateVoucherSubjectCode($debitSubjectCode, '借方科目');
                if ($debitCheck !== null) {
                    throw new \Exception($debitCheck);
                }
                $creditCheck = $this->validateVoucherSubjectCode($creditSubjectCode, '贷方科目');
                if ($creditCheck !== null) {
                    throw new \Exception($creditCheck);
                }

                $where = [
                    'account_set_id' => $this->accountSetId,
                    'biz_type' => $bizType,
                    'voucher_biz_type' => $voucherBizType,
                    'business_item_type' => $businessItemType,
                ];
                $existing = $this->getdb(self::TABLE_SUBJECT_CONFIG)->where($where)->find();
                $save = [
                    'debit_subject_code' => $debitSubjectCode,
                    'credit_subject_code' => $creditSubjectCode,
                    'remark' => $item['remark'] ?? '',
                    'del_flag' => 0,
                ];
                if ($existing) {
                    $before[] = $existing;
                    $this->fillUpdate($save);
                    $this->getdb(self::TABLE_SUBJECT_CONFIG)->where(['config_id' => $existing['config_id']])->update($save);
                    $after[] = array_merge($existing, $save);
                } else {
                    $insert = array_merge($where, $save);
                    $insert['config_id'] = uuid();
                    $this->fillCreate($insert);
                    $this->getdb(self::TABLE_SUBJECT_CONFIG)->insert($insert);
                    $after[] = $insert;
                }
            }
            $this->logAudit('CASE_FUND_SUBJECT_CONFIG', $voucherBizType . '@' . $bizType, 'SAVE', [
                'account_set' => $accountSetBefore,
                'subject_configs' => $before,
            ], [
                'account_set' => $accountSetAfter,
                'subject_configs' => $after,
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('保存科目配置失败：' . $e->getMessage());
        }

        return $this->ok(['saved' => count($after)], '保存成功', count($after));
    }

    public function paymentGenerateVoucher($data = [])
    {
        $auth = $this->requirePermission('case_fund:generate_voucher');
        if ($auth) {
            return $auth;
        }

        $paymentIds = $data['payment_ids'] ?? [];
        if (!is_array($paymentIds) || empty($paymentIds)) {
            return $this->error('请选择要生成凭证的缴费记录');
        }

        $rows = $this->getdb(self::TABLE_PAYMENT)
            ->where(['account_set_id' => $this->accountSetId, 'fiscal_year' => $this->currentYear(), 'del_flag' => 0])
            ->where('payment_id', 'in', $paymentIds)
            ->field(self::PAYMENT_FIELD)
            ->select();

        if (empty($rows)) {
            return $this->error('所选缴费记录不存在');
        }

        foreach ($rows as $row) {
            if ($row['voucher_status'] !== 'UNGENERATED') {
                return $this->error('案号【' . $row['case_no'] . '】的缴费记录已生成凭证，不允许重复生成');
            }
        }

        $bizType = $this->currentAccountSetBizType();
        $configRows = $this->getdb(self::TABLE_SUBJECT_CONFIG)
            ->where([
                'account_set_id' => $this->accountSetId,
                'biz_type' => $bizType,
                'voucher_biz_type' => 'PAYMENT',
                'del_flag' => 0,
            ])
            ->field(self::SUBJECT_CONFIG_FIELD)
            ->select();

        $subjectConfigMap = [];
        foreach ($configRows as $config) {
            $subjectConfigMap[$config['business_item_type']] = $config;
            if ($config['business_item_type'] === '执行、调解款') {
                $subjectConfigMap['执行、调节款'] = $config;
            }
            if ($config['business_item_type'] === '诉讼费预收') {
                $subjectConfigMap['预收诉讼费'] = $config;
            }
        }

        foreach ($rows as $row) {
            $businessType = $row['business_type'];
            if (!isset($subjectConfigMap[$businessType])) {
                return $this->error('案号【' . $row['case_no'] . '】的业务类型【' . $businessType . '】未配置借方/贷方科目');
            }
            $debitCheck = $this->validateVoucherSubjectCode($subjectConfigMap[$businessType]['debit_subject_code'], '借方科目');
            if ($debitCheck !== null) {
                return $this->error('案号【' . $row['case_no'] . '】' . $debitCheck);
            }
            $creditCheck = $this->validateVoucherSubjectCode($subjectConfigMap[$businessType]['credit_subject_code'], '贷方科目');
            if ($creditCheck !== null) {
                return $this->error('案号【' . $row['case_no'] . '】' . $creditCheck);
            }
        }

        $byDayFlag = $this->subjectConfigAccountSetFlag();

        $groups = [];
        if ($byDayFlag === 1) {
            foreach ($rows as $row) {
                $date = $row['payment_date'];
                if (!isset($groups[$date])) {
                    $groups[$date] = [];
                }
                $groups[$date][] = $row;
            }
        } else {
            foreach ($rows as $row) {
                $groups[$row['payment_id']] = [$row];
            }
        }

        $generatedCount = 0;
        $voucherInfos = [];

        Db::startTrans();
        try {
            foreach ($groups as $groupKey => $groupRows) {
                $firstRow = $groupRows[0];
                $period = substr($firstRow['payment_date'], 0, 7);
                $voucherDate = $firstRow['payment_date'];

                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $voucherDate)) {
                    throw new \Exception('缴费日期格式不正确：' . $voucherDate);
                }
                if (substr($voucherDate, 0, 7) !== $period) {
                    throw new \Exception('缴费日期不在会计期间内：' . $voucherDate);
                }

                $this->ensureAuxArchives($groupRows);

                $fiscalYear = $this->fiscalYear($period);
                $voucherTable = 'fin_voucher';
                $detailTable = 'fin_voucher_detail';
                $auxTable = 'fin_voucher_aux_value';

                $voucherId = uuid();
                $voucherNo = $this->generateNextVoucherNo($period);

                $totalDebit = 0;
                $detailsData = [];
                $lineNo = 1;

                foreach ($groupRows as $row) {
                    $businessType = $row['business_type'];
                    $config = $subjectConfigMap[$businessType];
                    $summary = $row['case_no'] . ' ' . $row['party_name'];
                    $amountCents = $this->decimalToCents($row['payment_amount']);
                    $amountDecimal = $this->centsToDecimal($amountCents);
                    $totalDebit += $amountCents;

                    $auxValues = [
                        ['aux_type_code' => 'case_no', 'aux_value' => $row['case_no'], 'aux_label' => $row['case_no']],
                        ['aux_type_code' => 'receipt_no', 'aux_value' => $row['receipt_no'] ?? '', 'aux_label' => $row['receipt_no'] ?? ''],
                    ];

                    $debitDetailId = uuid();
                    $debitConfigs = $this->getSubjectAuxConfigs($config['debit_subject_code']);
                    $detailsData[] = [
                        'detail_id' => $debitDetailId,
                        'line_no' => $lineNo++,
                        'subject_code' => $config['debit_subject_code'],
                        'summary' => $summary,
                        'debit_amount' => $amountDecimal,
                        'credit_amount' => '0.00',
                        'verification_status' => $this->needVerification($debitConfigs) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                        'aux_desc' => $this->buildAuxDesc($auxValues),
                        'aux_values' => $auxValues,
                    ];

                    $creditDetailId = uuid();
                    $creditConfigs = $this->getSubjectAuxConfigs($config['credit_subject_code']);
                    $detailsData[] = [
                        'detail_id' => $creditDetailId,
                        'line_no' => $lineNo++,
                        'subject_code' => $config['credit_subject_code'],
                        'summary' => $summary,
                        'debit_amount' => '0.00',
                        'credit_amount' => $amountDecimal,
                        'verification_status' => $this->needVerification($creditConfigs) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                        'aux_desc' => $this->buildAuxDesc($auxValues),
                        'aux_values' => $auxValues,
                    ];
                }

                if ($totalDebit <= 0) {
                    throw new \Exception('凭证金额必须大于0');
                }

                $voucher = [
                    'voucher_id' => $voucherId,
                    'account_set_id' => $this->accountSetId,
                    'fiscal_year' => $fiscalYear,
                    'period' => $period,
                    'voucher_date' => $voucherDate,
                    'voucher_word' => '记',
                    'voucher_no' => $voucherNo,
                    'summary' => $firstRow['case_no'] . ' ' . $firstRow['party_name'],
                    'debit_amount' => $this->centsToDecimal($totalDebit),
                    'credit_amount' => $this->centsToDecimal($totalDebit),
                    'attachment_count' => 0,
                    'status' => 'SUBMITTED',
                    'source_type' => 'BUSINESS',
                    'printed_flag' => '0',
                    'prepared_by' => $this->userid,
                    'prepared_time' => $this->now(),
                    'audit_by' => null,
                    'audit_time' => null,
                    'posted_by' => null,
                    'posted_time' => null,
                    'void_flag' => '0',
                    'remark' => '',
                ];
                $this->fillCreate($voucher);
                $this->getdb($voucherTable)->insert($voucher);

                foreach ($detailsData as $detail) {
                    $auxValues = $detail['aux_values'];
                    unset($detail['aux_values']);

                    $detailRow = [
                        'detail_id' => $detail['detail_id'],
                        'account_set_id' => $this->accountSetId,
                        'fiscal_year' => $fiscalYear,
                        'period' => $period,
                        'voucher_id' => $voucherId,
                        'line_no' => $detail['line_no'],
                        'subject_code' => $detail['subject_code'],
                        'summary' => $detail['summary'],
                        'debit_amount' => $detail['debit_amount'],
                        'credit_amount' => $detail['credit_amount'],
                        'verification_status' => $detail['verification_status'],
                        'aux_desc' => $detail['aux_desc'],
                        'remark' => '',
                    ];
                    $this->fillCreate($detailRow);
                    $this->getdb($detailTable)->insert($detailRow);

                    foreach ($auxValues as $aux) {
                        if (empty($aux['aux_type_code']) || !isset($aux['aux_value']) || $aux['aux_value'] === '') {
                            continue;
                        }
                        $auxRow = [
                            'id' => uuid(),
                            'account_set_id' => $this->accountSetId,
                            'fiscal_year' => $fiscalYear,
                            'period' => $period,
                            'voucher_id' => $voucherId,
                            'detail_id' => $detail['detail_id'],
                            'aux_type_code' => $aux['aux_type_code'],
                            'aux_value' => $aux['aux_value'],
                            'aux_label' => $aux['aux_label'] ?? $aux['aux_value'],
                            'remark' => '',
                        ];
                        $this->fillCreate($auxRow);
                        $this->getdb($auxTable)->insert($auxRow);
                    }
                }

                foreach ($groupRows as $row) {
                    $this->getdb(self::TABLE_PAYMENT)->where([
                        'payment_id' => $row['payment_id'],
                        'account_set_id' => $this->accountSetId,
                    ])->update([
                        'voucher_status' => 'GENERATED',
                        'voucher_id' => $voucherId,
                        'voucher_no' => $voucherNo,
                        'voucher_period' => $period,
                        'voucher_generated_time' => $this->now(),
                        'updated_by' => $this->userid,
                        'updated_time' => $this->now(),
                    ]);
                }

                $generatedCount++;
                $voucherInfos[] = [
                    'voucher_id' => $voucherId,
                    'voucher_no' => $voucherNo,
                    'period' => $period,
                ];
            }

            $this->logAudit('CASE_FUND_PAYMENT', 'VOUCHER_GENERATE', 'GENERATE', null, [
                'payment_count' => count($rows),
                'voucher_count' => $generatedCount,
                'vouchers' => $voucherInfos,
            ]);

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('生成凭证失败：' . $e->getMessage());
        }

        return $this->ok([
            'generated_count' => $generatedCount,
            'payment_count' => count($rows),
            'vouchers' => $voucherInfos,
        ], '成功生成 ' . $generatedCount . ' 张凭证');
    }

    public function refundGenerateVoucher($data = [])
    {
        $auth = $this->requirePermission('case_fund:generate_voucher');
        if ($auth) {
            return $auth;
        }

        $refundIds = $data['refund_ids'] ?? [];
        if (!is_array($refundIds) || empty($refundIds)) {
            return $this->error('请选择要生成凭证的退付记录');
        }

        $rows = $this->getdb(self::TABLE_REFUND)
            ->where(['account_set_id' => $this->accountSetId, 'fiscal_year' => $this->currentYear(), 'del_flag' => 0])
            ->where('refund_id', 'in', $refundIds)
            ->field(self::REFUND_FIELD)
            ->select();

        if (empty($rows)) {
            return $this->error('所选退付记录不存在');
        }

        foreach ($rows as $row) {
            if ($row['voucher_status'] !== 'UNGENERATED') {
                return $this->error('案号【' . $row['case_no'] . '】的退付记录已生成凭证，不允许重复生成');
            }
        }

        $bizType = $this->currentAccountSetBizType();
        $configRows = $this->getdb(self::TABLE_SUBJECT_CONFIG)
            ->where([
                'account_set_id' => $this->accountSetId,
                'biz_type' => $bizType,
                'voucher_biz_type' => 'REFUND',
                'del_flag' => 0,
            ])
            ->field(self::SUBJECT_CONFIG_FIELD)
            ->select();

        $subjectConfigMap = [];
        foreach ($configRows as $config) {
            $subjectConfigMap[$config['business_item_type']] = $config;
        }

        foreach ($rows as $row) {
            $outType = $row['out_type'];
            if (!isset($subjectConfigMap[$outType])) {
                return $this->error('案号【' . $row['case_no'] . '】的出账种类【' . $outType . '】未配置借方/贷方科目');
            }
            $debitCheck = $this->validateVoucherSubjectCode($subjectConfigMap[$outType]['debit_subject_code'], '借方科目');
            if ($debitCheck !== null) {
                return $this->error('案号【' . $row['case_no'] . '】' . $debitCheck);
            }
            $creditCheck = $this->validateVoucherSubjectCode($subjectConfigMap[$outType]['credit_subject_code'], '贷方科目');
            if ($creditCheck !== null) {
                return $this->error('案号【' . $row['case_no'] . '】' . $creditCheck);
            }
        }

        $byDayFlag = $this->subjectConfigAccountSetFlag();

        $groups = [];
        if ($byDayFlag === 1) {
            foreach ($rows as $row) {
                $date = $row['refund_date'];
                if (!isset($groups[$date])) {
                    $groups[$date] = [];
                }
                $groups[$date][] = $row;
            }
        } else {
            foreach ($rows as $row) {
                $groups[$row['refund_id']] = [$row];
            }
        }

        $generatedCount = 0;
        $voucherInfos = [];

        Db::startTrans();
        try {
            foreach ($groups as $groupRows) {
                $firstRow = $groupRows[0];
                $period = substr($firstRow['refund_date'], 0, 7);
                $voucherDate = $firstRow['refund_date'];

                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $voucherDate)) {
                    throw new \Exception('出账日期格式不正确：' . $voucherDate);
                }
                if (substr($voucherDate, 0, 7) !== $period) {
                    throw new \Exception('出账日期不在会计期间内：' . $voucherDate);
                }

                $this->ensureAuxArchives($groupRows);

                $fiscalYear = $this->fiscalYear($period);
                $voucherTable = 'fin_voucher';
                $detailTable = 'fin_voucher_detail';
                $auxTable = 'fin_voucher_aux_value';

                $voucherId = uuid();
                $voucherNo = $this->generateNextVoucherNo($period);

                $totalDebit = 0;
                $detailsData = [];
                $lineNo = 1;

                foreach ($groupRows as $row) {
                    $outType = $row['out_type'];
                    $config = $subjectConfigMap[$outType];
                    $summary = trim($row['case_no'] . ' ' . ($row['party_name'] ?: $row['actual_payee_name']) . ' 退付');
                    $amountCents = $this->decimalToCents($row['refund_amount']);
                    $amountDecimal = $this->centsToDecimal($amountCents);
                    $totalDebit += $amountCents;
                    $receiptNo = $row['source_receipt_no'] ?: ($row['receipt_no'] ?? '');

                    $auxValues = [
                        ['aux_type_code' => 'case_no', 'aux_value' => $row['case_no'], 'aux_label' => $row['case_no']],
                        ['aux_type_code' => 'receipt_no', 'aux_value' => $receiptNo, 'aux_label' => $receiptNo],
                    ];

                    $debitDetailId = uuid();
                    $debitConfigs = $this->getSubjectAuxConfigs($config['debit_subject_code']);
                    $detailsData[] = [
                        'detail_id' => $debitDetailId,
                        'line_no' => $lineNo++,
                        'subject_code' => $config['debit_subject_code'],
                        'summary' => $summary,
                        'debit_amount' => $amountDecimal,
                        'credit_amount' => '0.00',
                        'verification_status' => $this->needVerification($debitConfigs) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                        'aux_desc' => $this->buildAuxDesc($auxValues),
                        'aux_values' => $auxValues,
                    ];

                    $creditDetailId = uuid();
                    $creditConfigs = $this->getSubjectAuxConfigs($config['credit_subject_code']);
                    $detailsData[] = [
                        'detail_id' => $creditDetailId,
                        'line_no' => $lineNo++,
                        'subject_code' => $config['credit_subject_code'],
                        'summary' => $summary,
                        'debit_amount' => '0.00',
                        'credit_amount' => $amountDecimal,
                        'verification_status' => $this->needVerification($creditConfigs) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                        'aux_desc' => $this->buildAuxDesc($auxValues),
                        'aux_values' => $auxValues,
                    ];
                }

                if ($totalDebit <= 0) {
                    throw new \Exception('凭证金额必须大于0');
                }

                $voucher = [
                    'voucher_id' => $voucherId,
                    'account_set_id' => $this->accountSetId,
                    'fiscal_year' => $fiscalYear,
                    'period' => $period,
                    'voucher_date' => $voucherDate,
                    'voucher_word' => '记',
                    'voucher_no' => $voucherNo,
                    'summary' => trim($firstRow['case_no'] . ' ' . ($firstRow['party_name'] ?: $firstRow['actual_payee_name']) . ' 退付'),
                    'debit_amount' => $this->centsToDecimal($totalDebit),
                    'credit_amount' => $this->centsToDecimal($totalDebit),
                    'attachment_count' => 0,
                    'status' => 'SUBMITTED',
                    'source_type' => 'BUSINESS',
                    'printed_flag' => '0',
                    'prepared_by' => $this->userid,
                    'prepared_time' => $this->now(),
                    'audit_by' => null,
                    'audit_time' => null,
                    'posted_by' => null,
                    'posted_time' => null,
                    'void_flag' => '0',
                    'remark' => '',
                ];
                $this->fillCreate($voucher);
                $this->getdb($voucherTable)->insert($voucher);

                foreach ($detailsData as $detail) {
                    $auxValues = $detail['aux_values'];
                    unset($detail['aux_values']);

                    $detailRow = [
                        'detail_id' => $detail['detail_id'],
                        'account_set_id' => $this->accountSetId,
                        'fiscal_year' => $fiscalYear,
                        'period' => $period,
                        'voucher_id' => $voucherId,
                        'line_no' => $detail['line_no'],
                        'subject_code' => $detail['subject_code'],
                        'summary' => $detail['summary'],
                        'debit_amount' => $detail['debit_amount'],
                        'credit_amount' => $detail['credit_amount'],
                        'verification_status' => $detail['verification_status'],
                        'aux_desc' => $detail['aux_desc'],
                        'remark' => '',
                    ];
                    $this->fillCreate($detailRow);
                    $this->getdb($detailTable)->insert($detailRow);

                    foreach ($auxValues as $aux) {
                        if (empty($aux['aux_type_code']) || !isset($aux['aux_value']) || $aux['aux_value'] === '') {
                            continue;
                        }
                        $auxRow = [
                            'id' => uuid(),
                            'account_set_id' => $this->accountSetId,
                            'fiscal_year' => $fiscalYear,
                            'period' => $period,
                            'voucher_id' => $voucherId,
                            'detail_id' => $detail['detail_id'],
                            'aux_type_code' => $aux['aux_type_code'],
                            'aux_value' => $aux['aux_value'],
                            'aux_label' => $aux['aux_label'] ?? $aux['aux_value'],
                            'remark' => '',
                        ];
                        $this->fillCreate($auxRow);
                        $this->getdb($auxTable)->insert($auxRow);
                    }
                }

                foreach ($groupRows as $row) {
                    $this->getdb(self::TABLE_REFUND)->where([
                        'refund_id' => $row['refund_id'],
                        'account_set_id' => $this->accountSetId,
                    ])->update([
                        'voucher_status' => 'GENERATED',
                        'voucher_id' => $voucherId,
                        'voucher_no' => $voucherNo,
                        'voucher_period' => $period,
                        'voucher_generated_time' => $this->now(),
                        'updated_by' => $this->userid,
                        'updated_time' => $this->now(),
                    ]);
                }

                $generatedCount++;
                $voucherInfos[] = [
                    'voucher_id' => $voucherId,
                    'voucher_no' => $voucherNo,
                    'period' => $period,
                ];
            }

            $this->logAudit('CASE_FUND_REFUND', 'VOUCHER_GENERATE', 'GENERATE', null, [
                'refund_count' => count($rows),
                'voucher_count' => $generatedCount,
                'vouchers' => $voucherInfos,
            ]);

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('生成凭证失败：' . $e->getMessage());
        }

        return $this->ok([
            'generated_count' => $generatedCount,
            'refund_count' => count($rows),
            'vouchers' => $voucherInfos,
        ], '成功生成 ' . $generatedCount . ' 张凭证');
    }

    protected function ensureAuxArchives($rows)
    {
        $caseNos = [];
        $receiptNos = [];
        foreach ($rows as $row) {
            if (!empty($row['case_no'])) {
                $caseNos[] = $row['case_no'];
            }
            if (!empty($row['receipt_no'])) {
                $receiptNos[] = $row['receipt_no'];
            }
            if (!empty($row['source_receipt_no'])) {
                $receiptNos[] = $row['source_receipt_no'];
            }
        }
        $this->ensureAuxType('case_no', '案号');
        $this->ensureAuxType('receipt_no', '收据号');
        $this->ensureAuxArchivesByType('case_no', array_unique($caseNos));
        $this->ensureAuxArchivesByType('receipt_no', array_unique($receiptNos));
    }

    protected function ensureAuxType($typeCode, $typeName)
    {
        $exists = $this->getdb('fin_aux_type')->where([
            'account_set_id' => $this->accountSetId,
            'aux_type_code' => $typeCode,
            'del_flag' => 0,
        ])->find();
        if (!$exists) {
            $this->getdb('fin_aux_type')->insert([
                'aux_type_id' => uuid(),
                'account_set_id' => $this->accountSetId,
                'aux_type_code' => $typeCode,
                'aux_type_name' => $typeName,
                'value_source' => 'MANUAL',
                'required_flag' => 0,
                'status' => 1,
                'del_flag' => 0,
                'created_by' => $this->userid,
                'created_time' => $this->now(),
                'updated_by' => $this->userid,
                'updated_time' => $this->now(),
                'version' => 0,
            ]);
        }
    }

    protected function ensureAuxArchivesByType($typeCode, $codes)
    {
        foreach ($codes as $code) {
            if ($code === '') {
                continue;
            }
            $exists = $this->getdb('fin_aux_archive')->where([
                'account_set_id' => $this->accountSetId,
                'aux_type_code' => $typeCode,
                'archive_code' => $code,
                'del_flag' => 0,
            ])->find();
            if (!$exists) {
                $this->getdb('fin_aux_archive')->insert([
                    'archive_id' => uuid(),
                    'account_set_id' => $this->accountSetId,
                    'aux_type_code' => $typeCode,
                    'archive_code' => $code,
                    'archive_name' => $code,
                    'status' => 1,
                    'del_flag' => 0,
                    'created_by' => $this->userid,
                    'created_time' => $this->now(),
                    'updated_by' => $this->userid,
                    'updated_time' => $this->now(),
                    'version' => 0,
                ]);
            }
        }
    }

    protected function getSubjectAuxConfigs($subjectCode)
    {
        return $this->getdb('fin_subject_aux_config')->where([
            'account_set_id' => $this->accountSetId,
            'subject_code' => $subjectCode,
            'del_flag' => 0,
        ])->select();
    }

    protected function needVerification($configs)
    {
        foreach ($configs as $config) {
            if ((int)$config['verification_flag'] === 1) {
                return true;
            }
        }
        return false;
    }

    protected function buildAuxDesc($auxValues)
    {
        $parts = [];
        foreach ($auxValues as $aux) {
            if (empty($aux['aux_type_code']) || !isset($aux['aux_value']) || $aux['aux_value'] === '') {
                continue;
            }
            $parts[] = $aux['aux_type_code'] . ':' . ($aux['aux_label'] ?? $aux['aux_value']);
        }
        return implode('; ', $parts);
    }

    protected function generateNextVoucherNo($period)
    {
        $where = [
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $this->fiscalYear($period),
            'period' => $period,
            'del_flag' => 0,
        ];
        $row = $this->getdb('fin_voucher_no_sequence')->where($where)->lock(true)->find();
        if (!$row) {
            $data = [
                'sequence_id' => uuid(),
                'account_set_id' => $this->accountSetId,
                'fiscal_year' => $this->fiscalYear($period),
                'period' => $period,
                'current_no' => 1,
                'created_by' => $this->userid,
                'created_time' => $this->now(),
                'updated_by' => $this->userid,
                'updated_time' => $this->now(),
                'version' => 0,
            ];
            $this->getdb('fin_voucher_no_sequence')->insert($data);
            return 1;
        }
        $nextNo = (int)$row['current_no'] + 1;
        $this->getdb('fin_voucher_no_sequence')->where($where)->update([
            'current_no' => $nextNo,
            'updated_by' => $this->userid,
            'updated_time' => $this->now(),
        ]);
        return $nextNo;
    }

    protected function parsePaymentImportRowsFromXls($binary)
    {
        $cells = $this->parseBiffCells($this->readOleWorkbookStream($binary));
        if (empty($cells)) {
            throw new \Exception('文件中没有可识别的工作表数据');
        }
        $maxRow = -1;
        $maxCol = -1;
        foreach ($cells as $key => $value) {
            list($row, $col) = array_map('intval', explode(':', $key));
            $maxRow = max($maxRow, $row);
            $maxCol = max($maxCol, $col);
        }

        $headers = [];
        for ($col = 0; $col <= $maxCol; $col++) {
            $headers[$col] = $this->normalizeImportCellText($cells['0:' . $col] ?? '');
        }
        $required = [
            '案号', '确', '可', '业务类型', '缴费人', '当事人', '开票抬头', '金额',
            '登记类型', '前审案号', '缴费日期', '票据号码', '开票日期', '开票员',
            '收费方式', '收费员', '承办法官', '书记员', '承办部门', '收款账号',
            '银行流水号', '缴费单号', '内转支出票号', '是否提存撤销',
        ];
        $index = [];
        foreach ($required as $name) {
            $found = array_search($name, $headers, true);
            if ($found === false) {
                throw new \Exception('缺少表头：' . $name);
            }
            $index[$name] = $found;
        }

        $rows = [];
        for ($row = 1; $row <= $maxRow; $row++) {
            $raw = [];
            foreach ($index as $name => $col) {
                $raw[$name] = $this->normalizeImportCellText($cells[$row . ':' . $col] ?? '');
            }
            if ($this->isEmptyPaymentRawRow($raw)) {
                continue;
            }
            if ($this->isMalformedPaymentRawRow($raw)) {
                continue;
            }
            if ($this->isNonDetailPaymentRawRow($raw)) {
                continue;
            }
            $invoiceDate = $this->nullableDateValue($raw['开票日期']);
            $paymentTime = $this->normalizeDateTimeValue($raw['缴费日期']);
            $paymentDate = $paymentTime === '' ? '' : substr($paymentTime, 0, 10);
            $amount = $this->centsToDecimal($this->decimalToCents($raw['金额']));
            $period = $paymentDate === '' ? '' : substr($paymentDate, 0, 7);
            $parsed = [
                'fiscal_year' => $paymentDate === '' ? '' : substr($paymentDate, 0, 4),
                'period' => $period,
                'case_no' => $raw['案号'],
                'confirmed_flag' => $this->yesNoFlagFromText($raw['确']),
                'available_flag' => $this->yesNoFlagFromText($raw['可']),
                'business_type' => $raw['业务类型'],
                'payer_name' => $raw['缴费人'],
                'party_name' => $raw['当事人'],
                'invoice_title' => $raw['开票抬头'],
                'payment_amount' => $amount,
                'register_type' => $raw['登记类型'],
                'trial_case_no' => $raw['前审案号'],
                'payment_date' => $paymentDate,
                'payment_time' => $paymentTime,
                'receipt_no' => $raw['票据号码'],
                'invoice_date' => $invoiceDate,
                'invoice_operator' => $raw['开票员'],
                'payment_method' => $raw['收费方式'],
                'cashier_name' => $raw['收费员'],
                'judge_name' => $raw['承办法官'],
                'clerk_name' => $raw['书记员'],
                'department_name' => $raw['承办部门'],
                'bank_account_no' => $raw['收款账号'],
                'bank_serial_no' => $raw['银行流水号'],
                'payment_order_no' => $raw['缴费单号'],
                'internal_transfer_ticket_no' => $raw['内转支出票号'],
                'deposit_revoke_flag' => $this->yesNoFlagFromText($raw['是否提存撤销']),
                'source_row_no' => $row + 1,
                'source_raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE),
            ];
            $parsed['source_fingerprint'] = $this->paymentFingerprint($parsed);
            $rows[] = $parsed;
        }
        return $rows;
    }

    protected function parseRefundImportRowsFromXls($binary)
    {
        $cells = $this->parseBiffCells($this->readOleWorkbookStream($binary));
        if (empty($cells)) {
            throw new \Exception('文件中没有可识别的工作表数据');
        }
        $maxRow = -1;
        $maxCol = -1;
        foreach ($cells as $key => $value) {
            list($row, $col) = array_map('intval', explode(':', $key));
            $maxRow = max($maxRow, $row);
            $maxCol = max($maxCol, $col);
        }

        $headers = [];
        for ($col = 0; $col <= $maxCol; $col++) {
            $headers[$col] = $this->normalizeImportCellText($cells['0:' . $col] ?? '');
        }
        $required = [
            '案号', '经办人', '书记员', '票据号码', '开票日期', '出账日期',
            '来源票据号码', '来源票据开票日期', '出账单号', '出账状态', '出账种类',
            '诉讼地位', '当事人', '出账金额', '总出账金额', '收款人与当事人关系',
            '支付方式', '实际收款人', '身份证号/企业', '收款人银行户名', '收款账号',
            '开户银行', '银联号', '是否本行', '承办人情况说明', '申请人',
        ];
        $index = [];
        foreach ($required as $name) {
            $found = array_search($name, $headers, true);
            if ($found === false) {
                throw new \Exception('缺少表头：' . $name);
            }
            $index[$name] = $found;
        }

        $rows = [];
        for ($row = 1; $row <= $maxRow; $row++) {
            $raw = [];
            foreach ($index as $name => $col) {
                $raw[$name] = $this->normalizeImportCellText($cells[$row . ':' . $col] ?? '');
            }
            if ($this->isEmptyPaymentRawRow($raw)) {
                continue;
            }
            if ($this->isMalformedPaymentRawRow($raw)) {
                continue;
            }
            if ($this->isNonDetailRefundRawRow($raw)) {
                continue;
            }
            $refundDate = $this->normalizeDateValue($raw['出账日期']);
            $invoiceDate = $this->nullableDateValue($raw['开票日期']);
            $sourceReceiptDate = $this->nullableDateValue($raw['来源票据开票日期']);
            $refundAmount = $this->centsToDecimal($this->decimalToCents($raw['出账金额']));
            $totalRefundAmount = $this->centsToDecimal($this->decimalToCents($raw['总出账金额']));
            $period = $refundDate === '' ? '' : substr($refundDate, 0, 7);
            $parsed = [
                'fiscal_year' => $refundDate === '' ? '' : substr($refundDate, 0, 4),
                'period' => $period,
                'case_no' => $raw['案号'],
                'handler_name' => $raw['经办人'],
                'clerk_name' => $raw['书记员'],
                'receipt_no' => $raw['票据号码'],
                'invoice_date' => $invoiceDate,
                'refund_date' => $refundDate,
                'source_receipt_no' => $raw['来源票据号码'],
                'source_receipt_date' => $sourceReceiptDate,
                'out_order_no' => $raw['出账单号'],
                'out_status' => $raw['出账状态'],
                'out_type' => $raw['出账种类'],
                'litigation_position' => $raw['诉讼地位'],
                'party_name' => $raw['当事人'],
                'refund_amount' => $refundAmount,
                'total_refund_amount' => $totalRefundAmount,
                'payee_party_relation' => $raw['收款人与当事人关系'],
                'payment_method' => $raw['支付方式'],
                'actual_payee_name' => $raw['实际收款人'],
                'payee_identity_no' => $raw['身份证号/企业'],
                'payee_bank_account_name' => $raw['收款人银行户名'],
                'payee_bank_account_no' => $raw['收款账号'],
                'payee_bank_name' => $raw['开户银行'],
                'unionpay_no' => $raw['银联号'],
                'same_bank_flag' => $raw['是否本行'],
                'handler_note' => $raw['承办人情况说明'],
                'applicant_name' => $raw['申请人'],
                'source_row_no' => $row + 1,
                'source_raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE),
            ];
            $parsed['source_fingerprint'] = $this->refundFingerprint($parsed);
            $rows[] = $parsed;
        }
        return $rows;
    }

    protected function validatePaymentRows($rows, $bizType = '')
    {
        $errors = [];
        $allowedBusinessTypes = $this->allowedPaymentBusinessTypes($bizType);
        foreach ($rows as $row) {
            $errors = array_merge($errors, $this->paymentRowValidationErrors($row, $bizType, $allowedBusinessTypes));
        }
        return $errors;
    }

    protected function paymentRowValidationErrors($row, $bizType, $allowedBusinessTypes = null)
    {
        $errors = [];
        $allowedBusinessTypes = $allowedBusinessTypes === null ? $this->allowedPaymentBusinessTypes($bizType) : $allowedBusinessTypes;
        $prefix = '第' . $row['source_row_no'] . '行：';
        if ($row['case_no'] === '') {
            $errors[] = $prefix . '案号不能为空';
        }
        if ($row['business_type'] === '') {
            $errors[] = $prefix . '业务类型不能为空';
        } elseif (empty($allowedBusinessTypes)) {
            $errors[] = $prefix . '当前账套类型不支持案款缴费登记：' . $bizType;
        } elseif (!in_array($row['business_type'], $allowedBusinessTypes, true)) {
            $errors[] = $prefix . '当前账套不允许导入业务类型【' . $row['business_type'] . '】，允许类型：' . implode('、', $allowedBusinessTypes);
        }
        if ($row['payer_name'] === '' && $row['party_name'] === '') {
            $errors[] = $prefix . '缴费人和当事人不能同时为空';
        }
        if ($this->decimalToCents($row['payment_amount']) <= 0) {
            $errors[] = $prefix . '金额必须大于0';
        }
        if ($row['payment_date'] === '') {
            $errors[] = $prefix . '缴费日期不能为空或格式不正确';
        }
        if (!$this->paymentRowHasTraceKey($row)) {
            $errors[] = $prefix . '票据号码、银行流水号、缴费单号、收款账号+金额至少填写一项用于追溯';
        }
        return $errors;
    }

    protected function paymentRowHasTraceKey($row)
    {
        if ($row['receipt_no'] !== '' || $row['bank_serial_no'] !== '' || $row['payment_order_no'] !== '') {
            return true;
        }
        return trim((string)($row['bank_account_no'] ?? '')) !== ''
            && $this->decimalToCents($row['payment_amount'] ?? '0') > 0;
    }

    protected function validateRefundRows($rows, $bizType = '')
    {
        $errors = [];
        $allowedOutTypes = $this->allowedRefundOutTypes($bizType);
        foreach ($rows as $row) {
            $prefix = '第' . $row['source_row_no'] . '行：';
            if ($row['case_no'] === '') {
                $errors[] = $prefix . '案号不能为空';
            }
            if ($row['out_type'] === '') {
                $errors[] = $prefix . '出账种类不能为空';
            } elseif (empty($allowedOutTypes)) {
                $errors[] = $prefix . '当前账套类型不支持案款退付登记：' . $bizType;
            } elseif (!in_array($row['out_type'], $allowedOutTypes, true)) {
                $errors[] = $prefix . '当前账套不允许导入出账种类【' . $row['out_type'] . '】，允许类型：' . implode('、', $allowedOutTypes);
            }
            if ($row['party_name'] === '' && $row['actual_payee_name'] === '') {
                $errors[] = $prefix . '当事人和实际收款人不能同时为空';
            }
            if ($this->decimalToCents($row['refund_amount']) <= 0) {
                $errors[] = $prefix . '出账金额必须大于0';
            }
            if ($row['refund_date'] === '') {
                $errors[] = $prefix . '出账日期不能为空或格式不正确';
            }
            if ($row['receipt_no'] === '' && $row['source_receipt_no'] === '' && $row['out_order_no'] === '') {
                $errors[] = $prefix . '票据号码、来源票据号码、出账单号至少填写一项用于追溯';
            }
        }
        return $errors;
    }

    protected function currentAccountSetBizType()
    {
        try {
            $row = $this->getdb('fin_account_set')->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])->field('biz_type')->find();
            return $row['biz_type'] ?? '';
        } catch (\Exception $e) {
            return '';
        }
    }

    protected function allowedPaymentBusinessTypes($bizType)
    {
        $map = [
            'CASE_FUND' => ['执行、调解款', '执行、调节款'],
            'LITIGATION_FEE' => ['预收诉讼费', '诉讼费预收'],
        ];
        return $map[$bizType] ?? [];
    }

    protected function allowedRefundOutTypes($bizType)
    {
        $map = [
            'CASE_FUND' => ['执行、调解款发放'],
            'LITIGATION_FEE' => ['诉讼费退费'],
        ];
        return $map[$bizType] ?? [];
    }

    protected function normalizeIdList($value)
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            $id = trim((string)$item);
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    protected function deleteCaseFundBusinessRows($table, $idField, $ids, $bizType, $auditBizType, $label)
    {
        $where = $this->accountWhere();
        $where['fiscal_year'] = $this->currentYear();
        $where['voucher_status'] = 'UNGENERATED';
        $fields = $bizType === 'PAYMENT' ? self::PAYMENT_FIELD : self::REFUND_FIELD;
        $rows = $this->getdb($table)
            ->where($where)
            ->where($idField, 'in', $ids)
            ->field($fields)
            ->select();
        if (count($rows) !== count($ids)) {
            return $this->error('只能删除未制证的' . $label);
        }
        $reconcileCount = $this->getdb(self::TABLE_BANK_RECONCILE)->where([
            'account_set_id' => $this->accountSetId,
            'biz_type' => $bizType,
            'del_flag' => 0,
        ])->where('biz_id', 'in', $ids)->count();
        if ((int)$reconcileCount > 0) {
            return $this->error('只能删除未对账的' . $label);
        }

        $update = ['del_flag' => 1];
        $this->fillUpdate($update);
        Db::startTrans();
        try {
            $this->getdb($table)->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])->where($idField, 'in', $ids)->update($update);
            $this->logAudit($auditBizType, 'DELETE', 'DELETE', $rows, [
                $idField . 's' => $ids,
                'deleted_count' => count($rows),
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('删除' . $label . '失败：' . $e->getMessage());
        }

        return $this->ok(['deleted_count' => count($rows)], '删除成功', count($rows));
    }

    protected function supportedBankStatementBanks()
    {
        return [
            'SHENGJING' => '盛京银行',
            'CCB' => '建设银行',
        ];
    }

    protected function normalizeBankStatementRow($row, $bankCode, $bankName, $defaultRowNo)
    {
        $sourceRowNo = (int)($row['source_row_no'] ?? $defaultRowNo);
        $prefix = '第' . $sourceRowNo . '行：';
        $transactionTime = $this->normalizeDateTimeValue($row['transaction_time'] ?? '');
        $transactionDate = $transactionTime === '' ? '' : substr($transactionTime, 0, 10);
        $debitAmount = $this->centsToDecimal($this->decimalToCents($row['debit_amount'] ?? '0'));
        $creditAmount = $this->centsToDecimal($this->decimalToCents($row['credit_amount'] ?? '0'));
        $balanceAmount = $this->centsToDecimal($this->decimalToCents($row['balance_amount'] ?? '0'));
        $debitCents = $this->decimalToCents($debitAmount);
        $creditCents = $this->decimalToCents($creditAmount);
        if ($transactionTime === '') {
            return ['error' => $prefix . '交易时间不能为空或格式不正确'];
        }
        if ($debitCents < 0 || $creditCents < 0) {
            return ['error' => $prefix . '支出和收入金额不能为负数'];
        }
        if (($debitCents > 0 && $creditCents > 0) || ($debitCents === 0 && $creditCents === 0)) {
            return ['error' => $prefix . '支出和收入必须且只能填写一项'];
        }
        $bankSerialNo = trim((string)($row['bank_serial_no'] ?? ''));
        if ($bankSerialNo === '') {
            return ['error' => $prefix . '交易流水号不能为空'];
        }
        $parsed = [
            'fiscal_year' => (int)substr($transactionDate, 0, 4),
            'period' => substr($transactionDate, 0, 7),
            'bank_code' => $bankCode,
            'bank_name' => $bankName,
            'transaction_date' => $transactionDate,
            'transaction_time' => $transactionTime,
            'direction' => $debitCents > 0 ? 'DEBIT' : 'CREDIT',
            'debit_amount' => $debitAmount,
            'credit_amount' => $creditAmount,
            'balance_amount' => $balanceAmount,
            'counterparty_account_no' => trim((string)($row['counterparty_account_no'] ?? '')),
            'counterparty_account_name' => trim((string)($row['counterparty_account_name'] ?? '')),
            'counterparty_bank_name' => trim((string)($row['counterparty_bank_name'] ?? '')),
            'purpose' => trim((string)($row['purpose'] ?? '')),
            'postscript' => trim((string)($row['postscript'] ?? '')),
            'bank_serial_no' => $bankSerialNo,
            'source_row_no' => $sourceRowNo,
            'source_raw_json' => json_encode($row, JSON_UNESCAPED_UNICODE),
        ];
        $parsed['source_fingerprint'] = $this->bankStatementFingerprint($parsed);
        return $parsed;
    }

    protected function bankStatementFingerprint($row)
    {
        $parts = [
            $row['bank_code'] ?? '',
            $row['transaction_time'] ?? '',
            $row['debit_amount'] ?? '',
            $row['credit_amount'] ?? '',
            $row['balance_amount'] ?? '',
            $row['counterparty_account_no'] ?? '',
            $row['counterparty_account_name'] ?? '',
            $row['bank_serial_no'] ?? '',
        ];
        return md5(implode('|', $parts));
    }

    protected function buildBankReconcileRow($statement, $bizType, $biz, $status, $bankCents, $bizCents)
    {
        $reconcileDate = $statement ? $statement['transaction_date'] : $this->bankReconcileBizDate($bizType, $biz);
        $bankSummary = $statement ? trim(($statement['purpose'] ?? '') . ' ' . ($statement['postscript'] ?? '')) : '';
        return [
            'reconcile_id' => uuid(),
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => (int)substr($reconcileDate, 0, 4),
            'period' => substr($reconcileDate, 0, 7),
            'reconcile_date' => $reconcileDate,
            'statement_id' => $statement['statement_id'] ?? null,
            'biz_type' => $bizType,
            'biz_id' => $biz ? $this->bankReconcileBizId($bizType, $biz) : null,
            'biz_no' => $biz ? $this->bankReconcileBizNo($bizType, $biz) : '',
            'bank_serial_no' => $statement['bank_serial_no'] ?? ($bizType === 'PAYMENT' ? ($biz['bank_serial_no'] ?? '') : ($biz['out_order_no'] ?? '')),
            'bank_amount' => $this->centsToDecimal($bankCents),
            'biz_amount' => $this->centsToDecimal($bizCents),
            'diff_amount' => $this->centsToDecimal($bankCents - $bizCents),
            'match_status' => $status,
            'match_rule' => 'SERIAL_NO',
            'matched_by' => $this->userid,
            'matched_time' => $this->now(),
            'bank_direction' => $statement['direction'] ?? ($bizType === 'PAYMENT' ? 'CREDIT' : 'DEBIT'),
            'bank_summary' => $bankSummary,
            'biz_summary' => $biz ? $this->bankReconcileBizSummary($bizType, $biz) : '',
            'remark' => '',
        ];
    }

    protected function paymentCandidatesByBankSerial($bankSerialNo)
    {
        $bankSerialNo = trim((string)$bankSerialNo);
        if ($bankSerialNo === '') {
            return [];
        }
        return $this->getdb(self::TABLE_PAYMENT)
            ->where(['account_set_id' => $this->accountSetId, 'fiscal_year' => $this->currentYear(), 'del_flag' => 0])
            ->where('bank_serial_no', $bankSerialNo)
            ->field(self::PAYMENT_FIELD)
            ->select();
    }

    protected function refundCandidatesByOutOrderNo($outOrderNo)
    {
        $outOrderNo = trim((string)$outOrderNo);
        if ($outOrderNo === '') {
            return [];
        }
        return $this->getdb(self::TABLE_REFUND)
            ->where(['account_set_id' => $this->accountSetId, 'fiscal_year' => $this->currentYear(), 'del_flag' => 0])
            ->where('out_order_no', $outOrderNo)
            ->field(self::REFUND_FIELD)
            ->select();
    }

    protected function paymentCandidatesInScope($dateStart, $dateEnd)
    {
        $query = $this->getdb(self::TABLE_PAYMENT)
            ->where(['account_set_id' => $this->accountSetId, 'fiscal_year' => $this->currentYear(), 'del_flag' => 0])
            ->where('bank_serial_no', 'neq', '');
        if ($dateStart !== '') {
            $query->where('payment_date', '>=', $dateStart);
        }
        if ($dateEnd !== '') {
            $query->where('payment_date', '<=', $dateEnd);
        }
        return $query->field(self::PAYMENT_FIELD)->select();
    }

    protected function refundCandidatesInScope($dateStart, $dateEnd)
    {
        $query = $this->getdb(self::TABLE_REFUND)
            ->where(['account_set_id' => $this->accountSetId, 'fiscal_year' => $this->currentYear(), 'del_flag' => 0])
            ->where('out_order_no', 'neq', '');
        if ($dateStart !== '') {
            $query->where('refund_date', '>=', $dateStart);
        }
        if ($dateEnd !== '') {
            $query->where('refund_date', '<=', $dateEnd);
        }
        return $query->field(self::REFUND_FIELD)->select();
    }

    protected function bankStatementExistsBySerial($bankSerialNo, $bankCode = '', $dateStart = '', $dateEnd = '')
    {
        $bankSerialNo = trim((string)$bankSerialNo);
        if ($bankSerialNo === '') {
            return false;
        }
        $where = [
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $this->currentYear(),
            'bank_serial_no' => $bankSerialNo,
            'del_flag' => 0,
        ];
        if ($bankCode !== '') {
            $where['bank_code'] = $bankCode;
        }
        $query = $this->getdb(self::TABLE_BANK_STATEMENT)->where($where);
        if ($dateStart !== '') {
            $query->where('transaction_date', '>=', $dateStart);
        }
        if ($dateEnd !== '') {
            $query->where('transaction_date', '<=', $dateEnd);
        }
        return $query->count() > 0;
    }

    protected function bankReconcileBizAmountCents($bizType, $biz)
    {
        if ($bizType === 'PAYMENT') {
            return $this->decimalToCents($biz['payment_amount'] ?? '0');
        }
        return $this->decimalToCents($biz['refund_amount'] ?? '0');
    }

    protected function bankReconcileBizId($bizType, $biz)
    {
        return $bizType === 'PAYMENT' ? ($biz['payment_id'] ?? '') : ($biz['refund_id'] ?? '');
    }

    protected function bankReconcileBizNo($bizType, $biz)
    {
        return $bizType === 'PAYMENT' ? ($biz['bank_serial_no'] ?? '') : ($biz['out_order_no'] ?? '');
    }

    protected function bankReconcileBizDate($bizType, $biz)
    {
        return $bizType === 'PAYMENT' ? ($biz['payment_date'] ?? '') : ($biz['refund_date'] ?? '');
    }

    protected function bankReconcileBizSummary($bizType, $biz)
    {
        if ($bizType === 'PAYMENT') {
            return trim(($biz['case_no'] ?? '') . ' ' . ($biz['payer_name'] ?? '') . ' ' . ($biz['party_name'] ?? ''));
        }
        return trim(($biz['case_no'] ?? '') . ' ' . ($biz['actual_payee_name'] ?? '') . ' ' . ($biz['party_name'] ?? '') . ' 出账单号:' . ($biz['out_order_no'] ?? ''));
    }

    protected function softDeleteBankReconcileScope($dateStart, $dateEnd)
    {
        $where = [
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $this->currentYear(),
            'match_rule' => 'SERIAL_NO',
            'del_flag' => 0,
        ];
        $query = $this->getdb(self::TABLE_BANK_RECONCILE)->where($where);
        if ($dateStart !== '') {
            $query->where('reconcile_date', '>=', $dateStart);
        }
        if ($dateEnd !== '') {
            $query->where('reconcile_date', '<=', $dateEnd);
        }
        $update = ['del_flag' => 1];
        $this->fillUpdate($update);
        $query->update($update);
    }

    protected function emptyBankReconcileCounts()
    {
        return [
            'MATCHED' => 0,
            'AMOUNT_DIFF' => 0,
            'BANK_ONLY' => 0,
            'BIZ_ONLY' => 0,
            'DUPLICATE' => 0,
        ];
    }

    protected function bankReconcileSummary($where)
    {
        $rows = $this->getdb(self::TABLE_BANK_RECONCILE)
            ->where($where)
            ->field('match_status,count(*) as total')
            ->group('match_status')
            ->select();
        $summary = $this->emptyBankReconcileCounts();
        foreach ($rows as $row) {
            $summary[$row['match_status']] = (int)$row['total'];
        }
        return $summary;
    }

    protected function allowedSubjectConfigItems($bizType, $voucherBizType)
    {
        if ($voucherBizType === 'PAYMENT') {
            return $this->subjectConfigPaymentBusinessTypes($bizType);
        }
        if ($voucherBizType === 'REFUND') {
            return $this->allowedRefundOutTypes($bizType);
        }
        return [];
    }

    protected function subjectConfigPaymentBusinessTypes($bizType)
    {
        $map = [
            'CASE_FUND' => ['执行、调解款'],
            'LITIGATION_FEE' => ['诉讼费预收'],
        ];
        return $map[$bizType] ?? [];
    }

    protected function normalizeVoucherBizType($value)
    {
        $value = strtoupper(trim((string)$value));
        return in_array($value, ['PAYMENT', 'REFUND'], true) ? $value : '';
    }

    protected function validateVoucherSubjectCode($subjectCode, $label)
    {
        if ($subjectCode === '') {
            return $label . '不能为空';
        }
        $subject = $this->getdb('fin_subject')->where([
            'account_set_id' => $this->accountSetId,
            'subject_code' => $subjectCode,
            'del_flag' => 0,
        ])->find();
        if (!$subject) {
            return $label . '不存在：' . $subjectCode;
        }
        if ((int)$subject['status'] !== 1) {
            return $label . '未启用：' . $subjectCode;
        }
        if ((int)$subject['leaf_flag'] !== 1) {
            return $label . '必须为末级科目：' . $subjectCode;
        }
        if (isset($subject['voucher_entry_flag']) && (int)$subject['voucher_entry_flag'] !== 1) {
            return $label . '不允许录入凭证：' . $subjectCode;
        }
        return null;
    }

    protected function subjectNameMap($subjectCodes)
    {
        $subjectCodes = array_values(array_unique(array_filter($subjectCodes)));
        if (empty($subjectCodes)) {
            return [];
        }
        $rows = $this->getdb('fin_subject')->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->where('subject_code', 'in', $subjectCodes)->field('subject_code,subject_name')->select();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['subject_code']] = $row['subject_name'];
        }
        return $map;
    }

    protected function subjectConfigAccountSetFlag()
    {
        $row = $this->getdb('fin_account_set')->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->field('generate_voucher_by_day_flag')->find();
        if (!$row || !array_key_exists('generate_voucher_by_day_flag', $row)) {
            return 1;
        }
        return (int)$row['generate_voucher_by_day_flag'] === 0 ? 0 : 1;
    }

    protected function saveSubjectConfigAccountSetFlag($flag)
    {
        $before = $this->getdb('fin_account_set')->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->field('account_set_id,generate_voucher_by_day_flag')->find();
        if (!$before) {
            throw new \Exception('账套不存在');
        }
        $update = ['generate_voucher_by_day_flag' => $this->normalizeDailyVoucherFlag($flag)];
        $this->fillUpdate($update);
        $this->getdb('fin_account_set')->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->update($update);
        return [$before, array_merge($before, $update)];
    }

    protected function normalizeDailyVoucherFlag($value)
    {
        return (int)$value === 0 ? 0 : 1;
    }

    protected function paymentImportErrorMessage($errors, $bizType = '')
    {
        foreach ($errors as $error) {
            if (strpos($error, '当前账套不允许导入业务类型') !== false) {
                return '导入的不是' . $this->paymentDataLabelByBizType($bizType) . '数据';
            }
        }
        return '导入校验失败';
    }

    protected function refundImportErrorMessage($errors, $bizType = '')
    {
        foreach ($errors as $error) {
            if (strpos($error, '当前账套不允许导入出账种类') !== false) {
                return '导入的不是' . $this->paymentDataLabelByBizType($bizType) . '数据';
            }
        }
        return '导入校验失败';
    }

    protected function paymentDataLabelByBizType($bizType)
    {
        $map = [
            'CASE_FUND' => '案款',
            'LITIGATION_FEE' => '诉讼费',
        ];
        return $map[$bizType] ?? '当前账套';
    }

    protected function isEmptyPaymentRawRow($raw)
    {
        foreach ($raw as $value) {
            if ($value !== '') {
                return false;
            }
        }
        return true;
    }

    protected function isNonDetailPaymentRawRow($raw)
    {
        $caseNo = trim((string)($raw['案号'] ?? ''));
        $paymentDate = trim((string)($raw['缴费日期'] ?? ''));
        $receiptNo = trim((string)($raw['票据号码'] ?? ''));
        $bankSerialNo = trim((string)($raw['银行流水号'] ?? ''));
        return $caseNo === '' && $paymentDate === '' && $receiptNo === '' && $bankSerialNo === '';
    }

    protected function isNonDetailRefundRawRow($raw)
    {
        $caseNo = trim((string)($raw['案号'] ?? ''));
        $refundDate = trim((string)($raw['出账日期'] ?? ''));
        $sourceReceiptNo = trim((string)($raw['来源票据号码'] ?? ''));
        $outOrderNo = trim((string)($raw['出账单号'] ?? ''));
        return $caseNo === '' && $refundDate === '' && $sourceReceiptNo === '' && $outOrderNo === '';
    }

    protected function isMalformedPaymentRawRow($raw)
    {
        foreach ($raw as $value) {
            $text = (string)$value;
            if (strpos($text, "\0") !== false) {
                return true;
            }
        }
        return false;
    }

    protected function loadExistingPaymentsForImport($rows)
    {
        $receiptNos = [];
        $accountNos = [];
        foreach ($rows as $row) {
            $receiptNo = trim((string)($row['receipt_no'] ?? ''));
            if ($receiptNo !== '') {
                $receiptNos[] = $receiptNo;
                continue;
            }
            $accountNo = trim((string)($row['bank_account_no'] ?? ''));
            if ($accountNo !== '') {
                $accountNos[] = $accountNo;
            }
        }

        $existing = [];
        if (!empty($receiptNos)) {
            $receiptRows = $this->getdb(self::TABLE_PAYMENT)->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])->where('receipt_no', 'in', array_values(array_unique($receiptNos)))->field(self::PAYMENT_FIELD)->select();
            foreach ($receiptRows as $row) {
                $this->rememberPaymentImportExistingRow($existing, $row);
            }
        }
        if (!empty($accountNos)) {
            $accountRows = $this->getdb(self::TABLE_PAYMENT)->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])->where('bank_account_no', 'in', array_values(array_unique($accountNos)))->field(self::PAYMENT_FIELD)->select();
            foreach ($accountRows as $row) {
                $this->rememberPaymentImportExistingRow($existing, $row);
            }
        }
        return $existing;
    }

    protected function rememberPaymentImportExistingRow(&$existing, $row)
    {
        $key = $this->paymentImportDuplicateKey($row);
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

    protected function updateExistingPaymentFromImport($existingRow, $row, $data)
    {
        $update = $row;
        unset($update['payment_id'], $update['account_set_id'], $update['created_by'], $update['created_time']);
        $update['source_file_name'] = $data['filename'] ?? '';
        $update['voucher_status'] = 'UNGENERATED';
        $update['voucher_id'] = null;
        $update['voucher_no'] = null;
        $update['voucher_period'] = null;
        $update['voucher_generated_time'] = null;
        $update['remark'] = $data['remark'] ?? '';
        $this->fillUpdate($update);
        $this->getdb(self::TABLE_PAYMENT)->where([
            'payment_id' => $existingRow['payment_id'],
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->update($update);
    }

    protected function paymentImportDuplicateKey($row)
    {
        $receiptNo = trim((string)($row['receipt_no'] ?? ''));
        if ($receiptNo !== '') {
            return 'receipt:' . $receiptNo;
        }
        $bankAccountNo = trim((string)($row['bank_account_no'] ?? ''));
        if ($bankAccountNo === '') {
            return '';
        }
        $amount = $this->centsToDecimal($this->decimalToCents($row['payment_amount'] ?? '0'));
        return 'account_amount:' . $bankAccountNo . '|' . $amount;
    }

    protected function paymentFingerprint($row)
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

    protected function refundFingerprint($row)
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

    protected function yesNoFlagFromText($text)
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

    protected function normalizeDateTimeValue($value)
    {
        $text = $this->normalizeImportCellText($value);
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

    protected function normalizeDateValue($value)
    {
        $datetime = $this->normalizeDateTimeValue($value);
        return $datetime === '' ? '' : substr($datetime, 0, 10);
    }

    protected function nullableDateValue($value)
    {
        $date = $this->normalizeDateValue($value);
        return $date === '' ? null : $date;
    }

    protected function normalizeImportCellText($value)
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

    protected function readOleWorkbookStream($binary)
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

    protected function parseBiffCells($stream)
    {
        $records = [];
        $pos = 0;
        while ($pos + 4 <= strlen($stream)) {
            $type = $this->u16($stream, $pos);
            $length = $this->u16($stream, $pos + 2);
            $pos += 4;
            $records[] = [$type, substr($stream, $pos, $length)];
            $pos += $length;
        }
        $sst = $this->parseBiffSharedStrings($records);

        $cells = [];
        foreach ($records as $record) {
            list($type, $data) = $record;
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

    protected function parseBiffSharedStrings($records)
    {
        for ($i = 0; $i < count($records); $i++) {
            if ($records[$i][0] !== 0x00FC) {
                continue;
            }
            $chunks = [$records[$i][1]];
            for ($j = $i + 1; $j < count($records) && $records[$j][0] === 0x003C; $j++) {
                $chunks[] = $records[$j][1];
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
