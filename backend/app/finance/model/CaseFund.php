<?php

namespace app\finance\model;

use app\finance\service\casefund\banking\CaseFundBankingRule;
use app\finance\service\casefund\importing\CaseFundImportIdentity;
use app\finance\service\casefund\importing\CaseFundImportNormalizer;
use app\finance\service\casefund\importing\CaseFundImportParser;
use app\finance\service\casefund\policy\CaseFundBusinessPolicy;
use app\finance\service\casefund\support\LegacySpreadsheetReader;
use app\finance\service\casefund\subject\SubjectConfigResolver;
use app\finance\service\casefund\validation\CaseFundImportValidator;
use app\finance\service\casefund\voucher\CaseFundVoucherRule;
use think\Db;

class CaseFund extends Common
{
    protected $bankingRule;
    protected $businessPolicy;
    protected $importIdentity;
    protected $importNormalizer;
    protected $importParser;
    protected $importValidator;
    protected $legacySpreadsheetReader;
    protected $subjectConfigResolver;
    protected $voucherRule;

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

        $configs = $this->subjectConfigResolver()->configsByBusinessItems(
            $bizType,
            $voucherBizType,
            $businessItems
        );

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
        $subjectConfigMap = $this->subjectConfigResolver()->configsByBusinessItems($bizType, 'PAYMENT');

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
        return $this->voucherRule()->buildPaymentVoucherDetailsData(
            $groupRows,
            $subjectConfigMap,
            $paymentMethodConfigMap,
            $byPaymentMethod,
            $bizType
        );
    }

    protected function paymentVoucherAuxValues($row)
    {
        return $this->voucherRule()->paymentVoucherAuxValues($row);
    }

    protected function filterAuxValuesBySubjectConfigs($auxValues, $configs)
    {
        return $this->voucherRule()->filterAuxValuesBySubjectConfigs($auxValues, $configs);
    }

    protected function buildPaymentMethodVoucherSummary($paymentMethod, $groupRows)
    {
        return $this->voucherRule()->buildPaymentMethodVoucherSummary($paymentMethod, $groupRows);
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

        $subjectConfigMap = $this->subjectConfigResolver()->directConfigsByBusinessItems(
            'CASE_FUND',
            'DISTRIBUTION'
        );
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
        $subjectConfigMap = $this->subjectConfigResolver()->configsByBusinessItems($bizType, 'REFUND');

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
        return $this->voucherRule()->groupRefundRowsForVoucher($rows, $byDayFlag, $byOutOrderNoFlag);
    }

    protected function groupDistributionRowsForVoucher($rows)
    {
        return $this->voucherRule()->groupDistributionRowsForVoucher($rows);
    }

    protected function buildRefundVoucherDetailsData($groupRows, $subjectConfigMap, $aggregateByOutOrderNo, $bizType = '')
    {
        return $this->voucherRule()->buildRefundVoucherDetailsData(
            $groupRows,
            $subjectConfigMap,
            $aggregateByOutOrderNo,
            $bizType
        );
    }

    protected function buildDistributionVoucherDetailsData($groupRows, $subjectConfigMap)
    {
        return $this->voucherRule()->buildDistributionVoucherDetailsData($groupRows, $subjectConfigMap);
    }

    protected function buildRefundDebitDetailData($row, $config, $lineNo, $useNormalSummary = false)
    {
        return $this->voucherRule()->buildRefundDebitDetailData($row, $config, $lineNo, $useNormalSummary);
    }

    protected function buildPaymentVoucherSummary($row)
    {
        return $this->voucherRule()->buildPaymentVoucherSummary($row);
    }

    protected function buildRefundVoucherHeaderSummary($row, $aggregateByOutOrderNo, $voucherDate)
    {
        return $this->voucherRule()->buildRefundVoucherHeaderSummary($row, $aggregateByOutOrderNo, $voucherDate);
    }

    protected function buildRefundNormalVoucherSummary($row)
    {
        return $this->voucherRule()->buildRefundNormalVoucherSummary($row);
    }

    protected function buildRefundAggregateCreditSummary($outOrderNo)
    {
        return $this->voucherRule()->buildRefundAggregateCreditSummary($outOrderNo);
    }

    protected function buildDistributionVoucherHeaderSummary($row)
    {
        return $this->voucherRule()->buildDistributionVoucherHeaderSummary($row);
    }

    protected function buildDistributionTransferOutSummary($row)
    {
        return $this->voucherRule()->buildDistributionTransferOutSummary($row);
    }

    protected function buildDistributionTransferInSummary($row)
    {
        return $this->voucherRule()->buildDistributionTransferInSummary($row);
    }

    protected function buildUnknownPaymentVoucherSummary($row)
    {
        return $this->voucherRule()->buildUnknownPaymentVoucherSummary($row);
    }

    protected function buildRefundAggregateCreditDetail($detailId, $lineNo, $subjectCode, $summary, $amountCents, $configs)
    {
        return $this->voucherRule()->buildRefundAggregateCreditDetail(
            $detailId,
            $lineNo,
            $subjectCode,
            $summary,
            $amountCents,
            $configs
        );
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
        return $this->voucherRule()->buildAuxDesc($auxValues);
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
        return $this->importParser()->parsePaymentCells($cells);
    }

    protected function parseRefundImportRowsFromXls($binary, $bizType = '', $receiptTraceRequired = null)
    {
        $cells = $this->parseBiffCells($this->readOleWorkbookStream($binary));
        return $this->importParser()->parseRefundCells(
            $cells,
            $bizType,
            $receiptTraceRequired
        );
    }

    protected function refundImportRequiredHeaders($bizType = '', $receiptTraceRequired = null)
    {
        return $this->importValidator()->refundImportRequiredHeaders($bizType, $receiptTraceRequired);
    }

    protected function parseUnknownPaymentImportRowsFromXls($binary)
    {
        $cells = $this->parseBiffCells($this->readOleWorkbookStream($binary));
        return $this->importParser()->parseUnknownPaymentCells($cells);
    }

    protected function parseDistributionImportRowsFromXls($binary)
    {
        $tableRows = $this->parseSpreadsheetRows($binary);
        return $this->importParser()->parseDistributionTableRows($tableRows);
    }

    protected function validatePaymentRows($rows, $bizType = '', $receiptNoDuplicateCheckRequired = true)
    {
        $allowedBusinessTypes = $this->allowedPaymentBusinessTypes($bizType);
        $errors = $this->importValidator()->validatePaymentRows($rows, $bizType, $allowedBusinessTypes);
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

        $configs = $this->subjectConfigResolver()->configsByBusinessItems(
            $bizType,
            'PAYMENT',
            $businessItems
        );

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
        return $this->importValidator()->validateDistributionRows(
            $rows,
            $bizType,
            $this->allowedDistributionInvoiceTypes($bizType)
        );
    }

    protected function validateUnknownPaymentRows($rows, $bizType = '')
    {
        return $this->importValidator()->validateUnknownPaymentRows($rows, $bizType);
    }

    protected function unknownPaymentRowHasTraceField($row)
    {
        return $this->importValidator()->unknownPaymentRowHasTraceField($row);
    }

    protected function isValidImportDate($value)
    {
        return $this->importValidator()->isValidImportDate($value);
    }

    protected function paymentRowValidationErrors($row, $bizType, $allowedBusinessTypes = null)
    {
        return $this->importValidator()->paymentRowValidationErrors($row, $bizType, $allowedBusinessTypes);
    }

    protected function isSuspiciousBankSerialNo($value)
    {
        return $this->importValidator()->isSuspiciousBankSerialNo($value);
    }

    protected function paymentRowHasTraceKey($row)
    {
        return $this->importValidator()->paymentRowHasTraceKey($row);
    }

    protected function validateRefundRows($rows, $bizType = '', $receiptTraceRequired = null)
    {
        $allowedOutTypes = $this->allowedRefundOutTypes($bizType);
        if ($receiptTraceRequired === null) {
            $receiptTraceRequired = $this->refundImportRequiresReceiptTrace($bizType, $rows);
        }
        return $this->importValidator()->validateRefundRows(
            $rows,
            $bizType,
            $receiptTraceRequired,
            $allowedOutTypes
        );
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

        return $this->subjectConfigResolver()->orderedConfigsByBusinessItems(
            $bizType,
            'REFUND',
            $businessItems
        );
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
        return $this->businessPolicy()->allowedPaymentBusinessTypes($bizType);
    }

    protected function allowedRefundOutTypes($bizType)
    {
        return $this->businessPolicy()->allowedRefundOutTypes($bizType);
    }

    protected function allowedDistributionInvoiceTypes($bizType)
    {
        return $this->businessPolicy()->allowedDistributionInvoiceTypes($bizType);
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
        return $this->bankingRule()->supportedBankStatementBanks();
    }

    protected function normalizeBankStatementRow($row, $bankCode, $bankName, $defaultRowNo)
    {
        return $this->bankingRule()->normalizeBankStatementRow($row, $bankCode, $bankName, $defaultRowNo);
    }

    protected function bankStatementFingerprint($row)
    {
        return $this->bankingRule()->bankStatementFingerprint($row);
    }

    protected function buildBankReconcileRow($statement, $bizType, $biz, $status, $bankCents, $bizCents, $matchRule = 'SERIAL_NO')
    {
        return $this->bankingRule()->buildBankReconcileRow(
            $statement,
            $bizType,
            $biz,
            $status,
            $bankCents,
            $bizCents,
            $matchRule
        );
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
        return $this->bankingRule()->bankReconcileBizAmountCents($bizType, $biz);
    }

    protected function bankReconcileBizId($bizType, $biz)
    {
        return $this->bankingRule()->bankReconcileBizId($bizType, $biz);
    }

    protected function bankReconcileBizNo($bizType, $biz)
    {
        return $this->bankingRule()->bankReconcileBizNo($bizType, $biz);
    }

    protected function bankReconcileBizDate($bizType, $biz)
    {
        return $this->bankingRule()->bankReconcileBizDate($bizType, $biz);
    }

    protected function bankReconcileBizSummary($bizType, $biz)
    {
        return $this->bankingRule()->bankReconcileBizSummary($bizType, $biz);
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
        return $this->bankingRule()->emptyBankReconcileCounts();
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
        return $this->businessPolicy()->allowedSubjectConfigItems($bizType, $voucherBizType);
    }

    protected function subjectConfigPaymentBusinessTypes($bizType)
    {
        return $this->businessPolicy()->subjectConfigPaymentBusinessTypes($bizType);
    }

    protected function subjectConfigRefundBusinessTypes($bizType)
    {
        return $this->businessPolicy()->subjectConfigRefundBusinessTypes($bizType);
    }

    protected function voucherSubjectConfigAliases($bizType, $voucherBizType)
    {
        return $this->businessPolicy()->voucherSubjectConfigAliases($bizType, $voucherBizType);
    }

    protected function voucherSubjectConfigKey($bizType, $voucherBizType, $businessItemType)
    {
        return $this->businessPolicy()->voucherSubjectConfigKey($bizType, $voucherBizType, $businessItemType);
    }

    protected function subjectConfigLookupItems($bizType, $voucherBizType, $businessItems)
    {
        return $this->businessPolicy()->subjectConfigLookupItems($bizType, $voucherBizType, $businessItems);
    }

    protected function normalizeVoucherBizType($value)
    {
        return $this->businessPolicy()->normalizeVoucherBizType($value);
    }

    protected function paymentMethodOptions()
    {
        return $this->businessPolicy()->paymentMethodOptions();
    }

    protected function normalizePaymentMethodCode($value)
    {
        return $this->businessPolicy()->normalizePaymentMethodCode($value);
    }

    protected function inferPaymentMethodByBankSerialNo($bankSerialNo, $rawPaymentMethod = '')
    {
        return $this->businessPolicy()->inferPaymentMethodByBankSerialNo($bankSerialNo, $rawPaymentMethod);
    }

    protected function paymentMethodLabel($method)
    {
        return $this->businessPolicy()->paymentMethodLabel($method);
    }

    protected function paymentMethodSubjectConfigItems($bizType, $paymentItems)
    {
        $defaultDebitSubjectCode = '';
        foreach ($paymentItems as $item) {
            if (!empty($item['debit_subject_code'])) {
                $defaultDebitSubjectCode = $item['debit_subject_code'];
                break;
            }
        }

        $configs = $this->subjectConfigResolver()->paymentMethodConfigMap($bizType);
        $subjectCodes = [];
        foreach ($configs as $row) {
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
        return $this->subjectConfigResolver()->paymentMethodConfigMap($bizType);
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
        return $this->importValidator()->paymentImportErrorMessage($errors, $bizType);
    }

    protected function refundImportErrorMessage($errors, $bizType = '')
    {
        return $this->importValidator()->refundImportErrorMessage($errors, $bizType);
    }

    protected function paymentDataLabelByBizType($bizType)
    {
        return $this->importValidator()->paymentDataLabelByBizType($bizType);
    }

    protected function isEmptyPaymentRawRow($raw)
    {
        return $this->importNormalizer()->isEmptyPaymentRawRow($raw);
    }

    protected function isNonDetailPaymentRawRow($raw)
    {
        return $this->importNormalizer()->isNonDetailPaymentRawRow($raw);
    }

    protected function isNonDetailRefundRawRow($raw)
    {
        return $this->importNormalizer()->isNonDetailRefundRawRow($raw);
    }

    protected function isMalformedPaymentRawRow($raw)
    {
        return $this->importNormalizer()->isMalformedPaymentRawRow($raw);
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
        return $this->importIdentity()->rememberPaymentImportExistingRowByKey($existing, $key, $row);
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
        return $this->importIdentity()->paymentImportDuplicateKey($row, $useReceiptNoAsDuplicateKey);
    }

    protected function paymentFingerprint($row)
    {
        return $this->importIdentity()->paymentFingerprint($row);
    }

    protected function refundFingerprint($row)
    {
        return $this->importIdentity()->refundFingerprint($row);
    }

    protected function unknownPaymentFingerprint($row)
    {
        return $this->importIdentity()->unknownPaymentFingerprint($row);
    }

    protected function distributionFingerprint($row)
    {
        return $this->importIdentity()->distributionFingerprint($row);
    }

    protected function yesNoFlagFromText($text)
    {
        return $this->importNormalizer()->yesNoFlagFromText($text);
    }

    protected function normalizeDateTimeValue($value)
    {
        $text = $this->normalizeImportCellText($value);
        return $this->importNormalizer()->normalizeDateTimeText($text);
    }

    protected function normalizeDateValue($value)
    {
        $datetime = $this->normalizeDateTimeValue($value);
        return $this->importNormalizer()->dateValueFromDateTime($datetime);
    }

    protected function nullableDateValue($value)
    {
        $date = $this->normalizeDateValue($value);
        return $this->importNormalizer()->nullableDateFromDate($date);
    }

    protected function normalizeImportCellText($value)
    {
        return $this->importNormalizer()->normalizeImportCellText($value);
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

    protected function bankingRule()
    {
        if ($this->bankingRule === null) {
            $this->bankingRule = new CaseFundBankingRule([
                'normalize_datetime' => function ($value) {
                    return $this->normalizeDateTimeValue($value);
                },
                'canonical_amount' => function ($amount) {
                    return $this->centsToDecimal($this->decimalToCents($amount));
                },
                'amount_to_cents' => function ($amount) {
                    return $this->decimalToCents($amount);
                },
                'cents_to_decimal' => function ($cents) {
                    return $this->centsToDecimal($cents);
                },
                'bank_statement_fingerprint' => function ($row) {
                    return $this->bankStatementFingerprint($row);
                },
                'new_id' => function () {
                    return uuid();
                },
                'account_set_id' => function () {
                    return $this->accountSetId;
                },
                'current_user_id' => function () {
                    return $this->userid;
                },
                'now' => function () {
                    return $this->now();
                },
                'bank_reconcile_biz_id' => function ($bizType, $biz) {
                    return $this->bankReconcileBizId($bizType, $biz);
                },
                'bank_reconcile_biz_no' => function ($bizType, $biz) {
                    return $this->bankReconcileBizNo($bizType, $biz);
                },
                'bank_reconcile_biz_date' => function ($bizType, $biz) {
                    return $this->bankReconcileBizDate($bizType, $biz);
                },
                'bank_reconcile_biz_summary' => function ($bizType, $biz) {
                    return $this->bankReconcileBizSummary($bizType, $biz);
                },
            ]);
        }
        return $this->bankingRule;
    }

    protected function voucherRule()
    {
        if ($this->voucherRule === null) {
            $this->voucherRule = new CaseFundVoucherRule([
                'amount_to_cents' => function ($amount) {
                    return $this->decimalToCents($amount);
                },
                'cents_to_decimal' => function ($cents) {
                    return $this->centsToDecimal($cents);
                },
                'new_id' => function () {
                    return uuid();
                },
                'normalize_payment_method' => function ($paymentMethod) {
                    return $this->normalizePaymentMethodCode($paymentMethod);
                },
                'subject_config_key' => function ($bizType, $voucherBizType, $businessItemType) {
                    return $this->voucherSubjectConfigKey($bizType, $voucherBizType, $businessItemType);
                },
                'get_subject_aux_configs' => function ($subjectCode) {
                    return $this->getSubjectAuxConfigs($subjectCode);
                },
                'need_verification' => function ($configs) {
                    return $this->needVerification($configs);
                },
                'has_required_aux_configs' => function ($configs) {
                    return $this->hasRequiredAuxConfigs($configs);
                },
                'filter_aux_values' => function ($auxValues, $configs) {
                    return $this->filterAuxValuesBySubjectConfigs($auxValues, $configs);
                },
                'build_aux_desc' => function ($auxValues) {
                    return $this->buildAuxDesc($auxValues);
                },
                'build_payment_summary' => function ($row) {
                    return $this->buildPaymentVoucherSummary($row);
                },
                'build_payment_method_summary' => function ($paymentMethod, $groupRows) {
                    return $this->buildPaymentMethodVoucherSummary($paymentMethod, $groupRows);
                },
                'build_refund_debit_detail' => function ($row, $config, $lineNo, $useNormalSummary) {
                    return $this->buildRefundDebitDetailData($row, $config, $lineNo, $useNormalSummary);
                },
                'build_refund_normal_summary' => function ($row) {
                    return $this->buildRefundNormalVoucherSummary($row);
                },
                'build_refund_aggregate_credit_summary' => function ($outOrderNo) {
                    return $this->buildRefundAggregateCreditSummary($outOrderNo);
                },
                'build_refund_aggregate_credit_detail' => function ($detailId, $lineNo, $subjectCode, $summary, $amountCents, $configs) {
                    return $this->buildRefundAggregateCreditDetail(
                        $detailId,
                        $lineNo,
                        $subjectCode,
                        $summary,
                        $amountCents,
                        $configs
                    );
                },
                'build_distribution_out_summary' => function ($row) {
                    return $this->buildDistributionTransferOutSummary($row);
                },
                'build_distribution_in_summary' => function ($row) {
                    return $this->buildDistributionTransferInSummary($row);
                },
            ]);
        }
        return $this->voucherRule;
    }

    protected function businessPolicy()
    {
        if ($this->businessPolicy === null) {
            $this->businessPolicy = new CaseFundBusinessPolicy();
        }
        return $this->businessPolicy;
    }

    protected function importIdentity()
    {
        if ($this->importIdentity === null) {
            $this->importIdentity = new CaseFundImportIdentity(function ($amount) {
                return $this->centsToDecimal($this->decimalToCents($amount));
            });
        }
        return $this->importIdentity;
    }

    protected function importNormalizer()
    {
        if ($this->importNormalizer === null) {
            $this->importNormalizer = new CaseFundImportNormalizer();
        }
        return $this->importNormalizer;
    }

    protected function importParser()
    {
        if ($this->importParser === null) {
            $this->importParser = new CaseFundImportParser([
                'normalize_cell' => function ($value) {
                    return $this->normalizeImportCellText($value);
                },
                'is_empty_row' => function ($row) {
                    return $this->isEmptyPaymentRawRow($row);
                },
                'is_malformed_row' => function ($row) {
                    return $this->isMalformedPaymentRawRow($row);
                },
                'is_non_detail_payment_row' => function ($row) {
                    return $this->isNonDetailPaymentRawRow($row);
                },
                'is_non_detail_refund_row' => function ($row) {
                    return $this->isNonDetailRefundRawRow($row);
                },
                'nullable_date' => function ($value) {
                    return $this->nullableDateValue($value);
                },
                'normalize_datetime' => function ($value) {
                    return $this->normalizeDateTimeValue($value);
                },
                'normalize_date' => function ($value) {
                    return $this->normalizeDateValue($value);
                },
                'canonical_amount' => function ($amount) {
                    return $this->centsToDecimal($this->decimalToCents($amount));
                },
                'yes_no_flag' => function ($value) {
                    return $this->yesNoFlagFromText($value);
                },
                'infer_payment_method' => function ($bankSerialNo, $rawPaymentMethod = '') {
                    return $this->inferPaymentMethodByBankSerialNo($bankSerialNo, $rawPaymentMethod);
                },
                'refund_required_headers' => function ($bizType = '', $receiptTraceRequired = null) {
                    return $this->refundImportRequiredHeaders($bizType, $receiptTraceRequired);
                },
                'payment_fingerprint' => function ($row) {
                    return $this->paymentFingerprint($row);
                },
                'refund_fingerprint' => function ($row) {
                    return $this->refundFingerprint($row);
                },
                'unknown_payment_fingerprint' => function ($row) {
                    return $this->unknownPaymentFingerprint($row);
                },
                'distribution_fingerprint' => function ($row) {
                    return $this->distributionFingerprint($row);
                },
            ]);
        }
        return $this->importParser;
    }

    protected function importValidator()
    {
        if ($this->importValidator === null) {
            $this->importValidator = new CaseFundImportValidator(
                $this->businessPolicy(),
                function ($amount) {
                    return $this->decimalToCents($amount);
                }
            );
        }
        return $this->importValidator;
    }

    protected function legacySpreadsheetReader()
    {
        if ($this->legacySpreadsheetReader === null) {
            $this->legacySpreadsheetReader = new LegacySpreadsheetReader();
        }
        return $this->legacySpreadsheetReader;
    }

    protected function subjectConfigResolver()
    {
        if ($this->subjectConfigResolver === null) {
            $this->subjectConfigResolver = new SubjectConfigResolver(
                $this->accountSetId,
                function ($table) {
                    return $this->getdb($table);
                },
                $this->businessPolicy(),
                self::SUBJECT_CONFIG_FIELD
            );
        }
        return $this->subjectConfigResolver;
    }

}
