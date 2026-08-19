insert into fin_account_set (
    account_set_id, set_code, set_name, biz_type, enabled_year, enabled_period, finance_manager, bank_account_name, bank_account_no, bank_name, bank_branch_name, paper_size, voucher_import_auto_no, voucher_print_line_count, generate_voucher_by_day_flag, generate_payment_voucher_by_method_flag, generate_refund_voucher_by_out_order_no_flag, skip_confirmed_return_voucher_flag, subject_code_rule, status,
    created_by, created_time, updated_by, updated_time, del_flag, version, remark
) values
('00000000-0000-0000-0000-000000000101', 'CASE_FUND_2026', '案款账套', 'CASE_FUND', 2026, '2026-01', '', '', '', '', '', 'A5', 1, 8, 1, 0, 0, 0, '4-2-2-2', 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '法院案款专项资金，独立账套'),
('00000000-0000-0000-0000-000000000102', 'LITIGATION_FEE_2026', '诉讼费账套', 'LITIGATION_FEE', 2026, '2026-01', '', '', '', '', '', 'A5', 1, 8, 1, 0, 0, 0, '4-2-2-2', 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '诉讼费收退费专项资金，独立账套')
on duplicate key update
    set_name = values(set_name),
    biz_type = values(biz_type),
    enabled_year = values(enabled_year),
    enabled_period = values(enabled_period),
    finance_manager = values(finance_manager),
    paper_size = values(paper_size),
    voucher_import_auto_no = values(voucher_import_auto_no),
    voucher_print_line_count = values(voucher_print_line_count),
    subject_code_rule = values(subject_code_rule),
    status = values(status),
    updated_by = values(updated_by),
    updated_time = values(updated_time),
    del_flag = values(del_flag),
    remark = values(remark);
