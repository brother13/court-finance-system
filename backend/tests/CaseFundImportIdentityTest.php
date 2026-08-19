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
    return date('Y-m-d H:i:s');
}

function uuid()
{
    return '12345678-1234-4000-8000-123456789abc';
}

$backendRoot = dirname(__DIR__);
$identityPath = $backendRoot . '/app/finance/service/casefund/importing/CaseFundImportIdentity.php';
if (!is_file($identityPath)) {
    fwrite(STDERR, 'CaseFundImportIdentity service does not exist' . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\model\CaseFund;
use app\finance\service\casefund\importing\CaseFundImportIdentity;

class CaseFundImportIdentityProbe extends CaseFund
{
    public function duplicateKey($row, $useReceiptNoAsDuplicateKey = true)
    {
        return $this->paymentImportDuplicateKey($row, $useReceiptNoAsDuplicateKey);
    }

    public function rememberExistingRow(&$existing, $row, $useReceiptNoAsDuplicateKey = true)
    {
        return $this->rememberPaymentImportExistingRow($existing, $row, $useReceiptNoAsDuplicateKey);
    }

    public function paymentHash($row)
    {
        return $this->paymentFingerprint($row);
    }

    public function refundHash($row)
    {
        return $this->refundFingerprint($row);
    }

    public function unknownPaymentHash($row)
    {
        return $this->unknownPaymentFingerprint($row);
    }

    public function distributionHash($row)
    {
        return $this->distributionFingerprint($row);
    }
}

class CaseFundImportIdentityOverrideProbe extends CaseFund
{
    protected function paymentImportDuplicateKey($row, $useReceiptNoAsDuplicateKey = true)
    {
        return 'override:duplicate-key';
    }

    public function rememberWithOverriddenKey(&$existing, $row)
    {
        return $this->rememberPaymentImportExistingRow($existing, $row, true);
    }
}

function importIdentityAssertSame($expected, $actual, $message)
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

function importIdentityAssertNotSame($unexpected, $actual, $message)
{
    if ($actual === $unexpected) {
        fwrite(STDERR, $message . '; both values were ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function importIdentityCanonicalAmount($amount)
{
    $amount = trim((string)$amount);
    if ($amount === '') {
        return '0.00';
    }
    $amount = str_replace([',', ' '], '', $amount);
    $negative = false;
    if ($amount[0] === '-') {
        $negative = true;
        $amount = substr($amount, 1);
    } elseif ($amount[0] === '+') {
        $amount = substr($amount, 1);
    }
    $parts = explode('.', $amount, 2);
    $yuan = preg_replace('/\D/', '', $parts[0]);
    $cent = isset($parts[1]) ? preg_replace('/\D/', '', $parts[1]) : '';
    $cent = str_pad($cent, 3, '0');
    $cents = ((int)$yuan) * 100 + (int)substr($cent, 0, 2);
    if ((int)$cent[2] >= 5) {
        $cents++;
    }
    if ($negative) {
        $cents = -$cents;
    }
    $negative = $cents < 0;
    $cents = abs((int)$cents);
    return ($negative ? '-' : '')
        . intdiv($cents, 100)
        . '.'
        . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

$canonicalAmountCalls = [];
$identity = new CaseFundImportIdentity(function ($amount) use (&$canonicalAmountCalls) {
    $canonicalAmountCalls[] = $amount;
    return importIdentityCanonicalAmount($amount);
});
$probe = new CaseFundImportIdentityProbe();

$duplicateKeyMethod = new ReflectionMethod(CaseFund::class, 'paymentImportDuplicateKey');
importIdentityAssertSame(true, $duplicateKeyMethod->isProtected(), 'CaseFund duplicate-key wrapper should remain protected');
$duplicateKeyParameters = $duplicateKeyMethod->getParameters();
importIdentityAssertSame(2, count($duplicateKeyParameters), 'CaseFund duplicate-key wrapper should keep two parameters');
importIdentityAssertSame(true, $duplicateKeyParameters[1]->isDefaultValueAvailable(), 'Duplicate-key policy parameter should keep its default');
importIdentityAssertSame(true, $duplicateKeyParameters[1]->getDefaultValue(), 'Duplicate-key policy should remain enabled by default');

$rememberMethod = new ReflectionMethod(CaseFund::class, 'rememberPaymentImportExistingRow');
importIdentityAssertSame(true, $rememberMethod->isProtected(), 'CaseFund existing-map wrapper should remain protected');
$rememberParameters = $rememberMethod->getParameters();
importIdentityAssertSame(3, count($rememberParameters), 'CaseFund existing-map wrapper should keep three parameters');
importIdentityAssertSame(true, $rememberParameters[0]->isPassedByReference(), 'Existing-map wrapper should keep its first parameter by reference');
importIdentityAssertSame(true, $rememberParameters[2]->isDefaultValueAvailable(), 'Existing-map policy parameter should keep its default');
importIdentityAssertSame(true, $rememberParameters[2]->getDefaultValue(), 'Existing-map receipt policy should remain enabled by default');

$overrideProbe = new CaseFundImportIdentityOverrideProbe();
$overrideExisting = [];
$overrideProbe->rememberWithOverriddenKey($overrideExisting, [
    'payment_id' => 'payment-override',
    'voucher_status' => 'UNGENERATED',
]);
importIdentityAssertSame(
    'payment-override',
    $overrideExisting['override:duplicate-key']['payment_id'] ?? null,
    'CaseFund existing-map wrapper should preserve duplicate-key overrides'
);

// 缴费运行时重复键：顺序必须与 existing-map、文件内 seen 使用的顺序完全一致。
$receiptPreferredRow = [
    'receipt_no' => ' 000001 ',
    'source_fingerprint' => ' fp-one ',
    'bank_account_no' => ' ACCOUNT-1 ',
    'payment_amount' => '100.00',
];
$canonicalAmountCalls = [];
importIdentityAssertSame(
    'receipt:000001',
    $identity->paymentImportDuplicateKey($receiptPreferredRow, true),
    'Receipt number should be the first duplicate-key choice when receipt checking is enabled'
);
importIdentityAssertSame([], $canonicalAmountCalls, 'Receipt keys should not invoke amount canonicalization');

$fingerprintPreferredRow = $receiptPreferredRow;
$canonicalAmountCalls = [];
importIdentityAssertSame(
    'fingerprint:fp-one',
    $identity->paymentImportDuplicateKey($fingerprintPreferredRow, false),
    'Fingerprint should win over account and amount when receipt checking is disabled and receipt is present'
);
importIdentityAssertSame([], $canonicalAmountCalls, 'Fingerprint keys should not invoke amount canonicalization');

$blankReceiptFingerprintRow = [
    'receipt_no' => '',
    'source_fingerprint' => ' fp-blank-receipt ',
    'bank_account_no' => ' ACCOUNT-SHARED ',
    'payment_amount' => '7500.00',
];
$canonicalAmountCalls = [];
importIdentityAssertSame(
    'fingerprint:fp-blank-receipt',
    $identity->paymentImportDuplicateKey($blankReceiptFingerprintRow, false),
    'Disabled receipt checks should use the source fingerprint even when receipt number is empty'
);
importIdentityAssertSame([], $canonicalAmountCalls, 'Disabled receipt checks should never canonicalize amounts');

$accountAmountRow = [
    'receipt_no' => '',
    'source_fingerprint' => ' fp-two ',
    'bank_account_no' => ' ACCOUNT-2 ',
    'payment_amount' => '97.349999999999',
];
$canonicalAmountCalls = [];
importIdentityAssertSame(
    'account_amount:ACCOUNT-2|97.35',
    $identity->paymentImportDuplicateKey($accountAmountRow, true),
    'Account plus canonical amount should win when receipt number is empty'
);
importIdentityAssertSame(
    ['97.349999999999'],
    $canonicalAmountCalls,
    'Account-amount keys should pass the original raw amount to the injected canonicalizer exactly once'
);

$canonicalAmountCalls = [];
importIdentityAssertSame(
    'account_amount:ACCOUNT-DEFAULT|0.00',
    $identity->paymentImportDuplicateKey([
        'receipt_no' => '',
        'source_fingerprint' => 'fp-default-amount',
        'bank_account_no' => 'ACCOUNT-DEFAULT',
    ], true),
    'Missing payment amounts should preserve the legacy zero default for account keys'
);
importIdentityAssertSame(['0'], $canonicalAmountCalls, 'Missing payment amounts should canonicalize the string zero once');

$receiptWithoutFingerprint = [
    'receipt_no' => ' R002 ',
    'source_fingerprint' => '',
    'bank_account_no' => ' ACCOUNT-3 ',
    'payment_amount' => '1,234.50',
];
importIdentityAssertSame(
    '',
    $identity->paymentImportDuplicateKey($receiptWithoutFingerprint, false),
    'Disabled receipt checks should not fall through to receipt, account, or amount when fingerprint is absent'
);

$fingerprintOnlyRow = [
    'receipt_no' => ' ',
    'source_fingerprint' => ' fp-only ',
    'bank_account_no' => ' ',
    'payment_amount' => '100.00',
];
importIdentityAssertSame(
    'fingerprint:fp-only',
    $identity->paymentImportDuplicateKey($fingerprintOnlyRow, true),
    'Fingerprint should be the final non-empty duplicate-key fallback'
);
importIdentityAssertSame(
    '',
    $identity->paymentImportDuplicateKey([
        'receipt_no' => ' ',
        'source_fingerprint' => ' ',
        'bank_account_no' => ' ',
        'payment_amount' => '0',
    ], true),
    'Rows without receipt, account, or fingerprint should not produce a duplicate key'
);

$duplicateKeyCases = [
    [$receiptPreferredRow, true],
    [$fingerprintPreferredRow, false],
    [$blankReceiptFingerprintRow, false],
    [$accountAmountRow, true],
    [$receiptWithoutFingerprint, false],
    [$fingerprintOnlyRow, true],
];
foreach ($duplicateKeyCases as $index => $case) {
    list($row, $useReceiptNoAsDuplicateKey) = $case;
    importIdentityAssertSame(
        $identity->paymentImportDuplicateKey($row, $useReceiptNoAsDuplicateKey),
        $probe->duplicateKey($row, $useReceiptNoAsDuplicateKey),
        'CaseFund duplicate-key wrapper should match CaseFundImportIdentity for case ' . $index
    );
}

// existing map：首次命中保留，但任何非 UNGENERATED 行都必须替换当前 UNGENERATED 行。
$ungeneratedOne = [
    'payment_id' => 'payment-u1',
    'receipt_no' => 'R-MAP',
    'source_fingerprint' => 'fp-u1',
    'bank_account_no' => '',
    'payment_amount' => '10.00',
    'voucher_status' => 'UNGENERATED',
];
$ungeneratedTwo = $ungeneratedOne;
$ungeneratedTwo['payment_id'] = 'payment-u2';
$generated = $ungeneratedOne;
$generated['payment_id'] = 'payment-g1';
$generated['voucher_status'] = 'GENERATED';
$voided = $ungeneratedOne;
$voided['payment_id'] = 'payment-v1';
$voided['voucher_status'] = 'VOIDED';

$existing = [];
$identity->rememberPaymentImportExistingRow($existing, $ungeneratedOne, true);
importIdentityAssertSame('payment-u1', $existing['receipt:R-MAP']['payment_id'], 'The first existing payment row should populate the map');
$identity->rememberPaymentImportExistingRow($existing, $ungeneratedTwo, true);
importIdentityAssertSame('payment-u1', $existing['receipt:R-MAP']['payment_id'], 'A later UNGENERATED row should not replace the first UNGENERATED row');
$identity->rememberPaymentImportExistingRow($existing, $generated, true);
importIdentityAssertSame('payment-g1', $existing['receipt:R-MAP']['payment_id'], 'A GENERATED row should replace an UNGENERATED row conservatively');
$identity->rememberPaymentImportExistingRow($existing, $ungeneratedTwo, true);
importIdentityAssertSame('payment-g1', $existing['receipt:R-MAP']['payment_id'], 'An UNGENERATED row should never replace a protected non-UNGENERATED row');
$identity->rememberPaymentImportExistingRow($existing, $voided, true);
importIdentityAssertSame('payment-g1', $existing['receipt:R-MAP']['payment_id'], 'The first non-UNGENERATED row should remain when another non-UNGENERATED row arrives');

$voidedPreferenceMap = [];
$identity->rememberPaymentImportExistingRow($voidedPreferenceMap, $ungeneratedOne, true);
$identity->rememberPaymentImportExistingRow($voidedPreferenceMap, $voided, true);
importIdentityAssertSame('payment-v1', $voidedPreferenceMap['receipt:R-MAP']['payment_id'], 'Any non-UNGENERATED status should replace an UNGENERATED row, not only GENERATED');

$emptyKeyMap = ['sentinel' => ['payment_id' => 'keep-me']];
$identity->rememberPaymentImportExistingRow($emptyKeyMap, [
    'payment_id' => 'payment-empty',
    'receipt_no' => '',
    'source_fingerprint' => '',
    'bank_account_no' => '',
    'payment_amount' => '0',
    'voucher_status' => 'GENERATED',
], true);
importIdentityAssertSame(
    ['sentinel' => ['payment_id' => 'keep-me']],
    $emptyKeyMap,
    'Rows without a duplicate key should not mutate the existing map'
);

$wrapperExisting = [];
$serviceExisting = [];
foreach ([$ungeneratedOne, $ungeneratedTwo, $generated, $voided] as $row) {
    $identity->rememberPaymentImportExistingRow($serviceExisting, $row, true);
    $probe->rememberExistingRow($wrapperExisting, $row, true);
}
importIdentityAssertSame(
    $serviceExisting,
    $wrapperExisting,
    'CaseFund existing-map wrapper should preserve CaseFundImportIdentity reference and status precedence'
);

$fingerprintMap = [];
$fingerprintMapRow = [
    'payment_id' => 'payment-fingerprint-map',
    'receipt_no' => 'R-FINGERPRINT-MAP',
    'source_fingerprint' => 'fp-map',
    'bank_account_no' => 'ACCOUNT-FINGERPRINT-MAP',
    'payment_amount' => '20.00',
    'voucher_status' => 'UNGENERATED',
];
$canonicalAmountCalls = [];
$identity->rememberPaymentImportExistingRow($fingerprintMap, $fingerprintMapRow, false);
importIdentityAssertSame(
    'payment-fingerprint-map',
    $fingerprintMap['fingerprint:fp-map']['payment_id'] ?? null,
    'Existing-map identity should honor disabled receipt checks and use the fingerprint branch'
);
importIdentityAssertSame([], $canonicalAmountCalls, 'Fingerprint existing-map keys should not canonicalize amounts');

$accountMap = [];
$accountMapRow = [
    'payment_id' => 'payment-account-map',
    'receipt_no' => '',
    'source_fingerprint' => 'fp-account-map',
    'bank_account_no' => ' ACCOUNT-MAP ',
    'payment_amount' => '20.005',
    'voucher_status' => 'UNGENERATED',
];
$canonicalAmountCalls = [];
$identity->rememberPaymentImportExistingRow($accountMap, $accountMapRow, true);
importIdentityAssertSame(
    'payment-account-map',
    $accountMap['account_amount:ACCOUNT-MAP|20.01']['payment_id'] ?? null,
    'Existing-map identity should preserve the account plus canonical amount branch'
);
importIdentityAssertSame(['20.005'], $canonicalAmountCalls, 'Account existing-map keys should canonicalize the original amount once');

// 四类持久化指纹：固定算法、固定字段顺序、固定 UTF-8 输入。
$paymentRow = [
    'case_no' => 'CASE-001',
    'payment_time' => '2026-05-01 10:20:30',
    'payment_amount' => '100.00',
    'receipt_no' => 'R001',
    'bank_serial_no' => 'B001',
    'payment_order_no' => 'P001',
];
$refundRow = [
    'case_no' => 'CASE-002',
    'refund_date' => '2026-05-02',
    'out_type' => '执行、调解款发放',
    'refund_amount' => '88.50',
    'source_receipt_no' => 'SR001',
    'out_order_no' => 'OUT001',
    'actual_payee_name' => '张三',
    'payee_bank_account_no' => '62220001',
];
$unknownPaymentRow = [
    'transaction_time' => '2026-05-03 09:00:00',
    'unknown_amount' => '66.00',
    'court_bank_account_no' => '21001',
    'payer_account_no' => '6222',
    'payer_name' => '李四',
    'bank_remark' => '备注',
    'bank_summary' => '摘要',
];
$distributionRow = [
    'distribution_no' => 'D001',
    'in_case_no' => 'CASE-IN',
    'out_case_no' => 'CASE-OUT',
    'invoice_type' => '执行、调解款',
    'receipt_no' => 'R002',
    'distribution_amount' => '77.70',
    'receive_date' => '2026-05-04',
];

$fingerprintCases = [
    [
        'label' => 'payment',
        'method' => 'paymentFingerprint',
        'wrapper' => 'paymentHash',
        'row' => $paymentRow,
        'expected' => '3099e4d1d6dfb205ea8883719370c64b',
        'fields' => ['case_no', 'payment_time', 'payment_amount', 'receipt_no', 'bank_serial_no', 'payment_order_no'],
    ],
    [
        'label' => 'refund',
        'method' => 'refundFingerprint',
        'wrapper' => 'refundHash',
        'row' => $refundRow,
        'expected' => '211acfd18b8a595348d4ecdffe8ef00fd4cf30ce8b582ecf6798b2e353c23d31',
        'fields' => ['case_no', 'refund_date', 'out_type', 'refund_amount', 'source_receipt_no', 'out_order_no', 'actual_payee_name', 'payee_bank_account_no'],
    ],
    [
        'label' => 'unknown payment',
        'method' => 'unknownPaymentFingerprint',
        'wrapper' => 'unknownPaymentHash',
        'row' => $unknownPaymentRow,
        'expected' => '2be19e8ea4ccb3ec773c759ed592bd374e6f1fd238a438d8bdb5b097274cb733',
        'fields' => ['transaction_time', 'unknown_amount', 'court_bank_account_no', 'payer_account_no', 'payer_name', 'bank_remark', 'bank_summary'],
    ],
    [
        'label' => 'distribution',
        'method' => 'distributionFingerprint',
        'wrapper' => 'distributionHash',
        'row' => $distributionRow,
        'expected' => '04a985a52038fb11780566d636e07e33c926c12d036958696bad478c2311278d',
        'fields' => ['distribution_no', 'in_case_no', 'out_case_no', 'invoice_type', 'receipt_no', 'distribution_amount', 'receive_date'],
    ],
];

foreach ($fingerprintCases as $case) {
    $method = $case['method'];
    $wrapper = $case['wrapper'];
    $baseHash = $identity->$method($case['row']);
    importIdentityAssertSame($case['expected'], $baseHash, ucfirst($case['label']) . ' fingerprint fixed vector should remain stable');
    importIdentityAssertSame($baseHash, $probe->$wrapper($case['row']), 'CaseFund ' . $case['label'] . ' fingerprint wrapper should match CaseFundImportIdentity');

    foreach ($case['fields'] as $field) {
        $changed = $case['row'];
        $changed[$field] = (string)$changed[$field] . '-changed';
        importIdentityAssertNotSame(
            $baseHash,
            $identity->$method($changed),
            ucfirst($case['label']) . ' fingerprint should include ' . $field
        );
    }

    $unrelated = $case['row'];
    $unrelated['account_set_id'] = 'another-account-set';
    $unrelated['fiscal_year'] = '2099';
    $unrelated['period'] = '2099-12';
    $unrelated['source_file_name'] = 'another-file.xls';
    $unrelated['source_row_no'] = 999;
    $unrelated['remark'] = 'unrelated';
    importIdentityAssertSame(
        $baseHash,
        $identity->$method($unrelated),
        ucfirst($case['label']) . ' fingerprint should ignore account scope, source metadata, period, and remarks'
    );
}

importIdentityAssertSame(
    '88572c7603bd2bb7f2bd0128fc8d65d8',
    $identity->paymentFingerprint([]),
    'Missing payment fingerprint fields should remain empty strings in their fixed positions'
);
importIdentityAssertSame(
    '543082649ab324aed040584422da1cfe576ec16860460b0c3571c2f49b2b24bb',
    $identity->refundFingerprint([]),
    'Missing refund fingerprint fields should remain empty strings in their fixed positions'
);
importIdentityAssertSame(
    '2dca6397f6798483d79ea9e0dcd45477d3fed0547a9194bef7440cfee3b2bf32',
    $identity->unknownPaymentFingerprint([]),
    'Missing unknown-payment fingerprint fields should remain empty strings in their fixed positions'
);
importIdentityAssertSame(
    '2dca6397f6798483d79ea9e0dcd45477d3fed0547a9194bef7440cfee3b2bf32',
    $identity->distributionFingerprint([]),
    'Missing distribution fingerprint fields should remain empty strings in their fixed positions'
);

importIdentityAssertSame(
    'af93886675b537fbf4e38db352f2327f',
    $identity->paymentFingerprint([
        'case_no' => ' CASE ',
        'payment_time' => ' 2026-01-01 ',
        'payment_amount' => ' 10.00 ',
        'receipt_no' => ' R ',
        'bank_serial_no' => ' B ',
        'payment_order_no' => ' P ',
    ]),
    'Payment fingerprint inputs should not be trimmed or normalized'
);
importIdentityAssertSame(
    '491c46210a2e320d444bae177fab71f464306df052c21b56077fee5cb7ff1c28',
    $identity->refundFingerprint([
        'case_no' => ' CASE ',
        'refund_date' => ' 2026-01-02 ',
        'out_type' => ' TYPE ',
        'refund_amount' => ' 11.00 ',
        'source_receipt_no' => ' SR ',
        'out_order_no' => ' OUT ',
        'actual_payee_name' => ' NAME ',
        'payee_bank_account_no' => ' ACCOUNT ',
    ]),
    'Refund fingerprint inputs should not be trimmed or normalized'
);
importIdentityAssertSame(
    '59310d3ba77044a840f3e8d0879dd56a1b633686da8ab4bcbfcbc14f56c658b4',
    $identity->unknownPaymentFingerprint([
        'transaction_time' => ' 2026-01-03 ',
        'unknown_amount' => ' 12.00 ',
        'court_bank_account_no' => ' COURT ',
        'payer_account_no' => ' PAYER ',
        'payer_name' => ' NAME ',
        'bank_remark' => ' REMARK ',
        'bank_summary' => ' SUMMARY ',
    ]),
    'Unknown-payment fingerprint inputs should not be trimmed or normalized'
);
importIdentityAssertSame(
    'ed58d65423efef1b1512c379cc27ab7b6b2f91a58ee0ba4301529a43d0e6539f',
    $identity->distributionFingerprint([
        'distribution_no' => ' D ',
        'in_case_no' => ' IN ',
        'out_case_no' => ' OUT ',
        'invoice_type' => ' TYPE ',
        'receipt_no' => ' R ',
        'distribution_amount' => ' 13.00 ',
        'receive_date' => ' 2026-01-04 ',
    ]),
    'Distribution fingerprint inputs should not be trimmed or normalized'
);

echo 'Case fund import identity test passed' . PHP_EOL;
