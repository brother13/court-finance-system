<?php

namespace app\finance\service\casefund\voucher;

/**
 * 认领冲减原不明款辅助余额。仅接受完整交易特征唯一匹配，并复用原凭证值。
 * 调用方在制证事务内持有缴费记录锁；不创建编号、不用新案号兜底。
 */
class UnknownClaimAuxiliaryResolver
{
    private $getDb;
    private $accountSetId;

    public function __construct(callable $getDb, $accountSetId)
    {
        $this->getDb = $getDb;
        $this->accountSetId = $accountSetId;
    }

    public function resolve(array $payment, $subjectCode)
    {
        $prefix = '不明款认领（' . ($payment['receipt_no'] ?? $payment['payment_id'] ?? '') . '）：';
        $payer = trim((string)($payment['payer_name'] ?? ''));
        $time = trim((string)($payment['payment_time'] ?? ''));
        $bank = trim((string)($payment['bank_account_no'] ?? ''));
        // 缴费导出的收款账号可带“-虚拟子账号”；原不明款记录保存法院银行主账号。
        $bank = explode('-', $bank, 2)[0];
        if ($payer === '' || $bank === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $time)) {
            throw new \Exception($prefix . '缺少原交易的缴款人、完整时间或收款账号，无法确定原辅助核算');
        }
        $sources = $this->db('fin_case_fund_unknown_payment')->where($this->scope() + [
            'payer_name' => $payer,
            'unknown_amount' => $payment['payment_amount'],
            'transaction_date' => substr($time, 0, 10),
            'transaction_time' => $time,
            'court_bank_account_no' => $bank,
        ])->lock(true)->select();
        if (count($sources) !== 1) {
            throw new \Exception($prefix . '原不明款匹配到' . count($sources) . '条，请先核实来源，不能使用新案号替代');
        }
        $source = $sources[0];
        $voucherId = trim((string)($source['voucher_id'] ?? ''));
        $period = trim((string)($source['voucher_period'] ?? ''));
        if ($voucherId === '' || !preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new \Exception($prefix . '原不明款尚无有效入账凭证，请先完成原不明款制证');
        }
        $voucherScope = $this->scope() + [
            'voucher_id' => $voucherId, 'fiscal_year' => (int)substr($period, 0, 4), 'period' => $period,
        ];
        $voucher = $this->db('fin_voucher')->where($voucherScope)->lock(true)->find();
        if (!$voucher || !in_array($voucher['status'], ['SUBMITTED', 'AUDITED', 'PRINTED', 'POSTED'], true)) {
            throw new \Exception($prefix . '原不明款入账凭证不存在或状态无效');
        }
        $details = $this->db('fin_voucher_detail')->where($voucherScope + [
            'subject_code' => (string)$subjectCode, 'credit_amount' => $payment['payment_amount'],
        ])->lock(true)->select();
        $matches = [];
        foreach ($details as $detail) {
            $aux = $this->db('fin_voucher_aux_value')->where($voucherScope + [
                'detail_id' => $detail['detail_id'],
            ])->field('aux_type_code,aux_value,aux_label')->lock(true)->select();
            // 按日合并时可能有多笔同额贷方，必须用持久 BMK 区分原分录。
            $code = trim((string)($source['aux_case_code'] ?? ''));
            $hasCase = false;
            $matchesCode = false;
            foreach ($aux as $value) {
                if ($value['aux_type_code'] === 'case_no') {
                    $hasCase = true;
                    $matchesCode = $code !== '' && substr((string)$value['aux_value'], -strlen($code)) === $code;
                }
            }
            if ($hasCase && $code !== '' && !$matchesCode) {
                continue;
            }
            $matches[] = $aux;
        }
        if (count($matches) !== 1 || empty($matches[0])) {
            throw new \Exception($prefix . '无法唯一确定原入账分录的辅助核算，请核实原凭证及科目配置');
        }
        return $matches[0];
    }

    private function scope()
    {
        return ['account_set_id' => $this->accountSetId, 'del_flag' => 0];
    }

    private function db($table)
    {
        return call_user_func($this->getDb, $table);
    }
}
