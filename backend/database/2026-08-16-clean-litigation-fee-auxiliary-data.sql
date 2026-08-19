-- 清理诉讼费账套不应存在的辅助核算数据
-- 数据库：court-finance-txfy
--
-- 业务口径：诉讼费账套不启用辅助核算。
--
-- 财务控制：
-- 1. 仅处理 LITIGATION_FEE_2026（账套 ID 00000000-0000-0000-0000-000000000102）；
-- 2. 执行前确认该账套没有有效科目辅助配置、辅助期初和辅助余额快照；
-- 3. 固定核对 5 张 2026-01 BUSINESS / SUBMITTED 凭证、669 条明细、
--    1,106 条错误辅助值和 503,529.42 元借贷金额；
-- 4. 软删除错误凭证辅助值、辅助档案和辅助类型，清空明细 aux_desc；
-- 5. 不修改凭证、凭证金额、凭证状态、凭证号以及缴费业务来源；
-- 6. 写入汇总审计日志；补丁可重复执行，重复执行为安全空操作；
-- 7. 不物理删除任何业务或审计记录。
--
-- 适用数据库：MySQL 5.7 / 8.0。
-- 执行要求：以完整脚本执行；发生任何错误时停止，不要使用 mysql --force。

use `court-finance-txfy`;

set names utf8mb4 collate utf8mb4_unicode_ci;
set @patch_tag = '2026-08-16-clean-litigation-fee-auxiliary-data';
set @expected_database = 'court-finance-txfy';
set @account_set_id = '00000000-0000-0000-0000-000000000102';
set @account_set_code = 'LITIGATION_FEE_2026';
set @operator_id = '00000000-0000-0000-0000-000000000301';
set @patch_time = current_timestamp;

drop procedure if exists patch_clean_litigation_fee_auxiliary_data;

delimiter $$

create procedure patch_clean_litigation_fee_auxiliary_data()
main: begin
    declare v_message varchar(500);
    declare v_existing_log_count int default 0;
    declare v_account_count int default 0;
    declare v_config_count int default 0;
    declare v_opening_count int default 0;
    declare v_snapshot_count int default 0;
    declare v_aux_type_count int default 0;
    declare v_archive_count int default 0;
    declare v_total_aux_count int default 0;
    declare v_target_aux_count int default 0;
    declare v_target_detail_count int default 0;
    declare v_target_voucher_count int default 0;
    declare v_nonempty_aux_desc_count int default 0;
    declare v_source_count int default 0;
    declare v_source_amount decimal(18,2) default 0.00;
    declare v_voucher_debit decimal(18,2) default 0.00;
    declare v_voucher_credit decimal(18,2) default 0.00;
    declare v_details_updated int default 0;
    declare v_aux_updated int default 0;
    declare v_archives_updated int default 0;
    declare v_types_updated int default 0;

    if database() <> @expected_database then
        set v_message = concat('数据库不匹配，当前=', coalesce(database(), 'NULL'), '，预期=', @expected_database);
        signal sqlstate '45000' set message_text = v_message;
    end if;

    select count(*) into v_account_count
    from fin_account_set
    where account_set_id = @account_set_id
      and set_code = @account_set_code
      and biz_type = 'LITIGATION_FEE'
      and status = 1
      and del_flag = 0;

    if v_account_count <> 1 then
        signal sqlstate '45000' set message_text = '诉讼费账套身份或状态不符合补丁要求';
    end if;

    select count(*) into v_existing_log_count
    from sys_audit_log
    where account_set_id = @account_set_id
      and biz_type = 'ACCOUNT_SET'
      and biz_id = @account_set_id
      and operation = 'CLEAN_LITIGATION_AUX'
      and operator_ip = 'SQL_PATCH'
      and json_unquote(json_extract(after_json, '$.patch')) = @patch_tag;

    select count(*) into v_config_count
    from fin_subject_aux_config
    where account_set_id = @account_set_id
      and del_flag = 0;

    select count(*) into v_opening_count
    from fin_aux_opening_balance
    where account_set_id = @account_set_id
      and del_flag = 0;

    select count(*) into v_snapshot_count
    from fin_aux_balance_snapshot
    where account_set_id = @account_set_id
      and del_flag = 0;

    select count(*) into v_aux_type_count
    from fin_aux_type
    where account_set_id = @account_set_id
      and del_flag = 0;

    select count(*) into v_archive_count
    from fin_aux_archive
    where account_set_id = @account_set_id
      and del_flag = 0;

    select count(*) into v_total_aux_count
    from fin_voucher_aux_value
    where account_set_id = @account_set_id
      and del_flag = 0;

    if v_total_aux_count = 0 then
        if v_existing_log_count <> 1
           or v_config_count <> 0
           or v_opening_count <> 0
           or v_snapshot_count <> 0
           or v_aux_type_count <> 0
           or v_archive_count <> 0 then
            signal sqlstate '45000' set message_text = '辅助值已为空，但补丁审计或其他辅助数据状态不完整';
        end if;

        select 'ALREADY_APPLIED' as patch_status,
               0 as active_aux_values,
               0 as active_aux_archives,
               0 as active_aux_types;
        leave main;
    end if;

    if v_existing_log_count <> 0 then
        signal sqlstate '45000' set message_text = '发现既有补丁审计，但待清理数据仍存在，拒绝重复修改';
    end if;

    if v_config_count <> 0 or v_opening_count <> 0 or v_snapshot_count <> 0 then
        signal sqlstate '45000' set message_text = '诉讼费账套已有辅助配置、辅助期初或辅助快照，拒绝清理';
    end if;

    if v_aux_type_count <> 2 or v_archive_count <> 6672 or v_total_aux_count <> 1106 then
        set v_message = concat(
            '辅助数据数量变化：类型=', v_aux_type_count,
            '，档案=', v_archive_count,
            '，凭证辅助值=', v_total_aux_count
        );
        signal sqlstate '45000' set message_text = v_message;
    end if;

    if exists (
        select 1
        from fin_aux_type
        where account_set_id = @account_set_id
          and del_flag = 0
          and aux_type_code not in ('case_no', 'receipt_no')
    ) then
        signal sqlstate '45000' set message_text = '发现案号、收据号以外的有效辅助类型，拒绝清理';
    end if;

    select count(*) into v_target_aux_count
    from fin_voucher_aux_value a
    join fin_voucher_detail d
      on d.account_set_id = a.account_set_id
     and d.fiscal_year = a.fiscal_year
     and d.period = a.period
     and d.voucher_id = a.voucher_id
     and d.detail_id = a.detail_id
     and d.del_flag = 0
    join fin_voucher v
      on v.account_set_id = d.account_set_id
     and v.fiscal_year = d.fiscal_year
     and v.period = d.period
     and v.voucher_id = d.voucher_id
     and v.del_flag = 0
    where a.account_set_id = @account_set_id
      and a.aux_type_code in ('case_no', 'receipt_no')
      and d.subject_code in ('10020101', '210301')
      and v.fiscal_year = 2026
      and v.period = '2026-01'
      and v.source_type = 'BUSINESS'
      and v.status = 'SUBMITTED'
      and v.void_flag = 0
      and a.del_flag = 0;

    select count(*) into v_target_detail_count
    from (
        select d.detail_id
        from fin_voucher_aux_value a
        join fin_voucher_detail d
          on d.account_set_id = a.account_set_id
         and d.fiscal_year = a.fiscal_year
         and d.period = a.period
         and d.voucher_id = a.voucher_id
         and d.detail_id = a.detail_id
         and d.del_flag = 0
        join fin_voucher v
          on v.account_set_id = d.account_set_id
         and v.fiscal_year = d.fiscal_year
         and v.period = d.period
         and v.voucher_id = d.voucher_id
         and v.del_flag = 0
        where a.account_set_id = @account_set_id
          and a.aux_type_code in ('case_no', 'receipt_no')
          and d.subject_code in ('10020101', '210301')
          and v.fiscal_year = 2026
          and v.period = '2026-01'
          and v.source_type = 'BUSINESS'
          and v.status = 'SUBMITTED'
          and v.void_flag = 0
          and a.del_flag = 0
        group by d.detail_id
    ) target_details;

    select count(*) into v_target_voucher_count
    from (
        select v.voucher_id
        from fin_voucher_aux_value a
        join fin_voucher_detail d
          on d.account_set_id = a.account_set_id
         and d.fiscal_year = a.fiscal_year
         and d.period = a.period
         and d.voucher_id = a.voucher_id
         and d.detail_id = a.detail_id
         and d.del_flag = 0
        join fin_voucher v
          on v.account_set_id = d.account_set_id
         and v.fiscal_year = d.fiscal_year
         and v.period = d.period
         and v.voucher_id = d.voucher_id
         and v.del_flag = 0
        where a.account_set_id = @account_set_id
          and a.aux_type_code in ('case_no', 'receipt_no')
          and d.subject_code in ('10020101', '210301')
          and v.fiscal_year = 2026
          and v.period = '2026-01'
          and v.source_type = 'BUSINESS'
          and v.status = 'SUBMITTED'
          and v.void_flag = 0
          and a.del_flag = 0
        group by v.voucher_id
    ) target_vouchers;

    select count(*) into v_nonempty_aux_desc_count
    from fin_voucher_detail d
    where d.account_set_id = @account_set_id
      and d.del_flag = 0
      and coalesce(d.aux_desc, '') <> ''
      and d.detail_id in (
          select a.detail_id
          from fin_voucher_aux_value a
          where a.account_set_id = @account_set_id
            and a.del_flag = 0
      );

    select coalesce(sum(x.debit_amount), 0.00),
           coalesce(sum(x.credit_amount), 0.00)
      into v_voucher_debit, v_voucher_credit
    from (
        select distinct v.voucher_id, v.debit_amount, v.credit_amount
        from fin_voucher_aux_value a
        join fin_voucher_detail d
          on d.detail_id = a.detail_id
         and d.voucher_id = a.voucher_id
         and d.account_set_id = a.account_set_id
         and d.del_flag = 0
        join fin_voucher v
          on v.voucher_id = a.voucher_id
         and v.account_set_id = a.account_set_id
         and v.del_flag = 0
        where a.account_set_id = @account_set_id
          and a.del_flag = 0
    ) x;

    select count(*), coalesce(sum(payment_amount), 0.00)
      into v_source_count, v_source_amount
    from fin_case_fund_payment
    where account_set_id = @account_set_id
      and fiscal_year = 2026
      and period = '2026-01'
      and voucher_status = 'GENERATED'
      and voucher_id in (
          select distinct voucher_id
          from fin_voucher_aux_value
          where account_set_id = @account_set_id
            and del_flag = 0
      )
      and del_flag = 0;

    if v_target_aux_count <> 1106
       or v_target_detail_count <> 669
       or v_target_voucher_count <> 5
       or v_nonempty_aux_desc_count <> 669
       or v_voucher_debit <> 503529.42
       or v_voucher_credit <> 503529.42
       or v_source_count <> 338
       or v_source_amount <> 503529.42 then
        set v_message = concat(
            '凭证或来源范围变化：aux=', v_target_aux_count,
            '，detail=', v_target_detail_count,
            '，voucher=', v_target_voucher_count,
            '，aux_desc=', v_nonempty_aux_desc_count,
            '，debit=', v_voucher_debit,
            '，credit=', v_voucher_credit,
            '，source=', v_source_count,
            '，source_amount=', v_source_amount
        );
        signal sqlstate '45000' set message_text = v_message;
    end if;

    if v_target_aux_count <> v_total_aux_count then
        signal sqlstate '45000' set message_text = '存在补丁固定范围以外的有效凭证辅助值，拒绝清理';
    end if;

    start transaction;

    update fin_voucher_detail d
    set d.aux_desc = '',
        d.updated_by = @operator_id,
        d.updated_time = @patch_time,
        d.version = d.version + 1,
        d.remark = left(concat_ws(' ', nullif(trim(coalesce(d.remark, '')), ''), @patch_tag), 500)
    where d.account_set_id = @account_set_id
      and d.del_flag = 0
      and d.detail_id in (
          select a.detail_id
          from fin_voucher_aux_value a
          where a.account_set_id = @account_set_id
            and a.del_flag = 0
      );
    set v_details_updated = row_count();

    update fin_voucher_aux_value
    set del_flag = 1,
        updated_by = @operator_id,
        updated_time = @patch_time,
        version = version + 1,
        remark = left(concat_ws(' ', nullif(trim(coalesce(remark, '')), ''), @patch_tag), 500)
    where account_set_id = @account_set_id
      and del_flag = 0;
    set v_aux_updated = row_count();

    update fin_aux_archive
    set del_flag = 1,
        updated_by = @operator_id,
        updated_time = @patch_time,
        version = version + 1,
        remark = left(concat_ws(' ', nullif(trim(coalesce(remark, '')), ''), @patch_tag), 500)
    where account_set_id = @account_set_id
      and del_flag = 0;
    set v_archives_updated = row_count();

    update fin_aux_type
    set del_flag = 1,
        updated_by = @operator_id,
        updated_time = @patch_time,
        version = version + 1,
        remark = left(concat_ws(' ', nullif(trim(coalesce(remark, '')), ''), @patch_tag), 500)
    where account_set_id = @account_set_id
      and del_flag = 0;
    set v_types_updated = row_count();

    if v_details_updated <> 669
       or v_aux_updated <> 1106
       or v_archives_updated <> 6672
       or v_types_updated <> 2 then
        rollback;
        set v_message = concat(
            '更新数量不符：detail=', v_details_updated,
            '，aux=', v_aux_updated,
            '，archive=', v_archives_updated,
            '，type=', v_types_updated
        );
        signal sqlstate '45000' set message_text = v_message;
    end if;

    if exists (
        select 1 from fin_voucher_aux_value
        where account_set_id = @account_set_id and del_flag = 0
    ) or exists (
        select 1 from fin_aux_archive
        where account_set_id = @account_set_id and del_flag = 0
    ) or exists (
        select 1 from fin_aux_type
        where account_set_id = @account_set_id and del_flag = 0
    ) then
        rollback;
        signal sqlstate '45000' set message_text = '清理后仍存在有效辅助数据，事务已回滚';
    end if;

    insert into sys_audit_log (
        log_id, account_set_id, biz_type, biz_id, operation,
        before_json, after_json, operator_id, operator_ip, created_time
    ) values (
        uuid(), @account_set_id, 'ACCOUNT_SET', @account_set_id, 'CLEAN_LITIGATION_AUX',
        json_object(
            'active_aux_types', 2,
            'active_aux_archives', 6672,
            'active_voucher_aux_values', 1106,
            'detail_aux_desc', 669,
            'voucher_count', 5,
            'voucher_debit', 503529.42,
            'voucher_credit', 503529.42,
            'payment_source_count', 338,
            'payment_source_amount', 503529.42
        ),
        json_object(
            'patch', @patch_tag,
            'active_aux_types', 0,
            'active_aux_archives', 0,
            'active_voucher_aux_values', 0,
            'detail_aux_desc', 0,
            'voucher_and_source_unchanged', true
        ),
        @operator_id, 'SQL_PATCH', @patch_time
    );

    commit;

    select 'APPLIED' as patch_status,
           v_details_updated as cleared_detail_aux_desc,
           v_aux_updated as soft_deleted_voucher_aux_values,
           v_archives_updated as soft_deleted_aux_archives,
           v_types_updated as soft_deleted_aux_types;
end$$

delimiter ;

call patch_clean_litigation_fee_auxiliary_data();
drop procedure if exists patch_clean_litigation_fee_auxiliary_data;

-- 执行后核验：均应为 0；凭证和缴费来源应仍为 5 / 503529.42 和 338 / 503529.42。
select count(*) as active_aux_types
from fin_aux_type
where account_set_id = @account_set_id and del_flag = 0;

select count(*) as active_aux_archives
from fin_aux_archive
where account_set_id = @account_set_id and del_flag = 0;

select count(*) as active_voucher_aux_values
from fin_voucher_aux_value
where account_set_id = @account_set_id and del_flag = 0;

select count(*) as patch_audit_logs
from sys_audit_log
where account_set_id = @account_set_id
  and operation = 'CLEAN_LITIGATION_AUX'
  and operator_ip = 'SQL_PATCH'
  and json_unquote(json_extract(after_json, '$.patch')) = @patch_tag;

select count(*) as voucher_count,
       coalesce(sum(debit_amount), 0.00) as debit_amount,
       coalesce(sum(credit_amount), 0.00) as credit_amount
from fin_voucher
where account_set_id = @account_set_id
  and fiscal_year = 2026
  and period = '2026-01'
  and voucher_no in (43, 44, 45, 47, 48)
  and del_flag = 0;

select count(*) as payment_source_count,
       coalesce(sum(payment_amount), 0.00) as payment_source_amount
from fin_case_fund_payment
where account_set_id = @account_set_id
  and fiscal_year = 2026
  and period = '2026-01'
  and voucher_status = 'GENERATED'
  and voucher_id in (
      select voucher_id
      from fin_voucher
      where account_set_id = @account_set_id
        and fiscal_year = 2026
        and period = '2026-01'
        and voucher_no in (43, 44, 45, 47, 48)
        and del_flag = 0
  )
  and del_flag = 0;
