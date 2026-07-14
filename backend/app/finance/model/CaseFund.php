<?php

namespace app\finance\model;

use app\finance\service\casefund\support\LegacySpreadsheetReader;
use think\Db;

class CaseFund extends Common
{
    protected $legacySpreadsheetReader;

    const ACTION = 'caseFund';
    const TABLE_PAYMENT = 'fin_case_fund_payment';
    const TABLE_REFUND = 'fin_case_fund_refund';
    const TABLE_UNKNOWN_PAYMENT = 'fin_case_fund_unknown_payment';
    const TABLE_DISTRIBUTION = 'fin_case_fund_distribution';
    const TABLE_BANK_STATEMENT = 'fin_case_fund_bank_statement';
    const TABLE_BANK_RECONCILE = 'fin_case_fund_bank_reconcile';
    const BANK_RECONCILE_AUTO_MATCH_RULES = ['SERIAL_NO', 'SERIAL_NO_DATE', 'PAYEE_ACCOUNT_DATE'];
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
    const UNKNOWN_PAYMENT_FIELD = [
        'unknown_payment_id', 'account_set_id', 'fiscal_year', 'period', 'court_name',
        'claim_status', 'payer_name', 'unknown_amount', 'bank_summary', 'bank_remark',
        'transaction_date', 'transaction_time', 'court_bank_account_no', 'arrival_method',
        'payer_bank_name', 'payer_account_no', 'claim_user_name', 'claim_date',
        'last_modified_time', 'source_file_name', 'source_row_no', 'source_fingerprint',
        'source_raw_json', 'voucher_status', 'voucher_id', 'voucher_no', 'voucher_period',
        'voucher_generated_time', 'created_by', 'created_time', 'updated_by', 'updated_time',
        'del_flag', 'version', 'remark',
    ];
    const DISTRIBUTION_FIELD = [
        'distribution_id', 'account_set_id', 'fiscal_year', 'period', 'distribution_no',
        'in_case_no', 'in_judge_name', 'in_party_name', 'out_case_no', 'out_judge_name',
        'out_party_name', 'invoice_type', 'receipt_no', 'distribution_amount',
        'applicant_name', 'apply_date', 'receive_date', 'business_status', 'description',
        'original_invoice_date', 'source_file_name', 'source_row_no', 'source_fingerprint',
        'source_raw_json', 'voucher_status', 'voucher_id', 'voucher_no', 'voucher_period',
        'voucher_generated_time', 'created_by', 'created_time', 'updated_by', 'updated_time',
        'del_flag', 'version', 'remark',
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
            case 'unknownPaymentList':
                return $this->unknownPaymentList($data);
            case 'unknownPaymentImport':
                return $this->unknownPaymentImport($data);
            case 'unknownPaymentGenerateVoucher':
                return $this->unknownPaymentGenerateVoucher($data);
            case 'unknownPaymentDelete':
                return $this->unknownPaymentDelete($data);
            case 'distributionList':
                return $this->distributionList($data);
            case 'distributionImport':
                return $this->distributionImport($data);
            case 'distributionGenerateVoucher':
                return $this->distributionGenerateVoucher($data);
            case 'distributionDelete':
                return $this->distributionDelete($data);
            case 'paymentGenerateVoucher':
                return $this->paymentGenerateVoucher($data);
            case 'paymentGenerateVoucherByQuery':
                return $this->paymentGenerateVoucherByQuery($data);
            case 'paymentDelete':
                return $this->paymentDelete($data);
            case 'refundList':
                return $this->refundList($data);
            case 'refundImport':
                return $this->refundImport($data);
            case 'refundGenerateVoucher':
                return $this->refundGenerateVoucher($data);
            case 'refundGenerateVoucherByQuery':
                return $this->refundGenerateVoucherByQuery($data);
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
        $paymentMethodFilter = trim((string)($data['payment_method'] ?? ($data['paymentMethod'] ?? '')));
        if ($paymentMethodFilter !== '') {
            $paymentMethod = $this->normalizePaymentMethodCode($paymentMethodFilter);
            if (isset($this->paymentMethodOptions()[$paymentMethod])) {
                $where['payment_method'] = $paymentMethod;
            }
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
        $receiptNoDuplicateCheckRequired = $this->paymentImportRequiresReceiptNoDuplicateCheck($bizType);
        $errors = $this->validatePaymentRows($rows, $bizType, $receiptNoDuplicateCheckRequired);
        if (!empty($errors)) {
            return $this->error($this->paymentImportErrorMessage($errors, $bizType), $errors);
        }

        $existing = $this->loadExistingPaymentsForImport($rows, $receiptNoDuplicateCheckRequired);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seen = [];
        Db::startTrans();
        try {
            foreach ($rows as $row) {
                $duplicateKey = $this->paymentImportDuplicateKey($row, $receiptNoDuplicateCheckRequired);
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

    public function unknownPaymentList($data = [])
    {
        $auth = $this->requirePermission('case_fund:view');
        if ($auth) {
            return $auth;
        }
        $bizType = $this->currentAccountSetBizType();
        if ($bizType !== 'CASE_FUND') {
            return $this->error('当前账套类型不支持不明款业务：' . $bizType);
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
        if (!empty($data['claim_status'])) {
            $where['claim_status'] = $data['claim_status'];
        }
        if (!empty($data['date_start'])) {
            $where['transaction_date'][] = ['>=', $data['date_start']];
        }
        if (!empty($data['date_end'])) {
            $where['transaction_date'][] = ['<=', $data['date_end']];
        }

        $keyword = trim($data['keyword'] ?? '');
        $query = $this->getdb(self::TABLE_UNKNOWN_PAYMENT)->where($where);
        $totalQuery = $this->getdb(self::TABLE_UNKNOWN_PAYMENT)->where($where);
        $amountQuery = $this->getdb(self::TABLE_UNKNOWN_PAYMENT)->where($where);
        foreach ([$query, $totalQuery, $amountQuery] as $item) {
            if ($keyword !== '') {
                $item->where('payer_name|bank_summary|bank_remark|court_bank_account_no|payer_bank_name|payer_account_no', 'like', '%' . $keyword . '%');
            }
        }

        $total = $totalQuery->count();
        $totalAmount = $amountQuery->sum('unknown_amount');
        $rows = $query->field(self::UNKNOWN_PAYMENT_FIELD)
            ->order('transaction_date asc, source_row_no asc')
            ->page($page, $pagesize)
            ->select();
        return $this->ok(['items' => $rows, 'total' => $total, 'total_amount' => $totalAmount], 'OK', $total);
    }

    public function unknownPaymentImport($data = [])
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
            $rows = $this->parseUnknownPaymentImportRowsFromXls($binary);
        } catch (\Exception $e) {
            return $this->error('解析不明款失败：' . $e->getMessage());
        }
        if (empty($rows)) {
            return $this->error('导入文件没有不明款数据');
        }

        $bizType = $this->currentAccountSetBizType();
        $errors = $this->validateUnknownPaymentRows($rows, $bizType);
        if (!empty($errors)) {
            return $this->error('导入校验失败', $errors);
        }

        $fingerprints = [];
        foreach ($rows as $row) {
            $fingerprints[] = $row['source_fingerprint'];
        }
        $fingerprints = array_values(array_unique($fingerprints));
        $existing = [];
        if (!empty($fingerprints)) {
            $existingRows = $this->getdb(self::TABLE_UNKNOWN_PAYMENT)->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])->where('source_fingerprint', 'in', $fingerprints)->select();
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
                $insert['unknown_payment_id'] = uuid();
                $insert['account_set_id'] = $this->accountSetId;
                $insert['source_file_name'] = $data['filename'] ?? '';
                $insert['voucher_status'] = 'UNGENERATED';
                $insert['voucher_id'] = null;
                $insert['voucher_no'] = null;
                $insert['voucher_period'] = null;
                $insert['voucher_generated_time'] = null;
                $insert['remark'] = $data['remark'] ?? '';
                $this->fillCreate($insert);
                $this->getdb(self::TABLE_UNKNOWN_PAYMENT)->insert($insert);
                $created++;
            }
            $this->logAudit('CASE_FUND_UNKNOWN_PAYMENT', 'IMPORT', 'IMPORT', null, [
                'filename' => $data['filename'] ?? '',
                'total' => count($rows),
                'created' => $created,
                'skipped' => $skipped,
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('保存不明款失败：' . $e->getMessage());
        }

        return $this->ok([
            'total' => count($rows),
            'created' => $created,
            'skipped' => $skipped,
        ], '导入成功', count($rows));
    }

    public function distributionList($data = [])
    {
        $auth = $this->requirePermission('case_fund:view');
        if ($auth) {
            return $auth;
        }
        $bizType = $this->currentAccountSetBizType();
        if ($bizType !== 'CASE_FUND') {
            return $this->error('当前账套类型不支持案款分配：' . $bizType);
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
        if (!empty($data['invoice_type'])) {
            $where['invoice_type'] = $data['invoice_type'];
        }
        if (!empty($data['date_start'])) {
            $where['receive_date'][] = ['>=', $data['date_start']];
        }
        if (!empty($data['date_end'])) {
            $where['receive_date'][] = ['<=', $data['date_end']];
        }

        $keyword = trim($data['keyword'] ?? '');
        $query = $this->getdb(self::TABLE_DISTRIBUTION)->where($where);
        $totalQuery = $this->getdb(self::TABLE_DISTRIBUTION)->where($where);
        $amountQuery = $this->getdb(self::TABLE_DISTRIBUTION)->where($where);
        foreach ([$query, $totalQuery, $amountQuery] as $item) {
            if ($keyword !== '') {
                $item->where('distribution_no|in_case_no|out_case_no|in_party_name|out_party_name|receipt_no|applicant_name', 'like', '%' . $keyword . '%');
            }
        }

        $total = $totalQuery->count();
        $totalAmount = $amountQuery->sum('distribution_amount');
        $rows = $query->field(self::DISTRIBUTION_FIELD)
            ->order('receive_date asc, distribution_no asc, source_row_no asc')
            ->page($page, $pagesize)
            ->select();
        return $this->ok(['items' => $rows, 'total' => $total, 'total_amount' => $totalAmount], 'OK', $total);
    }

    public function distributionImport($data = [])
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
            $rows = $this->parseDistributionImportRowsFromXls($binary);
        } catch (\Exception $e) {
            return $this->error('解析案款分配失败：' . $e->getMessage());
        }
        if (empty($rows)) {
            return $this->error('导入文件没有案款分配数据');
        }

        $bizType = $this->currentAccountSetBizType();
        $errors = $this->validateDistributionRows($rows, $bizType);
        if (!empty($errors)) {
            return $this->error('导入校验失败', $errors);
        }

        $fingerprints = [];
        foreach ($rows as $row) {
            $fingerprints[] = $row['source_fingerprint'];
        }
        $fingerprints = array_values(array_unique($fingerprints));
        $existing = [];
        if (!empty($fingerprints)) {
            $existingRows = $this->getdb(self::TABLE_DISTRIBUTION)->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])->where('source_fingerprint', 'in', $fingerprints)->select();
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
                $insert['distribution_id'] = uuid();
                $insert['account_set_id'] = $this->accountSetId;
                $insert['source_file_name'] = $data['filename'] ?? '';
                $insert['voucher_status'] = 'UNGENERATED';
                $insert['voucher_id'] = null;
                $insert['voucher_no'] = null;
                $insert['voucher_period'] = null;
                $insert['voucher_generated_time'] = null;
                $insert['remark'] = $data['remark'] ?? '';
                $this->fillCreate($insert);
                $this->getdb(self::TABLE_DISTRIBUTION)->insert($insert);
                $created++;
            }
            $this->logAudit('CASE_FUND_DISTRIBUTION', 'IMPORT', 'IMPORT', null, [
                'filename' => $data['filename'] ?? '',
                'total' => count($rows),
                'created' => $created,
                'skipped' => $skipped,
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('保存案款分配失败：' . $e->getMessage());
        }

        return $this->ok([
            'total' => count($rows),
            'created' => $created,
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
        $this->applyDateRangeFilter($where, 'refund_date', $dateStart, $dateEnd);
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

    protected function normalizeDateFilterValue($value)
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return trim($value);
        }
        if (is_numeric($value)) {
            return trim((string)$value);
        }
        return '';
    }

    protected function applyDateRangeFilter(&$where, $field, $dateStart, $dateEnd)
    {
        $dateStart = $this->normalizeDateFilterValue($dateStart);
        $dateEnd = $this->normalizeDateFilterValue($dateEnd);
        if ($dateStart !== '') {
            $where[$field][] = ['>=', $dateStart];
        }
        if ($dateEnd !== '') {
            $where[$field][] = ['<=', $dateEnd];
        }
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

        $bizType = $this->currentAccountSetBizType();
        $receiptTraceRequired = $this->refundImportRequiresReceiptTrace($bizType);
        try {
            $rows = $this->parseRefundImportRowsFromXls($binary, $bizType, $receiptTraceRequired);
        } catch (\Exception $e) {
            return $this->error('解析案款退付登记失败：' . $e->getMessage());
        }
        if (empty($rows)) {
            return $this->error('导入文件没有案款退付数据');
        }

        $errors = $this->validateRefundRows($rows, $bizType, $receiptTraceRequired);
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
                $existing[$row['source_fingerprint']] = $row;
            }
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seen = [];
        Db::startTrans();
        try {
            foreach ($rows as $row) {
                $fingerprint = $row['source_fingerprint'];
                if (isset($seen[$fingerprint])) {
                    $skipped++;
                    $seen[$fingerprint] = true;
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
                'updated' => $updated,
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
            'updated' => $updated,
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

    public function unknownPaymentDelete($data = [])
    {
        $auth = $this->requirePermission('case_fund:delete');
        if ($auth) {
            return $auth;
        }
        $bizType = $this->currentAccountSetBizType();
        if ($bizType !== 'CASE_FUND') {
            return $this->error('当前账套类型不支持删除不明款记录：' . $bizType);
        }
        $ids = $this->normalizeIdList($data['unknown_payment_ids'] ?? ($data['ids'] ?? []));
        if (empty($ids)) {
            return $this->error('请选择要删除的不明款记录');
        }
        return $this->deleteCaseFundBusinessRows(
            self::TABLE_UNKNOWN_PAYMENT,
            'unknown_payment_id',
            $ids,
            'UNKNOWN_PAYMENT',
            'CASE_FUND_UNKNOWN_PAYMENT',
            '不明款记录'
        );
    }

    public function distributionDelete($data = [])
    {
        $auth = $this->requirePermission('case_fund:delete');
        if ($auth) {
            return $auth;
        }
        $bizType = $this->currentAccountSetBizType();
        if ($bizType !== 'CASE_FUND') {
            return $this->error('当前账套类型不支持删除案款分配记录：' . $bizType);
        }
        $ids = $this->normalizeIdList($data['distribution_ids'] ?? ($data['ids'] ?? []));
        if (empty($ids)) {
            return $this->error('请选择要删除的案款分配记录');
        }
        return $this->deleteCaseFundBusinessRows(
            self::TABLE_DISTRIBUTION,
            'distribution_id',
            $ids,
            'DISTRIBUTION',
            'CASE_FUND_DISTRIBUTION',
            '案款分配记录'
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
                $candidates = $this->paymentCandidatesByBankStatement($statement);
                $bizType = 'PAYMENT';
                $matchRule = 'SERIAL_NO_DATE';
            } else {
                $candidates = $this->refundCandidatesByBankStatement($statement);
                $bizType = 'REFUND';
                $matchRule = 'PAYEE_ACCOUNT_DATE';
            }

            if (empty($candidates)) {
                $status = 'BANK_ONLY';
                $resultRows[] = $this->buildBankReconcileRow($statement, $bizType, null, $status, $bankCents, 0, $matchRule);
                $statementStatuses[$statement['statement_id']] = $status;
                continue;
            }

            if (count($candidates) > 1) {
                foreach ($candidates as $candidate) {
                    $bizCents = $this->bankReconcileBizAmountCents($bizType, $candidate);
                    $resultRows[] = $this->buildBankReconcileRow($statement, $bizType, $candidate, 'DUPLICATE', $bankCents, $bizCents, $matchRule);
                    $matchedBiz[$bizType . ':' . $this->bankReconcileBizId($bizType, $candidate)] = true;
                }
                $statementStatuses[$statement['statement_id']] = 'DUPLICATE';
                continue;
            }

            $candidate = $candidates[0];
            $bizCents = $this->bankReconcileBizAmountCents($bizType, $candidate);
            $status = $bankCents === $bizCents ? 'MATCHED' : 'AMOUNT_DIFF';
            $resultRows[] = $this->buildBankReconcileRow($statement, $bizType, $candidate, $status, $bankCents, $bizCents, $matchRule);
            $statementStatuses[$statement['statement_id']] = $status;
            $matchedBiz[$bizType . ':' . $this->bankReconcileBizId($bizType, $candidate)] = true;
        }

        foreach ($this->paymentCandidatesInScope($dateStart, $dateEnd) as $payment) {
            $key = 'PAYMENT:' . $payment['payment_id'];
            if (!isset($matchedBiz[$key]) && !$this->bankStatementExistsByPayment($payment, $bankCode, $dateStart, $dateEnd)) {
                $bizCents = $this->decimalToCents($payment['payment_amount']);
                $resultRows[] = $this->buildBankReconcileRow(null, 'PAYMENT', $payment, 'BIZ_ONLY', 0, $bizCents, 'SERIAL_NO_DATE');
            }
        }

        foreach ($this->refundCandidatesInScope($dateStart, $dateEnd) as $refund) {
            $key = 'REFUND:' . $refund['refund_id'];
            if (!isset($matchedBiz[$key]) && !$this->bankStatementExistsByRefundPayee($refund, $bankCode, $dateStart, $dateEnd)) {
                $bizCents = $this->decimalToCents($refund['refund_amount']);
                $resultRows[] = $this->buildBankReconcileRow(null, 'REFUND', $refund, 'BIZ_ONLY', 0, $bizCents, 'PAYEE_ACCOUNT_DATE');
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
        $generatePaymentVoucherByMethodFlag = $this->generatePaymentVoucherByMethodFlag();
        $generateRefundVoucherByOutOrderNoFlag = $this->generateRefundVoucherByOutOrderNoFlag();

        $configs = [];
        $lookupBusinessItems = $this->subjectConfigLookupItems($bizType, $voucherBizType, $businessItems);
        $rows = $this->getdb(self::TABLE_SUBJECT_CONFIG)->where([
            'account_set_id' => $this->accountSetId,
            'biz_type' => $bizType,
            'voucher_biz_type' => $voucherBizType,
            'del_flag' => 0,
        ])->where('business_item_type', 'in', $lookupBusinessItems)->field(self::SUBJECT_CONFIG_FIELD)->select();
        foreach ($rows as $row) {
            $businessItemKey = $this->voucherSubjectConfigKey($bizType, $voucherBizType, $row['business_item_type']);
            if ($businessItemKey === $row['business_item_type'] || !isset($configs[$businessItemKey])) {
                $configs[$businessItemKey] = $row;
            }
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
            'generate_payment_voucher_by_method_flag' => $generatePaymentVoucherByMethodFlag,
            'generate_refund_voucher_by_out_order_no_flag' => $generateRefundVoucherByOutOrderNoFlag,
            'payment_method_items' => $voucherBizType === 'PAYMENT' ? $this->paymentMethodSubjectConfigItems($bizType, $items) : [],
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
        $generateRefundVoucherByOutOrderNoFlag = $this->normalizeDailyVoucherFlag(
            $data['generate_refund_voucher_by_out_order_no_flag']
            ?? ($data['generateRefundVoucherByOutOrderNoFlag'] ?? $this->generateRefundVoucherByOutOrderNoFlag())
        );
        $generatePaymentVoucherByMethodFlag = $this->normalizeDailyVoucherFlag(
            $data['generate_payment_voucher_by_method_flag']
            ?? ($data['generatePaymentVoucherByMethodFlag'] ?? $this->generatePaymentVoucherByMethodFlag())
        );

        $before = [];
        $after = [];
        $accountSetBefore = null;
        $accountSetAfter = null;
        $seen = [];
        Db::startTrans();
        try {
            list($accountSetBefore, $accountSetAfter) = $this->saveSubjectConfigAccountSetFlag($generateVoucherByDayFlag, $generateRefundVoucherByOutOrderNoFlag, $generatePaymentVoucherByMethodFlag);
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
            if ($voucherBizType === 'PAYMENT') {
                $methodItems = $data['payment_method_items'] ?? ($data['paymentMethodItems'] ?? []);
                $methodSaveResult = $this->savePaymentMethodSubjectConfigs($bizType, $methodItems, $items);
                $before = array_merge($before, $methodSaveResult['before']);
                $after = array_merge($after, $methodSaveResult['after']);
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

        return $this->paymentGenerateVoucherFromRows($rows, 'SELECTED');
    }

    public function paymentGenerateVoucherByQuery($data = [])
    {
        $auth = $this->requirePermission('case_fund:generate_voucher');
        if ($auth) {
            return $auth;
        }

        $result = $this->paymentRowsByGenerateQuery($data);
        if (!empty($result['error'])) {
            return $this->error($result['error']);
        }
        $rows = $result['rows'];
        if (empty($rows)) {
            return $this->error('当前查询条件下没有未制证缴费记录');
        }

        return $this->paymentGenerateVoucherFromRows($rows, 'QUERY');
    }

    protected function paymentRowsByGenerateQuery($data)
    {
        $where = $this->accountWhere();
        $where['fiscal_year'] = $this->currentYear();
        $where['voucher_status'] = 'UNGENERATED';
        if (!empty($data['period'])) {
            $where['period'] = $data['period'];
        }
        $paymentMethodFilter = trim((string)($data['payment_method'] ?? ($data['paymentMethod'] ?? '')));
        if ($paymentMethodFilter !== '') {
            $paymentMethod = $this->normalizePaymentMethodCode($paymentMethodFilter);
            if (isset($this->paymentMethodOptions()[$paymentMethod])) {
                $where['payment_method'] = $paymentMethod;
            }
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
            return ['error' => '当前账套类型不支持案款缴费登记：' . $bizType, 'rows' => []];
        }
        $where['business_type'] = ['in', $allowedBusinessTypes];

        $keyword = trim($data['keyword'] ?? '');
        $query = $this->getdb(self::TABLE_PAYMENT)->where($where);
        if ($keyword !== '') {
            $query->where('case_no|trial_case_no|payer_name|party_name|receipt_no|bank_serial_no|payment_order_no', 'like', '%' . $keyword . '%');
        }
        $rows = $query->field(self::PAYMENT_FIELD)
            ->order('payment_date asc, source_row_no asc')
            ->select();
        return ['error' => '', 'rows' => $rows];
    }

    protected function paymentGenerateVoucherFromRows($rows, $generateScope = 'SELECTED')
    {
        $generationCheck = $this->assertRowsCanGenerateVoucher($rows, '缴费记录');
        if ($generationCheck !== null) {
            return $this->error($generationCheck);
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
            $configKey = $this->voucherSubjectConfigKey($bizType, 'PAYMENT', $config['business_item_type']);
            if ($configKey === $config['business_item_type'] || !isset($subjectConfigMap[$configKey])) {
                $subjectConfigMap[$configKey] = $config;
            }
        }

        $byPaymentMethodFlag = $this->generatePaymentVoucherByMethodFlag();
        $paymentMethodConfigMap = $byPaymentMethodFlag === 1 ? $this->paymentMethodSubjectConfigMap($bizType) : [];

        foreach ($rows as $row) {
            $businessType = $row['business_type'];
            $businessTypeConfigKey = $this->voucherSubjectConfigKey($bizType, 'PAYMENT', $businessType);
            if (!isset($subjectConfigMap[$businessTypeConfigKey])) {
                return $this->error('缴费登记未配置借方/贷方科目');
            }
            $debitCheck = $this->validateVoucherSubjectCode($subjectConfigMap[$businessTypeConfigKey]['debit_subject_code'], '借方科目');
            if ($debitCheck !== null) {
                return $this->error('案号【' . $row['case_no'] . '】' . $debitCheck);
            }
            $creditCheck = $this->validateVoucherSubjectCode($subjectConfigMap[$businessTypeConfigKey]['credit_subject_code'], '贷方科目');
            if ($creditCheck !== null) {
                return $this->error('案号【' . $row['case_no'] . '】' . $creditCheck);
            }
            if ($byPaymentMethodFlag === 1) {
                $paymentMethod = $this->normalizePaymentMethodCode($row['payment_method'] ?? '');
                if (!isset($paymentMethodConfigMap[$paymentMethod])) {
                    return $this->error('案号【' . $row['case_no'] . '】的收费方式【' . $this->paymentMethodLabel($paymentMethod) . '】未配置借方科目');
                }
                $methodDebitCheck = $this->validateVoucherSubjectCode($paymentMethodConfigMap[$paymentMethod]['debit_subject_code'], $this->paymentMethodLabel($paymentMethod) . '借方科目');
                if ($methodDebitCheck !== null) {
                    return $this->error('案号【' . $row['case_no'] . '】' . $methodDebitCheck);
                }
            }
        }

        $byDayFlag = $this->subjectConfigAccountSetFlag();

        $groups = [];
        if ($byDayFlag === 1) {
            foreach ($rows as $row) {
                $date = $row['payment_date'];
                $methodKey = $byPaymentMethodFlag === 1 ? $this->normalizePaymentMethodCode($row['payment_method'] ?? '') : 'ALL';
                $groupKey = $date . '|' . $methodKey;
                if (!isset($groups[$groupKey])) {
                    $groups[$groupKey] = [];
                }
                $groups[$groupKey][] = $row;
            }
        } else {
            foreach ($rows as $row) {
                $methodKey = $byPaymentMethodFlag === 1 ? $this->normalizePaymentMethodCode($row['payment_method'] ?? '') : 'ALL';
                $groups[$row['payment_id'] . '|' . $methodKey] = [$row];
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

                $fiscalYear = $this->fiscalYear($period);
                $voucherTable = 'fin_voucher';
                $detailTable = 'fin_voucher_detail';
                $auxTable = 'fin_voucher_aux_value';

                $voucherId = uuid();
                $voucherNo = $this->generateNextVoucherNo($period);

                $detailBuildResult = $this->buildPaymentVoucherDetailsData($groupRows, $subjectConfigMap, $paymentMethodConfigMap, $byPaymentMethodFlag === 1, $bizType);
                $totalDebit = $detailBuildResult['total_debit'];
                $detailsData = $detailBuildResult['details'];

                if ($totalDebit <= 0) {
                    throw new \Exception('凭证金额必须大于0');
                }
                $this->ensureAuxArchivesForVoucherDetails($detailsData);

                $voucher = [
                    'voucher_id' => $voucherId,
                    'account_set_id' => $this->accountSetId,
                    'fiscal_year' => $fiscalYear,
                    'period' => $period,
                    'voucher_date' => $voucherDate,
                    'voucher_word' => '记',
                    'voucher_no' => $voucherNo,
                    'summary' => $detailsData[0]['summary'] ?? $this->buildPaymentVoucherSummary($firstRow),
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
                'generate_scope' => $generateScope,
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

    protected function buildPaymentVoucherDetailsData($groupRows, $subjectConfigMap, $paymentMethodConfigMap, $byPaymentMethod, $bizType)
    {
        $detailsData = [];
        $totalDebit = 0;
        $lineNo = 1;
        $firstRow = $groupRows[0];
        $paymentMethod = $this->normalizePaymentMethodCode($firstRow['payment_method'] ?? '');

        if ($byPaymentMethod && $paymentMethod === 'POS') {
            $methodConfig = $paymentMethodConfigMap[$paymentMethod];
            $posTotalCents = 0;
            foreach ($groupRows as $row) {
                $posTotalCents += $this->decimalToCents($row['payment_amount']);
            }
            $totalDebit = $posTotalCents;
            $debitConfigs = $this->getSubjectAuxConfigs($methodConfig['debit_subject_code']);
            $detailsData[] = [
                'detail_id' => uuid(),
                'line_no' => $lineNo++,
                'subject_code' => $methodConfig['debit_subject_code'],
                'summary' => $this->buildPaymentMethodVoucherSummary($paymentMethod, $groupRows),
                'debit_amount' => $this->centsToDecimal($posTotalCents),
                'credit_amount' => '0.00',
                'verification_status' => $this->needVerification($debitConfigs) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                'aux_desc' => '',
                'aux_values' => [],
            ];

            foreach ($groupRows as $row) {
                $businessType = $row['business_type'];
                $businessTypeConfigKey = $this->voucherSubjectConfigKey($bizType, 'PAYMENT', $businessType);
                $config = $subjectConfigMap[$businessTypeConfigKey];
                $summary = $this->buildPaymentVoucherSummary($row);
                $amountCents = $this->decimalToCents($row['payment_amount']);
                $amountDecimal = $this->centsToDecimal($amountCents);
                $rawAuxValues = $this->paymentVoucherAuxValues($row);
                $creditConfigs = $this->getSubjectAuxConfigs($config['credit_subject_code']);
                $creditAuxValues = $this->filterAuxValuesBySubjectConfigs($rawAuxValues, $creditConfigs);
                $detailsData[] = [
                    'detail_id' => uuid(),
                    'line_no' => $lineNo++,
                    'subject_code' => $config['credit_subject_code'],
                    'summary' => $summary,
                    'debit_amount' => '0.00',
                    'credit_amount' => $amountDecimal,
                    'verification_status' => $this->needVerification($creditConfigs) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                    'aux_desc' => $this->buildAuxDesc($creditAuxValues),
                    'aux_values' => $creditAuxValues,
                ];
            }

            return ['total_debit' => $totalDebit, 'details' => $detailsData];
        }

        foreach ($groupRows as $row) {
            $businessType = $row['business_type'];
            $businessTypeConfigKey = $this->voucherSubjectConfigKey($bizType, 'PAYMENT', $businessType);
            $config = $subjectConfigMap[$businessTypeConfigKey];
            $summary = $this->buildPaymentVoucherSummary($row);
            $amountCents = $this->decimalToCents($row['payment_amount']);
            $amountDecimal = $this->centsToDecimal($amountCents);
            $totalDebit += $amountCents;

            $debitSubjectCode = $config['debit_subject_code'];
            if ($byPaymentMethod) {
                $rowPaymentMethod = $this->normalizePaymentMethodCode($row['payment_method'] ?? '');
                $debitSubjectCode = $paymentMethodConfigMap[$rowPaymentMethod]['debit_subject_code'];
            }

            $rawAuxValues = $this->paymentVoucherAuxValues($row);
            $debitConfigs = $this->getSubjectAuxConfigs($debitSubjectCode);
            $debitAuxValues = $this->filterAuxValuesBySubjectConfigs($rawAuxValues, $debitConfigs);
            $detailsData[] = [
                'detail_id' => uuid(),
                'line_no' => $lineNo++,
                'subject_code' => $debitSubjectCode,
                'summary' => $summary,
                'debit_amount' => $amountDecimal,
                'credit_amount' => '0.00',
                'verification_status' => $this->needVerification($debitConfigs) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                'aux_desc' => $this->buildAuxDesc($debitAuxValues),
                'aux_values' => $debitAuxValues,
            ];

            $creditConfigs = $this->getSubjectAuxConfigs($config['credit_subject_code']);
            $creditAuxValues = $this->filterAuxValuesBySubjectConfigs($rawAuxValues, $creditConfigs);
            $detailsData[] = [
                'detail_id' => uuid(),
                'line_no' => $lineNo++,
                'subject_code' => $config['credit_subject_code'],
                'summary' => $summary,
                'debit_amount' => '0.00',
                'credit_amount' => $amountDecimal,
                'verification_status' => $this->needVerification($creditConfigs) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                'aux_desc' => $this->buildAuxDesc($creditAuxValues),
                'aux_values' => $creditAuxValues,
            ];
        }

        return ['total_debit' => $totalDebit, 'details' => $detailsData];
    }

    protected function paymentVoucherAuxValues($row)
    {
        return [
            ['aux_type_code' => 'case_no', 'aux_value' => $row['case_no'], 'aux_label' => $row['case_no']],
            ['aux_type_code' => 'receipt_no', 'aux_value' => $row['receipt_no'] ?? '', 'aux_label' => $row['receipt_no'] ?? ''],
        ];
    }

    protected function filterAuxValuesBySubjectConfigs($auxValues, $configs)
    {
        $allowed = [];
        foreach ($configs as $config) {
            $typeCode = trim((string)($config['aux_type_code'] ?? ''));
            if ($typeCode !== '') {
                $allowed[$typeCode] = true;
            }
        }
        if (empty($allowed)) {
            return [];
        }

        $result = [];
        foreach ($auxValues as $aux) {
            $typeCode = trim((string)($aux['aux_type_code'] ?? ''));
            if ($typeCode === '' || empty($allowed[$typeCode])) {
                continue;
            }
            if (!isset($aux['aux_value']) || $aux['aux_value'] === '') {
                continue;
            }
            $result[] = $aux;
        }
        return $result;
    }

    protected function buildPaymentMethodVoucherSummary($paymentMethod, $groupRows)
    {
        $date = $groupRows[0]['payment_date'] ?? '';
        $label = $this->normalizePaymentMethodCode($paymentMethod);
        return trim($date . ' 收 ' . $label . ' 合计');
    }

    public function unknownPaymentGenerateVoucher($data = [])
    {
        $auth = $this->requirePermission('case_fund:generate_voucher');
        if ($auth) {
            return $auth;
        }

        $unknownPaymentIds = $this->normalizeIdList($data['unknown_payment_ids'] ?? ($data['ids'] ?? []));
        if (empty($unknownPaymentIds)) {
            return $this->error('请选择要生成凭证的不明款记录');
        }

        $bizType = $this->currentAccountSetBizType();
        if ($bizType !== 'CASE_FUND') {
            return $this->error('当前账套类型不支持不明款生成凭证：' . $bizType);
        }

        $subjectConfig = $this->getdb(self::TABLE_SUBJECT_CONFIG)
            ->where([
                'account_set_id' => $this->accountSetId,
                'biz_type' => 'CASE_FUND',
                'voucher_biz_type' => 'UNKNOWN_PAYMENT',
                'business_item_type' => '不明款',
                'del_flag' => 0,
            ])
            ->field(self::SUBJECT_CONFIG_FIELD)
            ->find();
        if (!$subjectConfig) {
            return $this->error('不明款未配置借方/贷方科目');
        }

        $debitCheck = $this->validateVoucherSubjectCode($subjectConfig['debit_subject_code'], '借方科目');
        if ($debitCheck !== null) {
            return $this->error($debitCheck);
        }
        $creditCheck = $this->validateVoucherSubjectCode($subjectConfig['credit_subject_code'], '贷方科目');
        if ($creditCheck !== null) {
            return $this->error($creditCheck);
        }

        $debitConfigs = $this->getSubjectAuxConfigs($subjectConfig['debit_subject_code']);
        $creditConfigs = $this->getSubjectAuxConfigs($subjectConfig['credit_subject_code']);
        if ($this->hasRequiredAuxConfigs($debitConfigs) || $this->hasRequiredAuxConfigs($creditConfigs)) {
            return $this->error('不明款借方/贷方科目存在必填辅助核算，不允许自动生成凭证');
        }

        $generatedCount = 0;
        $voucherInfos = [];
        $rows = [];
        $byDayFlag = $this->subjectConfigAccountSetFlag();

        Db::startTrans();
        try {
            $rows = $this->getdb(self::TABLE_UNKNOWN_PAYMENT)
                ->where(['account_set_id' => $this->accountSetId, 'fiscal_year' => $this->currentYear(), 'del_flag' => 0])
                ->where('unknown_payment_id', 'in', $unknownPaymentIds)
                ->field(self::UNKNOWN_PAYMENT_FIELD)
                ->order('transaction_date asc, source_row_no asc')
                ->lock(true)
                ->select();

            if (count($rows) !== count($unknownPaymentIds)) {
                throw new \Exception('所选不明款记录不存在或已被删除，请刷新后重试');
            }

            foreach ($rows as &$row) {
                $row['case_no'] = '不明款';
            }
            unset($row);

            $generationCheck = $this->assertRowsCanGenerateVoucher($rows, '不明款记录');
            if ($generationCheck !== null) {
                throw new \Exception($generationCheck);
            }

            $groups = [];
            if ($byDayFlag === 1) {
                foreach ($rows as $row) {
                    $date = $row['transaction_date'];
                    if (!isset($groups[$date])) {
                        $groups[$date] = [];
                    }
                    $groups[$date][] = $row;
                }
            } else {
                foreach ($rows as $row) {
                    $groups[$row['unknown_payment_id']] = [$row];
                }
            }

            foreach ($groups as $groupRows) {
                $firstRow = $groupRows[0];
                $period = substr($firstRow['transaction_date'], 0, 7);
                $voucherDate = $firstRow['transaction_date'];
                foreach ($groupRows as $row) {
                    $periodCheck = $this->validateUnknownPaymentVoucherPeriod($row, $period);
                    if ($periodCheck !== null) {
                        throw new \Exception($periodCheck);
                    }
                }

                $fiscalYear = $this->fiscalYear($period);
                $voucherTable = 'fin_voucher';
                $detailTable = 'fin_voucher_detail';

                $voucherId = uuid();
                $voucherNo = $this->generateNextVoucherNo($period);

                $totalDebit = 0;
                $detailsData = [];
                $lineNo = 1;

                foreach ($groupRows as $row) {
                    $summary = $this->buildUnknownPaymentVoucherSummary($row);
                    $amountCents = $this->decimalToCents($row['unknown_amount']);
                    $amountDecimal = $this->centsToDecimal($amountCents);
                    $totalDebit += $amountCents;

                    $detailsData[] = [
                        'detail_id' => uuid(),
                        'line_no' => $lineNo++,
                        'subject_code' => $subjectConfig['debit_subject_code'],
                        'summary' => $summary,
                        'debit_amount' => $amountDecimal,
                        'credit_amount' => '0.00',
                        'verification_status' => 'NOT_REQUIRED',
                        'aux_desc' => '',
                    ];
                    $detailsData[] = [
                        'detail_id' => uuid(),
                        'line_no' => $lineNo++,
                        'subject_code' => $subjectConfig['credit_subject_code'],
                        'summary' => $summary,
                        'debit_amount' => '0.00',
                        'credit_amount' => $amountDecimal,
                        'verification_status' => 'NOT_REQUIRED',
                        'aux_desc' => '',
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
                    'summary' => $this->buildUnknownPaymentVoucherSummary($firstRow),
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
                }

                foreach ($groupRows as $row) {
                    $affectedRows = $this->getdb(self::TABLE_UNKNOWN_PAYMENT)->where([
                        'unknown_payment_id' => $row['unknown_payment_id'],
                        'account_set_id' => $this->accountSetId,
                        'fiscal_year' => $this->currentYear(),
                        'del_flag' => 0,
                    ])->update([
                        'voucher_status' => 'GENERATED',
                        'voucher_id' => $voucherId,
                        'voucher_no' => $voucherNo,
                        'voucher_period' => $period,
                        'voucher_generated_time' => $this->now(),
                        'updated_by' => $this->userid,
                        'updated_time' => $this->now(),
                    ]);
                    if ((int)$affectedRows !== 1) {
                        throw new \Exception('不明款记录状态已变化，请刷新后重试');
                    }
                }

                $generatedCount++;
                $voucherInfos[] = [
                    'voucher_id' => $voucherId,
                    'voucher_no' => $voucherNo,
                    'period' => $period,
                ];
            }

            $this->logAudit('CASE_FUND_UNKNOWN_PAYMENT', 'VOUCHER_GENERATE', 'GENERATE', null, [
                'unknown_payment_count' => count($rows),
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
            'unknown_payment_count' => count($rows),
            'vouchers' => $voucherInfos,
        ], '成功生成 ' . $generatedCount . ' 张凭证');
    }

    public function distributionGenerateVoucher($data = [])
    {
        $auth = $this->requirePermission('case_fund:generate_voucher');
        if ($auth) {
            return $auth;
        }

        $distributionIds = $this->normalizeIdList($data['distribution_ids'] ?? ($data['ids'] ?? []));
        if (empty($distributionIds)) {
            return $this->error('请选择要生成凭证的案款分配记录');
        }

        $bizType = $this->currentAccountSetBizType();
        if ($bizType !== 'CASE_FUND') {
            return $this->error('当前账套类型不支持案款分配生成凭证：' . $bizType);
        }

        $configRows = $this->getdb(self::TABLE_SUBJECT_CONFIG)
            ->where([
                'account_set_id' => $this->accountSetId,
                'biz_type' => 'CASE_FUND',
                'voucher_biz_type' => 'DISTRIBUTION',
                'del_flag' => 0,
            ])
            ->field(self::SUBJECT_CONFIG_FIELD)
            ->select();

        $subjectConfigMap = [];
        foreach ($configRows as $config) {
            $subjectConfigMap[$config['business_item_type']] = $config;
        }
        if (empty($subjectConfigMap)) {
            return $this->error('案款分配未配置借方/贷方科目');
        }

        $generatedCount = 0;
        $voucherInfos = [];
        $rows = [];

        Db::startTrans();
        try {
            $rows = $this->getdb(self::TABLE_DISTRIBUTION)
                ->where(['account_set_id' => $this->accountSetId, 'fiscal_year' => $this->currentYear(), 'del_flag' => 0])
                ->where('distribution_id', 'in', $distributionIds)
                ->field(self::DISTRIBUTION_FIELD)
                ->order('receive_date asc, distribution_no asc, source_row_no asc')
                ->lock(true)
                ->select();

            if (count($rows) !== count($distributionIds)) {
                throw new \Exception('所选案款分配记录不存在或已被删除，请刷新后重试');
            }

            foreach ($rows as &$row) {
                $row['case_no'] = $row['out_case_no'] ?? '';
            }
            unset($row);

            $generationCheck = $this->assertRowsCanGenerateVoucher($rows, '案款分配记录');
            if ($generationCheck !== null) {
                throw new \Exception($generationCheck);
            }

            foreach ($rows as $row) {
                $invoiceType = $row['invoice_type'];
                if (!isset($subjectConfigMap[$invoiceType])) {
                    throw new \Exception('分配单号【' . $row['distribution_no'] . '】的开票种类【' . $invoiceType . '】未配置借方/贷方科目');
                }
                $debitCheck = $this->validateVoucherSubjectCode($subjectConfigMap[$invoiceType]['debit_subject_code'], '借方科目');
                if ($debitCheck !== null) {
                    throw new \Exception('分配单号【' . $row['distribution_no'] . '】' . $debitCheck);
                }
                $creditCheck = $this->validateVoucherSubjectCode($subjectConfigMap[$invoiceType]['credit_subject_code'], '贷方科目');
                if ($creditCheck !== null) {
                    throw new \Exception('分配单号【' . $row['distribution_no'] . '】' . $creditCheck);
                }
            }

            $groups = $this->groupDistributionRowsForVoucher($rows);
            foreach ($groups as $groupRows) {
                $firstRow = $groupRows[0];
                $period = substr($firstRow['receive_date'], 0, 7);
                $voucherDate = $firstRow['receive_date'];
                foreach ($groupRows as $row) {
                    $periodCheck = $this->validateDistributionVoucherPeriod($row, $period);
                    if ($periodCheck !== null) {
                        throw new \Exception($periodCheck);
                    }
                }

                $fiscalYear = $this->fiscalYear($period);
                $voucherTable = 'fin_voucher';
                $detailTable = 'fin_voucher_detail';
                $auxTable = 'fin_voucher_aux_value';

                $voucherId = uuid();
                $voucherNo = $this->generateNextVoucherNo($period);

                $detailBuildResult = $this->buildDistributionVoucherDetailsData($groupRows, $subjectConfigMap);
                $detailsData = $detailBuildResult['details'];
                $totalDebit = $detailBuildResult['total_cents'];

                if ($totalDebit <= 0) {
                    throw new \Exception('凭证金额必须大于0');
                }
                $this->ensureAuxArchivesForVoucherDetails($detailsData);

                $voucher = [
                    'voucher_id' => $voucherId,
                    'account_set_id' => $this->accountSetId,
                    'fiscal_year' => $fiscalYear,
                    'period' => $period,
                    'voucher_date' => $voucherDate,
                    'voucher_word' => '记',
                    'voucher_no' => $voucherNo,
                    'summary' => $this->buildDistributionVoucherHeaderSummary($firstRow),
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
                    $affectedRows = $this->getdb(self::TABLE_DISTRIBUTION)->where([
                        'distribution_id' => $row['distribution_id'],
                        'account_set_id' => $this->accountSetId,
                        'fiscal_year' => $this->currentYear(),
                        'del_flag' => 0,
                    ])->update([
                        'voucher_status' => 'GENERATED',
                        'voucher_id' => $voucherId,
                        'voucher_no' => $voucherNo,
                        'voucher_period' => $period,
                        'voucher_generated_time' => $this->now(),
                        'updated_by' => $this->userid,
                        'updated_time' => $this->now(),
                    ]);
                    if ((int)$affectedRows !== 1) {
                        throw new \Exception('案款分配记录状态已变化，请刷新后重试');
                    }
                }

                $generatedCount++;
                $voucherInfos[] = [
                    'voucher_id' => $voucherId,
                    'voucher_no' => $voucherNo,
                    'period' => $period,
                ];
            }

            $this->logAudit('CASE_FUND_DISTRIBUTION', 'VOUCHER_GENERATE', 'GENERATE', null, [
                'distribution_count' => count($rows),
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
            'distribution_count' => count($rows),
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
            ->order('refund_date asc, source_row_no asc')
            ->select();

        if (empty($rows)) {
            return $this->error('所选退付记录不存在');
        }

        return $this->refundGenerateVoucherFromRows($rows, 'SELECTED');
    }

    public function refundGenerateVoucherByQuery($data = [])
    {
        $auth = $this->requirePermission('case_fund:generate_voucher');
        if ($auth) {
            return $auth;
        }

        $result = $this->refundRowsByGenerateQuery($data);
        if (!empty($result['error'])) {
            return $this->error($result['error']);
        }
        $rows = $result['rows'];
        if (empty($rows)) {
            return $this->error('当前查询条件下没有未制证出账记录');
        }

        return $this->refundGenerateVoucherFromRows($rows, 'QUERY');
    }

    protected function refundRowsByGenerateQuery($data)
    {
        $where = $this->accountWhere();
        $where['fiscal_year'] = $this->currentYear();
        $where['voucher_status'] = 'UNGENERATED';
        if (!empty($data['period'])) {
            $where['period'] = $data['period'];
        }
        $dateStart = $data['date_start'] ?? ($data['startDate'] ?? '');
        $dateEnd = $data['date_end'] ?? ($data['endDate'] ?? '');
        $this->applyDateRangeFilter($where, 'refund_date', $dateStart, $dateEnd);
        if (!empty($data['out_status'])) {
            $where['out_status'] = $data['out_status'];
        }
        $bizType = $this->currentAccountSetBizType();
        $allowedOutTypes = $this->allowedRefundOutTypes($bizType);
        if (empty($allowedOutTypes)) {
            return ['error' => '当前账套类型不支持案款退付登记：' . $bizType, 'rows' => []];
        }
        $where['out_type'] = ['in', $allowedOutTypes];

        $caseNo = trim($data['case_no'] ?? ($data['caseNo'] ?? ''));
        $partyName = trim($data['party_name'] ?? ($data['partyName'] ?? ''));
        $keyword = trim($data['keyword'] ?? '');
        $query = $this->getdb(self::TABLE_REFUND)->where($where);
        if ($caseNo !== '') {
            $query->where('case_no', 'like', '%' . $caseNo . '%');
        }
        if ($partyName !== '') {
            $query->where('party_name|actual_payee_name|payee_bank_account_name', 'like', '%' . $partyName . '%');
        }
        if ($keyword !== '') {
            $query->where('case_no|party_name|actual_payee_name|receipt_no|source_receipt_no|out_order_no|payee_bank_account_no', 'like', '%' . $keyword . '%');
        }
        $rows = $query->field(self::REFUND_FIELD)
            ->order('refund_date asc, source_row_no asc')
            ->select();
        return ['error' => '', 'rows' => $rows];
    }

    protected function refundGenerateVoucherFromRows($rows, $generateScope = 'SELECTED')
    {
        $generationCheck = $this->assertRowsCanGenerateVoucher($rows, '退付记录');
        if ($generationCheck !== null) {
            return $this->error($generationCheck);
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
            $configKey = $this->voucherSubjectConfigKey($bizType, 'REFUND', $config['business_item_type']);
            if ($configKey === $config['business_item_type'] || !isset($subjectConfigMap[$configKey])) {
                $subjectConfigMap[$configKey] = $config;
            }
        }

        foreach ($rows as $row) {
            $outType = $row['out_type'];
            $outTypeConfigKey = $this->voucherSubjectConfigKey($bizType, 'REFUND', $outType);
            if (!isset($subjectConfigMap[$outTypeConfigKey])) {
                return $this->error('出账查询未配置借方/贷方科目');
            }
            $debitCheck = $this->validateVoucherSubjectCode($subjectConfigMap[$outTypeConfigKey]['debit_subject_code'], '借方科目');
            if ($debitCheck !== null) {
                return $this->error('案号【' . $row['case_no'] . '】' . $debitCheck);
            }
            $creditCheck = $this->validateVoucherSubjectCode($subjectConfigMap[$outTypeConfigKey]['credit_subject_code'], '贷方科目');
            if ($creditCheck !== null) {
                return $this->error('案号【' . $row['case_no'] . '】' . $creditCheck);
            }
        }

        $byDayFlag = $this->subjectConfigAccountSetFlag();
        $byOutOrderNoFlag = $this->generateRefundVoucherByOutOrderNoFlag();
        try {
            $groups = $this->groupRefundRowsForVoucher($rows, $byDayFlag, $byOutOrderNoFlag);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }

        $generatedCount = 0;
        $voucherInfos = [];

        Db::startTrans();
        try {
            foreach ($groups as $groupRows) {
                $firstRow = $groupRows[0];
                $period = substr($firstRow['refund_date'], 0, 7);
                $voucherDate = $firstRow['refund_date'];
                $aggregateByOutOrderNo = $byDayFlag === 1 && $byOutOrderNoFlag === 1;

                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $voucherDate)) {
                    throw new \Exception('出账日期格式不正确：' . $voucherDate);
                }
                if (substr($voucherDate, 0, 7) !== $period) {
                    throw new \Exception('出账日期不在会计期间内：' . $voucherDate);
                }
                if ($aggregateByOutOrderNo) {
                    foreach ($groupRows as $row) {
                        if (substr($row['refund_date'], 0, 7) !== $period) {
                            throw new \Exception('同一出账单号【' . $firstRow['out_order_no'] . '】跨会计期间，不允许汇总生成一张凭证');
                        }
                    }
                }

                $fiscalYear = $this->fiscalYear($period);
                $voucherTable = 'fin_voucher';
                $detailTable = 'fin_voucher_detail';
                $auxTable = 'fin_voucher_aux_value';

                $voucherId = uuid();
                $voucherNo = $this->generateNextVoucherNo($period);

                $detailBuildResult = $this->buildRefundVoucherDetailsData($groupRows, $subjectConfigMap, $aggregateByOutOrderNo, $bizType);
                $detailsData = $detailBuildResult['details'];
                $totalDebit = $detailBuildResult['total_cents'];

                if ($totalDebit <= 0) {
                    throw new \Exception('凭证金额必须大于0');
                }
                $this->ensureAuxArchivesForVoucherDetails($detailsData);

                $voucher = [
                    'voucher_id' => $voucherId,
                    'account_set_id' => $this->accountSetId,
                    'fiscal_year' => $fiscalYear,
                    'period' => $period,
                    'voucher_date' => $voucherDate,
                    'voucher_word' => '记',
                    'voucher_no' => $voucherNo,
                    'summary' => $this->buildRefundVoucherHeaderSummary($firstRow, $aggregateByOutOrderNo, $voucherDate),
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
                'generate_scope' => $generateScope,
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

    protected function assertRowsCanGenerateVoucher($rows, $recordLabel)
    {
        foreach ($rows as $row) {
            $status = $row['voucher_status'] ?? 'UNGENERATED';
            if ($status === 'UNGENERATED') {
                continue;
            }
            if ($status === 'GENERATED' && !$this->hasActiveGeneratedVoucher($row)) {
                continue;
            }
            if ($status === 'GENERATED') {
                return '案号【' . $row['case_no'] . '】的' . $recordLabel . '已生成凭证且原凭证仍有效，不允许重复生成';
            }
            return '案号【' . $row['case_no'] . '】的' . $recordLabel . '制证状态为【' . $status . '】，不允许生成凭证';
        }
        return null;
    }

    protected function hasActiveGeneratedVoucher($row)
    {
        $voucherId = trim((string)($row['voucher_id'] ?? ''));
        if ($voucherId === '') {
            return false;
        }
        $where = [
            'account_set_id' => $this->accountSetId,
            'voucher_id' => $voucherId,
            'del_flag' => 0,
        ];
        $period = trim((string)($row['voucher_period'] ?? ''));
        if ($period !== '') {
            $where['period'] = $period;
        }
        return (int)$this->getdb('fin_voucher')->where($where)->count() > 0;
    }

    protected function groupRefundRowsForVoucher($rows, $byDayFlag, $byOutOrderNoFlag)
    {
        $groups = [];
        if ((int)$byDayFlag === 1) {
            foreach ($rows as $row) {
                $date = $row['refund_date'];
                if (!isset($groups[$date])) {
                    $groups[$date] = [];
                }
                $groups[$date][] = $row;
            }
            return $groups;
        }

        foreach ($rows as $row) {
            $groups[$row['refund_id']] = [$row];
        }
        return $groups;
    }

    protected function groupDistributionRowsForVoucher($rows)
    {
        $groups = [];
        foreach ($rows as $row) {
            $distributionNo = trim((string)($row['distribution_no'] ?? ''));
            if ($distributionNo === '') {
                $distributionNo = $row['distribution_id'] ?? uuid();
            }
            if (!isset($groups[$distributionNo])) {
                $groups[$distributionNo] = [];
            }
            $groups[$distributionNo][] = $row;
        }
        return $groups;
    }

    protected function buildRefundVoucherDetailsData($groupRows, $subjectConfigMap, $aggregateByOutOrderNo, $bizType = '')
    {
        $detailsData = [];
        $totalDebit = 0;
        $lineNo = 1;
        $outOrderCounts = [];
        $processedOutOrders = [];

        if ($aggregateByOutOrderNo) {
            foreach ($groupRows as $row) {
                $outOrderNo = trim((string)($row['out_order_no'] ?? ''));
                if ($outOrderNo === '') {
                    continue;
                }
                $outOrderCounts[$outOrderNo] = ($outOrderCounts[$outOrderNo] ?? 0) + 1;
            }
        }

        foreach ($groupRows as $row) {
            $outOrderNo = trim((string)($row['out_order_no'] ?? ''));
            $shouldAggregate = $aggregateByOutOrderNo
                && $outOrderNo !== ''
                && ($outOrderCounts[$outOrderNo] ?? 0) > 1;

            if ($shouldAggregate) {
                if (isset($processedOutOrders[$outOrderNo])) {
                    continue;
                }
                $processedOutOrders[$outOrderNo] = true;
                $aggregateAmountCents = 0;
                $aggregateCreditSubjectCode = '';
                $aggregateCreditConfigs = [];

                foreach ($groupRows as $bucketRow) {
                    if (trim((string)($bucketRow['out_order_no'] ?? '')) !== $outOrderNo) {
                        continue;
                    }
                    $outType = $bucketRow['out_type'];
                    $outTypeConfigKey = $this->voucherSubjectConfigKey($bizType, 'REFUND', $outType);
                    $config = $subjectConfigMap[$outTypeConfigKey];
                    $creditConfigs = $this->getSubjectAuxConfigs($config['credit_subject_code']);
                    if ($aggregateCreditSubjectCode === '') {
                        $aggregateCreditSubjectCode = $config['credit_subject_code'];
                        $aggregateCreditConfigs = $creditConfigs;
                    } elseif ($aggregateCreditSubjectCode !== $config['credit_subject_code']) {
                        throw new \Exception('同一出账单号【' . $bucketRow['out_order_no'] . '】存在不同贷方科目配置，不允许汇总生成一张凭证');
                    }
                    if ($this->hasRequiredAuxConfigs($creditConfigs)) {
                        throw new \Exception('贷方科目【' . $config['credit_subject_code'] . '】存在必填辅助核算，不允许按出账单号合并为一条贷方分录');
                    }

                    $debitResult = $this->buildRefundDebitDetailData($bucketRow, $config, $lineNo++, true);
                    $detailsData[] = $debitResult['detail'];
                    $aggregateAmountCents += $debitResult['amount_cents'];
                    $totalDebit += $debitResult['amount_cents'];
                }

                $detailsData[] = $this->buildRefundAggregateCreditDetail(
                    uuid(),
                    $lineNo++,
                    $aggregateCreditSubjectCode,
                    $this->buildRefundAggregateCreditSummary($outOrderNo),
                    $aggregateAmountCents,
                    $aggregateCreditConfigs
                );
                continue;
            }

            $outType = $row['out_type'];
            $outTypeConfigKey = $this->voucherSubjectConfigKey($bizType, 'REFUND', $outType);
            $config = $subjectConfigMap[$outTypeConfigKey];
            $debitResult = $this->buildRefundDebitDetailData($row, $config, $lineNo++, true);
            $detailsData[] = $debitResult['detail'];
            $totalDebit += $debitResult['amount_cents'];

            $creditDetailId = uuid();
            $creditConfigs = $this->getSubjectAuxConfigs($config['credit_subject_code']);
            $creditAuxValues = $this->filterAuxValuesBySubjectConfigs($debitResult['raw_aux_values'], $creditConfigs);
            $detailsData[] = [
                'detail_id' => $creditDetailId,
                'line_no' => $lineNo++,
                'subject_code' => $config['credit_subject_code'],
                'summary' => $debitResult['summary'],
                'debit_amount' => '0.00',
                'credit_amount' => $debitResult['amount_decimal'],
                'verification_status' => $this->needVerification($creditConfigs) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                'aux_desc' => $this->buildAuxDesc($creditAuxValues),
                'aux_values' => $creditAuxValues,
            ];
        }

        return [
            'details' => $detailsData,
            'total_cents' => $totalDebit,
        ];
    }

    protected function buildDistributionVoucherDetailsData($groupRows, $subjectConfigMap)
    {
        $detailsData = [];
        $totalDebit = 0;
        $lineNo = 1;

        foreach ($groupRows as $row) {
            $invoiceType = $row['invoice_type'];
            $config = $subjectConfigMap[$invoiceType];
            $amountCents = $this->decimalToCents($row['distribution_amount']);
            $amountDecimal = $this->centsToDecimal($amountCents);
            $totalDebit += $amountCents;

            $outAuxValues = [
                ['aux_type_code' => 'case_no', 'aux_value' => $row['out_case_no'], 'aux_label' => $row['out_case_no']],
                ['aux_type_code' => 'receipt_no', 'aux_value' => $row['receipt_no'], 'aux_label' => $row['receipt_no']],
            ];
            $inAuxValues = [
                ['aux_type_code' => 'case_no', 'aux_value' => $row['in_case_no'], 'aux_label' => $row['in_case_no']],
                ['aux_type_code' => 'receipt_no', 'aux_value' => $row['receipt_no'], 'aux_label' => $row['receipt_no']],
            ];

            $debitConfigs = $this->getSubjectAuxConfigs($config['debit_subject_code']);
            $outAuxValues = $this->filterAuxValuesBySubjectConfigs($outAuxValues, $debitConfigs);
            $detailsData[] = [
                'detail_id' => uuid(),
                'line_no' => $lineNo++,
                'subject_code' => $config['debit_subject_code'],
                'summary' => $this->buildDistributionTransferOutSummary($row),
                'debit_amount' => $amountDecimal,
                'credit_amount' => '0.00',
                'verification_status' => $this->needVerification($debitConfigs) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                'aux_desc' => $this->buildAuxDesc($outAuxValues),
                'aux_values' => $outAuxValues,
            ];

            $creditConfigs = $this->getSubjectAuxConfigs($config['credit_subject_code']);
            $inAuxValues = $this->filterAuxValuesBySubjectConfigs($inAuxValues, $creditConfigs);
            $detailsData[] = [
                'detail_id' => uuid(),
                'line_no' => $lineNo++,
                'subject_code' => $config['credit_subject_code'],
                'summary' => $this->buildDistributionTransferInSummary($row),
                'debit_amount' => '0.00',
                'credit_amount' => $amountDecimal,
                'verification_status' => $this->needVerification($creditConfigs) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                'aux_desc' => $this->buildAuxDesc($inAuxValues),
                'aux_values' => $inAuxValues,
            ];
        }

        return [
            'details' => $detailsData,
            'total_cents' => $totalDebit,
        ];
    }

    protected function buildRefundDebitDetailData($row, $config, $lineNo, $useNormalSummary = false)
    {
        $summary = $useNormalSummary
            ? $this->buildRefundNormalVoucherSummary($row)
            : trim($row['case_no'] . ' ' . ($row['party_name'] ?: $row['actual_payee_name']) . ' 退付');
        $amountCents = $this->decimalToCents($row['refund_amount']);
        $amountDecimal = $this->centsToDecimal($amountCents);
        $receiptNo = $row['source_receipt_no'] ?: ($row['receipt_no'] ?? '');
        $auxValues = [
            ['aux_type_code' => 'case_no', 'aux_value' => $row['case_no'], 'aux_label' => $row['case_no']],
            ['aux_type_code' => 'receipt_no', 'aux_value' => $receiptNo, 'aux_label' => $receiptNo],
        ];
        $debitConfigs = $this->getSubjectAuxConfigs($config['debit_subject_code']);
        $filteredAuxValues = $this->filterAuxValuesBySubjectConfigs($auxValues, $debitConfigs);
        $auxDesc = $this->buildAuxDesc($filteredAuxValues);

        return [
            'detail' => [
                'detail_id' => uuid(),
                'line_no' => $lineNo,
                'subject_code' => $config['debit_subject_code'],
                'summary' => $summary,
                'debit_amount' => $amountDecimal,
                'credit_amount' => '0.00',
                'verification_status' => $this->needVerification($debitConfigs) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                'aux_desc' => $auxDesc,
                'aux_values' => $filteredAuxValues,
            ],
            'summary' => $summary,
            'amount_cents' => $amountCents,
            'amount_decimal' => $amountDecimal,
            'aux_desc' => $auxDesc,
            'aux_values' => $filteredAuxValues,
            'raw_aux_values' => $auxValues,
        ];
    }

    protected function buildPaymentVoucherSummary($row)
    {
        return trim('收 ' . ($row['case_no'] ?? '') . ' ' . ($row['party_name'] ?? ''));
    }

    protected function buildRefundVoucherHeaderSummary($row, $aggregateByOutOrderNo, $voucherDate)
    {
        if ($aggregateByOutOrderNo) {
            return $voucherDate . ' 付合计';
        }
        return $this->buildRefundNormalVoucherSummary($row);
    }

    protected function buildRefundNormalVoucherSummary($row)
    {
        return trim('付 ' . ($row['case_no'] ?? '') . ' ' . (($row['party_name'] ?? '') ?: ($row['actual_payee_name'] ?? '')));
    }

    protected function buildRefundAggregateCreditSummary($outOrderNo)
    {
        return trim('付 出账单号' . $outOrderNo . ' 合计');
    }

    protected function buildDistributionVoucherHeaderSummary($row)
    {
        return trim('分配 ' . ($row['distribution_no'] ?? '') . ' 合计');
    }

    protected function buildDistributionTransferOutSummary($row)
    {
        return trim('分配 ' . ($row['distribution_no'] ?? '') . ' 转出 ' . ($row['out_case_no'] ?? '') . ' ' . ($row['out_party_name'] ?? ''));
    }

    protected function buildDistributionTransferInSummary($row)
    {
        return trim('分配 ' . ($row['distribution_no'] ?? '') . ' 转入 ' . ($row['in_case_no'] ?? '') . ' ' . ($row['in_party_name'] ?? ''));
    }

    protected function buildUnknownPaymentVoucherSummary($row)
    {
        $remark = trim((string)($row['bank_remark'] ?? ''));
        if ($remark === '') {
            $remark = trim((string)($row['bank_summary'] ?? ''));
        }
        return trim('收 不明款 ' . ($row['payer_name'] ?? '') . ' ' . $remark);
    }

    protected function buildRefundAggregateCreditDetail($detailId, $lineNo, $subjectCode, $summary, $amountCents, $configs)
    {
        return [
            'detail_id' => $detailId,
            'line_no' => $lineNo,
            'subject_code' => $subjectCode,
            'summary' => $summary,
            'debit_amount' => '0.00',
            'credit_amount' => $this->centsToDecimal($amountCents),
            'verification_status' => $this->needVerification($configs) ? 'UNVERIFIED' : 'NOT_REQUIRED',
            'aux_desc' => '',
            'aux_values' => [],
        ];
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

    protected function ensureAuxArchivesForVoucherDetails($detailsData)
    {
        $caseNos = [];
        $receiptNos = [];
        foreach ($detailsData as $detail) {
            foreach (($detail['aux_values'] ?? []) as $aux) {
                $typeCode = trim((string)($aux['aux_type_code'] ?? ''));
                $value = trim((string)($aux['aux_value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                if ($typeCode === 'case_no') {
                    $caseNos[] = $value;
                } elseif ($typeCode === 'receipt_no') {
                    $receiptNos[] = $value;
                }
            }
        }
        if (!empty($caseNos)) {
            $this->ensureAuxType('case_no', '案号');
            $this->ensureAuxArchivesByType('case_no', array_unique($caseNos));
        }
        if (!empty($receiptNos)) {
            $this->ensureAuxType('receipt_no', '收据号');
            $this->ensureAuxArchivesByType('receipt_no', array_unique($receiptNos));
        }
    }

    protected function ensureDistributionAuxArchives($rows)
    {
        $caseNos = [];
        $receiptNos = [];
        foreach ($rows as $row) {
            if (!empty($row['in_case_no'])) {
                $caseNos[] = $row['in_case_no'];
            }
            if (!empty($row['out_case_no'])) {
                $caseNos[] = $row['out_case_no'];
            }
            if (!empty($row['receipt_no'])) {
                $receiptNos[] = $row['receipt_no'];
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

    protected function hasRequiredAuxConfigs($configs)
    {
        foreach ($configs as $config) {
            if ((int)$config['required_flag'] === 1) {
                return true;
            }
        }
        return false;
    }

    protected function validateUnknownPaymentVoucherPeriod($row, $period)
    {
        $transactionDate = (string)($row['transaction_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionDate)) {
            return '不明款交易日期格式不正确：' . $transactionDate;
        }
        $sourcePeriod = (string)($row['period'] ?? '');
        if ($sourcePeriod !== $period || substr($transactionDate, 0, 7) !== $period) {
            return '不明款交易日期与会计期间不一致：' . $transactionDate;
        }
        $periodRow = $this->getdb('fin_fiscal_period')->where([
            'account_set_id' => $this->accountSetId,
            'period' => $period,
            'status' => 'OPEN',
            'del_flag' => 0,
        ])->find();
        if (!$periodRow || ($periodRow['status'] ?? '') !== 'OPEN') {
            return '不明款交易日期所属会计期间不是打开状态：' . $period;
        }
        return null;
    }

    protected function validateDistributionVoucherPeriod($row, $period)
    {
        $receiveDate = (string)($row['receive_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiveDate)) {
            return '案款分配接收日期格式不正确：' . $receiveDate;
        }
        $sourcePeriod = (string)($row['period'] ?? '');
        if ($sourcePeriod !== $period || substr($receiveDate, 0, 7) !== $period) {
            return '案款分配接收日期与会计期间不一致：' . $receiveDate;
        }
        $periodRow = $this->getdb('fin_fiscal_period')->where([
            'account_set_id' => $this->accountSetId,
            'period' => $period,
            'status' => 'OPEN',
            'del_flag' => 0,
        ])->find();
        if (!$periodRow || ($periodRow['status'] ?? '') !== 'OPEN') {
            return '案款分配接收日期所属会计期间不是打开状态：' . $period;
        }
        return null;
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
                'payment_method' => $this->inferPaymentMethodByBankSerialNo($raw['银行流水号'], $raw['收费方式']),
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

    protected function parseRefundImportRowsFromXls($binary, $bizType = '', $receiptTraceRequired = null)
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
        $required = $this->refundImportRequiredHeaders($bizType, $receiptTraceRequired);
        $optional = ['总出账金额'];
        if ($bizType === 'LITIGATION_FEE' || $receiptTraceRequired === false) {
            foreach (['来源票据号码', '来源票据开票日期'] as $name) {
                $optional[] = $name;
            }
        }
        if ($bizType === 'CASE_FUND' && $receiptTraceRequired === false) {
            $optional[] = '出账单号';
        }
        $optionalMap = array_fill_keys($optional, true);
        $index = [];
        foreach (array_merge($required, $optional) as $name) {
            $found = array_search($name, $headers, true);
            if ($found === false) {
                if (isset($optionalMap[$name])) {
                    continue;
                }
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
            foreach ($optional as $name) {
                if (!isset($raw[$name])) {
                    $raw[$name] = '';
                }
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

    protected function refundImportRequiredHeaders($bizType = '', $receiptTraceRequired = null)
    {
        $headers = [
            '案号', '经办人', '书记员', '票据号码', '开票日期', '出账日期',
            '来源票据号码', '来源票据开票日期', '出账单号', '出账状态', '出账种类',
            '诉讼地位', '当事人', '出账金额', '收款人与当事人关系',
            '支付方式', '实际收款人', '身份证号/企业', '收款人银行户名', '收款账号',
            '开户银行', '银联号', '是否本行', '承办人情况说明', '申请人',
        ];
        if ($bizType === 'LITIGATION_FEE') {
            $headers = array_values(array_diff($headers, ['来源票据号码', '来源票据开票日期']));
        }
        if ($bizType === 'CASE_FUND' && $receiptTraceRequired === false) {
            $headers = array_values(array_diff($headers, ['来源票据号码', '来源票据开票日期', '出账单号']));
        }
        return $headers;
    }

    protected function parseUnknownPaymentImportRowsFromXls($binary)
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
            '法院名称', '认领状态', '缴款人', '金额', '银行摘要', '银行备注',
            '交易日期', '法院银行账户', '到账方式', '缴款人开户行', '缴款人账号',
            '认领人姓名', '认领日期', '最后修改时间',
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

            $transactionTime = $this->normalizeDateTimeValue($raw['交易日期']);
            $transactionDate = $transactionTime === '' ? '' : substr($transactionTime, 0, 10);
            $lastModifiedTime = $this->normalizeDateTimeValue($raw['最后修改时间']);
            $unknownAmount = $this->centsToDecimal($this->decimalToCents($raw['金额']));
            $parsed = [
                'fiscal_year' => $transactionDate === '' ? '' : substr($transactionDate, 0, 4),
                'period' => $transactionDate === '' ? '' : substr($transactionDate, 0, 7),
                'court_name' => $raw['法院名称'],
                'claim_status' => $raw['认领状态'],
                'payer_name' => $raw['缴款人'],
                'unknown_amount' => $unknownAmount,
                'bank_summary' => $raw['银行摘要'],
                'bank_remark' => $raw['银行备注'],
                'transaction_date' => $transactionDate,
                'transaction_time' => $transactionTime,
                'court_bank_account_no' => $raw['法院银行账户'],
                'arrival_method' => $raw['到账方式'],
                'payer_bank_name' => $raw['缴款人开户行'],
                'payer_account_no' => $raw['缴款人账号'],
                'claim_user_name' => $raw['认领人姓名'],
                'claim_date' => $this->nullableDateValue($raw['认领日期']),
                'last_modified_time' => $lastModifiedTime === '' ? null : $lastModifiedTime,
                'source_row_no' => $row + 1,
                'source_raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE),
            ];
            $parsed['source_fingerprint'] = $this->unknownPaymentFingerprint($parsed);
            $rows[] = $parsed;
        }
        return $rows;
    }

    protected function parseDistributionImportRowsFromXls($binary)
    {
        $tableRows = $this->parseSpreadsheetRows($binary);
        if (empty($tableRows)) {
            throw new \Exception('文件中没有可识别的工作表数据');
        }

        $headers = [];
        foreach ($tableRows[0] as $col => $value) {
            $headers[$col] = $this->normalizeImportCellText($value);
        }
        $required = [
            '序号', '分配单号', '转入案号', '转入承办法官', '转入当事人',
            '转出案号', '转出承办法官', '转出当事人', '开票种类', '单据号',
            '分配金额', '申请人', '申请日期', '接收日期', '状态', '情况说明',
            '原开票日期',
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
        for ($row = 1; $row < count($tableRows); $row++) {
            $raw = [];
            foreach ($index as $name => $col) {
                $raw[$name] = $this->normalizeImportCellText($tableRows[$row][$col] ?? '');
            }
            if ($this->isEmptyPaymentRawRow($raw)) {
                continue;
            }
            if ($this->isMalformedPaymentRawRow($raw)) {
                continue;
            }

            $receiveDate = $this->normalizeDateValue($raw['接收日期']);
            $amount = $this->centsToDecimal($this->decimalToCents($raw['分配金额']));
            $parsed = [
                'fiscal_year' => $receiveDate === '' ? '' : substr($receiveDate, 0, 4),
                'period' => $receiveDate === '' ? '' : substr($receiveDate, 0, 7),
                'distribution_no' => $raw['分配单号'],
                'in_case_no' => $raw['转入案号'],
                'in_judge_name' => $raw['转入承办法官'],
                'in_party_name' => $raw['转入当事人'],
                'out_case_no' => $raw['转出案号'],
                'out_judge_name' => $raw['转出承办法官'],
                'out_party_name' => $raw['转出当事人'],
                'invoice_type' => $raw['开票种类'],
                'receipt_no' => $raw['单据号'],
                'distribution_amount' => $amount,
                'applicant_name' => $raw['申请人'],
                'apply_date' => $this->nullableDateValue($raw['申请日期']),
                'receive_date' => $receiveDate,
                'business_status' => $raw['状态'],
                'description' => $raw['情况说明'],
                'original_invoice_date' => $this->nullableDateValue($raw['原开票日期']),
                'source_row_no' => $row + 1,
                'source_raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE),
            ];
            $parsed['source_fingerprint'] = $this->distributionFingerprint($parsed);
            $rows[] = $parsed;
        }
        return $rows;
    }

    protected function validatePaymentRows($rows, $bizType = '', $receiptNoDuplicateCheckRequired = true)
    {
        $errors = [];
        $allowedBusinessTypes = $this->allowedPaymentBusinessTypes($bizType);
        foreach ($rows as $row) {
            $errors = array_merge($errors, $this->paymentRowValidationErrors($row, $bizType, $allowedBusinessTypes));
        }
        if ($receiptNoDuplicateCheckRequired) {
            $errors = array_merge($errors, $this->paymentReceiptNoDuplicateErrors($rows));
        }
        return $errors;
    }

    protected function paymentImportRequiresReceiptNoDuplicateCheck($bizType)
    {
        $businessItems = $this->allowedSubjectConfigItems($bizType, 'PAYMENT');
        if (empty($businessItems)) {
            return true;
        }

        $lookupItems = $this->subjectConfigLookupItems($bizType, 'PAYMENT', $businessItems);
        $rows = $this->getdb(self::TABLE_SUBJECT_CONFIG)->where([
            'account_set_id' => $this->accountSetId,
            'biz_type' => $bizType,
            'voucher_biz_type' => 'PAYMENT',
            'del_flag' => 0,
        ])->where('business_item_type', 'in', $lookupItems)->field(self::SUBJECT_CONFIG_FIELD)->select();

        $configs = [];
        foreach ($rows as $row) {
            $key = $this->voucherSubjectConfigKey($bizType, 'PAYMENT', $row['business_item_type']);
            if ($key === $row['business_item_type'] || !isset($configs[$key])) {
                $configs[$key] = $row;
            }
        }

        $creditSubjectCodes = [];
        foreach ($businessItems as $businessItem) {
            $creditSubjectCode = trim((string)($configs[$businessItem]['credit_subject_code'] ?? ''));
            if ($creditSubjectCode !== '') {
                $creditSubjectCodes[] = $creditSubjectCode;
            }
        }
        $creditSubjectCodes = array_values(array_unique($creditSubjectCodes));
        if (empty($creditSubjectCodes)) {
            return true;
        }

        $exists = $this->getdb('fin_subject_aux_config')->where([
            'account_set_id' => $this->accountSetId,
            'aux_type_code' => 'receipt_no',
            'del_flag' => 0,
        ])->where('subject_code', 'in', $creditSubjectCodes)->find();
        return !empty($exists);
    }

    protected function paymentReceiptNoDuplicateErrors($rows)
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

        $existingRows = $this->loadExistingPaymentsByReceiptNos(array_keys($rowsByReceiptNo));
        foreach ($existingRows as $existingRow) {
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

    protected function loadExistingPaymentsByReceiptNos($receiptNos)
    {
        $receiptNos = array_values(array_unique(array_filter(array_map(function ($value) {
            return trim((string)$value);
        }, $receiptNos))));
        if (empty($receiptNos)) {
            return [];
        }
        return $this->getdb(self::TABLE_PAYMENT)->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->where('receipt_no', 'in', $receiptNos)
            ->field('payment_id,account_set_id,fiscal_year,period,receipt_no,case_no,payer_name,party_name,payment_amount,payment_date,payment_time,source_file_name,source_row_no,source_fingerprint,voucher_status,voucher_id,voucher_no,voucher_period,del_flag')
            ->select();
    }

    protected function paymentExistingDuplicateSummary($row)
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
        if (empty($parts)) {
            return '';
        }
        return '（' . implode('，', $parts) . '）';
    }

    protected function validateDistributionRows($rows, $bizType = '')
    {
        $errors = [];
        $allowedInvoiceTypes = $this->allowedDistributionInvoiceTypes($bizType);
        foreach ($rows as $row) {
            $prefix = '第' . ($row['source_row_no'] ?? '?') . '行：';
            if ($bizType !== 'CASE_FUND') {
                $errors[] = $prefix . '当前账套类型不支持案款分配：' . $bizType;
                continue;
            }
            if (($row['distribution_no'] ?? '') === '') {
                $errors[] = $prefix . '分配单号不能为空';
            }
            if (($row['in_case_no'] ?? '') === '') {
                $errors[] = $prefix . '转入案号不能为空';
            }
            if (($row['out_case_no'] ?? '') === '') {
                $errors[] = $prefix . '转出案号不能为空';
            }
            if (($row['invoice_type'] ?? '') === '') {
                $errors[] = $prefix . '开票种类不能为空';
            } elseif (!in_array($row['invoice_type'], $allowedInvoiceTypes, true)) {
                $errors[] = $prefix . '当前账套不允许导入开票种类【' . $row['invoice_type'] . '】，允许类型：' . implode('、', $allowedInvoiceTypes);
            }
            if (($row['receipt_no'] ?? '') === '') {
                $errors[] = $prefix . '单据号不能为空';
            }
            if ($this->decimalToCents($row['distribution_amount'] ?? '0') <= 0) {
                $errors[] = $prefix . '分配金额必须大于0';
            }
            if (!$this->isValidImportDate($row['receive_date'] ?? '')) {
                $errors[] = $prefix . '接收日期不能为空或格式不正确';
            }
        }
        return $errors;
    }

    protected function validateUnknownPaymentRows($rows, $bizType = '')
    {
        $errors = [];
        foreach ($rows as $row) {
            $prefix = '第' . ($row['source_row_no'] ?? '?') . '行：';
            if ($bizType !== 'CASE_FUND') {
                $errors[] = $prefix . '当前账套类型不支持不明款业务：' . $bizType;
                continue;
            }
            if ($this->decimalToCents($row['unknown_amount'] ?? '0') <= 0) {
                $errors[] = $prefix . '金额必须大于0';
            }
            if (!$this->isValidImportDate($row['transaction_date'] ?? '')) {
                $errors[] = $prefix . '交易日期不能为空或格式不正确';
            }
            if (!$this->unknownPaymentRowHasTraceField($row)) {
                $errors[] = $prefix . '缴款人、银行备注、银行摘要、法院银行账户、缴款人账号至少填写一项用于追溯';
            }
        }
        return $errors;
    }

    protected function unknownPaymentRowHasTraceField($row)
    {
        foreach (['payer_name', 'bank_remark', 'bank_summary', 'court_bank_account_no', 'payer_account_no'] as $field) {
            if (trim((string)($row[$field] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    protected function isValidImportDate($value)
    {
        $text = trim((string)$value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            return false;
        }
        list($year, $month, $day) = array_map('intval', explode('-', $text));
        return checkdate($month, $day, $year);
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
        if ($this->isSuspiciousBankSerialNo($row['bank_serial_no'] ?? '')) {
            $errors[] = $prefix . '银行流水号格式异常，请将银行流水号列设置为文本后重新导入';
        }
        return $errors;
    }

    protected function isSuspiciousBankSerialNo($value)
    {
        $text = trim((string)$value);
        if ($text === '') {
            return false;
        }
        return preg_match('/^[+-]?\d+(?:\.\d+)?E[+-]?\d+$/i', $text) === 1;
    }

    protected function paymentRowHasTraceKey($row)
    {
        if ($row['receipt_no'] !== '' || $row['bank_serial_no'] !== '' || $row['payment_order_no'] !== '') {
            return true;
        }
        return trim((string)($row['bank_account_no'] ?? '')) !== ''
            && $this->decimalToCents($row['payment_amount'] ?? '0') > 0;
    }

    protected function validateRefundRows($rows, $bizType = '', $receiptTraceRequired = null)
    {
        $errors = [];
        $allowedOutTypes = $this->allowedRefundOutTypes($bizType);
        if ($receiptTraceRequired === null) {
            $receiptTraceRequired = $this->refundImportRequiresReceiptTrace($bizType, $rows);
        }
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
            if ($receiptTraceRequired && $bizType !== 'LITIGATION_FEE' && $row['receipt_no'] === '' && $row['source_receipt_no'] === '' && $row['out_order_no'] === '') {
                $errors[] = $prefix . '票据号码、来源票据号码、出账单号至少填写一项用于追溯';
            }
        }
        return $errors;
    }

    protected function refundImportRequiresReceiptTrace($bizType, $rows = [])
    {
        if ($bizType === 'LITIGATION_FEE') {
            return false;
        }
        if ($bizType !== 'CASE_FUND') {
            return true;
        }

        $configs = $this->refundSubjectConfigsForImport($bizType, $rows);
        if (empty($configs)) {
            return true;
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
        $subjectCodes = array_values(array_unique(array_filter($subjectCodes)));
        if (empty($subjectCodes)) {
            return true;
        }

        $exists = $this->getdb('fin_subject_aux_config')->where([
            'account_set_id' => $this->accountSetId,
            'aux_type_code' => 'receipt_no',
            'del_flag' => 0,
        ])->where('subject_code', 'in', $subjectCodes)->find();
        return !empty($exists);
    }

    protected function refundSubjectConfigsForImport($bizType, $rows = [])
    {
        $businessItems = [];
        foreach ($rows as $row) {
            $outType = trim((string)($row['out_type'] ?? ''));
            if ($outType !== '') {
                $businessItems[] = $this->voucherSubjectConfigKey($bizType, 'REFUND', $outType);
            }
        }
        if (empty($businessItems)) {
            $businessItems = $this->allowedSubjectConfigItems($bizType, 'REFUND');
        }
        if (empty($businessItems)) {
            return [];
        }

        $lookupItems = $this->subjectConfigLookupItems($bizType, 'REFUND', array_values(array_unique($businessItems)));
        $rows = $this->getdb(self::TABLE_SUBJECT_CONFIG)->where([
            'account_set_id' => $this->accountSetId,
            'biz_type' => $bizType,
            'voucher_biz_type' => 'REFUND',
            'del_flag' => 0,
        ])->where('business_item_type', 'in', $lookupItems)->field(self::SUBJECT_CONFIG_FIELD)->select();

        $configs = [];
        foreach ($rows as $row) {
            $key = $this->voucherSubjectConfigKey($bizType, 'REFUND', $row['business_item_type']);
            if ($key === $row['business_item_type'] || !isset($configs[$key])) {
                $configs[$key] = $row;
            }
        }

        $result = [];
        foreach ($businessItems as $businessItem) {
            if (isset($configs[$businessItem])) {
                $result[] = $configs[$businessItem];
            }
        }
        return $result;
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
            'CASE_FUND' => ['执行、调解款'],
            'LITIGATION_FEE' => ['诉讼费直接结算', '诉讼费预收'],
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

    protected function allowedDistributionInvoiceTypes($bizType)
    {
        $map = [
            'CASE_FUND' => ['执行、调解款'],
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
        if ($bizType === 'PAYMENT') {
            $fields = self::PAYMENT_FIELD;
        } elseif ($bizType === 'REFUND') {
            $fields = self::REFUND_FIELD;
        } elseif ($bizType === 'UNKNOWN_PAYMENT') {
            $fields = self::UNKNOWN_PAYMENT_FIELD;
        } elseif ($bizType === 'DISTRIBUTION') {
            $fields = self::DISTRIBUTION_FIELD;
        } else {
            return $this->error('不支持删除该业务类型：' . $bizType);
        }
        $rows = $this->getdb($table)
            ->where($where)
            ->where($idField, 'in', $ids)
            ->field($fields)
            ->select();
        if (count($rows) !== count($ids)) {
            return $this->error($label . '不存在或已删除');
        }
        $deleteCheck = $this->assertRowsCanDeleteBusinessData($rows, $label);
        if ($deleteCheck !== null) {
            return $this->error($deleteCheck);
        }
        $reconcileCount = $this->getdb(self::TABLE_BANK_RECONCILE)->where([
            'account_set_id' => $this->accountSetId,
            'biz_type' => $bizType,
            'del_flag' => 0,
        ])->where('biz_id', 'in', $ids)->count();
        if ((int)$reconcileCount > 0) {
            return $this->error('只能删除未对账的' . $label);
        }

        $hardDelete = $this->shouldPhysicalDeleteCaseFundBusinessRows($bizType);
        Db::startTrans();
        try {
            if ($hardDelete) {
                $this->getdb($table)->where([
                    'account_set_id' => $this->accountSetId,
                ])->where($idField, 'in', $ids)->delete();
            } else {
                $update = ['del_flag' => 1];
                $this->fillUpdate($update);
                $this->getdb($table)->where([
                    'account_set_id' => $this->accountSetId,
                    'del_flag' => 0,
                ])->where($idField, 'in', $ids)->update($update);
            }
            $this->logAudit($auditBizType, 'DELETE', 'DELETE', $rows, [
                $idField . 's' => $ids,
                'deleted_count' => count($rows),
                'delete_mode' => $hardDelete ? 'PHYSICAL' : 'SOFT',
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('删除' . $label . '失败：' . $e->getMessage());
        }

        return $this->ok(['deleted_count' => count($rows)], '删除成功', count($rows));
    }

    protected function shouldPhysicalDeleteCaseFundBusinessRows($bizType)
    {
        return in_array($bizType, ['PAYMENT', 'REFUND', 'UNKNOWN_PAYMENT', 'DISTRIBUTION'], true);
    }

    protected function assertRowsCanDeleteBusinessData($rows, $label)
    {
        foreach ($rows as $row) {
            $status = $row['voucher_status'] ?? 'UNGENERATED';
            if ($status === 'UNGENERATED') {
                continue;
            }
            if ($status === 'GENERATED' && !$this->hasActiveGeneratedVoucher($row)) {
                continue;
            }
            if ($status === 'GENERATED') {
                return '案号【' . ($row['case_no'] ?? '') . '】的' . $label . '已生成凭证且原凭证仍有效，不允许删除';
            }
            return '案号【' . ($row['case_no'] ?? '') . '】的' . $label . '制证状态为【' . $status . '】，不允许删除';
        }
        return null;
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

    protected function buildBankReconcileRow($statement, $bizType, $biz, $status, $bankCents, $bizCents, $matchRule = 'SERIAL_NO')
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
            'match_rule' => $matchRule,
            'matched_by' => $this->userid,
            'matched_time' => $this->now(),
            'bank_direction' => $statement['direction'] ?? ($bizType === 'PAYMENT' ? 'CREDIT' : 'DEBIT'),
            'bank_summary' => $bankSummary,
            'biz_summary' => $biz ? $this->bankReconcileBizSummary($bizType, $biz) : '',
            'remark' => '',
        ];
    }

    protected function paymentCandidatesByBankStatement($statement)
    {
        $bankSerialNo = trim((string)($statement['bank_serial_no'] ?? ''));
        if ($bankSerialNo === '' || empty($statement['transaction_date'])) {
            return [];
        }
        return $this->getdb(self::TABLE_PAYMENT)
            ->where(['account_set_id' => $this->accountSetId, 'fiscal_year' => $this->currentYear(), 'del_flag' => 0])
            ->where('payment_date', $statement['transaction_date'])
            ->where('bank_serial_no', $bankSerialNo)
            ->field(self::PAYMENT_FIELD)
            ->select();
    }

    protected function refundCandidatesByBankStatement($statement)
    {
        if (empty($statement['transaction_date'])) {
            return [];
        }

        $accountNo = trim((string)($statement['counterparty_account_no'] ?? ''));
        $accountName = trim((string)($statement['counterparty_account_name'] ?? ''));
        $query = $this->getdb(self::TABLE_REFUND)
            ->where(['account_set_id' => $this->accountSetId, 'fiscal_year' => $this->currentYear(), 'del_flag' => 0])
            ->where('refund_date', $statement['transaction_date']);

        if ($accountNo !== '') {
            $query->where('payee_bank_account_no', $accountNo);
        } elseif ($accountName !== '') {
            $query->where('payee_bank_account_name|actual_payee_name', 'like', '%' . $accountName . '%');
        } else {
            return [];
        }

        return $query->field(self::REFUND_FIELD)->select();
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
            ->where(['account_set_id' => $this->accountSetId, 'fiscal_year' => $this->currentYear(), 'del_flag' => 0]);
        if ($dateStart !== '') {
            $query->where('refund_date', '>=', $dateStart);
        }
        if ($dateEnd !== '') {
            $query->where('refund_date', '<=', $dateEnd);
        }
        return $query->field(self::REFUND_FIELD)->select();
    }

    protected function bankStatementExistsByPayment($payment, $bankCode = '', $dateStart = '', $dateEnd = '')
    {
        $bankSerialNo = trim((string)($payment['bank_serial_no'] ?? ''));
        $paymentDate = trim((string)($payment['payment_date'] ?? ''));
        if ($bankSerialNo === '' || $paymentDate === '') {
            return false;
        }
        $query = $this->getdb(self::TABLE_BANK_STATEMENT)->where([
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $this->currentYear(),
            'direction' => 'CREDIT',
            'transaction_date' => $paymentDate,
            'bank_serial_no' => $bankSerialNo,
            'del_flag' => 0,
        ]);
        if ($bankCode !== '') {
            $query->where('bank_code', $bankCode);
        }
        if ($dateStart !== '') {
            $query->where('transaction_date', '>=', $dateStart);
        }
        if ($dateEnd !== '') {
            $query->where('transaction_date', '<=', $dateEnd);
        }
        return $query->count() > 0;
    }

    protected function bankStatementExistsByRefundPayee($refund, $bankCode = '', $dateStart = '', $dateEnd = '')
    {
        $refundDate = trim((string)($refund['refund_date'] ?? ''));
        if ($refundDate === '') {
            return false;
        }

        $accountNo = trim((string)($refund['payee_bank_account_no'] ?? ''));
        $accountName = trim((string)($refund['payee_bank_account_name'] ?? ''));
        if ($accountName === '') {
            $accountName = trim((string)($refund['actual_payee_name'] ?? ''));
        }
        if ($accountNo === '' && $accountName === '') {
            return false;
        }

        $query = $this->getdb(self::TABLE_BANK_STATEMENT)->where([
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $this->currentYear(),
            'direction' => 'DEBIT',
            'transaction_date' => $refundDate,
            'del_flag' => 0,
        ]);
        if ($bankCode !== '') {
            $query->where('bank_code', $bankCode);
        }
        if ($dateStart !== '') {
            $query->where('transaction_date', '>=', $dateStart);
        }
        if ($dateEnd !== '') {
            $query->where('transaction_date', '<=', $dateEnd);
        }
        if ($accountNo !== '') {
            $query->where('counterparty_account_no', $accountNo);
        } else {
            $query->where('counterparty_account_name', 'like', '%' . $accountName . '%');
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
            'del_flag' => 0,
        ];
        $query = $this->getdb(self::TABLE_BANK_RECONCILE)
            ->where($where)
            ->where('match_rule', 'in', self::BANK_RECONCILE_AUTO_MATCH_RULES);
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
        if ($voucherBizType === 'PAYMENT_METHOD') {
            return array_keys($this->paymentMethodOptions());
        }
        if ($voucherBizType === 'REFUND') {
            return $this->subjectConfigRefundBusinessTypes($bizType);
        }
        if ($voucherBizType === 'UNKNOWN_PAYMENT') {
            return $bizType === 'CASE_FUND' ? ['不明款'] : [];
        }
        if ($voucherBizType === 'DISTRIBUTION') {
            return $this->allowedDistributionInvoiceTypes($bizType);
        }
        return [];
    }

    protected function subjectConfigPaymentBusinessTypes($bizType)
    {
        $map = [
            'CASE_FUND' => ['缴费登记'],
            'LITIGATION_FEE' => ['缴费登记'],
        ];
        return $map[$bizType] ?? [];
    }

    protected function subjectConfigRefundBusinessTypes($bizType)
    {
        $map = [
            'CASE_FUND' => ['出账查询'],
            'LITIGATION_FEE' => ['出账查询'],
        ];
        return $map[$bizType] ?? [];
    }

    protected function voucherSubjectConfigAliases($bizType, $voucherBizType)
    {
        $map = [
            'PAYMENT' => [
                'CASE_FUND' => [
                    '执行、调解款' => '缴费登记',
                ],
                'LITIGATION_FEE' => [
                    '诉讼费直接结算' => '缴费登记',
                    '诉讼费预收' => '缴费登记',
                    '预收诉讼费' => '缴费登记',
                    '诉讼费收款' => '缴费登记',
                ],
            ],
            'REFUND' => [
                'CASE_FUND' => [
                    '执行、调解款发放' => '出账查询',
                ],
                'LITIGATION_FEE' => [
                    '诉讼费退费' => '出账查询',
                ],
            ],
        ];
        return $map[$voucherBizType][$bizType] ?? [];
    }

    protected function voucherSubjectConfigKey($bizType, $voucherBizType, $businessItemType)
    {
        $businessItemType = trim((string)$businessItemType);
        $aliases = $this->voucherSubjectConfigAliases($bizType, $voucherBizType);
        return $aliases[$businessItemType] ?? $businessItemType;
    }

    protected function subjectConfigLookupItems($bizType, $voucherBizType, $businessItems)
    {
        if (!in_array($voucherBizType, ['PAYMENT', 'REFUND'], true)) {
            return $businessItems;
        }
        return array_values(array_unique(array_merge($businessItems, array_keys($this->voucherSubjectConfigAliases($bizType, $voucherBizType)))));
    }

    protected function normalizeVoucherBizType($value)
    {
        $value = strtoupper(trim((string)$value));
        return in_array($value, ['PAYMENT', 'PAYMENT_METHOD', 'REFUND', 'UNKNOWN_PAYMENT', 'DISTRIBUTION'], true) ? $value : '';
    }

    protected function paymentMethodOptions()
    {
        return [
            'POS' => 'POS 收款',
            'TRANSFER' => '银行转账',
            'UNKNOWN' => '不明款认领',
        ];
    }

    protected function normalizePaymentMethodCode($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return 'UNKNOWN';
        }
        $upper = strtoupper($value);
        if (in_array($upper, ['POS', 'TRANSFER', 'UNKNOWN'], true)) {
            return $upper;
        }
        $compact = preg_replace('/\s+/u', '', $value);
        if (in_array($compact, ['POS收款', 'POS刷卡'], true)) {
            return 'POS';
        }
        if (in_array($compact, ['银行转账', '转账'], true)) {
            return 'TRANSFER';
        }
        if (in_array($compact, ['不明款认领', '不明款', '未知'], true)) {
            return 'UNKNOWN';
        }
        return $value;
    }

    protected function inferPaymentMethodByBankSerialNo($bankSerialNo, $rawPaymentMethod = '')
    {
        $serial = preg_replace('/\s+/u', '', trim((string)$bankSerialNo));
        if ($serial === '') {
            return 'UNKNOWN';
        }
        $length = strlen($serial);
        if (in_array($length, [10, 12], true)) {
            return 'POS';
        }
        if ($length > 15) {
            return 'TRANSFER';
        }
        return trim((string)$rawPaymentMethod);
    }

    protected function paymentMethodLabel($method)
    {
        $code = $this->normalizePaymentMethodCode($method);
        $options = $this->paymentMethodOptions();
        return $options[$code] ?? (string)$method;
    }

    protected function paymentMethodSubjectConfigItems($bizType, $paymentItems)
    {
        $methodCodes = array_keys($this->paymentMethodOptions());
        $defaultDebitSubjectCode = '';
        foreach ($paymentItems as $item) {
            if (!empty($item['debit_subject_code'])) {
                $defaultDebitSubjectCode = $item['debit_subject_code'];
                break;
            }
        }

        $rows = $this->getdb(self::TABLE_SUBJECT_CONFIG)->where([
            'account_set_id' => $this->accountSetId,
            'biz_type' => $bizType,
            'voucher_biz_type' => 'PAYMENT_METHOD',
            'del_flag' => 0,
        ])->where('business_item_type', 'in', $methodCodes)->field(self::SUBJECT_CONFIG_FIELD)->select();

        $configs = [];
        $subjectCodes = [];
        foreach ($rows as $row) {
            $configs[$row['business_item_type']] = $row;
            if (!empty($row['debit_subject_code'])) {
                $subjectCodes[] = $row['debit_subject_code'];
            }
        }
        if ($defaultDebitSubjectCode !== '') {
            $subjectCodes[] = $defaultDebitSubjectCode;
        }
        $subjectNames = $this->subjectNameMap($subjectCodes);

        $items = [];
        foreach ($this->paymentMethodOptions() as $code => $label) {
            $config = $configs[$code] ?? [];
            $debitSubjectCode = $config['debit_subject_code'] ?? $defaultDebitSubjectCode;
            $items[] = [
                'config_id' => $config['config_id'] ?? '',
                'account_set_id' => $this->accountSetId,
                'biz_type' => $bizType,
                'voucher_biz_type' => 'PAYMENT_METHOD',
                'business_item_type' => $code,
                'business_item_label' => $label,
                'debit_subject_code' => $debitSubjectCode,
                'debit_subject_name' => $subjectNames[$debitSubjectCode] ?? '',
                'credit_subject_code' => $config['credit_subject_code'] ?? $debitSubjectCode,
                'credit_subject_name' => '',
            ];
        }
        return $items;
    }

    protected function paymentMethodSubjectConfigMap($bizType)
    {
        $rows = $this->getdb(self::TABLE_SUBJECT_CONFIG)->where([
            'account_set_id' => $this->accountSetId,
            'biz_type' => $bizType,
            'voucher_biz_type' => 'PAYMENT_METHOD',
            'del_flag' => 0,
        ])->where('business_item_type', 'in', array_keys($this->paymentMethodOptions()))->field(self::SUBJECT_CONFIG_FIELD)->select();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['business_item_type']] = $row;
        }
        return $map;
    }

    protected function savePaymentMethodSubjectConfigs($bizType, $methodItems, $paymentItems)
    {
        if (!is_array($methodItems)) {
            $methodItems = [];
        }
        $defaultDebitSubjectCode = '';
        foreach ($paymentItems as $item) {
            $subjectCode = trim($item['debit_subject_code'] ?? ($item['debitSubjectCode'] ?? ''));
            if ($subjectCode !== '') {
                $defaultDebitSubjectCode = $subjectCode;
                break;
            }
        }
        $byMethod = [];
        foreach ($methodItems as $item) {
            $methodCode = $this->normalizePaymentMethodCode($item['business_item_type'] ?? ($item['businessItemType'] ?? ''));
            if (isset($this->paymentMethodOptions()[$methodCode])) {
                $byMethod[$methodCode] = $item;
            }
        }

        $before = [];
        $after = [];
        foreach ($this->paymentMethodOptions() as $methodCode => $label) {
            $item = $byMethod[$methodCode] ?? [];
            $debitSubjectCode = trim($item['debit_subject_code'] ?? ($item['debitSubjectCode'] ?? $defaultDebitSubjectCode));
            $debitCheck = $this->validateVoucherSubjectCode($debitSubjectCode, $label . '借方科目');
            if ($debitCheck !== null) {
                throw new \Exception($debitCheck);
            }
            $where = [
                'account_set_id' => $this->accountSetId,
                'biz_type' => $bizType,
                'voucher_biz_type' => 'PAYMENT_METHOD',
                'business_item_type' => $methodCode,
            ];
            $existing = $this->getdb(self::TABLE_SUBJECT_CONFIG)->where($where)->find();
            $save = [
                'debit_subject_code' => $debitSubjectCode,
                'credit_subject_code' => $debitSubjectCode,
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
        return ['before' => $before, 'after' => $after];
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

    protected function generateRefundVoucherByOutOrderNoFlag()
    {
        $row = $this->getdb('fin_account_set')->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->field('generate_refund_voucher_by_out_order_no_flag')->find();
        if (!$row || !array_key_exists('generate_refund_voucher_by_out_order_no_flag', $row)) {
            return 0;
        }
        return (int)$row['generate_refund_voucher_by_out_order_no_flag'] === 1 ? 1 : 0;
    }

    protected function generatePaymentVoucherByMethodFlag()
    {
        $row = $this->getdb('fin_account_set')->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->field('generate_payment_voucher_by_method_flag')->find();
        if (!$row || !array_key_exists('generate_payment_voucher_by_method_flag', $row)) {
            return 0;
        }
        return (int)$row['generate_payment_voucher_by_method_flag'] === 1 ? 1 : 0;
    }

    protected function saveSubjectConfigAccountSetFlag($flag, $refundOutOrderFlag = null, $paymentMethodFlag = null)
    {
        $before = $this->getdb('fin_account_set')->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->field('account_set_id,generate_voucher_by_day_flag,generate_payment_voucher_by_method_flag,generate_refund_voucher_by_out_order_no_flag')->find();
        if (!$before) {
            throw new \Exception('账套不存在');
        }
        $update = ['generate_voucher_by_day_flag' => $this->normalizeDailyVoucherFlag($flag)];
        if ($refundOutOrderFlag !== null) {
            $update['generate_refund_voucher_by_out_order_no_flag'] = $this->normalizeDailyVoucherFlag($refundOutOrderFlag);
        }
        if ($paymentMethodFlag !== null) {
            $update['generate_payment_voucher_by_method_flag'] = $this->normalizeDailyVoucherFlag($paymentMethodFlag);
        }
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

    protected function loadExistingPaymentsForImport($rows, $useReceiptNoAsDuplicateKey = true)
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
            $fingerprintRows = $this->getdb(self::TABLE_PAYMENT)->where([
                'account_set_id' => $this->accountSetId,
            ])->where('source_fingerprint', 'in', array_values(array_unique($fingerprints)))->field(self::PAYMENT_FIELD)->select();
            foreach ($fingerprintRows as $row) {
                $this->rememberPaymentImportExistingRow($existing, $row, $useReceiptNoAsDuplicateKey);
            }
        }
        if (!empty($receiptNos)) {
            $receiptRows = $this->getdb(self::TABLE_PAYMENT)->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])->where('receipt_no', 'in', array_values(array_unique($receiptNos)))->field(self::PAYMENT_FIELD)->select();
            foreach ($receiptRows as $row) {
                $this->rememberPaymentImportExistingRow($existing, $row, $useReceiptNoAsDuplicateKey);
            }
        }
        if (!empty($accountNos)) {
            $accountRows = $this->getdb(self::TABLE_PAYMENT)->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])->where('bank_account_no', 'in', array_values(array_unique($accountNos)))->field(self::PAYMENT_FIELD)->select();
            foreach ($accountRows as $row) {
                $this->rememberPaymentImportExistingRow($existing, $row, $useReceiptNoAsDuplicateKey);
            }
        }
        return $existing;
    }

    protected function rememberPaymentImportExistingRow(&$existing, $row, $useReceiptNoAsDuplicateKey = true)
    {
        $key = $this->paymentImportDuplicateKey($row, $useReceiptNoAsDuplicateKey);
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
        $update['del_flag'] = 0;
        $update['remark'] = $data['remark'] ?? '';
        $this->fillUpdate($update);
        $this->getdb(self::TABLE_PAYMENT)->where([
            'payment_id' => $existingRow['payment_id'],
            'account_set_id' => $this->accountSetId,
        ])->update($update);
    }

    protected function updateExistingRefundFromImport($existingRow, $row, $data)
    {
        $update = $row;
        unset($update['refund_id'], $update['account_set_id'], $update['created_by'], $update['created_time']);
        $update['source_file_name'] = $data['filename'] ?? '';
        $update['voucher_status'] = 'UNGENERATED';
        $update['voucher_id'] = null;
        $update['voucher_no'] = null;
        $update['voucher_period'] = null;
        $update['voucher_generated_time'] = null;
        $update['remark'] = $data['remark'] ?? '';
        $this->fillUpdate($update);
        $this->getdb(self::TABLE_REFUND)->where([
            'refund_id' => $existingRow['refund_id'],
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->update($update);
    }

    protected function canOverwriteGeneratedBusinessRow($row)
    {
        $status = $row['voucher_status'] ?? 'UNGENERATED';
        if ($status === 'UNGENERATED') {
            return true;
        }
        return $status === 'GENERATED' && !$this->hasActiveGeneratedVoucher($row);
    }

    protected function paymentImportDuplicateKey($row, $useReceiptNoAsDuplicateKey = true)
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
            $amount = $this->centsToDecimal($this->decimalToCents($row['payment_amount'] ?? '0'));
            return 'account_amount:' . $bankAccountNo . '|' . $amount;
        }
        return $fingerprint === '' ? '' : 'fingerprint:' . $fingerprint;
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

    protected function unknownPaymentFingerprint($row)
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

    protected function distributionFingerprint($row)
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

    protected function parseSpreadsheetRows($binary)
    {
        return $this->legacySpreadsheetReader()->parseRows($binary);
    }

    protected function readOleWorkbookStream($binary)
    {
        return $this->legacySpreadsheetReader()->readOleWorkbookStream($binary);
    }

    protected function parseBiffCells($stream)
    {
        return $this->legacySpreadsheetReader()->parseBiffCells($stream);
    }

    protected function legacySpreadsheetReader()
    {
        if ($this->legacySpreadsheetReader === null) {
            $this->legacySpreadsheetReader = new LegacySpreadsheetReader();
        }
        return $this->legacySpreadsheetReader;
    }

}
