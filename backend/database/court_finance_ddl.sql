create table sys_audit_log (
    log_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    biz_type varchar(50) not null,
    biz_id varchar(64) not null,
    operation varchar(50) not null,
    before_json text,
    after_json text,
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

create unique index uk_voucher_no_sequence on fin_voucher_no_sequence (account_set_id, period);

create table fin_voucher_2026 (
    voucher_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    period varchar(7) not null,
    voucher_date date not null,
    voucher_word varchar(10) not null default '记',
    voucher_no int not null,
    summary varchar(500),
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

create unique index uk_voucher_2026_no on fin_voucher_2026 (account_set_id, period, voucher_no);
create index idx_voucher_2026_date on fin_voucher_2026 (account_set_id, voucher_date);

create table fin_voucher_detail_2026 (
    detail_id varchar(36) primary key,
    account_set_id varchar(36) not null,
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

create index idx_voucher_detail_2026_voucher on fin_voucher_detail_2026 (account_set_id, voucher_id);
create index idx_voucher_detail_2026_subject on fin_voucher_detail_2026 (account_set_id, subject_code);

create table fin_voucher_aux_value_2026 (
    id varchar(36) primary key,
    account_set_id varchar(36) not null,
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

create index idx_voucher_aux_2026_detail on fin_voucher_aux_value_2026 (account_set_id, detail_id);
create index idx_voucher_aux_2026_value on fin_voucher_aux_value_2026 (account_set_id, aux_type_code, aux_value);
