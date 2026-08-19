-- 诉讼费账套银行科目层级、制证科目配置及未审核凭证纠正
-- 目标账套：court-finance 中唯一启用的 LITIGATION_FEE 账套
-- 原错误科目：100201 诉讼费专户（存在活动下级，必须为非末级）
-- 正确制证科目：10020101 诉讼费
--
-- 财务处理口径：
-- 1. 100201 恢复为非末级、禁止直接录入凭证；
-- 2. 诉讼费缴费登记及收费方式配置的借方科目改为 10020101；
-- 3. 仅纠正 DRAFT、SUBMITTED 凭证中使用 100201 的分录，不改变金额、方向、辅助核算和值；
-- 4. AUDITED、POSTED、PRINTED、VOIDED 等不可逆状态凭证不直接修改，执行后单独输出待处理数量；
-- 5. 所有实际修改均写入 sys_audit_log；补丁可重复执行。
--
-- 适用数据库：MySQL 8.0。

use `court-finance`;

set names utf8mb4 collate utf8mb4_unicode_ci;
set @patch_tag = '2026-07-31-fix-litigation-fee-voucher-subject';
set @account_set_id = (
    select min(account_set_id)
    from fin_account_set
    where biz_type = 'LITIGATION_FEE'
      and status = 1
      and del_flag = 0
);
set @source_subject_code = '100201';
set @target_subject_code = '10020101';
set @operator_id = 'system';
set @patch_time = current_timestamp;

drop temporary table if exists tmp_20260731_guard;
create temporary table tmp_20260731_guard (
    check_name varchar(100) not null primary key,
    ok_flag tinyint not null,
    constraint chk_tmp_20260731_guard_ok check (ok_flag = 1)
) engine=InnoDB;

-- 前置校验：账套、科目层级、目标末级科目和缴费配置必须精确匹配。
insert into tmp_20260731_guard (check_name, ok_flag)
select 'account_set',
       if(count(*) = 1 and min(account_set_id) = @account_set_id, 1, 0)
from fin_account_set
where biz_type = 'LITIGATION_FEE'
  and status = 1
  and del_flag = 0;

insert into tmp_20260731_guard (check_name, ok_flag)
select 'source_subject',
       if(count(*) = 1, 1, 0)
from fin_subject
where account_set_id = @account_set_id
  and subject_code = @source_subject_code
  and status = 1
  and del_flag = 0;

insert into tmp_20260731_guard (check_name, ok_flag)
select 'source_subject_has_active_children',
       if(count(*) >= 1, 1, 0)
from fin_subject
where account_set_id = @account_set_id
  and parent_code = @source_subject_code
  and del_flag = 0;

insert into tmp_20260731_guard (check_name, ok_flag)
select 'target_subject',
       if(count(*) = 1, 1, 0)
from fin_subject
where account_set_id = @account_set_id
  and subject_code = @target_subject_code
  and parent_code = @source_subject_code
  and status = 1
  and del_flag = 0;

insert into tmp_20260731_guard (check_name, ok_flag)
select 'target_subject_has_no_active_children',
       if(count(*) = 0, 1, 0)
from fin_subject
where account_set_id = @account_set_id
  and parent_code = @target_subject_code
  and del_flag = 0;

insert into tmp_20260731_guard (check_name, ok_flag)
select 'payment_subject_config',
       if(count(*) >= 1, 1, 0)
from fin_case_fund_subject_config
where account_set_id = @account_set_id
  and biz_type = 'LITIGATION_FEE'
  and voucher_biz_type = 'PAYMENT'
  and business_item_type in (
      '缴费登记',
      '诉讼费直接结算',
      '诉讼费预收',
      '预收诉讼费',
      '诉讼费收款'
  )
  and debit_subject_code in (@source_subject_code, @target_subject_code)
  and del_flag = 0;

drop temporary table if exists tmp_20260731_subject_config_before;
create temporary table tmp_20260731_subject_config_before
engine=InnoDB
as
select config_id,
       account_set_id,
       biz_type,
       voucher_biz_type,
       business_item_type,
       debit_subject_code,
       credit_subject_code,
       version
from fin_case_fund_subject_config
where account_set_id = @account_set_id
  and biz_type = 'LITIGATION_FEE'
  and del_flag = 0
  and (
      (
          voucher_biz_type = 'PAYMENT'
          and business_item_type in (
              '缴费登记',
              '诉讼费直接结算',
              '诉讼费预收',
              '预收诉讼费',
              '诉讼费收款'
          )
          and debit_subject_code = @source_subject_code
      )
      or (
          voucher_biz_type = 'PAYMENT_METHOD'
          and business_item_type in ('POS', 'TRANSFER', 'UNKNOWN')
          and (
              debit_subject_code = @source_subject_code
              or credit_subject_code = @source_subject_code
          )
      )
  );

alter table tmp_20260731_subject_config_before
    add primary key (config_id);

drop temporary table if exists tmp_20260731_voucher_before;
create temporary table tmp_20260731_voucher_before
engine=InnoDB
as
select distinct v.voucher_id,
       v.fiscal_year,
       v.period,
       v.voucher_no,
       v.status,
       v.source_type,
       v.version
from fin_voucher v
join fin_voucher_detail d
  on d.account_set_id = v.account_set_id
 and d.fiscal_year = v.fiscal_year
 and d.period = v.period
 and d.voucher_id = v.voucher_id
 and d.del_flag = 0
where v.account_set_id = @account_set_id
  and v.status in ('DRAFT', 'SUBMITTED')
  and v.del_flag = 0
  and d.subject_code = @source_subject_code;

alter table tmp_20260731_voucher_before
    add primary key (voucher_id);

drop temporary table if exists tmp_20260731_voucher_detail_before;
create temporary table tmp_20260731_voucher_detail_before
engine=InnoDB
as
select d.voucher_id,
       count(*) as detail_count,
       sum(d.debit_amount) as debit_amount,
       sum(d.credit_amount) as credit_amount
from fin_voucher_detail d
join tmp_20260731_voucher_before v
  on v.voucher_id = d.voucher_id
where d.account_set_id = @account_set_id
  and d.subject_code = @source_subject_code
  and d.del_flag = 0
group by d.voucher_id;

alter table tmp_20260731_voucher_detail_before
    add primary key (voucher_id);

set @subject_needs_update = (
    select if(leaf_flag <> 0 or voucher_entry_flag <> 0, 1, 0)
    from fin_subject
    where account_set_id = @account_set_id
      and subject_code = @source_subject_code
      and del_flag = 0
    limit 1
);
set @subject_before_json = (
    select json_object(
        'subject_code', subject_code,
        'subject_name', subject_name,
        'parent_code', parent_code,
        'leaf_flag', leaf_flag,
        'voucher_entry_flag', voucher_entry_flag,
        'version', version
    )
    from fin_subject
    where account_set_id = @account_set_id
      and subject_code = @source_subject_code
      and del_flag = 0
    limit 1
);
set @subject_config_before_json = (
    select json_arrayagg(json_object(
        'config_id', config_id,
        'voucher_biz_type', voucher_biz_type,
        'business_item_type', business_item_type,
        'debit_subject_code', debit_subject_code,
        'credit_subject_code', credit_subject_code,
        'version', version
    ))
    from tmp_20260731_subject_config_before
);
set @expected_config_count = (
    select count(*)
    from tmp_20260731_subject_config_before
);
set @expected_voucher_count = (
    select count(*)
    from tmp_20260731_voucher_before
);
set @expected_detail_count = (
    select count(*)
    from fin_voucher_detail d
    join tmp_20260731_voucher_before v
      on v.voucher_id = d.voucher_id
    where d.account_set_id = @account_set_id
      and d.subject_code = @source_subject_code
      and d.del_flag = 0
);

start transaction;

update fin_subject
set leaf_flag = 0,
    voucher_entry_flag = 0,
    updated_by = @operator_id,
    updated_time = @patch_time,
    version = version + 1
where account_set_id = @account_set_id
  and subject_code = @source_subject_code
  and del_flag = 0
  and @subject_needs_update = 1;
set @updated_subject_count = row_count();

insert into sys_audit_log (
    log_id, account_set_id, biz_type, biz_id, operation,
    before_json, after_json, operator_id, operator_ip, created_time
)
select uuid(),
       @account_set_id,
       'SUBJECT',
       @source_subject_code,
       'PATCH_NON_LEAF',
       @subject_before_json,
       json_object(
           'patch', @patch_tag,
           'subject_code', @source_subject_code,
           'leaf_flag', 0,
           'voucher_entry_flag', 0
       ),
       @operator_id,
       'SQL_PATCH',
       @patch_time
where @subject_needs_update = 1;

update fin_case_fund_subject_config c
join tmp_20260731_subject_config_before b
  on b.config_id = c.config_id
set c.debit_subject_code = if(
        c.debit_subject_code = @source_subject_code,
        @target_subject_code,
        c.debit_subject_code
    ),
    c.credit_subject_code = if(
        c.voucher_biz_type = 'PAYMENT_METHOD'
        and c.credit_subject_code = @source_subject_code,
        @target_subject_code,
        c.credit_subject_code
    ),
    c.updated_by = @operator_id,
    c.updated_time = @patch_time,
    c.version = c.version + 1;
set @updated_config_count = row_count();

set @subject_config_after_json = (
    select json_arrayagg(json_object(
        'config_id', c.config_id,
        'voucher_biz_type', c.voucher_biz_type,
        'business_item_type', c.business_item_type,
        'debit_subject_code', c.debit_subject_code,
        'credit_subject_code', c.credit_subject_code,
        'version', c.version
    ))
    from fin_case_fund_subject_config c
    where c.config_id in (
        select config_id
        from tmp_20260731_subject_config_before
    )
);

insert into sys_audit_log (
    log_id, account_set_id, biz_type, biz_id, operation,
    before_json, after_json, operator_id, operator_ip, created_time
)
select uuid(),
       @account_set_id,
       'CASE_FUND_SUBJECT_CONFIG',
       'PAYMENT@LITIGATION_FEE',
       'PATCH_SUBJECT',
       json_object(
           'patch', @patch_tag,
           'subject_configs', cast(@subject_config_before_json as json)
       ),
       json_object(
           'patch', @patch_tag,
           'subject_configs', cast(@subject_config_after_json as json)
       ),
       @operator_id,
       'SQL_PATCH',
       @patch_time
where @expected_config_count > 0;

update fin_voucher_detail d
join tmp_20260731_voucher_before v
  on v.voucher_id = d.voucher_id
set d.subject_code = @target_subject_code,
    d.updated_by = @operator_id,
    d.updated_time = @patch_time,
    d.version = d.version + 1
where d.account_set_id = @account_set_id
  and d.subject_code = @source_subject_code
  and d.del_flag = 0;
set @updated_detail_count = row_count();

update fin_voucher v
join tmp_20260731_voucher_before b
  on b.voucher_id = v.voucher_id
set v.updated_by = @operator_id,
    v.updated_time = @patch_time,
    v.version = v.version + 1
where v.account_set_id = @account_set_id
  and v.status in ('DRAFT', 'SUBMITTED')
  and v.del_flag = 0;
set @updated_voucher_count = row_count();

insert into sys_audit_log (
    log_id, account_set_id, biz_type, biz_id, operation,
    before_json, after_json, operator_id, operator_ip, created_time
)
select uuid(),
       @account_set_id,
       'VOUCHER',
       v.voucher_id,
       'CORRECT_SUBJECT',
       json_object(
           'patch', @patch_tag,
           'period', v.period,
           'voucher_no', v.voucher_no,
           'status', v.status,
           'source_type', v.source_type,
           'source_subject_code', @source_subject_code,
           'detail_count', s.detail_count,
           'debit_amount', s.debit_amount,
           'credit_amount', s.credit_amount,
           'version', v.version
       ),
       json_object(
           'patch', @patch_tag,
           'period', v.period,
           'voucher_no', v.voucher_no,
           'status', v.status,
           'target_subject_code', @target_subject_code,
           'detail_count', s.detail_count,
           'debit_amount', s.debit_amount,
           'credit_amount', s.credit_amount,
           'version', v.version + 1
       ),
       @operator_id,
       'SQL_PATCH',
       @patch_time
from tmp_20260731_voucher_before v
join tmp_20260731_voucher_detail_before s
  on s.voucher_id = v.voucher_id;

-- 事务内结果复核：任一数量或最终状态不符即触发 CHECK 约束失败并回滚。
insert into tmp_20260731_guard (check_name, ok_flag)
values (
    'updated_subject_count',
    if(@updated_subject_count = @subject_needs_update, 1, 0)
);

insert into tmp_20260731_guard (check_name, ok_flag)
values (
    'updated_config_count',
    if(@updated_config_count = @expected_config_count, 1, 0)
);

insert into tmp_20260731_guard (check_name, ok_flag)
values (
    'updated_voucher_count',
    if(@updated_voucher_count = @expected_voucher_count, 1, 0)
);

insert into tmp_20260731_guard (check_name, ok_flag)
values (
    'updated_detail_count',
    if(@updated_detail_count = @expected_detail_count, 1, 0)
);

insert into tmp_20260731_guard (check_name, ok_flag)
select 'source_subject_final_state',
       if(count(*) = 1, 1, 0)
from fin_subject
where account_set_id = @account_set_id
  and subject_code = @source_subject_code
  and leaf_flag = 0
  and voucher_entry_flag = 0
  and del_flag = 0;

insert into tmp_20260731_guard (check_name, ok_flag)
select 'payment_config_final_state',
       if(count(*) = 0, 1, 0)
from fin_case_fund_subject_config
where account_set_id = @account_set_id
  and biz_type = 'LITIGATION_FEE'
  and del_flag = 0
  and (
      (
          voucher_biz_type = 'PAYMENT'
          and business_item_type in (
              '缴费登记',
              '诉讼费直接结算',
              '诉讼费预收',
              '预收诉讼费',
              '诉讼费收款'
          )
          and debit_subject_code = @source_subject_code
      )
      or (
          voucher_biz_type = 'PAYMENT_METHOD'
          and business_item_type in ('POS', 'TRANSFER', 'UNKNOWN')
          and (
              debit_subject_code = @source_subject_code
              or credit_subject_code = @source_subject_code
          )
      )
  );

insert into tmp_20260731_guard (check_name, ok_flag)
select 'unreviewed_voucher_final_state',
       if(count(*) = 0, 1, 0)
from fin_voucher_detail d
join fin_voucher v
  on v.account_set_id = d.account_set_id
 and v.fiscal_year = d.fiscal_year
 and v.period = d.period
 and v.voucher_id = d.voucher_id
 and v.del_flag = 0
where d.account_set_id = @account_set_id
  and d.subject_code = @source_subject_code
  and d.del_flag = 0
  and v.status in ('DRAFT', 'SUBMITTED');

commit;

-- 执行结果与待后续按财务流程处理的已审核凭证。
select @patch_tag as patch_tag,
       @updated_subject_count as updated_subject_count,
       @updated_config_count as updated_config_count,
       @updated_voucher_count as corrected_unreviewed_voucher_count,
       @updated_detail_count as corrected_unreviewed_detail_count;

select v.status,
       count(distinct v.voucher_id) as voucher_count,
       count(*) as detail_count,
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
  and d.subject_code = @source_subject_code
  and d.del_flag = 0
group by v.status
order by v.status;

drop temporary table if exists tmp_20260731_voucher_detail_before;
drop temporary table if exists tmp_20260731_voucher_before;
drop temporary table if exists tmp_20260731_subject_config_before;
drop temporary table if exists tmp_20260731_guard;
