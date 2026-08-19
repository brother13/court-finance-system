-- MySQL 8.0 auxiliary balance pagination and on-demand case detail indexes.
-- This patch is idempotent and does not change business data.

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_aux_balance_snapshot add index idx_aux_balance_snapshot_page (account_set_id, fiscal_year, period, del_flag, subject_code, case_no)',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_aux_balance_snapshot'
      and index_name = 'idx_aux_balance_snapshot_page'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql = (
    select if(
        count(*) = 0,
        'alter table fin_voucher_aux_value add index idx_voucher_aux_label_lookup (account_set_id, aux_type_code, aux_label, del_flag, fiscal_year, period)',
        'select 1'
    )
    from information_schema.statistics
    where table_schema = database()
      and table_name = 'fin_voucher_aux_value'
      and index_name = 'idx_voucher_aux_label_lookup'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;
