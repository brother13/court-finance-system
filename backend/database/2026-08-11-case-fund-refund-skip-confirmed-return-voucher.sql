-- 出账查询科目配置：已确认退汇的出账记录可配置为不生成凭证。
-- 默认关闭，保持升级前的凭证生成口径。

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_account_set add column skip_confirmed_return_voucher_flag tinyint not null default 0 after generate_refund_voucher_by_out_order_no_flag',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_account_set'
      and column_name = 'skip_confirmed_return_voucher_flag'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;
