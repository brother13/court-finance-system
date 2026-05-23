# Voucher Renumber Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a controlled voucher-number renumbering feature that reorders one open period by preparation date so same-day payment and refund vouchers sit together.

**Architecture:** Keep the behavior inside the existing `Voucher` model and `/voucher/*` action style. Add a preview endpoint and an execution endpoint; both compute the same server-side mapping from active vouchers, enforce accounting-control guards, and write audit detail rows plus normal audit logs. Add a small frontend dialog in the voucher center that calls preview first and execution second.

**Tech Stack:** PHP 7.4 / ThinkPHP 5 style backend, MySQL migration SQL, Vue 3 / TypeScript / Element Plus frontend, existing script-style PHP and Node tests.

---

### Task 1: Backend Renumber Rules

**Files:**
- Modify: `backend/app/finance/model/Voucher.php`
- Test: `backend/tests/VoucherRenumberTest.php`

- [ ] **Step 1: Write the failing backend test**

Create `backend/tests/VoucherRenumberTest.php` with seeded in-memory expectations against public wrapper methods or direct API calls. Cover date-first ordering, same-day payment before refund, deleted voucher gap compression, posted/printed/closed-period blocking, sequence update, and adjustment log rows.

- [ ] **Step 2: Run the backend test to verify RED**

Run: `/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/VoucherRenumberTest.php`

Expected: FAIL because `/voucher/renumberPreview` and `/voucher/renumber` do not exist yet.

- [ ] **Step 3: Implement backend actions**

In `Voucher::index`, add `renumberPreview` and `renumber`.

In `Voucher.php`, add helpers:

- `renumberPreview($data)`
- `renumber($data)`
- `buildVoucherRenumberPlan($period, $startNo)`
- `voucherRenumberBusinessSort($voucher)`
- `assertVoucherRenumberAllowed($period, $rows)`
- `updateVoucherNoSequenceExact($period, $maxNo)`

Use active vouchers only: `account_set_id`, `fiscal_year`, `period`, `del_flag = 0`.

Sort by `voucher_date`, business sort, original `voucher_no`, `created_time`, `voucher_id`. Business sort returns payment first for summary/source values beginning with or containing `收`, receipt, payment-in terms; refund/payout second for `付`, refund, payout terms; unknown last.

Execution must start a transaction, re-read and lock vouchers, assign temporary negative numbers, assign final positive numbers, update the sequence, insert adjustment rows, and write an audit log.

- [ ] **Step 4: Run backend test to verify GREEN**

Run: `/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/VoucherRenumberTest.php`

Expected: PASS.

### Task 2: Database And Permission Scripts

**Files:**
- Modify: `backend/database/court_finance_ddl.sql`
- Modify: `backend/database/finance_standard_patch.sql`
- Modify: `backend/database/migration_rbac.sql`
- Test: backend syntax and SQL text assertions inside `backend/tests/VoucherRenumberTest.php`

- [ ] **Step 1: Extend failing assertions**

Add assertions that the DDL and patch contain `fin_voucher_no_adjust_log`, and RBAC contains `voucher:renumber`.

- [ ] **Step 2: Run test to verify RED**

Run: `/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/VoucherRenumberTest.php`

Expected: FAIL until SQL scripts are updated.

- [ ] **Step 3: Update SQL scripts**

Add `fin_voucher_no_adjust_log` and its indexes to the DDL. Add idempotent creation logic to `finance_standard_patch.sql`. Add `voucher:renumber` permission and grants for system administrator and finance supervisor in `migration_rbac.sql`.

- [ ] **Step 4: Run test to verify GREEN**

Run: `/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/VoucherRenumberTest.php`

Expected: PASS.

### Task 3: Frontend API And Dialog

**Files:**
- Modify: `frontend/src/api/voucher.ts`
- Modify: `frontend/src/views/voucher/VoucherListView.vue`
- Test: `frontend/tests/VoucherRenumberDialogTest.mjs`

- [ ] **Step 1: Write failing frontend test**

Create `frontend/tests/VoucherRenumberDialogTest.mjs`. Assert that `voucher:renumber` appears in the batch operations, that `voucherApi.renumberPreview` and `voucherApi.renumber` call the expected actions, and that the dialog includes the warning text about date-order sorting and same-day payment/refund order.

- [ ] **Step 2: Run frontend test to verify RED**

Run: `node frontend/tests/VoucherRenumberDialogTest.mjs`

Expected: FAIL because the API wrappers and dialog do not exist.

- [ ] **Step 3: Implement frontend feature**

Add `renumberPreview` and `renumber` wrappers to `voucherApi`.

In `VoucherListView.vue`, add a `凭证号整理` dropdown item when `context.hasPermission('voucher:renumber')`. Add an Element Plus dialog with period, start number, preview table, warning text, `检查`, and `确认整理`. On success, close the dialog and reload the voucher list.

- [ ] **Step 4: Run frontend test to verify GREEN**

Run: `node frontend/tests/VoucherRenumberDialogTest.mjs`

Expected: PASS.

### Task 4: Verification

**Files:**
- Verify all touched files

- [ ] **Step 1: Run focused backend tests**

Run:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/VoucherRenumberTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/VoucherDeleteEligibilityTest.php
```

Expected: PASS.

- [ ] **Step 2: Run focused frontend tests**

Run:

```bash
node frontend/tests/VoucherRenumberDialogTest.mjs
node frontend/tests/VoucherListOperationColumnTest.mjs
```

Expected: PASS.

- [ ] **Step 3: Run frontend build**

Run: `cd frontend && npm run build`

Expected: PASS.
