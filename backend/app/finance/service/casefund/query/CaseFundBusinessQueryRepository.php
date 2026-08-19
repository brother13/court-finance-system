<?php

namespace app\finance\service\casefund\query;

/**
 * 案款业务登记列表查询；只读，不负责权限、业务类型准入或接口返回封装。
 */
class CaseFundBusinessQueryRepository
{
    protected $hooks;
    protected $tables;
    protected $fields;

    public function __construct(array $hooks, array $tables, array $fields)
    {
        foreach ([
            'get_db',
            'account_where',
            'current_year',
            'normalize_payment_method',
            'payment_method_options',
            'normalize_date_filter',
        ] as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('案款业务查询仓储缺少回调：' . $name);
            }
        }
        foreach (['payment', 'refund', 'unknown_payment', 'distribution'] as $name) {
            if (empty($tables[$name]) || !isset($fields[$name])) {
                throw new \InvalidArgumentException('案款业务查询仓储缺少表或字段：' . $name);
            }
        }
        $this->hooks = $hooks;
        $this->tables = $tables;
        $this->fields = $fields;
    }

    public function paymentList($data, $page, $pageSize, $allowedBusinessTypes)
    {
        $where = $this->baseWhere();
        $paymentId = $this->normalizeExactId($data['payment_id'] ?? ($data['paymentId'] ?? ''));
        if ($paymentId !== '') {
            $where['payment_id'] = $paymentId;
        } else {
            $this->applyCommonWhere($where, $data);
            $paymentMethodFilter = trim((string)($data['payment_method'] ?? ($data['paymentMethod'] ?? '')));
            if ($paymentMethodFilter !== '') {
                $paymentMethod = $this->callHook('normalize_payment_method', [$paymentMethodFilter]);
                if (isset($this->callHook('payment_method_options')[$paymentMethod])) {
                    $where['payment_method'] = $paymentMethod;
                }
            }
            $this->applyRawDateRange($where, 'payment_date', $data['date_start'] ?? '', $data['date_end'] ?? '');
            $this->applyAmountRange($where, 'payment_amount', $data);
        }
        $where['business_type'] = ['in', $allowedBusinessTypes];
        $keyword = $paymentId === '' ? trim($data['keyword'] ?? '') : '';
        $keywordFields = 'case_no|trial_case_no|payer_name|party_name|receipt_no|bank_serial_no|payment_order_no';

        $query = $this->db($this->tables['payment'])->where($where);
        $this->applyKeyword($query, $keywordFields, $keyword);
        $totalQuery = $this->db($this->tables['payment'])->where($where);
        $this->applyKeyword($totalQuery, $keywordFields, $keyword);
        $total = $totalQuery->count();
        $rows = $query->field($this->fields['payment'])
            ->order('payment_date asc, source_row_no asc')
            ->page($page, $pageSize)
            ->select();
        return ['items' => $rows, 'total' => $total];
    }

    public function refundList($data, $page, $pageSize, $allowedOutTypes)
    {
        $where = $this->baseWhere();
        $refundId = $this->normalizeExactId($data['refund_id'] ?? ($data['refundId'] ?? ''));
        if ($refundId !== '') {
            $where['refund_id'] = $refundId;
        } else {
            $this->applyCommonWhere($where, $data);
            if (isset($data['return_match_ids']) && is_array($data['return_match_ids'])) {
                $where['refund_id'] = ['in', $data['return_match_ids']];
            }
            $dateStart = $this->callHook('normalize_date_filter', [
                $data['date_start'] ?? ($data['startDate'] ?? ''),
            ]);
            $dateEnd = $this->callHook('normalize_date_filter', [
                $data['date_end'] ?? ($data['endDate'] ?? ''),
            ]);
            $this->applyRawDateRange($where, 'refund_date', $dateStart, $dateEnd);
            if (!empty($data['out_status'])) {
                $where['out_status'] = $data['out_status'];
            }
            $this->applyAmountRange($where, 'refund_amount', $data);
        }
        $where['out_type'] = ['in', $allowedOutTypes];
        $caseNo = $refundId === '' ? trim($data['case_no'] ?? ($data['caseNo'] ?? '')) : '';
        $partyName = $refundId === '' ? trim($data['party_name'] ?? ($data['partyName'] ?? '')) : '';
        $keyword = $refundId === '' ? trim($data['keyword'] ?? '') : '';

        $query = $this->refundFilteredQuery($where, $caseNo, $partyName, $keyword);
        $totalQuery = $this->refundFilteredQuery($where, $caseNo, $partyName, $keyword);
        $amountQuery = $this->refundFilteredQuery($where, $caseNo, $partyName, $keyword);
        $total = $totalQuery->count();
        $totalAmount = $amountQuery->sum('refund_amount');
        $rows = $query->field($this->fields['refund'])
            ->order('refund_date asc, source_row_no asc')
            ->page($page, $pageSize)
            ->select();
        return ['items' => $rows, 'total' => $total, 'total_amount' => $totalAmount];
    }

    public function unknownPaymentList($data, $page, $pageSize)
    {
        $where = $this->baseWhere();
        $unknownPaymentId = $this->normalizeExactId(
            $data['unknown_payment_id'] ?? ($data['unknownPaymentId'] ?? '')
        );
        if ($unknownPaymentId !== '') {
            $where['unknown_payment_id'] = $unknownPaymentId;
        } else {
            $this->applyCommonWhere($where, $data);
            if (isset($data['return_match_ids']) && is_array($data['return_match_ids'])) {
                $where['unknown_payment_id'] = ['in', $data['return_match_ids']];
            }
            if (!empty($data['claim_status'])) {
                $where['claim_status'] = $data['claim_status'];
            }
            $this->applyRawDateRange($where, 'transaction_date', $data['date_start'] ?? '', $data['date_end'] ?? '');
            $this->applyAmountRange($where, 'unknown_amount', $data);
        }
        return $this->simpleAmountList(
            'unknown_payment',
            $where,
            'payer_name|bank_summary|bank_remark|court_bank_account_no|payer_bank_name|payer_account_no',
            $unknownPaymentId === '' ? trim($data['keyword'] ?? '') : '',
            'unknown_amount',
            'transaction_date asc, source_row_no asc',
            $page,
            $pageSize
        );
    }

    public function distributionList($data, $page, $pageSize)
    {
        $where = $this->baseWhere();
        $this->applyCommonWhere($where, $data);
        if (!empty($data['invoice_type'])) {
            $where['invoice_type'] = $data['invoice_type'];
        }
        $dateStart = $this->callHook('normalize_date_filter', [$data['date_start'] ?? '']);
        $dateEnd = $this->callHook('normalize_date_filter', [$data['date_end'] ?? '']);
        $this->applyRawDateRange($where, 'receive_date', $dateStart, $dateEnd);
        return $this->simpleAmountList(
            'distribution',
            $where,
            'distribution_no|in_case_no|out_case_no|in_party_name|out_party_name|receipt_no|applicant_name',
            trim($data['keyword'] ?? ''),
            'distribution_amount',
            'receive_date asc, distribution_no asc, source_row_no asc',
            $page,
            $pageSize
        );
    }

    protected function simpleAmountList($tableKey, $where, $keywordFields, $keyword, $amountField, $order, $page, $pageSize)
    {
        $query = $this->db($this->tables[$tableKey])->where($where);
        $totalQuery = $this->db($this->tables[$tableKey])->where($where);
        $amountQuery = $this->db($this->tables[$tableKey])->where($where);
        foreach ([$query, $totalQuery, $amountQuery] as $item) {
            $this->applyKeyword($item, $keywordFields, $keyword);
        }
        $total = $totalQuery->count();
        $totalAmount = $amountQuery->sum($amountField);
        $rows = $query->field($this->fields[$tableKey])
            ->order($order)
            ->page($page, $pageSize)
            ->select();
        return ['items' => $rows, 'total' => $total, 'total_amount' => $totalAmount];
    }

    protected function refundFilteredQuery($where, $caseNo, $partyName, $keyword)
    {
        $query = $this->db($this->tables['refund'])->where($where);
        if ($caseNo !== '') {
            $query->where('case_no', 'like', '%' . $caseNo . '%');
        }
        if ($partyName !== '') {
            $query->where('party_name|actual_payee_name|payee_bank_account_name', 'like', '%' . $partyName . '%');
        }
        $this->applyKeyword(
            $query,
            'case_no|party_name|actual_payee_name|receipt_no|source_receipt_no|out_order_no|payee_bank_account_no',
            $keyword
        );
        return $query;
    }

    protected function baseWhere()
    {
        $where = $this->callHook('account_where');
        $where['fiscal_year'] = $this->callHook('current_year');
        return $where;
    }

    protected function applyCommonWhere(&$where, $data)
    {
        if (!empty($data['period'])) {
            $where['period'] = $data['period'];
        }
        if (!empty($data['voucher_status'])) {
            $where['voucher_status'] = $data['voucher_status'];
        }
    }

    protected function applyRawDateRange(&$where, $field, $dateStart, $dateEnd)
    {
        if ($dateStart !== '' && $dateEnd !== '') {
            $where[$field] = [['>=', $dateStart], ['<=', $dateEnd]];
        } elseif ($dateStart !== '') {
            $where[$field] = ['>=', $dateStart];
        } elseif ($dateEnd !== '') {
            $where[$field] = ['<=', $dateEnd];
        }
    }

    protected function applyAmountRange(&$where, $field, $data)
    {
        $amountMin = trim((string)($data['amount_min'] ?? ($data['amountMin'] ?? '')));
        $amountMax = trim((string)($data['amount_max'] ?? ($data['amountMax'] ?? '')));
        if ($amountMin !== '' && $amountMax !== '') {
            $where[$field] = [['>=', $amountMin], ['<=', $amountMax]];
        } elseif ($amountMin !== '') {
            $where[$field] = ['>=', $amountMin];
        } elseif ($amountMax !== '') {
            $where[$field] = ['<=', $amountMax];
        }
    }

    protected function applyKeyword($query, $fields, $keyword)
    {
        if ($keyword !== '') {
            $query->where($fields, 'like', '%' . $keyword . '%');
        }
    }

    protected function normalizeExactId($value)
    {
        return is_string($value) || is_numeric($value) ? trim((string)$value) : '';
    }

    protected function db($table)
    {
        return $this->callHook('get_db', [$table]);
    }

    protected function callHook($name, $args = [])
    {
        return call_user_func_array($this->hooks[$name], $args);
    }
}
