<?php

namespace app\finance\model;

use app\finance\service\opening\OpeningAuxiliaryRule;
use think\Db;

class Opening extends Common
{
    const ACTION = 'opening';
    const TABLE = 'fin_opening_balance';

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'list':
                return $this->getList($data);
            case 'save':
                return $this->save($data);
            case 'auxList':
                return $this->auxList($data);
            case 'auxSave':
                return $this->auxSave($data);
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
        $period = $data['period'] ?? input('param.period', config('default_year') . '-01');
        $subjectRows = $this->getdb('fin_subject')
            ->where($this->accountWhere())
            ->order('subject_code asc')
            ->select();

        $balanceRows = $this->getdb(self::TABLE)
            ->where([
                'account_set_id' => $this->accountSetId,
                'period' => $period,
                'del_flag' => 0,
            ])
            ->select();

        $balanceMap = [];
        foreach ($balanceRows as $row) {
            $balanceMap[$row['subject_code']] = $row;
        }
        $configRows = $this->getdb('fin_subject_aux_config')->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->select();
        $configMap = [];
        foreach ($configRows as $config) {
            $configMap[$config['subject_code']][] = $config;
        }

        $rows = [];
        foreach ($subjectRows as $subject) {
            $code = $subject['subject_code'];
            $balance = isset($balanceMap[$code]) ? $balanceMap[$code] : [];
            $rows[] = [
                'balance_id' => $balance['balance_id'] ?? '',
                'period' => $period,
                'subject_code' => $code,
                'subject_name' => $subject['subject_name'],
                'direction' => $subject['direction'],
                'subject_type' => $subject['subject_type'],
                'leaf_flag' => $subject['leaf_flag'] ?? 1,
                'aux_config_count' => isset($configMap[$code]) ? count($configMap[$code]) : 0,
                'debit_amount' => $balance['debit_amount'] ?? '0.00',
                'credit_amount' => $balance['credit_amount'] ?? '0.00',
                'remark' => $balance['remark'] ?? '',
            ];
        }

        return $this->ok($rows, 'OK', count($rows));
    }

    public function save($data = [])
    {
        $auth = $this->requireOpeningSavePermission();
        if ($auth) {
            return $auth;
        }
        $period = $data['period'] ?? '';
        $items = $data['items'] ?? [];
        if ($period === '') {
            return $this->error('期间不能为空');
        }
        if (!is_array($items)) {
            return $this->error('期初数据格式不正确');
        }

        $saved = 0;
        foreach ($items as $item) {
            $subjectCode = trim($item['subject_code'] ?? '');
            if ($subjectCode === '') {
                continue;
            }
            $debit = (float)($item['debit_amount'] ?? 0);
            $credit = (float)($item['credit_amount'] ?? 0);
            if ($debit > 0 && $credit > 0) {
                return $this->error('同一科目的期初借方和贷方不能同时录入金额：' . $subjectCode);
            }
            $d = [
                'account_set_id' => $this->accountSetId,
                'period' => $period,
                'subject_code' => $subjectCode,
                'debit_amount' => $debit,
                'credit_amount' => $credit,
                'remark' => $item['remark'] ?? '',
            ];
            $where = [
                'account_set_id' => $this->accountSetId,
                'period' => $period,
                'subject_code' => $subjectCode,
                'del_flag' => 0,
            ];
            $before = $this->getdb(self::TABLE)->where($where)->find();
            if ($before) {
                $this->fillUpdate($d);
                $this->getdb(self::TABLE)->where('balance_id', $before['balance_id'])->update($d);
                $this->logAudit('OPENING_BALANCE', $before['balance_id'], 'UPDATE', $before, $d);
            } else {
                $d['balance_id'] = uuid();
                $this->fillCreate($d);
                $this->getdb(self::TABLE)->insert($d);
                $this->logAudit('OPENING_BALANCE', $d['balance_id'], 'CREATE', null, $d);
            }
            $saved++;
        }

        return $this->ok(['saved' => $saved], '保存成功');
    }

    public function auxList($data = [])
    {
        $auth = $this->requirePermission('base:view');
        if ($auth) {
            return $auth;
        }
        $period = $data['period'] ?? '';
        $subjectCode = trim($data['subject_code'] ?? '');
        if ($period === '' || $subjectCode === '') {
            return $this->error('期间和科目不能为空');
        }

        $configResult = $this->loadAuxConfigsAndTypes($subjectCode);
        $configs = $configResult['configs'];

        $archiveMap = [];
        foreach ($configs as $config) {
            $archiveMap[$config['aux_type_code']] = $this->getdb('fin_aux_archive')->where([
                'account_set_id' => $this->accountSetId,
                'aux_type_code' => $config['aux_type_code'],
                'status' => 1,
                'del_flag' => 0,
            ])->order('archive_code asc')->select();
        }

        $rows = $this->getdb('fin_aux_opening_balance')->where([
            'account_set_id' => $this->accountSetId,
            'period' => $period,
            'subject_code' => $subjectCode,
            'del_flag' => 0,
        ])->order('created_time asc')->select();

        foreach ($rows as &$row) {
            $decoded = json_decode($row['aux_values_json'], true);
            $row['aux_values'] = is_array($decoded) ? $decoded : [];
        }

        return $this->ok([
            'configs' => $configs,
            'archives' => $archiveMap,
            'rows' => $rows,
        ]);
    }

    public function auxSave($data = [])
    {
        $auth = $this->requireOpeningSavePermission();
        if ($auth) {
            return $auth;
        }
        $period = $data['period'] ?? '';
        $subjectCode = trim($data['subject_code'] ?? '');
        $items = $data['items'] ?? [];
        if ($period === '' || $subjectCode === '') {
            return $this->error('期间和科目不能为空');
        }
        if (!is_array($items)) {
            return $this->error('辅助期初数据格式不正确');
        }

        $subject = $this->getdb('fin_subject')->where([
            'account_set_id' => $this->accountSetId,
            'subject_code' => $subjectCode,
            'status' => 1,
            'del_flag' => 0,
        ])->find();
        if (!$subject) {
            return $this->error('科目不存在或已停用：' . $subjectCode);
        }

        $configResult = $this->loadAuxConfigsAndTypes($subjectCode);
        $where = [
            'account_set_id' => $this->accountSetId,
            'period' => $period,
            'subject_code' => $subjectCode,
            'del_flag' => 0,
        ];
        Db::startTrans();
        try {
            $archiveCache = [];
            $rule = new OpeningAuxiliaryRule(function ($typeCode, $archiveCode) use (&$archiveCache) {
                $key = $typeCode . '|' . $archiveCode;
                if (!array_key_exists($key, $archiveCache)) {
                    $archiveCache[$key] = $this->getdb('fin_aux_archive')->where([
                        'account_set_id' => $this->accountSetId,
                        'aux_type_code' => $typeCode,
                        'archive_code' => $archiveCode,
                        'status' => 1,
                        'del_flag' => 0,
                    ])->lock(true)->find();
                }
                return $archiveCache[$key];
            });
            $prepared = $rule->prepare($items, $configResult['configs'], $configResult['types']);

            $subjectOpening = $this->getdb(self::TABLE)->where($where)->lock(true)->find();
            $subjectDebitCents = $this->decimalToCents($subjectOpening['debit_amount'] ?? 0);
            $subjectCreditCents = $this->decimalToCents($subjectOpening['credit_amount'] ?? 0);
            if ($prepared['debit_total_cents'] !== $subjectDebitCents || $prepared['credit_total_cents'] !== $subjectCreditCents) {
                throw new \InvalidArgumentException(
                    '辅助期初明细合计必须等于科目期初余额；科目期初借方'
                    . $this->centsToDecimal($subjectDebitCents)
                    . '、贷方' . $this->centsToDecimal($subjectCreditCents)
                );
            }

            foreach ($prepared['manual_archives'] as $typeCode => $archiveCodes) {
                foreach ($archiveCodes as $archiveCode) {
                    $this->ensureManualAuxArchive($typeCode, $archiveCode);
                }
            }

            $before = $this->getdb('fin_aux_opening_balance')->where($where)->select();
            $this->getdb('fin_aux_opening_balance')->where($where)->update([
                'del_flag' => 1,
                'updated_by' => $this->userid,
                'updated_time' => $this->now(),
            ]);

            $saved = 0;
            foreach ($prepared['rows'] as $preparedRow) {
                $auxValues = $preparedRow['aux_values'];
                $row = [
                    'balance_id' => uuid(),
                    'account_set_id' => $this->accountSetId,
                    'period' => $period,
                    'subject_code' => $subjectCode,
                    'aux_values_json' => json_encode($auxValues, JSON_UNESCAPED_UNICODE),
                    'aux_desc' => $this->buildAuxDesc($auxValues),
                    'debit_amount' => $preparedRow['debit_amount'],
                    'credit_amount' => $preparedRow['credit_amount'],
                    'remark' => $preparedRow['remark'],
                ];
                $this->fillCreate($row);
                if ($this->getdb('fin_aux_opening_balance')->insert($row) !== 1) {
                    throw new \RuntimeException('辅助期初写入失败');
                }
                $saved++;
            }
            $this->logAudit('AUX_OPENING_BALANCE', $subjectCode . '@' . $period, 'SAVE', $before, $prepared['rows']);
            Db::commit();
        } catch (\InvalidArgumentException $e) {
            Db::rollback();
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('保存辅助期初失败：' . $e->getMessage());
        }
        return $this->ok(['saved' => $saved], '辅助期初已保存');
    }

    protected function loadAuxConfigsAndTypes($subjectCode)
    {
        $configs = $this->getdb('fin_subject_aux_config')->where([
            'account_set_id' => $this->accountSetId,
            'subject_code' => $subjectCode,
            'del_flag' => 0,
        ])->order('aux_type_code asc')->select();
        $types = $this->getdb('fin_aux_type')->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->select();
        $typeMap = [];
        foreach ($types as $type) {
            $typeMap[$type['aux_type_code']] = $type;
        }
        foreach ($configs as &$config) {
            $type = $typeMap[$config['aux_type_code']] ?? [];
            $config['aux_type_name'] = $type['aux_type_name'] ?? $config['aux_type_code'];
            $config['value_source'] = strtoupper((string)($type['value_source'] ?? 'ARCHIVE'));
            $config['aux_type_status'] = (int)($type['status'] ?? 0);
        }
        unset($config);
        return ['configs' => $configs, 'types' => $types];
    }

    protected function ensureManualAuxArchive($typeCode, $archiveCode)
    {
        $type = $this->getdb('fin_aux_type')->where([
            'account_set_id' => $this->accountSetId,
            'aux_type_code' => $typeCode,
            'del_flag' => 0,
        ])->lock(true)->find();
        if (!$type || (int)($type['status'] ?? 0) !== 1 || strtoupper((string)($type['value_source'] ?? '')) !== 'MANUAL') {
            throw new \RuntimeException('手工辅助维度不存在、已停用或来源类型不正确：' . $typeCode);
        }

        $archive = $this->getdb('fin_aux_archive')->where([
            'account_set_id' => $this->accountSetId,
            'aux_type_code' => $typeCode,
            'archive_code' => $archiveCode,
        ])->order('del_flag asc,updated_time desc')->lock(true)->find();
        if ($archive && (int)$archive['del_flag'] === 0) {
            if ((int)$archive['status'] !== 1) {
                throw new \RuntimeException('辅助档案已停用：' . $typeCode . '/' . $archiveCode);
            }
            return;
        }
        if ($archive) {
            $restored = [
                'archive_name' => $archiveCode,
                'status' => 1,
                'del_flag' => 0,
                'remark' => '辅助期初重新使用并恢复档案',
            ];
            $this->fillUpdate($restored);
            if ($this->getdb('fin_aux_archive')->where('archive_id', $archive['archive_id'])->update($restored) !== 1) {
                throw new \RuntimeException('辅助档案恢复失败：' . $typeCode . '/' . $archiveCode);
            }
            $this->logAudit('AUX_ARCHIVE', $archive['archive_id'], 'RESTORE', $archive, $restored);
            return;
        }

        $row = [
            'archive_id' => uuid(),
            'account_set_id' => $this->accountSetId,
            'aux_type_code' => $typeCode,
            'archive_code' => $archiveCode,
            'archive_name' => $archiveCode,
            'status' => 1,
            'remark' => '辅助期初自动建档',
        ];
        $this->fillCreate($row);
        if ($this->getdb('fin_aux_archive')->insert($row) !== 1) {
            throw new \RuntimeException('辅助档案写入失败：' . $typeCode . '/' . $archiveCode);
        }
        $this->logAudit('AUX_ARCHIVE', $row['archive_id'], 'AUTO_CREATE', null, $row);
    }

    protected function buildAuxDesc($auxValues)
    {
        $parts = [];
        foreach ($auxValues as $code => $value) {
            if ($value !== '') {
                $parts[] = $code . ':' . $value;
            }
        }
        return implode(' / ', $parts);
    }

    protected function requireOpeningSavePermission()
    {
        if ($this->hasPermission('opening:save') || $this->hasPermission('base:edit')) {
            return null;
        }
        return $this->error('无权限执行当前操作：opening:save');
    }
}
