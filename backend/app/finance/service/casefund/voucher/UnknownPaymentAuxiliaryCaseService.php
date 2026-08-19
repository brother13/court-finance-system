<?php

namespace app\finance\service\casefund\voucher;

/**
 * 不明款案号辅助核算值服务。
 *
 * BMK 编号随每条新导入不明款持久保存；完整辅助值由缴款人、银行备注和 BMK 编号组成。
 * 本服务不判断科目是否启用 case_no，制证服务只在科目已配置时使用完整辅助值。
 */
class UnknownPaymentAuxiliaryCaseService
{
    const CODE_PREFIX = 'BMK-';
    const CODE_PATTERN = '/BMK-[A-Z0-9]{8}/i';
    const MAX_AUX_VALUE_LENGTH = 200;
    const MAX_ALLOCATION_ATTEMPTS = 100;

    protected $hooks;

    public function __construct(array $hooks)
    {
        foreach (['load_occupied_codes', 'persist_code'] as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('不明款辅助案号服务缺少回调：' . $name);
            }
        }
        if (isset($hooks['random_hex']) && !is_callable($hooks['random_hex'])) {
            throw new \InvalidArgumentException('不明款辅助案号服务回调不可调用：random_hex');
        }
        $this->hooks = $hooks;
    }

    /**
     * 为待导入的新行预分配编号；最终持久化仍由统一导入服务完成。
     */
    public function assignImportCodes(array $rows)
    {
        $occupied = $this->loadOccupiedCodeSet();
        foreach ($rows as &$row) {
            $existingCode = trim((string)($row['aux_case_code'] ?? ''));
            if ($existingCode !== '') {
                $row['aux_case_code'] = $this->normalizeCode($existingCode);
                $occupied[$row['aux_case_code']] = true;
                continue;
            }
            $row['aux_case_code'] = $this->allocateCode($occupied, $row, false);
        }
        unset($row);
        return $rows;
    }

    /**
     * 为锁定后的既有业务行补齐编号并写回，同时生成凭证规则使用的完整 case_no 值。
     */
    public function ensurePersistedCodes(array $rows)
    {
        $occupied = $this->loadOccupiedCodeSet();
        foreach ($rows as &$row) {
            $code = trim((string)($row['aux_case_code'] ?? ''));
            if ($code === '') {
                $code = $this->allocateCode($occupied, $row, true);
                $row['aux_case_code'] = $code;
            } else {
                $code = $this->normalizeCode($code);
                $row['aux_case_code'] = $code;
                $occupied[$code] = true;
            }
            $row['unknown_aux_case_no'] = $this->buildCaseNo($row);
        }
        unset($row);
        return $rows;
    }

    public function buildCaseNo(array $row)
    {
        $code = $this->normalizeCode($row['aux_case_code'] ?? '');
        $payerName = trim((string)($row['payer_name'] ?? ''));
        $bankRemark = trim((string)($row['bank_remark'] ?? ''));

        $value = $this->composeValue($payerName, $bankRemark, $code);
        if ($this->textLength($value) <= self::MAX_AUX_VALUE_LENGTH) {
            return $value;
        }

        // 超长时先缩短银行备注；仍超长才缩短缴款人。BMK 编号永不截断。
        if ($bankRemark !== '') {
            $excess = $this->textLength($value) - self::MAX_AUX_VALUE_LENGTH;
            $bankRemark = $this->textSubstring(
                $bankRemark,
                0,
                max(0, $this->textLength($bankRemark) - $excess)
            );
            $value = $this->composeValue($payerName, $bankRemark, $code);
        }
        if ($this->textLength($value) > self::MAX_AUX_VALUE_LENGTH && $payerName !== '') {
            $excess = $this->textLength($value) - self::MAX_AUX_VALUE_LENGTH;
            $payerName = $this->textSubstring(
                $payerName,
                0,
                max(0, $this->textLength($payerName) - $excess)
            );
            $value = $this->composeValue($payerName, $bankRemark, $code);
        }
        if ($this->textLength($value) > self::MAX_AUX_VALUE_LENGTH) {
            throw new \RuntimeException('不明款辅助案号长度无法压缩到200个字符以内');
        }
        return $value;
    }

    public function extractCodes(array $values)
    {
        $codes = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                foreach ($this->extractCodes($value) as $code) {
                    $codes[$code] = true;
                }
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            if (preg_match_all(self::CODE_PATTERN, (string)$value, $matches)) {
                foreach ($matches[0] as $match) {
                    $codes[strtoupper($match)] = true;
                }
            }
        }
        return array_keys($codes);
    }

    protected function allocateCode(array &$occupied, array $row, $persist)
    {
        for ($attempt = 0; $attempt < self::MAX_ALLOCATION_ATTEMPTS; $attempt++) {
            $suffix = $this->randomHex($row, $attempt);
            $code = self::CODE_PREFIX . $suffix;
            if (isset($occupied[$code])) {
                continue;
            }
            if ($persist) {
                $unknownPaymentId = trim((string)($row['unknown_payment_id'] ?? ''));
                if ($unknownPaymentId === '') {
                    throw new \InvalidArgumentException('不明款业务ID不能为空');
                }
                $affectedRows = $this->callHook('persist_code', [$unknownPaymentId, $code]);
                if ((int)$affectedRows !== 1) {
                    // 并发占用唯一编号时继续尝试下一个候选；其他数据库异常由回调直接抛出。
                    $occupied[$code] = true;
                    continue;
                }
            }
            $occupied[$code] = true;
            return $code;
        }
        throw new \RuntimeException('无法生成不重复的不明款辅助编号，请稍后重试');
    }

    protected function loadOccupiedCodeSet()
    {
        $values = $this->callHook('load_occupied_codes');
        if (!is_array($values)) {
            throw new \RuntimeException('不明款辅助编号占用数据格式不正确');
        }
        $occupied = [];
        foreach ($this->extractCodes($values) as $code) {
            $occupied[$code] = true;
        }
        return $occupied;
    }

    protected function normalizeCode($code)
    {
        $code = strtoupper(trim((string)$code));
        if (!preg_match('/^BMK-[A-Z0-9]{8}$/', $code)) {
            throw new \InvalidArgumentException('不明款辅助编号格式不正确：' . $code);
        }
        return $code;
    }

    protected function randomHex(array $row, $attempt)
    {
        if (isset($this->hooks['random_hex'])) {
            $suffix = call_user_func($this->hooks['random_hex'], $row, $attempt);
        } else {
            $suffix = bin2hex(random_bytes(4));
        }
        $suffix = strtoupper(trim((string)$suffix));
        if (!preg_match('/^[A-F0-9]{8}$/', $suffix)) {
            throw new \RuntimeException('不明款辅助编号随机源返回格式不正确');
        }
        return $suffix;
    }

    protected function composeValue($payerName, $bankRemark, $code)
    {
        $parts = [];
        foreach ([$payerName, $bankRemark, $code] as $part) {
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        return implode('｜', $parts);
    }

    protected function textLength($value)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        preg_match_all('/./us', $value, $matches);
        return count($matches[0]);
    }

    protected function textSubstring($value, $start, $length)
    {
        if ($length <= 0) {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, $start, $length, 'UTF-8');
        }
        preg_match_all('/./us', $value, $matches);
        return implode('', array_slice($matches[0], $start, $length));
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
