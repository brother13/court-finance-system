<?php

namespace app\finance\service\opening;

/**
 * 辅助期初行规则。
 *
 * 只负责输入归一化和财务校验，档案落库及期初保存事务由模型负责。
 */
class OpeningAuxiliaryRule
{
    protected $findArchive;

    public function __construct(callable $findArchive)
    {
        $this->findArchive = $findArchive;
    }

    public function prepare(array $items, array $configs, array $types)
    {
        $configMap = [];
        $orderedCodes = [];
        foreach ($configs as $config) {
            $code = trim((string)($config['aux_type_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $configMap[$code] = $config;
            $orderedCodes[] = $code;
        }
        if (empty($configMap)) {
            throw new \InvalidArgumentException('当前科目未配置辅助核算，不能录入辅助期初');
        }

        $typeMap = [];
        foreach ($types as $type) {
            $code = trim((string)($type['aux_type_code'] ?? ''));
            if ($code !== '') {
                $typeMap[$code] = $type;
            }
        }
        foreach ($orderedCodes as $code) {
            if (!isset($typeMap[$code]) || (int)($typeMap[$code]['status'] ?? 0) !== 1) {
                throw new \InvalidArgumentException('辅助核算维度不存在或已停用：' . $code);
            }
        }

        $preparedRows = [];
        $manualArchives = [];
        $combinationKeys = [];
        $debitTotalCents = 0;
        $creditTotalCents = 0;

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('辅助期初第' . ($index + 1) . '行格式不正确');
            }
            $debitCents = $this->amountToCents($item['debit_amount'] ?? 0, $index, '借方');
            $creditCents = $this->amountToCents($item['credit_amount'] ?? 0, $index, '贷方');
            if ($debitCents === 0 && $creditCents === 0) {
                continue;
            }
            if ($debitCents > 0 && $creditCents > 0) {
                throw new \InvalidArgumentException('辅助期初第' . ($index + 1) . '行不能同时录入借方和贷方');
            }

            $inputValues = $item['aux_values'] ?? [];
            if (!is_array($inputValues)) {
                throw new \InvalidArgumentException('辅助期初第' . ($index + 1) . '行辅助核算值格式不正确');
            }
            foreach ($inputValues as $code => $value) {
                $normalizedCode = trim((string)$code);
                if ($this->normalizeValue($value) !== '' && !isset($configMap[$normalizedCode])) {
                    throw new \InvalidArgumentException('辅助期初第' . ($index + 1) . '行包含科目未配置的辅助维度：' . $normalizedCode);
                }
            }

            $auxValues = [];
            foreach ($orderedCodes as $code) {
                $value = $this->normalizeValue($inputValues[$code] ?? '');
                if ((int)($configMap[$code]['required_flag'] ?? 0) === 1 && $value === '') {
                    throw new \InvalidArgumentException('辅助期初第' . ($index + 1) . '行缺少必填辅助维度：' . $code);
                }
                if ($value === '') {
                    continue;
                }
                $valueSource = strtoupper(trim((string)($typeMap[$code]['value_source'] ?? 'ARCHIVE')));
                if ($valueSource === 'MANUAL') {
                    $manualArchives[$code][$value] = $value;
                } else {
                    $archive = call_user_func($this->findArchive, $code, $value);
                    if (!$archive || (int)($archive['status'] ?? 0) !== 1 || (int)($archive['del_flag'] ?? 0) !== 0) {
                        throw new \InvalidArgumentException('辅助期初第' . ($index + 1) . '行辅助档案不存在或已停用：' . $code . '/' . $value);
                    }
                }
                $auxValues[$code] = $value;
            }

            $combinationKey = json_encode($auxValues, JSON_UNESCAPED_UNICODE);
            if (isset($combinationKeys[$combinationKey])) {
                throw new \InvalidArgumentException(
                    '辅助期初第' . ($index + 1) . '行与第' . $combinationKeys[$combinationKey] . '行辅助组合重复，请合并金额'
                );
            }
            $combinationKeys[$combinationKey] = $index + 1;
            $debitTotalCents += $debitCents;
            $creditTotalCents += $creditCents;
            $preparedRows[] = [
                'aux_values' => $auxValues,
                'debit_amount' => $this->centsToDecimal($debitCents),
                'credit_amount' => $this->centsToDecimal($creditCents),
                'remark' => trim((string)($item['remark'] ?? '')),
            ];
        }

        ksort($manualArchives, SORT_STRING);
        foreach ($manualArchives as &$values) {
            ksort($values, SORT_STRING);
            $values = array_values($values);
        }
        unset($values);

        return [
            'rows' => $preparedRows,
            'manual_archives' => $manualArchives,
            'debit_total_cents' => $debitTotalCents,
            'credit_total_cents' => $creditTotalCents,
        ];
    }

    protected function normalizeValue($value)
    {
        if (is_array($value) || is_object($value)) {
            throw new \InvalidArgumentException('辅助核算值必须是文本');
        }
        return trim((string)$value);
    }

    protected function amountToCents($amount, $index, $side)
    {
        if (is_array($amount) || is_object($amount)) {
            throw new \InvalidArgumentException('辅助期初第' . ($index + 1) . '行' . $side . '金额格式不正确');
        }
        $normalized = str_replace([',', ' '], '', trim((string)$amount));
        if ($normalized === '') {
            return 0;
        }
        if (!preg_match('/^\d{1,16}(?:\.\d{1,2})?$/', $normalized)) {
            throw new \InvalidArgumentException('辅助期初第' . ($index + 1) . '行' . $side . '金额格式不正确');
        }
        $parts = explode('.', $normalized, 2);
        $yuan = (int)$parts[0];
        $cent = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';
        return $yuan * 100 + (int)$cent;
    }

    protected function centsToDecimal($cents)
    {
        $cents = (int)$cents;
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
