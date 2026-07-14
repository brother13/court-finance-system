<?php

namespace app\finance\model;

use app\finance\service\casefund\banking\CaseFundBankingRule;
use app\finance\service\casefund\banking\CaseFundBankStatementDeletionService;
use app\finance\service\casefund\banking\CaseFundBankStatementImportService;
use app\finance\service\casefund\banking\CaseFundBankReconcileRepository;
use app\finance\service\casefund\banking\CaseFundBankReconcileMatcher;
use app\finance\service\casefund\importing\CaseFundImportIdentity;
use app\finance\service\casefund\importing\CaseFundImportNormalizer;
use app\finance\service\casefund\importing\CaseFundImportParser;
use app\finance\service\casefund\importing\CaseFundImportPersistence;
use app\finance\service\casefund\policy\CaseFundBusinessPolicy;
use app\finance\service\casefund\persistence\CaseFundBusinessDeletionService;
use app\finance\service\casefund\query\CaseFundBusinessQueryRepository;
use app\finance\service\casefund\support\LegacySpreadsheetReader;
use app\finance\service\casefund\subject\SubjectConfigResolver;
use app\finance\service\casefund\subject\SubjectConfigManager;
use app\finance\service\casefund\subject\SubjectConfigApplicationService;
use app\finance\service\casefund\validation\CaseFundImportValidator;
use app\finance\service\casefund\voucher\CaseFundVoucherRule;
use app\finance\service\casefund\voucher\PaymentVoucherGenerationService;
use app\finance\service\casefund\voucher\RefundVoucherGenerationService;
use app\finance\service\casefund\voucher\UnknownPaymentVoucherGenerationService;
use think\Db;

class CaseFund extends Common
{
    protected $bankingRule;
    protected $bankStatementDeletionService;
    protected $bankStatementImportService;
    protected $bankReconcileRepository;
    protected $bankReconcileMatcher;
    protected $businessPolicy;
    protected $businessDeletionService;
    protected $businessQueryRepository;
    protected $importIdentity;
    protected $importNormalizer;
    protected $importParser;
    protected $importPersistence;
    protected $importValidator;
    protected $legacySpreadsheetReader;
    protected $subjectConfigResolver;
    protected $subjectConfigManager;
    protected $subjectConfigApplicationService;
    protected $voucherRule;
    protected $paymentVoucherGenerationService;
    protected $refundVoucherGenerationService;
    protected $unknownPaymentVoucherGenerationService;

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
        $bizType = $this->currentAccountSetBizType();
        $allowedBusinessTypes = $this->allowedPaymentBusinessTypes($bizType);
        if (empty($allowedBusinessTypes)) {
            return $this->error('当前账套类型不支持案款缴费登记：' . $bizType);
        }
        $result = $this->businessQueryRepository()->paymentList(
            $data,
            $page,
            $pagesize,
            $allowedBusinessTypes
        );
        return $this->ok($result, 'OK', $result['total']);
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

        $persistenceData = $data;
        $persistenceData['use_receipt_no_as_duplicate_key'] = $receiptNoDuplicateCheckRequired;
        Db::startTrans();
        try {
            $result = $this->importPersistence()->persistPaymentRows($rows, $existing, $persistenceData);
            $created = $result['created'];
            $updated = $result['updated'];
            $skipped = $result['skipped'];
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
        $result = $this->businessQueryRepository()->unknownPaymentList($data, $page, $pagesize);
        return $this->ok($result, 'OK', $result['total']);
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

        $existing = $this->importPersistence()->loadExistingRowsByFingerprint('unknown_payment', $rows);

        Db::startTrans();
        try {
            $result = $this->importPersistence()->persistUniqueFingerprintRows(
                'unknown_payment',
                'unknown_payment_id',
                $rows,
                $existing,
                $data
            );
            $created = $result['created'];
            $skipped = $result['skipped'];
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
        $result = $this->businessQueryRepository()->distributionList($data, $page, $pagesize);
        return $this->ok($result, 'OK', $result['total']);
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

        $existing = $this->importPersistence()->loadExistingRowsByFingerprint('distribution', $rows);

        Db::startTrans();
        try {
            $result = $this->importPersistence()->persistUniqueFingerprintRows(
                'distribution',
                'distribution_id',
                $rows,
                $existing,
                $data
            );
            $created = $result['created'];
            $skipped = $result['skipped'];
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
        $bizType = $this->currentAccountSetBizType();
        $allowedOutTypes = $this->allowedRefundOutTypes($bizType);
        if (empty($allowedOutTypes)) {
            return $this->error('当前账套类型不支持案款退付登记：' . $bizType);
        }
        $result = $this->businessQueryRepository()->refundList(
            $data,
            $page,
            $pagesize,
            $allowedOutTypes
        );
        return $this->ok($result, 'OK', $result['total']);
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

        $existing = $this->importPersistence()->loadExistingRowsByFingerprint('refund', $rows, true);

        Db::startTrans();
        try {
            $result = $this->importPersistence()->persistRefundRows($rows, $existing, $data);
            $created = $result['created'];
            $updated = $result['updated'];
            $skipped = $result['skipped'];
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
        $result = $this->bankReconcileRepository()->bankStatementList($data, $page, $pagesize);
        $result['banks'] = $this->supportedBankStatementBanks();
        return $this->ok($result, 'OK', $result['total']);
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

        $prepared = $this->bankStatementImportService()->prepare(
            $rows,
            $bankCode,
            $banks[$bankCode],
            $data['filename'] ?? '',
            $data['remark'] ?? ''
        );
        if (!empty($prepared['errors'])) {
            return $this->error('导入校验失败', $prepared['errors']);
        }
        if ($prepared['parsed_count'] === 0) {
            return $this->error('导入文件没有可保存的银行对账单数据');
        }
        $created = $prepared['created'];
        $skipped = $prepared['skipped'];
        Db::startTrans();
        try {
            foreach ($prepared['insert_rows'] as $insert) {
                $this->fillCreate($insert);
                $this->getdb(self::TABLE_BANK_STATEMENT)->insert($insert);
            }
            $this->logAudit('CASE_FUND_BANK_STATEMENT', 'IMPORT', 'IMPORT', null, [
                'bank_code' => $bankCode,
                'bank_name' => $banks[$bankCode],
                'filename' => $data['filename'] ?? '',
                'total' => $prepared['parsed_count'],
                'created' => $created,
                'skipped' => $skipped,
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('保存银行对账单失败：' . $e->getMessage());
        }

        return $this->ok([
            'total' => $prepared['parsed_count'],
            'created' => $created,
            'skipped' => $skipped,
        ], '导入成功', $prepared['parsed_count']);
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

        $prepared = $this->bankStatementDeletionService()->prepare($ids);
        if (!empty($prepared['error'])) {
            return $this->error($prepared['error']);
        }
        $rows = $prepared['rows'];

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
        $statements = $this->bankReconcileRepository()->bankStatementsInScope(
            $bankCode,
            $dateStart,
            $dateEnd
        );
        $matchResult = $this->bankReconcileMatcher()->match(
            $statements,
            $bankCode,
            $dateStart,
            $dateEnd
        );
        $statementIds = $matchResult['statement_ids'];
        $resultRows = $matchResult['result_rows'];
        $statementStatuses = $matchResult['statement_statuses'];

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
        $result = $this->bankReconcileRepository()->bankReconcileList(
            $data,
            $page,
            $pagesize,
            $this->emptyBankReconcileCounts()
        );
        return $this->ok($result, 'OK', $result['total']);
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
        $result = $this->subjectConfigApplicationService()->listConfig($bizType, $voucherBizType);
        if (!empty($result['error'])) {
            return $this->error($result['error']);
        }
        return $this->ok($result['data'], 'OK', $result['total']);
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
        $prepared = $this->subjectConfigApplicationService()->prepareSave($data, $bizType, $voucherBizType);
        if (!empty($prepared['error'])) {
            return $this->error($prepared['error']);
        }

        Db::startTrans();
        try {
            $saveResult = $this->subjectConfigApplicationService()->savePrepared($prepared);
            $this->logAudit('CASE_FUND_SUBJECT_CONFIG', $voucherBizType . '@' . $bizType, 'SAVE', [
                'account_set' => $saveResult['account_set_before'],
                'subject_configs' => $saveResult['before'],
            ], [
                'account_set' => $saveResult['account_set_after'],
                'subject_configs' => $saveResult['after'],
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('保存科目配置失败：' . $e->getMessage());
        }

        return $this->ok(['saved' => $saveResult['saved']], '保存成功', $saveResult['saved']);
    }

    public function paymentGenerateVoucher($data = [])
    {
        $auth = $this->requirePermission('case_fund:generate_voucher');
        if ($auth) {
            return $auth;
        }

        $result = $this->paymentVoucherGenerationService()->selectedRows($data['payment_ids'] ?? []);
        if (!empty($result['error'])) {
            return $this->error($result['error']);
        }
        return $this->paymentGenerateVoucherFromRows($result['rows'], 'SELECTED');
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
        return $this->paymentVoucherGenerationService()->rowsByQuery($data);
    }

    protected function paymentGenerateVoucherFromRows($rows, $generateScope = 'SELECTED')
    {
        $prepared = $this->paymentVoucherGenerationService()->prepareGeneration($rows, $generateScope);
        if (!empty($prepared['error'])) {
            return $this->error($prepared['error']);
        }

        Db::startTrans();
        try {
            $result = $this->paymentVoucherGenerationService()->generatePrepared($prepared);
            $this->logAudit('CASE_FUND_PAYMENT', 'VOUCHER_GENERATE', 'GENERATE', null, [
                'payment_count' => $result['payment_count'],
                'voucher_count' => $result['generated_count'],
                'generate_scope' => $result['generate_scope'],
                'vouchers' => $result['vouchers'],
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('生成凭证失败：' . $e->getMessage());
        }

        return $this->ok([
            'generated_count' => $result['generated_count'],
            'payment_count' => $result['payment_count'],
            'vouchers' => $result['vouchers'],
        ], '成功生成 ' . $result['generated_count'] . ' 张凭证');
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

        $prepared = $this->unknownPaymentVoucherGenerationService()->prepareGeneration(
            $data['unknown_payment_ids'] ?? ($data['ids'] ?? [])
        );
        if (!empty($prepared['error'])) {
            return $this->error($prepared['error']);
        }

        Db::startTrans();
        try {
            $result = $this->unknownPaymentVoucherGenerationService()->generatePrepared($prepared);
            $this->logAudit('CASE_FUND_UNKNOWN_PAYMENT', 'VOUCHER_GENERATE', 'GENERATE', null, [
                'unknown_payment_count' => $result['unknown_payment_count'],
                'voucher_count' => $result['generated_count'],
                'vouchers' => $result['vouchers'],
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('生成凭证失败：' . $e->getMessage());
        }

        return $this->ok([
            'generated_count' => $result['generated_count'],
            'unknown_payment_count' => $result['unknown_payment_count'],
            'vouchers' => $result['vouchers'],
        ], '成功生成 ' . $result['generated_count'] . ' 张凭证');
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

        $result = $this->refundVoucherGenerationService()->selectedRows($data['refund_ids'] ?? []);
        if (!empty($result['error'])) {
            return $this->error($result['error']);
        }
        return $this->refundGenerateVoucherFromRows($result['rows'], 'SELECTED');
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
        return $this->refundVoucherGenerationService()->rowsByQuery($data);
    }

    protected function refundGenerateVoucherFromRows($rows, $generateScope = 'SELECTED')
    {
        $prepared = $this->refundVoucherGenerationService()->prepareGeneration($rows, $generateScope);
        if (!empty($prepared['error'])) {
            return $this->error($prepared['error']);
        }

        Db::startTrans();
        try {
            $result = $this->refundVoucherGenerationService()->generatePrepared($prepared);
            $this->logAudit('CASE_FUND_REFUND', 'VOUCHER_GENERATE', 'GENERATE', null, [
                'refund_count' => $result['refund_count'],
                'voucher_count' => $result['generated_count'],
                'generate_scope' => $result['generate_scope'],
                'vouchers' => $result['vouchers'],
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('生成凭证失败：' . $e->getMessage());
        }

        return $this->ok([
            'generated_count' => $result['generated_count'],
            'refund_count' => $result['refund_count'],
            'vouchers' => $result['vouchers'],
        ], '成功生成 ' . $result['generated_count'] . ' 张凭证');
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
        return $this->importPersistence()->paymentReceiptNoDuplicateErrors($rows);
    }

    protected function loadExistingPaymentsByReceiptNos($receiptNos)
    {
        return $this->importPersistence()->loadExistingPaymentsByReceiptNos($receiptNos);
    }

    protected function paymentExistingDuplicateSummary($row)
    {
        return $this->importPersistence()->paymentExistingDuplicateSummary($row);
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
        $prepared = $this->businessDeletionService()->prepareBusinessDeletion(
            $table,
            $idField,
            $ids,
            $bizType,
            $label
        );
        if (!empty($prepared['error'])) {
            return $this->error($prepared['error']);
        }
        $rows = $prepared['rows'];
        $hardDelete = $prepared['hard_delete'];
        Db::startTrans();
        try {
            $this->businessDeletionService()->executeBusinessDeletion($table, $idField, $ids, $hardDelete);
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
        return $this->businessDeletionService()->shouldPhysicalDeleteCaseFundBusinessRows($bizType);
    }

    protected function assertRowsCanDeleteBusinessData($rows, $label)
    {
        return $this->businessDeletionService()->assertRowsCanDeleteBusinessData($rows, $label);
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

    protected function existingBankStatementFingerprints($fingerprints)
    {
        return $this->bankReconcileRepository()->existingBankStatementFingerprints($fingerprints);
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
        return $this->bankReconcileRepository()->paymentCandidatesByBankStatement($statement);
    }

    protected function refundCandidatesByBankStatement($statement)
    {
        return $this->bankReconcileRepository()->refundCandidatesByBankStatement($statement);
    }

    protected function paymentCandidatesInScope($dateStart, $dateEnd)
    {
        return $this->bankReconcileRepository()->paymentCandidatesInScope($dateStart, $dateEnd);
    }

    protected function refundCandidatesInScope($dateStart, $dateEnd)
    {
        return $this->bankReconcileRepository()->refundCandidatesInScope($dateStart, $dateEnd);
    }

    protected function bankStatementExistsByPayment($payment, $bankCode = '', $dateStart = '', $dateEnd = '')
    {
        return $this->bankReconcileRepository()->bankStatementExistsByPayment(
            $payment,
            $bankCode,
            $dateStart,
            $dateEnd
        );
    }

    protected function bankStatementExistsByRefundPayee($refund, $bankCode = '', $dateStart = '', $dateEnd = '')
    {
        return $this->bankReconcileRepository()->bankStatementExistsByRefundPayee(
            $refund,
            $bankCode,
            $dateStart,
            $dateEnd
        );
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
        return $this->bankReconcileRepository()->softDeleteBankReconcileScope($dateStart, $dateEnd);
    }

    protected function emptyBankReconcileCounts()
    {
        return $this->bankingRule()->emptyBankReconcileCounts();
    }

    protected function bankReconcileSummary($where)
    {
        return $this->bankReconcileRepository()->bankReconcileSummary($where, $this->emptyBankReconcileCounts());
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
        return $this->subjectConfigManager()->paymentMethodSubjectConfigItems($bizType, $paymentItems);
    }

    protected function paymentMethodSubjectConfigMap($bizType)
    {
        return $this->subjectConfigResolver()->paymentMethodConfigMap($bizType);
    }

    protected function savePaymentMethodSubjectConfigs($bizType, $methodItems, $paymentItems)
    {
        return $this->subjectConfigManager()->savePaymentMethodSubjectConfigs($bizType, $methodItems, $paymentItems);
    }

    protected function validateVoucherSubjectCode($subjectCode, $label)
    {
        return $this->subjectConfigManager()->validateVoucherSubjectCode($subjectCode, $label);
    }

    protected function subjectNameMap($subjectCodes)
    {
        return $this->subjectConfigManager()->subjectNameMap($subjectCodes);
    }

    protected function subjectConfigAccountSetFlag()
    {
        return $this->subjectConfigManager()->subjectConfigAccountSetFlag();
    }

    protected function generateRefundVoucherByOutOrderNoFlag()
    {
        return $this->subjectConfigManager()->generateRefundVoucherByOutOrderNoFlag();
    }

    protected function generatePaymentVoucherByMethodFlag()
    {
        return $this->subjectConfigManager()->generatePaymentVoucherByMethodFlag();
    }

    protected function saveSubjectConfigAccountSetFlag($flag, $refundOutOrderFlag = null, $paymentMethodFlag = null)
    {
        return $this->subjectConfigManager()->saveSubjectConfigAccountSetFlag(
            $flag,
            $refundOutOrderFlag,
            $paymentMethodFlag
        );
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
        return $this->importPersistence()->loadExistingPaymentsForImport($rows, $useReceiptNoAsDuplicateKey);
    }

    protected function rememberPaymentImportExistingRow(&$existing, $row, $useReceiptNoAsDuplicateKey = true)
    {
        $key = $this->paymentImportDuplicateKey($row, $useReceiptNoAsDuplicateKey);
        return $this->importIdentity()->rememberPaymentImportExistingRowByKey($existing, $key, $row);
    }

    protected function updateExistingPaymentFromImport($existingRow, $row, $data)
    {
        return $this->importPersistence()->updateExistingPaymentFromImport($existingRow, $row, $data);
    }

    protected function updateExistingRefundFromImport($existingRow, $row, $data)
    {
        return $this->importPersistence()->updateExistingRefundFromImport($existingRow, $row, $data);
    }

    protected function canOverwriteGeneratedBusinessRow($row)
    {
        return $this->importPersistence()->canOverwriteGeneratedBusinessRow($row);
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

    protected function bankReconcileRepository()
    {
        if ($this->bankReconcileRepository === null) {
            $this->bankReconcileRepository = new CaseFundBankReconcileRepository([
                'get_db' => function ($table) {
                    return $this->getdb($table);
                },
                'account_set_id' => function () {
                    return $this->accountSetId;
                },
                'current_year' => function () {
                    return $this->currentYear();
                },
                'prepare_update' => function ($row) {
                    $this->fillUpdate($row);
                    return $row;
                },
            ], [
                'payment' => self::TABLE_PAYMENT,
                'refund' => self::TABLE_REFUND,
                'statement' => self::TABLE_BANK_STATEMENT,
                'reconcile' => self::TABLE_BANK_RECONCILE,
            ], [
                'payment' => self::PAYMENT_FIELD,
                'refund' => self::REFUND_FIELD,
                'statement' => self::BANK_STATEMENT_FIELD,
                'reconcile' => self::BANK_RECONCILE_FIELD,
            ], self::BANK_RECONCILE_AUTO_MATCH_RULES);
        }
        return $this->bankReconcileRepository;
    }

    protected function bankStatementImportService()
    {
        if ($this->bankStatementImportService === null) {
            $this->bankStatementImportService = new CaseFundBankStatementImportService([
                'normalize_row' => function ($row, $bankCode, $bankName, $defaultRowNo) {
                    return $this->normalizeBankStatementRow($row, $bankCode, $bankName, $defaultRowNo);
                },
                'existing_fingerprints' => function ($fingerprints) {
                    return $this->existingBankStatementFingerprints($fingerprints);
                },
                'new_id' => function () {
                    return uuid();
                },
                'account_set_id' => function () {
                    return $this->accountSetId;
                },
            ]);
        }
        return $this->bankStatementImportService;
    }

    protected function bankStatementDeletionService()
    {
        if ($this->bankStatementDeletionService === null) {
            $this->bankStatementDeletionService = new CaseFundBankStatementDeletionService([
                'unmatched_statements_by_ids' => function ($ids) {
                    return $this->bankReconcileRepository()->bankStatementsForDeletion($ids);
                },
                'active_reconcile_count' => function ($ids) {
                    return $this->bankReconcileRepository()->activeBankReconcileCountForStatements($ids);
                },
            ]);
        }
        return $this->bankStatementDeletionService;
    }

    protected function bankReconcileMatcher()
    {
        if ($this->bankReconcileMatcher === null) {
            $this->bankReconcileMatcher = new CaseFundBankReconcileMatcher([
                'amount_to_cents' => function ($amount) {
                    return $this->decimalToCents($amount);
                },
                'payment_candidates_by_statement' => function ($statement) {
                    return $this->paymentCandidatesByBankStatement($statement);
                },
                'refund_candidates_by_statement' => function ($statement) {
                    return $this->refundCandidatesByBankStatement($statement);
                },
                'payment_candidates_in_scope' => function ($dateStart, $dateEnd) {
                    return $this->paymentCandidatesInScope($dateStart, $dateEnd);
                },
                'refund_candidates_in_scope' => function ($dateStart, $dateEnd) {
                    return $this->refundCandidatesInScope($dateStart, $dateEnd);
                },
                'bank_statement_exists_by_payment' => function ($payment, $bankCode, $dateStart, $dateEnd) {
                    return $this->bankStatementExistsByPayment($payment, $bankCode, $dateStart, $dateEnd);
                },
                'bank_statement_exists_by_refund' => function ($refund, $bankCode, $dateStart, $dateEnd) {
                    return $this->bankStatementExistsByRefundPayee($refund, $bankCode, $dateStart, $dateEnd);
                },
                'build_reconcile_row' => function ($statement, $bizType, $biz, $status, $bankCents, $bizCents, $matchRule) {
                    return $this->buildBankReconcileRow(
                        $statement,
                        $bizType,
                        $biz,
                        $status,
                        $bankCents,
                        $bizCents,
                        $matchRule
                    );
                },
                'biz_amount_cents' => function ($bizType, $biz) {
                    return $this->bankReconcileBizAmountCents($bizType, $biz);
                },
                'biz_id' => function ($bizType, $biz) {
                    return $this->bankReconcileBizId($bizType, $biz);
                },
            ]);
        }
        return $this->bankReconcileMatcher;
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
                'build_unknown_payment_summary' => function ($row) {
                    return $this->buildUnknownPaymentVoucherSummary($row);
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

    protected function paymentVoucherGenerationService()
    {
        if ($this->paymentVoucherGenerationService === null) {
            $this->paymentVoucherGenerationService = new PaymentVoucherGenerationService(
                $this->businessPolicy(),
                $this->subjectConfigResolver(),
                $this->subjectConfigManager(),
                $this->voucherRule(),
                [
                    'get_db' => function ($table) {
                        return $this->getdb($table);
                    },
                    'account_where' => function () {
                        return $this->accountWhere();
                    },
                    'account_set_id' => function () {
                        return $this->accountSetId;
                    },
                    'current_year' => function () {
                        return $this->currentYear();
                    },
                    'current_biz_type' => function () {
                        return $this->currentAccountSetBizType();
                    },
                    'assert_rows_can_generate' => function ($rows, $recordLabel) {
                        return $this->assertRowsCanGenerateVoucher($rows, $recordLabel);
                    },
                    'fiscal_year' => function ($period) {
                        return $this->fiscalYear($period);
                    },
                    'generate_next_voucher_no' => function ($period) {
                        return $this->generateNextVoucherNo($period);
                    },
                    'ensure_aux_archives' => function ($detailsData) {
                        $this->ensureAuxArchivesForVoucherDetails($detailsData);
                    },
                    'cents_to_decimal' => function ($cents) {
                        return $this->centsToDecimal($cents);
                    },
                    'new_id' => function () {
                        return uuid();
                    },
                    'prepare_create' => function ($row) {
                        $this->fillCreate($row);
                        return $row;
                    },
                    'current_user_id' => function () {
                        return $this->userid;
                    },
                    'now' => function () {
                        return $this->now();
                    },
                ],
                self::TABLE_PAYMENT,
                self::PAYMENT_FIELD
            );
        }
        return $this->paymentVoucherGenerationService;
    }

    protected function refundVoucherGenerationService()
    {
        if ($this->refundVoucherGenerationService === null) {
            $this->refundVoucherGenerationService = new RefundVoucherGenerationService(
                $this->businessPolicy(),
                $this->subjectConfigResolver(),
                $this->subjectConfigManager(),
                $this->voucherRule(),
                [
                    'get_db' => function ($table) {
                        return $this->getdb($table);
                    },
                    'account_where' => function () {
                        return $this->accountWhere();
                    },
                    'account_set_id' => function () {
                        return $this->accountSetId;
                    },
                    'current_year' => function () {
                        return $this->currentYear();
                    },
                    'current_biz_type' => function () {
                        return $this->currentAccountSetBizType();
                    },
                    'normalize_date_filter' => function ($value) {
                        return $this->normalizeDateFilterValue($value);
                    },
                    'assert_rows_can_generate' => function ($rows, $recordLabel) {
                        return $this->assertRowsCanGenerateVoucher($rows, $recordLabel);
                    },
                    'fiscal_year' => function ($period) {
                        return $this->fiscalYear($period);
                    },
                    'generate_next_voucher_no' => function ($period) {
                        return $this->generateNextVoucherNo($period);
                    },
                    'ensure_aux_archives' => function ($detailsData) {
                        $this->ensureAuxArchivesForVoucherDetails($detailsData);
                    },
                    'cents_to_decimal' => function ($cents) {
                        return $this->centsToDecimal($cents);
                    },
                    'new_id' => function () {
                        return uuid();
                    },
                    'prepare_create' => function ($row) {
                        $this->fillCreate($row);
                        return $row;
                    },
                    'current_user_id' => function () {
                        return $this->userid;
                    },
                    'now' => function () {
                        return $this->now();
                    },
                ],
                self::TABLE_REFUND,
                self::REFUND_FIELD
            );
        }
        return $this->refundVoucherGenerationService;
    }

    protected function unknownPaymentVoucherGenerationService()
    {
        if ($this->unknownPaymentVoucherGenerationService === null) {
            $this->unknownPaymentVoucherGenerationService = new UnknownPaymentVoucherGenerationService(
                $this->subjectConfigManager(),
                $this->voucherRule(),
                [
                    'get_db' => function ($table) {
                        return $this->getdb($table);
                    },
                    'account_set_id' => function () {
                        return $this->accountSetId;
                    },
                    'current_year' => function () {
                        return $this->currentYear();
                    },
                    'current_biz_type' => function () {
                        return $this->currentAccountSetBizType();
                    },
                    'normalize_id_list' => function ($value) {
                        return $this->normalizeIdList($value);
                    },
                    'get_subject_aux_configs' => function ($subjectCode) {
                        return $this->getSubjectAuxConfigs($subjectCode);
                    },
                    'has_required_aux_configs' => function ($configs) {
                        return $this->hasRequiredAuxConfigs($configs);
                    },
                    'assert_rows_can_generate' => function ($rows, $recordLabel) {
                        return $this->assertRowsCanGenerateVoucher($rows, $recordLabel);
                    },
                    'validate_period' => function ($row, $period) {
                        return $this->validateUnknownPaymentVoucherPeriod($row, $period);
                    },
                    'build_summary' => function ($row) {
                        return $this->buildUnknownPaymentVoucherSummary($row);
                    },
                    'fiscal_year' => function ($period) {
                        return $this->fiscalYear($period);
                    },
                    'generate_next_voucher_no' => function ($period) {
                        return $this->generateNextVoucherNo($period);
                    },
                    'cents_to_decimal' => function ($cents) {
                        return $this->centsToDecimal($cents);
                    },
                    'new_id' => function () {
                        return uuid();
                    },
                    'prepare_create' => function ($row) {
                        $this->fillCreate($row);
                        return $row;
                    },
                    'current_user_id' => function () {
                        return $this->userid;
                    },
                    'now' => function () {
                        return $this->now();
                    },
                ],
                self::TABLE_UNKNOWN_PAYMENT,
                self::UNKNOWN_PAYMENT_FIELD,
                self::TABLE_SUBJECT_CONFIG,
                self::SUBJECT_CONFIG_FIELD
            );
        }
        return $this->unknownPaymentVoucherGenerationService;
    }

    protected function businessPolicy()
    {
        if ($this->businessPolicy === null) {
            $this->businessPolicy = new CaseFundBusinessPolicy();
        }
        return $this->businessPolicy;
    }

    protected function businessDeletionService()
    {
        if ($this->businessDeletionService === null) {
            $this->businessDeletionService = new CaseFundBusinessDeletionService([
                'get_db' => function ($table) {
                    return $this->getdb($table);
                },
                'account_where' => function () {
                    return $this->accountWhere();
                },
                'account_set_id' => function () {
                    return $this->accountSetId;
                },
                'current_year' => function () {
                    return $this->currentYear();
                },
                'prepare_update' => function ($row) {
                    $this->fillUpdate($row);
                    return $row;
                },
                'has_active_generated_voucher' => function ($row) {
                    return $this->hasActiveGeneratedVoucher($row);
                },
                'assert_rows_can_delete' => function ($rows, $label) {
                    return $this->assertRowsCanDeleteBusinessData($rows, $label);
                },
                'should_physical_delete' => function ($bizType) {
                    return $this->shouldPhysicalDeleteCaseFundBusinessRows($bizType);
                },
            ], self::TABLE_BANK_RECONCILE, [
                'PAYMENT' => self::PAYMENT_FIELD,
                'REFUND' => self::REFUND_FIELD,
                'UNKNOWN_PAYMENT' => self::UNKNOWN_PAYMENT_FIELD,
                'DISTRIBUTION' => self::DISTRIBUTION_FIELD,
            ]);
        }
        return $this->businessDeletionService;
    }

    protected function businessQueryRepository()
    {
        if ($this->businessQueryRepository === null) {
            $this->businessQueryRepository = new CaseFundBusinessQueryRepository([
                'get_db' => function ($table) {
                    return $this->getdb($table);
                },
                'account_where' => function () {
                    return $this->accountWhere();
                },
                'current_year' => function () {
                    return $this->currentYear();
                },
                'normalize_payment_method' => function ($method) {
                    return $this->normalizePaymentMethodCode($method);
                },
                'payment_method_options' => function () {
                    return $this->paymentMethodOptions();
                },
                'normalize_date_filter' => function ($value) {
                    return $this->normalizeDateFilterValue($value);
                },
            ], [
                'payment' => self::TABLE_PAYMENT,
                'refund' => self::TABLE_REFUND,
                'unknown_payment' => self::TABLE_UNKNOWN_PAYMENT,
                'distribution' => self::TABLE_DISTRIBUTION,
            ], [
                'payment' => self::PAYMENT_FIELD,
                'refund' => self::REFUND_FIELD,
                'unknown_payment' => self::UNKNOWN_PAYMENT_FIELD,
                'distribution' => self::DISTRIBUTION_FIELD,
            ]);
        }
        return $this->businessQueryRepository;
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

    protected function importPersistence()
    {
        if ($this->importPersistence === null) {
            $this->importPersistence = new CaseFundImportPersistence([
                'get_db' => function ($table) {
                    return $this->getdb($table);
                },
                'account_set_id' => function () {
                    return $this->accountSetId;
                },
                'new_id' => function () {
                    return uuid();
                },
                'prepare_create' => function ($row) {
                    $this->fillCreate($row);
                    return $row;
                },
                'prepare_update' => function ($row) {
                    $this->fillUpdate($row);
                    return $row;
                },
                'payment_duplicate_key' => function ($row, $useReceiptNoAsDuplicateKey) {
                    return $this->paymentImportDuplicateKey($row, $useReceiptNoAsDuplicateKey);
                },
                'remember_payment_existing' => function (&$existing, $row, $useReceiptNoAsDuplicateKey) {
                    return $this->rememberPaymentImportExistingRow($existing, $row, $useReceiptNoAsDuplicateKey);
                },
                'has_active_generated_voucher' => function ($row) {
                    return $this->hasActiveGeneratedVoucher($row);
                },
            ], [
                'payment' => self::TABLE_PAYMENT,
                'refund' => self::TABLE_REFUND,
                'unknown_payment' => self::TABLE_UNKNOWN_PAYMENT,
                'distribution' => self::TABLE_DISTRIBUTION,
            ], self::PAYMENT_FIELD);
        }
        return $this->importPersistence;
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

    protected function subjectConfigManager()
    {
        if ($this->subjectConfigManager === null) {
            $this->subjectConfigManager = new SubjectConfigManager([
                'get_db' => function ($table) {
                    return $this->getdb($table);
                },
                'account_set_id' => function () {
                    return $this->accountSetId;
                },
                'new_id' => function () {
                    return uuid();
                },
                'prepare_create' => function ($row) {
                    $this->fillCreate($row);
                    return $row;
                },
                'prepare_update' => function ($row) {
                    $this->fillUpdate($row);
                    return $row;
                },
                'payment_method_options' => function () {
                    return $this->paymentMethodOptions();
                },
                'normalize_payment_method' => function ($method) {
                    return $this->normalizePaymentMethodCode($method);
                },
                'payment_method_config_map' => function ($bizType) {
                    return $this->paymentMethodSubjectConfigMap($bizType);
                },
                'subject_name_map' => function ($subjectCodes) {
                    return $this->subjectNameMap($subjectCodes);
                },
                'validate_subject_code' => function ($subjectCode, $label) {
                    return $this->validateVoucherSubjectCode($subjectCode, $label);
                },
                'normalize_flag' => function ($value) {
                    return $this->normalizeDailyVoucherFlag($value);
                },
            ], self::TABLE_SUBJECT_CONFIG);
        }
        return $this->subjectConfigManager;
    }

    protected function subjectConfigApplicationService()
    {
        if ($this->subjectConfigApplicationService === null) {
            $this->subjectConfigApplicationService = new SubjectConfigApplicationService(
                $this->businessPolicy(),
                $this->subjectConfigResolver(),
                $this->subjectConfigManager(),
                [
                    'account_set_id' => function () {
                        return $this->accountSetId;
                    },
                ]
            );
        }
        return $this->subjectConfigApplicationService;
    }

}
