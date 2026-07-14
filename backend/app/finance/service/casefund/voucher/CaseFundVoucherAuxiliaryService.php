<?php

namespace app\finance\service\casefund\voucher;

/**
 * 案款自动凭证辅助档案准备服务。
 *
 * 调用方负责外层事务；本服务按固定 case_no -> receipt_no 顺序锁定辅助类型，
 * 再补齐凭证明细实际使用的档案，避免并发制证重复创建活动档案。
 */
class CaseFundVoucherAuxiliaryService
{
    const SUPPORTED_TYPES = [
        'case_no' => '案号',
        'receipt_no' => '收据号',
    ];

    protected $hooks;

    public function __construct(array $hooks)
    {
        foreach (['get_db', 'account_set_id', 'new_id', 'prepare_create'] as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款凭证辅助档案服务缺少回调：' . $name);
            }
        }
        $this->hooks = $hooks;
    }

    public function ensureArchivesForVoucherDetails(array $detailsData)
    {
        $valuesByType = [
            'case_no' => [],
            'receipt_no' => [],
        ];
        foreach ($detailsData as $detailIndex => $detail) {
            if (!is_array($detail)) {
                throw new \InvalidArgumentException('凭证明细第' . ($detailIndex + 1) . '行格式不正确');
            }
            $auxValues = $detail['aux_values'] ?? [];
            if (!is_array($auxValues)) {
                throw new \InvalidArgumentException('凭证明细第' . ($detailIndex + 1) . '行辅助核算值格式不正确');
            }
            foreach ($auxValues as $auxIndex => $aux) {
                if (!is_array($aux)) {
                    throw new \InvalidArgumentException(
                        '凭证明细第' . ($detailIndex + 1) . '行第' . ($auxIndex + 1) . '个辅助核算值格式不正确'
                    );
                }
                $typeCode = trim((string)($aux['aux_type_code'] ?? ''));
                $value = trim((string)($aux['aux_value'] ?? ''));
                if ($value === '' || !array_key_exists($typeCode, self::SUPPORTED_TYPES)) {
                    continue;
                }
                $valuesByType[$typeCode][$value] = true;
            }
        }

        $created = 0;
        foreach (self::SUPPORTED_TYPES as $typeCode => $typeName) {
            $codes = array_keys($valuesByType[$typeCode]);
            if (empty($codes)) {
                continue;
            }
            $this->ensureAuxTypeAndLock($typeCode, $typeName);
            $created += $this->ensureArchivesByType($typeCode, $codes);
        }
        return $created;
    }

    protected function ensureAuxTypeAndLock($typeCode, $typeName)
    {
        $where = [
            'account_set_id' => $this->accountSetId(),
            'aux_type_code' => $typeCode,
        ];
        $exists = $this->db('fin_aux_type')->where($where)->lock(true)->find();
        if ($exists) {
            if ((int)($exists['del_flag'] ?? 0) !== 0) {
                throw new \RuntimeException('辅助核算类型已删除，不能自动制证：' . $typeCode);
            }
            return;
        }
        $row = [
            'aux_type_id' => $this->callHook('new_id'),
            'account_set_id' => $this->accountSetId(),
            'aux_type_code' => $typeCode,
            'aux_type_name' => $typeName,
            'value_source' => 'MANUAL',
            'required_flag' => 0,
            'status' => 1,
            'version' => 0,
        ];
        $row = $this->callHook('prepare_create', [$row]);
        $this->assertInserted(
            $this->db('fin_aux_type')->insert($row),
            '辅助核算类型写入失败：' . $typeCode
        );
    }

    protected function ensureArchivesByType($typeCode, array $codes)
    {
        sort($codes, SORT_STRING);
        $rows = $this->db('fin_aux_archive')->where([
            'account_set_id' => $this->accountSetId(),
            'aux_type_code' => $typeCode,
            'del_flag' => 0,
        ])->where('archive_code', 'in', $codes)->field(['archive_code'])->lock(true)->select();
        $active = [];
        foreach ($rows as $row) {
            $code = (string)$row['archive_code'];
            $active[$code] = true;
        }

        $created = 0;
        foreach ($codes as $code) {
            if (isset($active[$code])) {
                continue;
            }
            $row = [
                'archive_id' => $this->callHook('new_id'),
                'account_set_id' => $this->accountSetId(),
                'aux_type_code' => $typeCode,
                'archive_code' => $code,
                'archive_name' => $code,
                'status' => 1,
                'version' => 0,
            ];
            $row = $this->callHook('prepare_create', [$row]);
            $this->assertInserted(
                $this->db('fin_aux_archive')->insert($row),
                '辅助核算档案写入失败：' . $typeCode . '/' . $code
            );
            $created++;
        }
        return $created;
    }

    protected function assertInserted($affectedRows, $message)
    {
        if ($affectedRows !== 1) {
            throw new \RuntimeException($message);
        }
    }

    protected function db($table)
    {
        return $this->callHook('get_db', [$table]);
    }

    protected function accountSetId()
    {
        return $this->callHook('account_set_id');
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
