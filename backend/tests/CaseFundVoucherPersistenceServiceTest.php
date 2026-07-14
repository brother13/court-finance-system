<?php

require_once __DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherPersistenceService.php';

use app\finance\service\casefund\voucher\CaseFundVoucherPersistenceService;

class CaseFundVoucherPersistenceDbStub
{
    public static $events = [];
    protected $table;
    public $inserts = [];
    public $throwMessage = '';

    public function __construct($table)
    {
        $this->table = $table;
    }

    public function insert($row)
    {
        self::$events[] = $this->table;
        if ($this->throwMessage !== '') {
            throw new \RuntimeException($this->throwMessage);
        }
        $this->inserts[] = $row;
        return 1;
    }
}

function voucherPersistenceAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$dbs = [
    'fin_voucher' => new CaseFundVoucherPersistenceDbStub('fin_voucher'),
    'fin_voucher_detail' => new CaseFundVoucherPersistenceDbStub('fin_voucher_detail'),
    'fin_voucher_aux_value' => new CaseFundVoucherPersistenceDbStub('fin_voucher_aux_value'),
];
$idSequence = 0;
$prepareCount = 0;
$hooks = [
    'get_db' => function ($table) use (&$dbs) { return $dbs[$table]; },
    'account_set_id' => function () { return 'set-1'; },
    'cents_to_decimal' => function ($cents) {
        $cents = (int)$cents;
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    },
    'new_id' => function () use (&$idSequence) { return 'aux-' . (++$idSequence); },
    'prepare_create' => function ($row) use (&$prepareCount) {
        $prepareCount++;
        $row['created_by'] = 'user-1';
        $row['del_flag'] = 0;
        return $row;
    },
    'current_user_id' => function () { return 'user-1'; },
    'now' => function () { return '2026-07-15 10:00:00'; },
];

try {
    new CaseFundVoucherPersistenceService([]);
    fwrite(STDERR, 'Voucher persistence service should reject incomplete hooks' . PHP_EOL);
    exit(1);
} catch (\InvalidArgumentException $e) {
    voucherPersistenceAssertSame(
        '案款凭证持久化服务缺少回调：get_db',
        $e->getMessage(),
        'Missing persistence hook should be explicit'
    );
}

$service = new CaseFundVoucherPersistenceService($hooks);
$service->persistVoucher([
    'voucher_id' => 'voucher-1',
    'fiscal_year' => 2026,
    'period' => '2026-07',
    'voucher_date' => '2026-07-15',
    'voucher_no' => 8,
    'summary' => '收案款',
    'total_cents' => 3000,
    'status' => 'DRAFT',
], [
    [
        'detail_id' => 'detail-1',
        'line_no' => 1,
        'subject_code' => '100201',
        'summary' => '收案款',
        'debit_amount' => '30.00',
        'credit_amount' => '0.00',
        'verification_status' => 'NOT_REQUIRED',
        'aux_desc' => '案号：案一',
        'aux_values' => [
            ['aux_type_code' => 'case_no', 'aux_value' => '案一'],
            ['aux_type_code' => '', 'aux_value' => '忽略'],
            ['aux_type_code' => 'receipt_no', 'aux_value' => ''],
        ],
    ],
    [
        'detail_id' => 'detail-2',
        'line_no' => 2,
        'subject_code' => '224101',
        'summary' => '收案款',
        'debit_amount' => '0.00',
        'credit_amount' => '30.00',
        'verification_status' => 'NOT_REQUIRED',
        'aux_desc' => '',
    ],
]);

voucherPersistenceAssertSame(1, count($dbs['fin_voucher']->inserts), 'Persistence should insert one voucher header');
$voucher = $dbs['fin_voucher']->inserts[0];
voucherPersistenceAssertSame('30.00', $voucher['debit_amount'], 'Voucher debit should use integer-cent conversion');
voucherPersistenceAssertSame('30.00', $voucher['credit_amount'], 'Voucher credit should equal debit');
voucherPersistenceAssertSame('SUBMITTED', $voucher['status'], 'Generated voucher should keep submitted status');
voucherPersistenceAssertSame('BUSINESS', $voucher['source_type'], 'Generated voucher should keep business source');
voucherPersistenceAssertSame('user-1', $voucher['prepared_by'], 'Generated voucher should keep preparer');
voucherPersistenceAssertSame('set-1', $voucher['account_set_id'], 'Voucher header should use trusted account scope');
voucherPersistenceAssertSame(2, count($dbs['fin_voucher_detail']->inserts), 'Persistence should keep all detail rows');
voucherPersistenceAssertSame(
    [1, 2],
    array_column($dbs['fin_voucher_detail']->inserts, 'line_no'),
    'Persistence should preserve detail order and line numbers'
);
voucherPersistenceAssertSame('voucher-1', $dbs['fin_voucher_detail']->inserts[1]['voucher_id'], 'Detail should link voucher header');
$secondDetail = $dbs['fin_voucher_detail']->inserts[1];
voucherPersistenceAssertSame(2026, $secondDetail['fiscal_year'], 'Detail should keep voucher fiscal year');
voucherPersistenceAssertSame('2026-07', $secondDetail['period'], 'Detail should keep voucher period');
voucherPersistenceAssertSame('set-1', $secondDetail['account_set_id'], 'Detail should keep voucher account scope');
voucherPersistenceAssertSame(1, count($dbs['fin_voucher_aux_value']->inserts), 'Persistence should skip empty auxiliary values');
$aux = $dbs['fin_voucher_aux_value']->inserts[0];
voucherPersistenceAssertSame('aux-1', $aux['id'], 'Auxiliary value should receive a new id');
voucherPersistenceAssertSame('案一', $aux['aux_label'], 'Auxiliary label should default to its value');
voucherPersistenceAssertSame('detail-1', $aux['detail_id'], 'Auxiliary value should link its detail');
voucherPersistenceAssertSame('voucher-1', $aux['voucher_id'], 'Auxiliary value should link its voucher');
voucherPersistenceAssertSame(2026, $aux['fiscal_year'], 'Auxiliary value should keep voucher fiscal year');
voucherPersistenceAssertSame('2026-07', $aux['period'], 'Auxiliary value should keep voucher period');
voucherPersistenceAssertSame('set-1', $aux['account_set_id'], 'Auxiliary value should keep voucher account scope');
voucherPersistenceAssertSame(4, $prepareCount, 'Create metadata should be applied to header, details and aux values');
voucherPersistenceAssertSame(
    ['fin_voucher', 'fin_voucher_detail', 'fin_voucher_aux_value', 'fin_voucher_detail'],
    CaseFundVoucherPersistenceDbStub::$events,
    'Persistence should write header before ordered details and their auxiliary values'
);

$insertCountBeforeMissingContext = count($dbs['fin_voucher']->inserts);
try {
    $service->persistVoucher(['voucher_id' => 'voucher-2'], []);
    fwrite(STDERR, 'Voucher persistence should reject incomplete context' . PHP_EOL);
    exit(1);
} catch (\InvalidArgumentException $e) {
    voucherPersistenceAssertSame(
        '案款凭证持久化缺少字段：fiscal_year',
        $e->getMessage(),
        'Missing persistence context should be explicit'
    );
}
voucherPersistenceAssertSame(
    $insertCountBeforeMissingContext,
    count($dbs['fin_voucher']->inserts),
    'Incomplete context should fail before inserting a voucher header'
);

$dbs['fin_voucher_detail']->throwMessage = 'detail insert failed';
try {
    $service->persistVoucher([
        'voucher_id' => 'voucher-3',
        'fiscal_year' => 2026,
        'period' => '2026-07',
        'voucher_date' => '2026-07-15',
        'voucher_no' => 9,
        'summary' => '异常测试',
        'total_cents' => 100,
    ], [[
        'detail_id' => 'detail-3',
        'line_no' => 1,
        'subject_code' => '100201',
        'summary' => '异常测试',
        'debit_amount' => '1.00',
        'credit_amount' => '0.00',
        'verification_status' => 'NOT_REQUIRED',
        'aux_desc' => '',
    ]]);
    fwrite(STDERR, 'Voucher persistence should propagate database insert errors' . PHP_EOL);
    exit(1);
} catch (\RuntimeException $e) {
    voucherPersistenceAssertSame('detail insert failed', $e->getMessage(), 'Insert error should propagate to caller transaction');
}
$dbs['fin_voucher_detail']->throwMessage = '';

$source = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherPersistenceService.php');
foreach ([
    'Db::startTrans',
    'Db::commit',
    'Db::rollback',
    'logAudit',
    'generateNextVoucherNo',
    'generate_next_voucher_no',
    'fin_case_fund_',
    'voucher_status',
    'ensure_aux_archives',
] as $forbidden) {
    if (strpos($source, $forbidden) !== false) {
        fwrite(STDERR, 'Voucher persistence should not own boundary or numbering concern: ' . $forbidden . PHP_EOL);
        exit(1);
    }
}

echo 'Case fund voucher persistence service test passed' . PHP_EOL;
