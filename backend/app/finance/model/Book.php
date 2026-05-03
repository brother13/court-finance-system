<?php

namespace app\finance\model;

class Book extends Common
{
    const ACTION = 'book';

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'detailLedger':
                return $this->detailLedger($data);
            case 'subjectBalance':
                return $this->subjectBalance($data);
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function detailLedger($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }
        $period = $data['period'] ?? '';
        if ($period === '') {
            return $this->error('会计期间不能为空');
        }
        $startDate = $data['start_date'] ?? substr($period, 0, 7) . '-01';
        $endDate = $data['end_date'] ?? date('Y-m-t', strtotime($startDate));
        $subjectCode = $data['subject_code'] ?? '';
        $voucherTable = $this->yearTable('fin_voucher', $period);
        $detailTable = $this->yearTable('fin_voucher_detail', $period);

        $where = [
            'd.account_set_id' => $this->accountSetId,
            'd.del_flag' => 0,
            'v.del_flag' => 0,
            'v.voucher_date' => ['between', [$startDate, $endDate]],
            'v.status' => ['in', ['AUDITED', 'PRINTED']],
        ];
        if ($subjectCode !== '') {
            $where['d.subject_code'] = $subjectCode;
        }

        $rows = db($detailTable)->alias('d')
            ->join($voucherTable . ' v', 'v.voucher_id=d.voucher_id and v.account_set_id=d.account_set_id')
            ->where($where)
            ->field('v.voucher_date,v.voucher_no,d.summary,d.subject_code,d.debit_amount,d.credit_amount,d.aux_desc')
            ->order('v.voucher_date asc,v.voucher_no asc,d.line_no asc')
            ->select();

        return $this->ok($rows, 'OK', count($rows));
    }

    public function subjectBalance($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }
        $period = $data['period'] ?? '';
        if ($period === '') {
            return $this->error('会计期间不能为空');
        }
        $voucherTable = $this->yearTable('fin_voucher', $period);
        $detailTable = $this->yearTable('fin_voucher_detail', $period);

        $rows = db('fin_subject')->alias('s')
            ->join($detailTable . ' d', "d.subject_code=s.subject_code and d.account_set_id=s.account_set_id and d.del_flag=0", 'LEFT')
            ->join($voucherTable . ' v', "v.voucher_id=d.voucher_id and v.account_set_id=d.account_set_id and v.period='" . addslashes($period) . "' and v.status in ('AUDITED','PRINTED') and v.del_flag=0", 'LEFT')
            ->where([
                's.account_set_id' => $this->accountSetId,
                's.del_flag' => 0,
            ])
            ->field("s.subject_code,s.subject_name,coalesce(sum(case when v.voucher_id is not null then d.debit_amount else 0 end),0) as debit_amount,coalesce(sum(case when v.voucher_id is not null then d.credit_amount else 0 end),0) as credit_amount,coalesce(sum(case when v.voucher_id is not null then d.debit_amount else 0 end),0)-coalesce(sum(case when v.voucher_id is not null then d.credit_amount else 0 end),0) as balance_amount")
            ->group('s.subject_code,s.subject_name')
            ->order('s.subject_code asc')
            ->select();

        return $this->ok($rows, 'OK', count($rows));
    }
}
