-- 案款缴费、退付后台 QUERY 制证取数索引。
--
-- 任务创建时会固定 account_set_id、fiscal_year、period，并只读取
-- voucher_status = 'UNGENERATED'、del_flag = 0 的业务记录，随后按业务日期、
-- 原导入行号升序冻结任务范围。现有期间/凭证索引不能完整支撑该筛选和排序。
--
-- MySQL 8.0：二级索引使用 INPLACE + LOCK=NONE 在线创建；脚本不修改业务数据，
-- 并通过 information_schema 按索引名判断，可重复执行。

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_case_fund_payment add index idx_case_fund_payment_async_voucher (account_set_id, fiscal_year, period, voucher_status, del_flag, payment_date, source_row_no), algorithm=inplace, lock=none',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_case_fund_payment'
      and index_name = 'idx_case_fund_payment_async_voucher'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_case_fund_refund add index idx_case_fund_refund_async_voucher (account_set_id, fiscal_year, period, voucher_status, del_flag, refund_date, source_row_no), algorithm=inplace, lock=none',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_case_fund_refund'
      and index_name = 'idx_case_fund_refund_async_voucher'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;
