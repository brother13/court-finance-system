-- 案款业务权限幂等补丁
-- 用途：补齐已部署数据库中缺失的案款菜单、操作权限和角色授权。

insert into fin_permission (permission_code, permission_name, permission_type, module_code, description, sort_order) values
('menu:case_fund', '案款业务', 1, 'case_fund', '/case-fund/*', 35),
('menu:case_fund:payment', '缴费登记', 1, 'case_fund', '/case-fund/payments', 36),
('menu:case_fund:refund', '出账查询', 1, 'case_fund', '/case-fund/refunds', 37),
('menu:case_fund:bank_statement', '银行对账单', 1, 'case_fund', '/case-fund/bank-statements', 38),
('menu:case_fund:unknown_payment', '不明款', 1, 'case_fund', '/case-fund/unknown-payments', 39),
('menu:case_fund:distribution', '案款分配', 1, 'case_fund', '/case-fund/distributions', 40),
('case_fund:view', '案款业务查看', 2, 'case_fund', '查看案款缴费、退付、银行对账、不明款和案款分配数据', 251),
('case_fund:import', '案款业务导入', 2, 'case_fund', '导入案款缴费、退付、银行对账单、不明款和案款分配数据', 252),
('case_fund:generate_voucher', '案款生成凭证', 2, 'case_fund', '根据案款业务登记批量生成凭证', 253),
('case_fund:subject_config', '案款科目配置', 2, 'case_fund', '配置案款业务生成凭证的借贷科目', 254),
('case_fund:reconcile', '案款银行对账', 2, 'case_fund', '按银行流水号执行案款银行自动对账', 255),
('case_fund:delete', '案款业务删除', 2, 'case_fund', '删除未制证且未对账的案款业务数据', 256)
on duplicate key update
    permission_name = values(permission_name),
    permission_type = values(permission_type),
    module_code = values(module_code),
    description = values(description),
    sort_order = values(sort_order);

-- 财务主管、制单会计：案款业务完整操作权限。
insert ignore into fin_role_permission (role_id, permission_code)
select r.role_id, p.permission_code
from fin_role r
join (
    select 'menu:case_fund' as permission_code
    union all select 'menu:case_fund:payment'
    union all select 'menu:case_fund:refund'
    union all select 'menu:case_fund:bank_statement'
    union all select 'menu:case_fund:unknown_payment'
    union all select 'menu:case_fund:distribution'
    union all select 'case_fund:view'
    union all select 'case_fund:import'
    union all select 'case_fund:generate_voucher'
    union all select 'case_fund:subject_config'
    union all select 'case_fund:reconcile'
    union all select 'case_fund:delete'
) p
where r.role_code in ('finance_manager', 'voucher_maker');

-- 审核会计、记账会计：仅允许进入案款菜单并查看业务数据。
insert ignore into fin_role_permission (role_id, permission_code)
select r.role_id, p.permission_code
from fin_role r
join (
    select 'menu:case_fund' as permission_code
    union all select 'menu:case_fund:payment'
    union all select 'menu:case_fund:refund'
    union all select 'menu:case_fund:bank_statement'
    union all select 'menu:case_fund:unknown_payment'
    union all select 'menu:case_fund:distribution'
    union all select 'case_fund:view'
) p
where r.role_code in ('voucher_auditor', 'voucher_poster');
