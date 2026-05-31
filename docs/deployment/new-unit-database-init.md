# 新用户单位数据库初始化实施手册

本文档用于“树莓法衡专项账务系统”在一个新的法院或新用户单位部署时初始化数据库。程序部署、Web 服务、前端构建不在本文档范围内。

## 1. 初始化原则

1. 每个法院独立部署一个数据库实例或一个独立数据库，不在同一套业务库里混用多个法院数据。
2. 登录页“用户单位”来自 `sys_unit` 表；登录用户来自 `sys_user` 表；账套来自 `fin_account_set` 表。
3. 业务数据按 `account_set_id` 隔离，不使用 `customer_id` 作为租户字段。
4. 新单位上线前必须替换示例单位、示例管理员、默认密码和默认账套年度。
5. 初始化脚本只允许在空库或确认无业务数据的新库执行。已有凭证、期初、导入业务数据后，不要重新执行会重置基础资料的脚本。

## 2. 部署前确认信息

上线前先确认以下信息：

```text
单位编码：例如 SJTFY
单位名称：例如 沈阳市苏家屯区人民法院
数据库名：例如 court-finance
启用年度：例如 2026
启用期间：例如 2026-01
初始管理员账号：例如 admin
初始管理员姓名：例如 系统管理员
初始管理员临时密码：上线后首次登录强制修改
启用账套：案款账套 / 诉讼费账套
```

建议单位编码使用大写字母、数字、短横线或下划线，不要使用中文。

## 3. 创建数据库

MySQL 8 示例：

```sql
create database if not exists `court-finance`
default character set utf8
collate utf8_general_ci;
```

如果使用命令行：

```bash
mysql -uroot -p -e "create database if not exists \`court-finance\` default character set utf8 collate utf8_general_ci;"
```

生产环境不要沿用开发库账号 `root/root`。应用连接账号应单独创建，并只授予当前业务库权限。

```sql
create user 'court_finance_app'@'%' identified by '请替换为强密码';
grant select, insert, update, delete
on `court-finance`.*
to 'court_finance_app'@'%';
flush privileges;
```

初始化、升级脚本使用 DBA 账号执行。应用运行账号只保留业务读写权限，避免生产运行期误执行 DDL。

## 4. 执行基础脚本

在空库中按以下顺序执行：

```bash
mysql -uroot -p --default-character-set=utf8 court-finance < backend/database/court_finance_ddl.sql
mysql -uroot -p --default-character-set=utf8 court-finance < backend/database/preset_case_fund_template.sql
mysql -uroot -p --default-character-set=utf8 court-finance < backend/database/account_sets_preset.sql
mysql -uroot -p --default-character-set=utf8 court-finance < backend/database/migration_rbac.sql
mysql -uroot -p --default-character-set=utf8 court-finance < backend/database/finance_standard_patch.sql
mysql -uroot -p --default-character-set=utf8 court-finance < backend/database/standard_aux_types.sql
mysql -uroot -p --default-character-set=utf8 court-finance < backend/database/opening_balance.sql
mysql -uroot -p --default-character-set=utf8 court-finance < backend/database/auth_user_unit.sql
```

说明：

1. `court_finance_ddl.sql` 创建核心表。
2. `preset_case_fund_template.sql` 初始化默认案款账套、期间、科目、案号/收据号等辅助核算配置。
3. `account_sets_preset.sql` 补充诉讼费账套记录。初始化不预置食堂账和工会账。
4. `migration_rbac.sql` 初始化菜单、按钮权限和预置角色。
5. `finance_standard_patch.sql` 补齐历史升级字段和新增业务表。
6. `standard_aux_types.sql` 补充标准辅助核算维度。
7. `opening_balance.sql` 确认期初余额表存在。
8. `auth_user_unit.sql` 写入默认单位和管理员。执行后必须继续执行下一节的新单位覆盖 SQL。

注意：`preset_case_fund_template.sql` 不是完整幂等脚本，必须在空库且按上述顺序执行；不要在已有业务数据的生产库重复执行。

## 5. 生成管理员密码哈希

不要在 SQL 中保存明文密码。先在 PHP 环境生成密码哈希：

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php -r "echo password_hash('请替换为临时强密码', PASSWORD_DEFAULT), PHP_EOL;"
```

复制输出结果，填入下一节 SQL 的 `@admin_password_hash`。

## 6. 执行新单位覆盖 SQL

建议把下面内容复制为每个单位独立的脚本，例如：

```text
deploy/sql/init-unit-sjtfy-2026.sql
```

再替换变量后执行。不要直接修改通用初始化脚本。

```sql
set @unit_id = '00000000-0000-0000-0000-000000000201';
set @unit_code = 'SJTFY';
set @unit_name = '沈阳市苏家屯区人民法院';

set @admin_user_id = '00000000-0000-0000-0000-000000000301';
set @admin_username = 'admin';
set @admin_real_name = '系统管理员';
set @admin_password_hash = '请替换为 password_hash 输出值';

set @enabled_year = 2026;
set @enabled_period = '2026-01';
set @case_account_set_id = '00000000-0000-0000-0000-000000000101';

start transaction;

update sys_unit
set unit_code = @unit_code,
    unit_name = @unit_name,
    sort_no = 1,
    status = 1,
    updated_by = 'deploy',
    updated_time = now(),
    del_flag = 0,
    remark = '生产部署单位'
where unit_id = @unit_id;

update sys_user
set unit_id = @unit_id,
    username = @admin_username,
    password_hash = @admin_password_hash,
    real_name = @admin_real_name,
    status = 1,
    must_change_password = 1,
    updated_by = 'deploy',
    updated_time = now(),
    del_flag = 0,
    remark = '生产初始管理员，上线后必须修改密码'
where user_id = @admin_user_id;

update fin_account_set
set set_code = concat('CASE_FUND_', @enabled_year),
    set_name = '案款账套',
    biz_type = 'CASE_FUND',
    enabled_year = @enabled_year,
    enabled_period = @enabled_period,
    finance_manager = '',
    paper_size = 'A5',
    voucher_import_auto_no = 1,
    voucher_print_line_count = 8,
    generate_voucher_by_day_flag = 1,
    generate_refund_voucher_by_out_order_no_flag = 0,
    subject_code_rule = '4-2-2-2',
    status = 1,
    updated_by = 'deploy',
    updated_time = now(),
    del_flag = 0,
    remark = concat(@unit_name, '案款专项资金账套')
where account_set_id = @case_account_set_id;

delete from fin_fiscal_period
where account_set_id = @case_account_set_id;

insert into fin_fiscal_period (
    period_id, account_set_id, period, start_date, end_date, status,
    created_by, created_time, updated_by, updated_time, del_flag, version
)
select uuid(),
       @case_account_set_id,
       concat(@enabled_year, '-', month_no),
       str_to_date(concat(@enabled_year, '-', month_no, '-01'), '%Y-%m-%d'),
       last_day(str_to_date(concat(@enabled_year, '-', month_no, '-01'), '%Y-%m-%d')),
       'OPEN',
       'deploy', now(), 'deploy', now(), 0, 0
from (
    select '01' as month_no union all select '02' union all select '03' union all
    select '04' union all select '05' union all select '06' union all
    select '07' union all select '08' union all select '09' union all
    select '10' union all select '11' union all select '12'
) m
where concat(@enabled_year, '-', month_no) >= @enabled_period;

update fin_account_set
set status = 0,
    updated_by = 'deploy',
    updated_time = now(),
    remark = '新单位初始化时暂未启用'
where account_set_id = '00000000-0000-0000-0000-000000000102';

delete from fin_user_account_set
where user_id = @admin_user_id;

insert into fin_user_account_set (user_id, account_set_id)
select @admin_user_id, account_set_id
from fin_account_set
where status = 1
  and del_flag = 0;

insert ignore into fin_user_role (user_id, role_id)
values (@admin_user_id, '00000000-0000-0000-0000-000000010001');

commit;
```

如果新单位需要同时启用诉讼费账套，不要执行上面“暂未启用”更新，或者执行后再把诉讼费账套状态改回启用，并补齐期间、科目、期初和权限授权。食堂账和工会账初始化阶段不创建；确需使用时，通过系统“账套管理”另行新增，并补齐对应基础资料。

## 7. 生产上线前核查 SQL

执行完初始化后，至少核查以下内容。

### 7.1 单位与登录用户

```sql
select unit_id, unit_code, unit_name, status, del_flag
from sys_unit;

select user_id, unit_id, username, real_name, status, must_change_password, del_flag
from sys_user;
```

要求：

1. 登录页只能看到正式单位名称，不应出现“示例人民法院”。
2. 管理员 `must_change_password` 应为 `1`。
3. 用户 `status=1`、`del_flag=0`。

### 7.2 角色与权限

```sql
select r.role_code, r.role_name, r.status
from fin_user_role ur
join fin_role r on r.role_id = ur.role_id
where ur.user_id = '00000000-0000-0000-0000-000000000301';

select count(*) as permission_count
from fin_permission;
```

要求：

1. 初始管理员拥有 `system_admin` 角色。
2. `fin_permission` 不为空。

### 7.3 账套与期间

```sql
select account_set_id, set_code, set_name, biz_type, enabled_year, enabled_period, status, del_flag
from fin_account_set
order by set_code;

select account_set_id, min(period) as first_period, max(period) as last_period, count(*) as period_count
from fin_fiscal_period
where del_flag = 0
group by account_set_id;

select user_id, account_set_id
from fin_user_account_set
order by user_id, account_set_id;
```

要求：

1. 已启用账套的 `enabled_year`、`enabled_period` 与部署年度一致。
2. 案款账套应生成启用期间到当年 12 月的期间。
3. 管理员只授权到正式启用的账套。

### 7.4 科目与辅助核算

```sql
select subject_code, subject_name, parent_code, direction, subject_type, leaf_flag, voucher_entry_flag, status
from fin_subject
where account_set_id = '00000000-0000-0000-0000-000000000101'
  and del_flag = 0
order by subject_code;

select aux_type_code, aux_type_name, value_source, required_flag, status
from fin_aux_type
where account_set_id = '00000000-0000-0000-0000-000000000101'
  and del_flag = 0
order by aux_type_code;

select subject_code, aux_type_code, required_flag, verification_flag
from fin_subject_aux_config
where account_set_id = '00000000-0000-0000-0000-000000000101'
  and del_flag = 0
order by subject_code, aux_type_code;
```

要求：

1. 案款账套至少有银行存款、其他应付款、案款暂存等科目。
2. `220101` 案款暂存应挂载案号、收据号、当事人等辅助核算配置。
3. 科目方向、科目类别、末级标记和是否允许录入凭证符合财务规则。

## 8. 初始数据上线检查清单

上线前人工确认：

1. 登录页用户单位显示正式单位名称。
2. 初始管理员能登录，并被强制修改密码。
3. 选择账套页只显示本单位应启用的账套。
4. 当前会计期间正确。
5. 科目管理能看到案款账套科目。
6. 辅助核算项能看到案号、收据号、当事人等配置。
7. 用户管理中已创建正式财务人员账号，并按岗位分配角色。
8. 所有正式用户已授权对应账套。
9. 未启用的账套保持 `status=0` 或不授权给用户。
10. 生产环境不保留默认密码、示例单位、测试导入数据。

## 9. 常见问题

### 9.1 登录页单位名称不对

检查：

```sql
select unit_code, unit_name, status, del_flag
from sys_unit
order by sort_no, unit_code;
```

登录页只读取 `status=1` 且 `del_flag=0` 的单位。

### 9.2 登录后没有账套

检查用户账套授权：

```sql
select ua.user_id, u.username, a.account_set_id, a.set_name, a.status, a.del_flag
from fin_user_account_set ua
join sys_user u on u.user_id = ua.user_id
join fin_account_set a on a.account_set_id = ua.account_set_id
where u.username = 'admin';
```

需要给用户写入 `fin_user_account_set`，且账套必须 `status=1`、`del_flag=0`。

### 9.3 菜单为空或按钮不可用

检查用户角色和角色权限：

```sql
select u.username, r.role_code, r.role_name
from sys_user u
join fin_user_role ur on ur.user_id = u.user_id
join fin_role r on r.role_id = ur.role_id
where u.username = 'admin';

select *
from fin_role_permission
where role_id = '00000000-0000-0000-0000-000000010001';
```

系统管理员角色应有 `*` 权限。

### 9.4 会计期间不对

检查 `fin_account_set.enabled_period` 和 `fin_fiscal_period`：

```sql
select account_set_id, set_name, enabled_year, enabled_period
from fin_account_set
where status = 1 and del_flag = 0;

select period, start_date, end_date, status
from fin_fiscal_period
where account_set_id = '00000000-0000-0000-0000-000000000101'
order by period;
```

如果年份或启用月份错误，应在没有业务凭证、期初和导入数据前修正。已有业务数据后，不要直接删除期间，应先确认财务影响。
