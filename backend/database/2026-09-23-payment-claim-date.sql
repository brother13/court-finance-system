-- 缴费登记认领日期与制证日期。历史数据沿用原缴费日期，不改原交易时间。
set @sql = (
    select if(count(*) = 0,
        'alter table fin_case_fund_payment add column claim_date date null after payment_time',
        'select 1')
    from information_schema.columns
    where table_schema = database() and table_name = 'fin_case_fund_payment' and column_name = 'claim_date'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(count(*) = 0,
        'alter table fin_case_fund_payment add column posting_date date null after claim_date',
        'select 1')
    from information_schema.columns
    where table_schema = database() and table_name = 'fin_case_fund_payment' and column_name = 'posting_date'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

update fin_case_fund_payment
set posting_date = payment_date
where posting_date is null;

set @sql = (
    select if(count(*) = 1,
        'alter table fin_case_fund_payment modify column posting_date date not null',
        'select 1')
    from information_schema.columns
    where table_schema = database() and table_name = 'fin_case_fund_payment'
      and column_name = 'posting_date' and is_nullable = 'YES'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(count(*) = 0,
        'alter table fin_case_fund_payment add index idx_case_fund_payment_posting_date (account_set_id, fiscal_year, posting_date, del_flag)',
        'select 1')
    from information_schema.statistics
    where table_schema = database() and table_name = 'fin_case_fund_payment'
      and index_name = 'idx_case_fund_payment_posting_date'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;
