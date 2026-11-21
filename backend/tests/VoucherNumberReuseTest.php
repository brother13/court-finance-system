<?php

require_once __DIR__ . '/../app/finance/service/voucher/VoucherNumberService.php';

use app\finance\service\voucher\VoucherNumberService;

/** State and transaction boundary only; the number selection logic is the real service. */
class VoucherNumberReuseStore
{
    public $tables = ['fin_voucher' => [], 'fin_voucher_no_sequence' => []];
    public $events = [];
    public $snapshots = [];
    public $failOperation = '';
    public $nextId = 0;

    public function db($table) { return new VoucherNumberReuseQuery($this, $table); }
    public function begin() { $this->snapshots[] = $this->tables; }
    public function commit() { array_pop($this->snapshots); }
    public function rollback() { $this->tables = array_pop($this->snapshots); }

    public function assertUnique($table, array $rows)
    {
        $seen = [];
        $primary = [];
        foreach ($rows as $row) {
            $id = $row[$table === 'fin_voucher' ? 'voucher_id' : 'sequence_id'];
            if (isset($primary[$id])) throw new RuntimeException('Duplicate primary key: ' . $table . ':' . $id);
            $primary[$id] = true;
            $key = implode('|', [$row['account_set_id'], $row['fiscal_year'], $row['period']]);
            if ($table === 'fin_voucher') $key .= '|' . $row['voucher_no'];
            if (isset($seen[$key])) throw new RuntimeException('Duplicate physical unique key: ' . $table . ':' . $key);
            $seen[$key] = true;
        }
    }
}

class VoucherNumberReuseQuery
{
    private $store;
    private $table;
    private $conditions = [];
    private $locked = false;

    public function __construct($store, $table) { $this->store = $store; $this->table = $table; }
    public function field($fields) { return $this; }
    public function order($order) { return $this; }
    public function lock($locked = true) { $this->locked = $locked; return $this; }

    public function where($field, $operator = null, $value = null)
    {
        if (is_array($field)) {
            foreach ($field as $name => $expected) $this->conditions[] = [$name, '=', $expected];
        } elseif (func_num_args() === 2) {
            $this->conditions[] = [$field, '=', $operator];
        } else {
            $this->conditions[] = [$field, strtolower($operator), $value];
        }
        return $this;
    }

    private function matches($row)
    {
        foreach ($this->conditions as [$field, $operator, $expected]) {
            $actual = $row[$field] ?? null;
            switch ($operator) {
                case '=': $matched = $actual == $expected; break;
                case '>': $matched = $actual > $expected; break;
                case '>=': $matched = $actual >= $expected; break;
                case '<': $matched = $actual < $expected; break;
                case '<=': $matched = $actual <= $expected; break;
                case 'in': $matched = in_array($actual, (array)$expected, true); break;
                default: throw new RuntimeException('Unsupported query operator in number test: ' . $operator);
            }
            if (!$matched) return false;
        }
        return true;
    }

    private function event($operation, $row = null)
    {
        $this->store->events[] = ['table' => $this->table, 'operation' => $operation, 'locked' => $this->locked, 'row' => $row];
    }

    public function select()
    {
        $this->event('select');
        return array_values(array_filter($this->store->tables[$this->table], function ($row) { return $this->matches($row); }));
    }

    public function find() { $rows = $this->select(); return $rows[0] ?? null; }
    public function count() { return count($this->select()); }

    public function insert($row)
    {
        $this->event('insert', $row);
        if ($this->store->failOperation === $this->table . ':insert') return false;
        $rows = $this->store->tables[$this->table];
        $rows[] = $row;
        $this->store->assertUnique($this->table, $rows);
        $this->store->tables[$this->table] = $rows;
        return 1;
    }

    public function update($values)
    {
        $this->event('update', $values);
        if ($this->store->failOperation === $this->table . ':update') return false;
        $rows = $this->store->tables[$this->table];
        $affected = 0;
        foreach ($rows as &$row) {
            if ($this->matches($row)) {
                $updated = array_merge($row, $values);
                $affected += $updated !== $row ? 1 : 0;
                $row = $updated;
            }
        }
        unset($row);
        $this->store->assertUnique($this->table, $rows);
        $this->store->tables[$this->table] = $rows;
        return $affected;
    }
}

function numberReuseService($store, $accountSetId = 'set-1')
{
    return new VoucherNumberService([
        'get_db' => function ($table) use ($store) { return $store->db($table); },
        'account_set_id' => function () use ($accountSetId) { return $accountSetId; },
        'fiscal_year' => function ($period) { return (int)substr($period, 0, 4); },
        'prepare_create' => function ($row) {
            return array_merge($row, ['created_by' => 'user-1', 'updated_by' => 'user-1', 'del_flag' => 0]);
        },
        'prepare_update' => function ($row) { return array_merge($row, ['updated_by' => 'user-1', 'updated_time' => '2026-11-16 12:00:00']); },
        'new_id' => function () use ($store) { return sprintf('00000000-0000-4000-8000-%012d', ++$store->nextId); },
    ]);
}

function numberReuseAssert($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . '; expected ' . var_export($expected, true) . ', actual ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function numberReuseVoucher($number, $values = [])
{
    return array_merge([
        'voucher_id' => 'V-' . $number, 'account_set_id' => 'set-1', 'fiscal_year' => 2026,
        'period' => '2026-05', 'voucher_no' => $number, 'del_flag' => 0,
        'status' => 'SUBMITTED', 'remark' => '',
    ], $values);
}

function numberReuseSequence($currentNo, $values = [])
{
    return array_merge([
        'sequence_id' => 'SEQ-1', 'account_set_id' => 'set-1', 'fiscal_year' => 2026,
        'period' => '2026-05', 'current_no' => $currentNo, 'del_flag' => 0,
    ], $values);
}

function numberReuseFixture($active = [], $sequence = null)
{
    $store = new VoucherNumberReuseStore();
    $store->tables['fin_voucher'] = array_map('numberReuseVoucher', $active);
    if ($sequence !== null) $store->tables['fin_voucher_no_sequence'][] = numberReuseSequence($sequence);
    return $store;
}

function numberReusePersist($store, array $numbers, $scope = [])
{
    foreach ($numbers as $number) {
        $scope['voucher_id'] = 'INSERTED-' . ++$store->nextId;
        $store->db('fin_voucher')->insert(numberReuseVoucher($number, $scope));
    }
}

// Preview is a pure read, including the first-ever preview with no sequence row.
$store = numberReuseFixture();
$service = numberReuseService($store);
$before = $store->tables;
numberReuseAssert(1, $service->peek('2026-05'), 'Empty period must start at voucher 1');
numberReuseAssert(1, $service->peek('2026-05'), 'Repeated previews must not consume voucher numbers');
numberReuseAssert($before, $store->tables, 'Preview must not create a sequence or modify voucher data');
foreach ($store->events as $event) {
    numberReuseAssert('select', $event['operation'], 'Preview must only read');
    numberReuseAssert(false, $event['locked'], 'Preview must not acquire a write lock');
}
$store->begin();
numberReuseAssert([1, 2, 3], $service->allocate('2026-05', 3), 'New period batch must allocate unique consecutive numbers');
numberReuseAssert(3, $store->tables['fin_voucher_no_sequence'][0]['current_no'], 'New period sequence must retain batch maximum');
numberReusePersist($store, [1, 2, 3]);
$store->commit();

// Deleted numbers are reusable, including the physical-key conflict in a soft-deleted row.
$store = numberReuseFixture([1, 3], 3);
$store->tables['fin_voucher'][] = numberReuseVoucher(2, ['del_flag' => 1, 'remark' => '历史记录']);
$store->tables['fin_voucher'][] = numberReuseVoucher(-1, ['del_flag' => 1]);
$store->tables['fin_voucher'][] = numberReuseVoucher(-2, ['del_flag' => 1]);
$store->tables['fin_voucher'][] = numberReuseVoucher(99, ['del_flag' => 1]);
$service = numberReuseService($store);
$before = $store->tables;
numberReuseAssert(2, $service->peek('2026-05'), 'Active vouchers 1 and 3 must preview the reusable gap 2');
numberReuseAssert($before, $store->tables, 'Gap preview must not move deleted voucher rows');
$store->events = [];
$store->begin();
numberReuseAssert([2], $service->allocate('2026-05'), 'Deleted voucher 2 must not reserve its positive number');
$deleted = $store->tables['fin_voucher'][2];
numberReuseAssert(true, $deleted['voucher_no'] < 0 && !in_array($deleted['voucher_no'], [-1, -2], true), 'Soft-deleted number must move to an unused negative number');
numberReuseAssert(1, $deleted['del_flag'], 'Number reuse must preserve the deleted row');
numberReuseAssert(true, strpos($deleted['remark'], '历史记录') !== false && strpos($deleted['remark'], '2') !== false, 'Deleted voucher remark must retain its content and original positive number');
numberReuseAssert(99, $store->tables['fin_voucher'][5]['voucher_no'], 'Unallocated deleted numbers must remain untouched');
numberReusePersist($store, [2]); // Enforces the real DDL unique key across both active and deleted rows.
$store->commit();
$reads = array_values(array_filter($store->events, function ($event) { return $event['operation'] === 'select'; }));
numberReuseAssert('fin_voucher_no_sequence', $reads[0]['table'], 'Allocation must lock the sequence before scanning vouchers');
numberReuseAssert(true, $reads[0]['locked'], 'Allocation must serialize on the sequence row');
foreach ($reads as $read) {
    if ($read['table'] === 'fin_voucher') numberReuseAssert(true, $read['locked'], 'Allocation must scan vouchers using a current read, avoiding old transaction snapshots');
}
$store->begin();
numberReuseAssert([4], numberReuseService($store)->allocate('2026-05'), 'Another service instance must see committed voucher 2');
numberReusePersist($store, [4]);
$store->commit();

// VOIDED is still a retained voucher and occupies its number until del_flag changes.
$store = numberReuseFixture([1, 2, 4], 4);
$store->tables['fin_voucher'][1]['status'] = 'VOIDED';
$store->tables['fin_voucher'][1]['void_flag'] = 1;
$store->tables['fin_voucher'][] = numberReuseVoucher(3, ['del_flag' => 1]);
$service = numberReuseService($store);
numberReuseAssert(3, $service->peek('2026-05'), 'VOIDED nondeleted voucher 2 must continue occupying its number');
$store->begin();
numberReuseAssert([3, 5], $service->allocate('2026-05', 2), 'Allocation must skip both active and VOIDED retained numbers');
numberReusePersist($store, [3, 5]);
$store->commit();
numberReuseAssert('VOIDED', $store->tables['fin_voucher'][1]['status'], 'Allocating a reused number must not change the voided voucher');

// Historical sequence values must not prevent reusing the first unused active number.
$store = numberReuseFixture(range(1, 123), 125);
$store->tables['fin_voucher'][] = numberReuseVoucher(124, ['del_flag' => 1]);
$store->tables['fin_voucher'][] = numberReuseVoucher(125, ['del_flag' => 1]);
$service = numberReuseService($store);
numberReuseAssert(124, $service->peek('2026-05'), 'Historical sequence 125 with active 1..123 must preview 124');
$store->begin();
numberReuseAssert([124], $service->allocate('2026-05'), 'Historical sequence must be reconciled to active numbers');
numberReuseAssert(124, $store->tables['fin_voucher_no_sequence'][0]['current_no'], 'Historical high-water mark must not force skipped numbers');
numberReusePersist($store, [124]);
$store->commit();
$store->begin();
numberReuseAssert([125], numberReuseService($store)->allocate('2026-05'), 'Following allocation must reuse deleted 125');
numberReusePersist($store, [125]);
$store->commit();

// A whole period batch receives its numbers before business services insert any voucher.
$store = numberReuseFixture([1, 3, 5], 100);
$service = numberReuseService($store);
$store->begin();
$numbers = $service->allocate('2026-05', 3);
numberReuseAssert([2, 4, 6], $numbers, 'Batch preallocation must return each smallest available gap exactly once');
numberReuseAssert(3, count(array_unique($numbers)), 'Refund/payment batch preallocation must never duplicate a number');
numberReusePersist($store, $numbers);
numberReuseAssert([7], $service->allocate('2026-05'), 'Subsequent allocation must see vouchers inserted by the current batch');
numberReusePersist($store, [7]);
$store->commit();

// Filling multiple lower gaps can leave current_no unchanged in consecutive calls.
$store = numberReuseFixture([1, 4], 4);
$service = numberReuseService($store);
$store->begin();
numberReuseAssert([2], $service->allocate('2026-05'), 'First lower gap must be allocated without lowering the active maximum');
numberReusePersist($store, [2]);
numberReuseAssert([3], $service->allocate('2026-05'), 'Same-second no-op sequence update must not reject the next lower gap');
numberReusePersist($store, [3]);
numberReuseAssert(4, $store->tables['fin_voucher_no_sequence'][0]['current_no'], 'Sequence must still reflect the retained active maximum');
$store->commit();

// No cached reservations may survive a failed caller transaction.
$store = numberReuseFixture([1, 3], 7);
$store->tables['fin_voucher'][] = numberReuseVoucher(2, ['del_flag' => 1]);
$service = numberReuseService($store);
$before = $store->tables;
$store->begin();
numberReuseAssert([2, 4], $service->allocate('2026-05', 2), 'Rollback fixture must reserve gap and next number together');
numberReusePersist($store, [2, 4]);
$store->rollback();
numberReuseAssert($before, $store->tables, 'Caller rollback must restore sequence, deleted-voucher relocation and inserted vouchers');
numberReuseAssert(2, $service->peek('2026-05'), 'Rolled-back batch must not consume previewed gap');
$store->begin();
numberReuseAssert([2, 4], $service->allocate('2026-05', 2), 'Same service instance must return rolled-back numbers again');
$store->rollback();

// Account set, accounting year and period scopes must be enforced on every read and write.
$store = numberReuseFixture([1, 3], 3);
$unrelatedVouchers = [
    numberReuseVoucher(2, ['voucher_id' => 'OTHER-SET', 'account_set_id' => 'set-2']),
    numberReuseVoucher(2, ['voucher_id' => 'OTHER-PERIOD', 'period' => '2026-06']),
    numberReuseVoucher(2, ['voucher_id' => 'OTHER-YEAR', 'fiscal_year' => 2025]),
];
$store->tables['fin_voucher'] = array_merge($store->tables['fin_voucher'], $unrelatedVouchers);
$unrelatedSequences = [
    numberReuseSequence(100, ['sequence_id' => 'SEQ-2', 'account_set_id' => 'set-2']),
    numberReuseSequence(100, ['sequence_id' => 'SEQ-3', 'period' => '2026-06']),
    numberReuseSequence(100, ['sequence_id' => 'SEQ-4', 'fiscal_year' => 2025]),
];
$store->tables['fin_voucher_no_sequence'] = array_merge($store->tables['fin_voucher_no_sequence'], $unrelatedSequences);
$store->begin();
numberReuseAssert([2], numberReuseService($store)->allocate('2026-05'), 'Other-scope voucher 2 must not occupy the current gap');
numberReuseAssert($unrelatedVouchers, array_slice($store->tables['fin_voucher'], 2), 'Allocation must preserve unrelated voucher rows');
numberReuseAssert($unrelatedSequences, array_slice($store->tables['fin_voucher_no_sequence'], 1), 'Allocation must preserve unrelated sequence rows');
numberReusePersist($store, [2]);
$store->commit();
$store->begin();
numberReuseAssert([1], numberReuseService($store)->allocate('2026-06'), 'Each accounting period must have its own numbering gaps');
numberReusePersist($store, [1], ['period' => '2026-06']);
numberReuseAssert([1], numberReuseService($store, 'set-2')->allocate('2026-05'), 'Each account set must have its own numbering gaps');
numberReusePersist($store, [1], ['account_set_id' => 'set-2']);
$store->commit();

// Missing or stale-low sequences must still scan the actual vouchers before allocating.
$store = numberReuseFixture([1, 3]);
$store->begin();
numberReuseAssert([2], numberReuseService($store)->allocate('2026-05'), 'Missing sequence must not collide with existing vouchers');
numberReuseAssert(3, $store->tables['fin_voucher_no_sequence'][0]['current_no'], 'New sequence must account for the existing active maximum');
numberReusePersist($store, [2]);
$store->commit();
$store = numberReuseFixture(range(1, 5), 1);
$store->begin();
numberReuseAssert([6], numberReuseService($store)->allocate('2026-05'), 'Stale-low sequence must not reissue existing active numbers');
$store->rollback();

// Delete/renumber paths synchronize the pointer from retained vouchers, including VOIDED ones.
$store = numberReuseFixture([1, 2, 3], 125);
$store->tables['fin_voucher'][1]['status'] = 'VOIDED';
$store->tables['fin_voucher'][2]['del_flag'] = 1;
$store->begin();
$service = numberReuseService($store);
numberReuseAssert(2, $service->synchronize('2026-05'), 'Synchronization must ignore deleted voucher 3 but retain VOIDED voucher 2');
numberReuseAssert(2, $store->tables['fin_voucher_no_sequence'][0]['current_no'], 'Deletion synchronization must correct an old high-water mark');
numberReuseAssert([3], $service->allocate('2026-05'), 'The just-deleted maximum number must be reusable after synchronization');
numberReusePersist($store, [3]);
$store->commit();
$store = numberReuseFixture([], 125);
$store->tables['fin_voucher'][] = numberReuseVoucher(-1, ['del_flag' => 1]);
$store->begin();
$service = numberReuseService($store);
numberReuseAssert(0, $service->synchronize('2026-05'), 'A period with no retained positive voucher must synchronize to zero');
numberReuseAssert([1], $service->allocate('2026-05'), 'An emptied period must restart with voucher 1');
$store->rollback();

// Invalid inputs must be rejected before acquiring a lock or changing sequence state.
foreach ([['2026-13', 1], ['2026-05', 0], ['2026-05', -1], ['2026-05', 1.5]] as [$period, $count]) {
    $store = numberReuseFixture([1, 3], 3);
    $before = $store->tables;
    $failed = false;
    try {
        numberReuseService($store)->allocate($period, $count);
    } catch (InvalidArgumentException $e) {
        $failed = true;
    }
    numberReuseAssert(true, $failed, 'Invalid period or nonpositive/noninteger count must be rejected');
    numberReuseAssert($before, $store->tables, 'Rejected allocation must not modify data');
    numberReuseAssert([], $store->events, 'Rejected allocation must not acquire locks');
}

// Failed sequence creation/update or physical-key evacuation must abort the caller transaction.
foreach (['fin_voucher_no_sequence:insert', 'fin_voucher_no_sequence:update', 'fin_voucher:update'] as $failure) {
    $store = numberReuseFixture([1, 3], $failure === 'fin_voucher_no_sequence:insert' ? null : 9);
    $store->tables['fin_voucher'][] = numberReuseVoucher(2, ['del_flag' => 1]);
    $before = $store->tables;
    $store->failOperation = $failure;
    $store->begin();
    $failed = false;
    try {
        numberReuseService($store)->allocate('2026-05');
    } catch (Throwable $e) {
        $failed = true;
    }
    numberReuseAssert(true, $failed, $failure . ' must fail allocation rather than return an unsafe number');
    $store->rollback();
    numberReuseAssert($before, $store->tables, $failure . ' must restore all number-related data on caller rollback');
    $store->failOperation = '';
    $store->begin();
    numberReuseAssert([2], numberReuseService($store)->allocate('2026-05'), 'Retry after failed allocation must still reuse the original gap');
    $store->rollback();
}

echo "Voucher number reuse test passed\n";
