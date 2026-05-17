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
            case 'delete':
            case 'del':
                return $this->deleteVoucher($data);
            case 'batchDelete':
                return $this->batchDeleteVouchers($data);
            case 'void':
                return $this->voidVoucher($data);
            case 'printMark':
                return $this->printMark($data);
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
        $period = $data['period'] ?? input('param.period', '');
        if ($period === '') {
            return $this->error('会计期间不能为空');
        }
        $page = $data['page'] ?? input('param.page', 1);
        $pagesize = $data['pagesize'] ?? input('param.pagesize', 50);
        $table = self::TABLE;

        $voucherIds = $this->voucherIdsByDetailFilters($period, $data);
        if (is_array($voucherIds)) {
            if (empty($voucherIds)) {
                return $this->ok(['items' => [], 'total' => 0], 'OK', 0);
            }
        }

        $num = $this->buildVoucherListQuery($table, $period, $data, $voucherIds)->count();
        $rows = $this->buildVoucherListQuery($table, $period, $data, $voucherIds)
            ->order('voucher_no asc')
            ->page($page, $pagesize)
            ->select();
        $this->fillUserDisplayNames($rows);
        return $this->ok(['items' => $rows, 'total' => $num], 'OK', $num);
    }

    protected function buildVoucherListQuery($table, $period, $data, $voucherIds)
    {
        $query = $this->getdb($table)->where($this->buildVoucherHeaderWhere($period, $data));
        if (is_array($voucherIds)) {
            $query->where('voucher_id', 'in', $voucherIds);
        }
        return $query;
    }

    protected function buildVoucherHeaderWhere($period, $data)
    {
        $where = $this->accountWhere();
        $where['fiscal_year'] = $this->fiscalYear($period);
        $where['period'] = $period;

        $summaryKeyword = trim($data['summary_keyword'] ?? ($data['keyword'] ?? ''));
        if ($summaryKeyword !== '') {
            $where['summary'] = ['like', "%{$summaryKeyword}%"];
        }
        if (!empty($data['voucher_word'])) {
            $where['voucher_word'] = $data['voucher_word'];
        }
        if (!empty($data['status'])) {
            $where['status'] = $data['status'];
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

    protected function voucherIdsByDetailFilters($period, $data)
    {
        $sets = [];

        $debitIds = $this->voucherIdsByAmount($period, 'debit_amount', $data['debit_min'] ?? '', $data['debit_max'] ?? '');
        if (is_array($debitIds)) {
            $sets[] = $debitIds;
        }
        $creditIds = $this->voucherIdsByAmount($period, 'credit_amount', $data['credit_min'] ?? '', $data['credit_max'] ?? '');
        if (is_array($creditIds)) {
            $sets[] = $creditIds;
        }
        $caseIds = $this->voucherIdsByAux($period, 'case_no', $data['case_no'] ?? '');
        if (is_array($caseIds)) {
            $sets[] = $caseIds;
        }
        $receiptIds = $this->voucherIdsByAux($period, 'receipt_no', $data['receipt_no'] ?? '');
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

    protected function voucherIdsByAmount($period, $field, $min, $max)
    {
        if ($min === '' && $max === '') {
            return null;
        }
        $table = self::TABLE_DETAIL;
        $query = $this->getdb($table)->where([
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $this->fiscalYear($period),
            'period' => $period,
            'del_flag' => 0,
        ]);
        if ($min !== '') {
            $query->where($field, '>=', $this->centsToDecimal($this->decimalToCents($min)));
        }
        if ($max !== '') {
            $query->where($field, '<=', $this->centsToDecimal($this->decimalToCents($max)));
        }
        return array_values($query->group('voucher_id')->column('voucher_id'));
    }

    protected function voucherIdsByAux($period, $auxTypeCode, $keyword)
    {
        $keyword = trim((string)$keyword);
        if ($keyword === '') {
            return null;
        }
        $table = self::TABLE_AUX;
        $query = $this->getdb($table)->where([
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $this->fiscalYear($period),
            'period' => $period,
            'aux_type_code' => $auxTypeCode,
            'del_flag' => 0,
        ]);
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
                if (in_array($before['status'], ['AUDITED', 'PRINTED', 'VOIDED'])) {
                    Db::rollback();
                    return $this->error('已审核、已打印或已作废凭证不允许修改');
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
        $table = self::TABLE;
        $where = $this->voucherWhere($voucherId, $period);
        $before = $this->getdb($table)->where($where)->find();
        if (!$before) {
            return $this->error('凭证不存在');
        }
        if ($before['status'] !== $expected) {
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
        $table = self::TABLE;
        $where = $this->voucherWhere($voucherId, $period);
        $before = $this->getdb($table)->where($where)->find();
        if (!$before) {
            return $this->error('凭证不存在');
        }
        if ($before['printed_flag'] === '1') {
            return $this->error('已打印凭证不允许作废');
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
        $update = ['printed_flag' => '1', 'status' => 'PRINTED'];
        $this->fillUpdate($update);
        $this->getdb($table)->where($where)->update($update);
        $this->logAudit('VOUCHER', $voucherId, 'PRINT', $before, $update);
        return $this->ok($voucherId, '操作成功');
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
