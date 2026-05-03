set @add_voucher_entry_flag = (
    select if(
        count(*) = 0,
        'alter table fin_subject add column voucher_entry_flag int not null default 1 after leaf_flag',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_subject'
      and column_name = 'voucher_entry_flag'
);
prepare stmt from @add_voucher_entry_flag;
execute stmt;
deallocate prepare stmt;

update fin_subject
set subject_type = 'EQUITY'
where subject_type = 'NET_ASSET';

update fin_subject
set subject_type = 'PROFIT_LOSS'
where subject_type in ('INCOME', 'EXPENSE');

update fin_subject
set voucher_entry_flag = case when leaf_flag = 1 then 1 else 0 end
where voucher_entry_flag is null
   or voucher_entry_flag not in (0, 1)
   or leaf_flag = 0;

create table if not exists fin_aux_opening_balance (
    balance_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    period varchar(7) not null,
    subject_code varchar(50) not null,
    aux_values_json text not null,
    aux_desc varchar(1000),
    debit_amount decimal(18,2) not null default 0,
    credit_amount decimal(18,2) not null default 0,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500),
    key idx_aux_opening_subject (account_set_id, period, subject_code),
    key idx_aux_opening_desc (account_set_id, period, aux_desc(255))
);

set @voucher_table = concat('fin_voucher_', year(curdate()));
set @sql = (
    select if(
        count(*) = 0,
        concat('alter table ', @voucher_table, ' add column voucher_word varchar(10) not null default ''记'' after voucher_date'),
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = @voucher_table
      and column_name = 'voucher_word'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        concat('alter table ', @voucher_table, ' add column attachment_count int not null default 0 after summary'),
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = @voucher_table
      and column_name = 'attachment_count'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        concat('alter table ', @voucher_table, ' add column prepared_by varchar(36) after printed_flag, add column prepared_time datetime after prepared_by, add column posted_by varchar(36) after audit_time, add column posted_time datetime after posted_by, add column void_flag varchar(1) not null default ''0'' after posted_time'),
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = @voucher_table
      and column_name = 'prepared_by'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;
