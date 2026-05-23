<?php

namespace app\finance\model;

class CarryForward extends Common
{
    const ACTION = 'carryForward';
    const TABLE = 'fin_carry_forward_scheme';

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'info':
                return $this->info();
            case 'save':
                return $this->save($data);
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function info()
    {
        $auth = $this->requirePermission('base:view');
        if ($auth) {
            return $auth;
        }
        $row = $this->currentScheme();
        if (!$row) {
            return $this->ok($this->defaultScheme(), 'OK');
        }
        $row['rules'] = $this->decodeRules($row['rules_json'] ?? '[]');
        unset($row['rules_json']);
        return $this->ok($row, 'OK');
    }

    public function save($data = [])
    {
        $auth = $this->requirePermission('base:edit');
        if ($auth) {
            return $auth;
        }
        $validation = $this->validatePayload($data);
        if ($validation !== '') {
            return $this->error($validation);
        }

        $before = $this->currentScheme();
        $schemeId = $before['scheme_id'] ?? uuid();
        $rules = $this->normalizeRules($data['rules'] ?? []);
        $row = [
            'scheme_name' => trim($data['scheme_name'] ?? '收支结转方案'),
            'carry_mode' => 'INCOME_EXPENSE',
            'target_subject_code' => trim($data['target_subject_code'] ?? ''),
            'summary_template' => trim($data['summary_template'] ?? '年终收支结转'),
            'rules_json' => json_encode($rules, JSON_UNESCAPED_UNICODE),
            'enabled_flag' => (int)($data['enabled_flag'] ?? 1),
            'remark' => trim($data['remark'] ?? ''),
        ];

        if ($before) {
            $this->fillUpdate($row);
            $this->getdb(self::TABLE)->where([
                'scheme_id' => $schemeId,
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])->update($row);
        } else {
            $row['scheme_id'] = $schemeId;
            $row['account_set_id'] = $this->accountSetId;
            $this->fillCreate($row);
            $this->getdb(self::TABLE)->insert($row);
        }
        $after = $row;
        $after['scheme_id'] = $schemeId;
        $after['account_set_id'] = $this->accountSetId;
        $this->logAudit('CARRY_FORWARD_SCHEME', $schemeId, $before ? 'UPDATE' : 'CREATE', $before, $after);
        return $this->ok($schemeId, '保存成功');
    }

    protected function validatePayload($data)
    {
        if (trim($data['scheme_name'] ?? '') === '') {
            return '方案名称不能为空';
        }
        $enabled = (int)($data['enabled_flag'] ?? 1);
        $target = trim($data['target_subject_code'] ?? '');
        if ($enabled === 1 && $target === '') {
            return '启用方案时必须选择结转目标科目';
        }
        if ($target !== '') {
            $reason = $this->validateSubject($target, false);
            if ($reason !== '') {
                return '结转目标科目' . $reason;
            }
        }
        $rules = $this->normalizeRules($data['rules'] ?? []);
        foreach ($rules as $rule) {
            $sourceReason = $this->validateSubject($rule['source_subject_code'], true);
            if ($sourceReason !== '') {
                return '来源科目' . $sourceReason;
            }
            if ($rule['target_subject_code'] !== '') {
                $targetReason = $this->validateSubject($rule['target_subject_code'], false);
                if ($targetReason !== '') {
                    return '规则目标科目' . $targetReason;
                }
            }
        }
        return '';
    }

    protected function validateSubject($subjectCode, $expectProfitLoss)
    {
        $subject = $this->getdb('fin_subject')->where([
            'account_set_id' => $this->accountSetId,
            'subject_code' => $subjectCode,
            'del_flag' => 0,
        ])->find();
        if (!$subject) {
            return '不存在';
        }
        if ((int)($subject['status'] ?? 0) !== 1) {
            return '未启用';
        }
        if ((int)($subject['leaf_flag'] ?? 0) !== 1 || (int)($subject['voucher_entry_flag'] ?? 0) !== 1) {
            return '必须是允许录入凭证的末级科目';
        }
        $subjectType = $subject['subject_type'] ?? '';
        if ($expectProfitLoss && $subjectType !== 'PROFIT_LOSS') {
            return '必须是损益类科目';
        }
        if (!$expectProfitLoss && $subjectType === 'PROFIT_LOSS') {
            return '不能是损益类科目';
        }
        return '';
    }

    protected function normalizeRules($rules)
    {
        if (!is_array($rules)) {
            return [];
        }
        $items = [];
        foreach ($rules as $rule) {
            $sourceCode = trim($rule['source_subject_code'] ?? '');
            if ($sourceCode === '') {
                continue;
            }
            $items[] = [
                'source_subject_code' => $sourceCode,
                'target_subject_code' => trim($rule['target_subject_code'] ?? ''),
                'summary' => trim($rule['summary'] ?? ''),
            ];
        }
        return $items;
    }

    protected function decodeRules($json)
    {
        $rules = json_decode((string)$json, true);
        return is_array($rules) ? $this->normalizeRules($rules) : [];
    }

    protected function currentScheme()
    {
        return $this->getdb(self::TABLE)->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->order('updated_time desc, created_time desc')->find();
    }

    protected function defaultScheme()
    {
        return [
            'scheme_id' => '',
            'account_set_id' => $this->accountSetId,
            'scheme_name' => '收支结转方案',
            'carry_mode' => 'INCOME_EXPENSE',
            'target_subject_code' => '',
            'summary_template' => '年终收支结转',
            'enabled_flag' => 0,
            'remark' => '',
            'rules' => [],
        ];
    }
}
