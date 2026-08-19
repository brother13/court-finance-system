-- 不明款退汇识别：独立关联表、权限及默认角色授权。
-- 原不明款认领状态和出账状态不做任何修改。

create table if not exists fin_case_fund_unknown_refund_match (
    match_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    fiscal_year int not null,
    period varchar(7) not null,
    unknown_payment_id varchar(36) not null,
    refund_id varchar(36) not null,
    match_status varchar(30) not null default 'AUTO_CANDIDATE',
    match_rule varchar(60) not null default 'SAME_MONTH_NAME_AMOUNT_ACCOUNT',
    candidate_count int not null default 1,
    match_evidence_json text,
    decision_by varchar(36),
    decision_time datetime,
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500),
    unique key uk_case_fund_unknown_refund_pair (account_set_id, unknown_payment_id, refund_id),
    key idx_case_fund_return_match_unknown (account_set_id, fiscal_year, unknown_payment_id, del_flag, match_status),
    key idx_case_fund_return_match_refund (account_set_id, fiscal_year, refund_id, del_flag, match_status),
    key idx_case_fund_return_match_period (account_set_id, fiscal_year, period, del_flag, match_status)
);

insert into fin_permission (
    permission_code, permission_name, permission_type, module_code, description, sort_order
) values (
    'case_fund:return_match', '案款退汇识别', 2, 'case_fund',
    '识别、确认、排除和取消不明款与出账记录的退汇关联', 257
)
on duplicate key update
    permission_name = values(permission_name),
    permission_type = values(permission_type),
    module_code = values(module_code),
    description = values(description),
    sort_order = values(sort_order);

insert ignore into fin_role_permission (role_id, permission_code)
select role_id, 'case_fund:return_match'
from fin_role
where role_code in ('finance_manager', 'voucher_maker');
