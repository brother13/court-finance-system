# 案款分配 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a CASE_FUND-only “案款分配” workflow with Excel import, list display, deletion, subject configuration, and voucher generation grouped by distribution number.

**Architecture:** Reuse the existing `caseFund` backend module and case-fund frontend patterns. Store imported allocation rows in a new `fin_case_fund_distribution` table, and generate internal transfer vouchers on the configured case-fund liability subject with `case_no + receipt_no` auxiliary values.

**Tech Stack:** PHP 7.4 / ThinkPHP 5 style backend, MySQL DDL and idempotent patch SQL, Vue 3 + TypeScript + Element Plus frontend, existing script-style PHP and Node tests.

---

## File Map

- Modify `backend/app/finance/model/CaseFund.php`
  - Add distribution constants, parser, validation, list/import/delete/generate APIs, voucher summaries, subject config item support.
- Modify `backend/database/court_finance_ddl.sql`
  - Add `fin_case_fund_distribution`.
- Modify `backend/database/finance_standard_patch.sql`
  - Add idempotent table creation and index migration.
- Modify `backend/database/migration_rbac.sql`
  - Add `menu:case_fund:distribution` and role grants.
- Create `backend/tests/CaseFundDistributionImportTest.php`
  - Parse sample Excel and lock import rules.
- Create `backend/tests/CaseFundDistributionGenerateVoucherTest.php`
  - Lock voucher direction, grouping, summaries, aux usage, route, and guards.
- Modify `backend/tests/CaseFundSubjectConfigTest.php`
  - Add `DISTRIBUTION` config assertions.
- Modify `backend/tests/CaseFundDeleteGuardTest.php`
  - Add distribution delete guard assertions.
- Modify `frontend/src/api/caseFund.ts`
  - Add distribution API wrappers and `DISTRIBUTION` subject config type.
- Modify `frontend/src/types/api.ts`
  - Add `CaseFundDistribution` and widen voucher config types.
- Create `frontend/src/views/case-fund/DistributionView.vue`
  - Implement list, import, delete, generate voucher, and subject config UI.
- Modify `frontend/src/router/index.ts`
  - Add `/case-fund/distributions`.
- Modify `frontend/src/App.vue`
  - Add CASE_FUND-only menu entry and title map.
- Modify `frontend/src/views/dashboard/DashboardView.vue`
  - Add quick entry.
- Modify `frontend/tests/CaseFundMenuTest.mjs`
  - Add menu/route/RBAC assertions.
- Modify `frontend/tests/CaseFundDeleteActionsTest.mjs`
  - Add distribution delete UI assertions.
- Create `frontend/tests/CaseFundDistributionViewTest.mjs`
  - Assert API wiring and page behavior strings.

## Task 1: Backend DDL and Import Parser

- [ ] **Step 1: Write failing parser/import test**

Create `backend/tests/CaseFundDistributionImportTest.php` with assertions:

```php
$parseMethod = new ReflectionMethod(CaseFund::class, 'parseDistributionImportRowsFromXls');
$rows = $parseMethod->invoke($caseFund, file_get_contents(__DIR__ . '/../../分配清单(带单据号) (3).xls'));
assert(count($rows) === 32);
assert($rows[0]['distribution_no'] === 'ZXFP60726051900001');
assert($rows[0]['in_case_no'] === '（2024）辽0111执3548号');
assert($rows[0]['out_case_no'] === '（2017）辽0111执611号');
assert($rows[0]['invoice_type'] === '执行、调解款');
assert($rows[0]['receipt_no'] === '0001759490');
assert($rows[0]['distribution_amount'] === '5084.00');
assert(array_sum(array_map('distributionImportCents', $rows)) === 327331559);
```

Also assert `validateDistributionRows($rows, 'CASE_FUND')` returns no errors, `validateDistributionRows($rows, 'LITIGATION_FEE')` rejects, and the DDL/patch contain `fin_case_fund_distribution`.

- [ ] **Step 2: Run red**

Run:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundDistributionImportTest.php
```

Expected: FAIL because parser/table constants do not exist.

- [ ] **Step 3: Implement DDL and parser**

In `CaseFund.php`, add `TABLE_DISTRIBUTION`, `DISTRIBUTION_FIELD`, `parseDistributionImportRowsFromXls`, `validateDistributionRows`, `distributionFingerprint`, and helper methods for invoice type validation. In SQL files, add the `fin_case_fund_distribution` table and indexes.

- [ ] **Step 4: Run green**

Run:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundDistributionImportTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php -l backend/app/finance/model/CaseFund.php
```

Expected: PASS.

## Task 2: Backend List, Import, Delete, and Subject Config

- [ ] **Step 1: Extend failing tests**

Update `CaseFundDistributionImportTest.php`, `CaseFundDeleteGuardTest.php`, and `CaseFundSubjectConfigTest.php` to assert these source strings:

```php
"case 'distributionList'"
"case 'distributionImport'"
"case 'distributionDelete'"
"requirePermission('case_fund:view')"
"requirePermission('case_fund:import')"
"requirePermission('case_fund:delete')"
"'CASE_FUND_DISTRIBUTION'"
"'DISTRIBUTION'"
"['执行、调解款']"
```

- [ ] **Step 2: Run red**

Run the three tests. Expected: FAIL on missing routes/methods.

- [ ] **Step 3: Implement list/import/delete/config support**

Add `distributionList`, `distributionImport`, `distributionDelete`; extend `deleteCaseFundBusinessRows` for `DISTRIBUTION`; extend `allowedSubjectConfigItems` and `normalizeVoucherBizType` for `DISTRIBUTION`. All APIs reject non-`CASE_FUND`.

- [ ] **Step 4: Run green**

Run:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundDistributionImportTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundDeleteGuardTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundSubjectConfigTest.php
```

Expected: PASS.

## Task 3: Backend Voucher Generation

- [ ] **Step 1: Write failing voucher generation test**

Create `backend/tests/CaseFundDistributionGenerateVoucherTest.php` to assert:

```php
"case 'distributionGenerateVoucher'"
"public function distributionGenerateVoucher"
"requirePermission('case_fund:generate_voucher')"
"voucher_biz_type' => 'DISTRIBUTION'"
"buildDistributionDebitSummary"
"buildDistributionCreditSummary"
"ensureDistributionAuxArchives"
"fin_voucher_aux_value"
"groupDistributionRowsForVoucher"
"order('receive_date asc, distribution_no asc, source_row_no asc')"
"lock(true)"
"count($rows) !== count($distributionIds)"
"'SUBMITTED'"
"'BUSINESS'"
"CASE_FUND_DISTRIBUTION"
```

Use a probe class to assert:

```php
debit summary = '分配转出 （2017）辽0111执611号 李洁 单据号0001759490'
credit summary = '分配转入 （2024）辽0111执3548号 吴健 单据号0001759490'
header summary = '案款分配 ZXFP60726051900001'
```

- [ ] **Step 2: Run red**

Run the new test. Expected: FAIL.

- [ ] **Step 3: Implement voucher generation**

Add `distributionGenerateVoucher`. It should lock selected rows in a transaction, require exact row count, guard regenerated rows with `assertRowsCanGenerateVoucher`, require a `DISTRIBUTION` subject config per invoice type, validate subjects and required aux support, group by `distribution_no`, generate one submitted business voucher per group, insert two detail rows per distribution row, insert aux values using `out_case_no + receipt_no` on debit and `in_case_no + receipt_no` on credit, then update source rows to `GENERATED`.

- [ ] **Step 4: Run green**

Run:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundDistributionGenerateVoucherTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundDistributionImportTest.php
```

Expected: PASS.

## Task 4: Frontend API, Types, Menu, and Route

- [ ] **Step 1: Write failing frontend tests**

Update `CaseFundMenuTest.mjs` and create `CaseFundDistributionViewTest.mjs` to assert:

```js
"menu:case_fund:distribution"
"/case-fund/distributions"
"案款分配"
"DistributionView"
"distributionList"
"importDistributions"
"deleteDistributions"
"distributionGenerateVoucher"
"'DISTRIBUTION'"
```

- [ ] **Step 2: Run red**

Run:

```bash
node frontend/tests/CaseFundMenuTest.mjs
node frontend/tests/CaseFundDistributionViewTest.mjs
```

Expected: FAIL.

- [ ] **Step 3: Implement frontend wiring**

Add `CaseFundDistribution` type, API wrappers, router entry, menu item, title map, dashboard quick entry, and RBAC seed.

- [ ] **Step 4: Run green**

Run the two tests. Expected: PASS.

## Task 5: Frontend Distribution Page

- [ ] **Step 1: Extend failing UI tests**

Update `CaseFundDeleteActionsTest.mjs` and `CaseFundDistributionViewTest.mjs` to assert:

```js
"v-permission=\"'case_fund:delete'\""
"caseFundApi.deleteDistributions"
"selectedDistributionRows.value.some((row) => row.voucher_status !== 'UNGENERATED')"
"caseFundApi.distributionGenerateVoucher"
"caseFundApi.importDistributions"
"caseFundApi.subjectConfigList(voucherBizType)"
"const voucherBizType: 'DISTRIBUTION' = 'DISTRIBUTION'"
```

- [ ] **Step 2: Run red**

Run affected frontend tests. Expected: FAIL until page exists.

- [ ] **Step 3: Implement `DistributionView.vue`**

Mirror `PaymentRegisterView.vue` shape with distribution fields. Include filters, summary cards, table, import input, generate voucher, delete, and subject config dialog.

- [ ] **Step 4: Run green**

Run:

```bash
node frontend/tests/CaseFundDeleteActionsTest.mjs
node frontend/tests/CaseFundDistributionViewTest.mjs
npm run build
```

Expected: PASS.

## Task 6: Final Verification

- [ ] Run backend tests:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundDistributionImportTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundDistributionGenerateVoucherTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundSubjectConfigTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundDeleteGuardTest.php
```

- [ ] Run frontend tests:

```bash
node frontend/tests/CaseFundMenuTest.mjs
node frontend/tests/CaseFundDeleteActionsTest.mjs
node frontend/tests/CaseFundDistributionViewTest.mjs
```

- [ ] Run build:

```bash
cd frontend && npm run build
```

- [ ] Run whitespace check:

```bash
git diff --check -- backend/app/finance/model/CaseFund.php backend/database/court_finance_ddl.sql backend/database/finance_standard_patch.sql backend/database/migration_rbac.sql backend/tests/CaseFundDistributionImportTest.php backend/tests/CaseFundDistributionGenerateVoucherTest.php backend/tests/CaseFundSubjectConfigTest.php backend/tests/CaseFundDeleteGuardTest.php frontend/src/api/caseFund.ts frontend/src/types/api.ts frontend/src/router/index.ts frontend/src/App.vue frontend/src/views/dashboard/DashboardView.vue frontend/src/views/case-fund/DistributionView.vue frontend/tests/CaseFundMenuTest.mjs frontend/tests/CaseFundDeleteActionsTest.mjs frontend/tests/CaseFundDistributionViewTest.mjs
```

## Self-Review

- Spec coverage: table, import, list, delete, subject config, menu, route, page, voucher generation, audit and tests are covered.
- Placeholder scan: no TBD/TODO placeholders are used.
- Type consistency: backend uses `distribution_id`, frontend uses `CaseFundDistribution`, voucher config type is `DISTRIBUTION`, route is `/case-fund/distributions`.
