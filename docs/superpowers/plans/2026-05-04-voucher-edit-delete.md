# Voucher Edit And Delete Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow editing unreviewed vouchers and add safe single and batch voucher deletion.

**Architecture:** Reuse the current `VoucherEditorView.vue` and distinguish readonly detail mode from editable edit mode through route metadata. Add backend delete actions that share one eligibility helper and use soft delete in one transaction.

**Tech Stack:** ThinkPHP 5 style PHP backend, Vue 3 + TypeScript + Element Plus frontend.

---

### Task 1: Backend Delete Rules

**Files:**
- Create: `backend/tests/VoucherDeleteEligibilityTest.php`
- Modify: `backend/app/finance/model/Voucher.php`

- [ ] **Step 1: Write a failing deletion-rule test**

Create a PHP script that reflects `Voucher::voucherDeleteBlockReason()` and checks:

- `SUBMITTED` + `OPEN` + `MANUAL` returns an empty string
- `DRAFT` + `OPEN` + empty source returns an empty string
- `AUDITED` + `OPEN` + `MANUAL` returns a non-empty reason
- `SUBMITTED` + `CLOSED` + `MANUAL` returns a non-empty reason
- `SUBMITTED` + `OPEN` + `AUTO_CARRY` returns a non-empty reason

- [ ] **Step 2: Run the test to confirm red**

Run: `/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/VoucherDeleteEligibilityTest.php`

Expected: fails because `voucherDeleteBlockReason()` does not exist.

- [ ] **Step 3: Implement delete actions**

Add actions `delete` and `batchDelete`. Add helper methods to load fiscal-period status, validate deletability, and soft-delete voucher header, detail rows, and auxiliary-value rows.

- [ ] **Step 4: Run the deletion-rule test**

Run: `/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/VoucherDeleteEligibilityTest.php`

Expected: prints `Voucher delete eligibility test passed`.

### Task 2: Frontend Edit And Delete Controls

**Files:**
- Modify: `frontend/src/router/index.ts`
- Modify: `frontend/src/api/voucher.ts`
- Modify: `frontend/src/views/voucher/VoucherEditorView.vue`
- Modify: `frontend/src/views/voucher/VoucherListView.vue`

- [ ] **Step 1: Add the edit route**

Add `/vouchers/edit/:period/:voucherId` with `voucher:edit` permission and route meta `{ mode: 'edit' }`. Set the existing detail route meta to `{ mode: 'view' }`.

- [ ] **Step 2: Make the editor mode-aware**

Replace the current `isViewMode` rule with route-meta mode. Include `voucher_id` in the save payload for edit mode so `Voucher::saveVoucher()` updates the existing voucher.

- [ ] **Step 3: Add API wrappers**

Add `remove(period, voucherId)` and `batchRemove(period, voucherIds)` wrappers.

- [ ] **Step 4: Add list selection and row buttons**

Add table selection, `编辑`, `删除`, and `批量删除` controls. Confirm deletion with Element Plus message boxes, then reload the list after success.

### Task 3: Verification

**Files:**
- Verify backend and frontend changes.

- [ ] **Step 1: Run PHP tests**

Run:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/VoucherAmountSummaryTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/VoucherDeleteEligibilityTest.php
```

- [ ] **Step 2: Run PHP syntax check**

Run: `/Applications/MAMP/bin/php/php7.4.33/bin/php -l backend/app/finance/model/Voucher.php`

- [ ] **Step 3: Run frontend build**

Run from `frontend`: `npm run build`
