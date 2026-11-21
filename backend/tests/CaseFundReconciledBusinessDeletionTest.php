<?php

namespace think {
    /** Transaction boundary only; all model and service business logic remains real. */
    class Db
    {
        public static $store;

        public static function startTrans()
        {
            self::$store->snapshots[] = self::$store->tables;
        }

        public static function commit()
        {
            array_pop(self::$store->snapshots);
        }

        public static function rollback()
        {
            if (!empty(self::$store->snapshots)) {
                self::$store->tables = array_pop(self::$store->snapshots);
            }
        }
    }
}

namespace {
    function input($key = '', $default = null) { return $default; }
    function config($key = '') { return null; }
    function getNowTime() { return '2026-11-10 12:00:00'; }
    function uuid()
    {
        static $sequence = 0;
        return sprintf('00000000-0000-4000-8000-%012d', ++$sequence);
    }

    require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

    use app\finance\model\CaseFund;
    use think\Db;

    class ReconciledDeletionStore
    {
        public $tables = [];
        public $snapshots = [];
        public $events = [];
        public $failAudit = false;
        public $failUpdateTable = '';
        public $failDelete = false;
    }

    /** Small stateful query double: WHERE conditions affect every read and write. */
    class ReconciledDeletionQuery
    {
        private $store;
        private $table;
        private $conditions = [];
        private $locked = false;

        public function __construct($store, $table)
        {
            $this->store = $store;
            $this->table = $table;
        }

        public function where($field, $operator = null, $value = null)
        {
            if (is_array($field)) {
                foreach ($field as $name => $expected) {
                    $this->conditions[] = [$name, '=', $expected];
                }
            } elseif (func_num_args() === 2) {
                $this->conditions[] = [$field, '=', $operator];
            } else {
                $this->conditions[] = [$field, strtolower($operator), $value];
            }
            return $this;
        }

        public function field($fields) { return $this; }
        public function order($order) { return $this; }
        public function lock($lock = true) { $this->locked = $lock; return $this; }

        private function matches($row)
        {
            foreach ($this->conditions as [$field, $operator, $expected]) {
                $actual = $row[$field] ?? null;
                if (($operator === '=' && $actual != $expected)
                    || ($operator === 'in' && !in_array($actual, (array)$expected, true))
                    || ($operator === '>=' && $actual < $expected)
                    || ($operator === '<=' && $actual > $expected)
                ) {
                    return false;
                }
                if (!in_array($operator, ['=', 'in', '>=', '<='], true)) {
                    throw new \RuntimeException('Unsupported test query operator: ' . $operator);
                }
            }
            return true;
        }

        private function remember($operation)
        {
            $this->store->events[] = [
                'table' => $this->table,
                'operation' => $operation,
                'locked' => $this->locked,
            ];
        }

        public function select()
        {
            $this->remember('select');
            return array_values(array_filter($this->store->tables[$this->table] ?? [], function ($row) {
                return $this->matches($row);
            }));
        }

        public function find()
        {
            $rows = $this->select();
            return $rows[0] ?? null;
        }

        public function count() { return count($this->select()); }

        public function update($values)
        {
            $this->remember('update');
            if ($this->store->failUpdateTable === $this->table) {
                return 0;
            }
            $affected = 0;
            foreach ($this->store->tables[$this->table] as &$row) {
                if ($this->matches($row)) {
                    $updated = array_merge($row, $values);
                    $affected += $updated !== $row ? 1 : 0;
                    $row = $updated;
                }
            }
            unset($row);
            return $affected;
        }

        public function delete()
        {
            $this->remember('delete');
            if ($this->store->failDelete) {
                return 0;
            }
            $before = $this->store->tables[$this->table];
            $this->store->tables[$this->table] = array_values(array_filter($before, function ($row) {
                return !$this->matches($row);
            }));
            return count($before) - count($this->store->tables[$this->table]);
        }

        public function insert($row)
        {
            $this->remember('insert');
            $this->store->tables[$this->table][] = $row;
            return 1;
        }

        public function insertAll($rows)
        {
            foreach ($rows as $row) {
                $this->insert($row);
            }
            return count($rows);
        }
    }

    class ReconciledDeletionModel extends CaseFund
    {
        public function __construct($store)
        {
            Db::$store = $store;
            $this->accountSetId = 'set-1';
            $this->year = 2026;
            $this->userid = 'user-1';
        }

        public static function getdb($table = '')
        {
            return new ReconciledDeletionQuery(Db::$store, $table);
        }

        protected function requirePermission($permission) { return null; }

        protected function logAudit($bizType, $bizId, $operation, $beforeData, $afterData)
        {
            if (Db::$store->failAudit) {
                throw new \RuntimeException('Injected audit write failure');
            }
            $this->getdb('sys_audit_log')->insert([
                'biz_type' => $bizType,
                'operation' => $operation,
                'before' => $beforeData,
                'after' => $afterData,
            ]);
        }
    }

    function reconciledDeletionAssert($expected, $actual, $message)
    {
        if ($expected !== $actual) {
            fwrite(STDERR, $message . '\nExpected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE)
                . '\nActual: ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . PHP_EOL);
            exit(1);
        }
    }

    function reconciledDeletionRow($values)
    {
        return array_merge([
            'account_set_id' => 'set-1', 'fiscal_year' => 2026, 'period' => '2026-05', 'del_flag' => 0,
        ], $values);
    }

    function reconciledDeletionFixture($status = 'UNGENERATED')
    {
        $store = new ReconciledDeletionStore();
        $store->tables = [
            'fin_account_set' => [reconciledDeletionRow(['set_name' => '测试账套'])],
            CaseFund::TABLE_REFUND => [reconciledDeletionRow([
                'refund_id' => 'R-1', 'case_no' => '案一', 'refund_date' => '2026-05-13',
                'refund_amount' => '100.00', 'out_order_no' => 'OUT-1', 'source_row_no' => 1,
                'payee_bank_account_no' => '123456', 'voucher_status' => $status,
                'voucher_id' => $status === 'GENERATED' ? 'V-1' : null,
                'voucher_period' => $status === 'GENERATED' ? '2026-05' : null,
            ])],
            CaseFund::TABLE_BANK_STATEMENT => [reconciledDeletionRow([
                'statement_id' => 'S-1', 'transaction_date' => '2026-05-13',
                'transaction_time' => '2026-05-13 09:00:00', 'bank_code' => 'CCB',
                'direction' => 'DEBIT', 'debit_amount' => '100.00', 'credit_amount' => '0.00',
                'counterparty_account_no' => '123456', 'bank_serial_no' => 'SERIAL-1',
                'source_row_no' => 1, 'reconcile_status' => 'MATCHED',
            ])],
            CaseFund::TABLE_BANK_RECONCILE => [reconciledDeletionRow([
                'reconcile_id' => 'REC-1', 'statement_id' => 'S-1', 'biz_type' => 'REFUND',
                'biz_id' => 'R-1', 'match_status' => 'MATCHED', 'reconcile_date' => '2026-05-13',
                'match_rule' => 'PAYEE_ACCOUNT_DATE_AMOUNT',
            ])],
            CaseFund::TABLE_PAYMENT => [],
            CaseFund::TABLE_UNKNOWN_PAYMENT => [],
            CaseFund::TABLE_DISTRIBUTION => [],
            CaseFund::TABLE_UNKNOWN_REFUND_MATCH => [],
            'fin_voucher' => [],
            'sys_audit_log' => [],
        ];
        return $store;
    }

    function reconciledDeletionDelete($store, $ids = ['R-1'])
    {
        $model = new ReconciledDeletionModel($store);
        return $model->refundDelete(['refund_ids' => $ids]);
    }

    // The user's exact path: reconciled, not generated, delete, then rerun reconciliation.
    $store = reconciledDeletionFixture();
    $originalReconcile = $store->tables[CaseFund::TABLE_BANK_RECONCILE][0];
    $result = reconciledDeletionDelete($store);
    reconciledDeletionAssert(20000, $result['code'], 'Reconciled ungenerated refund must be deletable');
    reconciledDeletionAssert(['deleted_count' => 1], $result['data'], 'Delete response should keep its existing contract');
    reconciledDeletionAssert([], $store->tables[CaseFund::TABLE_REFUND], 'Selected refund must be removed');
    reconciledDeletionAssert(1, $store->tables[CaseFund::TABLE_BANK_RECONCILE][0]['del_flag'], 'Old match must be invalidated');
    reconciledDeletionAssert('UNMATCHED', $store->tables[CaseFund::TABLE_BANK_STATEMENT][0]['reconcile_status'], 'Bank statement must await reconciliation');
    reconciledDeletionAssert(0, $store->tables[CaseFund::TABLE_BANK_STATEMENT][0]['del_flag'], 'Bank statement itself must remain');
    $audit = $store->tables['sys_audit_log'][0];
    reconciledDeletionAssert('R-1', $audit['before'][0]['refund_id'], 'Deletion audit must retain the source snapshot');
    reconciledDeletionAssert([$originalReconcile], $audit['after']['bank_reconcile_invalidation']['reconcile_rows'], 'Audit must retain invalidated match snapshots');
    reconciledDeletionAssert(['S-1' => 'MATCHED'], $audit['after']['bank_reconcile_invalidation']['statement_statuses'], 'Audit must retain prior bank status');
    reconciledDeletionAssert(['fin_account_set', CaseFund::TABLE_REFUND], array_column(array_slice($store->events, 0, 2), 'table'), 'Account set must lock before source business rows');
    reconciledDeletionAssert([true, true], array_column(array_slice($store->events, 0, 2), 'locked'), 'Both reconciliation scope and source rows must be locked');
    $model = new ReconciledDeletionModel($store);
    $rerun = $model->bankReconcileRun(['date_start' => '2026-05-13', 'date_end' => '2026-05-13']);
    reconciledDeletionAssert(20000, $rerun['code'], 'Reconciliation must be runnable after deletion');
    reconciledDeletionAssert(1, $rerun['data']['counts']['BANK_ONLY'], 'The retained bank statement must become bank-only');
    $activeResults = array_values(array_filter($store->tables[CaseFund::TABLE_BANK_RECONCILE], function ($row) { return $row['del_flag'] === 0; }));
    reconciledDeletionAssert(1, count($activeResults), 'Rerun must save only the new result');
    reconciledDeletionAssert(null, $activeResults[0]['biz_id'], 'Rerun must not retain the deleted refund link');

    // A still-existing voucher blocks deletion regardless of reconciliation status.
    $store = reconciledDeletionFixture('GENERATED');
    $store->tables['fin_voucher'][] = reconciledDeletionRow(['voucher_id' => 'V-1', 'status' => 'SUBMITTED']);
    $before = $store->tables;
    $result = reconciledDeletionDelete($store);
    reconciledDeletionAssert(0, $result['code'], 'An active generated voucher must block deletion');
    reconciledDeletionAssert(true, strpos($result['message'], '原凭证仍有效') !== false, 'Blocked deletion must explain the voucher requirement');
    reconciledDeletionAssert($before, $store->tables, 'Rejected deletion must preserve business, bank, matches and audit');

    // Legacy GENERATED status is safe to delete once the actual voucher has been deleted.
    $store = reconciledDeletionFixture('GENERATED');
    $store->tables['fin_voucher'][] = reconciledDeletionRow(['voucher_id' => 'V-1', 'del_flag' => 1]);
    $result = reconciledDeletionDelete($store);
    reconciledDeletionAssert(20000, $result['code'], 'Deleted original voucher must allow deletion even with stale GENERATED status');
    reconciledDeletionAssert([], $store->tables[CaseFund::TABLE_REFUND], 'Refund with deleted original voucher must be removed');
    reconciledDeletionAssert('UNMATCHED', $store->tables[CaseFund::TABLE_BANK_STATEMENT][0]['reconcile_status'], 'Deleted-voucher path must also reset bank status');

    // Business-only results have no bank statement, but must still be invalidated.
    $store = reconciledDeletionFixture();
    $store->tables[CaseFund::TABLE_BANK_STATEMENT] = [];
    $store->tables[CaseFund::TABLE_BANK_RECONCILE][0]['statement_id'] = null;
    $store->tables[CaseFund::TABLE_BANK_RECONCILE][0]['match_status'] = 'BIZ_ONLY';
    $result = reconciledDeletionDelete($store);
    reconciledDeletionAssert(20000, $result['code'], 'Business-only refund must be deletable without a bank statement');
    reconciledDeletionAssert(1, $store->tables[CaseFund::TABLE_BANK_RECONCILE][0]['del_flag'], 'Business-only result must not dangle');
    reconciledDeletionAssert([], $store->tables['sys_audit_log'][0]['after']['bank_reconcile_invalidation']['statement_statuses'], 'Business-only audit must contain no invented bank statement');

    // All candidates for the affected bank statement are stale until recalculated.
    $store = reconciledDeletionFixture();
    $store->tables[CaseFund::TABLE_REFUND][] = reconciledDeletionRow(['refund_id' => 'R-2', 'voucher_status' => 'UNGENERATED']);
    $store->tables[CaseFund::TABLE_BANK_STATEMENT][0]['reconcile_status'] = 'DUPLICATE';
    $store->tables[CaseFund::TABLE_BANK_RECONCILE][0]['match_status'] = 'DUPLICATE';
    $store->tables[CaseFund::TABLE_BANK_RECONCILE][] = reconciledDeletionRow([
        'reconcile_id' => 'REC-2', 'statement_id' => 'S-1', 'biz_type' => 'REFUND', 'biz_id' => 'R-2', 'match_status' => 'DUPLICATE',
    ]);
    $result = reconciledDeletionDelete($store);
    reconciledDeletionAssert(20000, $result['code'], 'One duplicate candidate may be deleted');
    reconciledDeletionAssert([1, 1], array_column($store->tables[CaseFund::TABLE_BANK_RECONCILE], 'del_flag'), 'All old candidates of the affected statement must be invalidated');
    reconciledDeletionAssert(['R-2'], array_column($store->tables[CaseFund::TABLE_REFUND], 'refund_id'), 'Other candidate source data must remain');

    // One business can be a candidate on several bank statements; reset every affected statement.
    $store = reconciledDeletionFixture();
    $store->tables[CaseFund::TABLE_BANK_STATEMENT][] = reconciledDeletionRow([
        'statement_id' => 'S-2', 'reconcile_status' => 'AMOUNT_DIFF',
    ]);
    $store->tables[CaseFund::TABLE_BANK_RECONCILE][] = reconciledDeletionRow([
        'reconcile_id' => 'REC-2', 'statement_id' => 'S-2', 'biz_type' => 'REFUND',
        'biz_id' => 'R-1', 'match_status' => 'AMOUNT_DIFF',
    ]);
    $result = reconciledDeletionDelete($store);
    reconciledDeletionAssert(20000, $result['code'], 'Business linked to several bank statements must be deletable');
    reconciledDeletionAssert(['UNMATCHED', 'UNMATCHED'], array_column($store->tables[CaseFund::TABLE_BANK_STATEMENT], 'reconcile_status'), 'Every affected statement must be reset');
    reconciledDeletionAssert([1, 1], array_column($store->tables[CaseFund::TABLE_BANK_RECONCILE], 'del_flag'), 'No former match may retain the deleted business');
    reconciledDeletionAssert(['S-1' => 'MATCHED', 'S-2' => 'AMOUNT_DIFF'], $store->tables['sys_audit_log'][0]['after']['bank_reconcile_invalidation']['statement_statuses'], 'Audit must include every affected bank statement');

    // Other account sets/years, unrelated statements and old already-deleted results remain untouched.
    $store = reconciledDeletionFixture();
    $untouchedResults = [
        reconciledDeletionRow(['reconcile_id' => 'OTHER-SET', 'account_set_id' => 'set-2', 'statement_id' => 'S-1', 'biz_type' => 'REFUND', 'biz_id' => 'R-1']),
        reconciledDeletionRow(['reconcile_id' => 'OTHER-YEAR', 'fiscal_year' => 2025, 'period' => '2025-05', 'statement_id' => 'S-1', 'biz_type' => 'REFUND', 'biz_id' => 'R-1']),
        reconciledDeletionRow(['reconcile_id' => 'UNRELATED', 'statement_id' => 'S-2', 'biz_type' => 'REFUND', 'biz_id' => 'R-2']),
        reconciledDeletionRow(['reconcile_id' => 'ALREADY-DELETED', 'statement_id' => 'S-1', 'biz_type' => 'REFUND', 'biz_id' => 'R-1', 'del_flag' => 1]),
    ];
    $store->tables[CaseFund::TABLE_BANK_RECONCILE] = array_merge($store->tables[CaseFund::TABLE_BANK_RECONCILE], $untouchedResults);
    $unrelatedStatement = reconciledDeletionRow(['statement_id' => 'S-2', 'reconcile_status' => 'MATCHED']);
    $store->tables[CaseFund::TABLE_BANK_STATEMENT][] = $unrelatedStatement;
    $result = reconciledDeletionDelete($store);
    reconciledDeletionAssert(20000, $result['code'], 'Delete must be restricted to current account set and year');
    reconciledDeletionAssert($untouchedResults, array_slice($store->tables[CaseFund::TABLE_BANK_RECONCILE], 1), 'Other-scope and unrelated results must remain byte-for-byte unchanged');
    reconciledDeletionAssert($unrelatedStatement, $store->tables[CaseFund::TABLE_BANK_STATEMENT][1], 'Unrelated bank status must remain unchanged');

    // A stale link to a missing or deleted bank statement must fail without partial cleanup.
    foreach (['missing', 'deleted'] as $statementState) {
        $store = reconciledDeletionFixture();
        if ($statementState === 'missing') {
            $store->tables[CaseFund::TABLE_BANK_STATEMENT] = [];
        } else {
            $store->tables[CaseFund::TABLE_BANK_STATEMENT][0]['del_flag'] = 1;
        }
        $before = $store->tables;
        $result = reconciledDeletionDelete($store);
        reconciledDeletionAssert(0, $result['code'], 'A ' . $statementState . ' bank statement must block partial cleanup');
        reconciledDeletionAssert(true, strpos($result['message'], '关联银行流水不存在或已删除') !== false, 'Stale bank links must report an explicit error');
        reconciledDeletionAssert($before, $store->tables, 'Stale bank links must roll back the whole delete request');
    }

    // All failure points share the original transaction and restore the full state.
    foreach (['statement', 'reconcile', 'delete', 'audit'] as $failure) {
        $store = reconciledDeletionFixture();
        $before = $store->tables;
        if ($failure === 'statement') $store->failUpdateTable = CaseFund::TABLE_BANK_STATEMENT;
        if ($failure === 'reconcile') $store->failUpdateTable = CaseFund::TABLE_BANK_RECONCILE;
        if ($failure === 'delete') $store->failDelete = true;
        if ($failure === 'audit') $store->failAudit = true;
        $result = reconciledDeletionDelete($store);
        reconciledDeletionAssert(0, $result['code'], $failure . ' failure must fail the delete request');
        reconciledDeletionAssert($before, $store->tables, $failure . ' failure must roll back all business, reconciliation, bank and audit changes');
        reconciledDeletionAssert([], $store->snapshots, $failure . ' failure must close its transaction');
    }

    // Multi-selection is atomic when even one row still has an active voucher.
    $store = reconciledDeletionFixture();
    $store->tables[CaseFund::TABLE_REFUND][] = reconciledDeletionRow([
        'refund_id' => 'R-2', 'case_no' => '案二', 'voucher_status' => 'GENERATED',
        'voucher_id' => 'V-2', 'voucher_period' => '2026-05',
    ]);
    $store->tables['fin_voucher'][] = reconciledDeletionRow(['voucher_id' => 'V-2']);
    $before = $store->tables;
    $result = reconciledDeletionDelete($store, ['R-1', 'R-2']);
    reconciledDeletionAssert(0, $result['code'], 'Mixed selection must reject the whole deletion');
    reconciledDeletionAssert($before, $store->tables, 'Mixed selection must not partially delete the ungenerated row');

    echo "Case fund reconciled business deletion test passed\n";
}
