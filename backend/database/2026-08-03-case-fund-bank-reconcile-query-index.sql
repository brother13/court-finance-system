-- 案款银行自动对账范围查询与结果替换索引。
--
-- 当前自动对账按 account_set_id + fiscal_year + del_flag + 业务日期范围读取
-- 银行流水、缴费、退付和未认领款，并按同一日期、流水号或对方账号匹配；
-- 重算时还会软删除日期范围内的旧自动匹配结果，并按 statement_id 查询有效关联。
-- 任务头 period 只是提交上下文，跨月对账范围必须由业务日期决定，因此不进入索引。
--
-- MySQL 8.0：二级索引使用 INPLACE + LOCK=NONE 在线创建；脚本不修改业务数据，
-- 并通过 information_schema 按索引名判断，可重复执行。

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_case_fund_bank_statement add index idx_case_fund_bank_statement_reconcile_scope (account_set_id, fiscal_year, del_flag, transaction_date, direction, counterparty_account_no), algorithm=inplace, lock=none',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_case_fund_bank_statement'
      and index_name = 'idx_case_fund_bank_statement_reconcile_scope'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_case_fund_payment add index idx_case_fund_payment_reconcile_scope (account_set_id, fiscal_year, del_flag, payment_date, bank_serial_no), algorithm=inplace, lock=none',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_case_fund_payment'
      and index_name = 'idx_case_fund_payment_reconcile_scope'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_case_fund_refund add index idx_case_fund_refund_reconcile_scope (account_set_id, fiscal_year, del_flag, refund_date, payee_bank_account_no), algorithm=inplace, lock=none',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_case_fund_refund'
      and index_name = 'idx_case_fund_refund_reconcile_scope'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_case_fund_unknown_payment add index idx_case_fund_unknown_reconcile_scope (account_set_id, fiscal_year, del_flag, transaction_date, claim_status, payer_account_no), algorithm=inplace, lock=none',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_case_fund_unknown_payment'
      and index_name = 'idx_case_fund_unknown_reconcile_scope'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_case_fund_bank_reconcile add index idx_case_fund_bank_reconcile_replace_scope (account_set_id, fiscal_year, del_flag, match_rule, reconcile_date), algorithm=inplace, lock=none',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_case_fund_bank_reconcile'
      and index_name = 'idx_case_fund_bank_reconcile_replace_scope'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_case_fund_bank_reconcile add index idx_case_fund_bank_reconcile_active_statement (account_set_id, fiscal_year, del_flag, statement_id), algorithm=inplace, lock=none',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_case_fund_bank_reconcile'
      and index_name = 'idx_case_fund_bank_reconcile_active_statement'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;
