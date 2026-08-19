-- 2026-08-16 不明款案号辅助核算支持（MySQL 5.7+）
-- 仅增加稳定 BMK 编号字段、账套内唯一约束，并扩展辅助档案编码长度。
-- 不回填历史不明款，不迁移历史凭证，不生成辅助期初；这些数据由用户按已确认方案处理。

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_case_fund_unknown_payment add column aux_case_code varchar(20) null after bank_remark',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_case_fund_unknown_payment'
      and column_name = 'aux_case_code'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_case_fund_unknown_payment add unique key uk_case_fund_unknown_payment_aux_code (account_set_id, aux_case_code)',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_case_fund_unknown_payment'
      and index_name = 'uk_case_fund_unknown_payment_aux_code'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        coalesce(max(character_maximum_length), 0) < 200,
        'alter table fin_aux_archive modify column archive_code varchar(200) not null',
        'select 1'
    )
    from information_schema.columns
    where table_schema = database()
      and table_name = 'fin_aux_archive'
      and column_name = 'archive_code'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;
