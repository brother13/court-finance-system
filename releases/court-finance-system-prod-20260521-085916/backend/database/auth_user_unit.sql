create table if not exists sys_unit (
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
    remark varchar(500),
    unique key uk_sys_unit_code (unit_code)
);

create table if not exists sys_user (
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
    remark varchar(500),
    unique key uk_sys_user_unit_name (unit_id, username),
    key idx_sys_user_unit (unit_id)
);

insert into sys_unit (
    unit_id, unit_code, unit_name, sort_no, status,
    created_by, created_time, updated_by, updated_time, del_flag, version, remark
) values (
    '00000000-0000-0000-0000-000000000201',
    'DEMO-COURT',
    '示例人民法院',
    1,
    1,
    'system',
    now(),
    'system',
    now(),
    0,
    0,
    '系统预置单位'
) on duplicate key update
    unit_name = values(unit_name),
    status = values(status),
    updated_by = values(updated_by),
    updated_time = values(updated_time),
    del_flag = values(del_flag);

insert into sys_user (
    user_id, unit_id, username, password_hash, real_name, status, must_change_password,
    created_by, created_time, updated_by, updated_time, del_flag, version, remark
) values (
    '00000000-0000-0000-0000-000000000301',
    '00000000-0000-0000-0000-000000000201',
    'admin',
    '$2y$10$WU8EQMKfDHdbI9F82OsI7uatwiXdzdxrBmF6YHMCuvJrUs3YoZW3i',
    '系统管理员',
    1,
    0,
    'system',
    now(),
    'system',
    now(),
    0,
    0,
    '初始密码 123456，请上线前修改'
) on duplicate key update
    password_hash = values(password_hash),
    real_name = values(real_name),
    status = values(status),
    must_change_password = values(must_change_password),
    updated_by = values(updated_by),
    updated_time = values(updated_time),
    del_flag = values(del_flag);

insert ignore into fin_user_role (user_id, role_id) values
('00000000-0000-0000-0000-000000000301', '00000000-0000-0000-0000-000000010001');

insert ignore into fin_user_account_set (user_id, account_set_id)
select '00000000-0000-0000-0000-000000000301', account_set_id
from fin_account_set
where status = 1 and del_flag = 0;
