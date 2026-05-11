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
        count(*) > 0,
        'alter table sys_audit_log modify column before_json longtext, modify column after_json longtext',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'sys_audit_log'
      and column_name in ('before_json', 'after_json')
      and data_type <> 'longtext'
);
prepare stmt from @sql;
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

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_account_set add column generate_voucher_by_day_flag tinyint not null default 1 after voucher_print_line_count',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_account_set'
      and column_name = 'generate_voucher_by_day_flag'
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

create table if not exists fin_voucher (
    voucher_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    fiscal_year int not null,
    period varchar(7) not null,
    voucher_date date not null,
    voucher_word varchar(10) not null default '记',
    voucher_no int not null,
    summary varchar(500),
    debit_amount decimal(18,2) not null default 0,
    credit_amount decimal(18,2) not null default 0,
    attachment_count int not null default 0,
    status varchar(20) not null,
    source_type varchar(50),
    printed_flag varchar(1) not null default '0',
    prepared_by varchar(36),
    prepared_time datetime,
    audit_by varchar(36),
    audit_time datetime,
    posted_by varchar(36),
    posted_time datetime,
    void_flag varchar(1) not null default '0',
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500),
    key idx_voucher_period (account_set_id, fiscal_year, period),
    unique key uk_voucher_no (account_set_id, fiscal_year, period, voucher_no),
    key idx_voucher_date (account_set_id, voucher_date),
    key idx_voucher_del (account_set_id, del_flag)
);

create table if not exists fin_voucher_detail (
    detail_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    fiscal_year int not null,
    period varchar(7) not null,
    voucher_id varchar(36) not null,
    line_no int not null,
    subject_code varchar(50) not null,
    summary varchar(500),
    debit_amount decimal(18,2) not null default 0,
    credit_amount decimal(18,2) not null default 0,
    verification_status varchar(30) not null,
    aux_desc varchar(1000),
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500),
    key idx_voucher_detail_period (account_set_id, fiscal_year, period),
    key idx_voucher_detail_voucher (account_set_id, voucher_id),
    key idx_voucher_detail_id (voucher_id, detail_id),
    key idx_voucher_detail_subject (account_set_id, subject_code),
    key idx_voucher_detail_del (account_set_id, del_flag)
);

create table if not exists fin_voucher_aux_value (
    id varchar(36) primary key,
    account_set_id varchar(36) not null,
    fiscal_year int not null,
    period varchar(7) not null,
    voucher_id varchar(36) not null,
    detail_id varchar(36) not null,
    aux_type_code varchar(50) not null,
    aux_value varchar(200) not null,
    aux_label varchar(200),
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500),
    key idx_voucher_aux_period (account_set_id, fiscal_year, period),
    key idx_voucher_aux_voucher (account_set_id, voucher_id),
    key idx_voucher_aux_detail (account_set_id, detail_id),
    key idx_voucher_aux_detail_id (voucher_id, detail_id),
    key idx_voucher_aux_value (account_set_id, aux_type_code, aux_value),
    key idx_voucher_aux_del (account_set_id, del_flag)
);

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_voucher_no_sequence add column fiscal_year int not null default 0 after account_set_id',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_voucher_no_sequence'
      and column_name = 'fiscal_year'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

update fin_voucher_no_sequence
set fiscal_year = cast(substr(period, 1, 4) as unsigned)
where fiscal_year = 0
  and period is not null
  and length(period) >= 4;

-- Historical yearly tables such as fin_voucher_2026, fin_voucher_detail_2026
-- and fin_voucher_aux_value_2026 are migrated by backend/database/migrate_voucher_year_tables.php.

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

create table if not exists fin_case_fund_refund (
    refund_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    fiscal_year int not null,
    period varchar(7) not null,
    case_no varchar(100) not null,
    handler_name varchar(100),
    clerk_name varchar(100),
    receipt_no varchar(100),
    invoice_date date,
    refund_date date not null,
    source_receipt_no varchar(100),
    source_receipt_date date,
    out_order_no varchar(100),
    out_status varchar(100),
    out_type varchar(100) not null,
    litigation_position varchar(100),
    party_name varchar(200),
    refund_amount decimal(18,2) not null default 0,
    total_refund_amount decimal(18,2) not null default 0,
    payee_party_relation varchar(100),
    payment_method varchar(100),
    actual_payee_name varchar(200),
    payee_identity_no varchar(100),
    payee_bank_account_name varchar(200),
    payee_bank_account_no varchar(100),
    payee_bank_name varchar(255),
    unionpay_no varchar(100),
    same_bank_flag varchar(30),
    handler_note varchar(500),
    applicant_name varchar(100),
    source_file_name varchar(255),
    source_row_no int,
    source_fingerprint char(64) not null,
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
    unique key uk_refund_fingerprint (account_set_id, source_fingerprint, del_flag),
    key idx_case_fund_refund_period (account_set_id, period, refund_date),
    key idx_case_fund_refund_date (account_set_id, refund_date, del_flag),
    key idx_case_fund_refund_out_type (account_set_id, out_type, del_flag),
    key idx_case_fund_refund_case (account_set_id, case_no),
    key idx_case_fund_refund_source_receipt (account_set_id, source_receipt_no),
    key idx_case_fund_refund_voucher (account_set_id, voucher_status, voucher_period, voucher_id)
);

create table if not exists fin_case_fund_bank_statement (
    statement_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    fiscal_year int not null,
    period varchar(7) not null,
    bank_code varchar(50) not null,
    bank_name varchar(100) not null,
    transaction_date date not null,
    transaction_time datetime not null,
    direction varchar(10) not null,
    debit_amount decimal(18,2) not null default 0,
    credit_amount decimal(18,2) not null default 0,
    balance_amount decimal(18,2) not null default 0,
    counterparty_account_no varchar(100),
    counterparty_account_name varchar(200),
    counterparty_bank_name varchar(255),
    purpose varchar(500),
    postscript varchar(500),
    bank_serial_no varchar(100),
    reconcile_status varchar(30) not null default 'UNMATCHED',
    source_file_name varchar(255),
    source_row_no int,
    source_fingerprint char(32) not null,
    source_raw_json longtext,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500),
    unique key uk_case_fund_bank_statement_source (account_set_id, source_fingerprint),
    key idx_case_fund_bank_statement_period (account_set_id, period, transaction_date),
    key idx_case_fund_bank_statement_bank (account_set_id, bank_code, transaction_date),
    key idx_case_fund_bank_statement_serial (account_set_id, bank_serial_no),
    key idx_case_fund_bank_statement_reconcile (account_set_id, reconcile_status, transaction_date)
);

create table if not exists fin_case_fund_bank_reconcile (
    reconcile_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    fiscal_year int not null,
    period varchar(7) not null,
    reconcile_date date not null,
    statement_id varchar(36),
    biz_type varchar(30),
    biz_id varchar(36),
    biz_no varchar(100),
    bank_serial_no varchar(100),
    bank_amount decimal(18,2) not null default 0,
    biz_amount decimal(18,2) not null default 0,
    diff_amount decimal(18,2) not null default 0,
    match_status varchar(30) not null,
    match_rule varchar(50) not null,
    matched_by varchar(36),
    matched_time datetime,
    bank_direction varchar(10),
    bank_summary varchar(1000),
    biz_summary varchar(1000),
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500),
    key idx_case_fund_bank_reconcile_date (account_set_id, fiscal_year, reconcile_date),
    key idx_case_fund_bank_reconcile_status (account_set_id, match_status, reconcile_date),
    key idx_case_fund_bank_reconcile_statement (account_set_id, statement_id),
    key idx_case_fund_bank_reconcile_biz (account_set_id, biz_type, biz_id),
    key idx_case_fund_bank_reconcile_serial (account_set_id, bank_serial_no)
);

create table if not exists fin_case_fund_subject_config (
    config_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    biz_type varchar(50) not null,
    voucher_biz_type varchar(30) not null,
    business_item_type varchar(100) not null,
    debit_subject_code varchar(50) not null,
    credit_subject_code varchar(50) not null,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500),
    unique key uk_case_fund_subject_config (account_set_id, biz_type, voucher_biz_type, business_item_type),
    key idx_case_fund_subject_config_subject (account_set_id, debit_subject_code, credit_subject_code)
);

insert into fin_subject (
    subject_id, account_set_id, subject_code, subject_name, parent_code,
    direction, subject_type, level_no, leaf_flag, voucher_entry_flag, status,
    created_by, created_time, updated_by, updated_time, del_flag, version, remark
) values
('00000000-0000-0000-0000-000000020201', '00000000-0000-0000-0000-000000000102', '1002', '银行存款', null, 'DEBIT', 'ASSET', 1, 0, 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '诉讼费账套预置科目'),
('00000000-0000-0000-0000-000000020202', '00000000-0000-0000-0000-000000000102', '100201', '诉讼费专户', '1002', 'DEBIT', 'ASSET', 2, 1, 1, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '诉讼费收退费银行科目'),
('00000000-0000-0000-0000-000000020203', '00000000-0000-0000-0000-000000000102', '2203', '预收账款', null, 'CREDIT', 'LIABILITY', 1, 0, 0, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '诉讼费账套预置科目'),
('00000000-0000-0000-0000-000000020204', '00000000-0000-0000-0000-000000000102', '220301', '预收诉讼费', '2203', 'CREDIT', 'LIABILITY', 2, 1, 1, 1, 'system', current_timestamp, 'system', current_timestamp, 0, 0, '诉讼费收退费负债科目')
on duplicate key update
    subject_name = values(subject_name),
    parent_code = values(parent_code),
    direction = values(direction),
    subject_type = values(subject_type),
    level_no = values(level_no),
    leaf_flag = values(leaf_flag),
    voucher_entry_flag = values(voucher_entry_flag),
    status = values(status),
    updated_by = values(updated_by),
    updated_time = values(updated_time),
    del_flag = values(del_flag),
    remark = values(remark);
