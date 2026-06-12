create table if not exists fin_aux_balance_snapshot (
    snapshot_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    fiscal_year int not null,
    period varchar(7) not null,
    subject_code varchar(50) not null,
    subject_name varchar(200) not null default '',
    direction varchar(20) not null default 'DEBIT',
    case_no varchar(200) not null,
    receipt_no varchar(200) not null default '',
    opening_debit_amount decimal(18,2) not null default 0,
    opening_credit_amount decimal(18,2) not null default 0,
    opening_balance_amount decimal(18,2) not null default 0,
    debit_amount decimal(18,2) not null default 0,
    credit_amount decimal(18,2) not null default 0,
    ending_balance_amount decimal(18,2) not null default 0,
    entry_count int not null default 0,
    monitor_flag tinyint not null default 0,
    source_updated_time datetime,
    snapshot_time datetime not null,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500)
);

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_aux_balance_snapshot add unique index uk_aux_balance_snapshot (account_set_id, fiscal_year, period, subject_code, case_no, receipt_no)',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_aux_balance_snapshot'
      and index_name = 'uk_aux_balance_snapshot'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_aux_balance_snapshot add index idx_aux_balance_snapshot_period (account_set_id, fiscal_year, period, del_flag)',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_aux_balance_snapshot'
      and index_name = 'idx_aux_balance_snapshot_period'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_aux_balance_snapshot add index idx_aux_balance_snapshot_subject (account_set_id, fiscal_year, period, subject_code, del_flag)',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_aux_balance_snapshot'
      and index_name = 'idx_aux_balance_snapshot_subject'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_aux_balance_snapshot add index idx_aux_balance_snapshot_case (account_set_id, fiscal_year, period, case_no, del_flag)',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_aux_balance_snapshot'
      and index_name = 'idx_aux_balance_snapshot_case'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_aux_balance_snapshot add index idx_aux_balance_snapshot_receipt (account_set_id, fiscal_year, period, receipt_no, del_flag)',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_aux_balance_snapshot'
      and index_name = 'idx_aux_balance_snapshot_receipt'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_aux_balance_snapshot add index idx_aux_balance_snapshot_monitor (account_set_id, fiscal_year, period, monitor_flag, del_flag)',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_aux_balance_snapshot'
      and index_name = 'idx_aux_balance_snapshot_monitor'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;
