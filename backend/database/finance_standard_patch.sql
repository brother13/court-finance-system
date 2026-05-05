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

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_account_set add column enabled_period varchar(7) after enabled_year',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_account_set'
      and column_name = 'enabled_period'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_account_set add column finance_manager varchar(100) after enabled_period',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_account_set'
      and column_name = 'finance_manager'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_account_set add column paper_size varchar(20) not null default ''A5'' after finance_manager',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_account_set'
      and column_name = 'paper_size'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_account_set add column voucher_import_auto_no tinyint not null default 1 after paper_size',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_account_set'
      and column_name = 'voucher_import_auto_no'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_account_set add column voucher_print_line_count int not null default 8 after voucher_import_auto_no',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_account_set'
      and column_name = 'voucher_print_line_count'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_account_set add column subject_code_rule varchar(50) not null default ''4-2-2-2'' after voucher_print_line_count',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_account_set'
      and column_name = 'subject_code_rule'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

update fin_account_set
set enabled_period = concat(enabled_year, '-01')
where enabled_period is null
   or enabled_period = '';

update fin_account_set
set subject_code_rule = '4-2-2-2'
where subject_code_rule is null
   or subject_code_rule = '';

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
set @voucher_detail_table = concat('fin_voucher_detail_', year(curdate()));
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
        concat('alter table ', @voucher_table, ' add column debit_amount decimal(18,2) not null default 0 after summary'),
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = @voucher_table
      and column_name = 'debit_amount'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = concat(
    'update ', @voucher_table, ' v ',
    'set debit_amount = (',
        'select coalesce(sum(d.debit_amount), 0) from ', @voucher_detail_table, ' d ',
        'where d.account_set_id = v.account_set_id and d.voucher_id = v.voucher_id and d.del_flag = 0',
    '), credit_amount = (',
        'select coalesce(sum(d.credit_amount), 0) from ', @voucher_detail_table, ' d ',
        'where d.account_set_id = v.account_set_id and d.voucher_id = v.voucher_id and d.del_flag = 0',
    ') where v.del_flag = 0'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        concat('alter table ', @voucher_table, ' add column credit_amount decimal(18,2) not null default 0 after debit_amount'),
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = @voucher_table
      and column_name = 'credit_amount'
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

create table if not exists fin_case_fund_payment (
    payment_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    fiscal_year int not null,
    period varchar(7) not null,
    case_no varchar(100) not null,
    confirmed_flag tinyint not null default 0,
    available_flag tinyint not null default 0,
    business_type varchar(100) not null,
    payer_name varchar(200),
    party_name varchar(200),
    invoice_title varchar(200),
    payment_amount decimal(18,2) not null default 0,
    register_type varchar(100),
    trial_case_no varchar(100),
    payment_date date not null,
    payment_time datetime,
    receipt_no varchar(100),
    invoice_date date,
    invoice_operator varchar(100),
    payment_method varchar(100),
    cashier_name varchar(100),
    judge_name varchar(100),
    clerk_name varchar(100),
    department_name varchar(100),
    bank_account_no varchar(100),
    bank_serial_no varchar(100),
    payment_order_no varchar(100),
    internal_transfer_ticket_no varchar(100),
    deposit_revoke_flag tinyint not null default 0,
    source_file_name varchar(255),
    source_row_no int,
    source_fingerprint char(32) not null,
    source_raw_json text,
    voucher_status varchar(30) not null default 'UNGENERATED',
    voucher_id varchar(36),
    voucher_no int,
    voucher_period varchar(7),
    voucher_generated_time datetime,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500),
    unique key uk_case_fund_payment_source (account_set_id, source_fingerprint),
    key idx_case_fund_payment_period (account_set_id, period, payment_date),
    key idx_case_fund_payment_case (account_set_id, case_no),
    key idx_case_fund_payment_receipt (account_set_id, receipt_no),
    key idx_case_fund_payment_bank_serial (account_set_id, bank_serial_no),
    key idx_case_fund_payment_voucher (account_set_id, voucher_status, voucher_period, voucher_id)
);
