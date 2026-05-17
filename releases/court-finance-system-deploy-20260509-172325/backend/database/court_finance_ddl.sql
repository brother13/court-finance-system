create table sys_audit_log (
    log_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    biz_type varchar(50) not null,
    biz_id varchar(64) not null,
    operation varchar(50) not null,
    before_json longtext,
    after_json longtext,
    operator_id varchar(36),
    operator_ip varchar(64),
    created_time datetime not null
);

create index idx_audit_set_time on sys_audit_log (account_set_id, created_time);

create table sys_unit (
    unit_id varchar(36) primary key,
    unit_code varchar(50) not null,
    unit_name varchar(100) not null,
    sort_no int not null default 0,
    status int not null default 1,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500)
);

create unique index uk_sys_unit_code on sys_unit (unit_code);

create table sys_user (
    user_id varchar(36) primary key,
    unit_id varchar(36) not null,
    username varchar(50) not null,
    password_hash varchar(255) not null,
    real_name varchar(100) not null,
    mobile varchar(30),
    email varchar(100),
    status int not null default 1,
    must_change_password tinyint default 1,
    last_login_time datetime,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500)
);

create unique index uk_sys_user_unit_name on sys_user (unit_id, username);
create index idx_sys_user_unit on sys_user (unit_id);

create table fin_permission (
    permission_code varchar(50) primary key,
    permission_name varchar(100) not null,
    permission_type tinyint not null,
    module_code varchar(50),
    description varchar(255),
    sort_order int default 0
);

create table fin_role (
    role_id varchar(36) primary key,
    role_code varchar(50) not null,
    role_name varchar(100) not null,
    description varchar(255),
    is_system tinyint default 0,
    view_scope varchar(20) default 'ALL',
    status tinyint default 1,
    created_at datetime,
    updated_at datetime
);

create unique index uk_fin_role_code on fin_role (role_code);

create table fin_role_permission (
    role_id varchar(36) not null,
    permission_code varchar(50) not null,
    primary key (role_id, permission_code)
);

create table fin_user_role (
    user_id varchar(36) not null,
    role_id varchar(36) not null,
    primary key (user_id, role_id)
);

create table fin_user_account_set (
    user_id varchar(36) not null,
    account_set_id varchar(36) not null,
    primary key (user_id, account_set_id)
);

create table fin_account_set (
    account_set_id varchar(36) primary key,
    set_code varchar(50) not null,
    set_name varchar(100) not null,
    biz_type varchar(50) not null,
    enabled_year int not null,
    enabled_period varchar(7),
    finance_manager varchar(100),
    paper_size varchar(20) not null default 'A5',
    voucher_import_auto_no tinyint not null default 1,
    voucher_print_line_count int not null default 8,
    generate_voucher_by_day_flag tinyint not null default 1,
    subject_code_rule varchar(50) not null default '4-2-2-2',
    status int not null,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500)
);

create unique index uk_account_set_code on fin_account_set (set_code);

create table fin_fiscal_period (
    period_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    period varchar(7) not null,
    start_date date not null,
    end_date date not null,
    status varchar(20) not null,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500)
);

create unique index uk_fiscal_period on fin_fiscal_period (account_set_id, period);

create table fin_subject (
    subject_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    subject_code varchar(50) not null,
    subject_name varchar(100) not null,
    parent_code varchar(50),
    direction varchar(20) not null,
    subject_type varchar(50) not null,
    level_no int,
    leaf_flag int not null,
    voucher_entry_flag int not null default 1,
    status int not null,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500)
);

create unique index uk_subject_code on fin_subject (account_set_id, subject_code);

create table fin_aux_type (
    aux_type_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    aux_type_code varchar(50) not null,
    aux_type_name varchar(100) not null,
    value_source varchar(50) not null,
    required_flag int not null,
    status int not null,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500)
);

create unique index uk_aux_type_code on fin_aux_type (account_set_id, aux_type_code);

create table fin_aux_archive (
    archive_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    aux_type_code varchar(50) not null,
    archive_code varchar(100) not null,
    archive_name varchar(200) not null,
    extra_json text,
    status int not null,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500)
);

create index idx_aux_archive_type_value on fin_aux_archive (account_set_id, aux_type_code, archive_code);

create table fin_subject_aux_config (
    config_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    subject_code varchar(50) not null,
    aux_type_code varchar(50) not null,
    required_flag int not null,
    verification_flag int not null,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500)
);

create unique index uk_subject_aux on fin_subject_aux_config (account_set_id, subject_code, aux_type_code);

create table fin_opening_balance (
    balance_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    period varchar(7) not null,
    subject_code varchar(50) not null,
    debit_amount decimal(18,2) not null default 0,
    credit_amount decimal(18,2) not null default 0,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500)
);

create unique index uk_opening_subject on fin_opening_balance (account_set_id, period, subject_code);

create table fin_aux_opening_balance (
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
    remark varchar(500)
);

create index idx_aux_opening_subject on fin_aux_opening_balance (account_set_id, period, subject_code);

create table fin_voucher_no_sequence (
    sequence_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    fiscal_year int not null,
    period varchar(7) not null,
    current_no int not null,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500)
);

create unique index uk_voucher_no_sequence on fin_voucher_no_sequence (account_set_id, fiscal_year, period);

create table fin_voucher (
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
    remark varchar(500)
);

create index idx_voucher_period on fin_voucher (account_set_id, fiscal_year, period);
create unique index uk_voucher_no on fin_voucher (account_set_id, fiscal_year, period, voucher_no);
create index idx_voucher_date on fin_voucher (account_set_id, voucher_date);
create index idx_voucher_del on fin_voucher (account_set_id, del_flag);

create table fin_voucher_detail (
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
    remark varchar(500)
);

create index idx_voucher_detail_period on fin_voucher_detail (account_set_id, fiscal_year, period);
create index idx_voucher_detail_voucher on fin_voucher_detail (account_set_id, voucher_id);
create index idx_voucher_detail_id on fin_voucher_detail (voucher_id, detail_id);
create index idx_voucher_detail_subject on fin_voucher_detail (account_set_id, subject_code);
create index idx_voucher_detail_del on fin_voucher_detail (account_set_id, del_flag);

create table fin_voucher_aux_value (
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
    remark varchar(500)
);

create index idx_voucher_aux_period on fin_voucher_aux_value (account_set_id, fiscal_year, period);
create index idx_voucher_aux_voucher on fin_voucher_aux_value (account_set_id, voucher_id);
create index idx_voucher_aux_detail on fin_voucher_aux_value (account_set_id, detail_id);
create index idx_voucher_aux_detail_id on fin_voucher_aux_value (voucher_id, detail_id);
create index idx_voucher_aux_value on fin_voucher_aux_value (account_set_id, aux_type_code, aux_value);
create index idx_voucher_aux_del on fin_voucher_aux_value (account_set_id, del_flag);

create table fin_case_fund_payment (
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
    remark varchar(500)
);

create unique index uk_case_fund_payment_source on fin_case_fund_payment (account_set_id, source_fingerprint);
create index idx_case_fund_payment_period on fin_case_fund_payment (account_set_id, period, payment_date);
create index idx_case_fund_payment_case on fin_case_fund_payment (account_set_id, case_no);
create index idx_case_fund_payment_receipt on fin_case_fund_payment (account_set_id, receipt_no);
create index idx_case_fund_payment_bank_serial on fin_case_fund_payment (account_set_id, bank_serial_no);
create index idx_case_fund_payment_voucher on fin_case_fund_payment (account_set_id, voucher_status, voucher_period, voucher_id);

create table fin_case_fund_refund (
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
    remark varchar(500)
);

create unique index uk_refund_fingerprint on fin_case_fund_refund (account_set_id, source_fingerprint, del_flag);
create index idx_case_fund_refund_period on fin_case_fund_refund (account_set_id, period, refund_date);
create index idx_case_fund_refund_date on fin_case_fund_refund (account_set_id, refund_date, del_flag);
create index idx_case_fund_refund_out_type on fin_case_fund_refund (account_set_id, out_type, del_flag);
create index idx_case_fund_refund_case on fin_case_fund_refund (account_set_id, case_no);
create index idx_case_fund_refund_source_receipt on fin_case_fund_refund (account_set_id, source_receipt_no);
create index idx_case_fund_refund_voucher on fin_case_fund_refund (account_set_id, voucher_status, voucher_period, voucher_id);

create table fin_case_fund_subject_config (
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
    remark varchar(500)
);

create unique index uk_case_fund_subject_config on fin_case_fund_subject_config (account_set_id, biz_type, voucher_biz_type, business_item_type);
create index idx_case_fund_subject_config_subject on fin_case_fund_subject_config (account_set_id, debit_subject_code, credit_subject_code);
