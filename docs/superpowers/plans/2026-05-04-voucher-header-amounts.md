# Voucher Header Amounts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Store voucher-level debit and credit totals in the yearly voucher header table and show them in the voucher center list.

**Architecture:** Keep detail lines as the source of truth. Add a small `Voucher` helper that sums detail amounts in cents, then reuse it in `saveVoucher()` for both create and update paths. The frontend list reads the returned header fields directly.

**Tech Stack:** ThinkPHP 5 style PHP backend, MySQL DDL and migration SQL, Vue 3 + TypeScript + Element Plus frontend.

---

### Task 1: Backend Amount Summary

**Files:**
- Create: `backend/tests/VoucherAmountSummaryTest.php`
- Modify: `backend/app/finance/model/Voucher.php`

- [ ] **Step 1: Write the failing test**

Create a PHP script that reflects `Voucher::sumVoucherAmounts()` and checks that `10.10 + 0.20` debit totals to `10.30`, credit totals to `10.30`, and blank amounts are treated as zero.

- [ ] **Step 2: Run the test to verify it fails**

Run: `/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/VoucherAmountSummaryTest.php`

Expected: fails because `sumVoucherAmounts()` does not exist yet.

- [ ] **Step 3: Implement the helper and persistence**

Add `sumVoucherAmounts($details)` to `Voucher.php`, then set `debit_amount` and `credit_amount` on the voucher header array in both the insert and update branches of `saveVoucher()`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/VoucherAmountSummaryTest.php`

Expected: prints `Voucher amount summary test passed`.

### Task 2: Database Fields

**Files:**
- Modify: `backend/database/court_finance_ddl.sql`
- Modify: `backend/database/finance_standard_patch.sql`

- [ ] **Step 1: Update the base DDL**

Add `debit_amount` and `credit_amount` to `fin_voucher_2026` after `summary`.

- [ ] **Step 2: Update the patch script**

Add idempotent `alter table` statements for the current yearly voucher table so existing databases can receive the two fields.

### Task 3: Voucher List Display

**Files:**
- Modify: `frontend/src/types/api.ts`
- Modify: `frontend/src/views/voucher/VoucherListView.vue`

- [ ] **Step 1: Extend the voucher type**

Add optional `debit_amount`, `credit_amount`, `debitAmount`, and `creditAmount` fields to `Voucher`.

- [ ] **Step 2: Add list columns**

Add `借方金额` and `贷方金额` columns in the voucher center table and format values as two-decimal yuan amounts.

- [ ] **Step 3: Verify the frontend**

Run: `npm run build` from `frontend`.

Expected: TypeScript check and Vite build complete successfully.
