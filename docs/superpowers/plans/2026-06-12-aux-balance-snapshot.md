# 辅助核算余额快照 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a persistent auxiliary balance snapshot so `/book/auxBalance` does not recompute from voucher master tables on every page load or export.

**Architecture:** `fin_aux_balance_snapshot` stores report-cache rows by account set, year, period, subject, case number, and receipt number. `Book::auxBalance()` refreshes the snapshot only when it is missing or older than voucher/opening source data, then filters and returns the existing tree response shape.

**Tech Stack:** PHP 7.4, ThinkPHP 5 query builder, MySQL 8 SQL scripts, existing script-style PHP tests.

---

### Task 1: Tests

**Files:**
- Modify: `backend/tests/BookAuxBalanceTest.php`

- [ ] Assert that `fin_aux_balance_snapshot` exists in DDL and upgrade scripts.
- [ ] Assert that `Book::auxBalance()` calls snapshot freshness and read helpers.
- [ ] Assert that the old SQL aggregation remains available for rebuilding snapshots.

### Task 2: Database

**Files:**
- Modify: `backend/database/court_finance_ddl.sql`
- Modify: `backend/database/finance_standard_patch.sql`

- [ ] Create `fin_aux_balance_snapshot`.
- [ ] Add unique key for `account_set_id, fiscal_year, period, subject_code, case_no, receipt_no`.
- [ ] Add query indexes for period, subject, case number, receipt number, and monitor flag.

### Task 3: Backend

**Files:**
- Modify: `backend/app/finance/model/Book.php`

- [ ] Add snapshot freshness detection.
- [ ] Add snapshot rebuild from current SQL aggregation.
- [ ] Add snapshot read path with subject, case number, receipt number, and monitor filters.
- [ ] Keep the response shape unchanged: case rows with receipt children.

### Task 4: Verification

**Commands:**
- `/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/BookAuxBalanceTest.php`
- `/Applications/MAMP/bin/php/php7.4.33/bin/php -l backend/app/finance/model/Book.php`
- `curl` against `/book/auxBalance` on the local PHP server using the production-connected local database.
