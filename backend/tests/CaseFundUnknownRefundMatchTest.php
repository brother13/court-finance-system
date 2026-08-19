<?php

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\service\casefund\returning\CaseFundUnknownRefundMatchService;

function returnMatchAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            $message . '; expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL
        );
        exit(1);
    }
}

function returnMatchCents($amount)
{
    $normalized = str_replace([',', ' '], '', trim((string)$amount));
    $parts = explode('.', $normalized, 2);
    $yuan = (int)($parts[0] ?? 0);
    $cent = str_pad(preg_replace('/\D/', '', $parts[1] ?? ''), 2, '0');
    return $yuan * 100 + (int)substr($cent, 0, 2);
}

$service = new CaseFundUnknownRefundMatchService([
    'get_db' => function () {
        throw new RuntimeException('Pure matcher test must not query a database');
    },
    'account_set_id' => function () {
        return 'account-return-match';
    },
    'current_year' => function () {
        return 2026;
    },
    'new_id' => function () {
        return 'match-id';
    },
    'prepare_create' => function ($row) {
        return $row;
    },
    'prepare_update' => function ($row) {
        return $row;
    },
    'decimal_to_cents' => function ($amount) {
        return returnMatchCents($amount);
    },
    'now' => function () {
        return '2026-08-11 12:00:00';
    },
    'user_id' => function () {
        return 'return-user';
    },
], [
    'unknown_payment' => 'unknown',
    'refund' => 'refund',
    'match' => 'match',
]);

$unknownRows = [
    ['unknown_payment_id' => 'U1', 'period' => '2026-06', 'payer_name' => '张 三', 'unknown_amount' => '100.00', 'payer_account_no' => '6222-0001', 'transaction_date' => '2026-06-10'],
    ['unknown_payment_id' => 'U2', 'period' => '2026-06', 'payer_name' => '', 'unknown_amount' => '200.00', 'payer_account_no' => '62220002', 'transaction_date' => '2026-06-10'],
    ['unknown_payment_id' => 'U3', 'period' => '2026-06', 'payer_name' => '李四', 'unknown_amount' => '300.00', 'payer_account_no' => '', 'transaction_date' => '2026-06-10'],
    ['unknown_payment_id' => 'U4', 'period' => '2026-06', 'payer_name' => '王五', 'unknown_amount' => '400.00', 'payer_account_no' => '62220004', 'transaction_date' => '2026-06-11'],
    ['unknown_payment_id' => 'U5', 'period' => '2026-06', 'payer_name' => '王五', 'unknown_amount' => '400.00', 'payer_account_no' => '62220004', 'transaction_date' => '2026-06-12'],
    ['unknown_payment_id' => 'U6', 'period' => '2026-06', 'payer_name' => '赵六', 'unknown_amount' => '500.00', 'payer_account_no' => '62220006', 'transaction_date' => '2026-06-15'],
    ['unknown_payment_id' => 'U7', 'period' => '2026-06', 'payer_name' => '孙七', 'unknown_amount' => '600.00', 'payer_account_no' => '62220007', 'transaction_date' => '2026-06-16'],
    ['unknown_payment_id' => 'U8', 'period' => '2026-06', 'payer_name' => '周八', 'unknown_amount' => '700.00', 'payer_account_no' => '62220008', 'transaction_date' => '2026-06-17'],
    ['unknown_payment_id' => 'U9', 'period' => '2026-06', 'payer_name' => '吴九', 'unknown_amount' => '800.00', 'payer_account_no' => '62220009', 'transaction_date' => '2026-06-18'],
];

$refundRows = [
    ['refund_id' => 'R1', 'period' => '2026-06', 'actual_payee_name' => '张三', 'refund_amount' => '100', 'payee_bank_account_no' => '6222 0001', 'refund_date' => '2026-06-09'],
    ['refund_id' => 'R2', 'period' => '2026-06', 'actual_payee_name' => '王五', 'refund_amount' => '400.00', 'payee_bank_account_no' => '62220004', 'refund_date' => '2026-06-10'],
    ['refund_id' => 'R3', 'period' => '2026-06', 'actual_payee_name' => '赵六', 'refund_amount' => '500.00', 'payee_bank_account_no' => '62220006', 'refund_date' => '2026-06-20'],
    ['refund_id' => 'R4', 'period' => '2026-06', 'actual_payee_name' => '孙七', 'refund_amount' => '600.00', 'payee_bank_account_no' => '62220007', 'refund_date' => '2026-06-15'],
    ['refund_id' => 'R5', 'period' => '2026-06', 'actual_payee_name' => '周八', 'refund_amount' => '700.00', 'payee_bank_account_no' => '62220008', 'refund_date' => '2026-06-16'],
    ['refund_id' => 'R6', 'period' => '2026-06', 'actual_payee_name' => '吴九', 'refund_amount' => '800.00', 'payee_bank_account_no' => '62220009', 'refund_date' => '2026-06-16'],
    ['refund_id' => 'R7', 'period' => '2026-06', 'actual_payee_name' => '吴九', 'refund_amount' => '800.00', 'payee_bank_account_no' => '62220009', 'refund_date' => '2026-06-17'],
];

$decisionRows = [
    ['unknown_payment_id' => 'U7', 'refund_id' => 'R4', 'match_status' => 'REJECTED', 'del_flag' => 0],
    ['unknown_payment_id' => 'U8', 'refund_id' => 'R5', 'match_status' => 'CONFIRMED', 'del_flag' => 0],
];

$result = $service->matchRows($unknownRows, $refundRows, $decisionRows);
returnMatchAssertSame([
    'total_unknown' => 9,
    'ineligible' => 2,
    'unmatched' => 1,
    'auto_candidate' => 1,
    'ambiguous' => 3,
    'confirmed' => 1,
    'rejected' => 1,
    'candidate_pairs' => 5,
], $result['stats'], 'Strict return-match statistics should distinguish skipped, unique, ambiguous, confirmed and rejected rows');

$statusByPair = [];
foreach ($result['pairs'] as $pair) {
    $statusByPair[$pair['unknown_payment_id'] . '|' . $pair['refund_id']] = $pair['match_status'];
}
returnMatchAssertSame('AUTO_CANDIDATE', $statusByPair['U1|R1'] ?? '', 'Space and hyphen normalization should retain an exact unique candidate');
returnMatchAssertSame('AMBIGUOUS', $statusByPair['U4|R2'] ?? '', 'One refund matching multiple unknown payments must be ambiguous');
returnMatchAssertSame('AMBIGUOUS', $statusByPair['U9|R6'] ?? '', 'One unknown payment matching multiple refunds must be ambiguous');
returnMatchAssertSame(false, isset($statusByPair['U6|R3']), 'A refund dated after the incoming unknown payment must not match');
returnMatchAssertSame(false, isset($statusByPair['U7|R4']), 'A manually rejected pair must not be recreated by scanning');
returnMatchAssertSame(false, isset($statusByPair['U8|R5']), 'A confirmed pair must not be recalculated as a candidate');

$caseFundSource = file_get_contents(__DIR__ . '/../app/finance/model/CaseFund.php');
$ddlSource = file_get_contents(__DIR__ . '/../database/court_finance_ddl.sql');
foreach ([
    "case 'unknownRefundMatchPreview'" => 'CaseFund should route return-match previews',
    "case 'unknownRefundMatchScan'" => 'CaseFund should route return-match scans',
    "case 'unknownRefundMatchConfirm'" => 'CaseFund should route return-match confirmations',
    "requirePermission('case_fund:return_match')" => 'Return-match writes should require their own permission',
] as $needle => $message) {
    returnMatchAssertSame(true, strpos($caseFundSource, $needle) !== false, $message);
}
returnMatchAssertSame(
    true,
    strpos($ddlSource, 'create table fin_case_fund_unknown_refund_match') !== false,
    'Core DDL should contain the auditable return-match relation table'
);

echo "Case fund unknown-refund match test passed\n";
