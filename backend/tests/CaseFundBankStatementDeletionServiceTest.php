<?php

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\service\casefund\banking\CaseFundBankStatementDeletionService;

function bankDeletionAssertSame($expected, $actual, $message)
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
    new CaseFundBankStatementDeletionService([]);
    fwrite(STDERR, 'Bank statement deletion service should reject incomplete hook configuration' . PHP_EOL);
    exit(1);
} catch (\InvalidArgumentException $e) {
    bankDeletionAssertSame(
        '案款银行流水删除服务缺少回调：unmatched_statements_by_ids',
        $e->getMessage(),
        'Bank statement deletion service should report the first missing hook'
    );
}

$reconcileCalls = 0;
$missingService = new CaseFundBankStatementDeletionService([
    'unmatched_statements_by_ids' => function ($ids) {
        bankDeletionAssertSame(['S-1', 'S-MISSING'], $ids, 'Deletion lookup should receive normalized statement ids');
        return [['statement_id' => 'S-1', 'reconcile_status' => 'UNMATCHED']];
    },
    'active_reconcile_count' => function () use (&$reconcileCalls) {
        $reconcileCalls++;
        return 0;
    },
]);
$missing = $missingService->prepare(['S-1', 'S-MISSING']);
bankDeletionAssertSame('只能删除未对账的银行对账单流水', $missing['error'], 'Missing or non-unmatched rows should reject the entire deletion');
bankDeletionAssertSame([], $missing['rows'], 'Rejected deletion should expose no rows to the write phase');
bankDeletionAssertSame(0, $reconcileCalls, 'Row-count rejection should avoid a redundant reconcile lookup');

$rows = [
    ['statement_id' => 'S-1', 'reconcile_status' => 'UNMATCHED'],
    ['statement_id' => 'S-2', 'reconcile_status' => 'UNMATCHED'],
];
$reconciledService = new CaseFundBankStatementDeletionService([
    'unmatched_statements_by_ids' => function () use ($rows) {
        return $rows;
    },
    'active_reconcile_count' => function ($ids) {
        bankDeletionAssertSame(['S-1', 'S-2'], $ids, 'Reconcile lookup should use the same deletion scope');
        return 1;
    },
]);
$reconciled = $reconciledService->prepare(['S-1', 'S-2']);
bankDeletionAssertSame('只能删除未对账的银行对账单流水', $reconciled['error'], 'Active reconcile rows should block statement deletion');
bankDeletionAssertSame([], $reconciled['rows'], 'Reconciled statements should not reach the write phase');

$validService = new CaseFundBankStatementDeletionService([
    'unmatched_statements_by_ids' => function () use ($rows) {
        return $rows;
    },
    'active_reconcile_count' => function () {
        return 0;
    },
]);
$valid = $validService->prepare(['S-1', 'S-2']);
bankDeletionAssertSame('', $valid['error'], 'Unmatched statements without reconcile rows should be deletable');
bankDeletionAssertSame($rows, $valid['rows'], 'Validated rows should be preserved for audit and deletion');

$serviceSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/banking/CaseFundBankStatementDeletionService.php');
foreach (['getdb(', 'think\\Db', 'Db::', '->delete(', '->insert(', '->update(', 'startTrans', 'logAudit'] as $writeNeedle) {
    if (strpos($serviceSource, $writeNeedle) !== false) {
        fwrite(STDERR, 'Bank statement deletion service should remain write-free: ' . $writeNeedle . PHP_EOL);
        exit(1);
    }
}

echo 'Case fund bank statement deletion service test passed' . PHP_EOL;
