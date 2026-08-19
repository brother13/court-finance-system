-- 凭证号整理业务来源同步索引（MySQL 5.7，幂等）。
--
-- 用途：将按 voucher_id 回写缴款、退付、不明款、分配清单凭证号的查询，
-- 从账套/年度范围扫描收敛为精确索引定位。
-- 执行说明：建议业务低峰执行；InnoDB 使用在线 INPLACE/LOCK=NONE 建索引。

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_case_fund_payment add index idx_case_fund_payment_voucher_sync (account_set_id, fiscal_year, voucher_id, voucher_status, del_flag), algorithm=inplace, lock=none',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_case_fund_payment'
      and index_name = 'idx_case_fund_payment_voucher_sync'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_case_fund_refund add index idx_case_fund_refund_voucher_sync (account_set_id, fiscal_year, voucher_id, voucher_status, del_flag), algorithm=inplace, lock=none',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_case_fund_refund'
      and index_name = 'idx_case_fund_refund_voucher_sync'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_case_fund_unknown_payment add index idx_case_fund_unknown_payment_voucher_sync (account_set_id, fiscal_year, voucher_id, voucher_status, del_flag), algorithm=inplace, lock=none',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_case_fund_unknown_payment'
      and index_name = 'idx_case_fund_unknown_payment_voucher_sync'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_case_fund_distribution add index idx_case_fund_distribution_voucher_sync (account_set_id, fiscal_year, voucher_id, voucher_status, del_flag), algorithm=inplace, lock=none',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_case_fund_distribution'
      and index_name = 'idx_case_fund_distribution_voucher_sync'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

-- 验证：应返回 20 行（4 个索引 × 每个索引 5 个字段），且 SEQ_IN_INDEX 依次覆盖
-- account_set_id、fiscal_year、voucher_id、voucher_status、del_flag。
select table_name, index_name, seq_in_index, column_name
from information_schema.statistics
where table_schema = database()
  and index_name in (
      'idx_case_fund_payment_voucher_sync',
      'idx_case_fund_refund_voucher_sync',
      'idx_case_fund_unknown_payment_voucher_sync',
      'idx_case_fund_distribution_voucher_sync'
  )
order by table_name, index_name, seq_in_index;
