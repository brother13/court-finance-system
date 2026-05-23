# Voucher Renumber Design

## Business Rules

Voucher renumbering is an internal-control operation for making voucher numbers continuous and date-ordered inside one account set and one accounting period.

The first implementation sorts vouchers by:

- `voucher_date asc`
- business order for the same date: payment or receipt vouchers first, refund or payout vouchers second
- original `voucher_no asc`
- `created_time asc`
- `voucher_id asc`

This is intended for the case-fund workflow where payment vouchers and refund vouchers are generated from separate imports, but vouchers with the same preparation date should appear next to each other in voucher-number order.

The operation must be previewed before it is applied. The preview shows every voucher whose number will change, including old voucher number, new voucher number, voucher date, summary, source type, status, and printed flag.

Renumbering is allowed only when all of these are true:

- the target period belongs to the current account set
- the fiscal period status is `OPEN`
- every affected voucher has `del_flag = 0`
- no affected voucher is `POSTED` or `VOIDED`
- no affected voucher is printed in the first implementation
- the current user has `voucher:renumber`

The first implementation should not renumber posted or printed vouchers. This keeps the feature aligned with the current accounting-control boundary: posted vouchers are accounting evidence, and printed vouchers may already have paper records.

Deleted vouchers are ignored. If a deleted voucher caused a missing number, the active vouchers are compacted to close the gap.

Renumbering changes only voucher header numbers and the voucher-number sequence. It must not alter voucher dates, summaries, debit or credit amounts, details, auxiliary-accounting values, source business rows, audit status, or posting status.

## Database

Use existing voucher storage:

- `fin_voucher.voucher_no`
- `fin_voucher.voucher_date`
- `fin_voucher.period`
- `fin_voucher.status`
- `fin_voucher.printed_flag`
- `fin_voucher.del_flag`
- `fin_voucher_no_sequence.current_no`

Add a renumber audit detail table:

```sql
create table fin_voucher_no_adjust_log (
    adjust_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    fiscal_year int not null,
    period varchar(7) not null,
    voucher_id varchar(36) not null,
    voucher_date date not null,
    voucher_word varchar(10) not null,
    old_voucher_no int not null,
    new_voucher_no int not null,
    reason varchar(200),
    created_by varchar(36),
    created_time datetime,
    updated_by varchar(36),
    updated_time datetime,
    del_flag int not null default 0,
    version int not null default 0,
    remark varchar(500)
);
```

Add indexes:

- `idx_voucher_no_adjust_period` on `account_set_id, fiscal_year, period, created_time`
- `idx_voucher_no_adjust_voucher` on `account_set_id, voucher_id`

Update `court_finance_ddl.sql` and `finance_standard_patch.sql`. The patch must be idempotent.

## Backend

Add voucher actions:

- `/voucher/renumberPreview`
- `/voucher/renumber`

Both require `voucher:renumber`.

`renumberPreview` accepts:

- `period`
- optional `start_no`, default `1`
- optional `business_order`, default `payment_refund`

It returns:

- `period`
- `start_no`
- `voucher_count`
- `change_count`
- `has_gap`
- `items`

Each `items` row includes:

- `voucher_id`
- `voucher_date`
- `voucher_word`
- `old_voucher_no`
- `new_voucher_no`
- `summary`
- `source_type`
- `status`
- `printed_flag`
- `business_sort`

`renumber` re-runs all validation and computes the same mapping on the server. It must not trust the frontend preview. The operation runs in one database transaction.

Because `uk_voucher_no` enforces unique voucher numbers inside a period, the update should use a two-phase number change:

1. Update affected vouchers to temporary negative numbers.
2. Update them to final positive numbers.
3. Update `fin_voucher_no_sequence.current_no` to the final maximum voucher number.
4. Insert one `fin_voucher_no_adjust_log` row for each changed voucher.
5. Write a `sys_audit_log` entry for the batch operation.

If any validation fails, no voucher number changes are committed.

Business classification for same-date sorting should be centralized in a helper:

- payment or receipt vouchers first when `source_type` or summary indicates case-fund payment, receipt, or collection
- refund or payout vouchers second when `source_type` or summary indicates case-fund refund, payout, or payment out
- unknown manual vouchers last, sorted by original number

The helper must be deterministic. When source type is not specific enough, it may fall back to summary prefixes already used by the case-fund voucher-generation rules.

## Frontend

In `VoucherListView.vue`, add `凭证号整理` under `批量操作` when the user has `voucher:renumber`.

The dialog contains:

- target period, defaulting to the current list period when it is a single period
- start number, default `1`
- a `检查` button
- a preview table for old number, new number, date, summary, source, status, and printed flag
- a confirmation button shown only after preview succeeds

The dialog must clearly warn:

`整理将按制单日期重排本期间凭证号，同一天缴款凭证在前、退款凭证在后。已记账、已作废、已打印凭证不参与第一版整理。`

After successful renumbering, reload the voucher list.

## Permissions

Add permission:

- `voucher:renumber` named `凭证号整理`

Seed it for system administrator and finance supervisor. Do not give it to ordinary preparer roles by default.

## Testing

Add backend tests for:

- active vouchers with dates `05-01 payment`, `05-02 payment`, `05-01 refund` become numbers `1, 3, 2` by voucher id mapping
- deleted vouchers are ignored and gaps are closed
- closed period blocks renumbering
- posted vouchers block renumbering
- printed vouchers block the first implementation
- the voucher number sequence is updated to the final max number
- audit detail rows record old and new numbers

Add frontend tests for:

- the `凭证号整理` command is visible only with `voucher:renumber`
- preview request payload contains the selected period and start number
- successful renumbering refreshes the list

Run focused PHP tests, focused frontend tests, and `npm run build`.
