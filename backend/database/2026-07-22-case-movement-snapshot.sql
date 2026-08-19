-- MySQL 8.0 案号跨年度收支明细持久化快照表及菜单权限补丁

create table if not exists fin_case_movement_snapshot (
    snapshot_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    fiscal_year int not null,
    period varchar(7) not null,
    voucher_id varchar(36) not null,
    voucher_date date not null,
    voucher_word varchar(10) not null default '记',
    voucher_no int not null,
    voucher_status varchar(20) not null default '',
    detail_id varchar(36) not null,
    line_no int not null default 0,
    subject_code varchar(50) not null,
    subject_name varchar(200) not null default '',
    direction varchar(20) not null default 'DEBIT',
    case_no varchar(200) not null,
    receipt_no varchar(200) not null default '',
    summary varchar(500) not null default '',
    debit_amount decimal(18,2) not null default 0,
    credit_amount decimal(18,2) not null default 0,
    source_updated_time datetime,
    snapshot_time datetime not null,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500),
    unique key uk_case_movement_snapshot_detail (account_set_id, detail_id),
    key idx_case_movement_snapshot_page (account_set_id, period, del_flag, subject_code, case_no, voucher_date, voucher_no),
    key idx_case_movement_snapshot_case (account_set_id, case_no, period, del_flag),
    key idx_case_movement_snapshot_receipt (account_set_id, receipt_no, period, del_flag),
    key idx_case_movement_snapshot_source (account_set_id, source_updated_time, del_flag)
);

insert into fin_permission (permission_code, permission_name, permission_type, module_code, description, sort_order)
values ('menu:book:case_movement_detail', '案号收支明细表', 1, 'book', '/books/case-movement-detail', 35)
on duplicate key update
    permission_name = values(permission_name),
    permission_type = values(permission_type),
    module_code = values(module_code),
    description = values(description),
    sort_order = values(sort_order);

insert ignore into fin_role_permission (role_id, permission_code) values
('00000000-0000-0000-0000-000000010002', 'menu:book:case_movement_detail'),
('00000000-0000-0000-0000-000000010003', 'menu:book:case_movement_detail'),
('00000000-0000-0000-0000-000000010004', 'menu:book:case_movement_detail'),
('00000000-0000-0000-0000-000000010005', 'menu:book:case_movement_detail');
