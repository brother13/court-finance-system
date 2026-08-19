<?php

require_once __DIR__ . '/../app/finance/service/casefund/voucher/UnknownPaymentAuxiliaryCaseService.php';

use app\finance\service\casefund\voucher\UnknownPaymentAuxiliaryCaseService;

function unknownAuxAssertSame($expected, $actual, $message)
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

$suffixes = ['A82F193B', 'C0FFEE01', 'FACEB00C'];
$persisted = [];
$service = new UnknownPaymentAuxiliaryCaseService([
    'load_occupied_codes' => function () {
        return [
            '张三｜执行款退回｜BMK-A82F193B',
            'BMK-DEADBEEF',
        ];
    },
    'persist_code' => function ($unknownPaymentId, $code) use (&$persisted) {
        $persisted[] = [$unknownPaymentId, $code];
        return 1;
    },
    'random_hex' => function () use (&$suffixes) {
        return array_shift($suffixes);
    },
]);

$importRows = $service->assignImportCodes([
    [
        'unknown_payment_id' => '',
        'source_fingerprint' => 'fingerprint-1',
        'payer_name' => '张三',
        'bank_remark' => '执行款退回',
    ],
]);
unknownAuxAssertSame(
    'BMK-C0FFEE01',
    $importRows[0]['aux_case_code'],
    'Import allocation should retry when a manual opening or voucher already occupies a BMK code'
);
unknownAuxAssertSame([], $persisted, 'Import allocation should leave persistence to the normal import insert');
unknownAuxAssertSame(
    '张三｜执行款退回｜BMK-C0FFEE01',
    $service->buildCaseNo($importRows[0]),
    'Unknown-payment case auxiliary value should combine payer, bank remark, and stable code'
);

$generatedRows = $service->ensurePersistedCodes([
    [
        'unknown_payment_id' => 'unknown-1',
        'source_fingerprint' => 'fingerprint-2',
        'payer_name' => '',
        'bank_remark' => '',
        'aux_case_code' => '',
    ],
    [
        'unknown_payment_id' => 'unknown-2',
        'source_fingerprint' => 'fingerprint-3',
        'payer_name' => '李四',
        'bank_remark' => '',
        'aux_case_code' => 'BMK-1234ABCD',
    ],
]);
unknownAuxAssertSame('BMK-FACEB00C', $generatedRows[0]['aux_case_code'], 'Generation should allocate a missing stable code');
unknownAuxAssertSame([['unknown-1', 'BMK-FACEB00C']], $persisted, 'Generation should persist only newly allocated codes');
unknownAuxAssertSame('BMK-FACEB00C', $generatedRows[0]['unknown_aux_case_no'], 'Blank payer and remark should leave the BMK code as the complete case value');
unknownAuxAssertSame('李四｜BMK-1234ABCD', $generatedRows[1]['unknown_aux_case_no'], 'Blank remark should be omitted without an empty separator');
unknownAuxAssertSame('BMK-1234ABCD', $generatedRows[1]['aux_case_code'], 'Existing persisted code should remain stable during regeneration');

$longValue = $service->buildCaseNo([
    'payer_name' => str_repeat('甲', 100),
    'bank_remark' => str_repeat('乙', 255),
    'aux_case_code' => 'BMK-ABCDEF12',
]);
unknownAuxAssertSame(200, mb_strlen($longValue, 'UTF-8'), 'Composite auxiliary value should fit the voucher auxiliary value column');
unknownAuxAssertSame(true, substr($longValue, -12) === 'BMK-ABCDEF12', 'Length limiting must never truncate the BMK code');
unknownAuxAssertSame(true, strpos($longValue, str_repeat('甲', 100)) === 0, 'Length limiting should truncate bank remark before payer name');

try {
    $service->buildCaseNo(['aux_case_code' => 'MANUAL-1']);
    fwrite(STDERR, 'Invalid BMK codes should be rejected' . PHP_EOL);
    exit(1);
} catch (InvalidArgumentException $e) {
    unknownAuxAssertSame('不明款辅助编号格式不正确：MANUAL-1', $e->getMessage(), 'Invalid code error should be explicit');
}

$retrySuffixes = ['11111111', '22222222'];
$persistAttempts = [];
$retryService = new UnknownPaymentAuxiliaryCaseService([
    'load_occupied_codes' => function () { return []; },
    'persist_code' => function ($unknownPaymentId, $code) use (&$persistAttempts) {
        $persistAttempts[] = [$unknownPaymentId, $code];
        return count($persistAttempts) === 1 ? 0 : 1;
    },
    'random_hex' => function () use (&$retrySuffixes) { return array_shift($retrySuffixes); },
]);
$retriedRows = $retryService->ensurePersistedCodes([[
    'unknown_payment_id' => 'unknown-race',
    'aux_case_code' => '',
    'payer_name' => '',
    'bank_remark' => '',
]]);
unknownAuxAssertSame(
    'BMK-22222222',
    $retriedRows[0]['aux_case_code'],
    'Generation should retry when a concurrent insert occupies the first candidate code'
);
unknownAuxAssertSame(2, count($persistAttempts), 'Concurrent code collision should be retried without reusing the failed candidate');

echo 'Unknown-payment auxiliary case service test passed' . PHP_EOL;
