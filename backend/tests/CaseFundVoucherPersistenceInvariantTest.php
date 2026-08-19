<?php

require_once __DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherPersistenceService.php';

use app\finance\service\casefund\voucher\CaseFundVoucherPersistenceService;

class CaseFundVoucherInvariantDbStub
{
    public $insertResult = 1;
    public $insertCount = 0;

    public function insert($row)
    {
        $this->insertCount++;
        return $this->insertResult;
    }
}

function voucherInvariantFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function voucherInvariantContext()
{
    return [
        'voucher_id' => 'voucher-1',
        'fiscal_year' => 2026,
        'period' => '2026-07',
        'voucher_date' => '2026-07-15',
        'voucher_no' => 1,
        'summary' => '案款凭证',
        'total_cents' => 100,
    ];
}

function voucherInvariantDetails()
{
    return [
        [
            'detail_id' => 'detail-1',
            'line_no' => 1,
            'subject_code' => '100201',
            'summary' => '案款凭证',
            'debit_amount' => '1.00',
            'credit_amount' => '0.00',
            'verification_status' => 'NOT_REQUIRED',
            'aux_desc' => '',
            'aux_values' => [
                ['aux_type_code' => 'case_no', 'aux_value' => '案一'],
            ],
        ],
        [
            'detail_id' => 'detail-2',
            'line_no' => 2,
            'subject_code' => '224101',
            'summary' => '案款凭证',
            'debit_amount' => '0.00',
            'credit_amount' => '1.00',
            'verification_status' => 'NOT_REQUIRED',
            'aux_desc' => '',
        ],
    ];
}

function voucherInvariantFixture(array $insertResults = [])
{
    $dbs = [
        'fin_voucher' => new CaseFundVoucherInvariantDbStub(),
        'fin_voucher_detail' => new CaseFundVoucherInvariantDbStub(),
        'fin_voucher_aux_value' => new CaseFundVoucherInvariantDbStub(),
    ];
    foreach ($insertResults as $table => $result) {
        $dbs[$table]->insertResult = $result;
    }
    $id = 0;
    $service = new CaseFundVoucherPersistenceService([
        'get_db' => function ($table) use (&$dbs) { return $dbs[$table]; },
        'account_set_id' => function () { return 'set-1'; },
        'cents_to_decimal' => function ($cents) {
            $cents = (int)$cents;
            return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
        },
        'new_id' => function () use (&$id) { return 'aux-' . (++$id); },
        'prepare_create' => function ($row) { return $row; },
        'current_user_id' => function () { return 'user-1'; },
        'now' => function () { return '2026-07-15 10:00:00'; },
    ]);
    return [$service, $dbs];
}

function voucherInvariantAssertRejected($name, $expectedMessage, callable $mutate)
{
    list($service, $dbs) = voucherInvariantFixture();
    $context = voucherInvariantContext();
    $details = voucherInvariantDetails();
    $mutate($context, $details);
    try {
        $service->persistVoucher($context, $details);
        voucherInvariantFail($name . ' should be rejected');
    } catch (\InvalidArgumentException $e) {
        if ($e->getMessage() !== $expectedMessage) {
            voucherInvariantFail(
                $name . '; expected ' . $expectedMessage . ', got ' . $e->getMessage()
            );
        }
    }
    foreach ($dbs as $table => $db) {
        if ($db->insertCount !== 0) {
            voucherInvariantFail($name . ' should fail before inserting ' . $table);
        }
    }
}

$invalidCases = [
    [
        'zero total',
        '凭证金额必须大于0',
        function (&$context, &$details) { $context['total_cents'] = 0; },
    ],
    [
        'float total',
        '凭证金额必须使用整数分',
        function (&$context, &$details) { $context['total_cents'] = 100.0; },
    ],
    [
        'single detail',
        '凭证明细至少需要两行',
        function (&$context, &$details) { $details = [$details[0]]; },
    ],
    [
        'scalar detail',
        '凭证明细第1行格式不正确',
        function (&$context, &$details) { $details[0] = 'invalid'; },
    ],
    [
        'both debit and credit',
        '凭证明细第1行必须且只能填写借方或贷方金额',
        function (&$context, &$details) { $details[0]['credit_amount'] = '1.00'; },
    ],
    [
        'negative amount',
        '凭证明细第1行金额不能为负数',
        function (&$context, &$details) { $details[0]['debit_amount'] = '-1.00'; },
    ],
    [
        'zero-sided detail',
        '凭证明细第1行必须且只能填写借方或贷方金额',
        function (&$context, &$details) { $details[0]['debit_amount'] = '0.00'; },
    ],
    [
        'unbalanced voucher',
        '凭证借贷金额不平衡',
        function (&$context, &$details) { $details[1]['credit_amount'] = '0.99'; },
    ],
    [
        'header mismatch',
        '凭证头金额与明细合计不一致',
        function (&$context, &$details) { $context['total_cents'] = 200; },
    ],
    [
        'duplicate detail id',
        '凭证明细ID重复：detail-1',
        function (&$context, &$details) { $details[1]['detail_id'] = 'detail-1'; },
    ],
    [
        'duplicate line number',
        '凭证明细行号重复：1',
        function (&$context, &$details) { $details[1]['line_no'] = 1; },
    ],
    [
        'zero line number',
        '凭证明细第1行行号必须为正整数',
        function (&$context, &$details) { $details[0]['line_no'] = 0; },
    ],
    [
        'fraction precision',
        '凭证明细第1行借方金额格式不正确',
        function (&$context, &$details) { $details[0]['debit_amount'] = '1.001'; },
    ],
    [
        'float detail amount',
        '凭证明细第1行借方金额格式不正确',
        function (&$context, &$details) { $details[0]['debit_amount'] = 1.0; },
    ],
    [
        'duplicate auxiliary type',
        '凭证明细第1行辅助核算维度重复：case_no',
        function (&$context, &$details) {
            $details[0]['aux_values'][] = ['aux_type_code' => ' case_no ', 'aux_value' => '案二'];
        },
    ],
    [
        'invalid auxiliary values',
        '凭证明细第1行辅助核算值格式不正确',
        function (&$context, &$details) { $details[0]['aux_values'] = 'case_no'; },
    ],
    [
        'total cents overflow',
        '凭证金额超出整数范围',
        function (&$context, &$details) { $context['total_cents'] = '999999999999999999999'; },
    ],
    [
        'detail amount overflow',
        '凭证明细第1行借方金额超出整数分范围',
        function (&$context, &$details) { $details[0]['debit_amount'] = '999999999999999999.99'; },
    ],
];

foreach ($invalidCases as $case) {
    voucherInvariantAssertRejected($case[0], $case[1], $case[2]);
}

$insertFailures = [
    ['fin_voucher', '凭证头写入失败', 1, 0, 0],
    ['fin_voucher_detail', '凭证明细批量写入失败：voucher-1', 1, 1, 0],
    ['fin_voucher_aux_value', '凭证辅助核算值批量写入失败：voucher-1', 1, 2, 1],
];
foreach ($insertFailures as $case) {
    list($service, $dbs) = voucherInvariantFixture([$case[0] => 0]);
    try {
        $service->persistVoucher(voucherInvariantContext(), voucherInvariantDetails());
        voucherInvariantFail($case[0] . ' zero affected rows should be rejected');
    } catch (\RuntimeException $e) {
        if ($e->getMessage() !== $case[1]) {
            voucherInvariantFail($case[0] . ' error message mismatch: ' . $e->getMessage());
        }
    }
    if ($dbs['fin_voucher']->insertCount !== $case[2]
        || $dbs['fin_voucher_detail']->insertCount !== $case[3]
        || $dbs['fin_voucher_aux_value']->insertCount !== $case[4]
    ) {
        voucherInvariantFail($case[0] . ' should stop immediately after failed insert');
    }
}

foreach ([2, true, '1', 1.0] as $invalidInsertResult) {
    list($service, $dbs) = voucherInvariantFixture(['fin_voucher' => $invalidInsertResult]);
    try {
        $service->persistVoucher(voucherInvariantContext(), voucherInvariantDetails());
        voucherInvariantFail('Non-integer-one insert result should be rejected');
    } catch (\RuntimeException $e) {
        if ($e->getMessage() !== '凭证头写入失败') {
            voucherInvariantFail('Strict insert result error message mismatch: ' . $e->getMessage());
        }
    }
}

list($service, $dbs) = voucherInvariantFixture();
$context = voucherInvariantContext();
$context['total_cents'] = '100';
$details = voucherInvariantDetails();
$details[0]['debit_amount'] = '1.0';
$details[1]['credit_amount'] = '1';
$details[0]['aux_values'][] = ['aux_type_code' => 'receipt_no', 'aux_value' => '   '];
$service->persistVoucher($context, $details);
if ($dbs['fin_voucher']->insertCount !== 1
    || $dbs['fin_voucher_detail']->insertCount !== 2
    || $dbs['fin_voucher_aux_value']->insertCount !== 1
) {
    voucherInvariantFail('Valid numeric strings should persist and blank auxiliary values should be skipped');
}

echo 'Case fund voucher persistence invariant test passed' . PHP_EOL;
