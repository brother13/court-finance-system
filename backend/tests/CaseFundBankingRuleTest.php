<?php

function input($key = '', $default = null)
{
    return $default;
}

function config($key = '')
{
    if ($key === 'default_account_set_id') {
        return '00000000-0000-0000-0000-000000000101';
    }
    if ($key === 'default_year') {
        return '2026';
    }
    return null;
}

function getNowTime()
{
    return '2026-07-14 12:34:56';
}

function uuid()
{
    return '12345678-1234-4000-8000-123456789abc';
}

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\model\CaseFund;
use app\finance\service\casefund\banking\CaseFundBankingRule;

class CaseFundBankingRuleProbe extends CaseFund
{
    public function setBankingContext($accountSetId, $userId)
    {
        $this->accountSetId = $accountSetId;
        $this->userid = $userId;
    }

    public function banks()
    {
        return $this->supportedBankStatementBanks();
    }

    public function normalizeBankRow($row, $bankCode, $bankName, $defaultRowNo)
    {
        return $this->normalizeBankStatementRow($row, $bankCode, $bankName, $defaultRowNo);
    }

    public function statementFingerprint($row)
    {
        return $this->bankStatementFingerprint($row);
    }

    public function reconcileRow($statement, $bizType, $biz, $status, $bankCents, $bizCents, $matchRule = 'SERIAL_NO')
    {
        return $this->buildBankReconcileRow($statement, $bizType, $biz, $status, $bankCents, $bizCents, $matchRule);
    }

    public function bizAmountCents($bizType, $biz)
    {
        return $this->bankReconcileBizAmountCents($bizType, $biz);
    }

    public function bizId($bizType, $biz)
    {
        return $this->bankReconcileBizId($bizType, $biz);
    }

    public function bizNo($bizType, $biz)
    {
        return $this->bankReconcileBizNo($bizType, $biz);
    }

    public function bizDate($bizType, $biz)
    {
        return $this->bankReconcileBizDate($bizType, $biz);
    }

    public function bizSummary($bizType, $biz)
    {
        return $this->bankReconcileBizSummary($bizType, $biz);
    }

    public function emptyCounts()
    {
        return $this->emptyBankReconcileCounts();
    }

    public function ruleService()
    {
        return $this->bankingRule();
    }
}

class CaseFundBankingRuleOverrideProbe extends CaseFundBankingRuleProbe
{
    protected function normalizeDateTimeValue($value)
    {
        if ($value === 'OVERRIDE-DATETIME') {
            return '2026-07-20 01:02:03';
        }
        return parent::normalizeDateTimeValue($value);
    }

    protected function bankStatementFingerprint($row)
    {
        return 'override-bank-fingerprint';
    }

    protected function bankReconcileBizDate($bizType, $biz)
    {
        if (($biz['override_marker'] ?? '') === 'DATE') {
            return '2026-07-21';
        }
        return parent::bankReconcileBizDate($bizType, $biz);
    }

    protected function bankReconcileBizSummary($bizType, $biz)
    {
        if (($biz['override_marker'] ?? '') === 'DATE') {
            return 'override-biz-summary';
        }
        return parent::bankReconcileBizSummary($bizType, $biz);
    }
}

function bankingRuleAssertSame($expected, $actual, $message)
{
    if ($actual !== $expected) {
        fwrite(
            STDERR,
            $message
            . '; expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
            . PHP_EOL
        );
        exit(1);
    }
}

function bankingRuleAssertThrows($expectedMessage, callable $callback, $message)
{
    try {
        $callback();
    } catch (\Exception $e) {
        bankingRuleAssertSame($expectedMessage, $e->getMessage(), $message);
        return;
    }
    fwrite(STDERR, $message . '; expected exception was not thrown' . PHP_EOL);
    exit(1);
}

bankingRuleAssertThrows(
    '案款银行规则缺少回调：normalize_datetime',
    function () {
        new CaseFundBankingRule([]);
    },
    'Banking rule should reject incomplete hook configuration'
);

$probe = new CaseFundBankingRuleProbe();
$probe->setBankingContext('account-set-banking', 'bank-user');
bankingRuleAssertSame(true, $probe->ruleService() instanceof CaseFundBankingRule, 'CaseFund should lazily create the extracted banking rule');
bankingRuleAssertSame([
    'SHENGJING' => '盛京银行',
    'CCB' => '建设银行',
], $probe->banks(), 'Supported bank codes and labels should remain stable');

$wrapperSignatures = [
    'supportedBankStatementBanks' => 0,
    'normalizeBankStatementRow' => 4,
    'bankStatementFingerprint' => 1,
    'buildBankReconcileRow' => 7,
    'bankReconcileBizAmountCents' => 2,
    'bankReconcileBizId' => 2,
    'bankReconcileBizNo' => 2,
    'bankReconcileBizDate' => 2,
    'bankReconcileBizSummary' => 2,
    'emptyBankReconcileCounts' => 0,
];
foreach ($wrapperSignatures as $methodName => $parameterCount) {
    $method = new ReflectionMethod(CaseFund::class, $methodName);
    bankingRuleAssertSame(true, $method->isProtected(), 'Banking compatibility wrapper should remain protected: ' . $methodName);
    bankingRuleAssertSame($parameterCount, count($method->getParameters()), 'Banking wrapper parameter count should remain stable: ' . $methodName);
}
$buildParameters = (new ReflectionMethod(CaseFund::class, 'buildBankReconcileRow'))->getParameters();
bankingRuleAssertSame(true, $buildParameters[6]->isDefaultValueAvailable(), 'Reconcile match-rule parameter should keep its default');
bankingRuleAssertSame('SERIAL_NO', $buildParameters[6]->getDefaultValue(), 'Reconcile match-rule default should remain SERIAL_NO');

$rawDebit = [
    'source_row_no' => '7',
    'transaction_time' => '2026/07/14 08:09:10',
    'debit_amount' => '97.345',
    'credit_amount' => '0',
    'balance_amount' => '1000.005',
    'counterparty_account_no' => ' 62220001 ',
    'counterparty_account_name' => ' 张三 ',
    'counterparty_bank_name' => ' 测试银行 ',
    'purpose' => ' 案款退付 ',
    'postscript' => ' 附言 ',
    'bank_serial_no' => ' BANK-001 ',
];
$originalTimezone = date_default_timezone_get();
date_default_timezone_set('PRC');
$debit = $probe->normalizeBankRow($rawDebit, 'CCB', '建设银行', 2);
date_default_timezone_set($originalTimezone);
$expectedDebit = [
    'fiscal_year' => 2026,
    'period' => '2026-07',
    'bank_code' => 'CCB',
    'bank_name' => '建设银行',
    'transaction_date' => '2026-07-14',
    'transaction_time' => '2026-07-14 08:09:10',
    'direction' => 'DEBIT',
    'debit_amount' => '97.35',
    'credit_amount' => '0.00',
    'balance_amount' => '1000.01',
    'counterparty_account_no' => '62220001',
    'counterparty_account_name' => '张三',
    'counterparty_bank_name' => '测试银行',
    'purpose' => '案款退付',
    'postscript' => '附言',
    'bank_serial_no' => 'BANK-001',
    'source_row_no' => 7,
];
foreach ($expectedDebit as $field => $expected) {
    bankingRuleAssertSame($expected, $debit[$field] ?? null, 'Normalized debit bank row field mismatch: ' . $field);
}
bankingRuleAssertSame($rawDebit, json_decode($debit['source_raw_json'], true), 'Bank row should retain its original source values as JSON');
$expectedDebitFingerprint = md5(implode('|', [
    'CCB',
    '2026-07-14 08:09:10',
    '97.35',
    '0.00',
    '1000.01',
    '62220001',
    '张三',
    'BANK-001',
]));
bankingRuleAssertSame($expectedDebitFingerprint, $debit['source_fingerprint'], 'Normalized bank row fingerprint should use mapped values in the legacy order');

$credit = $probe->normalizeBankRow([
    'transaction_time' => '2026-07-15 09:10:11',
    'debit_amount' => '0',
    'credit_amount' => '12.30',
    'balance_amount' => '1012.31',
    'bank_serial_no' => 'CREDIT-001',
], 'SHENGJING', '盛京银行', 3);
bankingRuleAssertSame('CREDIT', $credit['direction'], 'Credit-only rows should remain income bank rows');
bankingRuleAssertSame(3, $credit['source_row_no'], 'Missing source row numbers should use the supplied default');

$errorCases = [
    [
        ['source_row_no' => 10, 'transaction_time' => 'invalid', 'debit_amount' => '-1', 'credit_amount' => '0', 'bank_serial_no' => 'X'],
        '第10行：交易时间不能为空或格式不正确',
    ],
    [
        ['source_row_no' => 11, 'transaction_time' => '2026-07-14', 'debit_amount' => '-1', 'credit_amount' => '0', 'bank_serial_no' => 'X'],
        '第11行：支出和收入金额不能为负数',
    ],
    [
        ['source_row_no' => 12, 'transaction_time' => '2026-07-14', 'debit_amount' => '1', 'credit_amount' => '2', 'bank_serial_no' => 'X'],
        '第12行：支出和收入必须且只能填写一项',
    ],
    [
        ['source_row_no' => 13, 'transaction_time' => '2026-07-14', 'debit_amount' => '0', 'credit_amount' => '0', 'bank_serial_no' => 'X'],
        '第13行：支出和收入必须且只能填写一项',
    ],
    [
        ['source_row_no' => 14, 'transaction_time' => '2026-07-14', 'debit_amount' => '1', 'credit_amount' => '0', 'bank_serial_no' => ' '],
        '第14行：交易流水号不能为空',
    ],
];
foreach ($errorCases as $index => $case) {
    list($row, $expectedError) = $case;
    bankingRuleAssertSame(
        ['error' => $expectedError],
        $probe->normalizeBankRow($row, 'CCB', '建设银行', 99),
        'Bank row validation precedence should remain stable for case ' . $index
    );
}

$fingerprintRow = [
    'bank_code' => 'CCB',
    'transaction_time' => '2026-07-14 08:09:10',
    'debit_amount' => '97.35',
    'credit_amount' => '0.00',
    'balance_amount' => '1000.01',
    'counterparty_account_no' => '62220001',
    'counterparty_account_name' => '张三',
    'bank_serial_no' => 'BANK-001',
];
bankingRuleAssertSame($expectedDebitFingerprint, $probe->statementFingerprint($fingerprintRow), 'Bank fingerprint wrapper should preserve the fixed vector');
$fingerprintWithMetadata = $fingerprintRow;
$fingerprintWithMetadata['account_set_id'] = 'another-account-set';
$fingerprintWithMetadata['source_row_no'] = 99;
bankingRuleAssertSame($expectedDebitFingerprint, $probe->statementFingerprint($fingerprintWithMetadata), 'Bank fingerprint should ignore account and source metadata');

$statement = [
    'statement_id' => 'statement-001',
    'transaction_date' => '2026-07-14',
    'bank_serial_no' => 'BANK-001',
    'direction' => 'CREDIT',
    'purpose' => '案款收款',
    'postscript' => '测试备注',
];
$payment = [
    'payment_id' => 'payment-001',
    'bank_serial_no' => 'PAY-SERIAL-001',
    'payment_date' => '2026-07-14',
    'payment_amount' => '97.50',
    'case_no' => '（2026）辽0111执1号',
    'payer_name' => '缴费人甲',
    'party_name' => '当事人甲',
];
$paymentReconcile = $probe->reconcileRow(
    $statement,
    'PAYMENT',
    $payment,
    'AMOUNT_DIFF',
    10000,
    9750,
    'SERIAL_NO_DATE'
);
$expectedPaymentReconcile = [
    'reconcile_id' => '12345678-1234-4000-8000-123456789abc',
    'account_set_id' => 'account-set-banking',
    'fiscal_year' => 2026,
    'period' => '2026-07',
    'reconcile_date' => '2026-07-14',
    'statement_id' => 'statement-001',
    'biz_type' => 'PAYMENT',
    'biz_id' => 'payment-001',
    'biz_no' => 'PAY-SERIAL-001',
    'bank_serial_no' => 'BANK-001',
    'bank_amount' => '100.00',
    'biz_amount' => '97.50',
    'diff_amount' => '2.50',
    'match_status' => 'AMOUNT_DIFF',
    'match_rule' => 'SERIAL_NO_DATE',
    'matched_by' => 'bank-user',
    'matched_time' => '2026-07-14 12:34:56',
    'bank_direction' => 'CREDIT',
    'bank_summary' => '案款收款 测试备注',
    'biz_summary' => '（2026）辽0111执1号 缴费人甲 当事人甲',
    'remark' => '',
];
bankingRuleAssertSame($expectedPaymentReconcile, $paymentReconcile, 'Payment reconcile row mapping should remain exact');

$refund = [
    'refund_id' => 'refund-001',
    'out_order_no' => 'OUT-001',
    'refund_date' => '2026-07-16',
    'refund_amount' => '12.34',
    'case_no' => '（2026）辽0111执2号',
    'actual_payee_name' => '收款人乙',
    'party_name' => '当事人乙',
];
$refundBizOnly = $probe->reconcileRow(null, 'REFUND', $refund, 'BIZ_ONLY', 0, 1234, 'PAYEE_ACCOUNT_DATE');
bankingRuleAssertSame('2026-07-16', $refundBizOnly['reconcile_date'], 'Business-only refund rows should use the refund date');
bankingRuleAssertSame('OUT-001', $refundBizOnly['bank_serial_no'], 'Business-only refund rows should fall back to the out-order number');
bankingRuleAssertSame('0.00', $refundBizOnly['bank_amount'], 'Business-only rows should keep zero bank amount');
bankingRuleAssertSame('12.34', $refundBizOnly['biz_amount'], 'Refund cents should convert back to yuan exactly once');
bankingRuleAssertSame('-12.34', $refundBizOnly['diff_amount'], 'Business-only differences should remain bank minus business amount');
bankingRuleAssertSame('DEBIT', $refundBizOnly['bank_direction'], 'Business-only refunds should use debit bank direction');
bankingRuleAssertSame(
    '（2026）辽0111执2号 收款人乙 当事人乙 出账单号:OUT-001',
    $refundBizOnly['biz_summary'],
    'Refund reconcile summary should preserve its legacy wording'
);

bankingRuleAssertSame(9750, $probe->bizAmountCents('PAYMENT', $payment), 'Payment reconcile amount should use payment_amount');
bankingRuleAssertSame(1234, $probe->bizAmountCents('REFUND', $refund), 'Refund reconcile amount should use refund_amount');
bankingRuleAssertSame('payment-001', $probe->bizId('PAYMENT', $payment), 'Payment reconcile id should use payment_id');
bankingRuleAssertSame('refund-001', $probe->bizId('REFUND', $refund), 'Refund reconcile id should use refund_id');
bankingRuleAssertSame('PAY-SERIAL-001', $probe->bizNo('PAYMENT', $payment), 'Payment reconcile number should use bank serial');
bankingRuleAssertSame('OUT-001', $probe->bizNo('REFUND', $refund), 'Refund reconcile number should use out-order number');
bankingRuleAssertSame('2026-07-14', $probe->bizDate('PAYMENT', $payment), 'Payment reconcile date should use payment date');
bankingRuleAssertSame('2026-07-16', $probe->bizDate('REFUND', $refund), 'Refund reconcile date should use refund date');
bankingRuleAssertSame('（2026）辽0111执1号 缴费人甲 当事人甲', $probe->bizSummary('PAYMENT', $payment), 'Payment reconcile summary should remain stable');
bankingRuleAssertSame([
    'MATCHED' => 0,
    'AMOUNT_DIFF' => 0,
    'BANK_ONLY' => 0,
    'BIZ_ONLY' => 0,
    'DUPLICATE' => 0,
], $probe->emptyCounts(), 'Reconcile status counters and ordering should remain stable');

$overrideProbe = new CaseFundBankingRuleOverrideProbe();
$overrideProbe->setBankingContext('override-account-set', 'override-user');
$overrideBankRow = $overrideProbe->normalizeBankRow([
    'transaction_time' => 'OVERRIDE-DATETIME',
    'debit_amount' => '1.00',
    'credit_amount' => '0',
    'bank_serial_no' => 'OVERRIDE-SERIAL',
], 'CCB', '建设银行', 20);
bankingRuleAssertSame('2026-07-20 01:02:03', $overrideBankRow['transaction_time'], 'Banking rule should preserve CaseFund date normalization overrides');
bankingRuleAssertSame('override-bank-fingerprint', $overrideBankRow['source_fingerprint'], 'Banking rule should preserve CaseFund fingerprint overrides');
$overrideBiz = [
    'override_marker' => 'DATE',
    'refund_id' => 'override-refund',
    'out_order_no' => 'override-order',
];
$overrideReconcile = $overrideProbe->reconcileRow(null, 'REFUND', $overrideBiz, 'BIZ_ONLY', 0, 100, 'PAYEE_ACCOUNT_DATE');
bankingRuleAssertSame('2026-07-21', $overrideReconcile['reconcile_date'], 'Banking rule should preserve CaseFund business-date overrides');
bankingRuleAssertSame('override-biz-summary', $overrideReconcile['biz_summary'], 'Banking rule should preserve CaseFund business-summary overrides');

$ruleSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/banking/CaseFundBankingRule.php');
foreach (['getdb(', 'think\\Db', 'Db::', '->where(', '->insert(', '->update('] as $databaseNeedle) {
    if (strpos($ruleSource, $databaseNeedle) !== false) {
        fwrite(STDERR, 'Banking rule should remain database-free: ' . $databaseNeedle . PHP_EOL);
        exit(1);
    }
}

echo 'Case fund banking rule test passed' . PHP_EOL;
