-- RBAC migration for court finance system
-- Safe to run more than once on MySQL 8.x.

create table if not exists fin_permission (
    permission_code varchar(50) primary key comment '权限码，如 voucher:audit',
    permission_name varchar(100) not null,
    permission_type tinyint not null comment '1=菜单 2=按钮',
    module_code varchar(50),
    description varchar(255),
    sort_order int default 0
);

create table if not exists fin_role (
    role_id varchar(36) primary key,
    role_code varchar(50) not null,
    role_name varchar(100) not null,
    description varchar(255),
    is_system tinyint default 0,
    view_scope varchar(20) default 'ALL',
    status tinyint default 1,
    created_at datetime,
    updated_at datetime,
    unique key uk_fin_role_code (role_code)
);

create table if not exists fin_role_permission (
    role_id varchar(36) not null,
    permission_code varchar(50) not null,
    primary key (role_id, permission_code)
);

create table if not exists fin_user_role (
    user_id varchar(36) not null,
    role_id varchar(36) not null,
    primary key (user_id, role_id)
);

create table if not exists fin_user_account_set (
    user_id varchar(36) not null,
    account_set_id varchar(36) not null,
    primary key (user_id, account_set_id)
);

set @has_must_change_password := (
    select count(*) from information_schema.columns
    where table_schema = database()
      and table_name = 'sys_user'
      and column_name = 'must_change_password'
);
set @ddl := if(@has_must_change_password = 0,
    'alter table sys_user add column must_change_password tinyint default 1 comment ''首次登录强制改密''',
    'select 1'
);
prepare stmt from @ddl;
execute stmt;
deallocate prepare stmt;

insert into fin_permission (permission_code, permission_name, permission_type, module_code, description, sort_order) values
('menu:dashboard', '首页', 1, 'dashboard', '/dashboard', 10),
('menu:voucher', '凭证中心', 1, 'voucher', '/vouchers', 20),
('menu:book', '账簿', 1, 'book', '/books/*', 30),
('menu:book:detail_ledger', '明细账', 1, 'book', '/books/detail-ledger', 31),
('menu:book:subject_balance', '科目余额表', 1, 'book', '/books/subject-balance', 32),
('menu:book:subject_summary', '科目汇总表', 1, 'book', '/books/subject-summary', 33),
('menu:book:aux_balance', '辅助核算余额表', 1, 'book', '/books/aux-balance', 34),
('menu:case_fund', '案款业务', 1, 'case_fund', '/case-fund/*', 35),
('menu:case_fund:payment', '案款缴费登记', 1, 'case_fund', '/case-fund/payments', 36),
('menu:case_fund:refund', '案款退付登记', 1, 'case_fund', '/case-fund/refunds', 37),
('menu:case_fund:bank_statement', '银行对账单', 1, 'case_fund', '/case-fund/bank-statements', 38),
('menu:base', '基础数据', 1, 'base', '/base/*', 40),
('menu:base:subject', '科目管理', 1, 'base', '/base/subjects', 41),
('menu:base:opening', '期初余额', 1, 'base', '/base/opening-balances', 42),
('menu:base:aux', '辅助核算项', 1, 'base', '/base/aux-items', 43),
('menu:system', '系统管理', 1, 'system', '/system/*', 50),
('menu:system:user', '用户管理', 1, 'system', '/system/users', 51),
('menu:system:role', '角色管理', 1, 'system', '/system/roles', 52),
('menu:system:role_permission', '角色权限配置', 1, 'system', '/system/role-permissions', 53),
('menu:system:account_set', '账套管理', 1, 'system', '/system/account-sets', 54),
('menu:system:audit_log', '审计日志', 1, 'system', '/system/audit-logs', 55),
('voucher:view', '凭证查看', 2, 'voucher', '查看凭证', 101),
('voucher:add', '凭证新增', 2, 'voucher', '新增凭证', 102),
('voucher:edit', '凭证编辑', 2, 'voucher', '编辑凭证', 103),
('voucher:delete', '凭证删除', 2, 'voucher', '删除凭证', 104),
('voucher:audit', '凭证审核', 2, 'voucher', '审核凭证', 105),
('voucher:unaudit', '凭证反审核', 2, 'voucher', '反审核凭证', 106),
('voucher:post', '凭证记账', 2, 'voucher', '凭证记账', 107),
('voucher:unpost', '凭证反记账', 2, 'voucher', '反记账', 108),
('voucher:export', '凭证导出', 2, 'voucher', '导出凭证', 109),
('voucher:print', '凭证打印', 2, 'voucher', '打印凭证', 110),
('voucher:import', '凭证批量导入', 2, 'voucher', '批量导入凭证', 111),
('book:view', '账簿查看', 2, 'book', '查看账簿', 201),
('book:export', '账簿导出', 2, 'book', '导出账簿', 202),
('book:print', '账簿打印', 2, 'book', '打印账簿', 203),
('case_fund:view', '案款业务查看', 2, 'case_fund', '查看案款缴费和退付登记', 251),
('case_fund:import', '案款业务导入', 2, 'case_fund', '导入案款缴费和退付登记', 252),
('case_fund:generate_voucher', '案款生成凭证', 2, 'case_fund', '根据案款登记批量生成凭证', 253),
('case_fund:subject_config', '案款科目配置', 2, 'case_fund', '配置案款和诉讼费登记生成凭证的借贷科目', 254),
('case_fund:reconcile', '案款银行对账', 2, 'case_fund', '按银行流水号执行案款银行自动对账', 255),
('case_fund:delete', '案款业务删除', 2, 'case_fund', '删除未制证且未对账的案款业务数据', 256),
('base:view', '基础资料查看', 2, 'base', '查看基础资料', 301),
('base:add', '基础资料新增', 2, 'base', '新增基础资料', 302),
('base:edit', '基础资料编辑', 2, 'base', '编辑基础资料', 303),
('base:delete', '基础资料删除', 2, 'base', '删除基础资料', 304),
('base:export', '基础资料导出', 2, 'base', '导出基础资料', 305),
('opening:save', '期初保存', 2, 'base', '保存科目期初和辅助期初余额', 306),
('period:close', '期末结账', 2, 'period', '期末结账', 401),
('system:user:view', '用户查看', 2, 'system', '查看用户', 501),
('system:user:add', '用户新增', 2, 'system', '新增用户', 502),
('system:user:edit', '用户编辑', 2, 'system', '编辑用户', 503),
('system:user:delete', '用户删除', 2, 'system', '删除用户', 504),
('system:user:reset_password', '重置密码', 2, 'system', '管理员重置密码', 505),
('system:account_set:view', '账套查看', 2, 'system', '查看账套', 551),
('system:account_set:add', '账套新增', 2, 'system', '新增账套', 552),
('system:account_set:edit', '账套编辑', 2, 'system', '编辑账套', 553),
('system:role:view', '角色查看', 2, 'system', '查看角色', 601),
('system:role:add', '角色新增', 2, 'system', '新增角色', 602),
('system:role:edit', '角色编辑', 2, 'system', '编辑角色', 603),
('system:role:delete', '角色删除', 2, 'system', '删除角色', 604),
('system:role:assign_permission', '分配权限', 2, 'system', '配置角色权限', 605)
on duplicate key update
    permission_name = values(permission_name),
    permission_type = values(permission_type),
    module_code = values(module_code),
    description = values(description),
    sort_order = values(sort_order);

insert into fin_role (role_id, role_code, role_name, description, is_system, view_scope, status, created_at, updated_at) values
('00000000-0000-0000-0000-000000010001', 'system_admin', '系统管理员', '系统预置管理员，拥有全部权限', 1, 'ALL', 1, now(), now()),
('00000000-0000-0000-0000-000000010002', 'finance_manager', '财务主管', '财务主管，负责凭证、账簿、基础资料和审计查看', 1, 'ALL', 1, now(), now()),
('00000000-0000-0000-0000-000000010003', 'voucher_maker', '制单会计', '负责制单和草稿维护', 1, 'ALL', 1, now(), now()),
('00000000-0000-0000-0000-000000010004', 'voucher_auditor', '审核会计', '负责凭证审核和反审核', 1, 'ALL', 1, now(), now()),
('00000000-0000-0000-0000-000000010005', 'voucher_poster', '记账会计', '负责凭证记账和期末结账', 1, 'ALL', 1, now(), now())
on duplicate key update
    role_name = values(role_name),
    description = values(description),
    is_system = values(is_system),
    view_scope = values(view_scope),
    status = values(status),
    updated_at = now();

delete from fin_role_permission where role_id in (
    '00000000-0000-0000-0000-000000010001',
    '00000000-0000-0000-0000-000000010002',
    '00000000-0000-0000-0000-000000010003',
    '00000000-0000-0000-0000-000000010004',
    '00000000-0000-0000-0000-000000010005'
);

insert into fin_role_permission (role_id, permission_code) values
('00000000-0000-0000-0000-000000010001', '*'),
('00000000-0000-0000-0000-000000010002', 'menu:dashboard'),
('00000000-0000-0000-0000-000000010002', 'menu:voucher'),
('00000000-0000-0000-0000-000000010002', 'menu:book'),
('00000000-0000-0000-0000-000000010002', 'menu:book:detail_ledger'),
('00000000-0000-0000-0000-000000010002', 'menu:book:subject_balance'),
('00000000-0000-0000-0000-000000010002', 'menu:book:subject_summary'),
('00000000-0000-0000-0000-000000010002', 'menu:book:aux_balance'),
('00000000-0000-0000-0000-000000010002', 'menu:case_fund'),
('00000000-0000-0000-0000-000000010002', 'menu:case_fund:payment'),
('00000000-0000-0000-0000-000000010002', 'menu:case_fund:refund'),
('00000000-0000-0000-0000-000000010002', 'menu:case_fund:bank_statement'),
('00000000-0000-0000-0000-000000010002', 'menu:base'),
('00000000-0000-0000-0000-000000010002', 'menu:base:subject'),
('00000000-0000-0000-0000-000000010002', 'menu:base:opening'),
('00000000-0000-0000-0000-000000010002', 'menu:base:aux'),
('00000000-0000-0000-0000-000000010002', 'menu:system:audit_log'),
('00000000-0000-0000-0000-000000010002', 'voucher:view'),
('00000000-0000-0000-0000-000000010002', 'voucher:add'),
('00000000-0000-0000-0000-000000010002', 'voucher:edit'),
('00000000-0000-0000-0000-000000010002', 'voucher:delete'),
('00000000-0000-0000-0000-000000010002', 'voucher:audit'),
('00000000-0000-0000-0000-000000010002', 'voucher:unaudit'),
('00000000-0000-0000-0000-000000010002', 'voucher:post'),
('00000000-0000-0000-0000-000000010002', 'voucher:unpost'),
('00000000-0000-0000-0000-000000010002', 'voucher:export'),
('00000000-0000-0000-0000-000000010002', 'voucher:print'),
('00000000-0000-0000-0000-000000010002', 'voucher:import'),
('00000000-0000-0000-0000-000000010002', 'book:view'),
('00000000-0000-0000-0000-000000010002', 'book:export'),
('00000000-0000-0000-0000-000000010002', 'book:print'),
('00000000-0000-0000-0000-000000010002', 'case_fund:view'),
('00000000-0000-0000-0000-000000010002', 'case_fund:import'),
('00000000-0000-0000-0000-000000010002', 'case_fund:generate_voucher'),
('00000000-0000-0000-0000-000000010002', 'case_fund:subject_config'),
('00000000-0000-0000-0000-000000010002', 'case_fund:reconcile'),
('00000000-0000-0000-0000-000000010002', 'case_fund:delete'),
('00000000-0000-0000-0000-000000010002', 'base:view'),
('00000000-0000-0000-0000-000000010002', 'base:add'),
('00000000-0000-0000-0000-000000010002', 'base:edit'),
('00000000-0000-0000-0000-000000010002', 'base:delete'),
('00000000-0000-0000-0000-000000010002', 'base:export'),
('00000000-0000-0000-0000-000000010002', 'opening:save'),
('00000000-0000-0000-0000-000000010002', 'period:close'),
('00000000-0000-0000-0000-000000010003', 'menu:dashboard'),
('00000000-0000-0000-0000-000000010003', 'menu:voucher'),
('00000000-0000-0000-0000-000000010003', 'menu:book'),
('00000000-0000-0000-0000-000000010003', 'menu:book:detail_ledger'),
('00000000-0000-0000-0000-000000010003', 'menu:book:subject_balance'),
('00000000-0000-0000-0000-000000010003', 'menu:book:subject_summary'),
('00000000-0000-0000-0000-000000010003', 'menu:book:aux_balance'),
('00000000-0000-0000-0000-000000010003', 'menu:case_fund'),
('00000000-0000-0000-0000-000000010003', 'menu:case_fund:payment'),
('00000000-0000-0000-0000-000000010003', 'menu:case_fund:refund'),
('00000000-0000-0000-0000-000000010003', 'menu:case_fund:bank_statement'),
('00000000-0000-0000-0000-000000010003', 'menu:base'),
('00000000-0000-0000-0000-000000010003', 'menu:base:subject'),
('00000000-0000-0000-0000-000000010003', 'menu:base:opening'),
('00000000-0000-0000-0000-000000010003', 'menu:base:aux'),
('00000000-0000-0000-0000-000000010003', 'voucher:view'),
('00000000-0000-0000-0000-000000010003', 'voucher:add'),
('00000000-0000-0000-0000-000000010003', 'voucher:edit'),
('00000000-0000-0000-0000-000000010003', 'voucher:delete'),
('00000000-0000-0000-0000-000000010003', 'voucher:export'),
('00000000-0000-0000-0000-000000010003', 'voucher:print'),
('00000000-0000-0000-0000-000000010003', 'voucher:import'),
('00000000-0000-0000-0000-000000010003', 'book:view'),
('00000000-0000-0000-0000-000000010003', 'book:export'),
('00000000-0000-0000-0000-000000010003', 'book:print'),
('00000000-0000-0000-0000-000000010003', 'case_fund:view'),
('00000000-0000-0000-0000-000000010003', 'case_fund:import'),
('00000000-0000-0000-0000-000000010003', 'case_fund:generate_voucher'),
('00000000-0000-0000-0000-000000010003', 'case_fund:subject_config'),
('00000000-0000-0000-0000-000000010003', 'case_fund:reconcile'),
('00000000-0000-0000-0000-000000010003', 'case_fund:delete'),
('00000000-0000-0000-0000-000000010003', 'base:view'),
('00000000-0000-0000-0000-000000010003', 'opening:save'),
('00000000-0000-0000-0000-000000010004', 'menu:dashboard'),
('00000000-0000-0000-0000-000000010004', 'menu:voucher'),
('00000000-0000-0000-0000-000000010004', 'menu:book'),
('00000000-0000-0000-0000-000000010004', 'menu:book:detail_ledger'),
('00000000-0000-0000-0000-000000010004', 'menu:book:subject_balance'),
('00000000-0000-0000-0000-000000010004', 'menu:book:subject_summary'),
('00000000-0000-0000-0000-000000010004', 'menu:book:aux_balance'),
('00000000-0000-0000-0000-000000010004', 'menu:case_fund'),
('00000000-0000-0000-0000-000000010004', 'menu:case_fund:payment'),
('00000000-0000-0000-0000-000000010004', 'menu:case_fund:refund'),
('00000000-0000-0000-0000-000000010004', 'menu:case_fund:bank_statement'),
('00000000-0000-0000-0000-000000010004', 'menu:base'),
('00000000-0000-0000-0000-000000010004', 'menu:base:subject'),
('00000000-0000-0000-0000-000000010004', 'menu:base:opening'),
('00000000-0000-0000-0000-000000010004', 'menu:base:aux'),
('00000000-0000-0000-0000-000000010004', 'voucher:view'),
('00000000-0000-0000-0000-000000010004', 'voucher:audit'),
('00000000-0000-0000-0000-000000010004', 'voucher:unaudit'),
('00000000-0000-0000-0000-000000010004', 'voucher:export'),
('00000000-0000-0000-0000-000000010004', 'voucher:print'),
('00000000-0000-0000-0000-000000010004', 'book:view'),
('00000000-0000-0000-0000-000000010004', 'book:export'),
('00000000-0000-0000-0000-000000010004', 'book:print'),
('00000000-0000-0000-0000-000000010004', 'case_fund:view'),
('00000000-0000-0000-0000-000000010004', 'base:view'),
('00000000-0000-0000-0000-000000010005', 'menu:dashboard'),
('00000000-0000-0000-0000-000000010005', 'menu:voucher'),
('00000000-0000-0000-0000-000000010005', 'menu:book'),
('00000000-0000-0000-0000-000000010005', 'menu:book:detail_ledger'),
('00000000-0000-0000-0000-000000010005', 'menu:book:subject_balance'),
('00000000-0000-0000-0000-000000010005', 'menu:book:subject_summary'),
('00000000-0000-0000-0000-000000010005', 'menu:book:aux_balance'),
('00000000-0000-0000-0000-000000010005', 'menu:case_fund'),
('00000000-0000-0000-0000-000000010005', 'menu:case_fund:payment'),
('00000000-0000-0000-0000-000000010005', 'menu:case_fund:refund'),
('00000000-0000-0000-0000-000000010005', 'menu:case_fund:bank_statement'),
('00000000-0000-0000-0000-000000010005', 'menu:base'),
('00000000-0000-0000-0000-000000010005', 'menu:base:subject'),
('00000000-0000-0000-0000-000000010005', 'menu:base:opening'),
('00000000-0000-0000-0000-000000010005', 'menu:base:aux'),
('00000000-0000-0000-0000-000000010005', 'voucher:view'),
('00000000-0000-0000-0000-000000010005', 'voucher:post'),
('00000000-0000-0000-0000-000000010005', 'voucher:unpost'),
('00000000-0000-0000-0000-000000010005', 'voucher:export'),
('00000000-0000-0000-0000-000000010005', 'voucher:print'),
('00000000-0000-0000-0000-000000010005', 'book:view'),
('00000000-0000-0000-0000-000000010005', 'book:export'),
('00000000-0000-0000-0000-000000010005', 'book:print'),
('00000000-0000-0000-0000-000000010005', 'case_fund:view'),
('00000000-0000-0000-0000-000000010005', 'base:view'),
('00000000-0000-0000-0000-000000010005', 'period:close');

set @has_role_code := (
    select count(*) from information_schema.columns
    where table_schema = database()
      and table_name = 'sys_user'
      and column_name = 'role_code'
);

set @migrate_admin_role_sql := if(@has_role_code > 0,
    'insert ignore into fin_user_role (user_id, role_id)
     select u.user_id, ''00000000-0000-0000-0000-000000010001''
     from sys_user u
     where u.role_code = ''admin'' and u.del_flag = 0',
    'select 1'
);
prepare stmt from @migrate_admin_role_sql;
execute stmt;
deallocate prepare stmt;

set @migrate_admin_account_sql := if(@has_role_code > 0,
    'insert ignore into fin_user_account_set (user_id, account_set_id)
     select u.user_id, a.account_set_id
     from sys_user u
     join fin_account_set a on a.status = 1 and a.del_flag = 0
     where u.role_code = ''admin'' and u.del_flag = 0',
    'select 1'
);
prepare stmt from @migrate_admin_account_sql;
execute stmt;
deallocate prepare stmt;

insert ignore into fin_user_account_set (user_id, account_set_id)
select u.user_id, '00000000-0000-0000-0000-000000000101'
from sys_user u
where u.del_flag = 0
  and exists (
    select 1 from fin_account_set a
    where a.account_set_id = '00000000-0000-0000-0000-000000000101'
      and a.del_flag = 0
  );

set @drop_role_code_sql := if(@has_role_code > 0, 'alter table sys_user drop column role_code', 'select 1');
prepare stmt from @drop_role_code_sql;
execute stmt;
deallocate prepare stmt;
