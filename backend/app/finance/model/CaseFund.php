<?php

namespace app\finance\model;

use think\Db;

class CaseFund extends Common
{
    const ACTION = 'caseFund';
    const TABLE_PAYMENT = 'fin_case_fund_payment';
    const TABLE_REFUND = 'fin_case_fund_refund';
    const PAYMENT_FIELD = [
        'payment_id', 'account_set_id', 'fiscal_year', 'period', 'case_no', 'confirmed_flag',
        'available_flag', 'business_type', 'payer_name', 'party_name', 'invoice_title',
        'payment_amount', 'register_type', 'trial_case_no', 'payment_date', 'payment_time',
        'receipt_no', 'invoice_date', 'invoice_operator', 'payment_method', 'cashier_name',
        'judge_name', 'clerk_name', 'department_name', 'bank_account_no', 'bank_serial_no',
        'payment_order_no', 'internal_transfer_ticket_no', 'deposit_revoke_flag',
        'source_file_name', 'source_row_no', 'source_fingerprint', 'source_raw_json',
        'voucher_status', 'voucher_id', 'voucher_no', 'voucher_period', 'voucher_generated_time',
        'created_by', 'created_time', 'updated_by', 'updated_time', 'del_flag', 'version', 'remark',
    ];
    const REFUND_FIELD = [
        'refund_id', 'account_set_id', 'fiscal_year', 'period', 'case_no', 'handler_name',
        'clerk_name', 'receipt_no', 'invoice_date', 'refund_date', 'source_receipt_no',
        'source_receipt_date', 'out_order_no', 'out_status', 'out_type', 'litigation_position',
        'party_name', 'refund_amount', 'total_refund_amount', 'payee_party_relation',
        'payment_method', 'actual_payee_name', 'payee_identity_no', 'payee_bank_account_name',
        'payee_bank_account_no', 'payee_bank_name', 'unionpay_no', 'same_bank_flag',
        'handler_note', 'applicant_name', 'source_file_name', 'source_row_no',
        'source_fingerprint', 'source_raw_json', 'voucher_status', 'voucher_id', 'voucher_no',
        'voucher_period', 'voucher_generated_time', 'created_by', 'created_time', 'updated_by',
        'updated_time', 'del_flag', 'version', 'remark',
    ];

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'paymentList':
                return $this->paymentList($data);
            case 'paymentImport':
                return $this->paymentImport($data);
            case 'refundList':
                return $this->refundList($data);
            case 'refundImport':
                return $this->refundImport($data);
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function paymentList($data = [])
    {
        $auth = $this->requirePermission('case_fund:view');
        if ($auth) {
            return $auth;
        }
        $page = $data['page'] ?? input('param.page', 1);
        $pagesize = $data['pagesize'] ?? input('param.pagesize', 50);
        $where = $this->accountWhere();
        if (!empty($data['period'])) {
            $where['period'] = $data['period'];
        }
        if (!empty($data['voucher_status'])) {
            $where['voucher_status'] = $data['voucher_status'];
        }
        if (!empty($data['date_start'])) {
            $where['payment_date'][] = ['>=', $data['date_start']];
        }
        if (!empty($data['date_end'])) {
            $where['payment_date'][] = ['<=', $data['date_end']];
        }
        $bizType = $this->currentAccountSetBizType();
        $allowedBusinessTypes = $this->allowedPaymentBusinessTypes($bizType);
        if (empty($allowedBusinessTypes)) {
            return $this->error('当前账套类型不支持案款缴费登记：' . $bizType);
        }
        $where['business_type'] = ['in', $allowedBusinessTypes];
        $keyword = trim($data['keyword'] ?? '');
        $query = $this->getdb(self::TABLE_PAYMENT)->where($where);
        if ($keyword !== '') {
            $query->where('case_no|trial_case_no|payer_name|party_name|receipt_no|bank_serial_no|payment_order_no', 'like', '%' . $keyword . '%');
        }
        $totalQuery = $this->getdb(self::TABLE_PAYMENT)->where($where);
        if ($keyword !== '') {
            $totalQuery->where('case_no|trial_case_no|payer_name|party_name|receipt_no|bank_serial_no|payment_order_no', 'like', '%' . $keyword . '%');
        }
        $total = $totalQuery->count();
        $rows = $query->field(self::PAYMENT_FIELD)
            ->order('payment_date desc, source_row_no asc')
            ->page($page, $pagesize)
            ->select();
        return $this->ok(['items' => $rows, 'total' => $total], 'OK', $total);
    }

    public function paymentImport($data = [])
    {
        $auth = $this->requirePermission('case_fund:import');
        if ($auth) {
            return $auth;
        }
        $content = $data['content_base64'] ?? '';
        if ($content === '') {
            return $this->error('导入文件不能为空');
        }
        $binary = base64_decode($content, true);
        if ($binary === false || $binary === '') {
            return $this->error('导入文件内容不正确');
        }

        try {
            $rows = $this->parsePaymentImportRowsFromXls($binary);
        } catch (\Exception $e) {
            return $this->error('解析案款缴费登记失败：' . $e->getMessage());
        }
        if (empty($rows)) {
            return $this->error('导入文件没有案款缴费数据');
        }

        $bizType = $this->currentAccountSetBizType();
        $errors = $this->validatePaymentRows($rows, $bizType);
        if (!empty($errors)) {
            return $this->error($this->paymentImportErrorMessage($errors, $bizType), $errors);
        }

        $fingerprints = [];
        foreach ($rows as $row) {
            $fingerprints[] = $row['source_fingerprint'];
        }
        $existing = [];
        if (!empty($fingerprints)) {
            $existingRows = $this->getdb(self::TABLE_PAYMENT)->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])->where('source_fingerprint', 'in', array_values(array_unique($fingerprints)))->select();
            foreach ($existingRows as $row) {
                $existing[$row['source_fingerprint']] = true;
            }
        }

        $created = 0;
        $skipped = 0;
        $seen = [];
        Db::startTrans();
        try {
            foreach ($rows as $row) {
                $fingerprint = $row['source_fingerprint'];
                if (isset($existing[$fingerprint]) || isset($seen[$fingerprint])) {
                    $skipped++;
                    $seen[$fingerprint] = true;
                    continue;
                }
                $seen[$fingerprint] = true;
                $insert = $row;
                $insert['payment_id'] = uuid();
                $insert['account_set_id'] = $this->accountSetId;
                $insert['source_file_name'] = $data['filename'] ?? '';
                $insert['voucher_status'] = 'UNGENERATED';
                $insert['voucher_id'] = null;
                $insert['voucher_no'] = null;
                $insert['voucher_period'] = null;
                $insert['voucher_generated_time'] = null;
                $insert['remark'] = $data['remark'] ?? '';
                $this->fillCreate($insert);
                $this->getdb(self::TABLE_PAYMENT)->insert($insert);
                $created++;
            }
            $this->logAudit('CASE_FUND_PAYMENT', 'IMPORT', 'IMPORT', null, [
                'filename' => $data['filename'] ?? '',
                'total' => count($rows),
                'created' => $created,
                'skipped' => $skipped,
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('保存案款缴费登记失败：' . $e->getMessage());
        }

        return $this->ok([
            'total' => count($rows),
            'created' => $created,
            'skipped' => $skipped,
        ], '导入成功', count($rows));
    }

    public function refundList($data = [])
    {
        $auth = $this->requirePermission('case_fund:view');
        if ($auth) {
            return $auth;
        }
        $page = $data['page'] ?? input('param.page', 1);
        $pagesize = $data['pagesize'] ?? ($data['pageSize'] ?? input('param.pagesize', 50));
        $where = $this->accountWhere();
        if (!empty($data['period'])) {
            $where['period'] = $data['period'];
        }
        if (!empty($data['voucher_status'])) {
            $where['voucher_status'] = $data['voucher_status'];
        }
        $dateStart = $data['date_start'] ?? ($data['startDate'] ?? '');
        $dateEnd = $data['date_end'] ?? ($data['endDate'] ?? '');
        if ($dateStart !== '') {
            $where['refund_date'][] = ['>=', $dateStart];
        }
        if ($dateEnd !== '') {
            $where['refund_date'][] = ['<=', $dateEnd];
        }
        if (!empty($data['out_status'])) {
            $where['out_status'] = $data['out_status'];
        }
        $bizType = $this->currentAccountSetBizType();
        $allowedOutTypes = $this->allowedRefundOutTypes($bizType);
        if (empty($allowedOutTypes)) {
            return $this->error('当前账套类型不支持案款退付登记：' . $bizType);
        }
        $where['out_type'] = ['in', $allowedOutTypes];
        $caseNo = trim($data['case_no'] ?? ($data['caseNo'] ?? ''));
        $partyName = trim($data['party_name'] ?? ($data['partyName'] ?? ''));
        $keyword = trim($data['keyword'] ?? '');

        $query = $this->getdb(self::TABLE_REFUND)->where($where);
        $totalQuery = $this->getdb(self::TABLE_REFUND)->where($where);
        $amountQuery = $this->getdb(self::TABLE_REFUND)->where($where);
        foreach ([$query, $totalQuery, $amountQuery] as $item) {
            if ($caseNo !== '') {
                $item->where('case_no', 'like', '%' . $caseNo . '%');
            }
            if ($partyName !== '') {
                $item->where('party_name|actual_payee_name|payee_bank_account_name', 'like', '%' . $partyName . '%');
            }
            if ($keyword !== '') {
                $item->where('case_no|party_name|actual_payee_name|receipt_no|source_receipt_no|out_order_no|payee_bank_account_no', 'like', '%' . $keyword . '%');
            }
        }

        $total = $totalQuery->count();
        $totalAmount = $amountQuery->sum('refund_amount');
        $rows = $query->field(self::REFUND_FIELD)
            ->order('refund_date desc, source_row_no asc')
            ->page($page, $pagesize)
            ->select();
        return $this->ok(['items' => $rows, 'total' => $total, 'total_amount' => $totalAmount], 'OK', $total);
    }

    public function refundImport($data = [])
    {
        $auth = $this->requirePermission('case_fund:import');
        if ($auth) {
            return $auth;
        }
        $content = $data['content_base64'] ?? '';
        if ($content === '') {
            return $this->error('导入文件不能为空');
        }
        $binary = base64_decode($content, true);
        if ($binary === false || $binary === '') {
            return $this->error('导入文件内容不正确');
        }

        try {
            $rows = $this->parseRefundImportRowsFromXls($binary);
        } catch (\Exception $e) {
            return $this->error('解析案款退付登记失败：' . $e->getMessage());
        }
        if (empty($rows)) {
            return $this->error('导入文件没有案款退付数据');
        }

        $bizType = $this->currentAccountSetBizType();
        $errors = $this->validateRefundRows($rows, $bizType);
        if (!empty($errors)) {
            return $this->error($this->refundImportErrorMessage($errors, $bizType), $errors);
        }

        $fingerprints = [];
        foreach ($rows as $row) {
            $fingerprints[] = $row['source_fingerprint'];
        }
        $existing = [];
        if (!empty($fingerprints)) {
            $existingRows = $this->getdb(self::TABLE_REFUND)->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])->where('source_fingerprint', 'in', array_values(array_unique($fingerprints)))->select();
            foreach ($existingRows as $row) {
                $existing[$row['source_fingerprint']] = true;
            }
        }

        $created = 0;
        $skipped = 0;
        $seen = [];
        Db::startTrans();
        try {
            foreach ($rows as $row) {
                $fingerprint = $row['source_fingerprint'];
                if (isset($existing[$fingerprint]) || isset($seen[$fingerprint])) {
                    $skipped++;
                    $seen[$fingerprint] = true;
                    continue;
                }
                $seen[$fingerprint] = true;
                $insert = $row;
                $insert['refund_id'] = uuid();
                $insert['account_set_id'] = $this->accountSetId;
                $insert['source_file_name'] = $data['filename'] ?? '';
                $insert['voucher_status'] = 'UNGENERATED';
                $insert['voucher_id'] = null;
                $insert['voucher_no'] = null;
                $insert['voucher_period'] = null;
                $insert['voucher_generated_time'] = null;
                $insert['remark'] = $data['remark'] ?? '';
                $this->fillCreate($insert);
                $this->getdb(self::TABLE_REFUND)->insert($insert);
                $created++;
            }
            $this->logAudit('CASE_FUND_REFUND', 'IMPORT', 'IMPORT', null, [
                'filename' => $data['filename'] ?? '',
                'total' => count($rows),
                'created' => $created,
                'skipped' => $skipped,
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('保存案款退付登记失败：' . $e->getMessage());
        }

        return $this->ok([
            'total' => count($rows),
            'created' => $created,
            'skipped' => $skipped,
        ], '导入成功', count($rows));
    }

    protected function parsePaymentImportRowsFromXls($binary)
    {
        $cells = $this->parseBiffCells($this->readOleWorkbookStream($binary));
        if (empty($cells)) {
            throw new \Exception('文件中没有可识别的工作表数据');
        }
        $maxRow = -1;
        $maxCol = -1;
        foreach ($cells as $key => $value) {
            list($row, $col) = array_map('intval', explode(':', $key));
            $maxRow = max($maxRow, $row);
            $maxCol = max($maxCol, $col);
        }

        $headers = [];
        for ($col = 0; $col <= $maxCol; $col++) {
            $headers[$col] = $this->normalizeImportCellText($cells['0:' . $col] ?? '');
        }
        $required = [
            '案号', '确', '可', '业务类型', '缴费人', '当事人', '开票抬头', '金额',
            '登记类型', '前审案号', '缴费日期', '票据号码', '开票日期', '开票员',
            '收费方式', '收费员', '承办法官', '书记员', '承办部门', '收款账号',
            '银行流水号', '缴费单号', '内转支出票号', '是否提存撤销',
        ];
        $index = [];
        foreach ($required as $name) {
            $found = array_search($name, $headers, true);
            if ($found === false) {
                throw new \Exception('缺少表头：' . $name);
            }
            $index[$name] = $found;
        }

        $rows = [];
        for ($row = 1; $row <= $maxRow; $row++) {
            $raw = [];
            foreach ($index as $name => $col) {
                $raw[$name] = $this->normalizeImportCellText($cells[$row . ':' . $col] ?? '');
            }
            if ($this->isEmptyPaymentRawRow($raw)) {
                continue;
            }
            if ($this->isMalformedPaymentRawRow($raw)) {
                continue;
            }
            if ($this->isNonDetailPaymentRawRow($raw)) {
                continue;
            }
            $paymentTime = $this->normalizeDateTimeValue($raw['缴费日期']);
            $paymentDate = $paymentTime === '' ? '' : substr($paymentTime, 0, 10);
            $invoiceDate = $this->nullableDateValue($raw['开票日期']);
            $amount = $this->centsToDecimal($this->decimalToCents($raw['金额']));
            $period = $paymentDate === '' ? '' : substr($paymentDate, 0, 7);
            $parsed = [
                'fiscal_year' => $paymentDate === '' ? '' : substr($paymentDate, 0, 4),
                'period' => $period,
                'case_no' => $raw['案号'],
                'confirmed_flag' => $this->yesNoFlagFromText($raw['确']),
                'available_flag' => $this->yesNoFlagFromText($raw['可']),
                'business_type' => $raw['业务类型'],
                'payer_name' => $raw['缴费人'],
                'party_name' => $raw['当事人'],
                'invoice_title' => $raw['开票抬头'],
                'payment_amount' => $amount,
                'register_type' => $raw['登记类型'],
                'trial_case_no' => $raw['前审案号'],
                'payment_date' => $paymentDate,
                'payment_time' => $paymentTime,
                'receipt_no' => $raw['票据号码'],
                'invoice_date' => $invoiceDate,
                'invoice_operator' => $raw['开票员'],
                'payment_method' => $raw['收费方式'],
                'cashier_name' => $raw['收费员'],
                'judge_name' => $raw['承办法官'],
                'clerk_name' => $raw['书记员'],
                'department_name' => $raw['承办部门'],
                'bank_account_no' => $raw['收款账号'],
                'bank_serial_no' => $raw['银行流水号'],
                'payment_order_no' => $raw['缴费单号'],
                'internal_transfer_ticket_no' => $raw['内转支出票号'],
                'deposit_revoke_flag' => $this->yesNoFlagFromText($raw['是否提存撤销']),
                'source_row_no' => $row + 1,
                'source_raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE),
            ];
            $parsed['source_fingerprint'] = $this->paymentFingerprint($parsed);
            $rows[] = $parsed;
        }
        return $rows;
    }

    protected function parseRefundImportRowsFromXls($binary)
    {
        $cells = $this->parseBiffCells($this->readOleWorkbookStream($binary));
        if (empty($cells)) {
            throw new \Exception('文件中没有可识别的工作表数据');
        }
        $maxRow = -1;
        $maxCol = -1;
        foreach ($cells as $key => $value) {
            list($row, $col) = array_map('intval', explode(':', $key));
            $maxRow = max($maxRow, $row);
            $maxCol = max($maxCol, $col);
        }

        $headers = [];
        for ($col = 0; $col <= $maxCol; $col++) {
            $headers[$col] = $this->normalizeImportCellText($cells['0:' . $col] ?? '');
        }
        $required = [
            '案号', '经办人', '书记员', '票据号码', '开票日期', '出账日期',
            '来源票据号码', '来源票据开票日期', '出账单号', '出账状态', '出账种类',
            '诉讼地位', '当事人', '出账金额', '总出账金额', '收款人与当事人关系',
            '支付方式', '实际收款人', '身份证号/企业', '收款人银行户名', '收款账号',
            '开户银行', '银联号', '是否本行', '承办人情况说明', '申请人',
        ];
        $index = [];
        foreach ($required as $name) {
            $found = array_search($name, $headers, true);
            if ($found === false) {
                throw new \Exception('缺少表头：' . $name);
            }
            $index[$name] = $found;
        }

        $rows = [];
        for ($row = 1; $row <= $maxRow; $row++) {
            $raw = [];
            foreach ($index as $name => $col) {
                $raw[$name] = $this->normalizeImportCellText($cells[$row . ':' . $col] ?? '');
            }
            if ($this->isEmptyPaymentRawRow($raw)) {
                continue;
            }
            if ($this->isMalformedPaymentRawRow($raw)) {
                continue;
            }
            if ($this->isNonDetailRefundRawRow($raw)) {
                continue;
            }
            $refundDate = $this->normalizeDateValue($raw['出账日期']);
            $invoiceDate = $this->nullableDateValue($raw['开票日期']);
            $sourceReceiptDate = $this->nullableDateValue($raw['来源票据开票日期']);
            $refundAmount = $this->centsToDecimal($this->decimalToCents($raw['出账金额']));
            $totalRefundAmount = $this->centsToDecimal($this->decimalToCents($raw['总出账金额']));
            $period = $refundDate === '' ? '' : substr($refundDate, 0, 7);
            $parsed = [
                'fiscal_year' => $refundDate === '' ? '' : substr($refundDate, 0, 4),
                'period' => $period,
                'case_no' => $raw['案号'],
                'handler_name' => $raw['经办人'],
                'clerk_name' => $raw['书记员'],
                'receipt_no' => $raw['票据号码'],
                'invoice_date' => $invoiceDate,
                'refund_date' => $refundDate,
                'source_receipt_no' => $raw['来源票据号码'],
                'source_receipt_date' => $sourceReceiptDate,
                'out_order_no' => $raw['出账单号'],
                'out_status' => $raw['出账状态'],
                'out_type' => $raw['出账种类'],
                'litigation_position' => $raw['诉讼地位'],
                'party_name' => $raw['当事人'],
                'refund_amount' => $refundAmount,
                'total_refund_amount' => $totalRefundAmount,
                'payee_party_relation' => $raw['收款人与当事人关系'],
                'payment_method' => $raw['支付方式'],
                'actual_payee_name' => $raw['实际收款人'],
                'payee_identity_no' => $raw['身份证号/企业'],
                'payee_bank_account_name' => $raw['收款人银行户名'],
                'payee_bank_account_no' => $raw['收款账号'],
                'payee_bank_name' => $raw['开户银行'],
                'unionpay_no' => $raw['银联号'],
                'same_bank_flag' => $raw['是否本行'],
                'handler_note' => $raw['承办人情况说明'],
                'applicant_name' => $raw['申请人'],
                'source_row_no' => $row + 1,
                'source_raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE),
            ];
            $parsed['source_fingerprint'] = $this->refundFingerprint($parsed);
            $rows[] = $parsed;
        }
        return $rows;
    }

    protected function validatePaymentRows($rows, $bizType = '')
    {
        $errors = [];
        $allowedBusinessTypes = $this->allowedPaymentBusinessTypes($bizType);
        foreach ($rows as $row) {
            $prefix = '第' . $row['source_row_no'] . '行：';
            if ($row['case_no'] === '') {
                $errors[] = $prefix . '案号不能为空';
            }
            if ($row['business_type'] === '') {
                $errors[] = $prefix . '业务类型不能为空';
            } elseif (empty($allowedBusinessTypes)) {
                $errors[] = $prefix . '当前账套类型不支持案款缴费登记：' . $bizType;
            } elseif (!in_array($row['business_type'], $allowedBusinessTypes, true)) {
                $errors[] = $prefix . '当前账套不允许导入业务类型【' . $row['business_type'] . '】，允许类型：' . implode('、', $allowedBusinessTypes);
            }
            if ($row['payer_name'] === '' && $row['party_name'] === '') {
                $errors[] = $prefix . '缴费人和当事人不能同时为空';
            }
            if ($this->decimalToCents($row['payment_amount']) <= 0) {
                $errors[] = $prefix . '金额必须大于0';
            }
            if ($row['payment_date'] === '') {
                $errors[] = $prefix . '缴费日期不能为空或格式不正确';
            }
            if ($row['receipt_no'] === '' && $row['bank_serial_no'] === '' && $row['payment_order_no'] === '') {
                $errors[] = $prefix . '票据号码、银行流水号、缴费单号至少填写一项用于追溯';
            }
        }
        return $errors;
    }

    protected function validateRefundRows($rows, $bizType = '')
    {
        $errors = [];
        $allowedOutTypes = $this->allowedRefundOutTypes($bizType);
        foreach ($rows as $row) {
            $prefix = '第' . $row['source_row_no'] . '行：';
            if ($row['case_no'] === '') {
                $errors[] = $prefix . '案号不能为空';
            }
            if ($row['out_type'] === '') {
                $errors[] = $prefix . '出账种类不能为空';
            } elseif (empty($allowedOutTypes)) {
                $errors[] = $prefix . '当前账套类型不支持案款退付登记：' . $bizType;
            } elseif (!in_array($row['out_type'], $allowedOutTypes, true)) {
                $errors[] = $prefix . '当前账套不允许导入出账种类【' . $row['out_type'] . '】，允许类型：' . implode('、', $allowedOutTypes);
            }
            if ($row['party_name'] === '' && $row['actual_payee_name'] === '') {
                $errors[] = $prefix . '当事人和实际收款人不能同时为空';
            }
            if ($this->decimalToCents($row['refund_amount']) <= 0) {
                $errors[] = $prefix . '出账金额必须大于0';
            }
            if ($row['refund_date'] === '') {
                $errors[] = $prefix . '出账日期不能为空或格式不正确';
            }
            if ($row['receipt_no'] === '' && $row['source_receipt_no'] === '' && $row['out_order_no'] === '') {
                $errors[] = $prefix . '票据号码、来源票据号码、出账单号至少填写一项用于追溯';
            }
        }
        return $errors;
    }

    protected function currentAccountSetBizType()
    {
        try {
            $row = $this->getdb('fin_account_set')->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])->field('biz_type')->find();
            return $row['biz_type'] ?? '';
        } catch (\Exception $e) {
            return '';
        }
    }

    protected function allowedPaymentBusinessTypes($bizType)
    {
        $map = [
            'CASE_FUND' => ['执行、调解款', '执行、调节款'],
            'LITIGATION_FEE' => ['预收诉讼费', '诉讼费预收'],
        ];
        return $map[$bizType] ?? [];
    }

    protected function allowedRefundOutTypes($bizType)
    {
        $map = [
            'CASE_FUND' => ['执行、调解款发放'],
            'LITIGATION_FEE' => ['诉讼费退费'],
        ];
        return $map[$bizType] ?? [];
    }

    protected function paymentImportErrorMessage($errors, $bizType = '')
    {
        foreach ($errors as $error) {
            if (strpos($error, '当前账套不允许导入业务类型') !== false) {
                return '导入的不是' . $this->paymentDataLabelByBizType($bizType) . '数据';
            }
        }
        return '导入校验失败';
    }

    protected function refundImportErrorMessage($errors, $bizType = '')
    {
        foreach ($errors as $error) {
            if (strpos($error, '当前账套不允许导入出账种类') !== false) {
                return '导入的不是' . $this->paymentDataLabelByBizType($bizType) . '数据';
            }
        }
        return '导入校验失败';
    }

    protected function paymentDataLabelByBizType($bizType)
    {
        $map = [
            'CASE_FUND' => '案款',
            'LITIGATION_FEE' => '诉讼费',
        ];
        return $map[$bizType] ?? '当前账套';
    }

    protected function isEmptyPaymentRawRow($raw)
    {
        foreach ($raw as $value) {
            if ($value !== '') {
                return false;
            }
        }
        return true;
    }

    protected function isNonDetailPaymentRawRow($raw)
    {
        $caseNo = trim((string)($raw['案号'] ?? ''));
        $paymentDate = trim((string)($raw['缴费日期'] ?? ''));
        $receiptNo = trim((string)($raw['票据号码'] ?? ''));
        $bankSerialNo = trim((string)($raw['银行流水号'] ?? ''));
        return $caseNo === '' && $paymentDate === '' && $receiptNo === '' && $bankSerialNo === '';
    }

    protected function isNonDetailRefundRawRow($raw)
    {
        $caseNo = trim((string)($raw['案号'] ?? ''));
        $refundDate = trim((string)($raw['出账日期'] ?? ''));
        $sourceReceiptNo = trim((string)($raw['来源票据号码'] ?? ''));
        $outOrderNo = trim((string)($raw['出账单号'] ?? ''));
        return $caseNo === '' && $refundDate === '' && $sourceReceiptNo === '' && $outOrderNo === '';
    }

    protected function isMalformedPaymentRawRow($raw)
    {
        foreach ($raw as $value) {
            $text = (string)$value;
            if (strpos($text, "\0") !== false) {
                return true;
            }
        }
        return false;
    }

    protected function paymentFingerprint($row)
    {
        $parts = [
            $row['case_no'] ?? '',
            $row['payment_time'] ?? '',
            $row['payment_amount'] ?? '',
            $row['receipt_no'] ?? '',
            $row['bank_serial_no'] ?? '',
            $row['payment_order_no'] ?? '',
        ];
        return md5(implode('|', $parts));
    }

    protected function refundFingerprint($row)
    {
        $parts = [
            $row['case_no'] ?? '',
            $row['refund_date'] ?? '',
            $row['out_type'] ?? '',
            $row['refund_amount'] ?? '',
            $row['source_receipt_no'] ?? '',
            $row['out_order_no'] ?? '',
            $row['actual_payee_name'] ?? '',
            $row['payee_bank_account_no'] ?? '',
        ];
        return hash('sha256', implode('|', $parts));
    }

    protected function yesNoFlagFromText($text)
    {
        $text = trim((string)$text);
        if ($text === '') {
            return 0;
        }
        if (in_array($text, ['√', '是', 'Y', 'YES', '1', 'true', 'TRUE'], true)) {
            return 1;
        }
        return 0;
    }

    protected function normalizeDateTimeValue($value)
    {
        $text = $this->normalizeImportCellText($value);
        if ($text === '') {
            return '';
        }
        if (is_numeric($text) && (float)$text > 1000) {
            return date('Y-m-d H:i:s', ((float)$text - 25569) * 86400);
        }
        $text = str_replace('/', '-', $text);
        $timestamp = strtotime($text);
        if ($timestamp === false) {
            return '';
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    protected function normalizeDateValue($value)
    {
        $datetime = $this->normalizeDateTimeValue($value);
        return $datetime === '' ? '' : substr($datetime, 0, 10);
    }

    protected function nullableDateValue($value)
    {
        $date = $this->normalizeDateValue($value);
        return $date === '' ? null : $date;
    }

    protected function normalizeImportCellText($value)
    {
        if (is_float($value) || is_int($value)) {
            return floor((float)$value) == (float)$value ? (string)(int)$value : (string)$value;
        }
        $text = trim((string)$value);
        if (preg_match('/^\d+\.0$/', $text)) {
            return substr($text, 0, -2);
        }
        return $text;
    }

    protected function readOleWorkbookStream($binary)
    {
        if (substr($binary, 0, 8) !== hex2bin('d0cf11e0a1b11ae1')) {
            throw new \Exception('仅支持 Excel 97-2003 .xls 文件');
        }
        $sectorSize = 1 << $this->u16($binary, 30);
        $dirStart = $this->u32($binary, 48);
        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $sid = $this->u32($binary, 76 + $i * 4);
            if ($sid < 0xFFFFFFF0) {
                $fatSectors[] = $sid;
            }
        }
        $fat = [];
        foreach ($fatSectors as $sid) {
            $sector = $this->oleSector($binary, $sectorSize, $sid);
            for ($pos = 0; $pos + 4 <= strlen($sector); $pos += 4) {
                $fat[] = $this->u32($sector, $pos);
            }
        }
        $dirStream = $this->oleFatStream($binary, $sectorSize, $fat, $dirStart, 1048576);
        $workbook = null;
        for ($pos = 0; $pos + 128 <= strlen($dirStream); $pos += 128) {
            $entry = substr($dirStream, $pos, 128);
            $nameLen = $this->u16($entry, 64);
            if ($nameLen < 2) {
                continue;
            }
            $name = iconv('UTF-16LE', 'UTF-8//IGNORE', substr($entry, 0, $nameLen - 2));
            if ($name !== 'Workbook' && $name !== 'Book') {
                continue;
            }
            $start = $this->u32($entry, 116);
            $size = $this->u64($entry, 120);
            $workbook = $this->oleFatStream($binary, $sectorSize, $fat, $start, $size);
            break;
        }
        if ($workbook === null) {
            throw new \Exception('未找到 Workbook 数据流');
        }
        return $workbook;
    }

    protected function parseBiffCells($stream)
    {
        $records = [];
        $pos = 0;
        while ($pos + 4 <= strlen($stream)) {
            $type = $this->u16($stream, $pos);
            $length = $this->u16($stream, $pos + 2);
            $pos += 4;
            $records[] = [$type, substr($stream, $pos, $length)];
            $pos += $length;
        }
        $sst = [];
        for ($i = 0; $i < count($records); $i++) {
            if ($records[$i][0] !== 0x00FC) {
                continue;
            }
            $blob = $records[$i][1];
            for ($j = $i + 1; $j < count($records) && $records[$j][0] === 0x003C; $j++) {
                $blob .= $records[$j][1];
            }
            $unique = $this->u32($blob, 4);
            $offset = 8;
            for ($k = 0; $k < $unique; $k++) {
                list($text, $offset) = $this->readBiffString($blob, $offset);
                $sst[] = $text;
            }
            break;
        }

        $cells = [];
        foreach ($records as $record) {
            list($type, $data) = $record;
            if ($type === 0x00FD && strlen($data) >= 10) {
                $row = $this->u16($data, 0);
                $col = $this->u16($data, 2);
                $sstIndex = $this->u32($data, 6);
                $cells[$row . ':' . $col] = $sst[$sstIndex] ?? '';
            } elseif ($type === 0x0203 && strlen($data) >= 14) {
                $row = $this->u16($data, 0);
                $col = $this->u16($data, 2);
                $value = unpack('e', substr($data, 6, 8))[1];
                $cells[$row . ':' . $col] = $value;
            } elseif ($type === 0x027E && strlen($data) >= 10) {
                $row = $this->u16($data, 0);
                $col = $this->u16($data, 2);
                $cells[$row . ':' . $col] = $this->decodeRkNumber($this->u32($data, 6));
            } elseif ($type === 0x00BE && strlen($data) >= 6) {
                $row = $this->u16($data, 0);
                $firstCol = $this->u16($data, 2);
                $lastCol = $this->u16($data, strlen($data) - 2);
                $offset = 4;
                for ($col = $firstCol; $col <= $lastCol && $offset + 6 <= strlen($data); $col++) {
                    $cells[$row . ':' . $col] = $this->decodeRkNumber($this->u32($data, $offset + 2));
                    $offset += 6;
                }
            }
        }
        return $cells;
    }

    protected function readBiffString($data, $offset)
    {
        if ($offset + 3 > strlen($data)) {
            return ['', strlen($data)];
        }
        $charCount = $this->u16($data, $offset);
        $offset += 2;
        $flags = ord($data[$offset]);
        $offset++;
        $hasRichText = ($flags & 0x08) !== 0;
        $hasExt = ($flags & 0x04) !== 0;
        $isUtf16 = ($flags & 0x01) !== 0;
        $richCount = 0;
        $extLength = 0;
        if ($hasRichText) {
            $richCount = $this->u16($data, $offset);
            $offset += 2;
        }
        if ($hasExt) {
            $extLength = $this->u32($data, $offset);
            $offset += 4;
        }
        $byteLength = $charCount * ($isUtf16 ? 2 : 1);
        $raw = substr($data, $offset, $byteLength);
        $offset += $byteLength;
        $text = $isUtf16 ? iconv('UTF-16LE', 'UTF-8//IGNORE', $raw) : $raw;
        $offset += $richCount * 4 + $extLength;
        return [$text, $offset];
    }

    protected function u16($data, $offset)
    {
        return unpack('v', substr($data, $offset, 2))[1];
    }

    protected function u32($data, $offset)
    {
        return unpack('V', substr($data, $offset, 4))[1];
    }

    protected function u64($data, $offset)
    {
        $low = $this->u32($data, $offset);
        $high = $this->u32($data, $offset + 4);
        return $high * 4294967296 + $low;
    }

    protected function oleSector($binary, $sectorSize, $sid)
    {
        $offset = 512 + $sid * $sectorSize;
        return substr($binary, $offset, $sectorSize);
    }

    protected function oleFatStream($binary, $sectorSize, $fat, $start, $size)
    {
        $parts = [];
        $seen = [];
        $sid = $start;
        while ($sid < 0xFFFFFFF0 && isset($fat[$sid]) && !isset($seen[$sid])) {
            $seen[$sid] = true;
            $parts[] = $this->oleSector($binary, $sectorSize, $sid);
            $sid = $fat[$sid];
        }
        return substr(implode('', $parts), 0, $size);
    }

    protected function decodeRkNumber($rk)
    {
        $isMultiplied = ($rk & 0x01) !== 0;
        $isInteger = ($rk & 0x02) !== 0;
        if ($isInteger) {
            $value = $rk >> 2;
            if ($value & 0x20000000) {
                $value -= 0x40000000;
            }
        } else {
            $value = unpack('e', pack('V2', 0, $rk & 0xFFFFFFFC))[1];
        }
        return $isMultiplied ? $value / 100 : $value;
    }
}
