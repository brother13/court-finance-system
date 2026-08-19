-- 诉讼费账套已审核凭证错误父科目直接纠正
-- 建议先执行：2026-07-31-fix-litigation-fee-voucher-subject.sql
-- 目标账套：court-finance 中唯一启用的 LITIGATION_FEE 账套
-- 错误科目：100201 诉讼费专户
-- 正确科目：10020101 诉讼费
--
-- 用户确认处理口径：
-- 1. 仅直接修改本账套 2026-05 期间 AUDITED 凭证中的错误借方分录；
-- 2. 不反审核，不改变凭证状态、审核人、审核时间和打印标记；
-- 3. 金额、方向、摘要、辅助核算和值均不改变；
-- 4. 每张凭证写入 CORRECT_SUBJECT 审计日志；
-- 5. 补丁可重复执行，第二次执行不再产生修改和重复日志。
--
-- 适用数据库：MySQL 8.0。

use `court-finance`;

set names utf8mb4 collate utf8mb4_unicode_ci;
set @patch_tag = '2026-07-31-correct-audited-litigation-fee-voucher-subject';
set @account_set_id = (
    select min(account_set_id)
    from fin_account_set
    where biz_type = 'LITIGATION_FEE'
      and status = 1
      and del_flag = 0
);
set @period = '2026-05';
set @source_subject_code = '100201';
set @target_subject_code = '10020101';
set @operator_id = 'system';
set @patch_time = current_timestamp;

drop temporary table if exists tmp_20260731_audited_guard;
create temporary table tmp_20260731_audited_guard (
    check_name varchar(100) not null primary key,
    ok_flag tinyint not null,
    constraint chk_tmp_20260731_audited_guard_ok check (ok_flag = 1)
) engine=InnoDB;

insert into tmp_20260731_audited_guard (check_name, ok_flag)
select 'account_set',
       if(count(*) = 1 and min(account_set_id) = @account_set_id, 1, 0)
from fin_account_set
where biz_type = 'LITIGATION_FEE'
  and status = 1
  and del_flag = 0;

insert into tmp_20260731_audited_guard (check_name, ok_flag)
select 'source_subject_exists',
       if(count(*) = 1, 1, 0)
from fin_subject
where account_set_id = @account_set_id
  and subject_code = @source_subject_code
  and status = 1
  and del_flag = 0;

insert into tmp_20260731_audited_guard (check_name, ok_flag)
select 'target_subject_leaf',
       if(count(*) = 1, 1, 0)
from fin_subject
where account_set_id = @account_set_id
  and subject_code = @target_subject_code
  and parent_code = @source_subject_code
  and status = 1
  and del_flag = 0;

insert into tmp_20260731_audited_guard (check_name, ok_flag)
select 'target_subject_has_no_active_children',
       if(count(*) = 0, 1, 0)
from fin_subject
where account_set_id = @account_set_id
  and parent_code = @target_subject_code
  and del_flag = 0;

drop temporary table if exists tmp_20260731_audited_voucher_before;
create temporary table tmp_20260731_audited_voucher_before
engine=InnoDB
as
select distinct v.voucher_id,
       v.fiscal_year,
       v.period,
       v.voucher_no,
       v.voucher_word,
       v.status,
       v.source_type,
       v.printed_flag,
       v.prepared_by,
       v.audit_by,
       v.audit_time,
       v.version
from fin_voucher v
join fin_voucher_detail d
  on d.account_set_id = v.account_set_id
 and d.fiscal_year = v.fiscal_year
 and d.period = v.period
 and d.voucher_id = v.voucher_id
 and d.del_flag = 0
where v.account_set_id = @account_set_id
  and v.period = @period
  and v.status = 'AUDITED'
  and v.void_flag = '0'
  and v.del_flag = 0
  and d.subject_code = @source_subject_code;

alter table tmp_20260731_audited_voucher_before
    add primary key (voucher_id);

drop temporary table if exists tmp_20260731_audited_detail_before;
create temporary table tmp_20260731_audited_detail_before
engine=InnoDB
as
select d.voucher_id,
       count(*) as detail_count,
       sum(d.debit_amount) as debit_amount,
       sum(d.credit_amount) as credit_amount
from fin_voucher_detail d
join tmp_20260731_audited_voucher_before v
  on v.voucher_id = d.voucher_id
where d.account_set_id = @account_set_id
  and d.subject_code = @source_subject_code
  and d.del_flag = 0
group by d.voucher_id;

alter table tmp_20260731_audited_detail_before
    add primary key (voucher_id);

set @pending_voucher_count = (
    select count(*)
    from tmp_20260731_audited_voucher_before
);
set @pending_detail_count = (
    select coalesce(sum(detail_count), 0)
    from tmp_20260731_audited_detail_before
);
set @pending_debit_amount = (
    select coalesce(sum(debit_amount), 0.00)
    from tmp_20260731_audited_detail_before
);
set @pending_credit_amount = (
    select coalesce(sum(credit_amount), 0.00)
    from tmp_20260731_audited_detail_before
);

-- 按当前数据库实际待更正范围执行；已全部更正时允许安全重复执行。
insert into tmp_20260731_audited_guard (check_name, ok_flag)
values (
    'pending_scope',
    if(
        (
            @pending_voucher_count > 0
            and @pending_detail_count > 0
            and @pending_debit_amount > 0
            and @pending_credit_amount = 0
        )
        or (
            @pending_voucher_count = 0
            and @pending_detail_count = 0
        ),
        1,
        0
    )
);

insert into tmp_20260731_audited_guard (check_name, ok_flag)
select 'voucher_headers_unchanged_scope',
       if(count(*) = @pending_voucher_count, 1, 0)
from tmp_20260731_audited_voucher_before
where status = 'AUDITED';

insert into tmp_20260731_audited_guard (check_name, ok_flag)
select 'voucher_headers_balanced',
       if(count(*) = 0, 1, 0)
from fin_voucher v
join tmp_20260731_audited_voucher_before b
  on b.voucher_id = v.voucher_id
where v.account_set_id = @account_set_id
  and v.debit_amount <> v.credit_amount;

start transaction;

update fin_voucher_detail d
join tmp_20260731_audited_voucher_before v
  on v.voucher_id = d.voucher_id
set d.subject_code = @target_subject_code,
    d.updated_by = @operator_id,
    d.updated_time = @patch_time,
    d.version = d.version + 1
where d.account_set_id = @account_set_id
  and d.subject_code = @source_subject_code
  and d.del_flag = 0;
set @corrected_detail_count = row_count();

insert into sys_audit_log (
    log_id, account_set_id, biz_type, biz_id, operation,
    before_json, after_json, operator_id, operator_ip, created_time
)
select uuid(),
       @account_set_id,
       'VOUCHER',
       b.voucher_id,
       'CORRECT_SUBJECT',
       json_object(
           'patch', @patch_tag,
           'period', b.period,
           'voucher_word', b.voucher_word,
           'voucher_no', b.voucher_no,
           'status', b.status,
           'printed_flag', b.printed_flag,
           'audit_by', b.audit_by,
           'audit_time', b.audit_time,
           'source_subject_code', @source_subject_code,
           'detail_count', s.detail_count,
           'debit_amount', s.debit_amount,
           'credit_amount', s.credit_amount
       ),
       json_object(
           'patch', @patch_tag,
           'period', b.period,
           'voucher_word', b.voucher_word,
           'voucher_no', b.voucher_no,
           'status', b.status,
           'printed_flag', b.printed_flag,
           'audit_by', b.audit_by,
           'audit_time', b.audit_time,
           'target_subject_code', @target_subject_code,
           'detail_count', s.detail_count,
           'debit_amount', s.debit_amount,
           'credit_amount', s.credit_amount
       ),
       @operator_id,
       'SQL_PATCH',
       @patch_time
from tmp_20260731_audited_voucher_before b
join tmp_20260731_audited_detail_before s
  on s.voucher_id = b.voucher_id
where not exists (
    select 1
    from sys_audit_log old_log
    where old_log.account_set_id = @account_set_id
      and old_log.biz_type = 'VOUCHER'
      and old_log.biz_id = b.voucher_id
      and old_log.operation = 'CORRECT_SUBJECT'
      and old_log.operator_ip = 'SQL_PATCH'
      and json_unquote(json_extract(old_log.after_json, '$.patch')) = @patch_tag
);

-- 事务内复核：数量、科目、借贷或凭证头状态任一异常即触发 CHECK 约束失败并回滚。
insert into tmp_20260731_audited_guard (check_name, ok_flag)
values (
    'corrected_detail_count',
    if(@corrected_detail_count = @pending_detail_count, 1, 0)
);

insert into tmp_20260731_audited_guard (check_name, ok_flag)
select 'source_subject_removed',
       if(count(*) = 0, 1, 0)
from fin_voucher_detail d
join tmp_20260731_audited_voucher_before b
  on b.voucher_id = d.voucher_id
where d.account_set_id = @account_set_id
  and d.subject_code = @source_subject_code
  and d.del_flag = 0;

insert into tmp_20260731_audited_guard (check_name, ok_flag)
select 'target_subject_detail_count',
       if(count(*) >= @pending_detail_count, 1, 0)
from fin_voucher_detail d
join tmp_20260731_audited_voucher_before b
  on b.voucher_id = d.voucher_id
where d.account_set_id = @account_set_id
  and d.subject_code = @target_subject_code
  and d.del_flag = 0;

insert into tmp_20260731_audited_guard (check_name, ok_flag)
select 'voucher_headers_still_audited',
       if(count(*) = @pending_voucher_count, 1, 0)
from fin_voucher v
join tmp_20260731_audited_voucher_before b
  on b.voucher_id = v.voucher_id
where v.account_set_id = @account_set_id
  and v.status <=> b.status
  and v.printed_flag <=> b.printed_flag
  and v.audit_by <=> b.audit_by
  and v.audit_time <=> b.audit_time
  and v.version <=> b.version
  and v.del_flag = 0;

insert into tmp_20260731_audited_guard (check_name, ok_flag)
select 'voucher_final_balance',
       if(count(*) = 0, 1, 0)
from fin_voucher v
join tmp_20260731_audited_voucher_before b
  on b.voucher_id = v.voucher_id
where v.account_set_id = @account_set_id
  and v.debit_amount <> v.credit_amount;

commit;

select @patch_tag as patch_tag,
       @pending_voucher_count as unchanged_audited_voucher_count,
       @corrected_detail_count as corrected_detail_count,
       @pending_debit_amount as corrected_debit_amount,
       @pending_credit_amount as corrected_credit_amount;

select v.status,
       v.printed_flag,
       count(distinct v.voucher_id) as voucher_count,
       count(*) as corrected_detail_count,
       sum(d.debit_amount) as debit_amount,
       sum(d.credit_amount) as credit_amount
from fin_voucher_detail d
join fin_voucher v
  on v.account_set_id = d.account_set_id
 and v.fiscal_year = d.fiscal_year
 and v.period = d.period
 and v.voucher_id = d.voucher_id
 and v.del_flag = 0
where d.account_set_id = @account_set_id
  and d.period = @period
  and d.subject_code = @target_subject_code
  and d.del_flag = 0
group by v.status, v.printed_flag
order by v.status, v.printed_flag;

drop temporary table if exists tmp_20260731_audited_detail_before;
drop temporary table if exists tmp_20260731_audited_voucher_before;
drop temporary table if exists tmp_20260731_audited_guard;
