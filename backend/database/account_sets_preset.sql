insert into fin_account_set (
    account_set_id, set_code, set_name, biz_type, enabled_year, status,
    created_by, created_time, updated_by, updated_time, del_flag, version, remark
) values
('00000000-0000-0000-0000-000000000101', 'CASE_FUND_2026', '案款账套', 'CASE_FUND', 2026, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '法院案款专项资金，独立账套'),
('00000000-0000-0000-0000-000000000102', 'LITIGATION_FEE_2026', '诉讼费账套', 'LITIGATION_FEE', 2026, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '诉讼费收退费专项资金，独立账套'),
('00000000-0000-0000-0000-000000000103', 'CANTEEN_2026', '食堂账', 'CANTEEN', 2026, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '食堂资金独立账套'),
('00000000-0000-0000-0000-000000000104', 'UNION_2026', '工会账', 'UNION', 2026, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '工会经费独立账套')
on duplicate key update
    set_name = values(set_name),
    biz_type = values(biz_type),
    enabled_year = values(enabled_year),
    status = values(status),
    updated_by = values(updated_by),
    updated_time = values(updated_time),
    del_flag = values(del_flag),
    remark = values(remark);
