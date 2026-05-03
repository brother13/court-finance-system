insert into fin_account_set (
    account_set_id, set_code, set_name, biz_type, enabled_year, status,
    created_by, created_time, updated_by, updated_time, del_flag, version, remark
) values (
    '00000000-0000-0000-0000-000000000101',
    'CASE_FUND_2026',
    '案款账套',
    'CASE_FUND',
    2026,
    1,
    'system', current_timestamp, 'system', current_timestamp, 0, 0, '一期预置模板'
);

insert into fin_fiscal_period (
    period_id, account_set_id, period, start_date, end_date, status,
    created_by, created_time, updated_by, updated_time, del_flag, version
) values
('00000000-0000-0000-0000-000000010001', '00000000-0000-0000-0000-000000000101', '2026-01', '2026-01-01', '2026-01-31', 'OPEN', 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000010002', '00000000-0000-0000-0000-000000000101', '2026-02', '2026-02-01', '2026-02-28', 'OPEN', 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000010003', '00000000-0000-0000-0000-000000000101', '2026-03', '2026-03-01', '2026-03-31', 'OPEN', 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000010004', '00000000-0000-0000-0000-000000000101', '2026-04', '2026-04-01', '2026-04-30', 'OPEN', 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000010005', '00000000-0000-0000-0000-000000000101', '2026-05', '2026-05-01', '2026-05-31', 'OPEN', 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000010006', '00000000-0000-0000-0000-000000000101', '2026-06', '2026-06-01', '2026-06-30', 'OPEN', 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000010007', '00000000-0000-0000-0000-000000000101', '2026-07', '2026-07-01', '2026-07-31', 'OPEN', 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000010008', '00000000-0000-0000-0000-000000000101', '2026-08', '2026-08-01', '2026-08-31', 'OPEN', 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000010009', '00000000-0000-0000-0000-000000000101', '2026-09', '2026-09-01', '2026-09-30', 'OPEN', 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000010010', '00000000-0000-0000-0000-000000000101', '2026-10', '2026-10-01', '2026-10-31', 'OPEN', 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000010011', '00000000-0000-0000-0000-000000000101', '2026-11', '2026-11-01', '2026-11-30', 'OPEN', 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000010012', '00000000-0000-0000-0000-000000000101', '2026-12', '2026-12-01', '2026-12-31', 'OPEN', 'system', current_timestamp, 'system', current_timestamp, 0, 0);

insert into fin_subject (
    subject_id, account_set_id, subject_code, subject_name, parent_code,
    direction, subject_type, level_no, leaf_flag, status,
    created_by, created_time, updated_by, updated_time, del_flag, version
) values
('00000000-0000-0000-0000-000000020001', '00000000-0000-0000-0000-000000000101', '1002', '银行存款', null, 'DEBIT', 'ASSET', 1, 1, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000020002', '00000000-0000-0000-0000-000000000101', '2201', '其他应付款', null, 'CREDIT', 'LIABILITY', 1, 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000020003', '00000000-0000-0000-0000-000000000101', '220101', '案款暂存', '2201', 'CREDIT', 'LIABILITY', 2, 1, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000020004', '00000000-0000-0000-0000-000000000101', '6601', '业务支出', null, 'DEBIT', 'EXPENSE', 1, 1, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0);

insert into fin_aux_type (
    aux_type_id, account_set_id, aux_type_code, aux_type_name, value_source,
    required_flag, status, created_by, created_time, updated_by, updated_time, del_flag, version
) values
('00000000-0000-0000-0000-000000030001', '00000000-0000-0000-0000-000000000101', 'case_no', '案号', 'MANUAL', 1, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000030002', '00000000-0000-0000-0000-000000000101', 'receipt_no', '收据号', 'MANUAL', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000030003', '00000000-0000-0000-0000-000000000101', 'party_name', '当事人', 'ARCHIVE', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000030004', '00000000-0000-0000-0000-000000000101', 'supplier_id', '供应商', 'ARCHIVE', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0);

insert into fin_aux_type (
    aux_type_id, account_set_id, aux_type_code, aux_type_name, value_source,
    required_flag, status, created_by, created_time, updated_by, updated_time, del_flag, version, remark
) values
('00000000-0000-0000-0000-000000030101', '00000000-0000-0000-0000-000000000101', 'customer', '客户', 'ARCHIVE', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '应收账款、预收账款、主营业务收入；按客户对账、回款、毛利'),
('00000000-0000-0000-0000-000000030102', '00000000-0000-0000-0000-000000000101', 'supplier', '供应商', 'ARCHIVE', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '应付账款、预付账款、材料采购；按供应商对账、账期、采购'),
('00000000-0000-0000-0000-000000030103', '00000000-0000-0000-0000-000000000101', 'department', '部门', 'ARCHIVE', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '管理费用、销售费用、制造费用；部门费用考核、部门利润'),
('00000000-0000-0000-0000-000000030104', '00000000-0000-0000-0000-000000000101', 'employee', '职员', 'ARCHIVE', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '其他应收款（个人）、差旅费、工资；个人借款、费用报销、工资核算'),
('00000000-0000-0000-0000-000000030105', '00000000-0000-0000-0000-000000000101', 'project', '项目', 'ARCHIVE', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '在建工程、工程施工、研发支出、主营业务成本；项目成本/收入/利润全周期'),
('00000000-0000-0000-0000-000000030106', '00000000-0000-0000-0000-000000000101', 'custom', '自定义', 'ARCHIVE', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '按需，如产品线、区域、合同；行业特殊维度');

insert into fin_subject_aux_config (
    config_id, account_set_id, subject_code, aux_type_code, required_flag,
    verification_flag, created_by, created_time, updated_by, updated_time, del_flag, version
) values
('00000000-0000-0000-0000-000000040001', '00000000-0000-0000-0000-000000000101', '220101', 'case_no', 1, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000040002', '00000000-0000-0000-0000-000000000101', '220101', 'receipt_no', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0),
('00000000-0000-0000-0000-000000040003', '00000000-0000-0000-0000-000000000101', '220101', 'party_name', 0, 0, 'system', current_timestamp, 'system', current_timestamp, 0, 0);
