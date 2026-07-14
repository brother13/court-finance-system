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
    'S-PAYMENT' => [['payment_id' => 'P-1', 'payment_amount' => '10.00']],
    'S-DUPLICATE' => [
        ['payment_id' => 'P-2', 'payment_amount' => '20.00'],
        ['payment_id' => 'P-3', 'payment_amount' => '20.00'],
    ],
];
$refundsByStatement = [
    'S-DIFF' => [['refund_id' => 'R-1', 'refund_amount' => '7.00']],
];
$paymentExistenceCalls = [];
$refundExistenceCalls = [];

$matcher = new CaseFundBankReconcileMatcher([
    'amount_to_cents' => function ($amount) {
        return (int)round((float)$amount * 100);
    },
    'payment_candidates_by_statement' => function ($statement) use ($paymentsByStatement) {
        return $paymentsByStatement[$statement['statement_id']] ?? [];
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
    'bank_statement_exists_by_refund' => function ($refund, $bankCode, $dateStart, $dateEnd) use (&$refundExistenceCalls) {
        $refundExistenceCalls[] = [$refund['refund_id'], $bankCode, $dateStart, $dateEnd];
        return $refund['refund_id'] === 'R-3';
    },
    'build_reconcile_row' => function ($statement, $bizType, $biz, $status, $bankCents, $bizCents, $matchRule) {
        return [
            'statement_id' => $statement['statement_id'] ?? '',
            'biz_type' => $bizType,
            'biz_id' => $biz[$bizType === 'PAYMENT' ? 'payment_id' : 'refund_id'] ?? '',
            'status' => $status,
            'bank_cents' => $bankCents,
            'biz_cents' => $bizCents,
            'match_rule' => $matchRule,
        ];
    },
    'biz_amount_cents' => function ($bizType, $biz) {
        $field = $bizType === 'PAYMENT' ? 'payment_amount' : 'refund_amount';
        return (int)round((float)$biz[$field] * 100);
    },
    'biz_id' => function ($bizType, $biz) {
        return $biz[$bizType === 'PAYMENT' ? 'payment_id' : 'refund_id'];
    },
]);

$result = $matcher->match([
    ['statement_id' => 'S-PAYMENT', 'direction' => 'CREDIT', 'credit_amount' => '10.00', 'debit_amount' => '0.00'],
    ['statement_id' => 'S-BANK-ONLY', 'direction' => 'DEBIT', 'credit_amount' => '0.00', 'debit_amount' => '5.00'],
    ['statement_id' => 'S-DUPLICATE', 'direction' => 'CREDIT', 'credit_amount' => '20.00', 'debit_amount' => '0.00'],
    ['statement_id' => 'S-DIFF', 'direction' => 'DEBIT', 'credit_amount' => '0.00', 'debit_amount' => '6.00'],
], 'CCB', '2026-07-01', '2026-07-31');

bankMatcherAssertSame(
    ['S-PAYMENT', 'S-BANK-ONLY', 'S-DUPLICATE', 'S-DIFF'],
    $result['statement_ids'],
    'Matcher should return statement ids in bank statement order'
);
bankMatcherAssertSame([
    'S-PAYMENT' => 'MATCHED',
    'S-BANK-ONLY' => 'BANK_ONLY',
    'S-DUPLICATE' => 'DUPLICATE',
    'S-DIFF' => 'AMOUNT_DIFF',
], $result['statement_statuses'], 'Matcher should preserve every bank statement status');
bankMatcherAssertSame(
    ['MATCHED', 'BANK_ONLY', 'DUPLICATE', 'DUPLICATE', 'AMOUNT_DIFF', 'BIZ_ONLY', 'BIZ_ONLY'],
    array_column($result['result_rows'], 'status'),
    'Matcher should preserve matched, duplicate, difference and one-sided result construction'
);
bankMatcherAssertSame(
    ['SERIAL_NO_DATE', 'PAYEE_ACCOUNT_DATE', 'SERIAL_NO_DATE', 'SERIAL_NO_DATE', 'PAYEE_ACCOUNT_DATE', 'SERIAL_NO_DATE', 'PAYEE_ACCOUNT_DATE'],
    array_column($result['result_rows'], 'match_rule'),
    'Matcher should preserve payment and refund matching rules'
);
bankMatcherAssertSame(
    [['P-4', 'CCB', '2026-07-01', '2026-07-31']],
    $paymentExistenceCalls,
    'Matcher should skip bank existence checks for already matched payments'
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
