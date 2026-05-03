<?php

namespace app\finance\model;

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
        $auth = $this->requirePermission('base:edit');
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

        $configs = $this->getdb('fin_subject_aux_config')->where([
            'account_set_id' => $this->accountSetId,
            'subject_code' => $subjectCode,
            'del_flag' => 0,
        ])->order('aux_type_code asc')->select();

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
        $auth = $this->requirePermission('base:edit');
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

        $configs = $this->getdb('fin_subject_aux_config')->where([
            'account_set_id' => $this->accountSetId,
            'subject_code' => $subjectCode,
            'del_flag' => 0,
        ])->select();
        $requiredCodes = [];
        foreach ($configs as $config) {
            if ((int)$config['required_flag'] === 1) {
                $requiredCodes[] = $config['aux_type_code'];
            }
        }

        $preparedRows = [];
        foreach ($items as $item) {
            $auxValues = isset($item['aux_values']) && is_array($item['aux_values']) ? $item['aux_values'] : [];
            foreach ($requiredCodes as $code) {
                if (empty($auxValues[$code])) {
                    return $this->error('辅助期初缺少必填辅助维度：' . $code);
                }
            }
            $debit = (float)($item['debit_amount'] ?? 0);
            $credit = (float)($item['credit_amount'] ?? 0);
            if ($debit == 0 && $credit == 0) {
                continue;
            }
            if ($debit > 0 && $credit > 0) {
                return $this->error('辅助期初同一行不能同时录入借方和贷方');
            }
            $preparedRows[] = [
                'balance_id' => uuid(),
                'account_set_id' => $this->accountSetId,
                'period' => $period,
                'subject_code' => $subjectCode,
                'aux_values_json' => json_encode($auxValues, JSON_UNESCAPED_UNICODE),
                'aux_desc' => $this->buildAuxDesc($auxValues),
                'debit_amount' => $debit,
                'credit_amount' => $credit,
                'remark' => $item['remark'] ?? '',
            ];
        }

        $where = [
            'account_set_id' => $this->accountSetId,
            'period' => $period,
            'subject_code' => $subjectCode,
            'del_flag' => 0,
        ];
        $before = $this->getdb('fin_aux_opening_balance')->where($where)->select();
        $this->getdb('fin_aux_opening_balance')->where($where)->update([
            'del_flag' => 1,
            'updated_by' => $this->userid,
            'updated_time' => $this->now(),
        ]);

        $saved = 0;
        foreach ($preparedRows as $row) {
            $this->fillCreate($row);
            $this->getdb('fin_aux_opening_balance')->insert($row);
            $saved++;
        }
        $this->logAudit('AUX_OPENING_BALANCE', $subjectCode . '@' . $period, 'SAVE', $before, $items);
        return $this->ok(['saved' => $saved], '辅助期初已保存');
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
}
