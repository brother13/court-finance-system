-- 清理案款账套银行科目 10020102 错挂的案号、收据号辅助核算值
-- 数据库：court-finance-lzfy
--
-- 背景：
-- 2026-06-05 至 2026-06-10 期间生成的历史案款业务凭证，曾把借方/贷方业务科目的
-- 案号、收据号辅助值复制到未配置辅助核算的银行科目 10020102。该问题会让辅助核算
-- 余额表把银行分录也按案号统计，造成同一案号重复展示、余额绝对值重复累计。
--
-- 财务控制：
-- 1. 仅处理 CASE_FUND_2026 案款账套、10020102 执行款、指定历史生成窗口内的
--    case_no / receipt_no 辅助值；
-- 2. 执行前强制确认 10020102 当前没有任何有效辅助核算配置；
-- 3. 仅软删除错误辅助值、清空对应分录 aux_desc，并更新版本/审计元数据；
-- 4. 不修改凭证号、日期、摘要、科目、借贷金额、凭证状态、审核/记账/打印信息，
--    不修改缴费、退付等业务来源关联；
-- 5. 每张受影响凭证写入一条 CLEAN_INVALID_AUX_VALUES 审计日志；
-- 6. 补丁可重复执行：完成后再次执行应为安全空操作；
-- 7. 不直接删除余额快照。来源更新时间改变后，辅助余额页面下次查询会自动重建快照。
--
-- 2026-08-16 在 court-finance-lzfy 只读核对的固定范围：
-- - 181 张 BUSINESS / POSTED / 未作废凭证；
-- - 2,940 条 10020102 凭证明细；
-- - 5,880 条错误辅助值（2,940 条 case_no + 2,940 条 receipt_no）；
-- - 分录借方合计 64,870,281.14，贷方合计 39,490,783.46；
-- - 涉及期间 2026-01 至 2026-05；
-- - 辅助值创建时间范围 2026-06-05 17:35:46 至 2026-06-10 14:48:33。
--
-- 适用数据库：MySQL 5.7 / 8.0。
-- 执行要求：先备份数据库；以完整脚本方式执行；发生任何 SQL 错误时必须停止，
-- 不要使用 mysql --force，也不要在客户端中启用“出错后继续执行”。

use `court-finance-lzfy`;

set names utf8mb4 collate utf8mb4_unicode_ci;
set @patch_tag = '2026-08-16-clean-invalid-case-fund-bank-subject-aux-values';
set @expected_database = 'court-finance-lzfy';
set @account_set_id = '00000000-0000-0000-0000-000000000101';
set @account_set_code = 'CASE_FUND_2026';
set @subject_code = '10020102';
set @subject_name = '执行款';
set @window_start = '2026-06-05 00:00:00';
set @window_end = '2026-06-11 00:00:00';
set @operator_id = 'system';
set @patch_time = current_timestamp;

set @expected_aux_count = 5880;
set @expected_detail_count = 2940;
set @expected_voucher_count = 181;
set @expected_period_count = 5;
set @expected_debit_amount = 64870281.14;
set @expected_credit_amount = 39490783.46;

-- 已有补丁审计：首次执行必须为 0；完整执行后的重复运行必须为 181。
set @existing_patch_log_count = (
    select count(*)
    from sys_audit_log
    where account_set_id = @account_set_id
      and biz_type = 'VOUCHER'
      and operation = 'CLEAN_INVALID_AUX_VALUES'
      and operator_ip = 'SQL_PATCH'
      and json_unquote(json_extract(after_json, '$.patch')) = @patch_tag
);

set @active_config_count = (
    select count(*)
    from fin_subject_aux_config
    where account_set_id = @account_set_id
      and subject_code = @subject_code
      and del_flag = 0
);

-- 当前全部错误辅助值范围，不限定创建窗口。
set @all_active_target_aux_count = (
    select count(*)
    from fin_voucher_aux_value a
    join fin_voucher_detail d
      on d.account_set_id = a.account_set_id
     and d.fiscal_year = a.fiscal_year
     and d.period = a.period
     and d.detail_id = a.detail_id
     and d.del_flag = 0
    join fin_voucher v
      on v.account_set_id = d.account_set_id
     and v.fiscal_year = d.fiscal_year
     and v.period = d.period
     and v.voucher_id = d.voucher_id
     and v.del_flag = 0
    where a.account_set_id = @account_set_id
      and d.subject_code = @subject_code
      and a.aux_type_code in ('case_no', 'receipt_no')
      and a.del_flag = 0
);

-- 本补丁允许处理的固定历史生成窗口。
set @window_target_aux_count = (
    select count(*)
    from fin_voucher_aux_value a
    join fin_voucher_detail d
      on d.account_set_id = a.account_set_id
     and d.fiscal_year = a.fiscal_year
     and d.period = a.period
     and d.detail_id = a.detail_id
     and d.del_flag = 0
    join fin_voucher v
      on v.account_set_id = d.account_set_id
     and v.fiscal_year = d.fiscal_year
     and v.period = d.period
     and v.voucher_id = d.voucher_id
     and v.del_flag = 0
    where a.account_set_id = @account_set_id
      and d.subject_code = @subject_code
      and a.aux_type_code in ('case_no', 'receipt_no')
      and a.created_time >= @window_start
      and a.created_time < @window_end
      and a.del_flag = 0
);

set @target_detail_count = (
    select count(*)
    from (
        select d.detail_id
        from fin_voucher_aux_value a
        join fin_voucher_detail d
          on d.account_set_id = a.account_set_id
         and d.fiscal_year = a.fiscal_year
         and d.period = a.period
         and d.detail_id = a.detail_id
         and d.del_flag = 0
        join fin_voucher v
          on v.account_set_id = d.account_set_id
         and v.fiscal_year = d.fiscal_year
         and v.period = d.period
         and v.voucher_id = d.voucher_id
         and v.del_flag = 0
        where a.account_set_id = @account_set_id
          and d.subject_code = @subject_code
          and a.aux_type_code in ('case_no', 'receipt_no')
          and a.created_time >= @window_start
          and a.created_time < @window_end
          and a.del_flag = 0
        group by d.detail_id
    ) target_details
);

set @target_voucher_count = (
    select count(*)
    from (
        select v.voucher_id
        from fin_voucher_aux_value a
        join fin_voucher_detail d
          on d.account_set_id = a.account_set_id
         and d.fiscal_year = a.fiscal_year
         and d.period = a.period
         and d.detail_id = a.detail_id
         and d.del_flag = 0
        join fin_voucher v
          on v.account_set_id = d.account_set_id
         and v.fiscal_year = d.fiscal_year
         and v.period = d.period
         and v.voucher_id = d.voucher_id
         and v.del_flag = 0
        where a.account_set_id = @account_set_id
          and d.subject_code = @subject_code
          and a.aux_type_code in ('case_no', 'receipt_no')
          and a.created_time >= @window_start
          and a.created_time < @window_end
          and a.del_flag = 0
        group by v.voucher_id
    ) target_vouchers
);

set @target_period_count = (
    select count(*)
    from (
        select d.period
        from fin_voucher_aux_value a
        join fin_voucher_detail d
          on d.account_set_id = a.account_set_id
         and d.fiscal_year = a.fiscal_year
         and d.period = a.period
         and d.detail_id = a.detail_id
         and d.del_flag = 0
        join fin_voucher v
          on v.account_set_id = d.account_set_id
         and v.fiscal_year = d.fiscal_year
         and v.period = d.period
         and v.voucher_id = d.voucher_id
         and v.del_flag = 0
        where a.account_set_id = @account_set_id
          and d.subject_code = @subject_code
          and a.aux_type_code in ('case_no', 'receipt_no')
          and a.created_time >= @window_start
          and a.created_time < @window_end
          and a.del_flag = 0
        group by d.period
    ) target_periods
);

set @target_debit_amount = (
    select coalesce(sum(target_details.debit_amount), 0.00)
    from (
        select d.detail_id, d.debit_amount
        from fin_voucher_aux_value a
        join fin_voucher_detail d
          on d.account_set_id = a.account_set_id
         and d.fiscal_year = a.fiscal_year
         and d.period = a.period
         and d.detail_id = a.detail_id
         and d.del_flag = 0
        join fin_voucher v
          on v.account_set_id = d.account_set_id
         and v.fiscal_year = d.fiscal_year
         and v.period = d.period
         and v.voucher_id = d.voucher_id
         and v.del_flag = 0
        where a.account_set_id = @account_set_id
          and d.subject_code = @subject_code
          and a.aux_type_code in ('case_no', 'receipt_no')
          and a.created_time >= @window_start
          and a.created_time < @window_end
          and a.del_flag = 0
        group by d.detail_id, d.debit_amount
    ) target_details
);

set @target_credit_amount = (
    select coalesce(sum(target_details.credit_amount), 0.00)
    from (
        select d.detail_id, d.credit_amount
        from fin_voucher_aux_value a
        join fin_voucher_detail d
          on d.account_set_id = a.account_set_id
         and d.fiscal_year = a.fiscal_year
         and d.period = a.period
         and d.detail_id = a.detail_id
         and d.del_flag = 0
        join fin_voucher v
          on v.account_set_id = d.account_set_id
         and v.fiscal_year = d.fiscal_year
         and v.period = d.period
         and v.voucher_id = d.voucher_id
         and v.del_flag = 0
        where a.account_set_id = @account_set_id
          and d.subject_code = @subject_code
          and a.aux_type_code in ('case_no', 'receipt_no')
          and a.created_time >= @window_start
          and a.created_time < @window_end
          and a.del_flag = 0
        group by d.detail_id, d.credit_amount
    ) target_details
);

-- 每条目标分录必须恰好有一条案号和一条收据号辅助值。
set @malformed_detail_count = (
    select count(*)
    from (
        select d.detail_id,
               count(*) as aux_rows,
               sum(a.aux_type_code = 'case_no') as case_rows,
               sum(a.aux_type_code = 'receipt_no') as receipt_rows
        from fin_voucher_aux_value a
        join fin_voucher_detail d
          on d.account_set_id = a.account_set_id
         and d.fiscal_year = a.fiscal_year
         and d.period = a.period
         and d.detail_id = a.detail_id
         and d.del_flag = 0
        join fin_voucher v
          on v.account_set_id = d.account_set_id
         and v.fiscal_year = d.fiscal_year
         and v.period = d.period
         and v.voucher_id = d.voucher_id
         and v.del_flag = 0
        where a.account_set_id = @account_set_id
          and d.subject_code = @subject_code
          and a.aux_type_code in ('case_no', 'receipt_no')
          and a.created_time >= @window_start
          and a.created_time < @window_end
          and a.del_flag = 0
        group by d.detail_id
        having aux_rows <> 2 or case_rows <> 1 or receipt_rows <> 1
    ) malformed_details
);

-- 目标科目不能存在任何其他有效辅助值；否则不能直接清空 aux_desc。
set @other_active_aux_count = (
    select count(*)
    from fin_voucher_aux_value a
    join fin_voucher_detail d
      on d.account_set_id = a.account_set_id
     and d.fiscal_year = a.fiscal_year
     and d.period = a.period
     and d.detail_id = a.detail_id
     and d.del_flag = 0
    where a.account_set_id = @account_set_id
      and d.subject_code = @subject_code
      and a.aux_type_code not in ('case_no', 'receipt_no')
      and a.del_flag = 0
);

set @invalid_voucher_state_count = (
    select count(*)
    from (
        select v.voucher_id
        from fin_voucher_aux_value a
        join fin_voucher_detail d
          on d.account_set_id = a.account_set_id
         and d.fiscal_year = a.fiscal_year
         and d.period = a.period
         and d.detail_id = a.detail_id
         and d.del_flag = 0
        join fin_voucher v
          on v.account_set_id = d.account_set_id
         and v.fiscal_year = d.fiscal_year
         and v.period = d.period
         and v.voucher_id = d.voucher_id
         and v.del_flag = 0
        where a.account_set_id = @account_set_id
          and d.subject_code = @subject_code
          and a.aux_type_code in ('case_no', 'receipt_no')
          and a.created_time >= @window_start
          and a.created_time < @window_end
          and a.del_flag = 0
          and (
              v.source_type <> 'BUSINESS'
              or v.status <> 'POSTED'
              or v.printed_flag <> '0'
              or v.void_flag <> '0'
          )
        group by v.voucher_id
    ) invalid_vouchers
);

set @already_applied = if(
    @existing_patch_log_count = @expected_voucher_count
    and @all_active_target_aux_count = 0,
    1,
    0
);

-- MySQL 5.7 不执行 CHECK 约束，因此使用 NOT NULL 临时表做硬保护。
-- 任一条件不满足会报 Column 'ok_flag' cannot be null，并在进入事务前停止。
drop temporary table if exists tmp_20260816_invalid_aux_guard;
create temporary table tmp_20260816_invalid_aux_guard (
    check_name varchar(100) not null primary key,
    ok_flag tinyint not null
) engine=InnoDB;

insert into tmp_20260816_invalid_aux_guard (check_name, ok_flag)
values ('database_name', if(database() = @expected_database, 1, null));

insert into tmp_20260816_invalid_aux_guard (check_name, ok_flag)
select 'account_set_identity',
       if(count(*) = 1, 1, null)
from fin_account_set
where account_set_id = @account_set_id
  and set_code = @account_set_code
  and set_name = '案款账套'
  and biz_type = 'CASE_FUND'
  and status = 1
  and del_flag = 0;

insert into tmp_20260816_invalid_aux_guard (check_name, ok_flag)
select 'subject_identity',
       if(count(*) = 1, 1, null)
from fin_subject
where account_set_id = @account_set_id
  and subject_code = @subject_code
  and subject_name = @subject_name
  and direction = 'DEBIT'
  and subject_type = 'ASSET'
  and leaf_flag = 1
  and voucher_entry_flag = 1
  and status = 1
  and del_flag = 0;

insert into tmp_20260816_invalid_aux_guard (check_name, ok_flag)
values ('subject_has_no_active_aux_config', if(@active_config_count = 0, 1, null));

insert into tmp_20260816_invalid_aux_guard (check_name, ok_flag)
values (
    'fixed_or_applied_scope',
    if(
        (
            @existing_patch_log_count = 0
            and @all_active_target_aux_count = @expected_aux_count
            and @window_target_aux_count = @expected_aux_count
            and @target_detail_count = @expected_detail_count
            and @target_voucher_count = @expected_voucher_count
            and @target_period_count = @expected_period_count
            and @target_debit_amount = @expected_debit_amount
            and @target_credit_amount = @expected_credit_amount
            and @malformed_detail_count = 0
            and @other_active_aux_count = 0
            and @invalid_voucher_state_count = 0
        )
        or (
            @already_applied = 1
            and @window_target_aux_count = 0
            and @target_detail_count = 0
            and @target_voucher_count = 0
            and @target_period_count = 0
            and @target_debit_amount = 0.00
            and @target_credit_amount = 0.00
            and @malformed_detail_count = 0
            and @other_active_aux_count = 0
            and @invalid_voucher_state_count = 0
        ),
        1,
        null
    )
);

-- 执行前范围摘要。首次执行应显示 5880 / 2940 / 181 / 5。
select @patch_tag as patch_tag,
       @already_applied as already_applied,
       @all_active_target_aux_count as active_target_aux_count,
       @target_detail_count as target_detail_count,
       @target_voucher_count as target_voucher_count,
       @target_period_count as target_period_count,
       @target_debit_amount as target_debit_amount,
       @target_credit_amount as target_credit_amount,
       @existing_patch_log_count as existing_patch_log_count;

-- 固化软删除前辅助值、分录和凭证状态。
drop temporary table if exists tmp_20260816_invalid_aux_before;
create temporary table tmp_20260816_invalid_aux_before
engine=InnoDB
as
select a.id,
       a.account_set_id,
       a.fiscal_year,
       a.period,
       a.voucher_id,
       a.detail_id,
       a.aux_type_code,
       a.aux_value,
       a.aux_label,
       a.version
from fin_voucher_aux_value a
join fin_voucher_detail d
  on d.account_set_id = a.account_set_id
 and d.fiscal_year = a.fiscal_year
 and d.period = a.period
 and d.detail_id = a.detail_id
 and d.del_flag = 0
join fin_voucher v
  on v.account_set_id = d.account_set_id
 and v.fiscal_year = d.fiscal_year
 and v.period = d.period
 and v.voucher_id = d.voucher_id
 and v.del_flag = 0
where a.account_set_id = @account_set_id
  and d.subject_code = @subject_code
  and a.aux_type_code in ('case_no', 'receipt_no')
  and a.created_time >= @window_start
  and a.created_time < @window_end
  and a.del_flag = 0;

alter table tmp_20260816_invalid_aux_before
    add primary key (id),
    add index idx_tmp_20260816_invalid_aux_detail (detail_id),
    add index idx_tmp_20260816_invalid_aux_voucher (voucher_id);

drop temporary table if exists tmp_20260816_invalid_detail_before;
create temporary table tmp_20260816_invalid_detail_before
engine=InnoDB
as
select d.detail_id,
       d.account_set_id,
       d.fiscal_year,
       d.period,
       d.voucher_id,
       d.line_no,
       d.subject_code,
       d.summary,
       d.debit_amount,
       d.credit_amount,
       d.verification_status,
       d.aux_desc,
       d.version
from fin_voucher_detail d
where d.account_set_id = @account_set_id
  and d.subject_code = @subject_code
  and d.del_flag = 0
  and exists (
      select 1
      from tmp_20260816_invalid_aux_before a
      where a.detail_id = d.detail_id
  );

alter table tmp_20260816_invalid_detail_before
    add primary key (detail_id),
    add index idx_tmp_20260816_invalid_detail_voucher (voucher_id);

drop temporary table if exists tmp_20260816_invalid_voucher_before;
create temporary table tmp_20260816_invalid_voucher_before
engine=InnoDB
as
select v.voucher_id,
       v.account_set_id,
       v.fiscal_year,
       v.period,
       v.voucher_date,
       v.voucher_word,
       v.voucher_no,
       v.summary,
       v.debit_amount,
       v.credit_amount,
       v.status,
       v.source_type,
       v.printed_flag,
       v.prepared_by,
       v.prepared_time,
       v.audit_by,
       v.audit_time,
       v.posted_by,
       v.posted_time,
       v.void_flag,
       v.version,
       count(d.detail_id) as affected_detail_count,
       sum(d.debit_amount) as affected_debit_amount,
       sum(d.credit_amount) as affected_credit_amount
from fin_voucher v
join tmp_20260816_invalid_detail_before d
  on d.voucher_id = v.voucher_id
where v.account_set_id = @account_set_id
  and v.del_flag = 0
group by v.voucher_id,
         v.account_set_id,
         v.fiscal_year,
         v.period,
         v.voucher_date,
         v.voucher_word,
         v.voucher_no,
         v.summary,
         v.debit_amount,
         v.credit_amount,
         v.status,
         v.source_type,
         v.printed_flag,
         v.prepared_by,
         v.prepared_time,
         v.audit_by,
         v.audit_time,
         v.posted_by,
         v.posted_time,
         v.void_flag,
         v.version;

alter table tmp_20260816_invalid_voucher_before
    add primary key (voucher_id);

start transaction;

-- 软删除错误辅助值；保留原值，必要时可按审计记录追溯恢复。
update fin_voucher_aux_value a
join tmp_20260816_invalid_aux_before b
  on b.id = a.id
set a.del_flag = 1,
    a.updated_by = @operator_id,
    a.updated_time = @patch_time,
    a.version = a.version + 1
where a.account_set_id = @account_set_id
  and a.aux_type_code in ('case_no', 'receipt_no')
  and a.del_flag = 0
  and @already_applied = 0;
set @soft_deleted_aux_count = row_count();

-- 该科目不存在其他辅助值，清空历史冗余辅助摘要。
update fin_voucher_detail d
join tmp_20260816_invalid_detail_before b
  on b.detail_id = d.detail_id
set d.aux_desc = '',
    d.updated_by = @operator_id,
    d.updated_time = @patch_time,
    d.version = d.version + 1
where d.account_set_id = @account_set_id
  and d.subject_code = @subject_code
  and d.del_flag = 0
  and @already_applied = 0;
set @updated_detail_count = row_count();

-- 更新凭证聚合根版本，但保持所有会计与流程状态字段不变。
update fin_voucher v
join tmp_20260816_invalid_voucher_before b
  on b.voucher_id = v.voucher_id
set v.updated_by = @operator_id,
    v.updated_time = @patch_time,
    v.version = v.version + 1
where v.account_set_id = @account_set_id
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
       'CLEAN_INVALID_AUX_VALUES',
       json_object(
           'patch', @patch_tag,
           'period', b.period,
           'voucher_word', b.voucher_word,
           'voucher_no', b.voucher_no,
           'status', b.status,
           'source_type', b.source_type,
           'subject_code', @subject_code,
           'active_aux_types', json_array('case_no', 'receipt_no'),
           'active_aux_count', b.affected_detail_count * 2,
           'affected_detail_count', b.affected_detail_count,
           'affected_debit_amount', b.affected_debit_amount,
           'affected_credit_amount', b.affected_credit_amount
       ),
       json_object(
           'patch', @patch_tag,
           'period', b.period,
           'voucher_word', b.voucher_word,
           'voucher_no', b.voucher_no,
           'status', b.status,
           'source_type', b.source_type,
           'subject_code', @subject_code,
           'active_aux_types', json_array(),
           'active_aux_count', 0,
           'affected_detail_count', b.affected_detail_count,
           'affected_debit_amount', b.affected_debit_amount,
           'affected_credit_amount', b.affected_credit_amount
       ),
       @operator_id,
       'SQL_PATCH',
       @patch_time
from tmp_20260816_invalid_voucher_before b
where @already_applied = 0
  and not exists (
      select 1
      from sys_audit_log old_log
      where old_log.account_set_id = @account_set_id
        and old_log.biz_type = 'VOUCHER'
        and old_log.biz_id = b.voucher_id
        and old_log.operation = 'CLEAN_INVALID_AUX_VALUES'
        and old_log.operator_ip = 'SQL_PATCH'
        and json_unquote(json_extract(old_log.after_json, '$.patch')) = @patch_tag
  );
set @inserted_audit_count = row_count();

-- 事务内数量保护。任一失败时客户端必须停止；连接关闭后 InnoDB 自动回滚。
insert into tmp_20260816_invalid_aux_guard (check_name, ok_flag)
values (
    'soft_deleted_aux_count',
    if(@soft_deleted_aux_count = if(@already_applied = 1, 0, @expected_aux_count), 1, null)
);

insert into tmp_20260816_invalid_aux_guard (check_name, ok_flag)
values (
    'updated_detail_count',
    if(@updated_detail_count = if(@already_applied = 1, 0, @expected_detail_count), 1, null)
);

insert into tmp_20260816_invalid_aux_guard (check_name, ok_flag)
values (
    'updated_voucher_count',
    if(@updated_voucher_count = if(@already_applied = 1, 0, @expected_voucher_count), 1, null)
);

insert into tmp_20260816_invalid_aux_guard (check_name, ok_flag)
values (
    'inserted_audit_count',
    if(@inserted_audit_count = if(@already_applied = 1, 0, @expected_voucher_count), 1, null)
);

insert into tmp_20260816_invalid_aux_guard (check_name, ok_flag)
select 'invalid_active_aux_removed',
       if(count(*) = 0, 1, null)
from fin_voucher_aux_value a
join fin_voucher_detail d
  on d.account_set_id = a.account_set_id
 and d.fiscal_year = a.fiscal_year
 and d.period = a.period
 and d.detail_id = a.detail_id
 and d.del_flag = 0
join fin_voucher v
  on v.account_set_id = d.account_set_id
 and v.fiscal_year = d.fiscal_year
 and v.period = d.period
 and v.voucher_id = d.voucher_id
 and v.del_flag = 0
where a.account_set_id = @account_set_id
  and d.subject_code = @subject_code
  and a.aux_type_code in ('case_no', 'receipt_no')
  and a.del_flag = 0;

insert into tmp_20260816_invalid_aux_guard (check_name, ok_flag)
select 'soft_deleted_aux_final_state',
       if(count(*) = if(@already_applied = 1, 0, @expected_aux_count), 1, null)
from fin_voucher_aux_value a
join tmp_20260816_invalid_aux_before b
  on b.id = a.id
where a.account_set_id = @account_set_id
  and a.aux_type_code = b.aux_type_code
  and a.aux_value = b.aux_value
  and (a.aux_label <=> b.aux_label)
  and a.version = b.version + 1
  and a.updated_by = @operator_id
  and a.updated_time = @patch_time
  and a.del_flag = 1;

-- 确认分录只有 aux_desc 和更新元数据发生改变；金额、科目、摘要、核销状态保持不变。
insert into tmp_20260816_invalid_aux_guard (check_name, ok_flag)
select 'detail_final_state',
       if(count(*) = if(@already_applied = 1, 0, @expected_detail_count), 1, null)
from fin_voucher_detail d
join tmp_20260816_invalid_detail_before b
  on b.detail_id = d.detail_id
where d.account_set_id = @account_set_id
  and d.fiscal_year = b.fiscal_year
  and d.period = b.period
  and d.voucher_id = b.voucher_id
  and d.line_no = b.line_no
  and d.subject_code = b.subject_code
  and (d.summary <=> b.summary)
  and d.debit_amount = b.debit_amount
  and d.credit_amount = b.credit_amount
  and d.verification_status = b.verification_status
  and d.aux_desc = ''
  and d.version = b.version + 1
  and d.updated_by = @operator_id
  and d.updated_time = @patch_time
  and d.del_flag = 0;

-- 确认凭证头所有会计金额和流程状态保持不变，仅更新版本及更新元数据。
insert into tmp_20260816_invalid_aux_guard (check_name, ok_flag)
select 'voucher_final_state',
       if(count(*) = if(@already_applied = 1, 0, @expected_voucher_count), 1, null)
from fin_voucher v
join tmp_20260816_invalid_voucher_before b
  on b.voucher_id = v.voucher_id
where v.account_set_id = @account_set_id
  and v.fiscal_year = b.fiscal_year
  and v.period = b.period
  and v.voucher_date = b.voucher_date
  and v.voucher_word = b.voucher_word
  and v.voucher_no = b.voucher_no
  and (v.summary <=> b.summary)
  and v.debit_amount = b.debit_amount
  and v.credit_amount = b.credit_amount
  and v.status = b.status
  and v.source_type = b.source_type
  and v.printed_flag = b.printed_flag
  and (v.prepared_by <=> b.prepared_by)
  and (v.prepared_time <=> b.prepared_time)
  and (v.audit_by <=> b.audit_by)
  and (v.audit_time <=> b.audit_time)
  and (v.posted_by <=> b.posted_by)
  and (v.posted_time <=> b.posted_time)
  and v.void_flag = b.void_flag
  and v.version = b.version + 1
  and v.updated_by = @operator_id
  and v.updated_time = @patch_time
  and v.del_flag = 0;

insert into tmp_20260816_invalid_aux_guard (check_name, ok_flag)
select 'audit_log_final_count',
       if(count(*) = @expected_voucher_count, 1, null)
from sys_audit_log
where account_set_id = @account_set_id
  and biz_type = 'VOUCHER'
  and operation = 'CLEAN_INVALID_AUX_VALUES'
  and operator_ip = 'SQL_PATCH'
  and json_unquote(json_extract(after_json, '$.patch')) = @patch_tag;

commit;

-- 执行结果。首次执行应为 5880 / 2940 / 181 / 181；重复执行应为 0 / 0 / 0 / 0。
select @patch_tag as patch_tag,
       @already_applied as already_applied_before_run,
       @soft_deleted_aux_count as soft_deleted_aux_count,
       @updated_detail_count as updated_detail_count,
       @updated_voucher_count as updated_voucher_count,
       @inserted_audit_count as inserted_audit_count;

select d.fiscal_year,
       d.period,
       count(*) as cleaned_detail_count,
       sum(d.debit_amount) as unchanged_debit_amount,
       sum(d.credit_amount) as unchanged_credit_amount
from tmp_20260816_invalid_detail_before d
group by d.fiscal_year, d.period
order by d.fiscal_year, d.period;

select count(*) as remaining_invalid_active_aux_count
from fin_voucher_aux_value a
join fin_voucher_detail d
  on d.account_set_id = a.account_set_id
 and d.fiscal_year = a.fiscal_year
 and d.period = a.period
 and d.detail_id = a.detail_id
 and d.del_flag = 0
where a.account_set_id = @account_set_id
  and d.subject_code = @subject_code
  and a.aux_type_code in ('case_no', 'receipt_no')
  and a.del_flag = 0;

drop temporary table if exists tmp_20260816_invalid_voucher_before;
drop temporary table if exists tmp_20260816_invalid_detail_before;
drop temporary table if exists tmp_20260816_invalid_aux_before;
drop temporary table if exists tmp_20260816_invalid_aux_guard;
