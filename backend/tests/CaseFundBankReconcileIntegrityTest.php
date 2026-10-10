<?php

require_once __DIR__ . '/../app/finance/service/casefund/banking/CaseFundBankReconcileMatcher.php';

use app\finance\service\casefund\banking\CaseFundBankReconcileMatcher;

function integrityAssert($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
}

$cents = function ($value) {
    $parts = explode('.', (string)$value, 2);
    return (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
};
$idFields = ['PAYMENT' => 'payment_id', 'UNKNOWN_PAYMENT' => 'unknown_payment_id', 'REFUND' => 'refund_id'];
$amountFields = ['PAYMENT' => 'payment_amount', 'UNKNOWN_PAYMENT' => 'unknown_amount', 'REFUND' => 'refund_amount'];
$hooks = [
    'amount_to_cents' => $cents,
    'biz_id' => function ($type, $row) use ($idFields) { return $row[$idFields[$type]]; },
    'biz_amount_cents' => function ($type, $row) use ($amountFields, $cents) { return $cents($row[$amountFields[$type]]); },
    'build_reconcile_row' => function ($statement, $type, $biz, $status, $bankCents, $bizCents, $rule) use ($idFields) {
        return [
            'statement_id' => $statement['statement_id'] ?? '', 'biz_type' => $type,
            'biz_id' => $biz[$idFields[$type]] ?? '', 'match_status' => $status,
            'bank_cents' => $bankCents, 'biz_cents' => $bizCents, 'match_rule' => $rule,
        ];
    },
];
foreach (['payment_candidates_by_statement', 'unknown_payment_candidates_by_statement', 'refund_candidates_by_statement',
    'payment_candidates_in_scope', 'unknown_payment_candidates_in_scope', 'refund_candidates_in_scope',
    'bank_statement_exists_by_payment', 'bank_statement_exists_by_unknown_payment', 'bank_statement_exists_by_refund'] as $name) {
    $hooks[$name] = function () { throw new RuntimeException('Unexpected database query'); };
}
$matcher = new CaseFundBankReconcileMatcher($hooks);
$empty = ['statements' => [], 'payments' => [], 'unknown_payments' => [], 'refunds' => []];
$bank = function ($id, $amount, $direction = 'CREDIT') {
    return ['statement_id' => $id, 'bank_code' => 'CCB', 'transaction_date' => '2026-05-13',
        'direction' => $direction, 'credit_amount' => $direction === 'CREDIT' ? $amount : '0.00',
        'debit_amount' => $direction === 'DEBIT' ? $amount : '0.00',
        'counterparty_account_no' => '123456', 'bank_serial_no' => 'SERIAL001'];
};
$unknown = function ($id, $amount) {
    return ['unknown_payment_id' => $id, 'transaction_date' => '2026-05-13',
        'unknown_amount' => $amount, 'payer_account_no' => '123456', 'claim_status' => 'UNCLAIMED'];
};
$run = function ($scope) use ($matcher) {
    $result = $matcher->matchPrefetched($scope, '', '2026-05-01', '2026-05-31');
    $used = [];
    foreach ($result['result_rows'] as $row) {
        if ($row['match_status'] !== 'MATCHED') { continue; }
        $key = $row['biz_type'] . ':' . $row['biz_id'];
        integrityAssert(false, isset($used[$key]), 'A business must not be matched to two statements');
        integrityAssert($row['bank_cents'], $row['biz_cents'], 'Confirmed amounts must agree');
        $used[$key] = true;
    }
    return $result;
};

$scope = $empty;
$scope['statements'] = [$bank('B1', '100.00', 'DEBIT'), $bank('B2', '100.00', 'DEBIT')];
$scope['refunds'] = [['refund_id' => 'R1', 'refund_date' => '2026-05-13', 'refund_amount' => '100.00', 'payee_bank_account_no' => '123456']];
$result = $run($scope);
integrityAssert(['B1' => 'DUPLICATE', 'B2' => 'DUPLICATE'], $result['statement_statuses'], 'Competing equal payments need review, not arbitrary first-row confirmation');

$scope = $empty;
$scope['statements'] = [$bank('B100', '100.00'), $bank('B200', '200.00')];
$scope['unknown_payments'] = [$unknown('U200', '200.00')];
foreach ([$scope['statements'], array_reverse($scope['statements'])] as $statements) {
    $scope['statements'] = $statements;
    $result = $run($scope);
    integrityAssert('MATCHED', $result['statement_statuses']['B200'], 'Amount difference must not reserve the exact later match');
    integrityAssert('BANK_ONLY', $result['statement_statuses']['B100'], 'Confirmed business must not remain a difference candidate elsewhere');
}

$scope['statements'] = [$bank('B50', '50.00'), $bank('B100', '100.00'), $bank('B200', '200.00')];
$scope['unknown_payments'] = [$unknown('U100', '100.00'), $unknown('U200', '200.00')];
for ($i = 0; $i < 3; $i++) {
    $result = $run($scope);
    integrityAssert('MATCHED', $result['statement_statuses']['B100'], 'Ambiguous wrong-amount candidates must not occupy business');
    integrityAssert('MATCHED', $result['statement_statuses']['B200'], 'All unique exact matches must survive statement order');
    $scope['statements'][] = array_shift($scope['statements']);
}

$scope = $empty;
$scope['payments'] = [['payment_id' => 'P-NO-SERIAL', 'payment_date' => '2026-05-13', 'payment_amount' => '100.00', 'bank_serial_no' => '']];
$result = $run($scope);
integrityAssert(['P-NO-SERIAL'], array_column($result['result_rows'], 'biz_id'), 'Missing serial must remain visible for investigation');
integrityAssert('BIZ_ONLY', $result['result_rows'][0]['match_status'], 'Missing serial must not be automatically matched');
$scope['payments'][0]['payment_method'] = 'UNKNOWN';
integrityAssert('BIZ_ONLY', $run($scope)['result_rows'][0]['match_status'], 'Claim without source link remains pending investigation, not silently reconciled');

$scope['payments'][0]['bank_serial_no'] = 'SERIAL001';
$scope['statements'] = [$bank('B1', '100.00')];
$scope['unknown_payments'] = [$unknown('U-UNLINKED', '100.00')];
$result = $run($scope);
$businessOnly = array_values(array_filter($result['result_rows'], function ($row) { return $row['match_status'] === 'BIZ_ONLY'; }));
integrityAssert(['U-UNLINKED'], array_column($businessOnly, 'biz_id'), 'An existing bank candidate is not proof that another business was reconciled');

// One-sided rows must not duplicate business already visible in an unresolved candidate result.
$scope = $empty;
$scope['statements'] = [$bank('B1', '50.00')];
$scope['unknown_payments'] = [$unknown('U100', '100.00')];
integrityAssert(['AMOUNT_DIFF'], array_column($run($scope)['result_rows'], 'match_status'), 'Difference stays visible once without reserving business');

echo "Case fund bank reconcile integrity test passed\n";
