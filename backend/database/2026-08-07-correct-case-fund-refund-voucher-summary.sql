-- 案款账套 2026 年 5 月出账凭证摘要更正
-- 数据库：court-finance-txfy
-- 更正口径：普通出账摘要由“付 + 案号 + 当事人”改为“付 + 案号 + 实际收款人”。
--
-- 财务控制：
-- 1. 仅处理 CASE_FUND_2026 账套、2026-05 期间、BUSINESS 来源的未审核未打印凭证；
-- 2. 仅修改凭证头和凭证明细的 summary，以及对应更新元数据；
-- 3. 不修改凭证号、科目、借贷金额、辅助核算、审核/记账/打印状态；
-- 4. 使用“凭证 ID + 旧摘要 + 退款金额”精确匹配，歧义或数量不符时自动中止；
-- 5. 每张受影响凭证写入 CORRECT_REFUND_SUMMARY 审计日志；
-- 6. 补丁可重复执行，已经完整执行时再次运行不会重复修改或重复写日志。
--
-- 本补丁执行前已核对的固定范围：
-- - 出账记录 873 条、业务凭证 15 张；
-- - 实际需要更正的出账记录 320 条，涉及凭证 14 张；
-- - 更正凭证明细 640 条，借方/贷方金额各 3,514,319.84 元；
-- - 同步更正凭证头 4 张。
--
-- 适用数据库：MySQL 8.0。

use `court-finance-txfy`;

set names utf8mb4 collate utf8mb4_unicode_ci;
set @patch_tag = '2026-08-07-correct-case-fund-refund-voucher-summary';
set @expected_account_set_id = '00000000-0000-0000-0000-000000000101';
set @fiscal_year = 2026;
set @period = '2026-05';
set @operator_id = 'system';
set @patch_time = current_timestamp;

set @account_set_id = (
    select min(account_set_id)
    from fin_account_set
    where biz_type = 'CASE_FUND'
      and status = 1
      and del_flag = 0
);

set @existing_patch_log_count = (
    select count(*)
    from sys_audit_log
    where account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
      and biz_type = 'VOUCHER'
      and operation = 'CORRECT_REFUND_SUMMARY'
      and operator_ip = 'SQL_PATCH'
      and json_unquote(json_extract(after_json, '$.patch')) = @patch_tag
);
set @already_applied = if(@existing_patch_log_count = 14, 1, 0);

drop temporary table if exists tmp_20260807_refund_summary_guard;
create temporary table tmp_20260807_refund_summary_guard (
    check_name varchar(100) not null primary key,
    ok_flag tinyint not null,
    constraint chk_tmp_20260807_refund_summary_guard_ok check (ok_flag = 1)
) engine=InnoDB;

-- 数据库、账套和期间保护。
insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
values (
    'database_name',
    if(database() = 'court-finance-txfy', 1, 0)
);

insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
select 'single_case_fund_account_set',
       if(
           count(*) = 1
           and min(account_set_id) collate utf8mb4_unicode_ci = @expected_account_set_id collate utf8mb4_unicode_ci
           and min(account_set_id) collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci,
           1,
           0
       )
from fin_account_set
where biz_type = 'CASE_FUND'
  and status = 1
  and del_flag = 0;

insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
select 'account_set_identity',
       if(count(*) = 1, 1, 0)
from fin_account_set
where account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
  and set_code = 'CASE_FUND_2026'
  and set_name = '案款账套'
  and biz_type = 'CASE_FUND'
  and status = 1
  and del_flag = 0;

insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
select 'period_open',
       if(count(*) = 1, 1, 0)
from fin_fiscal_period
where account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
  and period collate utf8mb4_unicode_ci = @period collate utf8mb4_unicode_ci
  and status = 'OPEN'
  and del_flag = 0;

insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
values (
    'existing_patch_log_scope',
    if(@existing_patch_log_count in (0, 14), 1, 0)
);

-- 固化业务源范围，确保没有遗漏无效关联或空实际收款人。
set @source_refund_count = (
    select count(*)
    from fin_case_fund_refund r
    where r.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
      and r.fiscal_year = @fiscal_year
      and r.period collate utf8mb4_unicode_ci = @period collate utf8mb4_unicode_ci
      and r.voucher_status = 'GENERATED'
      and r.voucher_id is not null
      and r.del_flag = 0
);

set @valid_refund_count = (
    select count(*)
    from fin_case_fund_refund r
    join fin_voucher v
      on v.account_set_id collate utf8mb4_unicode_ci = r.account_set_id collate utf8mb4_unicode_ci
     and v.fiscal_year = r.fiscal_year
     and v.period collate utf8mb4_unicode_ci = r.period collate utf8mb4_unicode_ci
     and v.voucher_id collate utf8mb4_unicode_ci = r.voucher_id collate utf8mb4_unicode_ci
     and v.del_flag = 0
    where r.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
      and r.fiscal_year = @fiscal_year
      and r.period collate utf8mb4_unicode_ci = @period collate utf8mb4_unicode_ci
      and r.voucher_status = 'GENERATED'
      and r.del_flag = 0
);

set @scope_voucher_count = (
    select count(distinct v.voucher_id)
    from fin_case_fund_refund r
    join fin_voucher v
      on v.account_set_id collate utf8mb4_unicode_ci = r.account_set_id collate utf8mb4_unicode_ci
     and v.fiscal_year = r.fiscal_year
     and v.period collate utf8mb4_unicode_ci = r.period collate utf8mb4_unicode_ci
     and v.voucher_id collate utf8mb4_unicode_ci = r.voucher_id collate utf8mb4_unicode_ci
     and v.del_flag = 0
    where r.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
      and r.fiscal_year = @fiscal_year
      and r.period collate utf8mb4_unicode_ci = @period collate utf8mb4_unicode_ci
      and r.voucher_status = 'GENERATED'
      and r.del_flag = 0
);

set @invalid_voucher_state_count = (
    select count(distinct v.voucher_id)
    from fin_case_fund_refund r
    join fin_voucher v
      on v.account_set_id collate utf8mb4_unicode_ci = r.account_set_id collate utf8mb4_unicode_ci
     and v.fiscal_year = r.fiscal_year
     and v.period collate utf8mb4_unicode_ci = r.period collate utf8mb4_unicode_ci
     and v.voucher_id collate utf8mb4_unicode_ci = r.voucher_id collate utf8mb4_unicode_ci
     and v.del_flag = 0
    where r.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
      and r.fiscal_year = @fiscal_year
      and r.period collate utf8mb4_unicode_ci = @period collate utf8mb4_unicode_ci
      and r.voucher_status = 'GENERATED'
      and r.del_flag = 0
      and (
          v.source_type <> 'BUSINESS'
          or v.status <> 'SUBMITTED'
          or v.printed_flag <> '0'
          or v.void_flag <> '0'
      )
);

set @missing_actual_payee_count = (
    select count(*)
    from fin_case_fund_refund r
    where r.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
      and r.fiscal_year = @fiscal_year
      and r.period collate utf8mb4_unicode_ci = @period collate utf8mb4_unicode_ci
      and r.voucher_status = 'GENERATED'
      and r.del_flag = 0
      and (r.actual_payee_name is null or trim(r.actual_payee_name) = '')
);

insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
values (
    'fixed_source_scope',
    if(
        @source_refund_count = 873
        and @valid_refund_count = 873
        and @scope_voucher_count = 15
        and @invalid_voucher_state_count = 0
        and @missing_actual_payee_count = 0,
        1,
        0
    )
);

-- 生成“旧摘要 + 金额 -> 新摘要”映射；金额是同案号同当事人存在多个实际收款人时的消歧键。
drop temporary table if exists tmp_20260807_refund_summary_map;
create temporary table tmp_20260807_refund_summary_map
engine=InnoDB
as
select r.voucher_id collate utf8mb4_unicode_ci as voucher_id,
       trim(concat(
           '付 ',
           coalesce(r.case_no, ''),
           ' ',
           coalesce(r.party_name, '')
       )) collate utf8mb4_unicode_ci as old_summary,
       min(trim(concat(
           '付 ',
           coalesce(r.case_no, ''),
           ' ',
           coalesce(r.actual_payee_name, '')
       )) collate utf8mb4_unicode_ci) as new_summary,
       r.refund_amount,
       count(*) as refund_count,
       count(distinct trim(concat(
           '付 ',
           coalesce(r.case_no, ''),
           ' ',
           coalesce(r.actual_payee_name, '')
       )) collate utf8mb4_unicode_ci) as target_count
from fin_case_fund_refund r
join fin_voucher v
  on v.account_set_id collate utf8mb4_unicode_ci = r.account_set_id collate utf8mb4_unicode_ci
 and v.fiscal_year = r.fiscal_year
 and v.period collate utf8mb4_unicode_ci = r.period collate utf8mb4_unicode_ci
 and v.voucher_id collate utf8mb4_unicode_ci = r.voucher_id collate utf8mb4_unicode_ci
 and v.source_type = 'BUSINESS'
 and v.status = 'SUBMITTED'
 and v.printed_flag = '0'
 and v.void_flag = '0'
 and v.del_flag = 0
where r.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
  and r.fiscal_year = @fiscal_year
  and r.period collate utf8mb4_unicode_ci = @period collate utf8mb4_unicode_ci
  and r.voucher_status = 'GENERATED'
  and r.actual_payee_name is not null
  and trim(r.actual_payee_name) <> ''
  and r.del_flag = 0
group by r.voucher_id, old_summary, r.refund_amount
having old_summary <> new_summary;

alter table tmp_20260807_refund_summary_map
    add primary key (voucher_id, old_summary, refund_amount);

set @mapping_count = (
    select count(*)
    from tmp_20260807_refund_summary_map
);
set @changed_refund_count = (
    select coalesce(sum(refund_count), 0)
    from tmp_20260807_refund_summary_map
);
set @mapping_voucher_count = (
    select count(distinct voucher_id)
    from tmp_20260807_refund_summary_map
);
set @ambiguous_mapping_count = (
    select count(*)
    from tmp_20260807_refund_summary_map
    where target_count <> 1
);

insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
values (
    'fixed_summary_mapping_scope',
    if(
        @mapping_count = 320
        and @changed_refund_count = 320
        and @mapping_voucher_count = 14
        and @ambiguous_mapping_count = 0,
        1,
        0
    )
);

-- 精确快照本次要更正的凭证明细；汇总摘要不会匹配此映射。
drop temporary table if exists tmp_20260807_refund_detail_before;
create temporary table tmp_20260807_refund_detail_before
engine=InnoDB
as
select d.detail_id collate utf8mb4_unicode_ci as detail_id,
       d.voucher_id collate utf8mb4_unicode_ci as voucher_id,
       d.line_no,
       d.summary collate utf8mb4_unicode_ci as old_summary,
       m.new_summary collate utf8mb4_unicode_ci as new_summary,
       d.subject_code,
       d.debit_amount,
       d.credit_amount,
       d.verification_status,
       d.aux_desc,
       d.version
from fin_voucher_detail d
join tmp_20260807_refund_summary_map m
  on m.voucher_id = d.voucher_id collate utf8mb4_unicode_ci
 and m.old_summary = d.summary collate utf8mb4_unicode_ci
 and m.refund_amount = case
     when d.debit_amount > 0 then d.debit_amount
     else d.credit_amount
 end
where d.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
  and d.fiscal_year = @fiscal_year
  and d.period collate utf8mb4_unicode_ci = @period collate utf8mb4_unicode_ci
  and d.del_flag = 0
  and @already_applied = 0;

alter table tmp_20260807_refund_detail_before
    add primary key (detail_id),
    add index idx_tmp_20260807_refund_detail_voucher (voucher_id);

set @pending_detail_count = (
    select count(*)
    from tmp_20260807_refund_detail_before
);
set @pending_debit_amount = (
    select coalesce(sum(debit_amount), 0.00)
    from tmp_20260807_refund_detail_before
);
set @pending_credit_amount = (
    select coalesce(sum(credit_amount), 0.00)
    from tmp_20260807_refund_detail_before
);
set @detail_mapping_mismatch_count = (
    select count(*)
    from (
        select m.voucher_id,
               m.old_summary,
               m.refund_amount,
               m.refund_count,
               count(distinct d.detail_id) as detail_count
        from tmp_20260807_refund_summary_map m
        left join fin_voucher_detail d
          on d.voucher_id collate utf8mb4_unicode_ci = m.voucher_id
         and d.summary collate utf8mb4_unicode_ci = m.old_summary
         and m.refund_amount = case
             when d.debit_amount > 0 then d.debit_amount
             else d.credit_amount
         end
         and d.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
         and d.fiscal_year = @fiscal_year
         and d.period collate utf8mb4_unicode_ci = @period collate utf8mb4_unicode_ci
         and d.del_flag = 0
        group by m.voucher_id, m.old_summary, m.refund_amount, m.refund_count
        having detail_count <> m.refund_count * 2
    ) mismatch
);

insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
values (
    'pending_detail_scope',
    if(
        @already_applied = 1
        or (
            @pending_detail_count = 640
            and @pending_debit_amount = 3514319.84
            and @pending_credit_amount = 3514319.84
            and @detail_mapping_mismatch_count = 0
        ),
        1,
        0
    )
);

-- 仅当凭证头等于第一条分录旧摘要时同步凭证头。
drop temporary table if exists tmp_20260807_refund_header_before;
create temporary table tmp_20260807_refund_header_before
engine=InnoDB
as
select v.voucher_id collate utf8mb4_unicode_ci as voucher_id,
       v.summary collate utf8mb4_unicode_ci as old_summary,
       m.new_summary collate utf8mb4_unicode_ci as new_summary
from fin_voucher v
join fin_voucher_detail d
  on d.account_set_id collate utf8mb4_unicode_ci = v.account_set_id collate utf8mb4_unicode_ci
 and d.fiscal_year = v.fiscal_year
 and d.period collate utf8mb4_unicode_ci = v.period collate utf8mb4_unicode_ci
 and d.voucher_id collate utf8mb4_unicode_ci = v.voucher_id collate utf8mb4_unicode_ci
 and d.line_no = 1
 and d.del_flag = 0
join tmp_20260807_refund_summary_map m
  on m.voucher_id = d.voucher_id collate utf8mb4_unicode_ci
 and m.old_summary = d.summary collate utf8mb4_unicode_ci
 and m.refund_amount = case
     when d.debit_amount > 0 then d.debit_amount
     else d.credit_amount
 end
where v.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
  and v.fiscal_year = @fiscal_year
  and v.period collate utf8mb4_unicode_ci = @period collate utf8mb4_unicode_ci
  and v.summary collate utf8mb4_unicode_ci = d.summary collate utf8mb4_unicode_ci
  and v.del_flag = 0
  and @already_applied = 0;

alter table tmp_20260807_refund_header_before
    add primary key (voucher_id);

set @pending_header_count = (
    select count(*)
    from tmp_20260807_refund_header_before
);

insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
values (
    'pending_header_scope',
    if(@already_applied = 1 or @pending_header_count = 4, 1, 0)
);

-- 固化受影响凭证的财务状态与金额，用于事务后逐项复核。
drop temporary table if exists tmp_20260807_refund_voucher_before;
create temporary table tmp_20260807_refund_voucher_before
engine=InnoDB
as
select v.voucher_id collate utf8mb4_unicode_ci as voucher_id,
       v.fiscal_year,
       v.period,
       v.voucher_date,
       v.voucher_word,
       v.voucher_no,
       v.summary collate utf8mb4_unicode_ci as old_summary,
       coalesce(h.new_summary, v.summary collate utf8mb4_unicode_ci) as new_summary,
       v.debit_amount,
       v.credit_amount,
       v.status,
       v.source_type,
       v.printed_flag,
       v.audit_by,
       v.audit_time,
       v.posted_by,
       v.posted_time,
       v.void_flag,
       v.version,
       count(b.detail_id) as changed_detail_count,
       sum(b.debit_amount) as changed_debit_amount,
       sum(b.credit_amount) as changed_credit_amount
from fin_voucher v
join tmp_20260807_refund_detail_before b
  on b.voucher_id = v.voucher_id collate utf8mb4_unicode_ci
left join tmp_20260807_refund_header_before h
  on h.voucher_id = v.voucher_id collate utf8mb4_unicode_ci
where v.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
  and v.fiscal_year = @fiscal_year
  and v.period collate utf8mb4_unicode_ci = @period collate utf8mb4_unicode_ci
  and v.del_flag = 0
group by v.voucher_id, v.fiscal_year, v.period, v.voucher_date,
         v.voucher_word, v.voucher_no, v.summary, h.new_summary,
         v.debit_amount, v.credit_amount, v.status, v.source_type,
         v.printed_flag, v.audit_by, v.audit_time, v.posted_by,
         v.posted_time, v.void_flag, v.version;

alter table tmp_20260807_refund_voucher_before
    add primary key (voucher_id);

set @pending_voucher_count = (
    select count(*)
    from tmp_20260807_refund_voucher_before
);

insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
values (
    'pending_voucher_scope',
    if(@already_applied = 1 or @pending_voucher_count = 14, 1, 0)
);

insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
select 'voucher_balance_before',
       if(count(*) = 0, 1, 0)
from tmp_20260807_refund_voucher_before
where debit_amount <> credit_amount;

-- 预先聚合审计明细，避免 MySQL 在同一语句中重复打开临时明细表。
drop temporary table if exists tmp_20260807_refund_audit_payload;
create temporary table tmp_20260807_refund_audit_payload
engine=InnoDB
as
select d.voucher_id,
       json_arrayagg(json_object(
           'detail_id', d.detail_id,
           'line_no', d.line_no,
           'subject_code', d.subject_code,
           'summary', d.old_summary,
           'debit_amount', d.debit_amount,
           'credit_amount', d.credit_amount
       )) as before_details,
       json_arrayagg(json_object(
           'detail_id', d.detail_id,
           'line_no', d.line_no,
           'subject_code', d.subject_code,
           'summary', d.new_summary,
           'debit_amount', d.debit_amount,
           'credit_amount', d.credit_amount
       )) as after_details
from tmp_20260807_refund_detail_before d
group by d.voucher_id;

alter table tmp_20260807_refund_audit_payload
    add primary key (voucher_id);

start transaction;

update fin_voucher_detail d
join tmp_20260807_refund_detail_before b
  on b.detail_id = d.detail_id collate utf8mb4_unicode_ci
set d.summary = b.new_summary,
    d.updated_by = @operator_id,
    d.updated_time = @patch_time,
    d.version = d.version + 1
where d.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
  and d.fiscal_year = @fiscal_year
  and d.period collate utf8mb4_unicode_ci = @period collate utf8mb4_unicode_ci
  and d.summary collate utf8mb4_unicode_ci = b.old_summary
  and d.del_flag = 0
  and @already_applied = 0;
set @updated_detail_count = row_count();

-- 受影响凭证统一更新版本；仅4张首条分录变化的凭证会同时改变凭证头摘要。
update fin_voucher v
join tmp_20260807_refund_voucher_before b
  on b.voucher_id = v.voucher_id collate utf8mb4_unicode_ci
set v.summary = b.new_summary,
    v.updated_by = @operator_id,
    v.updated_time = @patch_time,
    v.version = v.version + 1
where v.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
  and v.fiscal_year = @fiscal_year
  and v.period collate utf8mb4_unicode_ci = @period collate utf8mb4_unicode_ci
  and v.status = 'SUBMITTED'
  and v.printed_flag = '0'
  and v.void_flag = '0'
  and v.del_flag = 0
  and @already_applied = 0;
set @updated_voucher_count = row_count();

insert into sys_audit_log (
    log_id, account_set_id, biz_type, biz_id, operation,
    before_json, after_json, operator_id, operator_ip, created_time
)
select uuid(),
       @account_set_id,
       'VOUCHER',
       b.voucher_id,
       'CORRECT_REFUND_SUMMARY',
       json_object(
           'patch', @patch_tag,
           'period', b.period,
           'voucher_word', b.voucher_word,
           'voucher_no', b.voucher_no,
           'status', b.status,
           'printed_flag', b.printed_flag,
           'header_summary', b.old_summary,
           'changed_detail_count', b.changed_detail_count,
           'changed_debit_amount', b.changed_debit_amount,
           'changed_credit_amount', b.changed_credit_amount,
           'detail_changes', cast(p.before_details as json)
       ),
       json_object(
           'patch', @patch_tag,
           'period', b.period,
           'voucher_word', b.voucher_word,
           'voucher_no', b.voucher_no,
           'status', b.status,
           'printed_flag', b.printed_flag,
           'header_summary', b.new_summary,
           'changed_detail_count', b.changed_detail_count,
           'changed_debit_amount', b.changed_debit_amount,
           'changed_credit_amount', b.changed_credit_amount,
           'detail_changes', cast(p.after_details as json)
       ),
       @operator_id,
       'SQL_PATCH',
       @patch_time
from tmp_20260807_refund_voucher_before b
join tmp_20260807_refund_audit_payload p
  on p.voucher_id = b.voucher_id
where @already_applied = 0
  and not exists (
      select 1
      from sys_audit_log old_log
      where old_log.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
        and old_log.biz_type = 'VOUCHER'
        and old_log.biz_id collate utf8mb4_unicode_ci = b.voucher_id
        and old_log.operation = 'CORRECT_REFUND_SUMMARY'
        and old_log.operator_ip = 'SQL_PATCH'
        and json_unquote(json_extract(old_log.after_json, '$.patch')) = @patch_tag
  );
set @inserted_audit_count = row_count();

-- 事务内数量保护。
insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
values (
    'updated_detail_count',
    if(
        @updated_detail_count = if(@already_applied = 1, 0, 640),
        1,
        0
    )
);

insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
values (
    'updated_voucher_count',
    if(
        @updated_voucher_count = if(@already_applied = 1, 0, 14),
        1,
        0
    )
);

insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
values (
    'inserted_audit_count',
    if(
        @inserted_audit_count = if(@already_applied = 1, 0, 14),
        1,
        0
    )
);

-- 逐行确认只改变了摘要与更新元数据，金额、科目、辅助描述和核销状态均未变化。
insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
select 'detail_final_state',
       if(count(*) = if(@already_applied = 1, 0, 640), 1, 0)
from fin_voucher_detail d
join tmp_20260807_refund_detail_before b
  on b.detail_id = d.detail_id collate utf8mb4_unicode_ci
where d.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
  and d.fiscal_year = @fiscal_year
  and d.period collate utf8mb4_unicode_ci = @period collate utf8mb4_unicode_ci
  and d.summary collate utf8mb4_unicode_ci = b.new_summary
  and d.subject_code <=> b.subject_code
  and d.debit_amount <=> b.debit_amount
  and d.credit_amount <=> b.credit_amount
  and d.verification_status <=> b.verification_status
  and d.aux_desc <=> b.aux_desc
  and d.version = b.version + 1
  and d.del_flag = 0;

-- 逐张确认凭证号、金额及所有流程状态均保持不变。
insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
select 'voucher_final_state',
       if(count(*) = if(@already_applied = 1, 0, 14), 1, 0)
from fin_voucher v
join tmp_20260807_refund_voucher_before b
  on b.voucher_id = v.voucher_id collate utf8mb4_unicode_ci
where v.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
  and v.fiscal_year <=> b.fiscal_year
  and v.period collate utf8mb4_unicode_ci = b.period collate utf8mb4_unicode_ci
  and v.voucher_date <=> b.voucher_date
  and v.voucher_word <=> b.voucher_word
  and v.voucher_no <=> b.voucher_no
  and v.summary collate utf8mb4_unicode_ci = b.new_summary
  and v.debit_amount <=> b.debit_amount
  and v.credit_amount <=> b.credit_amount
  and v.status <=> b.status
  and v.source_type <=> b.source_type
  and v.printed_flag <=> b.printed_flag
  and v.audit_by <=> b.audit_by
  and v.audit_time <=> b.audit_time
  and v.posted_by <=> b.posted_by
  and v.posted_time <=> b.posted_time
  and v.void_flag <=> b.void_flag
  and v.version = b.version + 1
  and v.del_flag = 0;

insert into tmp_20260807_refund_summary_guard (check_name, ok_flag)
select 'voucher_balance_after',
       if(count(*) = 0, 1, 0)
from fin_voucher v
join tmp_20260807_refund_voucher_before b
  on b.voucher_id = v.voucher_id collate utf8mb4_unicode_ci
where v.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
  and v.debit_amount <> v.credit_amount;

commit;

-- 执行结果与对账结果。
select @patch_tag as patch_tag,
       @already_applied as already_applied,
       @source_refund_count as source_refund_count,
       @scope_voucher_count as source_voucher_count,
       @changed_refund_count as changed_refund_count,
       @updated_detail_count as updated_detail_count,
       @updated_voucher_count as updated_voucher_count,
       @pending_header_count as updated_header_count,
       @pending_debit_amount as corrected_debit_amount,
       @pending_credit_amount as corrected_credit_amount,
       @inserted_audit_count as inserted_audit_count;

select v.voucher_no,
       v.voucher_date,
       v.status,
       v.printed_flag,
       v.summary as corrected_header_summary,
       b.changed_detail_count,
       b.changed_debit_amount,
       b.changed_credit_amount
from fin_voucher v
join tmp_20260807_refund_voucher_before b
  on b.voucher_id = v.voucher_id collate utf8mb4_unicode_ci
where v.account_set_id collate utf8mb4_unicode_ci = @account_set_id collate utf8mb4_unicode_ci
  and v.del_flag = 0
order by v.voucher_date, v.voucher_no;

drop temporary table if exists tmp_20260807_refund_voucher_before;
drop temporary table if exists tmp_20260807_refund_audit_payload;
drop temporary table if exists tmp_20260807_refund_header_before;
drop temporary table if exists tmp_20260807_refund_detail_before;
drop temporary table if exists tmp_20260807_refund_summary_map;
drop temporary table if exists tmp_20260807_refund_summary_guard;
