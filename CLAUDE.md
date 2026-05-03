# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**法院专项账务记账系统 (Court Special Accounting System / CSAS)** — a general-purpose accounting platform for courts' intranet, designed as a "microkernel + business plugin" system. The double-entry voucher engine is generic; business-specific attributes (case number, receipt number, party, etc.) are layered on via an EAV-style auxiliary accounting model.

Each court is deployed independently. There is **no multi-tenant (`customer_id`) layer** — only **account-set (`account_set_id`)** isolation within a deployment.

> **Tech stack note:** `AGENTS.md` describes a Java/Spring Boot/MyBatis-Plus stack as the *intended/long-term* direction. The **current code is PHP**. Trust `backend/README.md` and `docs/design/phase1-implementation.md` for what actually exists. Do not "migrate" code to Java without explicit instruction.

- **Backend**: PHP 7.4.33, ThinkPHP 5.0.24-style single-entry MVC, MySQL 8.0
- **Frontend**: Vue 3.5 + TypeScript + Vite 5 + Element Plus 2.8 + Pinia 2
- **Local PHP runtime**: MAMP's bundled PHP at `/Applications/MAMP/bin/php/php7.4.33/bin/php`

## Common Commands

### Backend (PHP)

```bash
# Start the dev server (from repo root)
/Applications/MAMP/bin/php/php7.4.33/bin/php -S 127.0.0.1:8080 -t backend/public

# Smoke test
curl "http://127.0.0.1:8080/?action=/sys/info"

# Sample API call
curl -X POST "http://127.0.0.1:8080/" \
  -d "action=/voucher/list" \
  -d "account_set_id=00000000-0000-0000-0000-000000000101" \
  -d 'data={"period":"2026-05"}'
```

There is no PHP build, lint, or test command configured in this repo.

### Frontend (Vue)

```bash
cd frontend
npm install                # First time only
npm run dev                # Vite dev server on http://localhost:5173 (binds 0.0.0.0)
npm run build              # vue-tsc type-check + vite build → frontend/dist/
npm run preview            # Preview production build
```

There is no separate lint or unit-test command; `npm run build` runs `vue-tsc --noEmit` as the type-checker gate.

### Database Initialization

```sql
CREATE DATABASE `court-finance` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
```

Then load DDL and presets in order:

```text
backend/database/court_finance_ddl.sql
backend/database/preset_case_fund_template.sql
```

Connection settings live in [backend/app/database.php](backend/app/database.php) (defaults to `localhost:3306`, `root/root`, db `court-finance`). The default account set UUID is in [backend/app/finance/config.php](backend/app/finance/config.php).

## Backend Architecture

### Single-Entry, Action-Dispatched API

All requests hit [backend/public/index.php](backend/public/index.php) which boots ThinkPHP, then [backend/app/finance/controller/Index.php](backend/app/finance/controller/Index.php) routes by parsing the `action` parameter.

```
POST /?action=/voucher/submit
  account_set_id=<uuid>
  data={...JSON payload...}
```

Action format is **`/<module>/<method>`**. `Index::index()` switches on `actionArr[1]` (module) and forwards to `<Model>->index($actionArr[2], $postdata)`. Each model implements a `public function index($action, $data)` that switches on the method name. To add a new endpoint:

1. Add a `case` in the model's `index()` method.
2. If it's a new module, add a `case` in the controller's switch and a model class under [backend/app/finance/model/](backend/app/finance/model/).

Active module models: `Subject` (科目), `Aux` (辅助核算), `Voucher` (凭证), `Book` (账簿), `Period` (会计期间), `Log` (审计日志).

### Response Envelope

Models return arrays shaped like:

```php
['code' => 20000 | 0, 'message' => '...', 'data' => ..., 'total' => ?]
```

`code: 20000` = success, `code: 0` = error. The controller normalizes this into the outer envelope (adds `action`, `time`, `page`, `pagesize`).

### Common Base Model

[backend/app/finance/model/Common.php](backend/app/finance/model/Common.php) is the parent every business model extends. It provides:

- **Account-set scoping**: `accountWhere()` returns `['account_set_id' => ..., 'del_flag' => 0]` — **always start `where()` clauses with this** so queries are isolated per account set and exclude soft-deleted rows.
- **Audit fields**: `fillCreate(&$data)` / `fillUpdate(&$data)` populate `created_by/created_time/updated_by/updated_time/del_flag/version`. User identity comes from `X-User-Id` / `X-User-Name` request headers (defaults to `'system'`).
- **Year-table helper**: `yearTable($base, $period)` → `${base}_${YYYY}` (e.g. `fin_voucher` + `2026-05` → `fin_voucher_2026`). Always use this for `fin_voucher`, `fin_voucher_detail`, `fin_voucher_aux_value` — they are partitioned by year.
- **Money handling**: `decimalToCents()` / `centsToDecimal()`. **All amount comparisons and sums must convert to integer cents first** to avoid PHP float drift. The DDL stores `decimal(18,2)`, but in-memory math uses cents.
- **Audit logging**: `logAudit($bizType, $bizId, $operation, $before, $after)` writes to `sys_audit_log`. Call this on every write (create/update/audit/unaudit/void/print/close-period).
- **Standard responses**: `ok($data, $message, $total)` and `error($message)`.

### Key Conventions

- **UUIDs everywhere**: all primary keys are `varchar(36)` UUIDs. Generate with the global `uuid()` helper in [backend/app/common.php](backend/app/common.php).
- **Soft delete**: never `DELETE` rows — set `del_flag = 1` instead. Drafts are the only allowed exception.
- **Account-set + del_flag in every where clause**: queries that bypass either are bugs.
- **No business fields on the voucher detail table**: things like 案号 (case_no), 收据号 (receipt_no), 当事人 must NOT become columns on `fin_voucher_detail`. They are dynamic dimensions stored in `fin_voucher_aux_value_YYYY` (an EAV table). On save, also denormalize them into `fin_voucher_detail.aux_desc` (a `;`-joined `code:label` string) for fast list/ledger queries — see `Voucher::buildAuxDesc()`.
- **Subject-driven aux config**: `fin_subject_aux_config` declares which aux types are required/verifiable for each subject. `Voucher::checkRequiredAux()` enforces required-flags before save.
- **Debit/credit balance is mandatory**: `Voucher::checkBalance()` rejects unbalanced or zero-amount vouchers. Each detail line must have exactly one of debit/credit non-zero.
- **Voucher number generation**: `Voucher::nextVoucherNo()` uses ThinkPHP's `lock(true)` (SELECT … FOR UPDATE) on `fin_voucher_no_sequence` to avoid duplicates/gaps. Keep this transactional — wrap with `Db::startTrans()` / `Db::commit()` / `Db::rollback()`.
- **Status transitions**: `DRAFT → SUBMITTED → AUDITED → PRINTED`, with `VOIDED` as a terminal state. `Voucher::changeStatus()` enforces the expected source state. Audited/printed/voided vouchers cannot be edited; printed vouchers cannot be voided.
- **Database-portability**: SQL must run unchanged on MySQL → 达梦/人大金仓. Avoid MySQL-only syntax (e.g. backticks, `LIMIT … OFFSET`-only patterns). Stick to ANSI-friendly constructs and ThinkPHP query builder methods.

## Frontend Architecture

Single SPA in [frontend/src/](frontend/src/), shell in [App.vue](frontend/src/App.vue) with Element Plus `el-container` (sidebar + topbar + main). Routes are flat in [router/index.ts](frontend/src/router/index.ts) (history mode).

### API Client

All backend calls go through [frontend/src/api/http.ts](frontend/src/api/http.ts) which:

1. Hits `http://127.0.0.1:8080/` directly (the `vite.config.ts` `/api` proxy is unused — code targets the backend host directly).
2. Wraps axios with a request interceptor that injects `X-User-Id` from the Pinia context store.
3. Wraps the response interceptor to unwrap `result.data` on `code === 20000`, and `ElMessage.error` + reject otherwise.
4. Exposes `apiAction(action, data, extra)` which serializes calls as `application/x-www-form-urlencoded` with `action` + `account_set_id` (from store) + `data` (JSON-stringified) — matching the backend's expected request shape.

When adding a new API: add a typed wrapper in `frontend/src/api/<module>.ts` that calls `apiAction('/<module>/<method>', payload)`. Don't call `http` or `axios` directly from views.

### Global State

[frontend/src/stores/context.ts](frontend/src/stores/context.ts) is a tiny Pinia store holding `accountSetId`, `userId`, `period`. The header `el-segmented` in [App.vue](frontend/src/App.vue) binds directly to `context.period`, so all views can read the active period from the store.


Out of scope for phase 1 (do **not** add unless asked):

- Multi-court / `customer_id`
- Approval workflow (anyone with audit permission can also unaudit)
- External business-flow auto-import (二期 — Excel import only, manual)
- Full reconciliation (往来核销) closure (二期 — match key is `case_no + receipt_no`, receipt_no nullable)
- Voucher-number renumbering UI (logic reserved for later)

The full set of phase-1 endpoints is enumerated in [docs/design/phase1-implementation.md](docs/design/phase1-implementation.md) under "一期 Action 清单".

## Reference Project

The PHP architecture is patterned directly on [/Applications/MAMP/htdocs/cccf](file:///Applications/MAMP/htdocs/cccf) (the **cccf** ThinkPHP-5 backend behind the `cccf_src` Vue 2 admin). When unsure about routing, response envelope, or controller layout, that project is the canonical reference.


## 开发规范
一、会计科目规范
1. 严格遵循国标企业会计科目编码体系，支持一级科目、多级下级科目。
2. 会计科目分资产、负债、共同、所有者权益、成本、损益六大类。
3. 科目支持启用/禁用、是否允许录入凭证、是否末级科目控制。
4. 科目一旦已有期初余额或已生成凭证，禁止随意删除、禁止随意修改科目类别和层级。

二、辅助核算强制规范（最重要，必须严格遵守）
1. 系统内置标准辅助核算维度：客户、供应商、部门、职员、项目。
2. 支持自定义新增辅助核算维度：如合同号、区域、产品线、费用类别等。
3. 互斥规则：同一会计科目，客户、供应商、职员（个人往来）三者只能选其一，不能同时挂载多个往来类核算。
4. 非往来维度：部门、项目、自定义辅助项可以互相自由组合挂载。
5. 规则约束：科目一旦已经录入期初、已经做过凭证分录，不允许中途修改、增减辅助核算项。
6. 挂了辅助核算的科目，不再建立二级三级明细科目，辅助核算替代明细科目做维度拆分。
7. 每一条凭证分录，凡是挂载了辅助核算的科目，必须强制录入对应辅助维度档案，不能为空。

三、凭证核心规则
1. 严格遵循会计准则：有借必有贷，借贷必相等，整张凭证借贷金额平衡。
2. 凭证按会计期间按月连续编号，不允许断号、不允许重复凭证号。
3. 凭证状态：制单 → 审核 → 记账 → 期末结账，流程不可逆。
4. 已审核、已记账凭证不允许直接修改删除，只能红字冲销/作废重做。
5. 每张凭证必须字段：凭证字号、凭证号、制单日期、会计期间、摘要、借方金额、贷方金额、附单据张数、制单人、审核人、记账人、作废标记。
6. 损益类科目支持期末自动结转损益，系统自动生成结转凭证。

四、数据库表设计强制规范
1. 必须拆分核心主表，严禁揉在一张表里：
- 会计科目主表
- 凭证主表（凭证头）
- 凭证分录明细表
- 科目余额表（按年度+期间）
- 辅助核算档案表（客户/供应商/部门/职员/项目/自定义）
- 辅助核算发生额余额表
- 会计期间、账套参数配置表
2. 所有余额同时维护两套：科目层级余额 + 各辅助维度余额，保证总账与辅助账能对账平衡。
3. 所有业务表必须包含：账套ID、年度、会计期间、创建人、创建时间、更新人、更新时间、删除软标记。
4. 设计表结构先考虑财务对账、报表查询、后期扩展，不能只做简单业务存储。

五、账簿与报表规范
1. 必须支持标准财务账簿：总账、明细账、科目余额表、试算平衡表。
2. 必须支持辅助核算专项账：客户往来账、供应商往来账、部门明细账、项目明细账、多维度组合汇总查询。
3. 所有报表支持按年度、期间、科目范围、辅助维度筛选、导出Excel。

六、开发协作规则
1. 我每给一个开发需求，你必须按固定顺序输出：
第一步：先给出财务专业业务规则
第二步：设计数据库表结构、字段、类型、索引、关联关系
第三步：画出业务流转流程
第四步：设计后端接口清单
第五步：枚举/字典/基础档案设计
第六步：最后再编写代码实现
2. 任何设计如果违背会计准则、辅助核算规则、标准财务流程，你要主动自查修正，不等待我提醒。
3. 后续所有对话、所有功能迭代、表修改、逻辑调整，都默认遵循以上全部规范，永久生效。