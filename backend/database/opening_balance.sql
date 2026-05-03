create table if not exists fin_opening_balance (
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
    remark varchar(500),
    unique key uk_opening_subject (account_set_id, period, subject_code)
);
