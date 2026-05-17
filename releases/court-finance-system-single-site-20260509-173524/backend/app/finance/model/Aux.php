<?php

namespace app\finance\model;

use think\Db;

class Aux extends Common
{
    const ACTION = 'aux';
    const TABLE_TYPE = 'fin_aux_type';
    const TABLE_ARCHIVE = 'fin_aux_archive';
    const TABLE_CONFIG = 'fin_subject_aux_config';

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'typeList':
                return $this->typeList();
            case 'typeAdd':
                return $this->typeSave('', $data);
            case 'typeSave':
                return $this->typeSave($data['aux_type_id'] ?? '', $data);
            case 'typeDel':
                return $this->typeDelete($data);
            case 'archiveList':
                return $this->archiveList($data);
            case 'archiveAdd':
                return $this->archiveSave('', $data);
            case 'archiveSave':
                return $this->archiveSave($data['archive_id'] ?? '', $data);
            case 'archiveDel':
                return $this->archiveDelete($data);
            case 'archiveImport':
                return $this->archiveImport($data);
            case 'subjectConfig':
                return $this->subjectConfig($data);
            case 'subjectConfigSave':
                return $this->subjectConfigSave($data);
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function typeList()
    {
        $auth = $this->requirePermission('base:view');
        if ($auth) {
            return $auth;
        }
        $where = $this->accountWhere();
        $rows = $this->getdb(self::TABLE_TYPE)->where($where)->order('aux_type_code asc')->select();
        return $this->ok($rows, 'OK', count($rows));
    }

    public function typeSave($id, $data)
    {
        $auth = $this->requirePermission(empty($id) ? 'base:add' : 'base:edit');
        if ($auth) {
            return $auth;
        }
        $code = trim($data['aux_type_code'] ?? '');
        $name = trim($data['aux_type_name'] ?? '');
        if ($code === '' || $name === '') {
            return $this->error('辅助维度编码和名称不能为空');
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,49}$/', $code)) {
            return $this->error('辅助维度编码必须以字母开头，只能包含字母、数字和下划线');
        }

        $standardCodes = ['customer', 'supplier', 'department', 'employee', 'project'];
        $whereDup = $this->accountWhere();
        $whereDup['aux_type_code'] = $code;
        if (!empty($id)) {
            $whereDup['aux_type_id'] = ['neq', $id];
        }
        if ($this->getdb(self::TABLE_TYPE)->where($whereDup)->count() > 0) {
            return $this->error('辅助维度编码已存在');
        }

        $row = [
            'account_set_id' => $this->accountSetId,
            'aux_type_code' => $code,
            'aux_type_name' => $name,
            'value_source' => $data['value_source'] ?? 'ARCHIVE',
            'required_flag' => $data['required_flag'] ?? 0,
            'status' => $data['status'] ?? 1,
            'remark' => $data['remark'] ?? '',
        ];

        if (empty($id)) {
            if (in_array($code, $standardCodes)) {
                return $this->error('标准辅助维度已由系统预置，不允许重复新增');
            }
            $row['aux_type_id'] = uuid();
            $this->fillCreate($row);
            $this->getdb(self::TABLE_TYPE)->insert($row);
            $this->logAudit('AUX_TYPE', $row['aux_type_id'], 'CREATE', null, $row);
            return $this->ok($row['aux_type_id'], '操作成功');
        }

        $where = $this->accountWhere();
        $where['aux_type_id'] = $id;
        $before = $this->getdb(self::TABLE_TYPE)->where($where)->find();
        if (!$before) {
            return $this->error('辅助维度不存在');
        }
        if (in_array($before['aux_type_code'], $standardCodes) && $before['aux_type_code'] !== $code) {
            return $this->error('标准辅助维度编码不允许修改');
        }
        if ($this->auxTypeHasBusiness($before['aux_type_code']) && $before['aux_type_code'] !== $code) {
            return $this->error('辅助维度已被科目或凭证使用，不允许修改编码');
        }
        $this->fillUpdate($row);
        $this->getdb(self::TABLE_TYPE)->where($where)->update($row);
        $this->logAudit('AUX_TYPE', $id, 'UPDATE', $before, $row);
        return $this->ok($id, '操作成功');
    }

    public function typeDelete($data = [])
    {
        $auth = $this->requirePermission('base:delete');
        if ($auth) {
            return $auth;
        }
        $id = trim($data['aux_type_id'] ?? '');
        if ($id === '') {
            return $this->error('辅助维度ID不能为空');
        }
        $where = $this->accountWhere();
        $where['aux_type_id'] = $id;
        $before = $this->getdb(self::TABLE_TYPE)->where($where)->find();
        if (!$before) {
            return $this->error('辅助维度不存在');
        }
        if (in_array($before['aux_type_code'], $this->standardAuxTypeCodes(), true)) {
            return $this->error('标准辅助维度不允许删除');
        }
        if ($this->auxTypeHasBusiness($before['aux_type_code'])) {
            return $this->error('辅助维度已被科目或凭证使用，不允许删除');
        }

        $d = ['del_flag' => 1, 'updated_by' => $this->userid, 'updated_time' => $this->now()];
        Db::startTrans();
        try {
            $this->getdb(self::TABLE_TYPE)->where($where)->update($d);
            $archiveWhere = $this->accountWhere();
            $archiveWhere['aux_type_code'] = $before['aux_type_code'];
            $this->getdb(self::TABLE_ARCHIVE)->where($archiveWhere)->update($d);
            $this->logAudit('AUX_TYPE', $id, 'DELETE', $before, $d);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('删除辅助维度失败：' . $e->getMessage());
        }
        return $this->ok($id, '删除成功');
    }

    public function archiveList($data = [])
    {
        $auth = $this->requirePermission('base:view');
        if ($auth) {
            return $auth;
        }
        $where = $this->accountWhere();
        if (!empty($data['aux_type_code'])) {
            $where['aux_type_code'] = $data['aux_type_code'];
        }
        $key = $data['keyword'] ?? '';
        if ($key !== '') {
            $where['archive_code|archive_name'] = ['like', "%{$key}%"];
        }
        $page = $data['page'] ?? 1;
        $pagesize = $data['pagesize'] ?? 50;
        $db = $this->getdb(self::TABLE_ARCHIVE);
        $total = $db->where($where)->count();
        $rows = $db->where($where)->order('aux_type_code asc, archive_code asc')->page($page, $pagesize)->select();
        return $this->ok(['items' => $rows, 'total' => $total], 'OK', $total);
    }

    public function archiveSave($id, $data)
    {
        $auth = $this->requirePermission(empty($id) ? 'base:add' : 'base:edit');
        if ($auth) {
            return $auth;
        }
        $typeCode = trim($data['aux_type_code'] ?? '');
        $archiveCode = trim($data['archive_code'] ?? '');
        $archiveName = trim($data['archive_name'] ?? '');
        if ($typeCode === '' || $archiveName === '') {
            return $this->error('辅助类型、档案名称不能为空');
        }

        // 新增时档案编码为空则自动生成自增序号
        if (empty($id) && $archiveCode === '') {
            $maxCode = $this->getdb(self::TABLE_ARCHIVE)
                ->where($this->accountWhere())
                ->where('aux_type_code', $typeCode)
                ->where('del_flag', 0)
                ->where('archive_code', 'regexp', '^[0-9]+$')
                ->order('cast(archive_code as unsigned) desc')
                ->value('archive_code');
            $nextNo = $maxCode ? ((int)$maxCode + 1) : 1;
            $archiveCode = (string)$nextNo;
        }

        if ($archiveCode === '') {
            return $this->error('档案编码不能为空');
        }
        $d = [
            'account_set_id' => $this->accountSetId,
            'aux_type_code' => $typeCode,
            'archive_code' => $archiveCode,
            'archive_name' => $archiveName,
            'extra_json' => $data['extra_json'] ?? '',
            'status' => $data['status'] ?? 1,
            'remark' => $data['remark'] ?? '',
        ];
        if (empty($id)) {
            $d['archive_id'] = uuid();
            $this->fillCreate($d);
            $this->getdb(self::TABLE_ARCHIVE)->insert($d);
            $this->logAudit('AUX_ARCHIVE', $d['archive_id'], 'CREATE', null, $d);
            return $this->ok($d['archive_id'], '操作成功');
        }
        $where = $this->accountWhere();
        $where['archive_id'] = $id;
        $before = $this->getdb(self::TABLE_ARCHIVE)->where($where)->find();
        if (!$before) {
            return $this->error('辅助档案不存在');
        }
        $this->fillUpdate($d);
        $this->getdb(self::TABLE_ARCHIVE)->where($where)->update($d);
        $this->logAudit('AUX_ARCHIVE', $id, 'UPDATE', $before, $d);
        return $this->ok($id, '操作成功');
    }

    public function archiveDelete($data = [])
    {
        $auth = $this->requirePermission('base:delete');
        if ($auth) {
            return $auth;
        }
        $id = trim($data['archive_id'] ?? '');
        if ($id === '') {
            return $this->error('辅助档案ID不能为空');
        }
        $where = $this->accountWhere();
        $where['archive_id'] = $id;
        $before = $this->getdb(self::TABLE_ARCHIVE)->where($where)->find();
        if (!$before) {
            return $this->error('辅助档案不存在');
        }
        if ($this->auxArchiveHasBusiness($before['aux_type_code'], $before['archive_code'])) {
            return $this->error('辅助档案已被期初或凭证使用，不允许删除');
        }
        $d = ['del_flag' => 1, 'updated_by' => $this->userid, 'updated_time' => $this->now()];
        $this->getdb(self::TABLE_ARCHIVE)->where($where)->update($d);
        $this->logAudit('AUX_ARCHIVE', $id, 'DELETE', $before, $d);
        return $this->ok($id, '删除成功');
    }

    public function archiveImport($data = [])
    {
        $auth = $this->requirePermission('base:add');
        if ($auth) {
            return $auth;
        }

        $typeCode = trim($data['aux_type_code'] ?? '');
        $names = $data['names'] ?? [];
        if ($typeCode === '') {
            return $this->error('辅助维度编码不能为空');
        }
        if (!is_array($names) || empty($names)) {
            return $this->error('导入数据不能为空');
        }

        // 获取当前维度下已存在的档案名称（用于去重判断）
        $existingNames = $this->getdb(self::TABLE_ARCHIVE)
            ->where($this->accountWhere())
            ->where('aux_type_code', $typeCode)
            ->column('archive_name');
        $existingNameSet = array_flip($existingNames);

        // 获取当前维度下最大的数字编码（在 PHP 中处理，避免 ThinkPHP 表达式限制）
        $allCodes = $this->getdb(self::TABLE_ARCHIVE)
            ->where($this->accountWhere())
            ->where('aux_type_code', $typeCode)
            ->column('archive_code');
        $numericCodes = array_filter($allCodes, function ($code) {
            return preg_match('/^[0-9]+$/', (string)$code);
        });
        $maxCode = empty($numericCodes) ? 0 : max(array_map('intval', $numericCodes));
        $nextNo = $maxCode + 1;

        $success = 0;
        $skipped = 0;
        $errors = [];

        foreach ($names as $index => $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            if (isset($existingNameSet[$name])) {
                $skipped++;
                continue;
            }

            $archiveCode = (string)$nextNo;
            $row = [
                'archive_id' => uuid(),
                'account_set_id' => $this->accountSetId,
                'aux_type_code' => $typeCode,
                'archive_code' => $archiveCode,
                'archive_name' => $name,
                'extra_json' => '',
                'status' => 1,
                'remark' => '',
            ];
            $this->fillCreate($row);
            $this->getdb(self::TABLE_ARCHIVE)->insert($row);
            $this->logAudit('AUX_ARCHIVE', $row['archive_id'], 'IMPORT', null, $row);

            $existingNameSet[$name] = true;
            $nextNo++;
            $success++;
        }

        return $this->ok([
            'success' => $success,
            'skipped' => $skipped,
            'errors' => $errors,
        ], '导入完成：成功 ' . $success . ' 条，跳过 ' . $skipped . ' 条');
    }

    public function subjectConfig($data = [])
    {
        $auth = $this->requirePermission('base:view');
        if ($auth) {
            return $auth;
        }
        $subjectCode = $data['subject_code'] ?? '';
        if ($subjectCode === '') {
            return $this->error('科目编码不能为空');
        }
        $where = $this->accountWhere();
        $where['subject_code'] = $subjectCode;
        $rows = $this->getdb(self::TABLE_CONFIG)->where($where)->order('aux_type_code asc')->select();
        return $this->ok($rows, 'OK', count($rows));
    }

    public function subjectConfigSave($data = [])
    {
        $auth = $this->requirePermission('base:edit');
        if ($auth) {
            return $auth;
        }
        $subjectCode = trim($data['subject_code'] ?? '');
        $items = $data['items'] ?? [];
        if ($subjectCode === '') {
            return $this->error('科目编码不能为空');
        }
        if (!is_array($items)) {
            return $this->error('辅助核算配置格式不正确');
        }
        if ($this->subjectHasBusiness($subjectCode)) {
            return $this->error('科目已有期初或凭证，不允许修改辅助核算配置');
        }

        $currentCodes = [];
        foreach ($items as $item) {
            if (!empty($item['aux_type_code'])) {
                $currentCodes[] = $item['aux_type_code'];
            }
        }
        $currentCodes = array_values(array_unique($currentCodes));
        $interact = array_intersect($currentCodes, ['customer', 'supplier', 'employee']);
        if (count($interact) > 1) {
            return $this->error('客户、供应商、职员属于往来类辅助核算，同一科目只能选择其一');
        }

        $where = ['account_set_id' => $this->accountSetId];
        $where['subject_code'] = $subjectCode;
        $before = $this->getdb(self::TABLE_CONFIG)->where($where)->select();
        $existingByCode = [];
        foreach ($before as $row) {
            $existingByCode[$row['aux_type_code']] = $row;
        }

        foreach ($items as $item) {
            $code = trim($item['aux_type_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $row = [
                'account_set_id' => $this->accountSetId,
                'subject_code' => $subjectCode,
                'aux_type_code' => $code,
                'required_flag' => $item['required_flag'] ?? 1,
                'verification_flag' => $item['verification_flag'] ?? 0,
                'del_flag' => 0,
            ];
            if (isset($existingByCode[$code])) {
                $this->fillUpdate($row);
                $updateWhere = $where;
                $updateWhere['aux_type_code'] = $code;
                $this->getdb(self::TABLE_CONFIG)->where($updateWhere)->update($row);
            } else {
                $row['config_id'] = uuid();
                $this->fillCreate($row);
                $this->getdb(self::TABLE_CONFIG)->insert($row);
            }
        }

        foreach ($before as $row) {
            if (in_array($row['aux_type_code'], $currentCodes, true) || (int)$row['del_flag'] === 1) {
                continue;
            }
            $deleteWhere = $where;
            $deleteWhere['aux_type_code'] = $row['aux_type_code'];
            $this->getdb(self::TABLE_CONFIG)->where($deleteWhere)->update([
                'del_flag' => 1,
                'updated_by' => $this->userid,
                'updated_time' => $this->now(),
            ]);
        }
        $this->logAudit('SUBJECT_AUX_CONFIG', $subjectCode, 'SAVE', $before, $items);
        return $this->ok($subjectCode, '辅助核算配置已保存');
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

        try {
            $voucherCount = $this->getdb('fin_voucher_detail')->where([
                'account_set_id' => $this->accountSetId,
                'subject_code' => $subjectCode,
                'del_flag' => 0,
            ])->count();
            return $voucherCount > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function auxTypeHasBusiness($auxTypeCode)
    {
        $configCount = $this->getdb(self::TABLE_CONFIG)->where([
            'account_set_id' => $this->accountSetId,
            'aux_type_code' => $auxTypeCode,
            'del_flag' => 0,
        ])->count();
        if ($configCount > 0) {
            return true;
        }
        if ($this->auxTypeHasOpeningBusiness($auxTypeCode)) {
            return true;
        }
        try {
            return $this->getdb('fin_voucher_aux_value')->where([
                'account_set_id' => $this->accountSetId,
                'aux_type_code' => $auxTypeCode,
                'del_flag' => 0,
            ])->count() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function auxTypeHasOpeningBusiness($auxTypeCode)
    {
        $rows = $this->getdb('fin_aux_opening_balance')->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->select();
        foreach ($rows as $row) {
            $auxValues = json_decode($row['aux_values_json'] ?? '', true);
            if (is_array($auxValues) && array_key_exists($auxTypeCode, $auxValues)) {
                return true;
            }
        }
        return false;
    }

    protected function auxArchiveHasBusiness($auxTypeCode, $archiveCode)
    {
        try {
            $voucherCount = $this->getdb('fin_voucher_aux_value')->where([
                'account_set_id' => $this->accountSetId,
                'aux_type_code' => $auxTypeCode,
                'aux_value' => $archiveCode,
                'del_flag' => 0,
            ])->count();
            if ($voucherCount > 0) {
                return true;
            }
        } catch (\Exception $e) {
        }

        $rows = $this->getdb('fin_aux_opening_balance')->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->select();
        foreach ($rows as $row) {
            $auxValues = json_decode($row['aux_values_json'] ?? '', true);
            if (is_array($auxValues) && isset($auxValues[$auxTypeCode]) && (string)$auxValues[$auxTypeCode] === (string)$archiveCode) {
                return true;
            }
        }
        return false;
    }

    protected function standardAuxTypeCodes()
    {
        return ['customer', 'supplier', 'department', 'employee', 'project'];
    }
}
