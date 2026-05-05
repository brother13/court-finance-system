# Voucher Edit And Delete Design

## Business Rules

Unreviewed vouchers can be edited. In this system that means voucher status `DRAFT` or `SUBMITTED`.

Voucher deletion is a soft delete. It is allowed only when all of these are true:

- voucher status is `DRAFT` or `SUBMITTED`
- fiscal period status is `OPEN`
- voucher source is not `AUTO_CARRY`

Audited, posted, printed, voided, closed-period, and auto-carry vouchers cannot be deleted. Audited vouchers must be unaudited first, and closed-period or auto-carry vouchers remain protected even if their voucher status is unreviewed.

## Database

No new table or field is needed. Use existing fields:

- `fin_voucher_YYYY.status`
- `fin_voucher_YYYY.source_type`
- `fin_voucher_YYYY.del_flag`
- `fin_voucher_detail_YYYY.del_flag`
- `fin_voucher_aux_value_YYYY.del_flag`
- `fin_fiscal_period.status`

Deletion sets `del_flag = 1` on voucher header, detail rows, and auxiliary-value rows for the same account set and voucher id.

## Backend

Add voucher actions:

- `/voucher/delete` for one voucher
- `/voucher/batchDelete` for multiple vouchers

Both require `voucher:delete`. Both validate each voucher before any delete work is committed. Batch delete is atomic: if any selected voucher is not deletable, no selected voucher is deleted.

Deletion writes an audit log operation `DELETE` for each deleted voucher.

## Frontend

The voucher list adds:

- a selection checkbox column
- a batch-delete button
- row-level `编辑` for `DRAFT` and `SUBMITTED` vouchers when the user has `voucher:edit`
- row-level `删除` for `DRAFT` and `SUBMITTED` vouchers when the user has `voucher:delete`

Add an edit route that opens the existing voucher editor in editable mode. The detail route remains readonly.

## Testing

Add a focused PHP script for deletion eligibility rules. It reflects a pure helper and verifies allowed and blocked cases. Run the existing amount-summary script, the new deletion-rule script, PHP syntax check, and frontend build after implementation.
