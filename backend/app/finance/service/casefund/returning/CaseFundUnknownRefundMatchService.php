<?php

namespace app\finance\service\casefund\returning;

/**
 * 不明款退汇识别服务。
 *
 * 关联表是退汇识别的唯一事实来源；原不明款认领状态和出账状态均不在这里修改。
 */
class CaseFundUnknownRefundMatchService
{
    const RULE_SAME_MONTH_NAME_AMOUNT_ACCOUNT = 'SAME_MONTH_NAME_AMOUNT_ACCOUNT';

    const STATUS_INELIGIBLE = 'INELIGIBLE';
    const STATUS_UNMATCHED = 'UNMATCHED';
    const STATUS_AUTO_CANDIDATE = 'AUTO_CANDIDATE';
    const STATUS_AMBIGUOUS = 'AMBIGUOUS';
    const STATUS_CONFIRMED = 'CONFIRMED';
    const STATUS_REJECTED = 'REJECTED';

    const CANDIDATE_STATUSES = [self::STATUS_AUTO_CANDIDATE, self::STATUS_AMBIGUOUS];
    const STORED_STATUSES = [
        self::STATUS_AUTO_CANDIDATE,
        self::STATUS_AMBIGUOUS,
        self::STATUS_CONFIRMED,
        self::STATUS_REJECTED,
    ];

    protected $hooks;
    protected $tables;

    public function __construct(array $hooks, array $tables)
    {
        foreach ([
            'get_db',
            'account_set_id',
            'current_year',
            'new_id',
            'prepare_create',
            'prepare_update',
            'decimal_to_cents',
            'now',
            'user_id',
        ] as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('退汇识别服务缺少回调：' . $name);
            }
        }
        foreach (['unknown_payment', 'refund', 'match'] as $name) {
            if (empty($tables[$name])) {
                throw new \InvalidArgumentException('退汇识别服务缺少数据表：' . $name);
            }
        }
        $this->hooks = $hooks;
        $this->tables = $tables;
    }

    public function preview($period)
    {
        return $this->analyzePeriod($period);
    }

    public function scan($period)
    {
        $analysis = $this->analyzePeriod($period, true);
        $saved = $this->persistAnalysis($analysis);
        $analysis['stats']['saved_pairs'] = $saved;
        unset($analysis['pairs']);
        return $analysis;
    }

    public function analyzePeriod($period, $lock = false)
    {
        $scope = $this->periodScope($period);
        $unknownQuery = $this->db($this->tables['unknown_payment'])->where([
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'period' => $scope['period'],
            'del_flag' => 0,
            'transaction_date' => [['>=', $scope['date_start']], ['<=', $scope['date_end']]],
        ]);
        $refundQuery = $this->db($this->tables['refund'])->where([
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'period' => $scope['period'],
            'del_flag' => 0,
            'refund_date' => [['>=', $scope['date_start']], ['<=', $scope['date_end']]],
        ]);
        if ($lock) {
            $unknownQuery->lock(true);
            $refundQuery->lock(true);
        }
        $unknownRows = $unknownQuery
            ->field('unknown_payment_id,period,payer_name,unknown_amount,payer_account_no,transaction_date,claim_status')
            ->order('transaction_date asc, source_row_no asc')
            ->select();
        $refundRows = $refundQuery
            ->field('refund_id,period,case_no,out_order_no,actual_payee_name,refund_amount,payee_bank_account_no,refund_date,out_status')
            ->order('refund_date asc, source_row_no asc')
            ->select();
        $decisions = $this->decisionRowsForPeriod($scope['period'], $lock, true);
        $analysis = $this->matchRows($unknownRows, $refundRows, $decisions);
        $analysis['period'] = $scope['period'];
        return $analysis;
    }

    /**
     * 纯匹配规则，便于用固定样本验证边界场景。
     */
    public function matchRows(array $unknownRows, array $refundRows, array $decisionRows = [])
    {
        $confirmedUnknown = [];
        $confirmedRefund = [];
        $rejectedPairs = [];
        $rejectedUnknown = [];
        foreach ($decisionRows as $row) {
            if ((int)($row['del_flag'] ?? 0) !== 0) {
                continue;
            }
            $status = strtoupper(trim((string)($row['match_status'] ?? '')));
            $unknownId = trim((string)($row['unknown_payment_id'] ?? ''));
            $refundId = trim((string)($row['refund_id'] ?? ''));
            if ($status === self::STATUS_CONFIRMED) {
                $confirmedUnknown[$unknownId] = true;
                $confirmedRefund[$refundId] = true;
            } elseif ($status === self::STATUS_REJECTED) {
                $rejectedPairs[$this->pairKey($unknownId, $refundId)] = true;
                $rejectedUnknown[$unknownId] = true;
            }
        }

        $refundIndex = [];
        foreach ($refundRows as $refund) {
            $refundId = trim((string)($refund['refund_id'] ?? ''));
            if ($refundId === '' || isset($confirmedRefund[$refundId])) {
                continue;
            }
            $key = $this->businessMatchKey(
                $refund['actual_payee_name'] ?? '',
                $refund['refund_amount'] ?? '',
                $refund['payee_bank_account_no'] ?? ''
            );
            if ($key !== '') {
                $refundIndex[$key][] = $refund;
            }
        }

        $rawPairs = [];
        $unknownDegree = [];
        $refundDegree = [];
        $eligibleUnknown = [];
        foreach ($unknownRows as $unknown) {
            $unknownId = trim((string)($unknown['unknown_payment_id'] ?? ''));
            if ($unknownId === '' || isset($confirmedUnknown[$unknownId])) {
                continue;
            }
            $key = $this->businessMatchKey(
                $unknown['payer_name'] ?? '',
                $unknown['unknown_amount'] ?? '',
                $unknown['payer_account_no'] ?? ''
            );
            if ($key === '') {
                continue;
            }
            $eligibleUnknown[$unknownId] = true;
            foreach ($refundIndex[$key] ?? [] as $refund) {
                $refundId = trim((string)($refund['refund_id'] ?? ''));
                if (!$this->datesCanBeReturn(
                    $refund['refund_date'] ?? '',
                    $unknown['transaction_date'] ?? '',
                    $refund['period'] ?? '',
                    $unknown['period'] ?? ''
                )) {
                    continue;
                }
                if (isset($rejectedPairs[$this->pairKey($unknownId, $refundId)])) {
                    continue;
                }
                $rawPairs[] = ['unknown' => $unknown, 'refund' => $refund];
                $unknownDegree[$unknownId] = ($unknownDegree[$unknownId] ?? 0) + 1;
                $refundDegree[$refundId] = ($refundDegree[$refundId] ?? 0) + 1;
            }
        }

        $pairs = [];
        $autoUnknown = [];
        $ambiguousUnknown = [];
        foreach ($rawPairs as $pair) {
            $unknownId = (string)$pair['unknown']['unknown_payment_id'];
            $refundId = (string)$pair['refund']['refund_id'];
            $ambiguous = ($unknownDegree[$unknownId] ?? 0) > 1
                || ($refundDegree[$refundId] ?? 0) > 1;
            $status = $ambiguous ? self::STATUS_AMBIGUOUS : self::STATUS_AUTO_CANDIDATE;
            if ($ambiguous) {
                $ambiguousUnknown[$unknownId] = true;
            } else {
                $autoUnknown[$unknownId] = true;
            }
            $pairs[] = [
                'unknown_payment_id' => $unknownId,
                'refund_id' => $refundId,
                'match_status' => $status,
                'candidate_count' => max($unknownDegree[$unknownId] ?? 1, $refundDegree[$refundId] ?? 1),
                'match_evidence_json' => $this->evidenceJson($pair['unknown'], $pair['refund']),
            ];
        }

        $totalUnknown = count($unknownRows);
        $confirmedCount = count(array_intersect_key($confirmedUnknown, $this->idMap($unknownRows, 'unknown_payment_id')));
        $ineligibleCount = 0;
        $unmatchedCount = 0;
        $rejectedCount = 0;
        foreach ($unknownRows as $unknown) {
            $unknownId = trim((string)($unknown['unknown_payment_id'] ?? ''));
            if (isset($confirmedUnknown[$unknownId])) {
                continue;
            }
            if (!$this->unknownPaymentEligible($unknown)) {
                $ineligibleCount++;
                continue;
            }
            if (isset($autoUnknown[$unknownId]) || isset($ambiguousUnknown[$unknownId])) {
                continue;
            }
            if (isset($rejectedUnknown[$unknownId])) {
                $rejectedCount++;
            } else {
                $unmatchedCount++;
            }
        }

        return [
            'pairs' => $pairs,
            'stats' => [
                'total_unknown' => $totalUnknown,
                'ineligible' => $ineligibleCount,
                'unmatched' => $unmatchedCount,
                'auto_candidate' => count($autoUnknown),
                'ambiguous' => count($ambiguousUnknown),
                'confirmed' => $confirmedCount,
                'rejected' => $rejectedCount,
                'candidate_pairs' => count($pairs),
            ],
        ];
    }

    public function attachUnknownStatuses(array $rows)
    {
        return $this->attachStatuses($rows, 'unknown_payment');
    }

    public function attachRefundStatuses(array $rows)
    {
        return $this->attachStatuses($rows, 'refund');
    }

    public function filterEntityIds($entity, $status)
    {
        $entity = $this->normalizeEntity($entity);
        $status = strtoupper(trim((string)$status));
        if (!in_array($status, [
            self::STATUS_INELIGIBLE,
            self::STATUS_UNMATCHED,
            self::STATUS_AUTO_CANDIDATE,
            self::STATUS_AMBIGUOUS,
            self::STATUS_CONFIRMED,
            self::STATUS_REJECTED,
        ], true)) {
            return null;
        }
        if ($entity === 'refund' && $status === self::STATUS_INELIGIBLE) {
            return [];
        }
        $idField = $entity === 'unknown_payment' ? 'unknown_payment_id' : 'refund_id';
        $fields = $idField;
        if ($entity === 'unknown_payment') {
            $fields .= ',payer_name,payer_account_no,unknown_amount';
        }
        $rows = $this->db($this->tables[$entity])->where([
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'del_flag' => 0,
        ])->field($fields)->select();
        $rows = $this->attachStatuses($rows, $entity);
        $ids = [];
        foreach ($rows as $row) {
            if (($row['return_match_status'] ?? '') === $status) {
                $ids[] = $row[$idField];
            }
        }
        return $ids;
    }

    public function sourceDeletionError($entity, array $ids)
    {
        $entity = $this->normalizeEntity($entity);
        if (empty($ids)) {
            return null;
        }
        $idField = $entity === 'unknown_payment' ? 'unknown_payment_id' : 'refund_id';
        try {
            $count = $this->matchQuery($this->activeMatchWhere(), false)
                ->where('match_status', self::STATUS_CONFIRMED)
                ->where($idField, 'in', array_values($ids))
                ->count();
        } catch (\Throwable $e) {
            return null;
        }
        return (int)$count > 0 ? '已确认退汇关联的数据不能删除，请先取消退汇确认' : null;
    }

    public function detachDeletedSources($entity, array $ids)
    {
        $entity = $this->normalizeEntity($entity);
        if (empty($ids)) {
            return 0;
        }
        $idField = $entity === 'unknown_payment' ? 'unknown_payment_id' : 'refund_id';
        try {
            return (int)$this->matchQuery($this->activeMatchWhere(), false)
                ->where($idField, 'in', array_values($ids))
                ->update($this->prepareUpdate(['del_flag' => 1]));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function candidates($unknownPaymentId = '', $refundId = '')
    {
        $unknownPaymentId = trim((string)$unknownPaymentId);
        $refundId = trim((string)$refundId);
        if ($unknownPaymentId === '' && $refundId === '') {
            throw new \InvalidArgumentException('不明款ID和出账ID不能同时为空');
        }
        $where = $this->activeMatchWhere();
        if ($unknownPaymentId !== '') {
            $where['unknown_payment_id'] = $unknownPaymentId;
        }
        if ($refundId !== '') {
            $where['refund_id'] = $refundId;
        }
        $matches = $this->matchQuery($where, true)
            ->field($this->matchFields())
            ->order('match_status asc, created_time asc')
            ->select();
        $unknownIds = [];
        $refundIds = [];
        foreach ($matches as $match) {
            $unknownIds[$match['unknown_payment_id']] = true;
            $refundIds[$match['refund_id']] = true;
        }
        $unknownMap = $this->sourceMap('unknown_payment', 'unknown_payment_id', array_keys($unknownIds), [
            'unknown_payment_id', 'period', 'payer_name', 'unknown_amount', 'payer_account_no',
            'transaction_date', 'claim_status', 'claim_user_name', 'claim_date',
        ]);
        $refundMap = $this->sourceMap('refund', 'refund_id', array_keys($refundIds), [
            'refund_id', 'period', 'case_no', 'out_order_no', 'out_status', 'out_type',
            'actual_payee_name', 'refund_amount', 'payee_bank_account_no', 'refund_date',
        ]);
        $items = [];
        foreach ($matches as $match) {
            $unknown = $unknownMap[$match['unknown_payment_id']] ?? [];
            $refund = $refundMap[$match['refund_id']] ?? [];
            $items[] = array_merge($match, [
                'payer_name' => $unknown['payer_name'] ?? '',
                'unknown_amount' => $unknown['unknown_amount'] ?? '0.00',
                'payer_account_no' => $unknown['payer_account_no'] ?? '',
                'transaction_date' => $unknown['transaction_date'] ?? '',
                'claim_status' => $unknown['claim_status'] ?? '',
                'claim_user_name' => $unknown['claim_user_name'] ?? '',
                'claim_date' => $unknown['claim_date'] ?? '',
                'case_no' => $refund['case_no'] ?? '',
                'out_order_no' => $refund['out_order_no'] ?? '',
                'out_status' => $refund['out_status'] ?? '',
                'out_type' => $refund['out_type'] ?? '',
                'actual_payee_name' => $refund['actual_payee_name'] ?? '',
                'refund_amount' => $refund['refund_amount'] ?? '0.00',
                'payee_bank_account_no' => $refund['payee_bank_account_no'] ?? '',
                'refund_date' => $refund['refund_date'] ?? '',
            ]);
        }
        $entity = $unknownPaymentId !== '' ? 'unknown_payment' : 'refund';
        $source = $entity === 'unknown_payment'
            ? ($unknownMap[$unknownPaymentId] ?? ['unknown_payment_id' => $unknownPaymentId])
            : ($refundMap[$refundId] ?? ['refund_id' => $refundId]);
        $decorated = $this->attachStatuses([$source], $entity);
        return [
            'items' => $items,
            'status' => $decorated[0]['return_match_status'] ?? self::STATUS_UNMATCHED,
            'candidate_count' => count($items),
        ];
    }

    public function confirm($matchId)
    {
        $match = $this->lockedMatch($matchId);
        $unknown = $this->lockedSource('unknown_payment', 'unknown_payment_id', $match['unknown_payment_id']);
        $refund = $this->lockedSource('refund', 'refund_id', $match['refund_id']);
        $this->assertStrictPair($unknown, $refund);

        $base = $this->activeMatchWhere();
        $base['match_status'] = self::STATUS_CONFIRMED;
        $otherUnknown = $this->matchQuery($base, true)
            ->where('unknown_payment_id', $match['unknown_payment_id'])
            ->where('match_id', 'neq', $match['match_id'])
            ->lock(true)
            ->find();
        $otherRefund = $this->matchQuery($base, true)
            ->where('refund_id', $match['refund_id'])
            ->where('match_id', 'neq', $match['match_id'])
            ->lock(true)
            ->find();
        if ($otherUnknown || $otherRefund) {
            throw new \RuntimeException('该不明款或出账记录已经确认其他退汇关联，请刷新后重试');
        }

        $before = $match;
        $update = [
            'match_status' => self::STATUS_CONFIRMED,
            'candidate_count' => 1,
            'match_evidence_json' => $this->evidenceJson($unknown, $refund),
            'decision_by' => $this->userId(),
            'decision_time' => $this->now(),
            'del_flag' => 0,
        ];
        $update = $this->prepareUpdate($update);
        $this->db($this->tables['match'])->where([
            'account_set_id' => $this->accountSetId(),
            'match_id' => $match['match_id'],
        ])->update($update);

        $close = $this->prepareUpdate(['del_flag' => 1]);
        $candidateWhere = $this->activeMatchWhere();
        $candidateWhere['match_status'] = ['in', self::CANDIDATE_STATUSES];
        $this->db($this->tables['match'])->where($candidateWhere)
            ->where('unknown_payment_id', $match['unknown_payment_id'])
            ->update($close);
        $this->db($this->tables['match'])->where($candidateWhere)
            ->where('refund_id', $match['refund_id'])
            ->update($close);

        return ['before' => $before, 'after' => array_merge($match, $update)];
    }

    public function reject($matchId, $remark = '')
    {
        $match = $this->lockedMatch($matchId);
        if (($match['match_status'] ?? '') === self::STATUS_CONFIRMED) {
            throw new \RuntimeException('已确认退汇不能直接排除，请先取消确认');
        }
        $before = $match;
        $update = [
            'match_status' => self::STATUS_REJECTED,
            'decision_by' => $this->userId(),
            'decision_time' => $this->now(),
            'remark' => substr(trim((string)$remark), 0, 500),
            'del_flag' => 0,
        ];
        $update = $this->prepareUpdate($update);
        $this->db($this->tables['match'])->where([
            'account_set_id' => $this->accountSetId(),
            'match_id' => $match['match_id'],
        ])->update($update);
        return [
            'period' => $match['period'],
            'before' => $before,
            'after' => array_merge($match, $update),
        ];
    }

    public function cancel($matchId)
    {
        $match = $this->lockedMatch($matchId);
        if (($match['match_status'] ?? '') !== self::STATUS_CONFIRMED) {
            throw new \RuntimeException('只有已确认退汇才能取消确认');
        }
        $before = $match;
        $update = [
            'match_status' => self::STATUS_AUTO_CANDIDATE,
            'candidate_count' => 1,
            'decision_by' => $this->userId(),
            'decision_time' => $this->now(),
            'del_flag' => 1,
        ];
        $update = $this->prepareUpdate($update);
        $this->db($this->tables['match'])->where([
            'account_set_id' => $this->accountSetId(),
            'match_id' => $match['match_id'],
        ])->update($update);
        return [
            'period' => $match['period'],
            'before' => $before,
            'after' => array_merge($match, $update),
        ];
    }

    protected function persistAnalysis(array $analysis)
    {
        $period = $analysis['period'];
        $candidateWhere = $this->activeMatchWhere();
        $candidateWhere['period'] = $period;
        $candidateWhere['match_status'] = ['in', self::CANDIDATE_STATUSES];
        $this->db($this->tables['match'])->where($candidateWhere)
            ->update($this->prepareUpdate(['del_flag' => 1]));

        $existingRows = $this->matchQuery([
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'period' => $period,
        ], true)->lock(true)->select();
        $existingMap = [];
        foreach ($existingRows as $row) {
            $existingMap[$this->pairKey($row['unknown_payment_id'], $row['refund_id'])] = $row;
        }

        $saved = 0;
        foreach ($analysis['pairs'] as $pair) {
            $key = $this->pairKey($pair['unknown_payment_id'], $pair['refund_id']);
            $existing = $existingMap[$key] ?? null;
            if ($existing && (int)($existing['del_flag'] ?? 0) === 0
                && in_array($existing['match_status'] ?? '', [self::STATUS_CONFIRMED, self::STATUS_REJECTED], true)
            ) {
                continue;
            }
            $data = array_merge($pair, [
                'account_set_id' => $this->accountSetId(),
                'fiscal_year' => $this->currentYear(),
                'period' => $period,
                'match_rule' => self::RULE_SAME_MONTH_NAME_AMOUNT_ACCOUNT,
                'decision_by' => null,
                'decision_time' => null,
                'del_flag' => 0,
                'remark' => '',
            ]);
            if ($existing) {
                $data = $this->prepareUpdate($data);
                $this->db($this->tables['match'])->where([
                    'account_set_id' => $this->accountSetId(),
                    'match_id' => $existing['match_id'],
                ])->update($data);
            } else {
                $data['match_id'] = $this->newId();
                $data = $this->prepareCreate($data);
                $this->db($this->tables['match'])->insert($data);
            }
            $saved++;
        }
        return $saved;
    }

    protected function attachStatuses(array $rows, $entity)
    {
        $entity = $this->normalizeEntity($entity);
        $idField = $entity === 'unknown_payment' ? 'unknown_payment_id' : 'refund_id';
        $ids = [];
        foreach ($rows as $row) {
            $id = trim((string)($row[$idField] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        $grouped = [];
        if (!empty($ids)) {
            try {
                $matches = $this->matchQuery($this->activeMatchWhere(), false)
                    ->where($idField, 'in', array_keys($ids))
                    ->field($this->matchFields())
                    ->select();
            } catch (\Throwable $e) {
                $matches = [];
            }
            foreach ($matches as $match) {
                $grouped[$match[$idField]][] = $match;
            }
        }
        foreach ($rows as &$row) {
            $id = trim((string)($row[$idField] ?? ''));
            $matches = $grouped[$id] ?? [];
            $status = $this->aggregateStatus($matches);
            if (empty($matches)) {
                $status = $entity === 'unknown_payment' && !$this->unknownPaymentEligible($row)
                    ? self::STATUS_INELIGIBLE
                    : self::STATUS_UNMATCHED;
            }
            $primary = $this->primaryMatch($matches);
            $row['return_match_status'] = $status;
            $row['return_match_count'] = count($matches);
            $row['return_match_id'] = $primary['match_id'] ?? '';
            $row['matched_unknown_payment_id'] = $primary['unknown_payment_id'] ?? '';
            $row['matched_refund_id'] = $primary['refund_id'] ?? '';
        }
        unset($row);
        return $rows;
    }

    protected function aggregateStatus(array $matches)
    {
        $statuses = [];
        foreach ($matches as $match) {
            $statuses[] = strtoupper(trim((string)($match['match_status'] ?? '')));
        }
        if (in_array(self::STATUS_CONFIRMED, $statuses, true)) {
            return self::STATUS_CONFIRMED;
        }
        if (in_array(self::STATUS_AMBIGUOUS, $statuses, true) || count(array_intersect($statuses, self::CANDIDATE_STATUSES)) > 1) {
            return self::STATUS_AMBIGUOUS;
        }
        if (in_array(self::STATUS_AUTO_CANDIDATE, $statuses, true)) {
            return self::STATUS_AUTO_CANDIDATE;
        }
        if (in_array(self::STATUS_REJECTED, $statuses, true)) {
            return self::STATUS_REJECTED;
        }
        return self::STATUS_UNMATCHED;
    }

    protected function primaryMatch(array $matches)
    {
        $priority = [
            self::STATUS_CONFIRMED => 1,
            self::STATUS_AMBIGUOUS => 2,
            self::STATUS_AUTO_CANDIDATE => 3,
            self::STATUS_REJECTED => 4,
        ];
        usort($matches, function ($left, $right) use ($priority) {
            return ($priority[$left['match_status'] ?? ''] ?? 99) <=> ($priority[$right['match_status'] ?? ''] ?? 99);
        });
        return $matches[0] ?? [];
    }

    protected function decisionRowsForPeriod($period, $lock, $strict)
    {
        $query = $this->matchQuery([
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'period' => $period,
            'del_flag' => 0,
            'match_status' => ['in', [self::STATUS_CONFIRMED, self::STATUS_REJECTED]],
        ], $strict);
        if ($lock) {
            $query->lock(true);
        }
        return $query->field($this->matchFields())->select();
    }

    protected function lockedMatch($matchId)
    {
        $matchId = trim((string)$matchId);
        if ($matchId === '') {
            throw new \InvalidArgumentException('退汇匹配ID不能为空');
        }
        $where = $this->activeMatchWhere();
        $where['match_id'] = $matchId;
        $row = $this->matchQuery($where, true)->lock(true)->find();
        if (!$row) {
            throw new \RuntimeException('退汇匹配记录不存在或已失效');
        }
        return $row;
    }

    protected function lockedSource($entity, $idField, $id)
    {
        $row = $this->db($this->tables[$entity])->where([
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            $idField => $id,
            'del_flag' => 0,
        ])->lock(true)->find();
        if (!$row) {
            throw new \RuntimeException($entity === 'unknown_payment' ? '不明款记录不存在' : '出账记录不存在');
        }
        return $row;
    }

    protected function assertStrictPair(array $unknown, array $refund)
    {
        if (!$this->unknownPaymentEligible($unknown)) {
            throw new \RuntimeException('缺少缴款人或付款账号的不明款不参与退汇匹配');
        }
        $unknownKey = $this->businessMatchKey(
            $unknown['payer_name'] ?? '',
            $unknown['unknown_amount'] ?? '',
            $unknown['payer_account_no'] ?? ''
        );
        $refundKey = $this->businessMatchKey(
            $refund['actual_payee_name'] ?? '',
            $refund['refund_amount'] ?? '',
            $refund['payee_bank_account_no'] ?? ''
        );
        if ($unknownKey === '' || $unknownKey !== $refundKey) {
            throw new \RuntimeException('双方姓名、金额或账号已经不再满足严格匹配规则');
        }
        if (!$this->datesCanBeReturn(
            $refund['refund_date'] ?? '',
            $unknown['transaction_date'] ?? '',
            $refund['period'] ?? '',
            $unknown['period'] ?? ''
        )) {
            throw new \RuntimeException('双方不在同一月份，或退汇来账日期早于原出账日期');
        }
    }

    protected function unknownPaymentEligible(array $row)
    {
        return $this->normalizeName($row['payer_name'] ?? '') !== ''
            && $this->normalizeAccountNo($row['payer_account_no'] ?? '') !== '';
    }

    protected function businessMatchKey($name, $amount, $accountNo)
    {
        $name = $this->normalizeName($name);
        $accountNo = $this->normalizeAccountNo($accountNo);
        $amountCents = $this->decimalToCents($amount);
        if ($name === '' || $accountNo === '' || $amountCents <= 0) {
            return '';
        }
        return $name . '|' . $amountCents . '|' . $accountNo;
    }

    protected function normalizeName($value)
    {
        $value = trim((string)$value);
        $normalized = preg_replace('/[\s\x{3000}]+/u', '', $value);
        return $normalized === null ? $value : $normalized;
    }

    protected function normalizeAccountNo($value)
    {
        $value = trim((string)$value);
        $normalized = preg_replace('/[\s\x{3000}\-－–—]+/u', '', $value);
        $normalized = $normalized === null ? $value : $normalized;
        return strtoupper($normalized);
    }

    protected function datesCanBeReturn($refundDate, $transactionDate, $refundPeriod, $unknownPeriod)
    {
        $refundDate = substr(trim((string)$refundDate), 0, 10);
        $transactionDate = substr(trim((string)$transactionDate), 0, 10);
        $refundPeriod = trim((string)$refundPeriod);
        $unknownPeriod = trim((string)$unknownPeriod);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $refundDate)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionDate)
        ) {
            return false;
        }
        return $refundPeriod !== ''
            && $refundPeriod === $unknownPeriod
            && substr($refundDate, 0, 7) === $unknownPeriod
            && substr($transactionDate, 0, 7) === $unknownPeriod
            && $refundDate <= $transactionDate;
    }

    protected function evidenceJson(array $unknown, array $refund)
    {
        return json_encode([
            'unknown_payment' => [
                'payer_name' => $unknown['payer_name'] ?? '',
                'unknown_amount' => $unknown['unknown_amount'] ?? '',
                'payer_account_no' => $unknown['payer_account_no'] ?? '',
                'transaction_date' => $unknown['transaction_date'] ?? '',
            ],
            'refund' => [
                'actual_payee_name' => $refund['actual_payee_name'] ?? '',
                'refund_amount' => $refund['refund_amount'] ?? '',
                'payee_bank_account_no' => $refund['payee_bank_account_no'] ?? '',
                'refund_date' => $refund['refund_date'] ?? '',
                'case_no' => $refund['case_no'] ?? '',
                'out_order_no' => $refund['out_order_no'] ?? '',
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    protected function sourceMap($entity, $idField, array $ids, array $fields)
    {
        if (empty($ids)) {
            return [];
        }
        $rows = $this->db($this->tables[$entity])->where([
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'del_flag' => 0,
        ])->where($idField, 'in', $ids)->field($fields)->select();
        $map = [];
        foreach ($rows as $row) {
            $map[$row[$idField]] = $row;
        }
        return $map;
    }

    protected function idMap(array $rows, $field)
    {
        $map = [];
        foreach ($rows as $row) {
            $id = trim((string)($row[$field] ?? ''));
            if ($id !== '') {
                $map[$id] = true;
            }
        }
        return $map;
    }

    protected function normalizeEntity($entity)
    {
        $entity = trim((string)$entity);
        if (!in_array($entity, ['unknown_payment', 'refund'], true)) {
            throw new \InvalidArgumentException('不支持的退汇关联实体：' . $entity);
        }
        return $entity;
    }

    protected function periodScope($period)
    {
        $period = trim((string)$period);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new \InvalidArgumentException('退汇识别期间格式必须为YYYY-MM');
        }
        if ((int)substr($period, 0, 4) !== $this->currentYear()) {
            throw new \InvalidArgumentException('退汇识别期间必须属于当前登录年度');
        }
        $start = $period . '-01';
        $date = new \DateTimeImmutable($start);
        return [
            'period' => $period,
            'date_start' => $start,
            'date_end' => $date->modify('last day of this month')->format('Y-m-d'),
        ];
    }

    protected function activeMatchWhere()
    {
        return [
            'account_set_id' => $this->accountSetId(),
            'fiscal_year' => $this->currentYear(),
            'del_flag' => 0,
        ];
    }

    protected function pairKey($unknownPaymentId, $refundId)
    {
        return trim((string)$unknownPaymentId) . '|' . trim((string)$refundId);
    }

    protected function matchFields()
    {
        return 'match_id,account_set_id,fiscal_year,period,unknown_payment_id,refund_id,match_status,match_rule,candidate_count,match_evidence_json,decision_by,decision_time,created_by,created_time,updated_by,updated_time,del_flag,version,remark';
    }

    protected function matchQuery(array $where, $strict)
    {
        try {
            return $this->db($this->tables['match'])->where($where);
        } catch (\Throwable $e) {
            if ($strict) {
                throw new \RuntimeException('退汇关联表不可用，请先执行2026-08-11退汇识别数据库补丁：' . $e->getMessage());
            }
            throw $e;
        }
    }

    protected function db($table)
    {
        return $this->callHook('get_db', [$table]);
    }

    protected function accountSetId()
    {
        return (string)$this->callHook('account_set_id');
    }

    protected function currentYear()
    {
        return (int)$this->callHook('current_year');
    }

    protected function newId()
    {
        return (string)$this->callHook('new_id');
    }

    protected function prepareCreate(array $row)
    {
        return $this->callHook('prepare_create', [$row]);
    }

    protected function prepareUpdate(array $row)
    {
        return $this->callHook('prepare_update', [$row]);
    }

    protected function decimalToCents($amount)
    {
        return (int)$this->callHook('decimal_to_cents', [$amount]);
    }

    protected function now()
    {
        return (string)$this->callHook('now');
    }

    protected function userId()
    {
        return (string)$this->callHook('user_id');
    }

    protected function callHook($name, array $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
