set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_account_set add column bank_account_name varchar(200) not null default '''' after finance_manager',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_account_set'
      and column_name = 'bank_account_name'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_account_set add column bank_account_no varchar(100) not null default '''' after bank_account_name',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_account_set'
      and column_name = 'bank_account_no'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_account_set add column bank_name varchar(160) not null default '''' after bank_account_no',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_account_set'
      and column_name = 'bank_name'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_account_set add column bank_branch_name varchar(200) not null default '''' after bank_name',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_account_set'
      and column_name = 'bank_branch_name'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'create index idx_account_set_bank_account on fin_account_set (bank_account_no)',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_account_set'
      and index_name = 'idx_account_set_bank_account'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;
