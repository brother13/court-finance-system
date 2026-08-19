-- 修复历史作废凭证状态与作废标记不一致的问题。
-- 口径：status=VOIDED 的有效凭证必须同时满足 void_flag=1。
-- 本脚本只补齐作废标记，不修改凭证明细、金额、凭证号或会计期间，可重复执行。

start transaction;

select count(*) as pending_repair_count
from fin_voucher
where status = 'VOIDED'
  and void_flag <> '1'
  and del_flag = 0;

update fin_voucher
set void_flag = '1',
    updated_by = 'system',
    updated_time = current_timestamp
where status = 'VOIDED'
  and void_flag <> '1'
  and del_flag = 0;

select row_count() as repaired_count;

commit;
