<?php

require_once __DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherAuxiliaryService.php';

use app\finance\service\casefund\voucher\CaseFundVoucherAuxiliaryService;

class CaseFundVoucherAuxiliaryStore
{
    public $events = [];
    public $types = [];
    public $archives = [];
    public $insertResults = [];

    public function db($table)
    {
        return new CaseFundVoucherAuxiliaryQuery($this, $table);
    }

    public function nextInsertResult($table)
    {
        if (!array_key_exists($table, $this->insertResults)) {
            return 1;
        }
        if (is_array($this->insertResults[$table])) {
            return array_shift($this->insertResults[$table]);
        }
        return $this->insertResults[$table];
    }
}

class CaseFundVoucherAuxiliaryQuery
{
    protected $store;
    protected $table;
    protected $where = [];
    protected $locked = false;
    protected $fields = [];

    public function __construct($store, $table)
    {
        $this->store = $store;
        $this->table = $table;
    }

    public function where($field, $operator = null, $value = null)
    {
        if (is_array($field)) {
            foreach ($field as $name => $item) {
                $this->where[$name] = ['=', $item];
            }
        } elseif (func_num_args() === 2) {
            $this->where[$field] = ['=', $operator];
        } else {
            $this->where[$field] = [$operator, $value];
        }
        return $this;
    }

    public function lock($lock = true)
    {
        $this->locked = $lock;
        return $this;
    }

    public function field($fields)
    {
        $this->fields = (array)$fields;
        return $this;
    }

    public function find()
    {
        $this->remember('find');
        if ($this->table !== 'fin_aux_type') {
            return null;
        }
        $key = $this->value('account_set_id') . '|' . $this->value('aux_type_code');
        return $this->store->types[$key] ?? null;
    }

    public function select()
    {
        $this->remember('select');
        if ($this->table !== 'fin_aux_archive') {
            return [];
        }
        $accountSetId = $this->value('account_set_id');
        $typeCode = $this->value('aux_type_code');
        $codes = $this->value('archive_code', []);
        $delFlag = $this->value('del_flag', null);
        $rows = [];
        foreach ((array)$codes as $code) {
            $key = $accountSetId . '|' . $typeCode . '|' . $code;
            if (isset($this->store->archives[$key])) {
                $row = $this->store->archives[$key];
                if ($delFlag !== null && (int)($row['del_flag'] ?? 0) !== (int)$delFlag) {
                    continue;
                }
                $rows[] = [
                    'archive_code' => $code,
                    'del_flag' => $row['del_flag'] ?? 0,
                ];
            }
        }
        return $rows;
    }

    public function insert($row)
    {
        $this->remember('insert', $row);
        $result = $this->store->nextInsertResult($this->table);
        if ($result !== 1) {
            return $result;
        }
        if ($this->table === 'fin_aux_type') {
            $key = $row['account_set_id'] . '|' . $row['aux_type_code'];
            $this->store->types[$key] = $row;
        } elseif ($this->table === 'fin_aux_archive') {
            $key = $row['account_set_id'] . '|' . $row['aux_type_code'] . '|' . $row['archive_code'];
            $this->store->archives[$key] = $row;
        }
        return $result;
    }

    protected function value($field, $default = '')
    {
        if (!array_key_exists($field, $this->where)) {
            return $default;
        }
        return $this->where[$field][1];
    }

    protected function remember($operation, $row = null)
    {
        $this->store->events[] = [
            'table' => $this->table,
            'operation' => $operation,
            'where' => $this->where,
            'locked' => $this->locked,
            'fields' => $this->fields,
            'row' => $row,
        ];
    }
}

function voucherAuxiliaryFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function voucherAuxiliaryFixture($store = null)
{
    $store = $store ?: new CaseFundVoucherAuxiliaryStore();
    $id = 0;
    $service = new CaseFundVoucherAuxiliaryService([
        'get_db' => function ($table) use ($store) { return $store->db($table); },
        'account_set_id' => function () { return 'set-1'; },
        'new_id' => function () use (&$id) { return 'aux-' . (++$id); },
        'prepare_create' => function ($row) {
            $row['created_by'] = 'user-1';
            $row['created_time'] = '2026-07-15 10:00:00';
            $row['updated_by'] = 'user-1';
            $row['updated_time'] = '2026-07-15 10:00:00';
            $row['del_flag'] = 0;
            return $row;
        },
    ]);
    return [$service, $store];
}

try {
    new CaseFundVoucherAuxiliaryService([]);
    voucherAuxiliaryFail('Incomplete auxiliary hooks should be rejected');
} catch (\InvalidArgumentException $e) {
    if ($e->getMessage() !== '案款凭证辅助档案服务缺少回调：get_db') {
        voucherAuxiliaryFail('Missing auxiliary hook error should be explicit');
    }
}

list($service, $store) = voucherAuxiliaryFixture();
$created = $service->ensureArchivesForVoucherDetails([
    [
        'aux_values' => [
            ['aux_type_code' => 'case_no', 'aux_value' => '（2026）执2号'],
            ['aux_type_code' => ' case_no ', 'aux_value' => ' （2026）执1号 '],
            ['aux_type_code' => 'case_no', 'aux_value' => '（2026）执2号'],
            ['aux_type_code' => 'receipt_no', 'aux_value' => ' R-001 '],
            ['aux_type_code' => 'party_name', 'aux_value' => '张三'],
            ['aux_type_code' => 'receipt_no', 'aux_value' => '   '],
        ],
    ],
]);
if ($created !== 3 || count($store->types) !== 2 || count($store->archives) !== 3) {
    voucherAuxiliaryFail('Auxiliary values should be trimmed, deduplicated and limited to supported types');
}

$lockedTypes = [];
$caseArchiveInsertOrder = [];
foreach ($store->events as $event) {
    if ($event['table'] === 'fin_aux_type' && $event['operation'] === 'find') {
        if (!$event['locked']) {
            voucherAuxiliaryFail('Auxiliary type lookup must use a row lock');
        }
        $lockedTypes[] = $event['where']['aux_type_code'][1] ?? '';
    }
    if ($event['operation'] === 'insert') {
        if (($event['row']['account_set_id'] ?? '') !== 'set-1'
            || ($event['row']['created_by'] ?? '') !== 'user-1'
            || ($event['row']['del_flag'] ?? null) !== 0
        ) {
            voucherAuxiliaryFail('Auxiliary inserts should use trusted account scope and common audit fields');
        }
        if ($event['table'] === 'fin_aux_archive'
            && ($event['row']['aux_type_code'] ?? '') === 'case_no'
        ) {
            $caseArchiveInsertOrder[] = $event['row']['archive_code'];
        }
    }
    if ($event['table'] === 'fin_aux_archive'
        && $event['operation'] === 'select'
        && !$event['locked']
    ) {
        voucherAuxiliaryFail('Auxiliary archive existence checks must use a current locking read');
    }
}
if ($caseArchiveInsertOrder !== ['（2026）执1号', '（2026）执2号']) {
    voucherAuxiliaryFail('Auxiliary archive inserts should use stable string order');
}

$deletedTypeStore = new CaseFundVoucherAuxiliaryStore();
$deletedTypeStore->types['set-1|case_no'] = [
    'account_set_id' => 'set-1',
    'aux_type_code' => 'case_no',
    'del_flag' => 1,
];
list($deletedTypeService) = voucherAuxiliaryFixture($deletedTypeStore);
try {
    $deletedTypeService->ensureArchivesForVoucherDetails([
        ['aux_values' => [['aux_type_code' => 'case_no', 'aux_value' => '案一']]],
    ]);
    voucherAuxiliaryFail('Soft-deleted auxiliary types should not be inserted again against the unique key');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== '辅助核算类型已删除，不能自动制证：case_no') {
        voucherAuxiliaryFail('Soft-deleted auxiliary type error should be explicit');
    }
}

$deletedArchiveStore = new CaseFundVoucherAuxiliaryStore();
$deletedArchiveStore->types['set-1|case_no'] = ['aux_type_code' => 'case_no', 'del_flag' => 0];
$deletedArchiveStore->archives['set-1|case_no|案一'] = [
    'archive_code' => '案一',
    'del_flag' => 1,
];
list($deletedArchiveService) = voucherAuxiliaryFixture($deletedArchiveStore);
$recreated = $deletedArchiveService->ensureArchivesForVoucherDetails([
    ['aux_values' => [['aux_type_code' => 'case_no', 'aux_value' => '案一']]],
]);
if ($recreated !== 1
    || (int)($deletedArchiveStore->archives['set-1|case_no|案一']['del_flag'] ?? 1) !== 0
) {
    voucherAuxiliaryFail('Soft-deleted auxiliary archive codes should retain the original recreate semantics');
}
if ($lockedTypes !== ['case_no', 'receipt_no']) {
    voucherAuxiliaryFail('Auxiliary types must be locked in fixed case_no then receipt_no order');
}
if (!isset($store->archives['set-1|case_no|（2026）执1号'])
    || !isset($store->archives['set-1|receipt_no|R-001'])
) {
    voucherAuxiliaryFail('Trimmed auxiliary archive codes should be persisted');
}

$eventCount = count($store->events);
$createdAgain = $service->ensureArchivesForVoucherDetails([
    ['aux_values' => [
        ['aux_type_code' => 'case_no', 'aux_value' => '（2026）执1号'],
        ['aux_type_code' => 'receipt_no', 'aux_value' => 'R-001'],
    ]],
]);
if ($createdAgain !== 0 || count($store->archives) !== 3) {
    voucherAuxiliaryFail('Existing auxiliary archives should not be inserted again');
}
foreach (array_slice($store->events, $eventCount) as $event) {
    if ($event['operation'] === 'insert') {
        voucherAuxiliaryFail('Repeated auxiliary preparation should perform no inserts');
    }
}

foreach ([
    ['details' => ['invalid'], 'message' => '凭证明细第1行格式不正确'],
    ['details' => [['aux_values' => 'invalid']], 'message' => '凭证明细第1行辅助核算值格式不正确'],
    ['details' => [['aux_values' => ['invalid']]], 'message' => '凭证明细第1行第1个辅助核算值格式不正确'],
] as $case) {
    list($invalidService, $invalidStore) = voucherAuxiliaryFixture();
    try {
        $invalidService->ensureArchivesForVoucherDetails($case['details']);
        voucherAuxiliaryFail('Malformed auxiliary details should be rejected');
    } catch (\InvalidArgumentException $e) {
        if ($e->getMessage() !== $case['message'] || !empty($invalidStore->events)) {
            voucherAuxiliaryFail('Malformed auxiliary details should fail before database access');
        }
    }
}

$typeFailureStore = new CaseFundVoucherAuxiliaryStore();
$typeFailureStore->insertResults['fin_aux_type'] = 0;
list($typeFailureService) = voucherAuxiliaryFixture($typeFailureStore);
try {
    $typeFailureService->ensureArchivesForVoucherDetails([
        ['aux_values' => [['aux_type_code' => 'case_no', 'aux_value' => '案一']]],
    ]);
    voucherAuxiliaryFail('Zero-row auxiliary type insert should fail');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== '辅助核算类型写入失败：case_no') {
        voucherAuxiliaryFail('Auxiliary type insert failure should identify the type');
    }
}

$archiveFailureStore = new CaseFundVoucherAuxiliaryStore();
$archiveFailureStore->types['set-1|case_no'] = ['aux_type_code' => 'case_no'];
$archiveFailureStore->insertResults['fin_aux_archive'] = false;
list($archiveFailureService) = voucherAuxiliaryFixture($archiveFailureStore);
try {
    $archiveFailureService->ensureArchivesForVoucherDetails([
        ['aux_values' => [['aux_type_code' => 'case_no', 'aux_value' => '案一']]],
    ]);
    voucherAuxiliaryFail('Failed auxiliary archive insert should fail');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== '辅助核算档案写入失败：case_no/案一') {
        voucherAuxiliaryFail('Auxiliary archive insert failure should identify the archive');
    }
}

$serviceSource = file_get_contents(__DIR__ . '/../app/finance/service/casefund/voucher/CaseFundVoucherAuxiliaryService.php');
foreach (['Db::startTrans', 'Db::commit', 'Db::rollback', 'logAudit'] as $forbidden) {
    if (strpos($serviceSource, $forbidden) !== false) {
        voucherAuxiliaryFail('Auxiliary preparation must remain inside the caller transaction: ' . $forbidden);
    }
}
$modelSource = file_get_contents(__DIR__ . '/../app/finance/model/CaseFund.php');
if (substr_count($modelSource, 'voucherAuxiliaryService()->ensureArchivesForVoucherDetails') !== 4) {
    voucherAuxiliaryFail('Payment, refund, unknown-payment and distribution generation must share the auxiliary service');
}
foreach ([
    'PaymentVoucherGenerationService.php',
    'RefundVoucherGenerationService.php',
    'UnknownPaymentVoucherGenerationService.php',
    'DistributionVoucherGenerationService.php',
] as $generationFile) {
    $generationSource = file_get_contents(
        __DIR__ . '/../app/finance/service/casefund/voucher/' . $generationFile
    );
    $lockPosition = strpos($generationSource, "callHook('ensure_aux_archives'");
    $numberPosition = strpos($generationSource, "callHook('generate_next_voucher_no'");
    if ($lockPosition === false || $numberPosition === false || $numberPosition >= $lockPosition) {
        voucherAuxiliaryFail($generationFile . ' must allocate voucher numbers before preparing the complete auxiliary scope');
    }
}

echo 'Case fund voucher auxiliary service test passed' . PHP_EOL;
