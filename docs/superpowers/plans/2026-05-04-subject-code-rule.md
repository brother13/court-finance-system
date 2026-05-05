# Subject Code Rule Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add account-level subject code rules, tree subject display, and subject-code validation when creating or editing subjects.

**Architecture:** Store the rule on `fin_account_set.subject_code_rule`, expose it through the existing `/subject/*` model endpoints, and enforce it in `Subject::save()`. The Vue page consumes the rule, renders subjects as an Element Plus tree table by current subject type, and provides a rule-setting dialog matching the supplied screenshot.

**Tech Stack:** ThinkPHP 5/PHP 7.4 backend, MySQL-compatible SQL, Vue 3 + TypeScript + Element Plus frontend.

---

### Task 1: Backend Rule Helpers

**Files:**
- Modify: `backend/app/finance/model/Subject.php`
- Test: `backend/tests/SubjectCodeRuleTest.php`

- [x] **Step 1: Write the failing test**

`backend/tests/SubjectCodeRuleTest.php` reflects `normalizeCodeRule`, `codeRuleLengths`, and `validateSubjectCodeRule`.

- [x] **Step 2: Run test to verify it fails**

Run: `/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/SubjectCodeRuleTest.php`

Expected: FAIL because `normalizeCodeRule()` does not exist.

- [ ] **Step 3: Add protected helper methods**

Add `normalizeCodeRule($rule)`, `codeRuleLengths($segments)`, and `validateSubjectCodeRule($code, $parentCode, $segments)` to `Subject`.

- [ ] **Step 4: Run helper test**

Run: `/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/SubjectCodeRuleTest.php`

Expected: PASS.

### Task 2: Backend Endpoints And Persistence

**Files:**
- Modify: `backend/app/finance/model/Subject.php`
- Modify: `backend/database/court_finance_ddl.sql`
- Modify: `backend/database/account_sets_preset.sql`
- Modify: `backend/database/preset_case_fund_template.sql`
- Modify: `backend/database/finance_standard_patch.sql`
- Modify: `frontend/src/api/base.ts`
- Modify: `frontend/src/types/api.ts`

- [ ] **Step 1: Add `/subject/codeRule` and `/subject/codeRuleSave`**

Read and write `fin_account_set.subject_code_rule` scoped by the current account set and `del_flag = 0`.

- [ ] **Step 2: Enforce rule in `save()`**

Normalize the current rule, validate code and parent prefix/length, set `level_no` from the validated code, and require parent existence for non-top-level codes.

- [ ] **Step 3: Update SQL DDL and patch**

Add `subject_code_rule varchar(50) not null default '4-2-2-2'` to the account-set table, presets, and idempotent upgrade patch.

- [ ] **Step 4: Add frontend API typings**

Expose `subjectCodeRule()` and `saveSubjectCodeRule(rule)` in `baseApi`.

### Task 3: Frontend Subject Page

**Files:**
- Modify: `frontend/src/views/base/SubjectManageView.vue`

- [ ] **Step 1: Replace side filter with top tabs**

Use six subject type tabs and keep current type filtering.

- [ ] **Step 2: Render tree table**

Build `children` arrays by `parent_code`, render `el-table` with `row-key="subject_code"` and `tree-props`.

- [ ] **Step 3: Add code rule dialog**

Display `科目编码规则：4 - 2 - 2 - 2`, allow adding/removing trailing segments, and persist through API.

- [ ] **Step 4: Add create/edit validation hints**

When a parent is selected, show expected prefix and length, and block save before backend call if the code violates the active rule.

### Task 4: Verification

**Files:**
- Test: `backend/tests/SubjectCodeRuleTest.php`
- Build: `frontend/package.json`

- [ ] **Step 1: Run PHP helper test**

Run: `/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/SubjectCodeRuleTest.php`

Expected: `Subject code rule test passed`.

- [ ] **Step 2: Run frontend build**

Run: `npm run build` in `frontend`.

Expected: Vue type-check and Vite build pass.

