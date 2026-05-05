<?php

namespace app\finance\model;

use think\Db;

class AccountSet extends Common
{
    const ACTION = 'accountSet';
    const TABLE = 'fin_account_set';

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'list':
                return $this->getList($data);
            case 'add':
                return $this->add($data);
            case 'edit':
                return $this->edit($data);
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function getList($data = [])
    {
        $auth = $this->requirePermission('system:account_set:view');
        if ($auth) {
            return $auth;
        }
        $rows = $this->getdb(self::TABLE)
            ->where(['del_flag' => 0])
            ->field('account_set_id,set_code,set_name,biz_type,enabled_year,enabled_period,finance_manager,paper_size,voucher_import_auto_no,voucher_print_line_count,status,remark')
            ->order('set_code asc')
            ->select();
        foreach ($rows as &$row) {
            $enabledPeriod = $this->normalizeEnabledPeriod($row['enabled_period'] ?? '', $row['enabled_year'] ?? '');
            $currentPeriod = $this->currentPeriodValue($enabledPeriod, $this->latestVoucherDate($row['account_set_id']));
            $row['enabled_period'] = $enabledPeriod;
            $row['enabled_period_label'] = $this->formatPeriodLabel($enabledPeriod);
            $row['current_period'] = $currentPeriod;
            $row['current_period_label'] = $this->formatPeriodLabel($currentPeriod);
            $row['is_current'] = $row['account_set_id'] === $this->accountSetId ? 1 : 0;
        }
        return $this->ok(['items' => $rows, 'total' => count($rows)], 'OK', count($rows));
    }

    public function add($data = [])
    {
        $auth = $this->requirePermission('system:account_set:add');
        if ($auth) {
            return $auth;
        }
        $validation = $this->validatePayload($data, true);
        if ($validation !== '') {
            return $this->error($validation);
        }
        $enabledPeriod = $data['enabled_period'];
        $enabledYear = (int)substr($enabledPeriod, 0, 4);
        $accountSetId = uuid();
        $row = [
            'account_set_id' => $accountSetId,
            'set_code' => $this->generateSetCode($data['biz_type'], $enabledPeriod),
            'set_name' => trim($data['set_name']),
            'biz_type' => $data['biz_type'],
            'enabled_year' => $enabledYear,
            'enabled_period' => $enabledPeriod,
            'finance_manager' => trim($data['finance_manager'] ?? ''),
            'paper_size' => $data['paper_size'] ?? 'A5',
            'voucher_import_auto_no' => (int)($data['voucher_import_auto_no'] ?? 1),
            'voucher_print_line_count' => (int)($data['voucher_print_line_count'] ?? 8),
            'status' => 1,
            'remark' => $data['remark'] ?? '',
        ];
        $this->fillCreate($row);

        Db::startTrans();
        try {
            $this->getdb(self::TABLE)->insert($row);
            $this->createFiscalPeriods($accountSetId, $enabledPeriod);
            $this->ensureYearTables($enabledYear);
            $this->grantCurrentUser($accountSetId);
            $this->logAudit('ACCOUNT_SET', $accountSetId, 'CREATE', null, $row);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('新增账套失败：' . $e->getMessage());
        }

        return $this->ok($accountSetId, '操作成功');
    }

    public function edit($data = [])
    {
        $auth = $this->requirePermission('system:account_set:edit');
        if ($auth) {
            return $auth;
        }
        $accountSetId = trim($data['account_set_id'] ?? '');
        if ($accountSetId === '') {
            return $this->error('账套ID不能为空');
        }
        $before = $this->getdb(self::TABLE)->where(['account_set_id' => $accountSetId, 'del_flag' => 0])->find();
        if (!$before) {
            return $this->error('账套不存在');
        }
        $validation = $this->validatePayload($data, false);
        if ($validation !== '') {
            return $this->error($validation);
        }
        $row = [
            'set_name' => trim($data['set_name']),
            'biz_type' => $data['biz_type'],
            'finance_manager' => trim($data['finance_manager'] ?? ''),
            'paper_size' => $data['paper_size'] ?? 'A5',
            'voucher_import_auto_no' => (int)($data['voucher_import_auto_no'] ?? 1),
            'voucher_print_line_count' => (int)($data['voucher_print_line_count'] ?? 8),
            'remark' => $data['remark'] ?? ($before['remark'] ?? ''),
        ];
        $this->fillUpdate($row);
        $this->getdb(self::TABLE)->where(['account_set_id' => $accountSetId, 'del_flag' => 0])->update($row);
        $this->logAudit('ACCOUNT_SET', $accountSetId, 'UPDATE', $before, $row);
        return $this->ok($accountSetId, '操作成功');
    }

    protected function validatePayload($data, $isCreate)
    {
        if (trim($data['set_name'] ?? '') === '') {
            return '单位名称不能为空';
        }
        if (trim($data['biz_type'] ?? '') === '') {
            return '业务类型不能为空';
        }
        if ($isCreate && !$this->isValidPeriod($data['enabled_period'] ?? '')) {
            return '账套启用年月格式不正确';
        }
        $paperSize = $data['paper_size'] ?? 'A5';
        if (!in_array($paperSize, ['A4', 'A5'], true)) {
            return '凭证纸张尺寸不正确';
        }
        $lineCount = (int)($data['voucher_print_line_count'] ?? 8);
        if ($lineCount <= 0) {
            return '凭证打印分录条数必须大于0';
        }
        return '';
    }

    protected function isValidPeriod($period)
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$period)) {
            return false;
        }
        return true;
    }

    protected function normalizeEnabledPeriod($enabledPeriod, $enabledYear)
    {
        if ($this->isValidPeriod($enabledPeriod)) {
            return $enabledPeriod;
        }
        return ((string)$enabledYear) . '-01';
    }

    protected function formatPeriodLabel($period)
    {
        if (!$this->isValidPeriod($period)) {
            return '';
        }
        return substr($period, 0, 4) . '年' . substr($period, 5, 2) . '月';
    }

    protected function currentPeriodValue($enabledPeriod, $latestVoucherDate)
    {
        if (!empty($latestVoucherDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $latestVoucherDate)) {
            return substr($latestVoucherDate, 0, 7);
        }
        return $enabledPeriod;
    }

    protected function generateSetCode($bizType, $enabledPeriod)
    {
        $shortId = strtoupper(substr(str_replace('-', '', uuid()), 0, 8));
        return strtoupper($bizType) . '_' . str_replace('-', '', $enabledPeriod) . '_' . $shortId;
    }

    protected function latestVoucherDate($accountSetId)
    {
        $latest = '';
        foreach ($this->voucherHeaderTables() as $table) {
            try {
                $date = $this->getdb($table)->where([
                    'account_set_id' => $accountSetId,
                    'del_flag' => 0,
                ])->max('voucher_date');
                if (!empty($date) && ($latest === '' || $date > $latest)) {
                    $latest = $date;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        return $latest;
    }

    protected function voucherHeaderTables()
    {
        $rows = Db::query("show tables like 'fin_voucher\\_%'");
        $tables = [];
        foreach ($rows as $row) {
            $table = array_values($row)[0] ?? '';
            if (preg_match('/^fin_voucher_\d{4}$/', $table)) {
                $tables[] = $table;
            }
        }
        return $tables;
    }

    protected function createFiscalPeriods($accountSetId, $enabledPeriod)
    {
        $year = (int)substr($enabledPeriod, 0, 4);
        $startMonth = (int)substr($enabledPeriod, 5, 2);
        for ($month = $startMonth; $month <= 12; $month++) {
            $period = sprintf('%04d-%02d', $year, $month);
            $startDate = $period . '-01';
            $endDate = date('Y-m-t', strtotime($startDate));
            $row = [
                'period_id' => uuid(),
                'account_set_id' => $accountSetId,
                'period' => $period,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'OPEN',
            ];
            $this->fillCreate($row);
            $this->getdb('fin_fiscal_period')->where([
                'account_set_id' => $accountSetId,
                'period' => $period,
            ])->delete();
            $this->getdb('fin_fiscal_period')->insert($row);
        }
    }

    protected function grantCurrentUser($accountSetId)
    {
        if (empty($this->userid) || in_array($this->userid, ['system', 'anonymous'], true)) {
            return;
        }
        $exists = Db::name('fin_user_account_set')->where([
            'user_id' => $this->userid,
            'account_set_id' => $accountSetId,
        ])->count();
        if ((int)$exists === 0) {
            Db::name('fin_user_account_set')->insert([
                'user_id' => $this->userid,
                'account_set_id' => $accountSetId,
            ]);
        }
    }

    protected function ensureYearTables($year)
    {
        $year = (int)$year;
        $voucherTable = 'fin_voucher_' . $year;
        $detailTable = 'fin_voucher_detail_' . $year;
        $auxTable = 'fin_voucher_aux_value_' . $year;

        Db::execute("create table if not exists {$voucherTable} (
            voucher_id varchar(36) primary key,
            account_set_id varchar(36) not null,
            period varchar(7) not null,
            voucher_date date not null,
            voucher_word varchar(10) not null default '记',
            voucher_no int not null,
            summary varchar(500),
            debit_amount decimal(18,2) not null default 0,
            credit_amount decimal(18,2) not null default 0,
            attachment_count int not null default 0,
            status varchar(20) not null,
            source_type varchar(50),
            printed_flag varchar(1) not null default '0',
            prepared_by varchar(36),
            prepared_time datetime,
            audit_by varchar(36),
            audit_time datetime,
            posted_by varchar(36),
            posted_time datetime,
            void_flag varchar(1) not null default '0',
            created_by varchar(36),
            created_time datetime,
            updated_by varchar(36),
            updated_time datetime,
            del_flag int not null default 0,
            version int not null default 0,
            remark varchar(500),
            unique key uk_voucher_no (account_set_id, period, voucher_no),
            key idx_voucher_date (account_set_id, voucher_date)
        )");

        Db::execute("create table if not exists {$detailTable} (
            detail_id varchar(36) primary key,
            account_set_id varchar(36) not null,
            voucher_id varchar(36) not null,
            line_no int not null,
            subject_code varchar(50) not null,
            summary varchar(500),
            debit_amount decimal(18,2) not null default 0,
            credit_amount decimal(18,2) not null default 0,
            verification_status varchar(30) not null,
            aux_desc varchar(1000),
            created_by varchar(36),
            created_time datetime,
            updated_by varchar(36),
            updated_time datetime,
            del_flag int not null default 0,
            version int not null default 0,
            remark varchar(500),
            key idx_voucher_detail_voucher (account_set_id, voucher_id),
            key idx_voucher_detail_subject (account_set_id, subject_code)
        )");

        Db::execute("create table if not exists {$auxTable} (
            id varchar(36) primary key,
            account_set_id varchar(36) not null,
            voucher_id varchar(36) not null,
            detail_id varchar(36) not null,
            aux_type_code varchar(50) not null,
            aux_value varchar(200) not null,
            aux_label varchar(200),
            created_by varchar(36),
            created_time datetime,
            updated_by varchar(36),
            updated_time datetime,
            del_flag int not null default 0,
            version int not null default 0,
            remark varchar(500),
            key idx_voucher_aux_detail (account_set_id, detail_id),
            key idx_voucher_aux_value (account_set_id, aux_type_code, aux_value)
        )");
    }
}
