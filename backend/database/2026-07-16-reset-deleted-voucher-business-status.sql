-- 修复历史数据：业务记录仍为“已制证”，但关联凭证已删除或不存在。
-- 后续凭证删除由 Voucher::resetGeneratedBusinessSources() 在同一事务内实时回写。

update fin_case_fund_payment p
set p.voucher_status = 'UNGENERATED',
    p.voucher_id = null,
    p.voucher_no = null,
    p.voucher_period = null,
    p.voucher_generated_time = null,
    p.updated_by = 'system',
    p.updated_time = current_timestamp
where p.del_flag = 0
  and p.voucher_status = 'GENERATED'
  and p.voucher_id is not null
  and not exists (
      select 1
      from fin_voucher v
      where v.account_set_id = p.account_set_id
        and v.voucher_id = p.voucher_id
        and v.del_flag = 0
  );

update fin_case_fund_refund r
set r.voucher_status = 'UNGENERATED',
    r.voucher_id = null,
    r.voucher_no = null,
    r.voucher_period = null,
    r.voucher_generated_time = null,
    r.updated_by = 'system',
    r.updated_time = current_timestamp
where r.del_flag = 0
  and r.voucher_status = 'GENERATED'
  and r.voucher_id is not null
  and not exists (
      select 1
      from fin_voucher v
      where v.account_set_id = r.account_set_id
        and v.voucher_id = r.voucher_id
        and v.del_flag = 0
  );

update fin_case_fund_unknown_payment u
set u.voucher_status = 'UNGENERATED',
    u.voucher_id = null,
    u.voucher_no = null,
    u.voucher_period = null,
    u.voucher_generated_time = null,
    u.updated_by = 'system',
    u.updated_time = current_timestamp
where u.del_flag = 0
  and u.voucher_status = 'GENERATED'
  and u.voucher_id is not null
  and not exists (
      select 1
      from fin_voucher v
      where v.account_set_id = u.account_set_id
        and v.voucher_id = u.voucher_id
        and v.del_flag = 0
  );

update fin_case_fund_distribution d
set d.voucher_status = 'UNGENERATED',
    d.voucher_id = null,
    d.voucher_no = null,
    d.voucher_period = null,
    d.voucher_generated_time = null,
    d.updated_by = 'system',
    d.updated_time = current_timestamp
where d.del_flag = 0
  and d.voucher_status = 'GENERATED'
  and d.voucher_id is not null
  and not exists (
      select 1
      from fin_voucher v
      where v.account_set_id = d.account_set_id
        and v.voucher_id = d.voucher_id
        and v.del_flag = 0
  );
