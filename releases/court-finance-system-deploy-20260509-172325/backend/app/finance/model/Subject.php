<?php

namespace app\finance\model;

use think\Db;

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
            case 'codeRule':
                return $this->getCodeRule();
            case 'codeRuleSave':
                return $this->saveCodeRule($data);
            case 'import':
                return $this->importSubjects($data);
            case 'export':
                return $this->exportSubjects();
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
        $d['parent_code'] = trim($d['parent_code'] ?? '');
        $d['leaf_flag'] = $d['leaf_flag'] ?? 1;
        $d['voucher_entry_flag'] = $d['voucher_entry_flag'] ?? $d['leaf_flag'];
        $d['status'] = $d['status'] ?? 1;

        $codeRule = $this->normalizeCodeRule($this->subjectCodeRuleValue());
        $codeError = $this->validateSubjectCodeRule($code, $d['parent_code'], $codeRule);
        if ($codeError !== null) {
            return $this->error($codeError);
        }
        $d['level_no'] = $this->subjectCodeLevel($code, $codeRule);

        $parent = null;
        if ($d['parent_code'] !== '') {
            if ($d['parent_code'] === $code) {
                return $this->error('上级科目不能选择自己');
            }
            $whereParent = $this->accountWhere();
            $whereParent['subject_code'] = $d['parent_code'];
            $parent = $this->getdb(self::TABLE)->where($whereParent)->find();
            if (!$parent) {
                return $this->error('上级科目不存在');
            }
            if ($this->subjectHasBusiness($parent['subject_code'])) {
                return $this->error('上级科目已有期初或凭证，不允许新增下级科目');
            }
        }

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
            if ($parent) {
                $this->markParentAsBranch($parent['subject_code']);
            }
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
            if ($parent) {
                $this->markParentAsBranch($parent['subject_code']);
            }
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
        $whereChild = $this->accountWhere();
        $whereChild['parent_code'] = $before['subject_code'];
        if ($this->getdb(self::TABLE)->where($whereChild)->count() > 0) {
            return $this->error('科目存在下级科目，不允许删除');
        }
        if ($this->subjectHasBusiness($before['subject_code'])) {
            return $this->error('科目已有期初或凭证，不允许删除');
        }
        $d = ['del_flag' => 1, 'updated_by' => $this->userid, 'updated_time' => $this->now()];
        $this->getdb(self::TABLE)->where($where)->update($d);
        $this->logAudit('SUBJECT', $id, 'DELETE', $before, $d);
        return $this->ok($id, '删除成功');
    }

    public function importSubjects($data)
    {
        $auth = $this->requirePermission('base:add');
        if ($auth) {
            return $auth;
        }
        $content = $data['content_base64'] ?? '';
        if ($content === '') {
            return $this->error('导入文件不能为空');
        }
        $binary = base64_decode($content, true);
        if ($binary === false || $binary === '') {
            return $this->error('导入文件内容不正确');
        }

        try {
            $rows = $this->parseSubjectImportRowsFromXls($binary);
        } catch (\Exception $e) {
            return $this->error('解析科目导入文件失败：' . $e->getMessage());
        }
        if (empty($rows)) {
            return $this->error('导入文件没有科目数据');
        }

        $segments = $this->normalizeCodeRule($this->subjectCodeRuleValue());
        $errors = $this->validateImportRows($rows, $segments);
        if (!empty($errors)) {
            return $this->error('导入校验失败', $errors);
        }

        $existingSubjectWhere = ['account_set_id' => $this->accountSetId];
        $existingRows = $this->getdb(self::TABLE)->where($existingSubjectWhere)->select();
        $existingByCode = [];
        $allCodes = [];
        foreach ($existingRows as $row) {
            $existingByCode[$row['subject_code']] = $row;
        }
        $hierarchyCodes = $this->importHierarchyCodes($existingRows, $rows);

        $auxTypeRows = $this->getdb('fin_aux_type')->where($this->accountWhere())->select();
        $auxMap = [];
        foreach ($auxTypeRows as $type) {
            $auxMap[$type['aux_type_name']] = $type['aux_type_code'];
            $auxMap[$type['aux_type_code']] = $type['aux_type_code'];
        }

        $created = 0;
        $updated = 0;
        Db::startTrans();
        try {
            foreach ($rows as $row) {
                $code = $row['subject_code'];
                $parentCode = $this->parentCodeFromRule($code, $segments);
                if ($parentCode !== '' && empty($hierarchyCodes[$parentCode])) {
                    throw new \Exception('科目' . $code . '缺少上级科目' . $parentCode);
                }
                $hasChild = $this->codeHasChild($code, array_keys($hierarchyCodes));
                $subjectData = [
                    'account_set_id' => $this->accountSetId,
                    'subject_code' => $code,
                    'subject_name' => $row['subject_name'],
                    'parent_code' => $parentCode,
                    'direction' => $row['direction'],
                    'subject_type' => $row['subject_type'],
                    'level_no' => $this->subjectCodeLevel($code, $segments),
                    'leaf_flag' => $hasChild ? 0 : 1,
                    'voucher_entry_flag' => $hasChild ? 0 : 1,
                    'status' => 1,
                    'del_flag' => 0,
                    'remark' => '',
                ];

                if (isset($existingByCode[$code])) {
                    $before = $existingByCode[$code];
                    if ($this->subjectHasBusiness($code)) {
                        if ($before['subject_type'] !== $subjectData['subject_type']) {
                            throw new \Exception('科目' . $code . '已有期初或凭证，不允许修改科目类别');
                        }
                        if (($before['parent_code'] ?? '') !== $subjectData['parent_code']) {
                            throw new \Exception('科目' . $code . '已有期初或凭证，不允许修改科目层级');
                        }
                    }
                    $this->fillUpdate($subjectData);
                    $where = ['account_set_id' => $this->accountSetId];
                    $where['subject_code'] = $code;
                    $this->getdb(self::TABLE)->where($where)->update($subjectData);
                    $this->logAudit('SUBJECT', $before[self::FIELD_PK], 'IMPORT_UPDATE', $before, $subjectData);
                    $updated++;
                } else {
                    $subjectData[self::FIELD_PK] = uuid();
                    $this->fillCreate($subjectData);
                    $this->getdb(self::TABLE)->insert($subjectData);
                    $this->logAudit('SUBJECT', $subjectData[self::FIELD_PK], 'IMPORT_CREATE', null, $subjectData);
                    $created++;
                }

                $this->saveImportedAuxConfig($code, $row['aux_names'], $auxMap);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('导入失败：' . $e->getMessage());
        }

        return $this->ok([
            'total' => count($rows),
            'created' => $created,
            'updated' => $updated,
        ], '导入成功');
    }

    public function exportSubjects()
    {
        $auth = $this->requirePermission('base:view');
        if ($auth) {
            return $auth;
        }
        $subjects = $this->getdb(self::TABLE)->where($this->accountWhere())->field(self::FIELD)->order('subject_code asc')->select();
        $auxTypes = $this->getdb('fin_aux_type')->where($this->accountWhere())->select();
        $auxNameByCode = [];
        foreach ($auxTypes as $type) {
            $auxNameByCode[$type['aux_type_code']] = $type['aux_type_name'];
        }
        $configRows = $this->getdb('fin_subject_aux_config')->where($this->accountWhere())->order('aux_type_code asc')->select();
        $auxBySubject = [];
        foreach ($configRows as $config) {
            $name = $auxNameByCode[$config['aux_type_code']] ?? $config['aux_type_code'];
            $auxBySubject[$config['subject_code']][] = $name;
        }
        $rows = [];
        foreach ($subjects as $subject) {
            $rows[] = [
                'subject_code' => $subject['subject_code'],
                'subject_name' => $subject['subject_name'],
                'subject_type' => $subject['subject_type'],
                'direction' => $subject['direction'],
                'aux_names' => $auxBySubject[$subject['subject_code']] ?? [],
            ];
        }
        $xml = $this->buildSubjectExportXml($rows);
        return $this->ok([
            'filename' => '科目数据.xls',
            'mime' => 'application/vnd.ms-excel',
            'content_base64' => base64_encode($xml),
        ], '导出成功');
    }

    public function getCodeRule()
    {
        $auth = $this->requirePermission('base:view');
        if ($auth) {
            return $auth;
        }
        $segments = $this->normalizeCodeRule($this->subjectCodeRuleValue());
        return $this->ok([
            'rule' => implode('-', $segments),
            'segments' => $segments,
            'lengths' => $this->codeRuleLengths($segments),
        ]);
    }

    public function saveCodeRule($data)
    {
        $auth = $this->requirePermission('base:edit');
        if ($auth) {
            return $auth;
        }
        $segments = $this->normalizeCodeRule($data['rule'] ?? ($data['segments'] ?? '4-2-2-2'));
        $rule = implode('-', $segments);
        $where = [
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ];
        try {
            $this->getdb('fin_account_set')->where($where)->update([
                'subject_code_rule' => $rule,
                'updated_by' => $this->userid,
                'updated_time' => $this->now(),
            ]);
        } catch (\Exception $e) {
            return $this->error('保存科目编码规则失败，请先执行数据库升级脚本');
        }
        return $this->ok([
            'rule' => $rule,
            'segments' => $segments,
            'lengths' => $this->codeRuleLengths($segments),
        ], '保存成功');
    }

    protected function subjectCodeRuleValue()
    {
        try {
            $where = [
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ];
            $row = $this->getdb('fin_account_set')->where($where)->field('subject_code_rule')->find();
            if ($row && !empty($row['subject_code_rule'])) {
                return $row['subject_code_rule'];
            }
        } catch (\Exception $e) {
            return '4-2-2-2';
        }
        return '4-2-2-2';
    }

    protected function parseSubjectImportRowsFromXls($binary)
    {
        $cells = $this->parseBiffCells($this->readOleWorkbookStream($binary));
        if (empty($cells)) {
            throw new \Exception('文件中没有可识别的工作表数据');
        }
        $maxRow = -1;
        $maxCol = -1;
        foreach ($cells as $key => $value) {
            list($row, $col) = array_map('intval', explode(':', $key));
            $maxRow = max($maxRow, $row);
            $maxCol = max($maxCol, $col);
        }
        $headers = [];
        for ($col = 0; $col <= $maxCol; $col++) {
            $headers[$col] = $this->normalizeImportCellText($cells['0:' . $col] ?? '');
        }
        $required = ['科目编码', '科目名称', '科目类别', '余额方向', '辅助核算'];
        $index = [];
        foreach ($required as $name) {
            $found = array_search($name, $headers, true);
            if ($found === false) {
                throw new \Exception('缺少表头：' . $name);
            }
            $index[$name] = $found;
        }

        $rows = [];
        for ($row = 1; $row <= $maxRow; $row++) {
            $code = $this->normalizeImportCellText($cells[$row . ':' . $index['科目编码']] ?? '');
            $name = $this->normalizeImportCellText($cells[$row . ':' . $index['科目名称']] ?? '');
            if ($code === '' && $name === '') {
                continue;
            }
            $typeText = $this->normalizeImportCellText($cells[$row . ':' . $index['科目类别']] ?? '');
            $directionText = $this->normalizeImportCellText($cells[$row . ':' . $index['余额方向']] ?? '');
            $auxText = $this->normalizeImportCellText($cells[$row . ':' . $index['辅助核算']] ?? '');
            $rows[] = [
                'row_no' => $row + 1,
                'subject_code' => $code,
                'subject_name' => $name,
                'subject_type' => $this->subjectTypeFromLabel($typeText),
                'direction' => $this->directionFromLabel($directionText),
                'aux_names' => $this->parseAuxNamesText($auxText),
            ];
        }
        return $rows;
    }

    protected function validateImportRows($rows, $segments)
    {
        $errors = [];
        $seen = [];
        foreach ($rows as $row) {
            $prefix = '第' . $row['row_no'] . '行：';
            if ($row['subject_code'] === '' || $row['subject_name'] === '') {
                $errors[] = $prefix . '科目编码和名称不能为空';
                continue;
            }
            if (isset($seen[$row['subject_code']])) {
                $errors[] = $prefix . '科目编码重复';
            }
            $seen[$row['subject_code']] = true;
            $parentCode = $this->parentCodeFromRule($row['subject_code'], $segments);
            $codeError = $this->validateSubjectCodeRule($row['subject_code'], $parentCode, $segments);
            if ($codeError !== null) {
                $errors[] = $prefix . $codeError;
            }
            if (!in_array($row['subject_type'], ['ASSET', 'LIABILITY', 'COMMON', 'EQUITY', 'COST', 'PROFIT_LOSS'], true)) {
                $errors[] = $prefix . '科目类别不正确';
            }
            if (!in_array($row['direction'], ['DEBIT', 'CREDIT'], true)) {
                $errors[] = $prefix . '余额方向不正确';
            }
        }
        return $errors;
    }

    protected function readOleWorkbookStream($binary)
    {
        if (substr($binary, 0, 8) !== hex2bin('d0cf11e0a1b11ae1')) {
            throw new \Exception('仅支持 Excel 97-2003 .xls 文件');
        }
        $sectorSize = 1 << $this->u16($binary, 30);
        $dirStart = $this->u32($binary, 48);
        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $sid = $this->u32($binary, 76 + $i * 4);
            if ($sid < 0xFFFFFFF0) {
                $fatSectors[] = $sid;
            }
        }
        $fat = [];
        foreach ($fatSectors as $sid) {
            $sector = $this->oleSector($binary, $sectorSize, $sid);
            for ($pos = 0; $pos + 4 <= strlen($sector); $pos += 4) {
                $fat[] = $this->u32($sector, $pos);
            }
        }
        $dirStream = $this->oleFatStream($binary, $sectorSize, $fat, $dirStart, 1048576);
        $workbook = null;
        for ($pos = 0; $pos + 128 <= strlen($dirStream); $pos += 128) {
            $entry = substr($dirStream, $pos, 128);
            $nameLen = $this->u16($entry, 64);
            if ($nameLen < 2) {
                continue;
            }
            $name = iconv('UTF-16LE', 'UTF-8//IGNORE', substr($entry, 0, $nameLen - 2));
            if ($name !== 'Workbook' && $name !== 'Book') {
                continue;
            }
            $start = $this->u32($entry, 116);
            $size = $this->u64($entry, 120);
            $workbook = $this->oleFatStream($binary, $sectorSize, $fat, $start, $size);
            break;
        }
        if ($workbook === null) {
            throw new \Exception('未找到 Workbook 数据流');
        }
        return $workbook;
    }

    protected function parseBiffCells($stream)
    {
        $records = [];
        $pos = 0;
        while ($pos + 4 <= strlen($stream)) {
            $type = $this->u16($stream, $pos);
            $length = $this->u16($stream, $pos + 2);
            $pos += 4;
            $records[] = [$type, substr($stream, $pos, $length)];
            $pos += $length;
        }
        $sst = [];
        for ($i = 0; $i < count($records); $i++) {
            if ($records[$i][0] !== 0x00FC) {
                continue;
            }
            $blob = $records[$i][1];
            for ($j = $i + 1; $j < count($records) && $records[$j][0] === 0x003C; $j++) {
                $blob .= $records[$j][1];
            }
            $unique = $this->u32($blob, 4);
            $offset = 8;
            for ($k = 0; $k < $unique; $k++) {
                list($text, $offset) = $this->readBiffString($blob, $offset);
                $sst[] = $text;
            }
            break;
        }

        $cells = [];
        foreach ($records as $record) {
            list($type, $data) = $record;
            if ($type === 0x00FD && strlen($data) >= 10) {
                $row = $this->u16($data, 0);
                $col = $this->u16($data, 2);
                $sstIndex = $this->u32($data, 6);
                $cells[$row . ':' . $col] = $sst[$sstIndex] ?? '';
            } elseif ($type === 0x0203 && strlen($data) >= 14) {
                $row = $this->u16($data, 0);
                $col = $this->u16($data, 2);
                $value = unpack('e', substr($data, 6, 8))[1];
                $cells[$row . ':' . $col] = $value;
            } elseif ($type === 0x027E && strlen($data) >= 10) {
                $row = $this->u16($data, 0);
                $col = $this->u16($data, 2);
                $cells[$row . ':' . $col] = $this->decodeRkNumber($this->u32($data, 6));
            } elseif ($type === 0x00BE && strlen($data) >= 6) {
                $row = $this->u16($data, 0);
                $firstCol = $this->u16($data, 2);
                $lastCol = $this->u16($data, strlen($data) - 2);
                $offset = 4;
                for ($col = $firstCol; $col <= $lastCol && $offset + 6 <= strlen($data); $col++) {
                    $cells[$row . ':' . $col] = $this->decodeRkNumber($this->u32($data, $offset + 2));
                    $offset += 6;
                }
            }
        }
        return $cells;
    }

    protected function readBiffString($data, $offset)
    {
        if ($offset + 3 > strlen($data)) {
            return ['', strlen($data)];
        }
        $charCount = $this->u16($data, $offset);
        $offset += 2;
        $flags = ord($data[$offset]);
        $offset++;
        $hasRichText = ($flags & 0x08) !== 0;
        $hasExt = ($flags & 0x04) !== 0;
        $isUtf16 = ($flags & 0x01) !== 0;
        $richCount = 0;
        $extLength = 0;
        if ($hasRichText) {
            $richCount = $this->u16($data, $offset);
            $offset += 2;
        }
        if ($hasExt) {
            $extLength = $this->u32($data, $offset);
            $offset += 4;
        }
        $byteLength = $charCount * ($isUtf16 ? 2 : 1);
        $raw = substr($data, $offset, $byteLength);
        $offset += $byteLength;
        $text = $isUtf16 ? iconv('UTF-16LE', 'UTF-8//IGNORE', $raw) : $raw;
        $offset += $richCount * 4 + $extLength;
        return [$text, $offset];
    }

    protected function buildSubjectExportXml($rows)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        $xml .= '<Worksheet ss:Name="科目数据"><Table>';
        $headers = ['科目编码', '科目名称', '科目类别', '余额方向', '辅助核算'];
        $xml .= '<Row>';
        foreach ($headers as $header) {
            $xml .= '<Cell><Data ss:Type="String">' . $this->xmlText($header) . '</Data></Cell>';
        }
        $xml .= '</Row>';
        foreach ($rows as $row) {
            $auxNames = $row['aux_names'] ?? [];
            $values = [
                $row['subject_code'] ?? '',
                $row['subject_name'] ?? '',
                $this->subjectTypeLabel($row['subject_type'] ?? ''),
                $this->directionLabel($row['direction'] ?? ''),
                empty($auxNames) ? '[]' : '[' . implode(',', $auxNames) . ']',
            ];
            $xml .= '<Row>';
            foreach ($values as $value) {
                $xml .= '<Cell><Data ss:Type="String">' . $this->xmlText($value) . '</Data></Cell>';
            }
            $xml .= '</Row>';
        }
        $xml .= '</Table></Worksheet></Workbook>';
        return $xml;
    }

    protected function saveImportedAuxConfig($subjectCode, $auxNames, $auxMap)
    {
        if ($this->subjectHasBusiness($subjectCode)) {
            return;
        }
        $where = ['account_set_id' => $this->accountSetId];
        $where['subject_code'] = $subjectCode;
        $before = $this->getdb('fin_subject_aux_config')->where($where)->select();
        $this->saveSubjectAuxConfigRows($subjectCode, $auxNames, $auxMap);
        $after = $this->getdb('fin_subject_aux_config')->where($where)->select();
        $this->logAudit('SUBJECT_AUX_CONFIG', $subjectCode, 'IMPORT_SAVE', $before, $after);
    }

    protected function saveSubjectAuxConfigRows($subjectCode, $auxNames, $auxMap)
    {
        $where = ['account_set_id' => $this->accountSetId, 'subject_code' => $subjectCode];
        $existingRows = $this->getdb('fin_subject_aux_config')->where($where)->select();
        $existingByCode = [];
        foreach ($existingRows as $row) {
            $existingByCode[$row['aux_type_code']] = $row;
        }

        $desiredCodes = [];
        foreach ($auxNames as $name) {
            if (!isset($auxMap[$name])) {
                throw new \Exception('科目' . $subjectCode . '的辅助核算项不存在：' . $name);
            }
            $code = $auxMap[$name];
            $desiredCodes[] = $code;
            $row = [
                'account_set_id' => $this->accountSetId,
                'subject_code' => $subjectCode,
                'aux_type_code' => $code,
                'required_flag' => 1,
                'verification_flag' => 0,
                'del_flag' => 0,
            ];
            if (isset($existingByCode[$code])) {
                $this->fillUpdate($row);
                $updateWhere = $where;
                $updateWhere['aux_type_code'] = $code;
                $this->getdb('fin_subject_aux_config')->where($updateWhere)->update($row);
            } else {
                $row['config_id'] = uuid();
                $this->fillCreate($row);
                $this->getdb('fin_subject_aux_config')->insert($row);
            }
        }

        foreach ($existingRows as $row) {
            if (in_array($row['aux_type_code'], $desiredCodes, true) || (int)$row['del_flag'] === 1) {
                continue;
            }
            $deleteWhere = $where;
            $deleteWhere['aux_type_code'] = $row['aux_type_code'];
            $this->getdb('fin_subject_aux_config')->where($deleteWhere)->update([
                'del_flag' => 1,
                'updated_by' => $this->userid,
                'updated_time' => $this->now(),
            ]);
        }
    }

    protected function parentCodeFromRule($code, $segments)
    {
        $lengths = $this->codeRuleLengths($segments);
        $length = strlen((string)$code);
        $index = array_search($length, $lengths, true);
        if ($index === false || $index === 0) {
            return '';
        }
        return substr($code, 0, $lengths[$index - 1]);
    }

    protected function importHierarchyCodes($existingRows, $importRows)
    {
        $codes = [];
        foreach ($existingRows as $row) {
            if ((int)($row['del_flag'] ?? 0) === 0 && !empty($row['subject_code'])) {
                $codes[$row['subject_code']] = true;
            }
        }
        foreach ($importRows as $row) {
            if (!empty($row['subject_code'])) {
                $codes[$row['subject_code']] = true;
            }
        }
        return $codes;
    }

    protected function codeHasChild($code, $allCodes)
    {
        foreach ($allCodes as $candidate) {
            if ($candidate !== $code && strpos($candidate, $code) === 0) {
                return true;
            }
        }
        return false;
    }

    protected function normalizeImportCellText($value)
    {
        if (is_float($value) || is_int($value)) {
            return floor((float)$value) == (float)$value ? (string)(int)$value : (string)$value;
        }
        $text = trim((string)$value);
        if (preg_match('/^\d+\.0$/', $text)) {
            return substr($text, 0, -2);
        }
        return $text;
    }

    protected function subjectTypeFromLabel($label)
    {
        $map = [
            '资产' => 'ASSET',
            '负债' => 'LIABILITY',
            '共同' => 'COMMON',
            '权益' => 'EQUITY',
            '所有者权益' => 'EQUITY',
            '成本' => 'COST',
            '损益' => 'PROFIT_LOSS',
            'ASSET' => 'ASSET',
            'LIABILITY' => 'LIABILITY',
            'COMMON' => 'COMMON',
            'EQUITY' => 'EQUITY',
            'COST' => 'COST',
            'PROFIT_LOSS' => 'PROFIT_LOSS',
        ];
        return $map[$label] ?? '';
    }

    protected function subjectTypeLabel($type)
    {
        $map = [
            'ASSET' => '资产',
            'LIABILITY' => '负债',
            'COMMON' => '共同',
            'EQUITY' => '权益',
            'COST' => '成本',
            'PROFIT_LOSS' => '损益',
        ];
        return $map[$type] ?? $type;
    }

    protected function directionFromLabel($label)
    {
        $map = [
            '借' => 'DEBIT',
            '借方' => 'DEBIT',
            'DEBIT' => 'DEBIT',
            '贷' => 'CREDIT',
            '贷方' => 'CREDIT',
            'CREDIT' => 'CREDIT',
        ];
        return $map[$label] ?? '';
    }

    protected function directionLabel($direction)
    {
        return $direction === 'CREDIT' ? '贷' : '借';
    }

    protected function parseAuxNamesText($text)
    {
        $text = trim((string)$text);
        if ($text === '' || $text === '[]') {
            return [];
        }
        $text = trim($text, "[]【】 ");
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/[,，、;；]+/u', $text);
        $names = [];
        foreach ($parts as $part) {
            $name = trim($part);
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return array_values(array_unique($names));
    }

    protected function xmlText($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    protected function u16($data, $offset)
    {
        return unpack('v', substr($data, $offset, 2))[1];
    }

    protected function u32($data, $offset)
    {
        return unpack('V', substr($data, $offset, 4))[1];
    }

    protected function u64($data, $offset)
    {
        $low = $this->u32($data, $offset);
        $high = $this->u32($data, $offset + 4);
        return $high * 4294967296 + $low;
    }

    protected function oleSector($binary, $sectorSize, $sid)
    {
        $offset = 512 + $sid * $sectorSize;
        return substr($binary, $offset, $sectorSize);
    }

    protected function oleFatStream($binary, $sectorSize, $fat, $start, $size)
    {
        $parts = [];
        $seen = [];
        $sid = $start;
        while ($sid < 0xFFFFFFF0 && isset($fat[$sid]) && !isset($seen[$sid])) {
            $seen[$sid] = true;
            $parts[] = $this->oleSector($binary, $sectorSize, $sid);
            $sid = $fat[$sid];
        }
        return substr(implode('', $parts), 0, $size);
    }

    protected function decodeRkNumber($rk)
    {
        $isMultiplied = ($rk & 0x01) !== 0;
        $isInteger = ($rk & 0x02) !== 0;
        if ($isInteger) {
            $value = $rk >> 2;
            if ($value & 0x20000000) {
                $value -= 0x40000000;
            }
        } else {
            $value = unpack('e', pack('V2', 0, $rk & 0xFFFFFFFC))[1];
        }
        return $isMultiplied ? $value / 100 : $value;
    }

    protected function normalizeCodeRule($rule)
    {
        if (is_array($rule)) {
            $parts = $rule;
        } else {
            $parts = preg_split('/[^\d]+/', (string)$rule, -1, PREG_SPLIT_NO_EMPTY);
        }
        $segments = [];
        foreach ($parts as $part) {
            $value = (int)$part;
            if ($value > 0 && $value <= 9) {
                $segments[] = $value;
            }
        }
        if (empty($segments)) {
            return [4, 2, 2, 2];
        }
        return array_slice($segments, 0, 9);
    }

    protected function codeRuleLengths($segments)
    {
        $lengths = [];
        $total = 0;
        foreach ($segments as $segment) {
            $total += (int)$segment;
            $lengths[] = $total;
        }
        return $lengths;
    }

    protected function subjectCodeLevel($code, $segments)
    {
        $length = strlen((string)$code);
        $lengths = $this->codeRuleLengths($segments);
        $index = array_search($length, $lengths, true);
        return $index === false ? 0 : $index + 1;
    }

    protected function validateSubjectCodeRule($code, $parentCode, $segments)
    {
        if (!preg_match('/^\d+$/', $code)) {
            return '科目编码只能输入数字';
        }
        $lengths = $this->codeRuleLengths($segments);
        $codeLength = strlen($code);
        $parentCode = trim((string)$parentCode);
        if ($parentCode === '') {
            if ($codeLength !== $lengths[0]) {
                return '一级科目编码长度应为' . $lengths[0] . '位';
            }
            return null;
        }
        if (!preg_match('/^\d+$/', $parentCode)) {
            return '上级科目编码只能是数字';
        }
        $parentLength = strlen($parentCode);
        $parentIndex = array_search($parentLength, $lengths, true);
        if ($parentIndex === false || !isset($lengths[$parentIndex + 1])) {
            return '上级科目编码不符合当前编码规则';
        }
        $expectedLength = $lengths[$parentIndex + 1];
        if ($codeLength !== $expectedLength || strpos($code, $parentCode) !== 0) {
            return '科目编码长度应为' . $expectedLength . '位，且必须以上级科目编码' . $parentCode . '开头';
        }
        return null;
    }

    protected function markParentAsBranch($parentCode)
    {
        $where = $this->accountWhere();
        $where['subject_code'] = $parentCode;
        $this->getdb(self::TABLE)->where($where)->update([
            'leaf_flag' => 0,
            'voucher_entry_flag' => 0,
            'updated_by' => $this->userid,
            'updated_time' => $this->now(),
        ]);
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
}
