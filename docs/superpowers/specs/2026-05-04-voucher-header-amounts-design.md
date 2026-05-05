# Voucher Header Amounts Design

## Business Rule

When a voucher is saved, the voucher header stores the voucher-level debit and credit totals. The source of truth remains the voucher detail lines. The save flow still rejects unbalanced vouchers, zero-amount vouchers, negative line amounts, and lines that contain both debit and credit.

Amounts are summed in integer cents and converted back to `decimal(18,2)` strings before persistence to avoid floating point drift.

## Database

Add two columns to each yearly voucher header table, starting with `fin_voucher_2026`:

```sql
debit_amount decimal(18,2) not null default 0
credit_amount decimal(18,2) not null default 0
```

In `fin_voucher_2026`, these fields mean full-voucher totals. In `fin_voucher_detail_2026`, the same field names continue to mean single detail-line amounts.

## Backend Flow

`/voucher/draft`, `/voucher/submit`, and `/voucher/save` all call `Voucher::saveVoucher()`.

1. Validate period, voucher date, detail count, voucher balance, and line rules.
2. Sum detail-line debit and credit amounts in cents.
3. Write `debit_amount` and `credit_amount` to the voucher header on create and update.
4. Continue writing detail rows and auxiliary accounting rows as before.
5. `/voucher/list` and `/voucher/info` return the header totals as regular row fields.

## Frontend Flow

The voucher center list adds two amount columns:

- `借方金额` from voucher header `debit_amount`
- `贷方金额` from voucher header `credit_amount`

The list does not calculate totals client-side.

## Testing

Add a focused PHP unit-style script for voucher amount summing. Verify the script fails before the helper exists and passes after implementation. Run the frontend production build to verify Vue and TypeScript changes.
