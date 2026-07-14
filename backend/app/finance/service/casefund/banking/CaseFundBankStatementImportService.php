<?php

namespace app\finance\service\casefund\banking;

/**
 * 银行流水导入的标准化、指纹去重和待插入数据构造；不负责事务、写库或审计。
 */
class CaseFundBankStatementImportService
{
    protected $hooks;

    public function __construct(array $hooks)
    {
        foreach (['normalize_row', 'existing_fingerprints', 'new_id', 'account_set_id'] as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款银行流水导入服务缺少回调：' . $name);
            }
        }
        $this->hooks = $hooks;
    }

    public function prepare($rows, $bankCode, $bankName, $sourceFileName = '', $remark = '')
    {
        $parsedRows = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            $normalized = $this->callHook('normalize_row', [
                $row,
                $bankCode,
                $bankName,
                $index + 1,
            ]);
            if (!empty($normalized['error'])) {
                $errors[] = $normalized['error'];
                continue;
            }
            $parsedRows[] = $normalized;
        }
        if (!empty($errors) || empty($parsedRows)) {
            return [
                'errors' => $errors,
                'parsed_count' => count($parsedRows),
                'insert_rows' => [],
                'created' => 0,
                'skipped' => 0,
            ];
        }

        $fingerprints = array_column($parsedRows, 'source_fingerprint');
        $existing = $this->callHook('existing_fingerprints', [$fingerprints]);
        $seen = [];
        $insertRows = [];
        $skipped = 0;
        foreach ($parsedRows as $row) {
            $fingerprint = $row['source_fingerprint'];
            if (isset($existing[$fingerprint]) || isset($seen[$fingerprint])) {
                $skipped++;
                $seen[$fingerprint] = true;
                continue;
            }
            $seen[$fingerprint] = true;
            $row['statement_id'] = $this->callHook('new_id');
            $row['account_set_id'] = $this->callHook('account_set_id');
            $row['source_file_name'] = $sourceFileName;
            $row['reconcile_status'] = 'UNMATCHED';
            $row['remark'] = $remark;
            $insertRows[] = $row;
        }

        return [
            'errors' => [],
            'parsed_count' => count($parsedRows),
            'insert_rows' => $insertRows,
            'created' => count($insertRows),
            'skipped' => $skipped,
        ];
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
