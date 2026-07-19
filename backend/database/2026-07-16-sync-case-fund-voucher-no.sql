-- 修复历史数据：案款业务表保存的凭证号/期间与当前有效凭证不一致。
-- 后续凭证号整理由 Voucher::syncGeneratedBusinessSourceVoucherNo() 在同一事务内实时同步。

update fin_case_fund_payment p
set p.voucher_no = (
        select v.voucher_no
        from fin_voucher v
        where v.account_set_id = p.account_set_id
          and v.voucher_id = p.voucher_id
          and v.del_flag = 0
    ),
    p.voucher_period = (
        select v.period
        from fin_voucher v
        where v.account_set_id = p.account_set_id
          and v.voucher_id = p.voucher_id
          and v.del_flag = 0
    ),
    p.updated_by = 'system',
    p.updated_time = current_timestamp
where p.del_flag = 0
  and p.voucher_status = 'GENERATED'
  and exists (
      select 1
      from fin_voucher v
      where v.account_set_id = p.account_set_id
        and v.voucher_id = p.voucher_id
        and v.del_flag = 0
        and (
            p.voucher_no is null
            or p.voucher_no <> v.voucher_no
            or p.voucher_period is null
            or p.voucher_period <> v.period
        )
  );

update fin_case_fund_refund r
set r.voucher_no = (
        select v.voucher_no
        from fin_voucher v
        where v.account_set_id = r.account_set_id
          and v.voucher_id = r.voucher_id
          and v.del_flag = 0
    ),
    r.voucher_period = (
        select v.period
        from fin_voucher v
        where v.account_set_id = r.account_set_id
          and v.voucher_id = r.voucher_id
          and v.del_flag = 0
    ),
    r.updated_by = 'system',
    r.updated_time = current_timestamp
where r.del_flag = 0
  and r.voucher_status = 'GENERATED'
  and exists (
      select 1
      from fin_voucher v
      where v.account_set_id = r.account_set_id
        and v.voucher_id = r.voucher_id
        and v.del_flag = 0
        and (
            r.voucher_no is null
            or r.voucher_no <> v.voucher_no
            or r.voucher_period is null
            or r.voucher_period <> v.period
        )
  );

update fin_case_fund_unknown_payment u
set u.voucher_no = (
        select v.voucher_no
        from fin_voucher v
        where v.account_set_id = u.account_set_id
          and v.voucher_id = u.voucher_id
          and v.del_flag = 0
    ),
    u.voucher_period = (
        select v.period
        from fin_voucher v
        where v.account_set_id = u.account_set_id
          and v.voucher_id = u.voucher_id
          and v.del_flag = 0
    ),
    u.updated_by = 'system',
    u.updated_time = current_timestamp
where u.del_flag = 0
  and u.voucher_status = 'GENERATED'
  and exists (
      select 1
      from fin_voucher v
      where v.account_set_id = u.account_set_id
        and v.voucher_id = u.voucher_id
        and v.del_flag = 0
        and (
            u.voucher_no is null
            or u.voucher_no <> v.voucher_no
            or u.voucher_period is null
            or u.voucher_period <> v.period
        )
  );

update fin_case_fund_distribution d
set d.voucher_no = (
        select v.voucher_no
        from fin_voucher v
        where v.account_set_id = d.account_set_id
          and v.voucher_id = d.voucher_id
          and v.del_flag = 0
    ),
    d.voucher_period = (
        select v.period
        from fin_voucher v
        where v.account_set_id = d.account_set_id
          and v.voucher_id = d.voucher_id
          and v.del_flag = 0
    ),
    d.updated_by = 'system',
    d.updated_time = current_timestamp
where d.del_flag = 0
  and d.voucher_status = 'GENERATED'
  and exists (
      select 1
      from fin_voucher v
      where v.account_set_id = d.account_set_id
        and v.voucher_id = d.voucher_id
        and v.del_flag = 0
        and (
            d.voucher_no is null
            or d.voucher_no <> v.voucher_no
            or d.voucher_period is null
            or d.voucher_period <> v.period
        )
  );
