<?php

namespace app\finance\model;

class Book extends Common
{
    const ACTION = 'book';

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'detailLedger':
                return $this->detailLedger($data);
            case 'subjectBalance':
                return $this->subjectBalance($data);
            case 'subjectSummary':
                return $this->subjectSummary($data);
            case 'auxBalanceSubjects':
                return $this->auxBalanceSubjects($data);
            case 'auxBalance':
                return $this->auxBalance($data);
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function detailLedger($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }
        $period = $data['period'] ?? '';
        if ($period === '') {
            return $this->error('会计期间不能为空');
        }
        $startDate = $data['start_date'] ?? substr($period, 0, 7) . '-01';
        $endDate = $data['end_date'] ?? date('Y-m-t', strtotime($startDate));
        $subjectCode = $data['subject_code'] ?? '';
        $fiscalYear = $this->fiscalYear($period);
        $voucherTable = 'fin_voucher';
        $detailTable = 'fin_voucher_detail';

        $where = [
            'd.account_set_id' => $this->accountSetId,
            'd.fiscal_year' => $fiscalYear,
            'd.period' => $period,
            'd.del_flag' => 0,
            'v.fiscal_year' => $fiscalYear,
            'v.period' => $period,
            'v.del_flag' => 0,
            'v.voucher_date' => ['between', [$startDate, $endDate]],
            'v.status' => ['in', ['AUDITED', 'PRINTED']],
        ];
        $codeRule = $this->subjectCodeRule();
        $lengths = $this->subjectCodeRuleLengths($codeRule);
        $maxLength = empty($lengths) ? 10 : max($lengths);

        $query = db($detailTable)->alias('d')
            ->join($voucherTable . ' v', 'v.voucher_id=d.voucher_id and v.account_set_id=d.account_set_id and v.fiscal_year=d.fiscal_year and v.period=d.period')
            ->join('fin_subject s', 's.subject_code=d.subject_code and s.account_set_id=d.account_set_id and s.del_flag=0', 'LEFT')
            ->where($where);
        if ($subjectCode !== '') {
            $query->where('d.subject_code', '>=', $subjectCode);
            $query->where('d.subject_code', '<=', $this->subjectRangeUpperBound($subjectCode, $maxLength));
        }

        $rows = $query
            ->field('v.voucher_id,v.period,v.voucher_date,v.voucher_no,d.summary,d.subject_code,s.subject_name,d.debit_amount,d.credit_amount,d.aux_desc')
            ->order('v.voucher_date asc,v.voucher_no asc,d.line_no asc')
            ->select();

        return $this->ok($rows, 'OK', count($rows));
    }

    public function subjectBalance($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }
        $period = $data['period'] ?? '';
        if ($period === '') {
            return $this->error('会计期间不能为空');
        }
        $codeRule = $this->subjectCodeRule();
        $subjects = db('fin_subject')
            ->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])
            ->field('subject_code,subject_name,direction,subject_type,level_no,leaf_flag')
            ->order('subject_code asc')
            ->select();
        $subjectMap = [];
        $balances = [];
        foreach ($subjects as $subject) {
            $subjectMap[$subject['subject_code']] = $subject;
            $balances[$subject['subject_code']] = [
                'subject_code' => $subject['subject_code'],
                'subject_name' => $subject['subject_name'],
                'direction' => $subject['direction'],
                'subject_type' => $subject['subject_type'],
                'level_no' => (int)($subject['level_no'] ?? 1),
                'leaf_flag' => (int)($subject['leaf_flag'] ?? 1),
                'opening_debit_cents' => 0,
                'opening_credit_cents' => 0,
                'current_debit_cents' => 0,
                'current_credit_cents' => 0,
                'year_debit_cents' => 0,
                'year_credit_cents' => 0,
            ];
        }

        $openingRows = db('fin_opening_balance')
            ->where([
                'account_set_id' => $this->accountSetId,
                'period' => $this->openingPeriodFor($period, 'fin_opening_balance'),
                'del_flag' => 0,
            ])
            ->field('subject_code,debit_amount,credit_amount')
            ->select();
        foreach ($openingRows as $row) {
            $this->addSubjectBalanceAmount(
                $balances,
                $row['subject_code'],
                $subjectMap,
                $codeRule,
                'opening',
                $this->decimalToCents($row['debit_amount'] ?? 0),
                $this->decimalToCents($row['credit_amount'] ?? 0)
            );
        }

        foreach ($this->loadSubjectBalanceVoucherRows($period, $period) as $row) {
            $this->addSubjectBalanceAmount(
                $balances,
                $row['subject_code'],
                $subjectMap,
                $codeRule,
                'current',
                $this->decimalToCents($row['debit_amount'] ?? 0),
                $this->decimalToCents($row['credit_amount'] ?? 0)
            );
        }

        foreach ($this->loadSubjectBalanceVoucherRows($this->reportYearStartPeriod($period), $period) as $row) {
            $this->addSubjectBalanceAmount(
                $balances,
                $row['subject_code'],
                $subjectMap,
                $codeRule,
                'year',
                $this->decimalToCents($row['debit_amount'] ?? 0),
                $this->decimalToCents($row['credit_amount'] ?? 0)
            );
        }

        $rows = [];
        foreach ($balances as $balance) {
            $rows[] = $this->formatSubjectBalanceRow($balance);
        }

        return $this->ok($rows, 'OK', count($rows));
    }

    public function subjectSummary($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }
        $period = $data['period'] ?? '';
        if ($period === '') {
            return $this->error('会计期间不能为空');
        }
        $startDate = $data['start_date'] ?? substr($period, 0, 7) . '-01';
        $endDate = $data['end_date'] ?? date('Y-m-t', strtotime($startDate));
        $subjectStartCode = trim((string)($data['subject_start_code'] ?? ''));
        $subjectEndCode = trim((string)($data['subject_end_code'] ?? ''));
        $subjectLevel = (int)($data['subject_level'] ?? 1);
        $codeRule = $this->subjectCodeRule();
        $lengths = $this->subjectCodeRuleLengths($codeRule);
        $maxLength = empty($lengths) ? 10 : max($lengths);
        if ($subjectLevel < 1) {
            $subjectLevel = 1;
        }
        if ($subjectLevel > count($lengths)) {
            $subjectLevel = count($lengths);
        }

        $subjects = db('fin_subject')
            ->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])
            ->field('subject_code,subject_name,level_no,direction')
            ->select();
        $subjectMap = [];
        foreach ($subjects as $subject) {
            $subjectMap[$subject['subject_code']] = $subject;
        }

        $fiscalYear = $this->fiscalYear($period);
        $voucherTable = 'fin_voucher';
        $detailTable = 'fin_voucher_detail';
        $where = [
            'd.account_set_id' => $this->accountSetId,
            'd.fiscal_year' => $fiscalYear,
            'd.period' => $period,
            'd.del_flag' => 0,
            'v.fiscal_year' => $fiscalYear,
            'v.period' => $period,
            'v.del_flag' => 0,
            'v.voucher_date' => ['between', [$startDate, $endDate]],
            'v.status' => ['in', ['AUDITED', 'PRINTED']],
        ];

        $query = db($detailTable)->alias('d')
            ->join($voucherTable . ' v', 'v.voucher_id=d.voucher_id and v.account_set_id=d.account_set_id and v.fiscal_year=d.fiscal_year and v.period=d.period')
            ->where($where);
        if ($subjectStartCode !== '') {
            $query->where('d.subject_code', '>=', $subjectStartCode);
        }
        if ($subjectEndCode !== '') {
            $query->where('d.subject_code', '<=', $this->subjectRangeUpperBound($subjectEndCode, $maxLength));
        }
        $details = $query
            ->field('d.subject_code,d.debit_amount,d.credit_amount')
            ->order('d.subject_code asc')
            ->select();

        $summary = [];
        foreach ($details as $detail) {
            $summaryCode = $this->subjectSummaryCode($detail['subject_code'], $subjectLevel, $codeRule);
            if (!isset($summary[$summaryCode])) {
                $subject = $subjectMap[$summaryCode] ?? [
                    'subject_code' => $summaryCode,
                    'subject_name' => '',
                    'level_no' => $subjectLevel,
                    'direction' => '',
                ];
                $summary[$summaryCode] = [
                    'subject_code' => $summaryCode,
                    'subject_name' => $subject['subject_name'] ?? '',
                    'level_no' => (int)($subject['level_no'] ?? $subjectLevel),
                    'direction' => $subject['direction'] ?? '',
                    'debit_cents' => 0,
                    'credit_cents' => 0,
                    'entry_count' => 0,
                ];
            }
            $summary[$summaryCode]['debit_cents'] += $this->decimalToCents($detail['debit_amount']);
            $summary[$summaryCode]['credit_cents'] += $this->decimalToCents($detail['credit_amount']);
            $summary[$summaryCode]['entry_count']++;
        }
        ksort($summary);

        $rows = [];
        foreach ($summary as $item) {
            $debitCents = $item['debit_cents'];
            $creditCents = $item['credit_cents'];
            $rows[] = [
                'subject_code' => $item['subject_code'],
                'subject_name' => $item['subject_name'],
                'level_no' => $item['level_no'],
                'direction' => $item['direction'],
                'debit_amount' => $this->centsToDecimal($debitCents),
                'credit_amount' => $this->centsToDecimal($creditCents),
                'balance_amount' => $this->centsToDecimal($debitCents - $creditCents),
                'entry_count' => $item['entry_count'],
            ];
        }

        return $this->ok($rows, 'OK', count($rows));
    }

    public function auxBalance($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }
        $period = $data['period'] ?? '';
        if ($period === '') {
            return $this->error('会计期间不能为空');
        }
        $subjectCode = trim((string)($data['subject_code'] ?? ''));
        $caseNoFilter = trim((string)($data['case_no'] ?? ''));
        $receiptNoFilter = trim((string)($data['receipt_no'] ?? ''));
        $fiscalYear = $this->fiscalYear($period);
        $groups = [];
        $subjectMap = $this->subjectInfoMap();

        $openingRows = db('fin_aux_opening_balance')
            ->where([
                'account_set_id' => $this->accountSetId,
                'period' => $this->openingPeriodFor($period, 'fin_aux_opening_balance'),
                'del_flag' => 0,
            ])
            ->select();
        foreach ($openingRows as $row) {
            $rowSubjectCode = $row['subject_code'] ?? '';
            if ($subjectCode !== '' && $rowSubjectCode !== $subjectCode) {
                continue;
            }
            $auxValues = json_decode($row['aux_values_json'] ?? '{}', true);
            if (!is_array($auxValues)) {
                $auxValues = [];
            }
            $caseNo = trim((string)($auxValues['case_no'] ?? ''));
            $receiptNo = trim((string)($auxValues['receipt_no'] ?? ''));
            if (!$this->matchAuxBalanceFilter($caseNo, $receiptNo, $caseNoFilter, $receiptNoFilter)) {
                continue;
            }
            $subject = $subjectMap[$rowSubjectCode] ?? [];
            $this->addAuxBalanceAmount(
                $groups,
                $rowSubjectCode,
                $subject['subject_name'] ?? '',
                $subject['direction'] ?? 'DEBIT',
                $caseNo,
                $receiptNo,
                $this->decimalToCents($row['debit_amount'] ?? 0),
                $this->decimalToCents($row['credit_amount'] ?? 0),
                0,
                0
            );
        }

        $voucherWhere = [
            'd.account_set_id' => $this->accountSetId,
            'd.fiscal_year' => $fiscalYear,
            'd.del_flag' => 0,
            'v.fiscal_year' => $fiscalYear,
            'v.del_flag' => 0,
            'v.status' => ['in', ['AUDITED', 'PRINTED']],
            'case_aux.aux_type_code' => 'case_no',
            'case_aux.del_flag' => 0,
        ];
        if ($subjectCode !== '') {
            $voucherWhere['d.subject_code'] = $subjectCode;
        }
        $voucherQuery = db('fin_voucher_detail')->alias('d')
            ->join('fin_voucher v', 'v.voucher_id=d.voucher_id and v.account_set_id=d.account_set_id and v.fiscal_year=d.fiscal_year and v.period=d.period')
            ->join('fin_voucher_aux_value case_aux', 'case_aux.detail_id=d.detail_id and case_aux.account_set_id=d.account_set_id and case_aux.fiscal_year=d.fiscal_year and case_aux.period=d.period')
            ->join('fin_voucher_aux_value receipt_aux', "receipt_aux.detail_id=d.detail_id and receipt_aux.account_set_id=d.account_set_id and receipt_aux.fiscal_year=d.fiscal_year and receipt_aux.period=d.period and receipt_aux.aux_type_code='receipt_no' and receipt_aux.del_flag=0", 'LEFT')
            ->join('fin_subject s', 's.subject_code=d.subject_code and s.account_set_id=d.account_set_id and s.del_flag=0', 'LEFT')
            ->where($voucherWhere)
            ->where('d.period', 'between', [$this->reportYearStartPeriod($period), $period])
            ->where('v.period', 'between', [$this->reportYearStartPeriod($period), $period]);
        if ($caseNoFilter !== '') {
            $voucherQuery->where('case_aux.aux_value|case_aux.aux_label', 'like', '%' . $caseNoFilter . '%');
        }
        if ($receiptNoFilter !== '') {
            $voucherQuery->where('receipt_aux.aux_value|receipt_aux.aux_label', 'like', '%' . $receiptNoFilter . '%');
        }
        $voucherRows = $voucherQuery
            ->field('d.subject_code,s.subject_name,s.direction,d.debit_amount,d.credit_amount,case_aux.aux_value as case_no,case_aux.aux_label as case_label,receipt_aux.aux_value as receipt_no,receipt_aux.aux_label as receipt_label')
            ->order('d.subject_code asc,case_aux.aux_value asc,receipt_aux.aux_value asc')
            ->select();
        foreach ($voucherRows as $row) {
            $caseNo = trim((string)($row['case_label'] ?: $row['case_no']));
            $receiptNo = trim((string)($row['receipt_label'] ?: $row['receipt_no']));
            $this->addAuxBalanceAmount(
                $groups,
                $row['subject_code'],
                $row['subject_name'] ?? '',
                $row['direction'] ?? 'DEBIT',
                $caseNo,
                $receiptNo,
                0,
                0,
                $this->decimalToCents($row['debit_amount'] ?? 0),
                $this->decimalToCents($row['credit_amount'] ?? 0)
            );
        }

        $rows = $this->buildAuxBalanceRows($groups);
        return $this->ok([
            'items' => $rows,
            'total' => count($rows),
            'period' => $period,
        ], 'OK', count($rows));
    }

    public function auxBalanceSubjects($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }

        $rows = db('fin_subject')->alias('s')
            ->join('fin_subject_aux_config case_cfg', 'case_cfg.subject_code=s.subject_code and case_cfg.account_set_id=s.account_set_id and case_cfg.del_flag=0')
            ->join('fin_subject_aux_config receipt_cfg', 'receipt_cfg.subject_code=s.subject_code and receipt_cfg.account_set_id=s.account_set_id and receipt_cfg.del_flag=0')
            ->where([
                's.account_set_id' => $this->accountSetId,
                's.del_flag' => 0,
                's.status' => 1,
                's.leaf_flag' => 1,
                's.voucher_entry_flag' => 1,
                'case_cfg.aux_type_code' => 'case_no',
                'receipt_cfg.aux_type_code' => 'receipt_no',
            ])
            ->field('s.subject_code,s.subject_name,s.direction')
            ->group('s.subject_code,s.subject_name,s.direction')
            ->order('s.subject_code asc')
            ->select();

        return $this->ok($rows, 'OK', count($rows));
    }

    protected function loadSubjectBalanceVoucherRows($startPeriod, $endPeriod)
    {
        $fiscalYear = $this->fiscalYear($endPeriod);
        return db('fin_voucher_detail')->alias('d')
            ->join('fin_voucher v', 'v.voucher_id=d.voucher_id and v.account_set_id=d.account_set_id and v.fiscal_year=d.fiscal_year and v.period=d.period')
            ->where([
                'd.account_set_id' => $this->accountSetId,
                'd.fiscal_year' => $fiscalYear,
                'd.del_flag' => 0,
                'v.fiscal_year' => $fiscalYear,
                'v.del_flag' => 0,
                'v.status' => ['in', ['AUDITED', 'PRINTED']],
            ])
            ->where('d.period', 'between', [$startPeriod, $endPeriod])
            ->where('v.period', 'between', [$startPeriod, $endPeriod])
            ->field('d.subject_code,coalesce(sum(d.debit_amount),0) as debit_amount,coalesce(sum(d.credit_amount),0) as credit_amount')
            ->group('d.subject_code')
            ->order('d.subject_code asc')
            ->select();
    }

    protected function addSubjectBalanceAmount(&$balances, $subjectCode, $subjectMap, $codeRule, $bucket, $debitCents, $creditCents)
    {
        foreach ($this->ancestorSubjectCodes($subjectCode, $subjectMap, $codeRule) as $code) {
            if (!isset($balances[$code])) {
                continue;
            }
            $balances[$code][$bucket . '_debit_cents'] += $debitCents;
            $balances[$code][$bucket . '_credit_cents'] += $creditCents;
        }
    }

    protected function ancestorSubjectCodes($subjectCode, $subjectMap, $codeRule)
    {
        $codes = [];
        $lengths = $this->subjectCodeRuleLengths($codeRule);
        foreach ($lengths as $length) {
            if (strlen($subjectCode) < $length) {
                continue;
            }
            $code = substr($subjectCode, 0, $length);
            if (isset($subjectMap[$code])) {
                $codes[] = $code;
            }
        }
        if (isset($subjectMap[$subjectCode]) && !in_array($subjectCode, $codes, true)) {
            $codes[] = $subjectCode;
        }
        return array_values(array_unique($codes));
    }

    protected function formatSubjectBalanceRow($row)
    {
        $openingDebitCents = (int)$row['opening_debit_cents'];
        $openingCreditCents = (int)$row['opening_credit_cents'];
        $yearDebitCents = (int)$row['year_debit_cents'];
        $yearCreditCents = (int)$row['year_credit_cents'];
        $endingNetCents = $openingDebitCents - $openingCreditCents + $yearDebitCents - $yearCreditCents;
        $endingDebitCents = $endingNetCents > 0 ? $endingNetCents : 0;
        $endingCreditCents = $endingNetCents < 0 ? abs($endingNetCents) : 0;
        $balanceDirection = $endingNetCents > 0 ? 'DEBIT' : ($endingNetCents < 0 ? 'CREDIT' : 'BALANCED');

        return [
            'subject_code' => $row['subject_code'],
            'subject_name' => $row['subject_name'],
            'direction' => $row['direction'],
            'subject_type' => $row['subject_type'] ?? '',
            'level_no' => (int)$row['level_no'],
            'leaf_flag' => (int)$row['leaf_flag'],
            'opening_debit_amount' => $this->centsToDecimal($openingDebitCents),
            'opening_credit_amount' => $this->centsToDecimal($openingCreditCents),
            'debit_amount' => $this->centsToDecimal($row['current_debit_cents']),
            'credit_amount' => $this->centsToDecimal($row['current_credit_cents']),
            'year_debit_amount' => $this->centsToDecimal($yearDebitCents),
            'year_credit_amount' => $this->centsToDecimal($yearCreditCents),
            'ending_debit_amount' => $this->centsToDecimal($endingDebitCents),
            'ending_credit_amount' => $this->centsToDecimal($endingCreditCents),
            'balance_amount' => $this->centsToDecimal($endingNetCents),
            'balance_direction' => $balanceDirection,
        ];
    }

    protected function reportYearStartPeriod($period)
    {
        $yearStart = substr((string)$period, 0, 4) . '-01';
        $enabledPeriod = $this->accountSetEnabledPeriod();
        if ($this->validPeriod($enabledPeriod) && substr($enabledPeriod, 0, 4) === substr((string)$period, 0, 4) && $enabledPeriod > $yearStart && $enabledPeriod <= $period) {
            return $enabledPeriod;
        }
        return $yearStart;
    }

    protected function openingPeriodFor($period, $table)
    {
        $enabledPeriod = $this->accountSetEnabledPeriod();
        if ($this->validPeriod($enabledPeriod) && substr($enabledPeriod, 0, 4) === substr((string)$period, 0, 4) && $enabledPeriod <= $period) {
            return $enabledPeriod;
        }
        $yearStart = substr((string)$period, 0, 4) . '-01';
        $existing = db($table)
            ->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])
            ->where('period', 'between', [$yearStart, $period])
            ->order('period asc')
            ->value('period');
        return $existing ?: $this->reportYearStartPeriod($period);
    }

    protected function accountSetEnabledPeriod()
    {
        return db('fin_account_set')
            ->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])
            ->value('enabled_period') ?: '';
    }

    protected function validPeriod($period)
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$period) === 1;
    }

    protected function subjectInfoMap()
    {
        $subjects = db('fin_subject')
            ->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])
            ->field('subject_code,subject_name,direction')
            ->select();
        $map = [];
        foreach ($subjects as $subject) {
            $map[$subject['subject_code']] = $subject;
        }
        return $map;
    }

    protected function matchAuxBalanceFilter($caseNo, $receiptNo, $caseNoFilter, $receiptNoFilter)
    {
        if ($caseNo === '') {
            return false;
        }
        if ($caseNoFilter !== '' && strpos($caseNo, $caseNoFilter) === false) {
            return false;
        }
        if ($receiptNoFilter !== '' && strpos($receiptNo, $receiptNoFilter) === false) {
            return false;
        }
        return true;
    }

    protected function addAuxBalanceAmount(&$groups, $subjectCode, $subjectName, $direction, $caseNo, $receiptNo, $openingDebitCents, $openingCreditCents, $debitCents, $creditCents)
    {
        $caseNo = trim((string)$caseNo);
        if ($caseNo === '') {
            return;
        }
        $receiptNo = trim((string)$receiptNo);
        $key = $this->auxBalanceKey($subjectCode, $caseNo, $receiptNo);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'subject_code' => $subjectCode,
                'subject_name' => $subjectName,
                'direction' => $direction ?: 'DEBIT',
                'case_no' => $caseNo,
                'receipt_no' => $receiptNo,
                'opening_debit_cents' => 0,
                'opening_credit_cents' => 0,
                'debit_cents' => 0,
                'credit_cents' => 0,
                'entry_count' => 0,
            ];
        }
        $groups[$key]['opening_debit_cents'] += $openingDebitCents;
        $groups[$key]['opening_credit_cents'] += $openingCreditCents;
        $groups[$key]['debit_cents'] += $debitCents;
        $groups[$key]['credit_cents'] += $creditCents;
        if ($debitCents !== 0 || $creditCents !== 0) {
            $groups[$key]['entry_count']++;
        }
    }

    protected function buildAuxBalanceRows($groups)
    {
        ksort($groups);
        $caseRows = [];
        foreach ($groups as $detail) {
            $caseKey = $detail['subject_code'] . '|' . $detail['case_no'];
            if (!isset($caseRows[$caseKey])) {
                $caseRows[$caseKey] = [
                    'row_key' => 'case|' . $caseKey,
                    'row_type' => 'CASE',
                    'subject_code' => $detail['subject_code'],
                    'subject_name' => $detail['subject_name'],
                    'direction' => $detail['direction'],
                    'case_no' => $detail['case_no'],
                    'receipt_no' => '',
                    'opening_debit_cents' => 0,
                    'opening_credit_cents' => 0,
                    'debit_cents' => 0,
                    'credit_cents' => 0,
                    'entry_count' => 0,
                    'monitor_count' => 0,
                    'children' => [],
                ];
            }
            $receiptRow = $this->formatAuxBalanceRow('RECEIPT', $detail);
            $caseRows[$caseKey]['children'][] = $receiptRow;
            foreach (['opening_debit_cents', 'opening_credit_cents', 'debit_cents', 'credit_cents', 'entry_count'] as $field) {
                $caseRows[$caseKey][$field] += $detail[$field];
            }
            if ($receiptRow['monitor_flag']) {
                $caseRows[$caseKey]['monitor_count']++;
            }
        }
        $rows = [];
        foreach ($caseRows as $caseRow) {
            $rows[] = $this->formatAuxBalanceRow('CASE', $caseRow);
        }
        return $rows;
    }

    protected function formatAuxBalanceRow($rowType, $row)
    {
        $openingBalanceCents = $this->normalBalanceCents($row['direction'], $row['opening_debit_cents'], $row['opening_credit_cents']);
        $endingBalanceCents = $this->normalBalanceCents(
            $row['direction'],
            $row['opening_debit_cents'] + $row['debit_cents'],
            $row['opening_credit_cents'] + $row['credit_cents']
        );
        $result = [
            'row_key' => $rowType === 'CASE' ? $row['row_key'] : 'receipt|' . $this->auxBalanceKey($row['subject_code'], $row['case_no'], $row['receipt_no']),
            'row_type' => $rowType,
            'subject_code' => $row['subject_code'],
            'subject_name' => $row['subject_name'],
            'direction' => $row['direction'],
            'case_no' => $row['case_no'],
            'receipt_no' => $row['receipt_no'],
            'opening_balance_amount' => $this->centsToDecimal($openingBalanceCents),
            'debit_amount' => $this->centsToDecimal($row['debit_cents']),
            'credit_amount' => $this->centsToDecimal($row['credit_cents']),
            'ending_balance_amount' => $this->centsToDecimal($endingBalanceCents),
            'entry_count' => $row['entry_count'],
            'monitor_flag' => $rowType === 'RECEIPT' && $endingBalanceCents !== 0,
            'monitor_count' => $row['monitor_count'] ?? 0,
        ];
        if ($rowType === 'CASE') {
            $result['children'] = $row['children'];
        }
        return $result;
    }

    protected function normalBalanceCents($direction, $debitCents, $creditCents)
    {
        return strtoupper((string)$direction) === 'CREDIT'
            ? (int)$creditCents - (int)$debitCents
            : (int)$debitCents - (int)$creditCents;
    }

    protected function auxBalanceKey($subjectCode, $caseNo, $receiptNo)
    {
        return $subjectCode . '|' . $caseNo . '|' . $receiptNo;
    }

    protected function subjectCodeRule()
    {
        $rule = db('fin_account_set')
            ->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])
            ->value('subject_code_rule');
        $parts = array_filter(explode('-', (string)($rule ?: '4-2-2-2')), 'strlen');
        $result = [];
        foreach ($parts as $part) {
            $length = (int)$part;
            if ($length > 0) {
                $result[] = $length;
            }
        }
        return empty($result) ? [4, 2, 2, 2] : $result;
    }

    protected function subjectCodeRuleLengths($codeRule)
    {
        $lengths = [];
        $total = 0;
        foreach ($codeRule as $length) {
            $total += (int)$length;
            $lengths[] = $total;
        }
        return $lengths;
    }

    protected function subjectSummaryCode($subjectCode, $subjectLevel, $codeRule)
    {
        $lengths = $this->subjectCodeRuleLengths($codeRule);
        if (empty($lengths)) {
            return $subjectCode;
        }
        $index = max(0, min((int)$subjectLevel, count($lengths)) - 1);
        $targetLength = $lengths[$index];
        if (strlen($subjectCode) <= $targetLength) {
            return $subjectCode;
        }
        return substr($subjectCode, 0, $targetLength);
    }

    protected function subjectRangeUpperBound($subjectCode, $maxLength)
    {
        $subjectCode = trim((string)$subjectCode);
        if ($subjectCode === '') {
            return '';
        }
        $maxLength = max(strlen($subjectCode), (int)$maxLength);
        return str_pad($subjectCode, $maxLength, '9');
    }
}
