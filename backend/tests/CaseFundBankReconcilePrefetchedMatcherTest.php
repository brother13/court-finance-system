<?php

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\service\casefund\banking\CaseFundBankReconcileMatcher;

function prefetchedMatcherFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function prefetchedMatcherAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        prefetchedMatcherFail(
            $message
            . '; expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

function prefetchedNormalizeSerial($value)
{
    return strtoupper((string)preg_replace('/[\s\-]+/u', '', trim((string)$value)));
}

function prefetchedNormalizeStatementSerial($value)
{
    $value = str_replace(['－', '—', '–'], '-', trim((string)$value));
    $position = strrpos($value, '-');
    if ($position !== false) {
        $value = substr($value, $position + 1);
    }
    return prefetchedNormalizeSerial($value);
}

function prefetchedSerialsMatch($statement, $paymentSerial)
{
    $statementSerial = prefetchedNormalizeStatementSerial($statement['bank_serial_no'] ?? '');
    $paymentSerial = prefetchedNormalizeSerial($paymentSerial);
    if ($statementSerial === '' || $paymentSerial === '') {
        return false;
    }
    return $statementSerial === $paymentSerial
        || (strtoupper((string)($statement['bank_code'] ?? '')) === 'CCB'
            && strlen($statementSerial) === 26
            && strlen($paymentSerial) === 19
            && substr($statementSerial, -19) === $paymentSerial);
}

function prefetchedNormalizeAccount($value)
{
    return strtoupper((string)preg_replace('/[^0-9A-Za-z]/u', '', trim((string)$value)));
}

function prefetchedContains($haystack, $needle)
{
    if (function_exists('mb_stripos')) {
        return mb_stripos((string)$haystack, (string)$needle, 0, 'UTF-8') !== false;
    }
    return stripos((string)$haystack, (string)$needle) !== false;
}

function prefetchedIsUnclaimed($row)
{
    $status = trim((string)($row['claim_status'] ?? ''));
    return $status === ''
        || in_array($status, ['待认领', '未认领'], true)
        || strtoupper($status) === 'UNCLAIMED';
}

$scope = [
    'statements' => [
        [
            'statement_id' => 'S-PAYMENT', 'bank_code' => 'CCB',
            'transaction_date' => '2026-07-01', 'direction' => 'CREDIT',
            'credit_amount' => '10.00', 'debit_amount' => '0.00',
            'bank_serial_no' => 'DETAIL-1234567890123456789',
        ],
        [
            'statement_id' => 'S-REUSE', 'bank_code' => 'CCB',
            'transaction_date' => '2026-07-01', 'direction' => 'CREDIT',
            'credit_amount' => '10.00', 'debit_amount' => '0.00',
            'bank_serial_no' => 'DETAIL-1234567890123456789',
        ],
        [
            'statement_id' => 'S-DUPLICATE', 'bank_code' => 'SHENGJING',
            'transaction_date' => '2026-07-02', 'direction' => 'CREDIT',
            'credit_amount' => '20.00', 'debit_amount' => '0.00',
            'bank_serial_no' => 'DETAIL-DUPSERIAL',
        ],
        [
            'statement_id' => 'S-UNKNOWN', 'bank_code' => 'CCB',
            'transaction_date' => '2026-07-03', 'direction' => 'CREDIT',
            'credit_amount' => '30.00', 'debit_amount' => '0.00',
            'bank_serial_no' => '', 'counterparty_account_no' => '62 100-1',
        ],
        [
            'statement_id' => 'S-REFUND-DIFF', 'bank_code' => 'CCB',
            'transaction_date' => '2026-07-04', 'direction' => 'DEBIT',
            'credit_amount' => '0.00', 'debit_amount' => '6.00',
            'counterparty_account_no' => 'ACC-1',
        ],
        [
            'statement_id' => 'S-REFUND-NAME', 'bank_code' => 'CCB',
            'transaction_date' => '2026-07-05', 'direction' => 'DEBIT',
            'credit_amount' => '0.00', 'debit_amount' => '9.00',
            'counterparty_account_no' => '', 'counterparty_account_name' => '张三',
        ],
        [
            'statement_id' => 'S-PAYMENT-PRIORITY', 'bank_code' => 'CCB',
            'transaction_date' => '2026-07-06', 'direction' => 'CREDIT',
            'credit_amount' => '12.00', 'debit_amount' => '0.00',
            'bank_serial_no' => 'DETAIL-PRIORITY',
            'counterparty_account_no' => 'PRIORITY-ACCOUNT',
        ],
        [
            'statement_id' => 'S-REFUND-EXISTS', 'bank_code' => 'CCB',
            'transaction_date' => '2026-07-07', 'direction' => 'DEBIT',
            'credit_amount' => '0.00', 'debit_amount' => '11.00',
            'counterparty_account_no' => '', 'counterparty_account_name' => 'Alice Smith',
        ],
        [
            'statement_id' => 'S-REFUND-AMOUNT', 'bank_code' => 'CCB',
            'transaction_date' => '2026-07-09', 'direction' => 'DEBIT',
            'credit_amount' => '0.00', 'debit_amount' => '6.00',
            'counterparty_account_no' => 'ACC-DUP', 'counterparty_account_name' => '',
        ],
    ],
    'payments' => [
        ['payment_id' => 'P-1', 'payment_date' => '2026-07-01', 'payment_amount' => '10.00', 'bank_serial_no' => '1234567890123456789'],
        ['payment_id' => 'P-2', 'payment_date' => '2026-07-02', 'payment_amount' => '20.00', 'bank_serial_no' => 'DUPSERIAL'],
        ['payment_id' => 'P-3', 'payment_date' => '2026-07-02', 'payment_amount' => '20.00', 'bank_serial_no' => 'DUPSERIAL'],
        ['payment_id' => 'P-4', 'payment_date' => '2026-07-03', 'payment_amount' => '8.00', 'bank_serial_no' => 'NO-BANK'],
        ['payment_id' => 'P-5', 'payment_date' => '2026-07-06', 'payment_amount' => '12.00', 'bank_serial_no' => 'PRIORITY'],
    ],
    'unknown_payments' => [
        ['unknown_payment_id' => 'U-1', 'transaction_date' => '2026-07-03', 'unknown_amount' => '30.00', 'payer_account_no' => '621001', 'claim_status' => '未认领'],
        ['unknown_payment_id' => 'U-2', 'transaction_date' => '2026-07-04', 'unknown_amount' => '40.00', 'payer_account_no' => 'NO-BANK', 'claim_status' => 'UNCLAIMED'],
        ['unknown_payment_id' => 'U-3', 'transaction_date' => '2026-07-06', 'unknown_amount' => '50.00', 'payer_account_no' => 'PRIORITYACCOUNT', 'claim_status' => '待认领'],
        ['unknown_payment_id' => 'U-CLAIMED', 'transaction_date' => '2026-07-03', 'unknown_amount' => '99.00', 'payer_account_no' => '621001', 'claim_status' => '已认领'],
    ],
    'refunds' => [
        ['refund_id' => 'R-1', 'refund_date' => '2026-07-04', 'refund_amount' => '7.00', 'payee_bank_account_no' => 'ACC-1'],
        ['refund_id' => 'R-2', 'refund_date' => '2026-07-05', 'refund_amount' => '9.00', 'payee_bank_account_no' => '', 'payee_bank_account_name' => '张三'],
        ['refund_id' => 'R-3', 'refund_date' => '2026-07-07', 'refund_amount' => '11.00', 'payee_bank_account_no' => '', 'payee_bank_account_name' => 'Alice'],
        ['refund_id' => 'R-4', 'refund_date' => '2026-07-08', 'refund_amount' => '13.00', 'payee_bank_account_no' => 'NO-REFUND'],
        ['refund_id' => 'R-5', 'refund_date' => '2026-07-09', 'refund_amount' => '87.00', 'payee_bank_account_no' => 'ACC-DUP'],
        ['refund_id' => 'R-6', 'refund_date' => '2026-07-09', 'refund_amount' => '6.00', 'payee_bank_account_no' => 'ACC-DUP'],
    ],
];

$nonQueryHooks = [
    'amount_to_cents' => function ($amount) {
        return (int)round((float)$amount * 100);
    },
    'build_reconcile_row' => function ($statement, $bizType, $biz, $status, $bankCents, $bizCents, $matchRule) {
        $idField = $bizType === 'PAYMENT'
            ? 'payment_id'
            : ($bizType === 'UNKNOWN_PAYMENT' ? 'unknown_payment_id' : 'refund_id');
        return [
            'statement_id' => $statement['statement_id'] ?? '',
            'biz_type' => $bizType,
            'biz_id' => $biz[$idField] ?? '',
            'match_status' => $status,
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
];

$referenceHooks = $nonQueryHooks;
$referenceHooks['payment_candidates_by_statement'] = function ($statement) use ($scope) {
    $rows = [];
    foreach ($scope['payments'] as $payment) {
        if (($payment['payment_date'] ?? '') === ($statement['transaction_date'] ?? '')
            && prefetchedSerialsMatch($statement, $payment['bank_serial_no'] ?? '')
        ) {
            $payment['__match_rule'] = 'SERIAL_NO_DATE';
            $rows[] = $payment;
        }
    }
    return $rows;
};
$referenceHooks['unknown_payment_candidates_by_statement'] = function ($statement) use ($scope) {
    $rows = [];
    $accountNo = prefetchedNormalizeAccount($statement['counterparty_account_no'] ?? '');
    foreach ($scope['unknown_payments'] as $unknownPayment) {
        if (prefetchedIsUnclaimed($unknownPayment)
            && ($unknownPayment['transaction_date'] ?? '') === ($statement['transaction_date'] ?? '')
            && $accountNo !== ''
            && prefetchedNormalizeAccount($unknownPayment['payer_account_no'] ?? '') === $accountNo
        ) {
            $unknownPayment['__match_rule'] = 'PAYER_ACCOUNT_DATE';
            $rows[] = $unknownPayment;
        }
    }
    return $rows;
};
$referenceHooks['refund_candidates_by_statement'] = function ($statement) use ($scope) {
    $rows = [];
    $accountNo = trim((string)($statement['counterparty_account_no'] ?? ''));
    $accountName = trim((string)($statement['counterparty_account_name'] ?? ''));
    foreach ($scope['refunds'] as $refund) {
        if (($refund['refund_date'] ?? '') !== ($statement['transaction_date'] ?? '')) {
            continue;
        }
        if (($accountNo !== '' && trim((string)($refund['payee_bank_account_no'] ?? '')) === $accountNo)
            || ($accountNo === '' && $accountName !== ''
                && (prefetchedContains($refund['payee_bank_account_name'] ?? '', $accountName)
                    || prefetchedContains($refund['actual_payee_name'] ?? '', $accountName)))
        ) {
            $rows[] = $refund;
        }
    }
    return $rows;
};
$referenceHooks['payment_candidates_in_scope'] = function () use ($scope) {
    return $scope['payments'];
};
$referenceHooks['unknown_payment_candidates_in_scope'] = function () use ($scope) {
    return array_values(array_filter($scope['unknown_payments'], 'prefetchedIsUnclaimed'));
};
$referenceHooks['refund_candidates_in_scope'] = function () use ($scope) {
    return $scope['refunds'];
};
$referenceHooks['bank_statement_exists_by_payment'] = function ($payment) use ($scope) {
    foreach ($scope['statements'] as $statement) {
        if (($statement['direction'] ?? '') === 'CREDIT'
            && ($statement['transaction_date'] ?? '') === ($payment['payment_date'] ?? '')
            && prefetchedSerialsMatch($statement, $payment['bank_serial_no'] ?? '')
        ) {
            return true;
        }
    }
    return false;
};
$referenceHooks['bank_statement_exists_by_unknown_payment'] = function ($unknownPayment) use ($scope) {
    foreach ($scope['statements'] as $statement) {
        if (($statement['direction'] ?? '') === 'CREDIT'
            && ($statement['transaction_date'] ?? '') === ($unknownPayment['transaction_date'] ?? '')
            && prefetchedNormalizeAccount($statement['counterparty_account_no'] ?? '')
                === prefetchedNormalizeAccount($unknownPayment['payer_account_no'] ?? '')
        ) {
            return true;
        }
    }
    return false;
};
$referenceHooks['bank_statement_exists_by_refund'] = function ($refund) use ($scope) {
    $accountNo = trim((string)($refund['payee_bank_account_no'] ?? ''));
    $accountName = trim((string)($refund['payee_bank_account_name'] ?? ''));
    if ($accountName === '') {
        $accountName = trim((string)($refund['actual_payee_name'] ?? ''));
    }
    foreach ($scope['statements'] as $statement) {
        if (($statement['direction'] ?? '') !== 'DEBIT'
            || ($statement['transaction_date'] ?? '') !== ($refund['refund_date'] ?? '')
            || (int)round((float)($statement['debit_amount'] ?? 0) * 100)
                !== (int)round((float)($refund['refund_amount'] ?? 0) * 100)
        ) {
            continue;
        }
        if (($accountNo !== '' && trim((string)($statement['counterparty_account_no'] ?? '')) === $accountNo)
            || ($accountNo === '' && $accountName !== ''
                && prefetchedContains($statement['counterparty_account_name'] ?? '', $accountName))
        ) {
            return true;
        }
    }
    return false;
};

$referenceMatcher = new CaseFundBankReconcileMatcher($referenceHooks);
$referenceResult = $referenceMatcher->match(
    $scope['statements'],
    '',
    '2026-07-01',
    '2026-07-31'
);

$unexpectedQueryCalls = 0;
$prefetchedHooks = $nonQueryHooks;
foreach ([
    'payment_candidates_by_statement',
    'unknown_payment_candidates_by_statement',
    'refund_candidates_by_statement',
    'payment_candidates_in_scope',
    'unknown_payment_candidates_in_scope',
    'refund_candidates_in_scope',
    'bank_statement_exists_by_payment',
    'bank_statement_exists_by_unknown_payment',
    'bank_statement_exists_by_refund',
] as $hookName) {
    $prefetchedHooks[$hookName] = function () use (&$unexpectedQueryCalls) {
        $unexpectedQueryCalls++;
        return [];
    };
}
$prefetchedMatcher = new CaseFundBankReconcileMatcher($prefetchedHooks);
$prefetchedResult = $prefetchedMatcher->matchPrefetched(
    $scope,
    '',
    '2026-07-01',
    '2026-07-31'
);

prefetchedMatcherAssertSame(
    $referenceResult,
    $prefetchedResult,
    'Prefetched and query-backed matchers must use the same allocation rules'
);
prefetchedMatcherAssertSame(0, $unexpectedQueryCalls, 'Prefetched matcher must not call row-by-row query hooks');
prefetchedMatcherAssertSame(
    [
        'S-PAYMENT' => 'DUPLICATE',
        'S-REUSE' => 'DUPLICATE',
        'S-DUPLICATE' => 'DUPLICATE',
        'S-UNKNOWN' => 'MATCHED',
        'S-REFUND-DIFF' => 'AMOUNT_DIFF',
        'S-REFUND-NAME' => 'MATCHED',
        'S-PAYMENT-PRIORITY' => 'MATCHED',
        'S-REFUND-EXISTS' => 'BANK_ONLY',
        'S-REFUND-AMOUNT' => 'MATCHED',
    ],
    $prefetchedResult['statement_statuses'],
    'Prefetched matcher should cover serial suffix, reuse, duplicate, unknown payment and refund rules'
);
prefetchedMatcherAssertSame(
    ['P-4', 'U-2', 'U-3', 'R-3', 'R-4', 'R-5'],
    array_values(array_map(function ($row) {
        return $row['biz_id'];
    }, array_filter($prefetchedResult['result_rows'], function ($row) {
        return $row['match_status'] === 'BIZ_ONLY';
    }))),
    'Prefetched matcher must retain unrepresented business even if a bank candidate exists'
);

try {
    $prefetchedMatcher->matchPrefetched(['statements' => []], '', '', '');
    prefetchedMatcherFail('Incomplete prefetch scope should be rejected');
} catch (InvalidArgumentException $e) {
    prefetchedMatcherAssertSame(
        '银行对账预取范围缺少数据集：payments',
        $e->getMessage(),
        'Incomplete prefetch scope error should identify the missing dataset'
    );
}

echo 'Case fund bank reconcile prefetched matcher test passed' . PHP_EOL;
