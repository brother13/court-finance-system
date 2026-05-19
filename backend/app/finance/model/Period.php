<?php

namespace app\finance\model;

use think\Db;

class Period extends Common
{
    const ACTION = 'period';
    const TABLE = 'fin_fiscal_period';

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'list':
                return $this->getList();
            case 'closeCheck':
                return $this->closeCheck($data);
            case 'close':
                return $this->close($data);
            case 'reopen':
                return $this->reopen($data);
            case 'yearEnd':
                return $this->yearEnd($data);
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function getList()
    {
        $where = $this->accountWhere();
        $where['period'] = ['like', $this->year . '-%'];
        $rows = $this->getdb(self::TABLE)->where($where)->order('period asc')->select();
        return $this->ok($rows, 'OK', count($rows));
    }

    public function closeCheck($data = [])
    {
        $auth = $this->requirePermission('period:close');
        if ($auth) {
            return $auth;
        }
        $period = trim((string)($data['period'] ?? input('param.period', '')));
        if (!$this->validPeriod($period)) {
            return $this->error('会计期间格式不正确');
        }
        $status = $this->periodStatus($period);
        $blocks = $this->periodCloseBlockItems($period, $status, $this->voucherStatusCounts($period));
        $warnings = $this->periodCloseWarningItems($period);
        return $this->ok([
            'period' => $period,
            'status' => $status,
            'can_close' => empty($blocks) ? 1 : 0,
            'blocks' => $blocks,
            'warnings' => $warnings,
        ], 'OK', count($blocks) + count($warnings));
    }

    public function close($data = [])
    {
        $auth = $this->requirePermission('period:close');
        if ($auth) {
            return $auth;
        }
        $period = trim((string)($data['period'] ?? ''));
        if (!$this->validPeriod($period)) {
            return $this->error('会计期间格式不正确');
        }
        $row = $this->periodRow($period);
        if (!$row) {
            return $this->error('会计期间不存在');
        }
        $blocks = $this->periodCloseBlockItems($period, $row['status'] ?? '', $this->voucherStatusCounts($period));
        if (!empty($blocks)) {
            return $this->error($blocks[0]['message'], ['blocks' => $blocks]);
        }

        $update = ['status' => 'CLOSED'];
        $this->fillUpdate($update);
        $this->getdb(self::TABLE)->where([
            'account_set_id' => $this->accountSetId,
            'period' => $period,
            'del_flag' => 0,
        ])->update($update);
        $this->logAudit('FISCAL_PERIOD', $period, 'CLOSE', $row, $update);
        return $this->ok([
            'period' => $period,
            'status' => 'CLOSED',
            'next_period' => $this->nextPeriodValue($period),
            'warnings' => $this->periodCloseWarningItems($period),
        ], '结账成功');
    }

    public function reopen($data = [])
    {
        $auth = $this->requirePermission('period:close');
        if ($auth) {
            return $auth;
        }
        $period = trim((string)($data['period'] ?? ''));
        if (!$this->validPeriod($period)) {
            return $this->error('会计期间格式不正确');
        }
        $row = $this->periodRow($period);
        if (!$row) {
            return $this->error('会计期间不存在');
        }
        if (($row['status'] ?? '') !== 'CLOSED') {
            return $this->error('只有已结账期间允许反结账');
        }
        $laterClosed = $this->getdb(self::TABLE)->where([
            'account_set_id' => $this->accountSetId,
            'status' => 'CLOSED',
            'del_flag' => 0,
        ])->where('period', '>', $period)->count();
        if ((int)$laterClosed > 0) {
            return $this->error('只能反结账最近一个已结账期间');
        }
        $update = ['status' => 'OPEN'];
        $this->fillUpdate($update);
        $this->getdb(self::TABLE)->where([
            'account_set_id' => $this->accountSetId,
            'period' => $period,
            'del_flag' => 0,
        ])->update($update);
        $this->logAudit('FISCAL_PERIOD', $period, 'REOPEN', $row, $update);
        return $this->ok(['period' => $period, 'status' => 'OPEN'], '反结账成功');
    }

    public function yearEnd($data = [])
    {
        $auth = $this->requirePermission('period:close');
        if ($auth) {
            return $auth;
        }
        $period = trim((string)($data['period'] ?? ''));
        if (!$this->validPeriod($period)) {
            return $this->error('会计期间格式不正确');
        }
        $row = $this->periodRow($period);
        if (!$row) {
            return $this->error('会计期间不存在');
        }
        $reason = $this->yearEndBlockReason($period, $row['status'] ?? '');
        if ($reason !== '') {
            return $this->error($reason);
        }
        $nextYear = ((int)substr($period, 0, 4)) + 1;
        $nextOpeningPeriod = sprintf('%04d-01', $nextYear);
        if ($this->hasOpeningRows($nextOpeningPeriod)) {
            return $this->error($nextOpeningPeriod . ' 已存在期初余额，不能重复年结');
        }

        Db::startTrans();
        try {
            $createdPeriods = $this->ensureYearPeriods($nextYear);
            $subjectSaved = $this->createNextYearSubjectOpening($period, $nextOpeningPeriod);
            $auxSaved = $this->createNextYearAuxOpening($period, $nextOpeningPeriod);
            $after = [
                'source_period' => $period,
                'next_year' => $nextYear,
                'opening_period' => $nextOpeningPeriod,
                'created_periods' => $createdPeriods,
                'subject_opening_count' => $subjectSaved,
                'aux_opening_count' => $auxSaved,
            ];
            $this->logAudit('FISCAL_YEAR', (string)$nextYear, 'YEAR_END', $row, $after);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('年结失败：' . $e->getMessage());
        }

        return $this->ok($after, '年结成功');
    }

    protected function periodCloseBlockItems($period, $periodStatus, $voucherStatusCounts)
    {
        $blocks = [];
        if ($periodStatus !== 'OPEN') {
            $blocks[] = [
                'code' => 'PERIOD_NOT_OPEN',
                'message' => $period . ' 不是开放状态，不能结账',
            ];
            return $blocks;
        }
        $labels = [
            'DRAFT' => '草稿',
            'SUBMITTED' => '未审核',
            'AUDITED' => '已审核未记账',
        ];
        foreach ($labels as $status => $label) {
            $count = (int)($voucherStatusCounts[$status] ?? 0);
            if ($count > 0) {
                $blocks[] = [
                    'code' => 'VOUCHER_' . $status,
                    'message' => $period . ' 存在' . $count . '张' . $label . '凭证，请先处理并记账',
                    'count' => $count,
                ];
            }
        }
        return $blocks;
    }

    protected function periodCloseWarningItems($period)
    {
        $warnings = [];
        $paymentCount = $this->safeCount('fin_case_fund_payment', [
            'account_set_id' => $this->accountSetId,
            'period' => $period,
            'voucher_status' => 'UNGENERATED',
            'del_flag' => 0,
        ]);
        if ($paymentCount > 0) {
            $warnings[] = ['code' => 'CASE_PAYMENT_UNGENERATED', 'message' => '存在未制证缴费登记，允许结账但请后续跟踪', 'count' => $paymentCount];
        }
        $refundCount = $this->safeCount('fin_case_fund_refund', [
            'account_set_id' => $this->accountSetId,
            'period' => $period,
            'voucher_status' => 'UNGENERATED',
            'del_flag' => 0,
        ]);
        if ($refundCount > 0) {
            $warnings[] = ['code' => 'CASE_REFUND_UNGENERATED', 'message' => '存在未制证退付登记，退款失败等业务可不制证', 'count' => $refundCount];
        }
        $bankCount = $this->safeCount('fin_case_fund_bank_statement', [
            'account_set_id' => $this->accountSetId,
            'period' => $period,
            'reconcile_status' => 'UNMATCHED',
            'del_flag' => 0,
        ]);
        if ($bankCount > 0) {
            $warnings[] = ['code' => 'BANK_UNMATCHED', 'message' => '存在未匹配银行流水，允许结账但请关注对账风险', 'count' => $bankCount];
        }
        return $warnings;
    }

    protected function voucherStatusCounts($period)
    {
        $rows = $this->getdb('fin_voucher')->where([
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => (int)substr($period, 0, 4),
            'period' => $period,
            'del_flag' => 0,
        ])->field('status,count(*) as total')->group('status')->select();
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int)$row['total'];
        }
        return $counts;
    }

    protected function yearEndBlockReason($period, $periodStatus)
    {
        if (substr($period, 5, 2) !== '12') {
            return '只有12月结账后才能生成下一年度';
        }
        if ($periodStatus !== 'CLOSED') {
            return '12月必须先期末结账，才能生成下一年度';
        }
        return '';
    }

    protected function nextPeriodValue($period)
    {
        $year = (int)substr($period, 0, 4);
        $month = (int)substr($period, 5, 2);
        if ($month >= 12) {
            return sprintf('%04d-01', $year + 1);
        }
        return sprintf('%04d-%02d', $year, $month + 1);
    }

    protected function yearPeriodValues($year)
    {
        $values = [];
        for ($month = 1; $month <= 12; $month++) {
            $values[] = sprintf('%04d-%02d', (int)$year, $month);
        }
        return $values;
    }

    protected function ensureYearPeriods($year)
    {
        $created = 0;
        foreach ($this->yearPeriodValues($year) as $period) {
            $exists = $this->getdb(self::TABLE)->where([
                'account_set_id' => $this->accountSetId,
                'period' => $period,
                'del_flag' => 0,
            ])->find();
            if ($exists) {
                continue;
            }
            $row = [
                'period_id' => uuid(),
                'account_set_id' => $this->accountSetId,
                'period' => $period,
                'start_date' => $period . '-01',
                'end_date' => date('Y-m-t', strtotime($period . '-01')),
                'status' => 'OPEN',
            ];
            $this->fillCreate($row);
            $this->getdb(self::TABLE)->insert($row);
            $created++;
        }
        return $created;
    }

    protected function createNextYearSubjectOpening($sourcePeriod, $targetPeriod)
    {
        $balances = $this->subjectEndingBalances($sourcePeriod);
        $saved = 0;
        foreach ($balances as $subjectCode => $cents) {
            if ($cents === 0) {
                continue;
            }
            $row = [
                'balance_id' => uuid(),
                'account_set_id' => $this->accountSetId,
                'period' => $targetPeriod,
                'subject_code' => $subjectCode,
                'debit_amount' => $this->centsToDecimal($cents > 0 ? $cents : 0),
                'credit_amount' => $this->centsToDecimal($cents < 0 ? abs($cents) : 0),
                'remark' => '由' . $sourcePeriod . '年结生成',
            ];
            $this->fillCreate($row);
            $this->getdb('fin_opening_balance')->insert($row);
            $saved++;
        }
        return $saved;
    }

    protected function createNextYearAuxOpening($sourcePeriod, $targetPeriod)
    {
        $balances = $this->auxEndingBalances($sourcePeriod);
        $saved = 0;
        foreach ($balances as $key => $item) {
            $cents = (int)$item['cents'];
            if ($cents === 0) {
                continue;
            }
            $row = [
                'balance_id' => uuid(),
                'account_set_id' => $this->accountSetId,
                'period' => $targetPeriod,
                'subject_code' => $item['subject_code'],
                'aux_values_json' => $item['aux_values_json'],
                'aux_desc' => $item['aux_desc'],
                'debit_amount' => $this->centsToDecimal($cents > 0 ? $cents : 0),
                'credit_amount' => $this->centsToDecimal($cents < 0 ? abs($cents) : 0),
                'remark' => '由' . $sourcePeriod . '年结生成',
            ];
            $this->fillCreate($row);
            $this->getdb('fin_aux_opening_balance')->insert($row);
            $saved++;
        }
        return $saved;
    }

    protected function subjectEndingBalances($sourcePeriod)
    {
        $year = substr($sourcePeriod, 0, 4);
        $startPeriod = $year . '-01';
        $balances = [];
        $openingRows = $this->getdb('fin_opening_balance')->where([
            'account_set_id' => $this->accountSetId,
            'period' => $this->openingPeriodForYear($year, 'fin_opening_balance'),
            'del_flag' => 0,
        ])->field('subject_code,debit_amount,credit_amount')->select();
        foreach ($openingRows as $row) {
            $code = $row['subject_code'];
            $balances[$code] = ($balances[$code] ?? 0)
                + $this->decimalToCents($row['debit_amount'] ?? 0)
                - $this->decimalToCents($row['credit_amount'] ?? 0);
        }
        $rows = $this->getdb('fin_voucher_detail')->alias('d')
            ->join('fin_voucher v', 'v.voucher_id=d.voucher_id and v.account_set_id=d.account_set_id and v.fiscal_year=d.fiscal_year and v.period=d.period')
            ->where([
                'd.account_set_id' => $this->accountSetId,
                'd.fiscal_year' => (int)$year,
                'd.del_flag' => 0,
                'v.del_flag' => 0,
                'v.status' => 'POSTED',
            ])
            ->where('d.period', 'between', [$startPeriod, $sourcePeriod])
            ->where('v.period', 'between', [$startPeriod, $sourcePeriod])
            ->field('d.subject_code,coalesce(sum(d.debit_amount),0) as debit_amount,coalesce(sum(d.credit_amount),0) as credit_amount')
            ->group('d.subject_code')
            ->select();
        foreach ($rows as $row) {
            $code = $row['subject_code'];
            $balances[$code] = ($balances[$code] ?? 0)
                + $this->decimalToCents($row['debit_amount'] ?? 0)
                - $this->decimalToCents($row['credit_amount'] ?? 0);
        }
        return $balances;
    }

    protected function auxEndingBalances($sourcePeriod)
    {
        $year = substr($sourcePeriod, 0, 4);
        $startPeriod = $year . '-01';
        $groups = [];
        $openingRows = $this->getdb('fin_aux_opening_balance')->where([
            'account_set_id' => $this->accountSetId,
            'period' => $this->openingPeriodForYear($year, 'fin_aux_opening_balance'),
            'del_flag' => 0,
        ])->field('subject_code,aux_values_json,aux_desc,debit_amount,credit_amount')->select();
        foreach ($openingRows as $row) {
            $key = $row['subject_code'] . '|' . $this->normalizeAuxJson($row['aux_values_json'] ?? '{}');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'subject_code' => $row['subject_code'],
                    'aux_values_json' => $this->normalizeAuxJson($row['aux_values_json'] ?? '{}'),
                    'aux_desc' => $row['aux_desc'] ?? '',
                    'cents' => 0,
                ];
            }
            $groups[$key]['cents'] += $this->decimalToCents($row['debit_amount'] ?? 0) - $this->decimalToCents($row['credit_amount'] ?? 0);
        }

        $details = $this->getdb('fin_voucher_detail')->alias('d')
            ->join('fin_voucher v', 'v.voucher_id=d.voucher_id and v.account_set_id=d.account_set_id and v.fiscal_year=d.fiscal_year and v.period=d.period')
            ->where([
                'd.account_set_id' => $this->accountSetId,
                'd.fiscal_year' => (int)$year,
                'd.del_flag' => 0,
                'v.del_flag' => 0,
                'v.status' => 'POSTED',
            ])
            ->where('d.period', 'between', [$startPeriod, $sourcePeriod])
            ->where('v.period', 'between', [$startPeriod, $sourcePeriod])
            ->field('d.detail_id,d.subject_code,d.debit_amount,d.credit_amount,d.aux_desc')
            ->select();
        foreach ($details as $detail) {
            $auxRows = $this->getdb('fin_voucher_aux_value')->where([
                'account_set_id' => $this->accountSetId,
                'fiscal_year' => (int)$year,
                'detail_id' => $detail['detail_id'],
                'del_flag' => 0,
            ])->field('aux_type_code,aux_value')->order('aux_type_code asc')->select();
            if (empty($auxRows)) {
                continue;
            }
            $auxValues = [];
            foreach ($auxRows as $aux) {
                $auxValues[$aux['aux_type_code']] = $aux['aux_value'];
            }
            $auxJson = $this->normalizeAuxJson(json_encode($auxValues, JSON_UNESCAPED_UNICODE));
            $key = $detail['subject_code'] . '|' . $auxJson;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'subject_code' => $detail['subject_code'],
                    'aux_values_json' => $auxJson,
                    'aux_desc' => $detail['aux_desc'] ?? '',
                    'cents' => 0,
                ];
            }
            $groups[$key]['cents'] += $this->decimalToCents($detail['debit_amount'] ?? 0) - $this->decimalToCents($detail['credit_amount'] ?? 0);
        }
        return $groups;
    }

    protected function normalizeAuxJson($json)
    {
        $decoded = json_decode((string)$json, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        ksort($decoded);
        return json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }

    protected function openingPeriodForYear($year, $table)
    {
        $enabledPeriod = $this->accountSetEnabledPeriod();
        if ($this->validPeriod($enabledPeriod) && substr($enabledPeriod, 0, 4) === (string)$year) {
            return $enabledPeriod;
        }
        $existing = $this->getdb($table)->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->where('period', 'between', [$year . '-01', $year . '-12'])->order('period asc')->value('period');
        return $existing ?: $year . '-01';
    }

    protected function accountSetEnabledPeriod()
    {
        return $this->getdb('fin_account_set')->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->value('enabled_period') ?: '';
    }

    protected function hasOpeningRows($period)
    {
        $subjectCount = $this->safeCount('fin_opening_balance', [
            'account_set_id' => $this->accountSetId,
            'period' => $period,
            'del_flag' => 0,
        ]);
        $auxCount = $this->safeCount('fin_aux_opening_balance', [
            'account_set_id' => $this->accountSetId,
            'period' => $period,
            'del_flag' => 0,
        ]);
        return ($subjectCount + $auxCount) > 0;
    }

    protected function periodRow($period)
    {
        return $this->getdb(self::TABLE)->where([
            'account_set_id' => $this->accountSetId,
            'period' => $period,
            'del_flag' => 0,
        ])->find();
    }

    protected function periodStatus($period)
    {
        $row = $this->periodRow($period);
        return $row['status'] ?? '';
    }

    protected function safeCount($table, $where)
    {
        try {
            return (int)$this->getdb($table)->where($where)->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    protected function validPeriod($period)
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$period) === 1;
    }
}
