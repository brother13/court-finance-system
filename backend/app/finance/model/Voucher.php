<?php

namespace app\finance\model;

use think\Db;

class Voucher extends Common
{
    const ACTION = 'voucher';
    const TABLE = 'fin_voucher';
    const TABLE_DETAIL = 'fin_voucher_detail';
    const TABLE_AUX = 'fin_voucher_aux_value';
    const TABLE_NO = 'fin_voucher_no_sequence';
    const TABLE_CONFIG = 'fin_subject_aux_config';

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'nextNo':
                return $this->peekVoucherNo($data);
            case 'list':
                return $this->getList($data);
            case 'info':
                return $this->getInfo($data);
            case 'draft':
                return $this->saveVoucher($data, 'DRAFT');
            case 'add':
            case 'submit':
                return $this->saveVoucher($data, 'SUBMITTED');
            case 'save':
                return $this->saveVoucher($data, $data['status'] ?? 'DRAFT');
            case 'audit':
                return $this->changeStatus($data, 'SUBMITTED', 'AUDITED', 'AUDIT');
            case 'unaudit':
                return $this->changeStatus($data, 'AUDITED', 'SUBMITTED', 'UNAUDIT');
            case 'post':
                return $this->postVoucher($data);
            case 'batchPost':
                return $this->batchPostVouchers($data);
            case 'unpost':
                return $this->unpostVoucher($data);
            case 'batchUnpost':
                return $this->batchUnpostVouchers($data);
            case 'delete':
            case 'del':
                return $this->deleteVoucher($data);
            case 'batchDelete':
                return $this->batchDeleteVouchers($data);
            case 'void':
                return $this->voidVoucher($data);
            case 'printMark':
                return $this->printMark($data);
            case 'printPdf':
                return $this->printPdf($data);
            case 'printData':
                return $this->printData($data);
            case 'import':
                return $this->importVouchers($data);
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function getList($data = [])
    {
        $auth = $this->requirePermission('voucher:view');
        if ($auth) {
            return $auth;
        }
        $periodRange = $this->resolveVoucherListPeriodRange($data);
        if (!$periodRange) {
            return $this->error('会计期间不能为空');
        }
        $page = $data['page'] ?? input('param.page', 1);
        $pagesize = $data['pagesize'] ?? input('param.pagesize', 50);
        $table = self::TABLE;

        $voucherIds = $this->voucherIdsByDetailFilters($periodRange, $data);
        if (is_array($voucherIds)) {
            if (empty($voucherIds)) {
                return $this->ok(['items' => [], 'total' => 0], 'OK', 0);
            }
        }

        $num = $this->buildVoucherListQuery($table, $periodRange, $data, $voucherIds)->count();
        $rows = $this->buildVoucherListQuery($table, $periodRange, $data, $voucherIds)
            ->order('voucher_date asc, voucher_no asc')
            ->page($page, $pagesize)
            ->select();
        $this->fillUserDisplayNames($rows);
        return $this->ok(['items' => $rows, 'total' => $num], 'OK', $num);
    }

    protected function resolveVoucherListPeriodRange($data)
    {
        $period = $data['period'] ?? input('param.period', '');
        $start = $data['period_start'] ?? $period;
        $end = $data['period_end'] ?? $period;
        $start = trim((string)$start);
        $end = trim((string)$end);
        if ($start === '' && $end !== '') {
            $start = $end;
        }
        if ($end === '' && $start !== '') {
            $end = $start;
        }
        if ($start === '' || $end === '') {
            return null;
        }
        if ($start > $end) {
            $tmp = $start;
            $start = $end;
            $end = $tmp;
        }
        return [
            'start' => $start,
            'end' => $end,
            'year' => $this->fiscalYear($start),
        ];
    }

    protected function buildVoucherListQuery($table, $periodRange, $data, $voucherIds)
    {
        $query = $this->getdb($table)->where($this->buildVoucherHeaderWhere($periodRange, $data));
        if (is_array($voucherIds)) {
            $query->where('voucher_id', 'in', $voucherIds);
        }
        return $query;
    }

    protected function buildVoucherHeaderWhere($periodRange, $data)
    {
        $where = $this->accountWhere();
        $where['fiscal_year'] = $periodRange['year'];
        $where['period'][] = ['>=', $periodRange['start']];
        $where['period'][] = ['<=', $periodRange['end']];

        $summaryKeyword = trim($data['summary_keyword'] ?? ($data['keyword'] ?? ''));
        if ($summaryKeyword !== '') {
            $where['summary'] = ['like', "%{$summaryKeyword}%"];
        }
        if (!empty($data['status'])) {
            if ($data['status'] === 'PRINTED') {
                $where['printed_flag'] = '1';
            } else {
                $where['status'] = $data['status'];
            }
        }
        if (isset($data['voucher_no_start']) && $data['voucher_no_start'] !== '') {
            $where['voucher_no'][] = ['>=', (int)$data['voucher_no_start']];
        }
        if (isset($data['voucher_no_end']) && $data['voucher_no_end'] !== '') {
            $where['voucher_no'][] = ['<=', (int)$data['voucher_no_end']];
        }
        if (!empty($data['date_start'])) {
            $where['voucher_date'][] = ['>=', $data['date_start']];
        }
        if (!empty($data['date_end'])) {
            $where['voucher_date'][] = ['<=', $data['date_end']];
        }
        if (!empty($data['prepared_by'])) {
            $where['prepared_by'] = ['like', '%' . trim($data['prepared_by']) . '%'];
        }
        if ($this->currentViewScope() === 'SELF') {
            $where['prepared_by'] = $this->userid;
        }
        if (!empty($data['audit_by'])) {
            $where['audit_by'] = ['like', '%' . trim($data['audit_by']) . '%'];
        }
        if (!empty($data['posted_by'])) {
            $where['posted_by'] = ['like', '%' . trim($data['posted_by']) . '%'];
        }
        $redSourceTypes = ['RED', 'RED_REVERSAL', 'REVERSAL'];
        $sourceType = $data['source_type'] ?? '';
        if ($sourceType !== '') {
            $where['source_type'] = $sourceType;
        }
        if (isset($data['red_flag']) && $data['red_flag'] !== '') {
            if ((string)$data['red_flag'] === '1') {
                $where['source_type'] = $sourceType === ''
                    ? ['in', $redSourceTypes]
                    : (in_array($sourceType, $redSourceTypes) ? $sourceType : '__NO_MATCH__');
            } else {
                $where['source_type'] = $sourceType === ''
                    ? ['not in', $redSourceTypes]
                    : (in_array($sourceType, $redSourceTypes) ? '__NO_MATCH__' : $sourceType);
            }
        }

        return $where;
    }

    protected function voucherIdsByDetailFilters($periodRange, $data)
    {
        $sets = [];

        $debitIds = $this->voucherIdsByAmount($periodRange, 'debit_amount', $data['debit_min'] ?? '', $data['debit_max'] ?? '');
        if (is_array($debitIds)) {
            $sets[] = $debitIds;
        }
        $creditIds = $this->voucherIdsByAmount($periodRange, 'credit_amount', $data['credit_min'] ?? '', $data['credit_max'] ?? '');
        if (is_array($creditIds)) {
            $sets[] = $creditIds;
        }
        $caseIds = $this->voucherIdsByAux($periodRange, 'case_no', $data['case_no'] ?? '');
        if (is_array($caseIds)) {
            $sets[] = $caseIds;
        }
        $receiptIds = $this->voucherIdsByAux($periodRange, 'receipt_no', $data['receipt_no'] ?? '');
        if (is_array($receiptIds)) {
            $sets[] = $receiptIds;
        }

        if (empty($sets)) {
            return null;
        }

        $result = array_shift($sets);
        foreach ($sets as $set) {
            $result = array_values(array_intersect($result, $set));
        }
        return array_values(array_unique($result));
    }

    protected function voucherIdsByAmount($periodRange, $field, $min, $max)
    {
        if ($min === '' && $max === '') {
            return null;
        }
        $table = self::TABLE_DETAIL;
        $query = $this->getdb($table)->where([
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $periodRange['year'],
            'del_flag' => 0,
        ]);
        $query->where('period', '>=', $periodRange['start']);
        $query->where('period', '<=', $periodRange['end']);
        if ($min !== '') {
            $query->where($field, '>=', $this->centsToDecimal($this->decimalToCents($min)));
        }
        if ($max !== '') {
            $query->where($field, '<=', $this->centsToDecimal($this->decimalToCents($max)));
        }
        return array_values($query->group('voucher_id')->column('voucher_id'));
    }

    protected function voucherIdsByAux($periodRange, $auxTypeCode, $keyword)
    {
        $keyword = trim((string)$keyword);
        if ($keyword === '') {
            return null;
        }
        $table = self::TABLE_AUX;
        $query = $this->getdb($table)->where([
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $periodRange['year'],
            'aux_type_code' => $auxTypeCode,
            'del_flag' => 0,
        ]);
        $query->where('period', '>=', $periodRange['start']);
        $query->where('period', '<=', $periodRange['end']);
        $query->where('aux_value|aux_label', 'like', '%' . $keyword . '%');
        return array_values($query->group('voucher_id')->column('voucher_id'));
    }

    public function getInfo($data = [])
    {
        $auth = $this->requirePermission('voucher:view');
        if ($auth) {
            return $auth;
        }
        $period = $data['period'] ?? '';
        $voucherId = $data['voucher_id'] ?? '';
        if ($period === '' || $voucherId === '') {
            return $this->error('会计期间和凭证ID不能为空');
        }
        $voucher = $this->loadVoucher($period, $voucherId);
        if (!$voucher) {
            return $this->error('凭证不存在');
        }
        if ($this->currentViewScope() === 'SELF' && $voucher['prepared_by'] !== $this->userid) {
            return $this->error('无权限查看该凭证');
        }
        $voucherRows = [$voucher];
        $this->fillUserDisplayNames($voucherRows);
        $voucher = $voucherRows[0];
        $detailTable = self::TABLE_DETAIL;
        $auxTable = self::TABLE_AUX;
        $details = $this->getdb($detailTable)->where([
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $this->fiscalYear($period),
            'period' => $period,
            'voucher_id' => $voucherId,
            'del_flag' => 0,
        ])->order('line_no asc')->select();
        foreach ($details as &$detail) {
            $detail['aux_values'] = $this->getdb($auxTable)->where([
                'account_set_id' => $this->accountSetId,
                'fiscal_year' => $this->fiscalYear($period),
                'period' => $period,
                'detail_id' => $detail['detail_id'],
                'del_flag' => 0,
            ])->order('aux_type_code asc')->select();
            foreach ($detail['aux_values'] as $aux) {
                $code = $aux['aux_type_code'];
                $detail[$code] = $aux['aux_value'];
                $detail[$code . '_label'] = $aux['aux_label'];
            }
        }
        $voucher['details'] = $details;
        return $this->ok($voucher);
    }

    protected function fillUserDisplayNames(&$rows)
    {
        $userIds = [];
        foreach ($rows as $row) {
            foreach (['prepared_by', 'audit_by', 'posted_by'] as $field) {
                if (!empty($row[$field])) {
                    $userIds[] = $row[$field];
                }
            }
        }
        $userIds = array_values(array_unique($userIds));
        if (empty($userIds)) {
            return;
        }
        $users = $this->getdb('sys_user')
            ->where('user_id', 'in', $userIds)
            ->field('user_id,username,real_name')
            ->select();
        $nameMap = [];
        foreach ($users as $user) {
            $nameMap[$user['user_id']] = $user['real_name'] ?: $user['username'];
        }
        foreach ($rows as &$row) {
            $row['prepared_by_name'] = !empty($row['prepared_by']) && isset($nameMap[$row['prepared_by']]) ? $nameMap[$row['prepared_by']] : ($row['prepared_by'] ?? '');
            $row['audit_by_name'] = !empty($row['audit_by']) && isset($nameMap[$row['audit_by']]) ? $nameMap[$row['audit_by']] : ($row['audit_by'] ?? '');
            $row['posted_by_name'] = !empty($row['posted_by']) && isset($nameMap[$row['posted_by']]) ? $nameMap[$row['posted_by']] : ($row['posted_by'] ?? '');
        }
    }

    public function saveVoucher($data, $status)
    {
        if ($status === 'POSTED') {
            return $this->error('凭证记账请通过记账功能执行');
        }
        $voucherIdForPermission = $data['voucher_id'] ?? '';
        $auth = $this->requirePermission(empty($voucherIdForPermission) ? 'voucher:add' : 'voucher:edit');
        if ($auth) {
            return $auth;
        }
        $period = $data['period'] ?? '';
        $voucherDate = $data['voucher_date'] ?? date('Y-m-d');
        $details = $data['details'] ?? [];
        if ($period === '') {
            return $this->error('会计期间不能为空');
        }
        $writableCheck = $this->ensurePeriodWritable($period, '会计期间已结账，不允许修改凭证');
        if ($writableCheck) {
            return $writableCheck;
        }
        $dateCheck = $this->checkVoucherDate($period, $voucherDate);
        if ($dateCheck['code'] !== self::CODE_SUCCESS) {
            return $dateCheck;
        }
        if (!is_array($details) || count($details) < 2) {
            return $this->error('凭证明细至少两行');
        }
        $balanceCheck = $this->checkBalance($details);
        if ($balanceCheck['code'] !== self::CODE_SUCCESS) {
            return $balanceCheck;
        }
        $amountTotals = $this->sumVoucherAmounts($details);
        $voucherSummary = $this->normalizeVoucherSummary($data);

        $fiscalYear = $this->fiscalYear($period);
        $voucherTable = self::TABLE;
        $detailTable = self::TABLE_DETAIL;
        $auxTable = self::TABLE_AUX;
        $voucherId = $data['voucher_id'] ?? '';
        $isNew = empty($voucherId);

        Db::startTrans();
        try {
            $before = null;
            if ($isNew) {
                $voucherId = uuid();
                $customVoucherNo = isset($data['voucher_no']) ? (int)$data['voucher_no'] : null;
                if ($customVoucherNo !== null) {
                    $existing = $this->getdb($voucherTable)->where([
                        'account_set_id' => $this->accountSetId,
                        'fiscal_year' => $fiscalYear,
                        'period' => $period,
                        'voucher_no' => $customVoucherNo,
                        'del_flag' => 0,
                    ])->find();
                    if ($existing) {
                        Db::rollback();
                        return $this->error('凭证号 ' . $customVoucherNo . ' 已存在');
                    }
                    $voucherNo = $customVoucherNo;
                } else {
                    $voucherNo = $this->nextVoucherNo($period);
                }
                $voucher = [
                    'voucher_id' => $voucherId,
                    'account_set_id' => $this->accountSetId,
                    'fiscal_year' => $fiscalYear,
                    'period' => $period,
                    'voucher_date' => $voucherDate,
                    'voucher_word' => $data['voucher_word'] ?? '记',
                    'voucher_no' => $voucherNo,
                    'summary' => $voucherSummary,
                    'debit_amount' => $amountTotals['debit_amount'],
                    'credit_amount' => $amountTotals['credit_amount'],
                    'attachment_count' => $data['attachment_count'] ?? 0,
                    'status' => $status,
                    'source_type' => $data['source_type'] ?? 'MANUAL',
                    'printed_flag' => '0',
                    'prepared_by' => $data['prepared_by'] ?? $this->userid,
                    'prepared_time' => $this->now(),
                    'audit_by' => null,
                    'audit_time' => null,
                    'posted_by' => null,
                    'posted_time' => null,
                    'void_flag' => '0',
                    'remark' => $data['remark'] ?? '',
                ];
                if ($status === 'AUDITED') {
                    $voucher['audit_by'] = $data['audit_by'] ?? $this->userid;
                    $voucher['audit_time'] = $this->now();
                }
                $this->fillCreate($voucher);
                $this->getdb($voucherTable)->insert($voucher);
            } else {
                $where = $this->voucherWhere($voucherId, $period);
                $before = $this->getdb($voucherTable)->where($where)->find();
                if (!$before) {
                    Db::rollback();
                    return $this->error('凭证不存在');
                }
                if (in_array($before['status'], ['AUDITED', 'POSTED', 'PRINTED', 'VOIDED'])) {
                    Db::rollback();
                    return $this->error('已审核、已记账、已打印或已作废凭证不允许修改');
                }
                $voucher = [
                    'voucher_date' => $voucherDate,
                    'voucher_word' => $data['voucher_word'] ?? ($before['voucher_word'] ?? '记'),
                    'summary' => $voucherSummary,
                    'debit_amount' => $amountTotals['debit_amount'],
                    'credit_amount' => $amountTotals['credit_amount'],
                    'attachment_count' => $data['attachment_count'] ?? 0,
                    'status' => $status,
                    'source_type' => $data['source_type'] ?? $before['source_type'],
                    'remark' => $data['remark'] ?? '',
                ];
                $this->fillUpdate($voucher);
                $this->getdb($voucherTable)->where($where)->update($voucher);
                $this->getdb($auxTable)->where([
                    'account_set_id' => $this->accountSetId,
                    'fiscal_year' => $fiscalYear,
                    'period' => $period,
                    'voucher_id' => $voucherId,
                ])->delete();
                $this->getdb($detailTable)->where([
                    'account_set_id' => $this->accountSetId,
                    'fiscal_year' => $fiscalYear,
                    'period' => $period,
                    'voucher_id' => $voucherId,
                ])->delete();
            }

            $lineNo = 1;
            foreach ($details as $line) {
                $lineCheck = $this->checkLine($line);
                if ($lineCheck['code'] !== self::CODE_SUCCESS) {
                    Db::rollback();
                    return $lineCheck;
                }
                $configs = $this->subjectAuxConfigs($line['subject_code']);
                $auxValues = $line['aux_values'] ?? [];
                if (($data['source_type'] ?? '') === 'IMPORT') {
                    $this->ensureImportAuxArchives($auxValues);
                }
                $auxCheck = $this->checkRequiredAux($line['subject_code'], $configs, $auxValues);
                if ($auxCheck['code'] !== self::CODE_SUCCESS) {
                    Db::rollback();
                    return $auxCheck;
                }

                $detailId = uuid();
                $detail = [
                    'detail_id' => $detailId,
                    'account_set_id' => $this->accountSetId,
                    'fiscal_year' => $fiscalYear,
                    'period' => $period,
                    'voucher_id' => $voucherId,
                    'line_no' => $lineNo++,
                    'subject_code' => $line['subject_code'],
                    'summary' => $line['summary'] ?? '',
                    'debit_amount' => $this->centsToDecimal($this->decimalToCents($line['debit_amount'] ?? '0')),
                    'credit_amount' => $this->centsToDecimal($this->decimalToCents($line['credit_amount'] ?? '0')),
                    'verification_status' => $this->needVerification($configs) ? 'UNVERIFIED' : 'NOT_REQUIRED',
                    'aux_desc' => $this->buildAuxDesc($auxValues),
                    'remark' => $line['remark'] ?? '',
                ];
                $this->fillCreate($detail);
                $this->getdb($detailTable)->insert($detail);

                foreach ($auxValues as $aux) {
                    if (empty($aux['aux_type_code']) || !isset($aux['aux_value']) || $aux['aux_value'] === '') {
                        continue;
                    }
                    $row = [
                        'id' => uuid(),
                        'account_set_id' => $this->accountSetId,
                        'fiscal_year' => $fiscalYear,
                        'period' => $period,
                        'voucher_id' => $voucherId,
                        'detail_id' => $detailId,
                        'aux_type_code' => $aux['aux_type_code'],
                        'aux_value' => $aux['aux_value'],
                        'aux_label' => $aux['aux_label'] ?? $aux['aux_value'],
                        'remark' => '',
                    ];
                    $this->fillCreate($row);
                    $this->getdb($auxTable)->insert($row);
                }
            }

            $this->logAudit('VOUCHER', $voucherId, $isNew ? 'CREATE' : 'UPDATE', $before, $data);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('凭证保存失败：' . $e->getMessage());
        }

        return $this->getInfo(['period' => $period, 'voucher_id' => $voucherId]);
    }

    public function postVoucher($data)
    {
        $voucherId = $data['voucher_id'] ?? '';
        if ($voucherId === '') {
            return $this->error('凭证ID不能为空');
        }
        return $this->postVoucherIds($data['period'] ?? '', [$voucherId]);
    }

    public function batchPostVouchers($data)
    {
        $voucherIds = $data['voucher_ids'] ?? [];
        if (!is_array($voucherIds)) {
            return $this->error('待记账凭证列表格式不正确');
        }
        $voucherIds = array_values(array_filter(array_unique($voucherIds)));
        if (empty($voucherIds)) {
            return $this->error('请选择要记账的凭证');
        }
        return $this->postVoucherIds($data['period'] ?? '', $voucherIds);
    }

    protected function postVoucherIds($period, $voucherIds)
    {
        $auth = $this->requirePermission('voucher:post');
        if ($auth) {
            return $auth;
        }
        if ($period === '') {
            return $this->error('会计期间不能为空');
        }

        $periodStatus = $this->fiscalPeriodStatus($period);
        $beforeRows = [];
        foreach ($voucherIds as $voucherId) {
            $voucher = $this->loadVoucher($period, $voucherId);
            if (!$voucher) {
                return $this->error('凭证不存在或已删除：' . $voucherId);
            }
            $reason = $this->voucherPostBlockReason($voucher, $periodStatus);
            if ($reason !== '') {
                return $this->error(($voucher['voucher_word'] ?? '记') . '-' . $voucher['voucher_no'] . '：' . $reason);
            }
            $detailCheck = $this->voucherPostingDetailCheck($period, $voucherId);
            if ($detailCheck['code'] !== self::CODE_SUCCESS) {
                return $this->error(($voucher['voucher_word'] ?? '记') . '-' . $voucher['voucher_no'] . '：' . $detailCheck['message']);
            }
            $beforeRows[] = $voucher;
        }

        Db::startTrans();
        try {
            foreach ($beforeRows as $voucher) {
                $update = [
                    'status' => 'POSTED',
                    'posted_by' => $this->userid,
                    'posted_time' => $this->now(),
                ];
                $this->fillUpdate($update);
                $this->getdb(self::TABLE)->where($this->voucherWhere($voucher['voucher_id'], $period))->update($update);
                $this->logAudit('VOUCHER', $voucher['voucher_id'], 'POST', $voucher, $update);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('凭证记账失败：' . $e->getMessage());
        }

        return $this->ok(['posted_count' => count($beforeRows)], '记账成功', count($beforeRows));
    }

    public function unpostVoucher($data)
    {
        $voucherId = $data['voucher_id'] ?? '';
        if ($voucherId === '') {
            return $this->error('凭证ID不能为空');
        }
        return $this->unpostVoucherIds($data['period'] ?? '', [$voucherId]);
    }

    public function batchUnpostVouchers($data)
    {
        $voucherIds = $data['voucher_ids'] ?? [];
        if (!is_array($voucherIds)) {
            return $this->error('待反记账凭证列表格式不正确');
        }
        $voucherIds = array_values(array_filter(array_unique($voucherIds)));
        if (empty($voucherIds)) {
            return $this->error('请选择要反记账的凭证');
        }
        return $this->unpostVoucherIds($data['period'] ?? '', $voucherIds);
    }

    protected function unpostVoucherIds($period, $voucherIds)
    {
        $auth = $this->requirePermission('voucher:unpost');
        if ($auth) {
            return $auth;
        }
        if ($period === '') {
            return $this->error('会计期间不能为空');
        }

        $periodStatus = $this->fiscalPeriodStatus($period);
        $beforeRows = [];
        foreach ($voucherIds as $voucherId) {
            $voucher = $this->loadVoucher($period, $voucherId);
            if (!$voucher) {
                return $this->error('凭证不存在或已删除：' . $voucherId);
            }
            $reason = $this->voucherUnpostBlockReason($voucher, $periodStatus);
            if ($reason !== '') {
                return $this->error(($voucher['voucher_word'] ?? '记') . '-' . $voucher['voucher_no'] . '：' . $reason);
            }
            $beforeRows[] = $voucher;
        }

        Db::startTrans();
        try {
            foreach ($beforeRows as $voucher) {
                $update = [
                    'status' => 'AUDITED',
                    'posted_by' => null,
                    'posted_time' => null,
                ];
                $this->fillUpdate($update);
                $this->getdb(self::TABLE)->where($this->voucherWhere($voucher['voucher_id'], $period))->update($update);
                $this->logAudit('VOUCHER', $voucher['voucher_id'], 'UNPOST', $voucher, $update);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('凭证反记账失败：' . $e->getMessage());
        }

        return $this->ok(['unposted_count' => count($beforeRows)], '反记账成功', count($beforeRows));
    }

    public function changeStatus($data, $expected, $target, $operation)
    {
        $permissionCode = $operation === 'AUDIT' ? 'voucher:audit' : 'voucher:unaudit';
        $auth = $this->requirePermission($permissionCode);
        if ($auth) {
            return $auth;
        }
        $period = $data['period'] ?? '';
        $voucherId = $data['voucher_id'] ?? '';
        if ($period === '' || $voucherId === '') {
            return $this->error('会计期间和凭证ID不能为空');
        }
        $writableCheck = $this->ensurePeriodWritable($period, '会计期间已结账，不允许审核或反审核');
        if ($writableCheck) {
            return $writableCheck;
        }
        $table = self::TABLE;
        $where = $this->voucherWhere($voucherId, $period);
        $before = $this->getdb($table)->where($where)->find();
        if (!$before) {
            return $this->error('凭证不存在');
        }
        if ($this->workflowStatus($before) !== $expected) {
            return $this->error('凭证状态不允许执行当前操作');
        }
        if ($operation === 'AUDIT' && $before['prepared_by'] === $this->userid) {
            return $this->error('制单人不能审核自己制单的凭证');
        }
        $update = ['status' => $target];
        if ($target === 'AUDITED') {
            $update['audit_by'] = $this->userid;
            $update['audit_time'] = $this->now();
        } else {
            $update['audit_by'] = null;
            $update['audit_time'] = null;
        }
        $this->fillUpdate($update);
        $this->getdb($table)->where($where)->update($update);
        $this->logAudit('VOUCHER', $voucherId, $operation, $before, $update);
        return $this->ok($voucherId, '操作成功');
    }

    public function voidVoucher($data)
    {
        $auth = $this->requirePermission('voucher:delete');
        if ($auth) {
            return $auth;
        }
        $period = $data['period'] ?? '';
        $voucherId = $data['voucher_id'] ?? '';
        if ($period === '' || $voucherId === '') {
            return $this->error('会计期间和凭证ID不能为空');
        }
        $writableCheck = $this->ensurePeriodWritable($period, '会计期间已结账，不允许作废凭证');
        if ($writableCheck) {
            return $writableCheck;
        }
        $table = self::TABLE;
        $where = $this->voucherWhere($voucherId, $period);
        $before = $this->getdb($table)->where($where)->find();
        if (!$before) {
            return $this->error('凭证不存在');
        }
        if ($before['printed_flag'] === '1') {
            return $this->error('已打印凭证不允许作废');
        }
        if ($before['status'] === 'POSTED') {
            return $this->error('已记账凭证不允许直接作废，请先反记账');
        }
        $update = ['status' => 'VOIDED'];
        $this->fillUpdate($update);
        $this->getdb($table)->where($where)->update($update);
        $this->logAudit('VOUCHER', $voucherId, 'VOID', $before, $update);
        return $this->ok($voucherId, '操作成功');
    }

    public function deleteVoucher($data)
    {
        $voucherId = $data['voucher_id'] ?? '';
        if ($voucherId === '') {
            return $this->error('凭证ID不能为空');
        }
        return $this->deleteVoucherIds($data['period'] ?? '', [$voucherId]);
    }

    public function batchDeleteVouchers($data)
    {
        $voucherIds = $data['voucher_ids'] ?? [];
        if (!is_array($voucherIds)) {
            return $this->error('待删除凭证列表格式不正确');
        }
        $voucherIds = array_values(array_filter(array_unique($voucherIds)));
        if (empty($voucherIds)) {
            return $this->error('请选择要删除的凭证');
        }
        return $this->deleteVoucherIds($data['period'] ?? '', $voucherIds);
    }

    protected function deleteVoucherIds($period, $voucherIds)
    {
        $auth = $this->requirePermission('voucher:delete');
        if ($auth) {
            return $auth;
        }
        if ($period === '') {
            return $this->error('会计期间不能为空');
        }

        $voucherTable = self::TABLE;
        $detailTable = self::TABLE_DETAIL;
        $auxTable = self::TABLE_AUX;
        $periodStatus = $this->fiscalPeriodStatus($period);

        $beforeRows = [];
        foreach ($voucherIds as $voucherId) {
            $voucher = $this->getdb($voucherTable)->where($this->voucherWhere($voucherId, $period))->find();
            if (!$voucher) {
                return $this->error('凭证不存在或已删除：' . $voucherId);
            }
            if ($this->currentViewScope() === 'SELF' && $voucher['prepared_by'] !== $this->userid) {
                return $this->error('无权限删除非本人制单凭证：' . ($voucher['voucher_word'] ?? '记') . '-' . $voucher['voucher_no']);
            }
            $reason = $this->voucherDeleteBlockReason($voucher, $periodStatus);
            if ($reason !== '') {
                return $this->error(($voucher['voucher_word'] ?? '记') . '-' . $voucher['voucher_no'] . '：' . $reason);
            }
            $beforeRows[] = $voucher;
        }

        Db::startTrans();
        try {
            foreach ($beforeRows as $voucher) {
                $voucherId = $voucher['voucher_id'];
                $update = ['del_flag' => 1];
                $this->fillUpdate($update);
                $this->getdb($voucherTable)->where($this->voucherWhere($voucherId, $period))->update($update);
                $this->getdb($detailTable)->where([
                    'account_set_id' => $this->accountSetId,
                    'fiscal_year' => $this->fiscalYear($period),
                    'period' => $period,
                    'voucher_id' => $voucherId,
                    'del_flag' => 0,
                ])->update($update);
                $this->getdb($auxTable)->where([
                    'account_set_id' => $this->accountSetId,
                    'fiscal_year' => $this->fiscalYear($period),
                    'period' => $period,
                    'voucher_id' => $voucherId,
                    'del_flag' => 0,
                ])->update($update);
                $this->logAudit('VOUCHER', $voucherId, 'DELETE', $voucher, $update);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('凭证删除失败：' . $e->getMessage());
        }

        return $this->ok(['deleted_count' => count($beforeRows)], '删除成功', count($beforeRows));
    }

    protected function fiscalPeriodStatus($period)
    {
        $row = $this->getdb('fin_fiscal_period')->where([
            'account_set_id' => $this->accountSetId,
            'period' => $period,
            'del_flag' => 0,
        ])->find();
        return $row['status'] ?? '';
    }

    protected function ensurePeriodWritable($period, $message)
    {
        $status = $this->fiscalPeriodStatus($period);
        if ($status !== '' && $status !== 'OPEN') {
            return $this->error($message);
        }
        return null;
    }

    protected function voucherDeleteBlockReason($voucher, $periodStatus)
    {
        if ($periodStatus !== 'OPEN') {
            return '会计期间已结账，不允许删除凭证';
        }
        if (($voucher['source_type'] ?? '') === 'AUTO_CARRY') {
            return '期末自动结转凭证不允许删除';
        }
        if (!in_array($voucher['status'] ?? '', ['DRAFT', 'SUBMITTED'])) {
            return '只能删除未审核凭证，已审核凭证需取消审核后再删除';
        }
        return '';
    }

    protected function voucherPostBlockReason($voucher, $periodStatus)
    {
        if ($periodStatus !== 'OPEN') {
            return '会计期间已结账，不允许记账';
        }
        if (($voucher['void_flag'] ?? '0') === '1' || ($voucher['status'] ?? '') === 'VOIDED') {
            return '已作废凭证不允许记账';
        }
        if ($this->workflowStatus($voucher) !== 'AUDITED') {
            return '只有已审核凭证允许记账';
        }
        return '';
    }

    protected function voucherUnpostBlockReason($voucher, $periodStatus)
    {
        if ($periodStatus !== 'OPEN') {
            return '会计期间已结账，不允许反记账';
        }
        if ($this->workflowStatus($voucher) !== 'POSTED') {
            return '只有已记账凭证允许反记账';
        }
        return '';
    }

    protected function voucherPostingDetailCheck($period, $voucherId)
    {
        $details = $this->getdb(self::TABLE_DETAIL)->where([
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $this->fiscalYear($period),
            'period' => $period,
            'voucher_id' => $voucherId,
            'del_flag' => 0,
        ])->field('debit_amount,credit_amount')->select();
        if (!is_array($details) || count($details) < 2) {
            return $this->error('凭证明细至少两行');
        }
        return $this->checkBalance($details);
    }

    public function printMark($data)
    {
        $auth = $this->requirePermission('voucher:print');
        if ($auth) {
            return $auth;
        }
        $period = $data['period'] ?? '';
        $voucherId = $data['voucher_id'] ?? '';
        if ($period === '' || $voucherId === '') {
            return $this->error('会计期间和凭证ID不能为空');
        }
        $table = self::TABLE;
        $where = $this->voucherWhere($voucherId, $period);
        $before = $this->getdb($table)->where($where)->find();
        if (!$before) {
            return $this->error('凭证不存在');
        }
        $update = ['printed_flag' => '1'];
        $this->fillUpdate($update);
        $this->getdb($table)->where($where)->update($update);
        $this->logAudit('VOUCHER', $voucherId, 'PRINT', $before, $update);
        return $this->ok($voucherId, '操作成功');
    }

    public function printData($data = [])
    {
        $auth = $this->requirePermission('voucher:print');
        if ($auth) {
            return $auth;
        }

        $items = $data['vouchers'] ?? [];
        if (!is_array($items) || empty($items)) {
            $period = $data['period'] ?? '';
            $voucherIds = $data['voucher_ids'] ?? [];
            if ($period !== '' && is_array($voucherIds)) {
                foreach ($voucherIds as $voucherId) {
                    $items[] = ['period' => $period, 'voucher_id' => $voucherId];
                }
            }
        }
        if (empty($items)) {
            return $this->error('请选择要打印的凭证');
        }

        $vouchers = $this->loadVouchersForPrint($items);
        if (empty($vouchers)) {
            return $this->error('所选凭证不存在');
        }
        $this->fillVoucherPrintDetails($vouchers);
        $accountSet = $this->printAccountSetConfig();
        $this->markVouchersPrinted($vouchers);

        return $this->ok([
            'account_set' => $accountSet,
            'paper_size' => $accountSet['paper_size'],
            'voucher_print_line_count' => (int)$accountSet['voucher_print_line_count'],
            'vouchers' => $vouchers,
        ], 'OK', count($vouchers));
    }

    public function printPdf($data = [])
    {
        $auth = $this->requirePermission('voucher:print');
        if ($auth) {
            return $auth;
        }

        $items = $this->resolvePrintVoucherItems($data);
        if (empty($items)) {
            return $this->error('请选择要打印的凭证');
        }

        $vouchers = $this->loadVouchersForPrint($items);
        if (empty($vouchers)) {
            return $this->error('所选凭证不存在');
        }
        $this->fillVoucherPrintDetails($vouchers);
        $accountSet = $this->printAccountSetConfig();
        $lineCount = max(1, (int)$accountSet['voucher_print_line_count']);
        $pages = $this->splitVoucherPrintPages($vouchers, $lineCount);
        if (empty($pages)) {
            return $this->error('没有可打印的凭证内容');
        }

        $vendorDir = __DIR__ . '/../../../vendor';
        if (!is_dir($vendorDir . '/mpdf/mpdf')) {
            return $this->error('PDF组件未安装，请先在backend目录安装mpdf/mpdf');
        }
        $this->registerMpdfAutoloader($vendorDir);

        $tempDir = __DIR__ . '/../../../runtime/mpdf';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        $format = strtoupper((string)$accountSet['paper_size']) === 'A4' ? 'A4-L' : 'A5-L';

        try {
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'zh-CN+aCJK',
                'format' => $format,
                'tempDir' => $tempDir,
                'margin_left' => 22,
                'margin_right' => 16,
                'margin_top' => 7,
                'margin_bottom' => 12,
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
                'useAdobeCJK' => true,
            ]);
            $mpdf->SetTitle('记账凭证');
            $mpdf->SetDisplayMode('fullpage');
            $mpdf->WriteHTML($this->voucherPrintPdfStyles());
            foreach ($pages as $index => $page) {
                if ($index > 0) {
                    $mpdf->AddPage();
                }
                $mpdf->WriteHTML($this->buildVoucherPrintPdfHtml($page, $accountSet, $lineCount));
            }
            $pdf = $mpdf->Output('', 'S');
        } catch (\Exception $e) {
            return $this->error('生成凭证PDF失败：' . $e->getMessage());
        }

        $this->markVouchersPrinted($vouchers);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="voucher-print.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    protected function loadVouchersForPrint($items)
    {
        $vouchers = [];
        $seen = [];
        foreach ($items as $item) {
            $period = trim((string)($item['period'] ?? ''));
            $voucherId = trim((string)($item['voucher_id'] ?? ($item['voucherId'] ?? '')));
            if ($period === '' || $voucherId === '') {
                continue;
            }
            $key = $period . ':' . $voucherId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $voucher = $this->loadVoucher($period, $voucherId);
            if (!$voucher) {
                continue;
            }
            if ($this->currentViewScope() === 'SELF' && $voucher['prepared_by'] !== $this->userid) {
                continue;
            }
            $vouchers[] = $voucher;
        }
        usort($vouchers, function ($a, $b) {
            $dateCompare = strcmp((string)$a['voucher_date'], (string)$b['voucher_date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return (int)$a['voucher_no'] <=> (int)$b['voucher_no'];
        });
        $this->fillUserDisplayNames($vouchers);
        return $vouchers;
    }

    protected function resolvePrintVoucherItems($data)
    {
        $items = $data['vouchers'] ?? [];
        if (!is_array($items) || empty($items)) {
            $items = [];
            $period = $data['period'] ?? '';
            $voucherIds = $data['voucher_ids'] ?? [];
            if ($period !== '' && is_array($voucherIds)) {
                foreach ($voucherIds as $voucherId) {
                    $items[] = ['period' => $period, 'voucher_id' => $voucherId];
                }
            }
        }
        return is_array($items) ? $items : [];
    }

    protected function splitVoucherPrintPages($vouchers, $lineCount)
    {
        $pages = [];
        foreach ($vouchers as $voucher) {
            $details = $voucher['details'] ?? [];
            if (empty($details)) {
                $details = [[]];
            }
            $chunks = array_chunk($details, max(1, (int)$lineCount));
            $pageCount = max(1, count($chunks));
            foreach ($chunks as $index => $chunk) {
                $pages[] = [
                    'voucher' => $voucher,
                    'details' => $chunk,
                    'page_no' => $index + 1,
                    'page_count' => $pageCount,
                ];
            }
        }
        return $pages;
    }

    protected function voucherPrintPdfStyles()
    {
        return '<style>
body { font-family: gb, sun-exta, "SimSun", serif; color: #111; font-size: 10pt; }
.voucher-page { width: 100%; }
h1 { text-align: center; margin: 0 0 8px; font-size: 18pt; font-weight: bold; }
.meta { width: 100%; margin-bottom: 8px; font-size: 9pt; }
.meta td { border: 0; padding: 0; }
.meta-center { text-align: center; }
.meta-right { text-align: right; }
.voucher-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.voucher-table th, .voucher-table td { border: 1px solid #111; padding: 4px 5px; vertical-align: top; overflow-wrap: break-word; }
.voucher-table th { text-align: center; font-weight: bold; background: #f2f2f2; }
.summary { width: 48%; }
.subject { width: 22%; }
.amount { width: 15%; text-align: right; font-family: dejavusansmono, gb, sun-exta, monospace; }
.total-row td { font-weight: bold; background: #f7f7f7; }
.footer { width: 100%; margin-top: 10px; font-size: 9pt; }
.footer td { border: 0; padding: 0; }
</style>';
    }

    protected function voucherPrintTableHeightMm($accountSet)
    {
        $paperSize = strtoupper((string)($accountSet['paper_size'] ?? 'A5'));
        return $paperSize === 'A4' ? 150 : 104;
    }

    protected function buildVoucherPrintPdfHtml($page, $accountSet, $lineCount)
    {
        $escape = function ($value) {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        };
        $money = function ($value) {
            $cents = $this->decimalToCents($value);
            return $cents === 0 ? '' : number_format($cents / 100, 2, '.', ',');
        };

        $voucher = $page['voucher'];
        $rows = $page['details'];
        $debitCents = 0;
        $creditCents = 0;
        foreach ($rows as $detail) {
            $debitCents += $this->decimalToCents($detail['debit_amount'] ?? 0);
            $creditCents += $this->decimalToCents($detail['credit_amount'] ?? 0);
        }
        while (count($rows) < $lineCount) {
            $rows[] = [];
        }

        $tableHeightMm = $this->voucherPrintTableHeightMm($accountSet);
        $headerHeightMm = 8;
        $totalRowHeightMm = 8;
        $detailRowHeightMm = round(max(4, ($tableHeightMm - $headerHeightMm - $totalRowHeightMm) / max(1, (int)$lineCount)), 2);
        $detailCellStyle = ' style="height: ' . $detailRowHeightMm . 'mm"';
        $totalCellStyle = ' style="height: ' . $totalRowHeightMm . 'mm"';

        $body = '';
        foreach ($rows as $detail) {
            $subjectText = trim((string)($detail['subject_code'] ?? '') . ' ' . (string)($detail['subject_name'] ?? ''));
            if (!empty($detail['aux_desc'])) {
                $subjectText .= ' ' . $this->formatVoucherPrintAuxDesc($detail['aux_desc']);
            }
            $body .= '<tr class="detail-row">';
            $body .= '<td class="summary"' . $detailCellStyle . '>' . $escape($detail['summary'] ?? '') . '</td>';
            $body .= '<td class="subject"' . $detailCellStyle . '>' . $escape(trim($subjectText)) . '</td>';
            $body .= '<td class="amount"' . $detailCellStyle . '>' . $escape($money($detail['debit_amount'] ?? 0)) . '</td>';
            $body .= '<td class="amount"' . $detailCellStyle . '>' . $escape($money($detail['credit_amount'] ?? 0)) . '</td>';
            $body .= '</tr>';
        }

        $voucherNo = ($voucher['voucher_word'] ?? '记') . '-' . ($voucher['voucher_no'] ?? '');
        if (($page['page_count'] ?? 1) > 1) {
            $voucherNo .= '(' . $page['page_no'] . '/' . $page['page_count'] . ')';
        }

        return '<div class="voucher-page">
  <h1>记账凭证</h1>
  <table class="meta" border="0" cellpadding="0" cellspacing="0">
    <tr>
      <td>账套：' . $escape($accountSet['set_name'] ?? '') . '</td>
      <td class="meta-center">日期：' . $escape($voucher['voucher_date'] ?? '') . '</td>
      <td class="meta-right">凭证号：' . $escape($voucherNo) . '</td>
    </tr>
    <tr>
      <td>附单据：' . $escape($voucher['attachment_count'] ?? 0) . '</td>
      <td class="meta-center"></td>
      <td class="meta-right"></td>
    </tr>
  </table>
  <table class="voucher-table" border="1" cellpadding="4" cellspacing="0" style="height: ' . $tableHeightMm . 'mm; table-layout: fixed;">
    <thead>
      <tr>
        <th class="summary" style="height: ' . $headerHeightMm . 'mm">摘要</th>
        <th class="subject" style="height: ' . $headerHeightMm . 'mm">科目</th>
        <th class="amount" style="height: ' . $headerHeightMm . 'mm">借方金额</th>
        <th class="amount" style="height: ' . $headerHeightMm . 'mm">贷方金额</th>
      </tr>
    </thead>
    <tbody>' . $body . '
      <tr class="total-row">
        <td class="summary"' . $totalCellStyle . '>合计：</td>
        <td class="subject"' . $totalCellStyle . '></td>
        <td class="amount"' . $totalCellStyle . '>' . $escape(number_format($debitCents / 100, 2, '.', ',')) . '</td>
        <td class="amount"' . $totalCellStyle . '>' . $escape(number_format($creditCents / 100, 2, '.', ',')) . '</td>
      </tr>
    </tbody>
  </table>
  <table class="footer" border="0" cellpadding="0" cellspacing="0">
    <tr>
      <td>会计主管：' . $escape($accountSet['finance_manager'] ?? '') . '</td>
      <td>审核人：' . $escape($voucher['audit_by_name'] ?? ($voucher['posted_by_name'] ?? '')) . '</td>
      <td class="meta-right">制单人：' . $escape($voucher['prepared_by_name'] ?? '') . '</td>
    </tr>
  </table>
</div>';
    }

    protected function formatVoucherPrintAuxDesc($auxDesc)
    {
        $parts = preg_split('/[;；\/]/u', (string)$auxDesc);
        $labels = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $index = strpos($part, ':');
            $labels[] = trim($index === false ? $part : substr($part, $index + 1));
        }
        return implode('；', array_filter($labels, 'strlen'));
    }

    protected function registerMpdfAutoloader($vendorDir)
    {
        if (class_exists('\Mpdf\Mpdf', false)) {
            return;
        }
        if (!class_exists('\Composer\Autoload\ClassLoader', false)) {
            require_once $vendorDir . '/composer/ClassLoader.php';
        }
        $loader = new \Composer\Autoload\ClassLoader($vendorDir);
        $loader->addPsr4('Mpdf\\', $vendorDir . '/mpdf/mpdf/src');
        $loader->addPsr4('Mpdf\\PsrHttpMessageShim\\', $vendorDir . '/mpdf/psr-http-message-shim/src');
        $loader->addPsr4('Mpdf\\PsrLogAwareTrait\\', $vendorDir . '/mpdf/psr-log-aware-trait/src');
        $loader->addPsr4('setasign\\Fpdi\\', $vendorDir . '/setasign/fpdi/src');
        $loader->addPsr4('Psr\\Log\\', $vendorDir . '/psr/log/Psr/Log');
        $loader->addPsr4('Psr\\Http\\Message\\', $vendorDir . '/psr/http-message/src');
        $loader->addPsr4('DeepCopy\\', $vendorDir . '/myclabs/deep-copy/src/DeepCopy');
        $loader->register(true);
        require_once $vendorDir . '/myclabs/deep-copy/src/DeepCopy/deep_copy.php';
        require_once $vendorDir . '/mpdf/mpdf/src/functions.php';
    }

    protected function fillVoucherPrintDetails(&$vouchers)
    {
        $subjectCodes = [];
        foreach ($vouchers as &$voucher) {
            $period = $voucher['period'];
            $details = $this->getdb(self::TABLE_DETAIL)->where([
                'account_set_id' => $this->accountSetId,
                'fiscal_year' => $this->fiscalYear($period),
                'period' => $period,
                'voucher_id' => $voucher['voucher_id'],
                'del_flag' => 0,
            ])->order('line_no asc')->select();
            foreach ($details as $detail) {
                if (!empty($detail['subject_code'])) {
                    $subjectCodes[] = $detail['subject_code'];
                }
            }
            $voucher['details'] = $details;
        }
        unset($voucher);

        $subjectMap = [];
        $subjectCodes = array_values(array_unique($subjectCodes));
        if (!empty($subjectCodes)) {
            $subjects = $this->getdb('fin_subject')->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])->where('subject_code', 'in', $subjectCodes)->field('subject_code,subject_name')->select();
            foreach ($subjects as $subject) {
                $subjectMap[$subject['subject_code']] = $subject['subject_name'];
            }
        }

        foreach ($vouchers as &$voucher) {
            foreach ($voucher['details'] as &$detail) {
                $detail['subject_name'] = $subjectMap[$detail['subject_code']] ?? '';
            }
            unset($detail);
        }
    }

    protected function printAccountSetConfig()
    {
        $row = $this->getdb('fin_account_set')->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->field('account_set_id,set_code,set_name,paper_size,voucher_print_line_count,finance_manager')->find();
        if (!$row) {
            return [
                'account_set_id' => $this->accountSetId,
                'set_name' => '',
                'paper_size' => 'A5',
                'voucher_print_line_count' => 8,
                'finance_manager' => '',
            ];
        }
        $row['paper_size'] = in_array($row['paper_size'] ?? '', ['A4', 'A5'], true) ? $row['paper_size'] : 'A5';
        $row['voucher_print_line_count'] = max(1, (int)($row['voucher_print_line_count'] ?? 8));
        return $row;
    }

    protected function markVouchersPrinted($vouchers)
    {
        foreach ($vouchers as $voucher) {
            $before = $this->loadVoucher($voucher['period'], $voucher['voucher_id']);
            if (!$before) {
                continue;
            }
            $update = ['printed_flag' => '1'];
            $this->fillUpdate($update);
            $this->getdb(self::TABLE)->where($this->voucherWhere($voucher['voucher_id'], $voucher['period']))->update($update);
            $this->logAudit('VOUCHER', $voucher['voucher_id'], 'PRINT', $before, $update);
        }
    }

    protected function workflowStatus($voucher)
    {
        $status = $voucher['status'] ?? '';
        if ($status !== 'PRINTED') {
            return $status;
        }
        return !empty($voucher['audit_by']) || !empty($voucher['audit_time']) ? 'AUDITED' : 'SUBMITTED';
    }

    protected function loadVoucher($period, $voucherId)
    {
        return $this->getdb(self::TABLE)->where($this->voucherWhere($voucherId, $period))->find();
    }

    protected function voucherWhere($voucherId, $period)
    {
        return [
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $this->fiscalYear($period),
            'period' => $period,
            'voucher_id' => $voucherId,
            'del_flag' => 0,
        ];
    }

    protected function checkBalance($details)
    {
        $debit = 0;
        $credit = 0;
        foreach ($details as $line) {
            $debit += $this->decimalToCents($line['debit_amount'] ?? '0');
            $credit += $this->decimalToCents($line['credit_amount'] ?? '0');
        }
        if ($debit !== $credit) {
            return $this->error('借贷不平衡');
        }
        return $this->ok();
    }

    protected function sumVoucherAmounts($details)
    {
        $debit = 0;
        $credit = 0;
        foreach ($details as $line) {
            $debit += $this->decimalToCents($line['debit_amount'] ?? '0');
            $credit += $this->decimalToCents($line['credit_amount'] ?? '0');
        }
        return [
            'debit_amount' => $this->centsToDecimal($debit),
            'credit_amount' => $this->centsToDecimal($credit),
        ];
    }

    protected function checkLine($line)
    {
        if (empty($line['subject_code'])) {
            return $this->error('明细科目不能为空');
        }
        if (trim($line['summary'] ?? '') === '') {
            return $this->error('明细摘要不能为空');
        }
        $subject = $this->getdb('fin_subject')->where([
            'account_set_id' => $this->accountSetId,
            'subject_code' => $line['subject_code'],
            'del_flag' => 0,
            'status' => 1,
        ])->find();
        if (!$subject) {
            return $this->error('科目不存在或已禁用：' . $line['subject_code']);
        }
        if ((int)$subject['leaf_flag'] !== 1) {
            return $this->error('非末级科目不允许录入凭证：' . $line['subject_code']);
        }
        $debit = $this->decimalToCents($line['debit_amount'] ?? '0');
        $credit = $this->decimalToCents($line['credit_amount'] ?? '0');
        if ($debit > 0 && $credit > 0) {
            return $this->error('同一明细不能同时填写借方和贷方');
        }
        if ($debit === 0 && $credit === 0) {
            return $this->error('明细借贷金额不能同时为0');
        }
        return $this->ok();
    }

    protected function normalizeVoucherSummary($data)
    {
        $summary = trim((string)($data['summary'] ?? ''));
        if ($summary !== '') {
            return $summary;
        }
        foreach (($data['details'] ?? []) as $line) {
            $lineSummary = trim((string)($line['summary'] ?? ''));
            if ($lineSummary !== '') {
                return $lineSummary;
            }
        }
        return '';
    }

    protected function checkVoucherDate($period, $voucherDate)
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $voucherDate)) {
            return $this->error('凭证日期格式不正确');
        }
        if (substr($voucherDate, 0, 7) !== $period) {
            return $this->error('凭证日期必须在当前会计期间内');
        }
        return $this->ok();
    }

    public function peekVoucherNo($data = [])
    {
        $period = $data['period'] ?? input('param.period', '');
        if ($period === '') {
            return $this->error('会计期间不能为空');
        }
        $row = $this->getdb(self::TABLE_NO)->where([
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $this->fiscalYear($period),
            'period' => $period,
            'del_flag' => 0,
        ])->find();
        return $this->ok([
            'period' => $period,
            'voucher_no' => $row ? ((int)$row['current_no'] + 1) : 1,
        ]);
    }

    protected function subjectAuxConfigs($subjectCode)
    {
        $where = $this->accountWhere();
        $where['subject_code'] = $subjectCode;
        return $this->getdb(self::TABLE_CONFIG)->where($where)->select();
    }

    protected function checkRequiredAux($subjectCode, $configs, $auxValues)
    {
        $input = [];
        foreach ($auxValues as $aux) {
            if (!empty($aux['aux_type_code']) && isset($aux['aux_value']) && $aux['aux_value'] !== '') {
                $input[$aux['aux_type_code']] = true;
            }
        }
        foreach ($configs as $config) {
            if ((int)$config['required_flag'] === 1 && empty($input[$config['aux_type_code']])) {
                return $this->error('科目 ' . $subjectCode . ' 缺少必填辅助核算：' . $config['aux_type_code']);
            }
        }
        return $this->ok();
    }

    protected function ensureImportAuxArchives($auxValues)
    {
        foreach ($auxValues as $aux) {
            $typeCode = trim((string)($aux['aux_type_code'] ?? ''));
            if (!in_array($typeCode, ['case_no', 'receipt_no'], true)) {
                continue;
            }
            $archiveCode = trim((string)($aux['aux_value'] ?? ''));
            if ($archiveCode === '') {
                continue;
            }
            $this->ensureImportAuxType($typeCode);
            $exists = $this->getdb('fin_aux_archive')->where([
                'account_set_id' => $this->accountSetId,
                'aux_type_code' => $typeCode,
                'archive_code' => $archiveCode,
                'del_flag' => 0,
            ])->find();
            if ($exists) {
                continue;
            }
            $row = [
                'archive_id' => uuid(),
                'account_set_id' => $this->accountSetId,
                'aux_type_code' => $typeCode,
                'archive_code' => $archiveCode,
                'archive_name' => trim((string)($aux['aux_label'] ?? '')) ?: $archiveCode,
                'status' => 1,
                'remark' => '凭证导入自动建档',
            ];
            $this->fillCreate($row);
            $this->getdb('fin_aux_archive')->insert($row);
        }
    }

    protected function ensureImportAuxType($typeCode)
    {
        $exists = $this->getdb('fin_aux_type')->where([
            'account_set_id' => $this->accountSetId,
            'aux_type_code' => $typeCode,
            'del_flag' => 0,
        ])->find();
        if ($exists) {
            return;
        }
        $row = [
            'aux_type_id' => uuid(),
            'account_set_id' => $this->accountSetId,
            'aux_type_code' => $typeCode,
            'aux_type_name' => $typeCode === 'case_no' ? '案号' : '收据号',
            'value_source' => 'MANUAL',
            'required_flag' => $typeCode === 'case_no' ? 1 : 0,
            'status' => 1,
            'remark' => '凭证导入自动建档',
        ];
        $this->fillCreate($row);
        $this->getdb('fin_aux_type')->insert($row);
    }

    protected function needVerification($configs)
    {
        foreach ($configs as $config) {
            if ((int)$config['verification_flag'] === 1) {
                return true;
            }
        }
        return false;
    }

    protected function buildAuxDesc($auxValues)
    {
        $parts = [];
        foreach ($auxValues as $aux) {
            if (empty($aux['aux_type_code']) || !isset($aux['aux_value']) || $aux['aux_value'] === '') {
                continue;
            }
            $label = $aux['aux_label'] ?? $aux['aux_value'];
            $parts[] = $aux['aux_type_code'] . ':' . $label;
        }
        return implode('; ', $parts);
    }

    protected function nextVoucherNo($period)
    {
        $where = [
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $this->fiscalYear($period),
            'period' => $period,
            'del_flag' => 0,
        ];
        $row = $this->getdb(self::TABLE_NO)->where($where)->lock(true)->find();
        if (!$row) {
            $data = [
                'sequence_id' => uuid(),
                'account_set_id' => $this->accountSetId,
                'fiscal_year' => $this->fiscalYear($period),
                'period' => $period,
                'current_no' => 1,
            ];
            $this->fillCreate($data);
            $this->getdb(self::TABLE_NO)->insert($data);
            return 1;
        }
        $next = ((int)$row['current_no']) + 1;
        $update = ['current_no' => $next];
        $this->fillUpdate($update);
        $this->getdb(self::TABLE_NO)->where(['sequence_id' => $row['sequence_id']])->update($update);
        return $next;
    }

    public function importVouchers($data = [])
    {
        $auth = $this->requirePermission('voucher:import');
        if ($auth) {
            return $auth;
        }

        $vouchers = $data['vouchers'] ?? [];
        if (!is_array($vouchers) || empty($vouchers)) {
            return $this->error('导入数据不能为空');
        }

        $success = 0;
        $failed = 0;
        $errors = [];
        $maxNoByPeriod = [];

        foreach ($vouchers as $index => $voucherData) {
            $period = $voucherData['period'] ?? '';
            $voucherNo = isset($voucherData['voucher_no']) ? (int)$voucherData['voucher_no'] : null;
            $voucherWord = $voucherData['voucher_word'] ?? '记';

            if ($period === '') {
                $failed++;
                $errors[] = '第' . ($index + 1) . '张凭证：会计期间不能为空';
                continue;
            }

            $voucherTable = self::TABLE;

            if ($voucherNo !== null) {
                $existing = $this->getdb($voucherTable)->where([
                    'account_set_id' => $this->accountSetId,
                    'fiscal_year' => $this->fiscalYear($period),
                    'period' => $period,
                    'voucher_no' => $voucherNo,
                    'del_flag' => 0,
                ])->find();
                if ($existing) {
                    $failed++;
                    $errors[] = '第' . ($index + 1) . '张凭证（' . $period . ' ' . $voucherWord . '-' . $voucherNo . '）：凭证号已存在';
                    continue;
                }
            }

            $preparedBy = $this->findUserIdByName($voucherData['prepared_by_name'] ?? '');
            $auditBy = $this->findUserIdByName($voucherData['audit_by_name'] ?? '');

            $voucherData['source_type'] = 'IMPORT';
            $voucherData['prepared_by'] = $preparedBy;
            $voucherData['audit_by'] = $auditBy;

            $result = $this->saveVoucher($voucherData, 'AUDITED');
            if ($result['code'] !== self::CODE_SUCCESS) {
                $failed++;
                $errors[] = '第' . ($index + 1) . '张凭证（' . $period . ' ' . $voucherWord . '-' . ($voucherNo ?? '?') . '）：' . $result['message'];
                continue;
            }

            $success++;

            if ($voucherNo !== null) {
                if (!isset($maxNoByPeriod[$period]) || $voucherNo > $maxNoByPeriod[$period]) {
                    $maxNoByPeriod[$period] = $voucherNo;
                }
            }
        }

        foreach ($maxNoByPeriod as $period => $maxNo) {
            $this->updateVoucherNoSequence($period, $maxNo);
        }

        return $this->ok([
            'success' => $success,
            'failed' => $failed,
            'errors' => $errors,
        ], '导入完成：成功' . $success . '张，失败' . $failed . '张');
    }

    protected function findUserIdByName($name)
    {
        $name = trim((string)$name);
        if ($name === '') {
            return $this->userid;
        }
        $user = $this->getdb('sys_user')
            ->where('del_flag', 0)
            ->where(function ($query) use ($name) {
                $query->where('real_name', $name)->whereOr('username', $name);
            })
            ->field('user_id')
            ->find();
        return $user ? $user['user_id'] : $this->userid;
    }

    protected function updateVoucherNoSequence($period, $maxNo)
    {
        $where = [
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $this->fiscalYear($period),
            'period' => $period,
            'del_flag' => 0,
        ];
        $row = $this->getdb(self::TABLE_NO)->where($where)->find();
        if (!$row) {
            $data = [
                'sequence_id' => uuid(),
                'account_set_id' => $this->accountSetId,
                'fiscal_year' => $this->fiscalYear($period),
                'period' => $period,
                'current_no' => $maxNo,
            ];
            $this->fillCreate($data);
            $this->getdb(self::TABLE_NO)->insert($data);
        } elseif ((int)$row['current_no'] < $maxNo) {
            $update = ['current_no' => $maxNo];
            $this->fillUpdate($update);
            $this->getdb(self::TABLE_NO)->where(['sequence_id' => $row['sequence_id']])->update($update);
        }
    }
}
