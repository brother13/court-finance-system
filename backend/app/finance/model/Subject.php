<?php

namespace app\finance\model;

class Subject extends Common
{
    const ACTION = 'subject';
    const TABLE = 'fin_subject';
    const FIELD_PK = 'subject_id';
    const FIELD = [
        'subject_id', 'account_set_id', 'subject_code', 'subject_name', 'parent_code',
        'direction', 'subject_type', 'level_no', 'leaf_flag', 'voucher_entry_flag', 'status',
        'created_by', 'created_time', 'updated_by', 'updated_time', 'del_flag', 'version', 'remark'
    ];

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'list':
                return $this->getList($data);
            case 'info':
                return $this->getInfo($data);
            case 'add':
                return $this->save('', $data);
            case 'save':
                return $this->save($data[self::FIELD_PK] ?? '', $data);
            case 'del':
                return $this->del($data[self::FIELD_PK] ?? '');
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function getList($data = [])
    {
        $auth = $this->requirePermission('base:view');
        if ($auth) {
            return $auth;
        }
        $where = $this->accountWhere();
        $key = $data['keyword'] ?? '';
        if ($key !== '') {
            $where['subject_code|subject_name'] = ['like', "%{$key}%"];
        }
        $rows = $this->getdb(self::TABLE)->where($where)->field(self::FIELD)->order('subject_code asc')->select();
        return $this->ok($rows, 'OK', count($rows));
    }

    public function getInfo($data = [])
    {
        $auth = $this->requirePermission('base:view');
        if ($auth) {
            return $auth;
        }
        $id = $data[self::FIELD_PK] ?? '';
        if (empty($id)) {
            return $this->error('科目ID不能为空');
        }
        $where = $this->accountWhere();
        $where[self::FIELD_PK] = $id;
        $row = $this->getdb(self::TABLE)->where($where)->field(self::FIELD)->find();
        if (!$row) {
            return $this->error('科目不存在');
        }
        return $this->ok($row);
    }

    public function save($id, $data)
    {
        $auth = $this->requirePermission(empty($id) ? 'base:add' : 'base:edit');
        if ($auth) {
            return $auth;
        }
        $code = trim($data['subject_code'] ?? '');
        $name = trim($data['subject_name'] ?? '');
        if ($code === '' || $name === '') {
            return $this->error('科目编码和名称不能为空');
        }
        $d = [];
        foreach (self::FIELD as $field) {
            if (isset($data[$field])) {
                $d[$field] = $data[$field];
            }
        }
        $d['account_set_id'] = $this->accountSetId;
        $d['subject_code'] = $code;
        $d['subject_name'] = $name;
        $d['direction'] = $d['direction'] ?? 'DEBIT';
        $d['subject_type'] = $d['subject_type'] ?? 'ASSET';
        $d['leaf_flag'] = $d['leaf_flag'] ?? 1;
        $d['voucher_entry_flag'] = $d['voucher_entry_flag'] ?? $d['leaf_flag'];
        $d['status'] = $d['status'] ?? 1;

        if ((int)$d['voucher_entry_flag'] === 1 && (int)$d['leaf_flag'] !== 1) {
            return $this->error('非末级科目不允许直接录入凭证');
        }

        $whereDup = $this->accountWhere();
        $whereDup['subject_code'] = $code;
        if (!empty($id)) {
            $whereDup[self::FIELD_PK] = ['neq', $id];
        }
        if ($this->getdb(self::TABLE)->where($whereDup)->count() > 0) {
            return $this->error('科目编码已存在');
        }

        if (empty($id)) {
            $d[self::FIELD_PK] = uuid();
            $this->fillCreate($d);
            $this->getdb(self::TABLE)->insert($d);
            $newId = $d[self::FIELD_PK];
            $this->logAudit('SUBJECT', $newId, 'CREATE', null, $d);
        } else {
            $where = $this->accountWhere();
            $where[self::FIELD_PK] = $id;
            $before = $this->getdb(self::TABLE)->where($where)->find();
            if (!$before) {
                return $this->error('科目不存在');
            }
            if ($this->subjectHasBusiness($before['subject_code'])) {
                if ($before['subject_type'] !== $d['subject_type']) {
                    return $this->error('科目已有期初或凭证，不允许修改科目类别');
                }
                if (($before['parent_code'] ?? '') !== ($d['parent_code'] ?? '')) {
                    return $this->error('科目已有期初或凭证，不允许修改科目层级');
                }
            }
            $this->fillUpdate($d);
            $this->getdb(self::TABLE)->where($where)->update($d);
            $newId = $id;
            $this->logAudit('SUBJECT', $id, 'UPDATE', $before, $d);
        }

        return $this->ok($newId, '操作成功');
    }

    public function del($id)
    {
        $auth = $this->requirePermission('base:delete');
        if ($auth) {
            return $auth;
        }
        if (empty($id)) {
            return $this->error('科目ID不能为空');
        }
        $where = $this->accountWhere();
        $where[self::FIELD_PK] = $id;
        $before = $this->getdb(self::TABLE)->where($where)->find();
        if (!$before) {
            return $this->error('科目不存在');
        }
        $d = ['del_flag' => 1, 'updated_by' => $this->userid, 'updated_time' => $this->now()];
        if ($this->subjectHasBusiness($before['subject_code'])) {
            return $this->error('科目已有期初或凭证，不允许删除');
        }
        $this->getdb(self::TABLE)->where($where)->update($d);
        $this->logAudit('SUBJECT', $id, 'DELETE', $before, $d);
        return $this->ok($id, '删除成功');
    }

    protected function subjectHasBusiness($subjectCode)
    {
        $openingRows = $this->getdb('fin_opening_balance')->where([
            'account_set_id' => $this->accountSetId,
            'subject_code' => $subjectCode,
            'del_flag' => 0,
        ])->select();
        foreach ($openingRows as $row) {
            if ((float)$row['debit_amount'] != 0 || (float)$row['credit_amount'] != 0) {
                return true;
            }
        }

        $year = config('default_year');
        $detailTable = 'fin_voucher_detail_' . $year;
        try {
            $voucherCount = $this->getdb($detailTable)->where([
                'account_set_id' => $this->accountSetId,
                'subject_code' => $subjectCode,
                'del_flag' => 0,
            ])->count();
            return $voucherCount > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}
