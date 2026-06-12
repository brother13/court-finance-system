set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_account_set add column generate_payment_voucher_by_method_flag tinyint not null default 0 after generate_voucher_by_day_flag',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_account_set'
      and column_name = 'generate_payment_voucher_by_method_flag'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;
