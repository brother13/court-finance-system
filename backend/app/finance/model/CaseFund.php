<?php

namespace app\finance\model;

use app\finance\service\casefund\banking\CaseFundBankingRule;
use app\finance\service\casefund\banking\CaseFundBankStatementDeletionService;
use app\finance\service\casefund\banking\CaseFundBankStatementImportService;
use app\finance\service\casefund\banking\CaseFundBankReconcileRepository;
use app\finance\service\casefund\banking\CaseFundBankReconcileMatcher;
use app\finance\service\casefund\banking\CaseFundBankingPersistenceService;
use app\finance\service\casefund\importing\CaseFundImportIdentity;
use app\finance\service\casefund\importing\CaseFundImportNormalizer;
use app\finance\service\casefund\importing\CaseFundImportParser;
use app\finance\service\casefund\importing\CaseFundImportPersistence;
use app\finance\service\casefund\policy\CaseFundBusinessPolicy;
use app\finance\service\casefund\policy\CaseFundReceiptTracePolicy;
use app\finance\service\casefund\persistence\CaseFundBusinessDeletionService;
use app\finance\service\casefund\query\CaseFundBusinessQueryRepository;
use app\finance\service\casefund\returning\CaseFundUnknownRefundMatchService;
use app\finance\service\casefund\support\LegacySpreadsheetReader;
use app\finance\service\casefund\subject\SubjectConfigResolver;
use app\finance\service\casefund\subject\SubjectConfigManager;
use app\finance\service\casefund\subject\SubjectConfigApplicationService;
use app\finance\service\casefund\validation\CaseFundImportValidator;
use app\finance\service\casefund\voucher\CaseFundVoucherRule;
use app\finance\service\casefund\voucher\CaseFundVoucherPersistenceService;
use app\finance\service\casefund\voucher\CaseFundVoucherAuxiliaryService;
use app\finance\service\casefund\voucher\PaymentVoucherGenerationService;
use app\finance\service\casefund\voucher\RefundVoucherGenerationService;
use app\finance\service\casefund\voucher\UnknownPaymentAuxiliaryCaseService;
use app\finance\service\casefund\voucher\UnknownPaymentVoucherGenerationService;
use app\finance\service\casefund\voucher\DistributionVoucherGenerationService;
use app\finance\service\job\FinanceAsyncJobLauncher;
use app\finance\service\job\FinanceAsyncJobService;
use think\Db;

class CaseFund extends Common
{
    protected $bankingRule;
    protected $bankStatementDeletionService;
    protected $bankStatementImportService;
    protected $bankReconcileRepository;
    protected $bankReconcileMatcher;
    protected $bankingPersistenceService;
    protected $businessPolicy;
    protected $receiptTracePolicy;
    protected $businessDeletionService;
    protected $businessQueryRepository;
    protected $unknownRefundMatchService;
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
    protected $voucherPersistenceService;
    protected $voucherAuxiliaryService;
    protected $paymentVoucherGenerationService;
    protected $refundVoucherGenerationService;
    protected $unknownPaymentAuxiliaryCaseService;
    protected $unknownPaymentVoucherGenerationService;
    protected $distributionVoucherGenerationService;
    protected $financeAsyncJobService;

    const ACTION = 'caseFund';
    const TABLE_PAYMENT = 'fin_case_fund_payment';
    const TABLE_REFUND = 'fin_case_fund_refund';
    const TABLE_UNKNOWN_PAYMENT = 'fin_case_fund_unknown_payment';
    const TABLE_UNKNOWN_REFUND_MATCH = 'fin_case_fund_unknown_refund_match';
    const TABLE_DISTRIBUTION = 'fin_case_fund_distribution';
    const TABLE_BANK_STATEMENT = 'fin_case_fund_bank_statement';
    const TABLE_BANK_RECONCILE = 'fin_case_fund_bank_reconcile';
    const TABLE_IMPORT_STAGE = 'fin_case_fund_import_stage';
    const JOB_PAYMENT_IMPORT = 'CASE_FUND_PAYMENT_IMPORT';
    const JOB_PAYMENT_VOUCHER = 'CASE_FUND_PAYMENT_VOUCHER';
    const JOB_REFUND_VOUCHER = 'CASE_FUND_REFUND_VOUCHER';
    const JOB_BANK_RECONCILE = 'CASE_FUND_BANK_RECONCILE';
    // 生产环境以低配置服务器为基线：Base64 上传会同时占用编码文本和解码后二进制，
    // 单任务范围也会在分组制证时形成额外数组。这里采用保守硬上限，超限时要求拆批，
    // 避免 worker 因内存耗尽反复被接管。
    const ASYNC_IMPORT_MAX_BYTES = 10485760;
    const ASYNC_VOUCHER_MAX_ROWS = 5000;
    const BANK_RECONCILE_AUTO_MATCH_RULES = [
        'SERIAL_NO',
        'SERIAL_NO_DATE',
        'PAYER_ACCOUNT_DATE',
        'PAYEE_ACCOUNT_DATE',
        'PAYEE_ACCOUNT_DATE_AMOUNT',
    ];
    const TABLE_SUBJECT_CONFIG = 'fin_case_fund_subject_config';
    const PAYMENT_FIELD = [
        'payment_id', 'account_set_id', 'fiscal_year', 'period', 'case_no', 'confirmed_flag',
        'available_flag', 'business_type', 'payer_name', 'party_name', 'invoice_title',
        'payment_amount', 'register_type', 'trial_case_no', 'payment_date', 'payment_time',
        'claim_date', 'posting_date',
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
    // 制证 worker 只读取规则、排序、并发复核所需字段，避免 QUERY 任务把完整
    // 导入原文等大字段载入内存。列表/导出仍继续使用上面的完整字段集。
    const PAYMENT_VOUCHER_FIELD = [
        'payment_id', 'period', 'case_no', 'business_type', 'party_name', 'payment_amount',
        'payment_date', 'posting_date', 'receipt_no', 'payment_method', 'source_row_no', 'voucher_status',
        'payment_time', 'payer_name', 'bank_account_no',
        'voucher_id', 'voucher_period',
    ];
    const REFUND_VOUCHER_FIELD = [
        'refund_id', 'period', 'case_no', 'receipt_no', 'refund_date', 'source_receipt_no',
        'out_order_no', 'out_type', 'party_name', 'refund_amount', 'actual_payee_name',
        'payee_bank_account_name', 'source_row_no', 'voucher_status', 'voucher_id', 'voucher_period',
    ];
    const UNKNOWN_PAYMENT_FIELD = [
        'unknown_payment_id', 'account_set_id', 'fiscal_year', 'period', 'court_name',
        'claim_status', 'payer_name', 'unknown_amount', 'bank_summary', 'bank_remark',
        'aux_case_code',
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
            case 'jobCreate':
                return $this->jobCreate($data);
            case 'jobStatus':
                return $this->jobStatus($data);
            case 'jobList':
                return $this->jobList($data);
            case 'jobErrorList':
                return $this->jobErrorList($data);
            case 'unknownPaymentList':
                return $this->unknownPaymentList($data);
            case 'unknownPaymentImport':
                return $this->unknownPaymentImport($data);
            case 'unknownPaymentGenerateVoucher':
                return $this->unknownPaymentGenerateVoucher($data);
            case 'unknownPaymentDelete':
                return $this->unknownPaymentDelete($data);
            case 'unknownRefundMatchPreview':
                return $this->unknownRefundMatchPreview($data);
            case 'unknownRefundMatchScan':
                return $this->unknownRefundMatchScan($data);
            case 'unknownRefundMatchCandidates':
                return $this->unknownRefundMatchCandidates($data);
            case 'unknownRefundMatchConfirm':
                return $this->unknownRefundMatchConfirm($data);
            case 'unknownRefundMatchReject':
                return $this->unknownRefundMatchReject($data);
            case 'unknownRefundMatchCancel':
                return $this->unknownRefundMatchCancel($data);
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
        try {
            $data = $this->normalizeBusinessAmountRange($data);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
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
        $receiptNoDuplicateCheckRequired = $this->receiptTracePolicy()
            ->paymentImportRequiresReceiptNoDuplicateCheck($bizType);
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

    /**
     * 创建可恢复的财务后台任务。HTTP 请求只完成文件落盘和任务入队，
     * 正式业务写入由单 worker 执行，避免浏览器或反向代理超时后状态不明。
     */
    public function jobCreate($data = [])
    {
        $jobType = trim((string)($data['job_type'] ?? ''));
        $permission = $this->asyncJobPermission($jobType);
        if ($permission === '') {
            return $this->error('不支持的后台任务类型：' . ($jobType ?: 'EMPTY'));
        }
        $auth = $this->requirePermission($permission);
        if ($auth) {
            return $auth;
        }

        $clientRequestId = trim((string)($data['client_request_id'] ?? ''));
        if ($clientRequestId === '' || strlen($clientRequestId) > 64
            || !preg_match('/^[A-Za-z0-9._:-]+$/', $clientRequestId)
        ) {
            return $this->error('客户端请求ID不能为空，且只能包含字母、数字、点、横线、下划线或冒号');
        }

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        try {
            $payload = $this->sanitizeAsyncJobPayload($jobType, $payload);
            $period = $this->normalizeAsyncJobPeriod($payload['period'] ?? '');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        try {
            $service = $this->financeAsyncJobService();
            $existing = $service->findByClientRequestId(
                $this->accountSetId,
                $jobType,
                $clientRequestId
            );
        } catch (\Throwable $e) {
            return $this->error('后台任务表不可用，请先执行 2026-08-03-finance-async-job.sql：' . $e->getMessage());
        }
        if ($existing) {
            if (($existing['created_by'] ?? '') !== $this->userid) {
                return $this->error('客户端请求ID已被其他用户使用，请重新提交');
            }
            try {
                $this->assertAsyncRequestMatchesExisting($existing, $jobType, $payload, $data);
            } catch (\Throwable $e) {
                return $this->error($e->getMessage());
            }
            return $this->ok(
                $this->launchAndPublicAsyncJob($existing, $service),
                '已找到同一请求对应的后台任务'
            );
        }

        $sourceFile = [
            'name' => null,
            'path' => null,
            'hash' => null,
        ];
        if ($jobType === self::JOB_PAYMENT_IMPORT) {
            try {
                $sourceFile = $this->saveAsyncImportFile($data);
            } catch (\Throwable $e) {
                return $this->error($e->getMessage());
            }
        }

        $payload['_request_fingerprint'] = $this->asyncRequestFingerprint(
            $jobType,
            $payload,
            $sourceFile['hash']
        );
        if (($payload['generate_scope'] ?? '') === 'QUERY') {
            // QUERY 不在 HTTP 请求内全量取数，但必须以提交时刻为范围上界，
            // 避免排队期间新写入的数据被意外纳入本次制证任务。
            $payload['scope_snapshot_time'] = $this->now();
        }

        $createResult = null;
        Db::startTrans();
        try {
            $createResult = $service->create([
                'account_set_id' => $this->accountSetId,
                'fiscal_year' => $this->currentYear(),
                'period' => $period,
                'job_type' => $jobType,
                'client_request_id' => $clientRequestId,
                'payload' => array_merge($payload, [
                    'operator_name' => $this->username,
                ]),
                'source_file_name' => $sourceFile['name'],
                'source_file_path' => $sourceFile['path'],
                'source_file_hash' => $sourceFile['hash'],
                'created_by' => $this->userid,
                'message' => '任务已提交，等待后台处理',
            ]);
            if ($createResult['created']) {
                $job = $createResult['job'];
                $this->logAudit('FIN_ASYNC_JOB', $job['job_id'], 'SUBMIT', null, [
                    'job_type' => $jobType,
                    'client_request_id' => $clientRequestId,
                    'period' => $period,
                    'source_file_name' => $sourceFile['name'],
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->removeAsyncSourceFile($sourceFile['path']);
            return $this->error('创建后台任务失败：' . $e->getMessage());
        }

        // 唯一键并发竞争时，当前请求写入的临时文件不属于最终任务，立即清理。
        if (!$createResult['created']) {
            $this->removeAsyncSourceFile($sourceFile['path']);
            if (($createResult['job']['created_by'] ?? '') !== $this->userid) {
                return $this->error('客户端请求ID已被其他用户使用，请重新提交');
            }
            try {
                $this->assertAsyncRequestMatchesExisting(
                    $createResult['job'],
                    $jobType,
                    $payload,
                    [],
                    $sourceFile['hash']
                );
            } catch (\Throwable $e) {
                return $this->error($e->getMessage());
            }
        }
        return $this->ok(
            $this->launchAndPublicAsyncJob($createResult['job'], $service),
            $createResult['created'] ? '后台任务已提交' : '已找到同一请求对应的后台任务'
        );
    }

    public function jobStatus($data = [])
    {
        $auth = $this->requirePermission('case_fund:view');
        if ($auth) {
            return $auth;
        }
        $jobId = trim((string)($data['job_id'] ?? ''));
        if ($jobId === '') {
            return $this->error('任务ID不能为空');
        }
        try {
            $service = $this->financeAsyncJobService();
            $job = $service->findById($jobId, $this->accountSetId);
        } catch (\Throwable $e) {
            return $this->error('查询后台任务失败：' . $e->getMessage());
        }
        if (!$job) {
            return $this->error('后台任务不存在', ['reason' => 'JOB_NOT_FOUND']);
        }
        if (!$this->canViewAsyncJob($job)) {
            return $this->error('无权查看该后台任务', ['reason' => 'JOB_FORBIDDEN']);
        }
        return $this->ok($service->publicJob($job));
    }

    public function jobList($data = [])
    {
        $auth = $this->requirePermission('case_fund:view');
        if ($auth) {
            return $auth;
        }
        $filters = [
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $this->currentYear(),
        ];
        foreach (['period', 'job_type', 'status', 'client_request_id'] as $field) {
            $value = trim((string)($data[$field] ?? ''));
            if ($value !== '') {
                $filters[$field] = $value;
            }
        }
        if (isset($filters['job_type']) && $this->asyncJobPermission($filters['job_type']) === '') {
            return $this->error('后台任务类型不正确');
        }
        if (isset($filters['status'])
            && !in_array($filters['status'], FinanceAsyncJobService::JOB_STATUSES, true)
        ) {
            return $this->error('后台任务状态不正确');
        }
        if (!$this->canViewAllAsyncJobs()) {
            $filters['created_by'] = $this->userid;
        }
        $page = max(1, (int)($data['page'] ?? 1));
        $pagesize = max(1, min(100, (int)($data['pagesize'] ?? 20)));
        try {
            $service = $this->financeAsyncJobService();
            $result = $service->listJobs($filters, $page, $pagesize);
            $result['items'] = array_map([$service, 'publicJob'], $result['items']);
        } catch (\Throwable $e) {
            return $this->error('查询后台任务列表失败：' . $e->getMessage());
        }
        return $this->ok($result, 'OK', $result['total']);
    }

    public function jobErrorList($data = [])
    {
        $auth = $this->requirePermission('case_fund:view');
        if ($auth) {
            return $auth;
        }
        $jobId = trim((string)($data['job_id'] ?? ''));
        if ($jobId === '') {
            return $this->error('任务ID不能为空');
        }
        $page = max(1, (int)($data['page'] ?? 1));
        $pagesize = max(1, min(200, (int)($data['pagesize'] ?? 100)));
        try {
            $service = $this->financeAsyncJobService();
            $job = $service->findById($jobId, $this->accountSetId);
            if (!$job) {
                return $this->error('后台任务不存在', ['reason' => 'JOB_NOT_FOUND']);
            }
            if (!$this->canViewAsyncJob($job)) {
                return $this->error('无权查看该后台任务', ['reason' => 'JOB_FORBIDDEN']);
            }
            $result = $service->listErrors($jobId, $this->accountSetId, $page, $pagesize);
        } catch (\Throwable $e) {
            return $this->error('查询任务错误明细失败：' . $e->getMessage());
        }
        return $this->ok($result, 'OK', $result['total']);
    }

    protected function asyncJobPermission($jobType)
    {
        if ($jobType === self::JOB_PAYMENT_IMPORT) {
            return 'case_fund:import';
        }
        if ($jobType === self::JOB_BANK_RECONCILE) {
            return 'case_fund:reconcile';
        }
        if (in_array($jobType, [self::JOB_PAYMENT_VOUCHER, self::JOB_REFUND_VOUCHER], true)) {
            return 'case_fund:generate_voucher';
        }
        return '';
    }

    protected function normalizeAsyncJobPeriod($period)
    {
        $period = trim((string)$period);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new \InvalidArgumentException('后台任务会计期间格式不正确');
        }
        if ((int)substr($period, 0, 4) !== $this->currentYear()) {
            throw new \InvalidArgumentException('后台任务会计期间与当前年度不一致');
        }
        return $period;
    }

    protected function sanitizeAsyncJobPayload($jobType, array $payload)
    {
        $clean = [
            'period' => $this->normalizeAsyncJobPeriod($payload['period'] ?? ''),
        ];
        if ($jobType === self::JOB_PAYMENT_IMPORT) {
            return $clean;
        }
        if ($jobType === self::JOB_BANK_RECONCILE) {
            $bankCode = strtoupper(trim((string)($payload['bank_code'] ?? '')));
            $banks = $this->supportedBankStatementBanks();
            if ($bankCode !== '' && !isset($banks[$bankCode])) {
                throw new \InvalidArgumentException('银行对账任务的开户银行不正确');
            }
            $dateStart = $this->normalizeAsyncJobDate($payload['date_start'] ?? '', '开始日期');
            $dateEnd = $this->normalizeAsyncJobDate($payload['date_end'] ?? '', '结束日期');
            if ($dateStart > $dateEnd) {
                throw new \InvalidArgumentException('银行对账任务的开始日期不能晚于结束日期');
            }
            $year = $this->currentYear();
            if ((int)substr($dateStart, 0, 4) !== $year || (int)substr($dateEnd, 0, 4) !== $year) {
                throw new \InvalidArgumentException('银行对账任务日期必须属于当前会计年度');
            }
            $clean['bank_code'] = $bankCode;
            $clean['date_start'] = $dateStart;
            $clean['date_end'] = $dateEnd;
            return $clean;
        }

        $scope = strtoupper(trim((string)($payload['generate_scope'] ?? '')));
        if (!in_array($scope, ['SELECTED', 'QUERY'], true)) {
            throw new \InvalidArgumentException('凭证生成范围必须为 SELECTED 或 QUERY');
        }
        $clean['generate_scope'] = $scope;
        if ($scope === 'SELECTED') {
            $idField = $jobType === self::JOB_PAYMENT_VOUCHER ? 'payment_ids' : 'refund_ids';
            $ids = $this->normalizeAsyncSourceIds($payload[$idField] ?? []);
            if (empty($ids)) {
                throw new \InvalidArgumentException('请选择要生成凭证的业务记录');
            }
            $clean[$idField] = $ids;
            return $clean;
        }

        $allowed = $jobType === self::JOB_PAYMENT_VOUCHER
            ? ['date_start', 'date_end', 'amount_min', 'amount_max', 'voucher_status', 'payment_method', 'keyword']
            : ['date_start', 'date_end', 'amount_min', 'amount_max', 'voucher_status', 'out_status', 'case_no', 'party_name', 'keyword'];
        foreach ($allowed as $field) {
            if ($field === 'amount_min' || $field === 'amount_max') {
                if (array_key_exists($field, $payload)) {
                    // 保留 JSON 解码后的原始数值类型，统一交给金额规范化处理；
                    // 不能在这里直接转字符串，否则生产 PHP 的 precision 会暴露浮点尾差。
                    $clean[$field] = $payload[$field];
                }
                continue;
            }
            $value = trim((string)($payload[$field] ?? ''));
            if ($value !== '') {
                $clean[$field] = substr($value, 0, $field === 'keyword' ? 200 : 100);
            }
        }
        $clean = $this->normalizeBusinessAmountRange($clean);
        $clean['voucher_status'] = 'UNGENERATED';
        return $clean;
    }

    protected function normalizeBusinessAmountRange(array $data)
    {
        $amountMin = $this->normalizeBusinessAmountBoundary(
            $data['amount_min'] ?? ($data['amountMin'] ?? ''),
            '最低金额'
        );
        $amountMax = $this->normalizeBusinessAmountBoundary(
            $data['amount_max'] ?? ($data['amountMax'] ?? ''),
            '最高金额'
        );
        unset($data['amountMin'], $data['amountMax']);
        if ($amountMin !== '') {
            $data['amount_min'] = $amountMin;
        } else {
            unset($data['amount_min']);
        }
        if ($amountMax !== '') {
            $data['amount_max'] = $amountMax;
        } else {
            unset($data['amount_max']);
        }
        if ($amountMin !== '' && $amountMax !== ''
            && $this->decimalToCents($amountMin) > $this->decimalToCents($amountMax)
        ) {
            throw new \InvalidArgumentException('最低金额不能大于最高金额');
        }
        return $data;
    }

    protected function normalizeBusinessAmountBoundary($value, $label)
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return '';
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new \InvalidArgumentException($label . '格式不正确，最多支持16位整数和2位小数');
        }
        // JSON 小数会被解码为 float。PHP 7.4 直接转字符串受 precision 和
        // LC_NUMERIC 影响，例如 precision=30 时 100.01 会暴露为长尾小数。
        // 大写 %F 固定使用点号且不受 precision 影响；字符串输入仍保留严格校验。
        $value = is_float($value) ? sprintf('%.2F', $value) : trim((string)$value);
        if (!preg_match('/^(\d{1,16})(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new \InvalidArgumentException($label . '格式不正确，最多支持16位整数和2位小数');
        }
        $yuan = ltrim($matches[1], '0');
        if ($yuan === '') {
            $yuan = '0';
        }
        $cent = str_pad($matches[2] ?? '', 2, '0', STR_PAD_RIGHT);
        return $yuan . '.' . $cent;
    }

    protected function normalizeAsyncJobDate($value, $label)
    {
        $value = trim((string)$value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)
            || !checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])
        ) {
            throw new \InvalidArgumentException('银行对账任务的' . $label . '格式不正确');
        }
        return $value;
    }

    protected function normalizeAsyncSourceIds($ids)
    {
        if (!is_array($ids)) {
            return [];
        }
        $clean = [];
        foreach ($ids as $id) {
            $id = trim((string)$id);
            if ($id === '' || strlen($id) > 36) {
                continue;
            }
            $clean[$id] = true;
            if (count($clean) > self::ASYNC_VOUCHER_MAX_ROWS) {
                throw new \InvalidArgumentException(
                    '单个后台任务最多处理' . self::ASYNC_VOUCHER_MAX_ROWS . '条所选记录，请拆分后提交'
                );
            }
        }
        $result = array_keys($clean);
        sort($result, SORT_STRING);
        return $result;
    }

    protected function assertAsyncRequestMatchesExisting(
        array $existing,
        $jobType,
        array $payload,
        array $requestData = [],
        $knownSourceHash = null
    ) {
        $existingPayload = is_array($existing['payload'] ?? null) ? $existing['payload'] : [];
        $existingSourceHash = strtolower(trim((string)($existing['source_file_hash'] ?? '')));
        $requestSourceHash = $knownSourceHash === null ? '' : strtolower(trim((string)$knownSourceHash));
        if ($jobType === self::JOB_PAYMENT_IMPORT && $requestSourceHash === '') {
            $content = (string)($requestData['content_base64'] ?? '');
            if ($content !== '') {
                $requestSourceHash = $this->hashAsyncImportRequestContent($content);
            } else {
                // 页面仅为恢复任务状态而重放时可以不再次上传文件；仍需比较期间等业务意图。
                $requestSourceHash = $existingSourceHash;
            }
        }

        $expected = $this->asyncRequestFingerprint($jobType, $payload, $requestSourceHash);
        $actual = trim((string)($existingPayload['_request_fingerprint'] ?? ''));
        if ($actual === '') {
            // 兼容补丁上线前已创建的任务，使用其持久化载荷重建指纹。
            $existingClean = $this->sanitizeAsyncJobPayload($jobType, $existingPayload);
            $actual = $this->asyncRequestFingerprint($jobType, $existingClean, $existingSourceHash);
        }
        if (!hash_equals($actual, $expected)) {
            throw new \RuntimeException('同一客户端请求ID已用于不同的文件或数据范围，请生成新的请求ID后再提交');
        }
    }

    protected function asyncRequestFingerprint($jobType, array $payload, $sourceFileHash = '')
    {
        unset(
            $payload['_request_fingerprint'],
            $payload['scope_snapshot_time'],
            $payload['operator_name']
        );
        if (isset($payload['payment_ids']) && is_array($payload['payment_ids'])) {
            sort($payload['payment_ids'], SORT_STRING);
        }
        if (isset($payload['refund_ids']) && is_array($payload['refund_ids'])) {
            sort($payload['refund_ids'], SORT_STRING);
        }
        ksort($payload, SORT_STRING);
        $intent = [
            'job_type' => trim((string)$jobType),
            'payload' => $payload,
        ];
        if ($jobType === self::JOB_PAYMENT_IMPORT) {
            $intent['source_file_hash'] = strtolower(trim((string)$sourceFileHash));
        }
        $json = json_encode($intent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('后台任务请求内容无法生成幂等校验指纹');
        }
        return hash('sha256', $json);
    }

    protected function hashAsyncImportRequestContent($contentBase64)
    {
        $contentBase64 = (string)$contentBase64;
        $this->assertAsyncImportEncodedSize($contentBase64);
        $binary = base64_decode($contentBase64, true);
        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('导入文件内容不正确');
        }
        if (strlen($binary) > self::ASYNC_IMPORT_MAX_BYTES) {
            throw new \InvalidArgumentException('导入文件不能超过10MB，请拆分后导入');
        }
        return hash('sha256', $binary);
    }

    protected function assertAsyncImportEncodedSize($contentBase64)
    {
        // Base64 长度可在解码前确定上界，必须先拒绝超限请求，不能先额外分配
        // 一份数十 MB 的二进制后再检查。
        $maxEncodedBytes = (int)(ceil(self::ASYNC_IMPORT_MAX_BYTES / 3) * 4);
        if (strlen((string)$contentBase64) > $maxEncodedBytes) {
            throw new \InvalidArgumentException('导入文件不能超过10MB，请拆分后导入');
        }
    }

    protected function saveAsyncImportFile(array $data)
    {
        $filename = str_replace('\\', '/', trim((string)($data['filename'] ?? '')));
        $filename = basename($filename);
        if ($filename === '' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'xls') {
            throw new \InvalidArgumentException('请选择 .xls 格式的缴费登记文件');
        }
        $content = (string)($data['content_base64'] ?? '');
        if ($content === '') {
            throw new \InvalidArgumentException('导入文件不能为空');
        }
        $this->assertAsyncImportEncodedSize($content);
        $binary = base64_decode($content, true);
        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('导入文件内容不正确');
        }
        if (strlen($binary) > self::ASYNC_IMPORT_MAX_BYTES) {
            throw new \InvalidArgumentException('导入文件不能超过10MB，请拆分后导入');
        }
        $runtimeDir = dirname(__DIR__, 3) . '/runtime/finance_async_jobs/imports';
        if (!is_dir($runtimeDir) && !@mkdir($runtimeDir, 0770, true) && !is_dir($runtimeDir)) {
            throw new \RuntimeException('后台任务文件目录不可写，请检查 backend/runtime 权限');
        }
        $path = $runtimeDir . '/payment-' . uuid() . '.xls';
        $written = @file_put_contents($path, $binary, LOCK_EX);
        if ($written === false || $written !== strlen($binary)) {
            $this->removeAsyncSourceFile($path);
            throw new \RuntimeException('导入文件暂存失败，请检查服务器磁盘空间和目录权限');
        }
        @chmod($path, 0660);
        return [
            'name' => substr($filename, 0, 255),
            'path' => $path,
            'hash' => hash('sha256', $binary),
        ];
    }

    protected function removeAsyncSourceFile($path)
    {
        $path = trim((string)$path);
        if ($path === '' || !is_file($path)) {
            return;
        }
        $runtimeRoot = realpath(dirname(__DIR__, 3) . '/runtime/finance_async_jobs/imports');
        $realPath = realpath($path);
        if ($runtimeRoot && $realPath
            && strpos($realPath, $runtimeRoot . DIRECTORY_SEPARATOR) === 0
        ) {
            @unlink($realPath);
        }
    }

    protected function launchAndPublicAsyncJob(array $job, FinanceAsyncJobService $service)
    {
        $public = $service->publicJob($job);
        if (!in_array($job['status'] ?? '', FinanceAsyncJobService::TERMINAL_STATUSES, true)) {
            // 同机只有一个 worker 锁；必须让持锁进程排空全部财务任务类型，
            // 否则另一类型的 launcher 因 flock 退出后会留下永久 QUEUED 任务。
            $launchResult = (new FinanceAsyncJobLauncher())->launch();
            if (empty($launchResult['ok'])) {
                $warning = trim((string)($launchResult['launcher_warning'] ?? '后台工作进程未启动'));
                $message = $warning . '；任务已安全保留在队列中，请联系管理员检查计划任务';
                try {
                    $job = $service->noteQueuedLaunchWarning($job['job_id'], $message);
                    $public = $service->publicJob($job);
                } catch (\Throwable $e) {
                    // 启动告警持久化失败不改变已成功入队的任务。
                }
                $public['launcher_warning'] = $warning;
                $public['message'] = $message;
            }
        }
        return $public;
    }

    protected function canViewAllAsyncJobs()
    {
        return $this->hasPermission('*') || $this->currentViewScope() === 'ALL';
    }

    protected function canViewAsyncJob(array $job)
    {
        return ($job['created_by'] ?? '') === $this->userid || $this->canViewAllAsyncJobs();
    }

    /**
     * CLI worker 唯一业务入口。该方法不通过 HTTP 路由暴露；worker 会注入
     * 任务进度与心跳回调，并使用提交人的权限、账套和年度上下文执行。
     */
    public function processAsyncJob(array $job)
    {
        $jobType = trim((string)($job['job_type'] ?? ''));
        $cleanupImportFile = $jobType === self::JOB_PAYMENT_IMPORT;
        try {
            if (($job['account_set_id'] ?? '') !== $this->accountSetId
                || (int)($job['fiscal_year'] ?? 0) !== $this->currentYear()
            ) {
                throw new \RuntimeException('后台任务账套或年度上下文不一致');
            }
            $permission = $this->asyncJobPermission($jobType);
            $this->assertAsyncWorkerUserAccess();
            if ($permission === '' || !$this->hasPermission($permission)) {
                throw new \RuntimeException('任务提交人已无权执行当前后台任务：' . ($permission ?: $jobType));
            }
            if ($jobType === self::JOB_PAYMENT_IMPORT) {
                return $this->processAsyncPaymentImport($job);
            }
            if ($jobType === self::JOB_PAYMENT_VOUCHER) {
                return $this->processAsyncVoucherGeneration($job, 'PAYMENT');
            }
            if ($jobType === self::JOB_REFUND_VOUCHER) {
                return $this->processAsyncVoucherGeneration($job, 'REFUND');
            }
            if ($jobType === self::JOB_BANK_RECONCILE) {
                return $this->processAsyncBankReconcile($job);
            }
            throw new \RuntimeException('后台任务类型未注册：' . $jobType);
        } catch (\Throwable $e) {
            // worker 执行权失效表示可能已有新 worker 接管，此时必须保留源文件供接管者继续处理。
            $ownershipLost = (strpos($e->getMessage(), '任务执行权') !== false
                    && strpos($e->getMessage(), '失效') !== false)
                || strpos($e->getMessage(), '禁止继续更新') !== false
                || strpos($e->getMessage(), '异步任务状态已变化') !== false;
            $alreadyCompleted = false;
            try {
                $currentJob = $this->financeAsyncJobService()->findById(
                    $job['job_id'] ?? '',
                    $job['account_set_id'] ?? ''
                );
                if ($currentJob
                    && !empty($currentJob['worker_token'])
                    && ($currentJob['worker_token'] ?? '') !== ($job['worker_token'] ?? '')
                ) {
                    $ownershipLost = true;
                }
                if ($currentJob
                    && ($currentJob['worker_token'] ?? '') === ($job['worker_token'] ?? '')
                    && in_array($currentJob['status'] ?? '', FinanceAsyncJobService::COMPLETION_STATUSES, true)
                ) {
                    // 银行对账可在正式业务事务内原子写入成功终态。提交后的异常
                    // 由 worker 读取 durable 终态收口，不能再追加失败明细。
                    $alreadyCompleted = true;
                }
            } catch (\Throwable $stateError) {
                // 任务表暂时不可读时仍按原始异常判断，不能覆盖业务错误。
            }
            if ($ownershipLost) {
                $cleanupImportFile = false;
            } elseif (!$alreadyCompleted) {
                $this->ensureAsyncFailureRecorded($job, $e->getMessage());
            }
            throw $e;
        } finally {
            if ($cleanupImportFile) {
                $this->removeAsyncSourceFile($job['source_file_path'] ?? '');
            }
        }
    }

    protected function assertAsyncWorkerUserAccess()
    {
        $user = $this->currentUser();
        if (!$user || (int)($user['status'] ?? 0) !== 1 || (int)($user['del_flag'] ?? 0) !== 0) {
            throw new \RuntimeException('任务提交人已被禁用或删除，后台任务拒绝执行');
        }
        foreach ($this->userAccountSets($this->userid) as $accountSet) {
            if (($accountSet['account_set_id'] ?? '') === $this->accountSetId) {
                return;
            }
        }
        throw new \RuntimeException('任务提交人已无当前账套访问权，后台任务拒绝执行');
    }

    protected function processAsyncBankReconcile(array $job)
    {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $this->asyncProgress($job, [
            'replace_counts' => true,
            'stage' => 'MATCHING',
            'total_count' => 0,
            'processed_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'progress_percent' => 5,
            'message' => '正在重算当前账套所选日期范围内的银行对账结果',
        ]);

        $result = $this->executeBankReconcile($payload, $job);
        $total = (int)($result['total'] ?? 0);
        $completionResult = $this->bankReconcileAsyncCompletionResult(
            $total,
            $result['counts'] ?? $this->emptyBankReconcileCounts()
        );
        $completionResult[FinanceAsyncJobService::ATOMIC_COMPLETION_KEY] =
            $result[FinanceAsyncJobService::ATOMIC_COMPLETION_KEY] ?? '';
        return $completionResult;
    }

    protected function bankReconcileAsyncCompletionResult($total, array $counts)
    {
        $total = max(0, (int)$total);
        return [
            'code' => self::CODE_SUCCESS,
            'message' => '自动对账完成',
            'total' => $total,
            'processed_count' => $total,
            'success_count' => $total,
            'failure_count' => 0,
            'counts' => $counts,
        ];
    }

    protected function processAsyncPaymentImport(array $job)
    {
        $this->asyncHeartbeat($job, 'PARSING', '正在读取并解析缴费登记文件');
        $path = $this->validatedAsyncImportPath($job['source_file_path'] ?? '');
        $fileSize = @filesize($path);
        if ($fileSize === false || $fileSize > self::ASYNC_IMPORT_MAX_BYTES) {
            throw new \RuntimeException('后台任务导入文件超过10MB安全上限，请拆分后重新导入');
        }
        $binary = @file_get_contents($path);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('后台任务导入文件不存在或无法读取');
        }
        $expectedHash = strtolower(trim((string)($job['source_file_hash'] ?? '')));
        if ($expectedHash !== '' && !hash_equals($expectedHash, hash('sha256', $binary))) {
            throw new \RuntimeException('后台任务导入文件校验失败，文件内容可能已被修改');
        }
        try {
            $rows = $this->parsePaymentImportRowsFromXls($binary);
        } catch (\Throwable $e) {
            throw new \RuntimeException('解析案款缴费登记失败：' . $e->getMessage());
        }
        unset($binary);
        if (empty($rows)) {
            throw new \RuntimeException('导入文件没有案款缴费数据');
        }

        $total = count($rows);
        $this->asyncProgress($job, [
            'replace_counts' => true,
            'stage' => 'VALIDATING',
            'total_count' => $total,
            'processed_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'message' => '正在校验 ' . $total . ' 条缴费登记',
        ]);
        $bizType = $this->currentAccountSetBizType();
        $receiptNoDuplicateCheckRequired = $this->receiptTracePolicy()
            ->paymentImportRequiresReceiptNoDuplicateCheck($bizType);
        $errors = $this->validatePaymentRows($rows, $bizType, $receiptNoDuplicateCheckRequired);
        $errorsByRow = $this->groupAsyncImportErrors($errors);
        $this->stageAsyncPaymentImportRows($job, $rows, $errorsByRow);

        if (!empty($errors)) {
            $this->addAsyncImportErrorItems($job, $errorsByRow);
            $this->getdb(self::TABLE_IMPORT_STAGE)
                ->where(['job_id' => $job['job_id'], 'del_flag' => 0])
                ->update([
                    'persistence_status' => 'BLOCKED',
                    'updated_by' => $this->userid,
                    'updated_time' => $this->now(),
                ]);
            $this->asyncProgress($job, [
                'stage' => 'FINALIZING',
                'total_count' => $total,
                'processed_count' => $total,
                'success_count' => 0,
                'failure_count' => max(1, count($errorsByRow)),
                'message' => '导入校验未通过，正式业务数据未写入',
            ]);
            throw new \RuntimeException($this->paymentImportErrorMessage($errors, $bizType));
        }

        $this->asyncProgress($job, [
            'stage' => 'WRITING',
            'total_count' => $total,
            'processed_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'message' => '校验通过，正在批量写入正式缴费登记',
        ]);
        $existing = $this->loadExistingPaymentsForImport($rows, $receiptNoDuplicateCheckRequired);
        $persistenceData = [
            'filename' => $job['source_file_name'] ?? '',
            'use_receipt_no_as_duplicate_key' => $receiptNoDuplicateCheckRequired,
        ];
        Db::startTrans();
        try {
            $this->financeAsyncJobService()->fenceBusinessTransaction(
                $job['job_id'],
                $job['worker_token'] ?? '',
                'WRITING',
                '正在事务内写入正式缴费登记'
            );
            $result = $this->importPersistence()->persistPaymentRows($rows, $existing, $persistenceData);
            $this->getdb(self::TABLE_IMPORT_STAGE)
                ->where(['job_id' => $job['job_id'], 'del_flag' => 0])
                ->update([
                    'persistence_status' => 'SUCCEEDED',
                    'updated_by' => $this->userid,
                    'updated_time' => $this->now(),
                ]);
            $this->logAudit('CASE_FUND_PAYMENT', 'IMPORT', 'IMPORT_ASYNC', null, [
                'job_id' => $job['job_id'],
                'filename' => $job['source_file_name'] ?? '',
                'total' => $total,
                'created' => $result['created'],
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->getdb(self::TABLE_IMPORT_STAGE)
                ->where(['job_id' => $job['job_id'], 'del_flag' => 0])
                ->update([
                    'persistence_status' => 'FAILED',
                    'error_code' => 'PERSISTENCE_ERROR',
                    'error_message' => substr($e->getMessage(), 0, 2000),
                    'updated_by' => $this->userid,
                    'updated_time' => $this->now(),
                ]);
            $this->addAsyncGeneralErrorItem($job, 'PAYMENT_IMPORT', 'PERSISTENCE_ERROR', $e->getMessage());
            throw new \RuntimeException('保存案款缴费登记失败：' . $e->getMessage());
        }

        $this->asyncProgress($job, [
            'stage' => 'FINALIZING',
            'total_count' => $total,
            'processed_count' => $total,
            'success_count' => $total,
            'failure_count' => 0,
            'message' => '缴费登记导入完成，正在确认结果',
        ]);
        return [
            'code' => self::CODE_SUCCESS,
            'message' => '缴费登记导入完成',
            'total' => $total,
            'created' => (int)$result['created'],
            'updated' => (int)$result['updated'],
            'skipped' => (int)$result['skipped'],
        ];
    }

    protected function processAsyncVoucherGeneration(array $job, $recordType)
    {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $scope = strtoupper(trim((string)($payload['generate_scope'] ?? '')));
        if (!in_array($scope, ['SELECTED', 'QUERY'], true)) {
            throw new \RuntimeException('后台制证任务的生成范围不正确');
        }
        $isPayment = $recordType === 'PAYMENT';
        $idField = $isPayment ? 'payment_id' : 'refund_id';
        $sourceType = $isPayment ? 'CASE_FUND_PAYMENT' : 'CASE_FUND_REFUND';
        $label = $isPayment ? '缴费' : '退付';
        $service = $isPayment
            ? $this->paymentVoucherGenerationService()
            : $this->refundVoucherGenerationService();

        $this->asyncHeartbeat($job, 'GENERATING', '正在冻结本次' . $label . '制证范围');
        $existingItems = $this->allAsyncJobItems($job['job_id'], $sourceType);
        $frozenIds = [];
        foreach ($existingItems as $item) {
            if (!empty($item['source_id'])) {
                $frozenIds[] = $item['source_id'];
            }
        }
        $freezeComplete = !empty($frozenIds)
            && (int)($job['total_count'] ?? 0) > 0
            && count($frozenIds) === (int)$job['total_count'];
        if ($freezeComplete) {
            $selection = $isPayment
                ? $service->selectedRows($frozenIds)
                : $service->selectedRows($frozenIds, false);
        } elseif ($scope === 'SELECTED') {
            $selection = $service->selectedRows($payload[$isPayment ? 'payment_ids' : 'refund_ids'] ?? []);
        } else {
            $selection = $service->rowsByQuery($this->asyncVoucherBusinessQueryPayload($payload));
        }
        if (!empty($selection['error'])) {
            $this->addAsyncGeneralErrorItem($job, $sourceType, 'SELECTION_ERROR', $selection['error']);
            throw new \RuntimeException($selection['error']);
        }
        $rows = $selection['rows'] ?? [];
        if (!$freezeComplete && !empty($frozenIds)) {
            // 上一 worker 可能在分批冻结过程中异常退出。已有明细属于本次范围，
            // 与同一提交快照重新查询的结果合并后再幂等补齐，不能只处理半批数据。
            $frozenSelection = $isPayment
                ? $service->selectedRows($frozenIds)
                : $service->selectedRows($frozenIds, false);
            if (!empty($frozenSelection['error'])) {
                throw new \RuntimeException('恢复未完成的任务范围失败：' . $frozenSelection['error']);
            }
            $rowsById = [];
            foreach (array_merge($rows, $frozenSelection['rows'] ?? []) as $row) {
                $sourceId = trim((string)($row[$idField] ?? ''));
                if ($sourceId !== '') {
                    $rowsById[$sourceId] = $row;
                }
            }
            $rows = array_values($rowsById);
            $dateField = $isPayment ? 'posting_date' : 'refund_date';
            usort($rows, function ($left, $right) use ($dateField, $idField) {
                foreach ([$dateField, 'source_row_no', $idField] as $field) {
                    $leftValue = $left[$field] ?? ($field === 'posting_date' ? ($left['payment_date'] ?? '') : '');
                    $rightValue = $right[$field] ?? ($field === 'posting_date' ? ($right['payment_date'] ?? '') : '');
                    $comparison = $leftValue <=> $rightValue;
                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }
                return 0;
            });
        }
        if (count($rows) > self::ASYNC_VOUCHER_MAX_ROWS) {
            $message = '单个后台制证任务最多处理' . self::ASYNC_VOUCHER_MAX_ROWS
                . '条记录，请缩小查询范围后重新提交';
            $this->addAsyncGeneralErrorItem($job, $sourceType, 'SCOPE_TOO_LARGE', $message);
            throw new \RuntimeException($message);
        }
        if (empty($rows)) {
            $message = '当前任务范围内没有未制证' . $label . '记录';
            $this->addAsyncGeneralErrorItem($job, $sourceType, 'EMPTY_SCOPE', $message);
            throw new \RuntimeException($message);
        }

        $itemMap = [];
        $itemNo = 0;
        foreach ($existingItems as $item) {
            $itemMap[$item['source_id']] = $item;
            $itemNo = max($itemNo, (int)($item['item_no'] ?? 0));
        }
        $newItems = [];
        foreach ($rows as $row) {
            $sourceId = trim((string)($row[$idField] ?? ''));
            if ($sourceId === '') {
                throw new \RuntimeException($label . '记录缺少业务主键，无法冻结任务范围');
            }
            if (!isset($itemMap[$sourceId])) {
                $itemNo++;
                $newItems[] = [
                    'item_no' => $itemNo,
                    'source_row_no' => $row['source_row_no'] ?? null,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'amount' => $row[$isPayment ? 'payment_amount' : 'refund_amount'] ?? 0,
                    'status' => 'QUEUED',
                    'created_by' => $this->userid,
                ];
                if (count($newItems) >= 500) {
                    $this->financeAsyncJobService()->batchAddItems(
                        $newItems,
                        $job['job_id'],
                        $this->accountSetId,
                        $job['worker_token'] ?? '',
                        500
                    );
                    $newItems = [];
                }
            }
        }
        if (!empty($newItems)) {
            $this->financeAsyncJobService()->batchAddItems(
                $newItems,
                $job['job_id'],
                $this->accountSetId,
                $job['worker_token'] ?? '',
                500
            );
        }
        unset($existingItems, $frozenIds, $newItems, $itemMap);
        $existingItems = $this->allAsyncJobItems($job['job_id'], $sourceType);
        $itemMap = [];
        foreach ($existingItems as $item) {
            $itemMap[$item['source_id']] = $item;
        }
        unset($existingItems);
        foreach ($rows as $row) {
            $sourceId = trim((string)($row[$idField] ?? ''));
            if (!isset($itemMap[$sourceId])) {
                throw new \RuntimeException('任务范围冻结不完整，请按原任务重新恢复');
            }
        }

        $pendingRows = [];
        $successRows = 0;
        $failureRows = 0;
        $voucherMap = [];
        foreach ($rows as $row) {
            $sourceId = trim((string)($row[$idField] ?? ''));
            $item = $itemMap[$sourceId] ?? null;
            if ($item && ($item['status'] ?? '') === 'SUCCEEDED') {
                $successRows++;
                if (!empty($item['voucher_id'])) {
                    $voucherMap[$item['voucher_id']] = [
                        'voucher_id' => $item['voucher_id'],
                        'voucher_no' => (int)($item['voucher_no'] ?? 0),
                        'period' => $item['voucher_period'] ?? '',
                    ];
                }
                continue;
            }
            $pendingRows[] = $row;
        }
        $total = count($rows);
        unset($rows);
        $this->asyncProgress($job, [
            'replace_counts' => true,
            'stage' => 'GENERATING',
            'total_count' => $total,
            'processed_count' => $successRows,
            'success_count' => $successRows,
            'failure_count' => 0,
            'message' => '已冻结 ' . $total . ' 条' . $label . '记录，开始逐张生成凭证',
        ]);

        $groups = [];
        if (!empty($pendingRows)) {
            try {
                if ($isPayment) {
                    $groups = $this->voucherRule()->groupPaymentRowsForVoucher(
                        $pendingRows,
                        $this->subjectConfigManager()->subjectConfigAccountSetFlag(),
                        $this->subjectConfigManager()->generatePaymentVoucherByMethodFlag()
                    );
                } else {
                    $groups = $this->voucherRule()->groupRefundRowsForVoucher(
                        $pendingRows,
                        $this->subjectConfigManager()->subjectConfigAccountSetFlag(),
                        $this->subjectConfigManager()->generateRefundVoucherByOutOrderNoFlag()
                    );
                }
            } catch (\Throwable $e) {
                $failedSourceIds = array_map(function ($row) use ($idField) {
                    return (string)$row[$idField];
                }, $pendingRows);
                $this->batchUpdateAsyncVoucherItemMap($job, $itemMap, $failedSourceIds, [
                    'status' => 'FAILED',
                    'error_code' => 'GROUP_ERROR',
                    'error_message' => $e->getMessage(),
                    'finished_time' => $this->now(),
                ]);
                $failureRows = count($pendingRows);
                $this->asyncProgress($job, [
                    'stage' => 'FINALIZING',
                    'total_count' => $total,
                    'processed_count' => $total,
                    'success_count' => $successRows,
                    'failure_count' => $failureRows,
                    'message' => $label . '记录分组失败',
                ]);
                throw new \RuntimeException($label . '记录分组失败：' . $e->getMessage());
            }
        }
        unset($pendingRows);

        $groupNo = 0;
        while (!empty($groups)) {
            $groupIndex = array_key_first($groups);
            $groupRows = $groups[$groupIndex];
            unset($groups[$groupIndex]);
            $groupNo++;
            $groupKey = 'GROUP-' . str_pad((string)$groupNo, 6, '0', STR_PAD_LEFT);
            $transactionStarted = false;
            $groupSourceIds = array_map(function ($row) use ($idField) {
                return (string)$row[$idField];
            }, $groupRows);
            try {
                $this->batchUpdateAsyncVoucherItemMap($job, $itemMap, $groupSourceIds, [
                    'status' => 'RUNNING',
                    'group_key' => $groupKey,
                    'error_code' => null,
                    'error_message' => null,
                    'started_time' => $this->now(),
                ]);

                $prepared = $service->prepareGeneration($groupRows, $scope);
                if (!empty($prepared['error'])) {
                    throw new \RuntimeException($prepared['error']);
                }
                Db::startTrans();
                $transactionStarted = true;
                $this->financeAsyncJobService()->fenceBusinessTransaction(
                    $job['job_id'],
                    $job['worker_token'] ?? '',
                    'GENERATING',
                    '正在事务内生成第' . $groupNo . '张凭证'
                );
                $generated = $service->generatePrepared($prepared);
                if ((int)($generated['generated_count'] ?? 0) !== 1
                    || count($generated['vouchers'] ?? []) !== 1
                ) {
                    throw new \RuntimeException('制证参数在任务执行期间发生变化，请重新提交任务');
                }
                $voucher = $generated['vouchers'][0] ?? null;
                if (!$voucher || empty($voucher['voucher_id'])) {
                    throw new \RuntimeException('凭证已生成但未返回可追溯的凭证信息');
                }
                $this->logAudit(
                    $isPayment ? 'CASE_FUND_PAYMENT' : 'CASE_FUND_REFUND',
                    'VOUCHER_GENERATE',
                    'GENERATE_ASYNC',
                    null,
                    [
                        'job_id' => $job['job_id'],
                        'group_key' => $groupKey,
                        'generate_scope' => $scope,
                        'source_count' => count($groupRows),
                        'vouchers' => $generated['vouchers'],
                    ]
                );
                $this->batchUpdateAsyncVoucherItemMap($job, $itemMap, $groupSourceIds, [
                    'status' => 'SUCCEEDED',
                    'result' => ['voucher' => $voucher],
                    'voucher_id' => $voucher['voucher_id'],
                    'voucher_no' => $voucher['voucher_no'] ?? null,
                    'voucher_period' => $voucher['period'] ?? null,
                    'finished_time' => $this->now(),
                ]);
                Db::commit();
                $transactionStarted = false;

                $successRows += count($groupRows);
                $voucherMap[$voucher['voucher_id']] = $voucher;
            } catch (\Throwable $e) {
                if ($transactionStarted) {
                    Db::rollback();
                }
                $this->batchUpdateAsyncVoucherItemMap($job, $itemMap, $groupSourceIds, [
                    'status' => 'FAILED',
                    'error_code' => 'VOUCHER_GENERATION_ERROR',
                    'error_message' => $e->getMessage(),
                    'finished_time' => $this->now(),
                ]);
                $failureRows += count($groupRows);
            }

            $processed = $successRows + $failureRows;
            $this->asyncProgress($job, [
                'stage' => 'GENERATING',
                'total_count' => $total,
                'processed_count' => $processed,
                'success_count' => $successRows,
                'failure_count' => $failureRows,
                'message' => '已处理 ' . $processed . '/' . $total . ' 条' . $label . '记录',
            ]);
        }

        $vouchers = array_values($voucherMap);
        if ($successRows === 0 && $failureRows > 0) {
            throw new \RuntimeException('全部' . $label . '记录生成凭证失败，请查看任务错误明细');
        }
        $status = $failureRows > 0 ? 'PARTIAL_FAILED' : 'SUCCEEDED';
        $this->asyncProgress($job, [
            'stage' => 'FINALIZING',
            'total_count' => $total,
            'processed_count' => $total,
            'success_count' => $successRows,
            'failure_count' => $failureRows,
            'message' => $failureRows > 0 ? '凭证生成完成，但部分记录处理失败' : '凭证生成完成，正在确认结果',
        ]);
        return [
            'code' => self::CODE_SUCCESS,
            '_job_status' => $status,
            'message' => $failureRows > 0
                ? '成功处理' . $successRows . '条，失败' . $failureRows . '条'
                : '凭证生成完成',
            'error_message' => $failureRows > 0 ? '有' . $failureRows . '条记录生成凭证失败' : '',
            'generated_count' => count($vouchers),
            $isPayment ? 'payment_count' : 'refund_count' => $total,
            'success_count' => $successRows,
            'failure_count' => $failureRows,
            'vouchers' => $vouchers,
        ];
    }

    /**
     * 异步任务头 period 表示提交任务时的会计上下文，用于账套年度校验和任务归档；
     * QUERY 制证的数据范围必须严格跟随页面日期等显式查询条件，不能再被任务头
     * 的当前月份暗中收窄。否则“全年/跨月查询”会只处理当前月，后续月份也会被
     * 同一个隐藏 period 条件误报为“没有未制证数据”。
     */
    protected function asyncVoucherBusinessQueryPayload(array $payload)
    {
        unset($payload['period']);
        return $payload;
    }

    protected function batchUpdateAsyncVoucherItemMap(
        array $job,
        array &$itemMap,
        array $sourceIds,
        array $data
    )
    {
        $sourceIds = array_values(array_unique(array_filter(array_map('strval', $sourceIds))));
        $itemIds = [];
        foreach ($sourceIds as $sourceId) {
            if (!isset($itemMap[$sourceId]['item_id'])) {
                throw new \RuntimeException('任务明细映射缺失：' . $sourceId);
            }
            $itemIds[] = $itemMap[$sourceId]['item_id'];
        }
        foreach (array_chunk($itemIds, 500) as $idChunk) {
            $this->financeAsyncJobService()->batchUpdateItems(
                $idChunk,
                $job['job_id'],
                $this->accountSetId,
                $job['worker_token'] ?? '',
                $data
            );
        }
        foreach ($sourceIds as $sourceId) {
            foreach ($data as $field => $value) {
                if ($field === 'result') {
                    $itemMap[$sourceId]['result'] = $value;
                } else {
                    $itemMap[$sourceId][$field] = $value;
                }
            }
            if (($data['status'] ?? '') === 'SUCCEEDED') {
                $itemMap[$sourceId]['error_code'] = null;
                $itemMap[$sourceId]['error_message'] = null;
            }
        }
    }

    protected function allAsyncJobItems($jobId, $sourceType = '')
    {
        return $this->financeAsyncJobService()->allItems(
            $jobId,
            $this->accountSetId,
            $sourceType
        );
    }

    protected function asyncProgress(array $job, array $progress)
    {
        if (!isset($job['_progress_callback']) || !is_callable($job['_progress_callback'])) {
            throw new \RuntimeException('后台任务缺少进度回调');
        }
        return call_user_func($job['_progress_callback'], $progress);
    }

    protected function asyncHeartbeat(array $job, $stage, $message)
    {
        if (!isset($job['_heartbeat_callback']) || !is_callable($job['_heartbeat_callback'])) {
            throw new \RuntimeException('后台任务缺少心跳回调');
        }
        return call_user_func($job['_heartbeat_callback'], $stage, $message);
    }

    protected function validatedAsyncImportPath($path)
    {
        $path = trim((string)$path);
        $runtimeRoot = realpath(dirname(__DIR__, 3) . '/runtime/finance_async_jobs/imports');
        $realPath = $path === '' ? false : realpath($path);
        if (!$runtimeRoot || !$realPath
            || strpos($realPath, $runtimeRoot . DIRECTORY_SEPARATOR) !== 0
            || !is_file($realPath)
        ) {
            throw new \RuntimeException('后台任务导入文件路径不安全或文件不存在');
        }
        return $realPath;
    }

    protected function groupAsyncImportErrors(array $errors)
    {
        $grouped = [];
        foreach ($errors as $error) {
            $message = trim((string)$error);
            if ($message === '') {
                continue;
            }
            $rowNo = 0;
            if (preg_match('/^第(\d+)行[：:]/u', $message, $matches)) {
                $rowNo = (int)$matches[1];
            }
            $grouped[$rowNo][] = $message;
        }
        return $grouped;
    }

    protected function stageAsyncPaymentImportRows(array $job, array $rows, array $errorsByRow)
    {
        $existingRowNos = $this->getdb(self::TABLE_IMPORT_STAGE)
            ->where(['job_id' => $job['job_id'], 'del_flag' => 0])
            ->column('source_row_no');
        $existingMap = array_fill_keys(array_map('intval', $existingRowNos ?: []), true);
        $now = $this->now();
        $inserts = [];
        foreach ($rows as $row) {
            $rowNo = (int)($row['source_row_no'] ?? 0);
            if ($rowNo <= 0) {
                throw new \RuntimeException('导入暂存行缺少有效来源行号');
            }
            if (isset($existingMap[$rowNo])) {
                continue;
            }
            $normalized = $row;
            $rawJson = trim((string)($normalized['source_raw_json'] ?? ''));
            unset($normalized['source_raw_json']);
            $rowErrors = $errorsByRow[$rowNo] ?? [];
            $rowYear = (int)($row['fiscal_year'] ?? 0);
            $rowYear = $rowYear > 0 ? $rowYear : $this->currentYear();
            $rowPeriod = trim((string)($row['period'] ?? ''));
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $rowPeriod)) {
                $rowPeriod = $job['period'];
            }
            $inserts[] = [
                'stage_id' => uuid(),
                'job_id' => $job['job_id'],
                'account_set_id' => $this->accountSetId,
                'fiscal_year' => $rowYear,
                'period' => $rowPeriod,
                'source_row_no' => $rowNo,
                'source_fingerprint' => substr((string)($row['source_fingerprint'] ?? ''), 0, 64),
                'raw_json' => $rawJson !== '' ? $rawJson : json_encode($row, JSON_UNESCAPED_UNICODE),
                'normalized_json' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
                'validation_status' => empty($rowErrors) ? 'VALIDATED' : 'FAILED',
                'validation_errors_json' => empty($rowErrors)
                    ? null
                    : json_encode($rowErrors, JSON_UNESCAPED_UNICODE),
                'persistence_status' => 'PENDING',
                'target_type' => 'PAYMENT',
                'target_id' => null,
                'error_code' => empty($rowErrors) ? null : 'VALIDATION_ERROR',
                'error_message' => empty($rowErrors) ? null : substr(implode('；', $rowErrors), 0, 2000),
                'created_by' => $this->userid,
                'created_time' => $now,
                'updated_by' => $this->userid,
                'updated_time' => $now,
                'del_flag' => 0,
                'version' => 0,
                'remark' => null,
            ];
            if (count($inserts) >= 200) {
                $this->insertAsyncImportStageBatch($inserts);
                $inserts = [];
            }
        }
        $this->insertAsyncImportStageBatch($inserts);
    }

    protected function insertAsyncImportStageBatch(array $batch)
    {
        if (empty($batch)) {
            return;
        }
        $affectedRows = $this->getdb(self::TABLE_IMPORT_STAGE)->insertAll($batch);
        if ((int)$affectedRows !== count($batch)) {
            throw new \RuntimeException('缴费导入暂存数据批量写入失败');
        }
    }

    protected function addAsyncImportErrorItems(array $job, array $errorsByRow)
    {
        $itemNo = 0;
        $items = [];
        foreach ($errorsByRow as $rowNo => $messages) {
            $itemNo++;
            $items[] = [
                'item_no' => $itemNo,
                'source_row_no' => $rowNo > 0 ? $rowNo : null,
                'source_type' => 'PAYMENT_IMPORT_ROW',
                'source_id' => $rowNo > 0 ? 'ROW-' . $rowNo : 'GENERAL',
                'amount' => 0,
                'status' => 'FAILED',
                'error_code' => 'VALIDATION_ERROR',
                'error_message' => implode('；', $messages),
                'finished_time' => $this->now(),
                'created_by' => $this->userid,
            ];
            if (count($items) >= 500) {
                $this->financeAsyncJobService()->batchAddItems(
                    $items,
                    $job['job_id'],
                    $this->accountSetId,
                    $job['worker_token'] ?? '',
                    500
                );
                $items = [];
            }
        }
        if (!empty($items)) {
            $this->financeAsyncJobService()->batchAddItems(
                $items,
                $job['job_id'],
                $this->accountSetId,
                $job['worker_token'] ?? '',
                500
            );
        }
    }

    protected function addAsyncGeneralErrorItem(array $job, $sourceType, $errorCode, $message)
    {
        return $this->financeAsyncJobService()->addItem([
            'job_id' => $job['job_id'],
            'account_set_id' => $this->accountSetId,
            'item_no' => 1,
            'source_type' => substr((string)$sourceType, 0, 50),
            'source_id' => 'GENERAL',
            'amount' => 0,
            'status' => 'FAILED',
            'error_code' => substr((string)$errorCode, 0, 100),
            'error_message' => trim((string)$message) ?: '后台任务处理失败',
            'finished_time' => $this->now(),
            'created_by' => $this->userid,
        ]);
    }

    protected function ensureAsyncFailureRecorded(array $job, $message)
    {
        try {
            $service = $this->financeAsyncJobService();
            $errors = $service->listErrors($job['job_id'], $this->accountSetId, 1, 1);
            if ((int)($errors['total'] ?? 0) === 0) {
                $this->addAsyncGeneralErrorItem(
                    $job,
                    substr((string)($job['job_type'] ?? 'ASYNC_JOB'), 0, 50),
                    'JOB_ERROR',
                    $message
                );
            }
            $current = $service->findById($job['job_id'], $this->accountSetId) ?: [];
            $total = max(1, (int)($current['total_count'] ?? 0));
            $success = max(0, (int)($current['success_count'] ?? 0));
            $failure = max(1, (int)($current['failure_count'] ?? 0));
            $processed = max((int)($current['processed_count'] ?? 0), $success + $failure);
            $this->asyncProgress($job, [
                'stage' => 'FINALIZING',
                'total_count' => max($total, $processed),
                'processed_count' => $processed,
                'success_count' => $success,
                'failure_count' => $failure,
                'message' => '任务处理失败，已记录错误明细',
            ]);
        } catch (\Throwable $recordError) {
            // 错误明细属于补充诊断信息，不能覆盖原始业务异常。
        }
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
        try {
            $data = $this->normalizeBusinessAmountRange($data);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
        $exactUnknownPaymentId = trim((string)($data['unknown_payment_id'] ?? ($data['unknownPaymentId'] ?? '')));
        $returnMatchStatus = $exactUnknownPaymentId === ''
            ? strtoupper(trim((string)($data['return_match_status'] ?? '')))
            : '';
        if ($returnMatchStatus !== '') {
            try {
                $ids = $this->unknownRefundMatchService()
                    ->filterEntityIds('unknown_payment', $returnMatchStatus);
            } catch (\Throwable $e) {
                return $this->error('退汇状态筛选失败：' . $e->getMessage());
            }
            if (is_array($ids) && empty($ids)) {
                return $this->ok(['items' => [], 'total' => 0, 'total_amount' => 0], 'OK', 0);
            }
            if (is_array($ids)) {
                $data['return_match_ids'] = $ids;
            }
        }
        $result = $this->businessQueryRepository()->unknownPaymentList($data, $page, $pagesize);
        $result['items'] = $this->unknownRefundMatchService()
            ->attachUnknownStatuses($result['items'] ?? []);
        return $this->ok($result, 'OK', $result['total']);
    }

    public function unknownRefundMatchPreview($data = [])
    {
        $auth = $this->requirePermission('case_fund:view');
        if ($auth) {
            return $auth;
        }
        $bizTypeError = $this->unknownRefundMatchBizTypeError();
        if ($bizTypeError) {
            return $bizTypeError;
        }
        try {
            $result = $this->unknownRefundMatchService()->preview($data['period'] ?? '');
            unset($result['pairs']);
            return $this->ok($result, '退汇识别预览完成');
        } catch (\Throwable $e) {
            return $this->error('退汇识别预览失败：' . $e->getMessage());
        }
    }

    public function unknownRefundMatchScan($data = [])
    {
        $auth = $this->requirePermission('case_fund:return_match');
        if ($auth) {
            return $auth;
        }
        $bizTypeError = $this->unknownRefundMatchBizTypeError();
        if ($bizTypeError) {
            return $bizTypeError;
        }
        Db::startTrans();
        try {
            $result = $this->unknownRefundMatchService()->scan($data['period'] ?? '');
            $this->logAudit('CASE_FUND_RETURN_MATCH', $result['period'], 'SCAN', null, $result['stats']);
            Db::commit();
            return $this->ok($result, '退汇识别完成');
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->error('退汇识别失败：' . $e->getMessage());
        }
    }

    public function unknownRefundMatchCandidates($data = [])
    {
        $auth = $this->requirePermission('case_fund:view');
        if ($auth) {
            return $auth;
        }
        try {
            $result = $this->unknownRefundMatchService()->candidates(
                $data['unknown_payment_id'] ?? '',
                $data['refund_id'] ?? ''
            );
            return $this->ok($result, 'OK', count($result['items'] ?? []));
        } catch (\Throwable $e) {
            return $this->error('退汇匹配详情查询失败：' . $e->getMessage());
        }
    }

    public function unknownRefundMatchConfirm($data = [])
    {
        return $this->decideUnknownRefundMatch('CONFIRM', $data);
    }

    public function unknownRefundMatchReject($data = [])
    {
        return $this->decideUnknownRefundMatch('REJECT', $data);
    }

    public function unknownRefundMatchCancel($data = [])
    {
        return $this->decideUnknownRefundMatch('CANCEL', $data);
    }

    protected function decideUnknownRefundMatch($operation, array $data)
    {
        $auth = $this->requirePermission('case_fund:return_match');
        if ($auth) {
            return $auth;
        }
        $bizTypeError = $this->unknownRefundMatchBizTypeError();
        if ($bizTypeError) {
            return $bizTypeError;
        }
        $matchId = trim((string)($data['match_id'] ?? ''));
        Db::startTrans();
        try {
            if ($operation === 'CONFIRM') {
                $result = $this->unknownRefundMatchService()->confirm($matchId);
            } elseif ($operation === 'REJECT') {
                $result = $this->unknownRefundMatchService()->reject($matchId, $data['remark'] ?? '');
                $result['rescan'] = $this->unknownRefundMatchService()->scan($result['period']);
            } elseif ($operation === 'CANCEL') {
                $result = $this->unknownRefundMatchService()->cancel($matchId);
                $result['rescan'] = $this->unknownRefundMatchService()->scan($result['period']);
            } else {
                throw new \InvalidArgumentException('不支持的退汇匹配操作：' . $operation);
            }
            $this->logAudit(
                'CASE_FUND_RETURN_MATCH',
                $matchId,
                $operation,
                $result['before'] ?? null,
                $result['after'] ?? null
            );
            Db::commit();
            return $this->ok(['match_id' => $matchId], '退汇匹配操作成功');
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->error('退汇匹配操作失败：' . $e->getMessage());
        }
    }

    protected function unknownRefundMatchBizTypeError()
    {
        $bizType = $this->currentAccountSetBizType();
        if ($bizType !== 'CASE_FUND') {
            return $this->error('当前账套类型不支持退汇识别：' . $bizType);
        }
        return null;
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
            $rows = $this->unknownPaymentAuxiliaryCaseService()->assignImportCodes($rows);
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

        $bizType = $this->currentAccountSetBizType();
        $receiptTraceRequired = $this->receiptTracePolicy()
            ->distributionImportRequiresReceiptTrace($bizType);
        try {
            $rows = $this->parseDistributionImportRowsFromXlsForReceiptPolicy($binary, $receiptTraceRequired);
        } catch (\Exception $e) {
            return $this->error('解析案款分配失败：' . $e->getMessage());
        }
        if (empty($rows)) {
            return $this->error('导入文件没有案款分配数据');
        }

        $errors = $this->validateDistributionRowsForReceiptPolicy($rows, $bizType, $receiptTraceRequired);
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
        try {
            $data = $this->normalizeBusinessAmountRange($data);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
        $exactRefundId = trim((string)($data['refund_id'] ?? ($data['refundId'] ?? '')));
        $returnMatchStatus = $exactRefundId === ''
            ? strtoupper(trim((string)($data['return_match_status'] ?? '')))
            : '';
        if ($returnMatchStatus !== '') {
            try {
                $ids = $this->unknownRefundMatchService()
                    ->filterEntityIds('refund', $returnMatchStatus);
            } catch (\Throwable $e) {
                return $this->error('退汇状态筛选失败：' . $e->getMessage());
            }
            if (is_array($ids) && empty($ids)) {
                return $this->ok(['items' => [], 'total' => 0, 'total_amount' => 0], 'OK', 0);
            }
            if (is_array($ids)) {
                $data['return_match_ids'] = $ids;
            }
        }
        $result = $this->businessQueryRepository()->refundList(
            $data,
            $page,
            $pagesize,
            $allowedOutTypes
        );
        $result['items'] = $this->unknownRefundMatchService()
            ->attachRefundStatuses($result['items'] ?? []);
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
        $receiptTraceRequired = $this->receiptTracePolicy()->refundImportRequiresReceiptTrace($bizType);
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
            $this->bankingPersistenceService()->insertStatements($prepared['insert_rows']);
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

        Db::startTrans();
        try {
            $prepared = $this->bankStatementDeletionService()->prepare($ids);
            if (!empty($prepared['error'])) {
                Db::rollback();
                return $this->error($prepared['error']);
            }
            $rows = $prepared['rows'];
            $this->bankingPersistenceService()->deleteStatements($ids);
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
        try {
            $result = $this->executeBankReconcile($data);
        } catch (\Throwable $e) {
            return $this->error('银行对账失败：' . $e->getMessage());
        }

        return $this->ok($result, '自动对账完成', $result['total']);
    }

    /**
     * 所选日期范围的自动对账必须整批原子替换：旧结果软删除、新结果写入、
     * 银行流水状态和审计日志任一失败时全部回滚。异步任务只改变执行载体，
     * 不改变既有匹配口径，也不允许按流水部分提交。
     */
    protected function executeBankReconcile(array $data, array $job = [])
    {
        $requestedBankCode = trim((string)($data['bank_code'] ?? ''));
        $dateStart = trim((string)($data['date_start'] ?? ''));
        $dateEnd = trim((string)($data['date_end'] ?? ''));
        $counts = $this->emptyBankReconcileCounts();
        Db::startTrans();
        try {
            if (!empty($job)) {
                $this->financeAsyncJobService()->fenceBusinessTransaction(
                    $job['job_id'],
                    $job['worker_token'] ?? '',
                    'MATCHING',
                    '正在事务内重算银行对账结果'
                );
            }
            $this->bankingPersistenceService()->lockReconcileScope();
            // 对账结果表当前没有银行编码，必须按账套+日期全范围重算，避免单银行重跑
            // 清除其他银行结果后却未同步其流水状态。请求银行仅用于审计追溯。
            $reconcileScope = $this->bankReconcileRepository()->prefetchReconcileScope(
                '',
                $dateStart,
                $dateEnd,
                true
            );
            $statements = $reconcileScope['statements'] ?? [];
            $currentStatementStatuses = [];
            foreach ($statements as $statement) {
                $currentStatementStatuses[$statement['statement_id']] = $statement['reconcile_status'] ?? 'UNMATCHED';
            }
            $matchResult = $this->bankReconcileMatcher()->matchPrefetched(
                $reconcileScope,
                '',
                $dateStart,
                $dateEnd
            );
            $statementIds = $matchResult['statement_ids'];
            $resultRows = $matchResult['result_rows'];
            $statementStatuses = $matchResult['statement_statuses'];
            foreach ($resultRows as $row) {
                $counts[$row['match_status']] = ($counts[$row['match_status']] ?? 0) + 1;
            }
            $this->bankingPersistenceService()->persistReconcileResult(
                $dateStart,
                $dateEnd,
                $statementIds,
                $resultRows,
                $statementStatuses,
                $currentStatementStatuses
            );
            $auditAfter = [
                'bank_code' => '',
                'requested_bank_code' => $requestedBankCode,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'counts' => $counts,
            ];
            if (!empty($job['job_id'])) {
                $auditAfter['job_id'] = $job['job_id'];
            }
            $this->logAudit(
                'CASE_FUND_BANK_RECONCILE',
                'RUN',
                empty($job) ? 'RECONCILE' : 'RECONCILE_ASYNC',
                null,
                $auditAfter
            );
            $total = count($resultRows);
            if (!empty($job)) {
                // 任务成功终态必须与对账结果和审计共用当前外层事务。这样 fatal
                // 或硬退出只会留下两种 durable 状态：全部回滚并等待 fail/接管，
                // 或业务数据与 SUCCEEDED 一起提交，不再出现“数据成功、任务失败”。
                $this->financeAsyncJobService()->completeInBusinessTransaction(
                    $job['job_id'],
                    $job['worker_token'] ?? '',
                    $this->bankReconcileAsyncCompletionResult($total, $counts),
                    'SUCCEEDED'
                );
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        $result = [
            'total' => $total,
            'counts' => $counts,
        ];
        if (!empty($job)) {
            $result[FinanceAsyncJobService::ATOMIC_COMPLETION_KEY] = 'SUCCEEDED';
        }
        return $result;
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

        try {
            $data = $this->normalizeBusinessAmountRange($data);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
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

        $prepared = $this->distributionVoucherGenerationService()->prepareGeneration(
            $data['distribution_ids'] ?? ($data['ids'] ?? [])
        );
        if (!empty($prepared['error'])) {
            return $this->error($prepared['error']);
        }

        Db::startTrans();
        try {
            $result = $this->distributionVoucherGenerationService()->generatePrepared($prepared);
            $this->logAudit('CASE_FUND_DISTRIBUTION', 'VOUCHER_GENERATE', 'GENERATE', null, [
                'distribution_count' => $result['distribution_count'],
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
            'distribution_count' => $result['distribution_count'],
            'vouchers' => $result['vouchers'],
        ], '成功生成 ' . $result['generated_count'] . ' 张凭证');
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

        try {
            $data = $this->normalizeBusinessAmountRange($data);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
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

    protected function assertVoucherPeriodsOpen($rows, $dateField, $recordLabel)
    {
        $periods = [];
        foreach ($rows as $row) {
            $date = trim((string)($row[$dateField] ?? ''));
            $period = strlen($date) >= 7 ? substr($date, 0, 7) : '';
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
                throw new \RuntimeException($recordLabel . '日期格式不正确：' . ($date ?: 'EMPTY'));
            }
            if ((int)substr($period, 0, 4) !== $this->currentYear()) {
                throw new \RuntimeException($recordLabel . '日期所属年度与当前会计年度不一致：' . $date);
            }
            $periods[$period] = true;
        }
        foreach (array_keys($periods) as $period) {
            $periodRow = $this->getdb('fin_fiscal_period')->where([
                'account_set_id' => $this->accountSetId,
                'period' => $period,
                'del_flag' => 0,
            ])->lock(true)->find();
            if (!$periodRow || ($periodRow['status'] ?? '') !== 'OPEN') {
                throw new \RuntimeException($recordLabel . '日期所属会计期间不是打开状态：' . $period);
            }
        }
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

    protected function buildRefundDebitDetailData($row, $config, $lineNo, $useNormalSummary = false)
    {
        return $this->voucherRule()->buildRefundDebitDetailData($row, $config, $lineNo, $useNormalSummary);
    }

    protected function buildPaymentVoucherSummary($row)
    {
        return $this->voucherRule()->buildPaymentVoucherSummary($row);
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

    protected function getSubjectAuxConfigs($subjectCode)
    {
        return $this->getdb('fin_subject_aux_config')->where([
            'account_set_id' => $this->accountSetId,
            'subject_code' => $subjectCode,
            'del_flag' => 0,
        ])->select();
    }

    protected function loadOccupiedUnknownAuxCaseCodeValues()
    {
        $values = [];
        $businessRows = $this->getdb(self::TABLE_UNKNOWN_PAYMENT)->where([
            'account_set_id' => $this->accountSetId,
        ])->where('aux_case_code', 'neq', '')
            ->field('aux_case_code')->select();
        foreach ($businessRows as $row) {
            $values[] = $row['aux_case_code'] ?? '';
        }

        $openingRows = $this->getdb('fin_aux_opening_balance')->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->where('aux_values_json', 'like', '%BMK-%')
            ->field('aux_values_json')->select();
        foreach ($openingRows as $row) {
            $values[] = $row['aux_values_json'] ?? '';
        }

        $voucherRows = $this->getdb('fin_voucher_aux_value')->where([
            'account_set_id' => $this->accountSetId,
            'aux_type_code' => 'case_no',
            'del_flag' => 0,
        ])->where('aux_value', 'like', '%BMK-%')
            ->field('aux_value,aux_label')->select();
        foreach ($voucherRows as $row) {
            $values[] = $row['aux_value'] ?? '';
            $values[] = $row['aux_label'] ?? '';
        }

        $archiveRows = $this->getdb('fin_aux_archive')->where([
            'account_set_id' => $this->accountSetId,
            'aux_type_code' => 'case_no',
            'del_flag' => 0,
        ])->where('archive_code', 'like', '%BMK-%')
            ->field('archive_code,archive_name')->select();
        foreach ($archiveRows as $row) {
            $values[] = $row['archive_code'] ?? '';
            $values[] = $row['archive_name'] ?? '';
        }
        return $values;
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
        return $this->parseDistributionImportRowsFromXlsForReceiptPolicy($binary, true);
    }

    protected function parseDistributionImportRowsFromXlsForReceiptPolicy($binary, $receiptTraceRequired)
    {
        $tableRows = $this->parseSpreadsheetRows($binary);
        return $this->importParser()->parseDistributionTableRows($tableRows, $receiptTraceRequired);
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

    protected function paymentReceiptNoDuplicateErrors($rows)
    {
        return $this->importPersistence()->paymentReceiptNoDuplicateErrors($rows);
    }

    protected function validateDistributionRows($rows, $bizType = '')
    {
        return $this->validateDistributionRowsForReceiptPolicy($rows, $bizType, true);
    }

    protected function validateDistributionRowsForReceiptPolicy($rows, $bizType, $receiptTraceRequired)
    {
        return $this->importValidator()->validateDistributionRows(
            $rows,
            $bizType,
            $this->allowedDistributionInvoiceTypes($bizType),
            $receiptTraceRequired
        );
    }

    protected function validateUnknownPaymentRows($rows, $bizType = '')
    {
        return $this->importValidator()->validateUnknownPaymentRows($rows, $bizType);
    }

    protected function validateRefundRows($rows, $bizType = '', $receiptTraceRequired = null)
    {
        $allowedOutTypes = $this->allowedRefundOutTypes($bizType);
        if ($receiptTraceRequired === null) {
            $receiptTraceRequired = $this->receiptTracePolicy()
                ->refundImportRequiresReceiptTrace($bizType, $rows);
        }
        return $this->importValidator()->validateRefundRows(
            $rows,
            $bizType,
            $receiptTraceRequired,
            $allowedOutTypes
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
        Db::startTrans();
        try {
            $prepared = $this->businessDeletionService()->prepareBusinessDeletion(
                $table,
                $idField,
                $ids,
                $bizType,
                $label
            );
            if (!empty($prepared['error'])) {
                Db::rollback();
                return $this->error($prepared['error']);
            }
            $rows = $prepared['rows'];
            $hardDelete = $prepared['hard_delete'];
            if (in_array($bizType, ['UNKNOWN_PAYMENT', 'REFUND'], true)) {
                $entity = $bizType === 'UNKNOWN_PAYMENT' ? 'unknown_payment' : 'refund';
                $returnMatchDeleteError = $this->unknownRefundMatchService()
                    ->sourceDeletionError($entity, $ids);
                if ($returnMatchDeleteError !== null) {
                    Db::rollback();
                    return $this->error($returnMatchDeleteError);
                }
                $this->unknownRefundMatchService()->detachDeletedSources($entity, $ids);
            }
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
        return $this->bankReconcileRepository()->paymentCandidatesByBankStatement($statement, true);
    }

    protected function refundCandidatesByBankStatement($statement)
    {
        return $this->bankReconcileRepository()->refundCandidatesByBankStatement($statement, true);
    }

    protected function unknownPaymentCandidatesByBankStatement($statement)
    {
        return $this->bankReconcileRepository()->unknownPaymentCandidatesByBankStatement($statement, true);
    }

    protected function paymentCandidatesInScope($dateStart, $dateEnd)
    {
        return $this->bankReconcileRepository()->paymentCandidatesInScope($dateStart, $dateEnd, true);
    }

    protected function refundCandidatesInScope($dateStart, $dateEnd)
    {
        return $this->bankReconcileRepository()->refundCandidatesInScope($dateStart, $dateEnd, true);
    }

    protected function unknownPaymentCandidatesInScope($dateStart, $dateEnd)
    {
        return $this->bankReconcileRepository()->unknownPaymentCandidatesInScope($dateStart, $dateEnd, true);
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

    protected function bankStatementExistsByUnknownPayment($unknownPayment, $bankCode = '', $dateStart = '', $dateEnd = '')
    {
        return $this->bankReconcileRepository()->bankStatementExistsByUnknownPayment(
            $unknownPayment,
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

    protected function emptyBankReconcileCounts()
    {
        return $this->bankingRule()->emptyBankReconcileCounts();
    }

    protected function voucherSubjectConfigKey($bizType, $voucherBizType, $businessItemType)
    {
        return $this->businessPolicy()->voucherSubjectConfigKey($bizType, $voucherBizType, $businessItemType);
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

    protected function paymentMethodSubjectConfigMap($bizType)
    {
        return $this->subjectConfigResolver()->paymentMethodConfigMap($bizType);
    }

    protected function validateVoucherSubjectCode($subjectCode, $label)
    {
        return $this->subjectConfigManager()->validateVoucherSubjectCode($subjectCode, $label);
    }

    protected function subjectNameMap($subjectCodes)
    {
        return $this->subjectConfigManager()->subjectNameMap($subjectCodes);
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
            ], [
                'payment' => self::TABLE_PAYMENT,
                'refund' => self::TABLE_REFUND,
                'unknown_payment' => self::TABLE_UNKNOWN_PAYMENT,
                'statement' => self::TABLE_BANK_STATEMENT,
                'reconcile' => self::TABLE_BANK_RECONCILE,
            ], [
                'payment' => self::PAYMENT_FIELD,
                'refund' => self::REFUND_FIELD,
                'unknown_payment' => self::UNKNOWN_PAYMENT_FIELD,
                'statement' => self::BANK_STATEMENT_FIELD,
                'reconcile' => self::BANK_RECONCILE_FIELD,
            ]);
        }
        return $this->bankReconcileRepository;
    }

    protected function bankingPersistenceService()
    {
        if ($this->bankingPersistenceService === null) {
            $this->bankingPersistenceService = new CaseFundBankingPersistenceService([
                'get_db' => function ($table) {
                    return $this->getdb($table);
                },
                'account_set_id' => function () {
                    return $this->accountSetId;
                },
                'current_year' => function () {
                    return $this->currentYear();
                },
                'prepare_create' => function ($row) {
                    $this->fillCreate($row);
                    return $row;
                },
                'prepare_update' => function ($row) {
                    $this->fillUpdate($row);
                    return $row;
                },
            ], [
                'account_set' => 'fin_account_set',
                'statement' => self::TABLE_BANK_STATEMENT,
                'reconcile' => self::TABLE_BANK_RECONCILE,
            ], self::BANK_RECONCILE_AUTO_MATCH_RULES);
        }
        return $this->bankingPersistenceService;
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
                'unknown_payment_candidates_by_statement' => function ($statement) {
                    return $this->unknownPaymentCandidatesByBankStatement($statement);
                },
                'refund_candidates_by_statement' => function ($statement) {
                    return $this->refundCandidatesByBankStatement($statement);
                },
                'payment_candidates_in_scope' => function ($dateStart, $dateEnd) {
                    return $this->paymentCandidatesInScope($dateStart, $dateEnd);
                },
                'unknown_payment_candidates_in_scope' => function ($dateStart, $dateEnd) {
                    return $this->unknownPaymentCandidatesInScope($dateStart, $dateEnd);
                },
                'refund_candidates_in_scope' => function ($dateStart, $dateEnd) {
                    return $this->refundCandidatesInScope($dateStart, $dateEnd);
                },
                'bank_statement_exists_by_payment' => function ($payment, $bankCode, $dateStart, $dateEnd) {
                    return $this->bankStatementExistsByPayment($payment, $bankCode, $dateStart, $dateEnd);
                },
                'bank_statement_exists_by_unknown_payment' => function ($unknownPayment, $bankCode, $dateStart, $dateEnd) {
                    return $this->bankStatementExistsByUnknownPayment($unknownPayment, $bankCode, $dateStart, $dateEnd);
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
                'resolve_unknown_claim_aux' => function ($row, $subjectCode) {
                    $resolver = new \app\finance\service\casefund\voucher\UnknownClaimAuxiliaryResolver(
                        function ($table) { return $this->getdb($table); },
                        $this->accountSetId
                    );
                    return $resolver->resolve($row, $subjectCode);
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

    protected function voucherPersistenceService()
    {
        if ($this->voucherPersistenceService === null) {
            $this->voucherPersistenceService = new CaseFundVoucherPersistenceService([
                'get_db' => function ($table) {
                    return $this->getdb($table);
                },
                'account_set_id' => function () {
                    return $this->accountSetId;
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
            ]);
        }
        return $this->voucherPersistenceService;
    }

    protected function voucherAuxiliaryService()
    {
        if ($this->voucherAuxiliaryService === null) {
            $this->voucherAuxiliaryService = new CaseFundVoucherAuxiliaryService([
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
            ]);
        }
        return $this->voucherAuxiliaryService;
    }

    protected function unknownPaymentAuxiliaryCaseService()
    {
        if ($this->unknownPaymentAuxiliaryCaseService === null) {
            $this->unknownPaymentAuxiliaryCaseService = new UnknownPaymentAuxiliaryCaseService([
                'load_occupied_codes' => function () {
                    return $this->loadOccupiedUnknownAuxCaseCodeValues();
                },
                'persist_code' => function ($unknownPaymentId, $code) {
                    try {
                        return $this->getdb(self::TABLE_UNKNOWN_PAYMENT)->where([
                            'unknown_payment_id' => $unknownPaymentId,
                            'account_set_id' => $this->accountSetId,
                            'del_flag' => 0,
                        ])->update([
                            'aux_case_code' => $code,
                            'updated_by' => $this->userid,
                            'updated_time' => $this->now(),
                        ]);
                    } catch (\Exception $e) {
                        if ((int)$e->getCode() === 1062 || stripos($e->getMessage(), 'Duplicate entry') !== false) {
                            return 0;
                        }
                        throw $e;
                    }
                },
            ]);
        }
        return $this->unknownPaymentAuxiliaryCaseService;
    }

    protected function paymentVoucherGenerationService()
    {
        if ($this->paymentVoucherGenerationService === null) {
            $this->paymentVoucherGenerationService = new PaymentVoucherGenerationService(
                $this->businessPolicy(),
                $this->subjectConfigResolver(),
                $this->subjectConfigManager(),
                $this->voucherRule(),
                $this->voucherPersistenceService(),
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
                    'assert_periods_open' => function ($rows, $dateField, $recordLabel) {
                        $this->assertVoucherPeriodsOpen($rows, $dateField, $recordLabel);
                    },
                    'fiscal_year' => function ($period) {
                        return $this->fiscalYear($period);
                    },
                    'generate_next_voucher_no' => function ($period) {
                        return $this->generateNextVoucherNo($period);
                    },
                    'ensure_aux_archives' => function ($detailsData) {
                        $this->voucherAuxiliaryService()->ensureArchivesForVoucherDetails($detailsData);
                    },
                    'new_id' => function () {
                        return uuid();
                    },
                    'current_user_id' => function () {
                        return $this->userid;
                    },
                    'now' => function () {
                        return $this->now();
                    },
                ],
                self::TABLE_PAYMENT,
                self::PAYMENT_VOUCHER_FIELD
            );
        }
        return $this->paymentVoucherGenerationService;
    }

    protected function financeAsyncJobService()
    {
        if ($this->financeAsyncJobService === null) {
            $this->financeAsyncJobService = FinanceAsyncJobService::createDefault();
        }
        return $this->financeAsyncJobService;
    }

    protected function refundVoucherGenerationService()
    {
        if ($this->refundVoucherGenerationService === null) {
            $this->refundVoucherGenerationService = new RefundVoucherGenerationService(
                $this->businessPolicy(),
                $this->subjectConfigResolver(),
                $this->subjectConfigManager(),
                $this->voucherRule(),
                $this->voucherPersistenceService(),
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
                    'assert_periods_open' => function ($rows, $dateField, $recordLabel) {
                        $this->assertVoucherPeriodsOpen($rows, $dateField, $recordLabel);
                    },
                    'fiscal_year' => function ($period) {
                        return $this->fiscalYear($period);
                    },
                    'generate_next_voucher_no' => function ($period) {
                        return $this->generateNextVoucherNo($period);
                    },
                    'ensure_aux_archives' => function ($detailsData) {
                        $this->voucherAuxiliaryService()->ensureArchivesForVoucherDetails($detailsData);
                    },
                    'new_id' => function () {
                        return uuid();
                    },
                    'current_user_id' => function () {
                        return $this->userid;
                    },
                    'now' => function () {
                        return $this->now();
                    },
                ],
                self::TABLE_REFUND,
                self::REFUND_VOUCHER_FIELD
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
                $this->voucherPersistenceService(),
                $this->unknownPaymentAuxiliaryCaseService(),
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
                    'ensure_aux_archives' => function ($detailsData) {
                        $this->voucherAuxiliaryService()->ensureArchivesForVoucherDetails($detailsData);
                    },
                    'new_id' => function () {
                        return uuid();
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

    protected function distributionVoucherGenerationService()
    {
        if ($this->distributionVoucherGenerationService === null) {
            $this->distributionVoucherGenerationService = new DistributionVoucherGenerationService(
                $this->subjectConfigResolver(),
                $this->subjectConfigManager(),
                $this->voucherRule(),
                $this->voucherPersistenceService(),
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
                    'assert_rows_can_generate' => function ($rows, $recordLabel) {
                        return $this->assertRowsCanGenerateVoucher($rows, $recordLabel);
                    },
                    'validate_period' => function ($row, $period) {
                        return $this->validateDistributionVoucherPeriod($row, $period);
                    },
                    'build_header_summary' => function ($row) {
                        return $this->buildDistributionVoucherHeaderSummary($row);
                    },
                    'ensure_aux_archives' => function ($detailsData) {
                        $this->voucherAuxiliaryService()->ensureArchivesForVoucherDetails($detailsData);
                    },
                    'fiscal_year' => function ($period) {
                        return $this->fiscalYear($period);
                    },
                    'generate_next_voucher_no' => function ($period) {
                        return $this->generateNextVoucherNo($period);
                    },
                    'new_id' => function () {
                        return uuid();
                    },
                    'current_user_id' => function () {
                        return $this->userid;
                    },
                    'now' => function () {
                        return $this->now();
                    },
                ],
                self::TABLE_DISTRIBUTION,
                self::DISTRIBUTION_FIELD
            );
        }
        return $this->distributionVoucherGenerationService;
    }

    protected function businessPolicy()
    {
        if ($this->businessPolicy === null) {
            $this->businessPolicy = new CaseFundBusinessPolicy();
        }
        return $this->businessPolicy;
    }

    protected function receiptTracePolicy()
    {
        if ($this->receiptTracePolicy === null) {
            $this->receiptTracePolicy = new CaseFundReceiptTracePolicy(
                $this->businessPolicy(),
                $this->subjectConfigResolver(),
                [
                    'get_db' => function ($table) {
                        return $this->getdb($table);
                    },
                    'account_set_id' => function () {
                        return $this->accountSetId;
                    },
                ]
            );
        }
        return $this->receiptTracePolicy;
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

    protected function unknownRefundMatchService()
    {
        if ($this->unknownRefundMatchService === null) {
            $this->unknownRefundMatchService = new CaseFundUnknownRefundMatchService([
                'get_db' => function ($table) {
                    return $this->getdb($table);
                },
                'account_set_id' => function () {
                    return $this->accountSetId;
                },
                'current_year' => function () {
                    return $this->currentYear();
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
                'decimal_to_cents' => function ($amount) {
                    return $this->decimalToCents($amount);
                },
                'now' => function () {
                    return $this->now();
                },
                'user_id' => function () {
                    return $this->userid;
                },
            ], [
                'unknown_payment' => self::TABLE_UNKNOWN_PAYMENT,
                'refund' => self::TABLE_REFUND,
                'match' => self::TABLE_UNKNOWN_REFUND_MATCH,
            ]);
        }
        return $this->unknownRefundMatchService;
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
