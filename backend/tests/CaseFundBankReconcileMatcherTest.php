<?php

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\service\casefund\banking\CaseFundBankReconcileMatcher;

function bankMatcherAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
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

try {
    new CaseFundBankReconcileMatcher([]);
    fwrite(STDERR, 'Bank reconcile matcher should reject incomplete hook configuration' . PHP_EOL);
    exit(1);
} catch (\InvalidArgumentException $e) {
    bankMatcherAssertSame(
        '案款银行对账匹配器缺少回调：amount_to_cents',
        $e->getMessage(),
        'Bank reconcile matcher should report the first missing hook'
    );
}

$paymentsByStatement = [
    'S-PAYMENT' => [[
        'payment_id' => 'P-1',
        'payment_amount' => '10.00',
        '__match_rule' => 'SERIAL_NO_DATE',
    ]],
    // 同一业务记录不能被第二条银行流水再次占用。
    'S-REUSE' => [[
        'payment_id' => 'P-1',
        'payment_amount' => '10.00',
        '__match_rule' => 'SERIAL_NO_DATE',
    ]],
    'S-DUPLICATE' => [
        ['payment_id' => 'P-2', 'payment_amount' => '20.00', '__match_rule' => 'SERIAL_NO_DATE'],
        ['payment_id' => 'P-3', 'payment_amount' => '20.00', '__match_rule' => 'SERIAL_NO_DATE'],
    ],
];
$unknownPaymentsByStatement = [
    'S-UNKNOWN' => [[
        'unknown_payment_id' => 'U-1',
        'unknown_amount' => '30.00',
        '__match_rule' => 'PAYER_ACCOUNT_DATE',
    ]],
];
$refundsByStatement = [
    'S-DIFF' => [['refund_id' => 'R-1', 'refund_amount' => '7.00']],
];
$paymentExistenceCalls = [];
$unknownPaymentExistenceCalls = [];
$refundExistenceCalls = [];

$matcher = new CaseFundBankReconcileMatcher([
    'amount_to_cents' => function ($amount) {
        return (int)round((float)$amount * 100);
    },
    'payment_candidates_by_statement' => function ($statement) use ($paymentsByStatement) {
        return $paymentsByStatement[$statement['statement_id']] ?? [];
    },
    'unknown_payment_candidates_by_statement' => function ($statement) use ($unknownPaymentsByStatement) {
        return $unknownPaymentsByStatement[$statement['statement_id']] ?? [];
    },
    'refund_candidates_by_statement' => function ($statement) use ($refundsByStatement) {
        return $refundsByStatement[$statement['statement_id']] ?? [];
    },
    'payment_candidates_in_scope' => function ($dateStart, $dateEnd) {
        bankMatcherAssertSame('2026-07-01', $dateStart, 'Payment scope should receive reconcile start date');
        bankMatcherAssertSame('2026-07-31', $dateEnd, 'Payment scope should receive reconcile end date');
        return [
            ['payment_id' => 'P-1', 'payment_amount' => '10.00'],
            ['payment_id' => 'P-2', 'payment_amount' => '20.00'],
            ['payment_id' => 'P-3', 'payment_amount' => '20.00'],
            ['payment_id' => 'P-4', 'payment_amount' => '8.00'],
        ];
    },
    'unknown_payment_candidates_in_scope' => function ($dateStart, $dateEnd) {
        bankMatcherAssertSame('2026-07-01', $dateStart, 'Unknown-payment scope should receive reconcile start date');
        bankMatcherAssertSame('2026-07-31', $dateEnd, 'Unknown-payment scope should receive reconcile end date');
        return [
            ['unknown_payment_id' => 'U-1', 'unknown_amount' => '30.00'],
            ['unknown_payment_id' => 'U-2', 'unknown_amount' => '40.00'],
            ['unknown_payment_id' => 'U-3', 'unknown_amount' => '50.00'],
        ];
    },
    'refund_candidates_in_scope' => function () {
        return [
            ['refund_id' => 'R-1', 'refund_amount' => '7.00'],
            ['refund_id' => 'R-2', 'refund_amount' => '9.00'],
            ['refund_id' => 'R-3', 'refund_amount' => '11.00'],
        ];
    },
    'bank_statement_exists_by_payment' => function ($payment, $bankCode, $dateStart, $dateEnd) use (&$paymentExistenceCalls) {
        $paymentExistenceCalls[] = [$payment['payment_id'], $bankCode, $dateStart, $dateEnd];
        return false;
    },
    'bank_statement_exists_by_unknown_payment' => function ($unknownPayment, $bankCode, $dateStart, $dateEnd) use (&$unknownPaymentExistenceCalls) {
        $unknownPaymentExistenceCalls[] = [$unknownPayment['unknown_payment_id'], $bankCode, $dateStart, $dateEnd];
        return $unknownPayment['unknown_payment_id'] === 'U-3';
    },
    'bank_statement_exists_by_refund' => function ($refund, $bankCode, $dateStart, $dateEnd) use (&$refundExistenceCalls) {
        $refundExistenceCalls[] = [$refund['refund_id'], $bankCode, $dateStart, $dateEnd];
        return $refund['refund_id'] === 'R-3';
    },
    'build_reconcile_row' => function ($statement, $bizType, $biz, $status, $bankCents, $bizCents, $matchRule) {
        $idField = $bizType === 'PAYMENT'
            ? 'payment_id'
            : ($bizType === 'UNKNOWN_PAYMENT' ? 'unknown_payment_id' : 'refund_id');
        return [
            'statement_id' => $statement['statement_id'] ?? '',
            'biz_type' => $bizType,
            'biz_id' => $biz[$idField] ?? '',
            'status' => $status,
            'bank_cents' => $bankCents,
            'biz_cents' => $bizCents,
            'match_rule' => $matchRule,
        ];
    },
    'biz_amount_cents' => function ($bizType, $biz) {
        $field = $bizType === 'PAYMENT'
            ? 'payment_amount'
            : ($bizType === 'UNKNOWN_PAYMENT' ? 'unknown_amount' : 'refund_amount');
        return (int)round((float)$biz[$field] * 100);
    },
    'biz_id' => function ($bizType, $biz) {
        $field = $bizType === 'PAYMENT'
            ? 'payment_id'
            : ($bizType === 'UNKNOWN_PAYMENT' ? 'unknown_payment_id' : 'refund_id');
        return $biz[$field];
    },
]);

$result = $matcher->match([
    ['statement_id' => 'S-PAYMENT', 'direction' => 'CREDIT', 'credit_amount' => '10.00', 'debit_amount' => '0.00'],
    ['statement_id' => 'S-REUSE', 'direction' => 'CREDIT', 'credit_amount' => '10.00', 'debit_amount' => '0.00'],
    ['statement_id' => 'S-BANK-ONLY', 'direction' => 'DEBIT', 'credit_amount' => '0.00', 'debit_amount' => '5.00'],
    ['statement_id' => 'S-DUPLICATE', 'direction' => 'CREDIT', 'credit_amount' => '20.00', 'debit_amount' => '0.00'],
    ['statement_id' => 'S-UNKNOWN', 'direction' => 'CREDIT', 'credit_amount' => '30.00', 'debit_amount' => '0.00'],
    ['statement_id' => 'S-DIFF', 'direction' => 'DEBIT', 'credit_amount' => '0.00', 'debit_amount' => '6.00'],
], 'CCB', '2026-07-01', '2026-07-31');

bankMatcherAssertSame(
    ['S-PAYMENT', 'S-REUSE', 'S-BANK-ONLY', 'S-DUPLICATE', 'S-UNKNOWN', 'S-DIFF'],
    $result['statement_ids'],
    'Matcher should return statement ids in bank statement order'
);
bankMatcherAssertSame([
    'S-PAYMENT' => 'MATCHED',
    'S-REUSE' => 'BANK_ONLY',
    'S-BANK-ONLY' => 'BANK_ONLY',
    'S-DUPLICATE' => 'DUPLICATE',
    'S-UNKNOWN' => 'MATCHED',
    'S-DIFF' => 'AMOUNT_DIFF',
], $result['statement_statuses'], 'Matcher should preserve every bank statement status');
bankMatcherAssertSame(
    ['MATCHED', 'BANK_ONLY', 'BANK_ONLY', 'DUPLICATE', 'DUPLICATE', 'MATCHED', 'AMOUNT_DIFF', 'BIZ_ONLY', 'BIZ_ONLY', 'BIZ_ONLY'],
    array_column($result['result_rows'], 'status'),
    'Matcher should preserve one-to-one, unknown-payment, duplicate, difference and one-sided results'
);
bankMatcherAssertSame(
    ['SERIAL_NO_DATE', 'SERIAL_NO_DATE', 'PAYEE_ACCOUNT_DATE', 'SERIAL_NO_DATE', 'SERIAL_NO_DATE', 'PAYER_ACCOUNT_DATE', 'PAYEE_ACCOUNT_DATE', 'SERIAL_NO_DATE', 'PAYER_ACCOUNT_DATE', 'PAYEE_ACCOUNT_DATE'],
    array_column($result['result_rows'], 'match_rule'),
    'Matcher should preserve payment, unknown-payment and refund matching rules'
);
bankMatcherAssertSame(
    ['PAYMENT', 'PAYMENT', 'REFUND', 'PAYMENT', 'PAYMENT', 'UNKNOWN_PAYMENT', 'REFUND', 'PAYMENT', 'UNKNOWN_PAYMENT', 'REFUND'],
    array_column($result['result_rows'], 'biz_type'),
    'Matcher should keep income sources distinguishable in reconcile results'
);
bankMatcherAssertSame(
    [['P-4', 'CCB', '2026-07-01', '2026-07-31']],
    $paymentExistenceCalls,
    'Matcher should skip bank existence checks for already matched payments'
);
bankMatcherAssertSame(
    [
        ['U-2', 'CCB', '2026-07-01', '2026-07-31'],
        ['U-3', 'CCB', '2026-07-01', '2026-07-31'],
    ],
    $unknownPaymentExistenceCalls,
    'Matcher should only check unmatched unclaimed unknown payments and suppress existing bank rows'
);
bankMatcherAssertSame(
    [
        ['R-2', 'CCB', '2026-07-01', '2026-07-31'],
        ['R-3', 'CCB', '2026-07-01', '2026-07-31'],
    ],
    $refundExistenceCalls,
    'Matcher should only check unmatched refunds and suppress existing bank rows'
);

echo 'Case fund bank reconcile matcher test passed' . PHP_EOL;
