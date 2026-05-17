insert into fin_aux_type (
    aux_type_id, account_set_id, aux_type_code, aux_type_name, value_source,
    required_flag, status, created_by, created_time, updated_by, updated_time, del_flag, version, remark
) values
(uuid(), '00000000-0000-0000-0000-000000000101', 'customer', '客户', 'ARCHIVE', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '应收账款、预收账款、主营业务收入；按客户对账、回款、毛利'),
(uuid(), '00000000-0000-0000-0000-000000000101', 'supplier', '供应商', 'ARCHIVE', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '应付账款、预付账款、材料采购；按供应商对账、账期、采购'),
(uuid(), '00000000-0000-0000-0000-000000000101', 'department', '部门', 'ARCHIVE', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '管理费用、销售费用、制造费用；部门费用考核、部门利润'),
(uuid(), '00000000-0000-0000-0000-000000000101', 'employee', '职员', 'ARCHIVE', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '其他应收款（个人）、差旅费、工资；个人借款、费用报销、工资核算'),
(uuid(), '00000000-0000-0000-0000-000000000101', 'project', '项目', 'ARCHIVE', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '在建工程、工程施工、研发支出、主营业务成本；项目成本/收入/利润全周期'),
(uuid(), '00000000-0000-0000-0000-000000000101', 'custom', '自定义', 'ARCHIVE', 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '按需，如产品线、区域、合同；行业特殊维度')
on duplicate key update
    aux_type_name = values(aux_type_name),
    value_source = values(value_source),
    status = values(status),
    updated_by = values(updated_by),
    updated_time = values(updated_time),
    del_flag = values(del_flag),
    remark = values(remark);
