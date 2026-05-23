# 案款不明款功能 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the case-fund-only “不明款” workflow with its own table, Excel import, list page, voucher generation, subject configuration, delete guard, menu entry, and tests.

**Architecture:** Extend the existing `CaseFund.php` module rather than creating a separate backend module, because payment, refund, bank statement, subject config, and voucher generation already live there. Add a dedicated `fin_case_fund_unknown_payment` table and `UNKNOWN_PAYMENT` voucher business type so unknown-payment data remains separate from normal payment registration while reusing shared permissions and voucher safeguards.

**Tech Stack:** PHP 7.4 / ThinkPHP 5 style backend, MySQL DDL and idempotent patch SQL, Vue 3 + TypeScript + Element Plus frontend, PHP script tests, Node assertion tests.

---

## File Structure

- Modify: `backend/app/finance/model/CaseFund.php`
  Add constants, routes, list/import/delete/generate methods, Excel parsing, validation, duplicate fingerprint, summary builder, and `UNKNOWN_PAYMENT` subject config support.
- Modify: `backend/database/court_finance_ddl.sql`
  Add base DDL for `fin_case_fund_unknown_payment`.
- Modify: `backend/database/finance_standard_patch.sql`
  Add idempotent DDL for existing deployments.
- Modify: `backend/database/migration_rbac.sql`
  Add `menu:case_fund:unknown_payment` and role grants.
- Create: `backend/tests/CaseFundUnknownPaymentImportTest.php`
  Lock parsing, validation, fingerprint, amount total, sample summary fallback behavior.
- Create: `backend/tests/CaseFundUnknownPaymentGenerateVoucherTest.php`
  Lock route, permission, transaction, voucher table writes, summary text, regeneration guard, subject config behavior.
- Modify: `backend/tests/CaseFundSubjectConfigTest.php`
  Include `UNKNOWN_PAYMENT` in backend subject configuration expectations.
- Modify: `frontend/src/api/caseFund.ts`
  Add unknown-payment API wrappers and `UNKNOWN_PAYMENT` subject config typing.
- Modify: `frontend/src/types/api.ts`
  Add `CaseFundUnknownPayment` and widen `CaseFundSubjectConfig.voucher_biz_type`.
- Create: `frontend/src/views/case-fund/UnknownPaymentView.vue`
  Implement the list page by following the existing case-fund list-page pattern.
- Modify: `frontend/src/router/index.ts`
  Add `/case-fund/unknown-payments`.
- Modify: `frontend/src/App.vue`
  Add menu item, page title, and menu permission code.
- Modify: `frontend/src/views/dashboard/DashboardView.vue`
  Add the dashboard quick entry only for `CASE_FUND`.
- Modify: `frontend/tests/CaseFundMenuTest.mjs`
  Lock menu, route, title, RBAC seed, API, and view file existence.
- Create: `frontend/tests/CaseFundUnknownPaymentViewTest.mjs`
  Lock UI controls and API usage.
- Modify: `frontend/tests/CaseFundSubjectConfigTest.mjs`
  Lock unknown-payment subject config wiring.

---

### Task 1: Backend Import Parsing Test

**Files:**
- Create: `backend/tests/CaseFundUnknownPaymentImportTest.php`
- Modify: `backend/app/finance/model/CaseFund.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/CaseFundUnknownPaymentImportTest.php`:

```php
<?php

function input($key = '', $default = null) { return $default; }
function config($key = '') {
    if ($key === 'default_account_set_id') return '00000000-0000-0000-0000-000000000101';
    if ($key === 'default_year') return '2026';
    return null;
}
function getNowTime() { return date('Y-m-d H:i:s'); }
function uuid() { return '12345678-1234-4000-8000-123456789abc'; }

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
require_once __DIR__ . '/../app/finance/model/Common.php';
require_once $modelPath;

use app\finance\model\CaseFund;

$xlsPath = __DIR__ . '/../../不明款查询 (4).xls';
if (!file_exists($xlsPath)) {
    fwrite(STDERR, 'Unknown payment sample xls does not exist' . PHP_EOL);
    exit(1);
}

$caseFund = new CaseFund();
$parseMethod = new ReflectionMethod(CaseFund::class, 'parseUnknownPaymentImportRowsFromXls');
$parseMethod->setAccessible(true);
$rows = $parseMethod->invoke($caseFund, file_get_contents($xlsPath));

if (count($rows) !== 37) {
    fwrite(STDERR, 'Expected 37 unknown payment rows, got ' . count($rows) . PHP_EOL);
    exit(1);
}

$first = $rows[0];
$expected = [
    'court_name' => '沈阳市苏家屯区人民法院',
    'claim_status' => '待认领',
    'payer_name' => '王雪梅',
    'unknown_amount' => '1612.00',
    'bank_summary' => '',
    'bank_remark' => '账号不存在账号错误',
    'transaction_date' => '2026-01-07',
    'transaction_time' => '2026-01-07 10:29:55',
    'court_bank_account_no' => '21050144000600000799',
    'arrival_method' => '其他方式',
    'payer_bank_name' => '中国农业银行股份有限公司沈阳苏家屯支行',
    'payer_account_no' => '6228480004804304473',
    'last_modified_time' => '2026-03-31 14:42:26',
];
foreach ($expected as $field => $value) {
    if (($first[$field] ?? null) !== $value) {
        fwrite(STDERR, 'Unexpected first row ' . $field . ': ' . var_export($first[$field] ?? null, true) . PHP_EOL);
        exit(1);
    }
}

$totalCents = 0;
foreach ($rows as $row) {
    $totalCents += (int)round(((float)$row['unknown_amount']) * 100);
}
if ($totalCents !== 377613674) {
    fwrite(STDERR, 'Expected sample amount total 3776136.74, got cents ' . $totalCents . PHP_EOL);
    exit(1);
}

if (empty($first['source_fingerprint']) || strlen($first['source_fingerprint']) !== 64) {
    fwrite(STDERR, 'Expected stable sha256 source fingerprint' . PHP_EOL);
    exit(1);
}

$validateMethod = new ReflectionMethod(CaseFund::class, 'validateUnknownPaymentRows');
$validateMethod->setAccessible(true);
$errors = $validateMethod->invoke($caseFund, $rows, 'CASE_FUND');
if (!empty($errors)) {
    fwrite(STDERR, 'Sample unknown payments should pass CASE_FUND validation: ' . $errors[0] . PHP_EOL);
    exit(1);
}

$wrongBizErrors = $validateMethod->invoke($caseFund, [$first], 'LITIGATION_FEE');
if (empty($wrongBizErrors) || strpos(implode("\n", $wrongBizErrors), '当前账套类型不支持不明款业务') === false) {
    fwrite(STDERR, 'Non case-fund account sets should reject unknown payment import' . PHP_EOL);
    exit(1);
}

$summaryMethod = new ReflectionMethod(CaseFund::class, 'buildUnknownPaymentVoucherSummary');
$summaryMethod->setAccessible(true);
$summary = $summaryMethod->invoke($caseFund, $first);
if ($summary !== '收 不明款 王雪梅 账号不存在账号错误') {
    fwrite(STDERR, 'Unexpected unknown payment voucher summary: ' . $summary . PHP_EOL);
    exit(1);
}

$fallback = $first;
$fallback['bank_remark'] = '';
$fallback['bank_summary'] = '电子汇入';
if ($summaryMethod->invoke($caseFund, $fallback) !== '收 不明款 王雪梅 电子汇入') {
    fwrite(STDERR, 'Unknown payment summary should fall back to bank summary' . PHP_EOL);
    exit(1);
}

echo 'Case fund unknown payment import test passed' . PHP_EOL;
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundUnknownPaymentImportTest.php
```

Expected: FAIL with `ReflectionException` for missing `parseUnknownPaymentImportRowsFromXls`.

- [ ] **Step 3: Implement minimal backend parsing helpers**

Modify `backend/app/finance/model/CaseFund.php`:

```php
const TABLE_UNKNOWN_PAYMENT = 'fin_case_fund_unknown_payment';
const UNKNOWN_PAYMENT_FIELD = [
    'unknown_payment_id', 'account_set_id', 'fiscal_year', 'period', 'court_name',
    'claim_status', 'payer_name', 'unknown_amount', 'bank_summary', 'bank_remark',
    'transaction_date', 'transaction_time', 'court_bank_account_no', 'arrival_method',
    'payer_bank_name', 'payer_account_no', 'claim_user_name', 'claim_date',
    'last_modified_time', 'source_file_name', 'source_row_no', 'source_fingerprint',
    'source_raw_json', 'voucher_status', 'voucher_id', 'voucher_no', 'voucher_period',
    'voucher_generated_time', 'created_by', 'created_time', 'updated_by',
    'updated_time', 'del_flag', 'version', 'remark',
];
```

Add methods:

```php
protected function parseUnknownPaymentImportRowsFromXls($binary)
{
    $cells = $this->parseBiffCells($this->readOleWorkbookStream($binary));
    if (empty($cells)) {
        throw new \Exception('文件中没有可识别的工作表数据');
    }
    $maxRow = -1;
    $maxCol = -1;
    foreach ($cells as $key => $value) {
        list($row, $col) = array_map('intval', explode(':', $key));
        $maxRow = max($maxRow, $row);
        $maxCol = max($maxCol, $col);
    }
    $headers = [];
    for ($col = 0; $col <= $maxCol; $col++) {
        $headers[$col] = $this->normalizeImportCellText($cells['0:' . $col] ?? '');
    }
    $required = [
        '法院名称', '认领状态', '缴款人', '金额', '银行摘要', '银行备注',
        '交易日期', '法院银行账户', '到账方式', '缴款人开户行', '缴款人账号',
        '认领人姓名', '认领日期', '最后修改时间',
    ];
    $index = [];
    foreach ($required as $name) {
        $found = array_search($name, $headers, true);
        if ($found === false) {
            throw new \Exception('缺少表头：' . $name);
        }
        $index[$name] = $found;
    }
    $rows = [];
    for ($row = 1; $row <= $maxRow; $row++) {
        $raw = [];
        foreach ($index as $name => $col) {
            $raw[$name] = $this->normalizeImportCellText($cells[$row . ':' . $col] ?? '');
        }
        if ($this->isEmptyPaymentRawRow($raw) || $this->isMalformedPaymentRawRow($raw)) {
            continue;
        }
        $transactionTime = $this->normalizeDateTimeValue($raw['交易日期']);
        $transactionDate = $transactionTime === '' ? '' : substr($transactionTime, 0, 10);
        $amount = $this->centsToDecimal($this->decimalToCents($raw['金额']));
        $parsed = [
            'fiscal_year' => $transactionDate === '' ? '' : substr($transactionDate, 0, 4),
            'period' => $transactionDate === '' ? '' : substr($transactionDate, 0, 7),
            'court_name' => $raw['法院名称'],
            'claim_status' => $raw['认领状态'],
            'payer_name' => $raw['缴款人'],
            'unknown_amount' => $amount,
            'bank_summary' => $raw['银行摘要'],
            'bank_remark' => $raw['银行备注'],
            'transaction_date' => $transactionDate,
            'transaction_time' => $transactionTime,
            'court_bank_account_no' => $raw['法院银行账户'],
            'arrival_method' => $raw['到账方式'],
            'payer_bank_name' => $raw['缴款人开户行'],
            'payer_account_no' => $raw['缴款人账号'],
            'claim_user_name' => $raw['认领人姓名'],
            'claim_date' => $this->nullableDateValue($raw['认领日期']),
            'last_modified_time' => $this->normalizeDateTimeValue($raw['最后修改时间']) ?: null,
            'source_row_no' => $row + 1,
            'source_raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE),
        ];
        $parsed['source_fingerprint'] = $this->unknownPaymentFingerprint($parsed);
        $rows[] = $parsed;
    }
    return $rows;
}

protected function validateUnknownPaymentRows($rows, $bizType = '')
{
    $errors = [];
    foreach ($rows as $row) {
        $prefix = '第' . $row['source_row_no'] . '行：';
        if ($bizType !== 'CASE_FUND') {
            $errors[] = $prefix . '当前账套类型不支持不明款业务：' . $bizType;
        }
        if ($row['payer_name'] === ''
            && $row['bank_remark'] === ''
            && $row['bank_summary'] === ''
            && $row['court_bank_account_no'] === ''
            && $row['payer_account_no'] === '') {
            $errors[] = $prefix . '缴款人、银行摘要、银行备注、法院银行账户、缴款人账号至少填写一项用于追溯';
        }
        if ($this->decimalToCents($row['unknown_amount']) <= 0) {
            $errors[] = $prefix . '金额必须大于0';
        }
        if ($row['transaction_date'] === '') {
            $errors[] = $prefix . '交易日期不能为空或格式不正确';
        }
    }
    return $errors;
}

protected function unknownPaymentFingerprint($row)
{
    $parts = [
        $row['transaction_time'] ?? '',
        $row['unknown_amount'] ?? '',
        $row['court_bank_account_no'] ?? '',
        $row['payer_account_no'] ?? '',
        $row['payer_name'] ?? '',
        $row['bank_remark'] ?? '',
        $row['bank_summary'] ?? '',
    ];
    return hash('sha256', implode('|', $parts));
}

protected function buildUnknownPaymentVoucherSummary($row)
{
    $memo = trim((string)($row['bank_remark'] ?? ''));
    if ($memo === '') {
        $memo = trim((string)($row['bank_summary'] ?? ''));
    }
    return trim('收 不明款 ' . ($row['payer_name'] ?? '') . ' ' . $memo);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundUnknownPaymentImportTest.php
```

Expected: `Case fund unknown payment import test passed`.

---

### Task 2: Database Schema and RBAC

**Files:**
- Modify: `backend/database/court_finance_ddl.sql`
- Modify: `backend/database/finance_standard_patch.sql`
- Modify: `backend/database/migration_rbac.sql`
- Modify: `frontend/tests/CaseFundMenuTest.mjs`

- [ ] **Step 1: Write the failing frontend menu/RBAC assertions**

Update `frontend/tests/CaseFundMenuTest.mjs` by adding these assertions near the existing case-fund assertions:

```js
assert(app.includes("context.bizType === 'CASE_FUND'"), 'unknown payment menu should be gated to case-fund account sets')
assert(app.includes('menu:case_fund:unknown_payment'), 'sidebar should check unknown payment menu permission')
assert(app.includes('index="/case-fund/unknown-payments"'), 'sidebar should route to case fund unknown payment page')
assert(app.includes('不明款'), 'sidebar should show unknown payment menu item')
assert(dashboard.includes("label: '不明款'"), 'dashboard should show unknown payment entry')
assert(rbacSql.includes("('menu:case_fund:unknown_payment', '不明款'"), 'RBAC seed should include unknown payment menu')
assert(router.includes("const UnknownPaymentView = () => import('../views/case-fund/UnknownPaymentView.vue')"), 'router should lazy-load unknown payment view')
assert(router.includes("'/case-fund/unknown-payments'"), 'router should register unknown payment page route')
assert(router.includes("permission: 'menu:case_fund:unknown_payment'"), 'unknown payment route should require menu permission')
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
node frontend/tests/CaseFundMenuTest.mjs
```

Expected: FAIL on missing unknown-payment menu permission or route.

- [ ] **Step 3: Add DDL**

In `backend/database/court_finance_ddl.sql`, after `fin_case_fund_refund`, add:

```sql
create table fin_case_fund_unknown_payment (
    unknown_payment_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    fiscal_year int not null,
    period varchar(7) not null,
    court_name varchar(100) not null default '',
    claim_status varchar(30) not null default '',
    payer_name varchar(100) not null default '',
    unknown_amount decimal(18,2) not null default 0.00,
    bank_summary varchar(255) not null default '',
    bank_remark varchar(255) not null default '',
    transaction_date date not null,
    transaction_time datetime not null,
    court_bank_account_no varchar(80) not null default '',
    arrival_method varchar(50) not null default '',
    payer_bank_name varchar(160) not null default '',
    payer_account_no varchar(80) not null default '',
    claim_user_name varchar(80) not null default '',
    claim_date date null,
    last_modified_time datetime null,
    source_file_name varchar(255) not null default '',
    source_row_no int not null default 0,
    source_fingerprint varchar(64) not null,
    source_raw_json text,
    voucher_status varchar(30) not null default 'UNGENERATED',
    voucher_id varchar(36) null,
    voucher_no int null,
    voucher_period varchar(7) null,
    voucher_generated_time datetime null,
    created_by varchar(36) not null default '',
    created_time datetime not null,
    updated_by varchar(36) not null default '',
    updated_time datetime not null,
    del_flag tinyint not null default 0,
    version int not null default 0,
    remark varchar(255) not null default ''
);
create unique index uk_case_fund_unknown_payment_source on fin_case_fund_unknown_payment (account_set_id, source_fingerprint, del_flag);
create index idx_case_fund_unknown_payment_period on fin_case_fund_unknown_payment (account_set_id, period, transaction_date);
create index idx_case_fund_unknown_payment_bank on fin_case_fund_unknown_payment (account_set_id, court_bank_account_no, transaction_date);
create index idx_case_fund_unknown_payment_payer on fin_case_fund_unknown_payment (account_set_id, payer_name);
create index idx_case_fund_unknown_payment_voucher on fin_case_fund_unknown_payment (account_set_id, voucher_status, voucher_period, voucher_id);
```

In `backend/database/finance_standard_patch.sql`, add the same schema as an idempotent deployment patch:

```sql
create table if not exists fin_case_fund_unknown_payment (
    unknown_payment_id varchar(36) primary key,
    account_set_id varchar(36) not null,
    fiscal_year int not null,
    period varchar(7) not null,
    court_name varchar(100) not null default '',
    claim_status varchar(30) not null default '',
    payer_name varchar(100) not null default '',
    unknown_amount decimal(18,2) not null default 0.00,
    bank_summary varchar(255) not null default '',
    bank_remark varchar(255) not null default '',
    transaction_date date not null,
    transaction_time datetime not null,
    court_bank_account_no varchar(80) not null default '',
    arrival_method varchar(50) not null default '',
    payer_bank_name varchar(160) not null default '',
    payer_account_no varchar(80) not null default '',
    claim_user_name varchar(80) not null default '',
    claim_date date null,
    last_modified_time datetime null,
    source_file_name varchar(255) not null default '',
    source_row_no int not null default 0,
    source_fingerprint varchar(64) not null,
    source_raw_json text,
    voucher_status varchar(30) not null default 'UNGENERATED',
    voucher_id varchar(36) null,
    voucher_no int null,
    voucher_period varchar(7) null,
    voucher_generated_time datetime null,
    created_by varchar(36) not null default '',
    created_time datetime not null,
    updated_by varchar(36) not null default '',
    updated_time datetime not null,
    del_flag tinyint not null default 0,
    version int not null default 0,
    remark varchar(255) not null default '',
    unique key uk_case_fund_unknown_payment_source (account_set_id, source_fingerprint, del_flag),
    key idx_case_fund_unknown_payment_period (account_set_id, period, transaction_date),
    key idx_case_fund_unknown_payment_bank (account_set_id, court_bank_account_no, transaction_date),
    key idx_case_fund_unknown_payment_payer (account_set_id, payer_name),
    key idx_case_fund_unknown_payment_voucher (account_set_id, voucher_status, voucher_period, voucher_id)
);
```

- [ ] **Step 4: Add RBAC seed**

In `backend/database/migration_rbac.sql`, add:

```sql
('menu:case_fund:unknown_payment', '不明款', 1, 'case_fund', '/case-fund/unknown-payments', 39),
```

Add the permission to the same role grants that already include `menu:case_fund:payment`, `menu:case_fund:refund`, and `menu:case_fund:bank_statement`:

```sql
('00000000-0000-0000-0000-000000010002', 'menu:case_fund:unknown_payment'),
('00000000-0000-0000-0000-000000010003', 'menu:case_fund:unknown_payment'),
('00000000-0000-0000-0000-000000010004', 'menu:case_fund:unknown_payment'),
('00000000-0000-0000-0000-000000010005', 'menu:case_fund:unknown_payment'),
```

- [ ] **Step 5: Run menu test**

Run:

```bash
node frontend/tests/CaseFundMenuTest.mjs
```

Expected: still FAIL until frontend route/menu exists.

---

### Task 3: Backend List and Import APIs

**Files:**
- Modify: `backend/app/finance/model/CaseFund.php`
- Test: `backend/tests/CaseFundUnknownPaymentImportTest.php`

- [ ] **Step 1: Write failing source assertions**

Append to `backend/tests/CaseFundUnknownPaymentImportTest.php` before the success echo:

```php
$source = file_get_contents($modelPath);
foreach ([
    "case 'unknownPaymentList'" => 'CaseFund should route unknownPaymentList',
    "case 'unknownPaymentImport'" => 'CaseFund should route unknownPaymentImport',
    'public function unknownPaymentList' => 'CaseFund should expose unknownPaymentList',
    'public function unknownPaymentImport' => 'CaseFund should expose unknownPaymentImport',
    "requirePermission('case_fund:view')" => 'Unknown payment list should check view permission',
    "requirePermission('case_fund:import')" => 'Unknown payment import should check import permission',
    'CASE_FUND_UNKNOWN_PAYMENT' => 'Unknown payment writes should use audit biz type',
] as $needle => $message) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundUnknownPaymentImportTest.php
```

Expected: FAIL on missing route or public method.

- [ ] **Step 3: Add routes and list/import methods**

In `CaseFund::index`, add cases:

```php
case 'unknownPaymentList':
    return $this->unknownPaymentList($data);
case 'unknownPaymentImport':
    return $this->unknownPaymentImport($data);
```

Add methods:

```php
public function unknownPaymentList($data = [])
{
    $auth = $this->requirePermission('case_fund:view');
    if ($auth) return $auth;
    if ($this->currentAccountSetBizType() !== 'CASE_FUND') {
        return $this->error('当前账套类型不支持不明款业务：' . $this->currentAccountSetBizType());
    }
    $page = $data['page'] ?? input('param.page', 1);
    $pagesize = $data['pagesize'] ?? input('param.pagesize', 50);
    $where = $this->accountWhere();
    $where['fiscal_year'] = $this->currentYear();
    if (!empty($data['period'])) $where['period'] = $data['period'];
    if (!empty($data['voucher_status'])) $where['voucher_status'] = $data['voucher_status'];
    if (!empty($data['claim_status'])) $where['claim_status'] = $data['claim_status'];
    if (!empty($data['date_start'])) $where['transaction_date'][] = ['>=', $data['date_start']];
    if (!empty($data['date_end'])) $where['transaction_date'][] = ['<=', $data['date_end']];
    $keyword = trim($data['keyword'] ?? '');
    $query = $this->getdb(self::TABLE_UNKNOWN_PAYMENT)->where($where);
    $totalQuery = $this->getdb(self::TABLE_UNKNOWN_PAYMENT)->where($where);
    $amountQuery = $this->getdb(self::TABLE_UNKNOWN_PAYMENT)->where($where);
    foreach ([$query, $totalQuery, $amountQuery] as $item) {
        if ($keyword !== '') {
            $item->where('payer_name|bank_summary|bank_remark|court_bank_account_no|payer_bank_name|payer_account_no', 'like', '%' . $keyword . '%');
        }
    }
    $total = $totalQuery->count();
    $totalAmount = $amountQuery->sum('unknown_amount');
    $rows = $query->field(self::UNKNOWN_PAYMENT_FIELD)
        ->order('transaction_time asc, source_row_no asc')
        ->page($page, $pagesize)
        ->select();
    return $this->ok(['items' => $rows, 'total' => $total, 'total_amount' => $totalAmount], 'OK', $total);
}

public function unknownPaymentImport($data = [])
{
    $auth = $this->requirePermission('case_fund:import');
    if ($auth) return $auth;
    $content = $data['content_base64'] ?? '';
    if ($content === '') return $this->error('导入文件不能为空');
    $binary = base64_decode($content, true);
    if ($binary === false || $binary === '') return $this->error('导入文件内容不正确');
    try {
        $rows = $this->parseUnknownPaymentImportRowsFromXls($binary);
    } catch (\Exception $e) {
        return $this->error('解析不明款失败：' . $e->getMessage());
    }
    if (empty($rows)) return $this->error('导入文件没有不明款数据');
    $errors = $this->validateUnknownPaymentRows($rows, $this->currentAccountSetBizType());
    if (!empty($errors)) return $this->error('导入校验失败', $errors);
    $fingerprints = [];
    foreach ($rows as $row) $fingerprints[] = $row['source_fingerprint'];
    $existing = [];
    $existingRows = $this->getdb(self::TABLE_UNKNOWN_PAYMENT)->where([
        'account_set_id' => $this->accountSetId,
        'del_flag' => 0,
    ])->where('source_fingerprint', 'in', array_values(array_unique($fingerprints)))->select();
    foreach ($existingRows as $row) $existing[$row['source_fingerprint']] = true;
    $created = 0;
    $skipped = 0;
    $seen = [];
    Db::startTrans();
    try {
        foreach ($rows as $row) {
            $fingerprint = $row['source_fingerprint'];
            if (isset($existing[$fingerprint]) || isset($seen[$fingerprint])) {
                $skipped++;
                $seen[$fingerprint] = true;
                continue;
            }
            $seen[$fingerprint] = true;
            $insert = $row;
            $insert['unknown_payment_id'] = uuid();
            $insert['account_set_id'] = $this->accountSetId;
            $insert['source_file_name'] = $data['filename'] ?? '';
            $insert['voucher_status'] = 'UNGENERATED';
            $insert['voucher_id'] = null;
            $insert['voucher_no'] = null;
            $insert['voucher_period'] = null;
            $insert['voucher_generated_time'] = null;
            $insert['remark'] = $data['remark'] ?? '';
            $this->fillCreate($insert);
            $this->getdb(self::TABLE_UNKNOWN_PAYMENT)->insert($insert);
            $created++;
        }
        $this->logAudit('CASE_FUND_UNKNOWN_PAYMENT', 'IMPORT', 'IMPORT', null, [
            'filename' => $data['filename'] ?? '',
            'total' => count($rows),
            'created' => $created,
            'skipped' => $skipped,
        ]);
        Db::commit();
    } catch (\Exception $e) {
        Db::rollback();
        return $this->error('保存不明款失败：' . $e->getMessage());
    }
    return $this->ok(['total' => count($rows), 'created' => $created, 'skipped' => $skipped], '导入成功', count($rows));
}
```

- [ ] **Step 4: Run backend import test**

Run:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundUnknownPaymentImportTest.php
```

Expected: PASS.

---

### Task 4: Backend Subject Config and Voucher Generation

**Files:**
- Create: `backend/tests/CaseFundUnknownPaymentGenerateVoucherTest.php`
- Modify: `backend/tests/CaseFundSubjectConfigTest.php`
- Modify: `backend/app/finance/model/CaseFund.php`

- [ ] **Step 1: Write failing generation test**

Create `backend/tests/CaseFundUnknownPaymentGenerateVoucherTest.php`:

```php
<?php

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$source = file_get_contents($modelPath);

$checks = [
    ["case 'unknownPaymentGenerateVoucher'", 'CaseFund should route unknownPaymentGenerateVoucher'],
    ['public function unknownPaymentGenerateVoucher', 'CaseFund should expose unknownPaymentGenerateVoucher'],
    ["requirePermission('case_fund:generate_voucher')", 'Unknown payment generation should check permission'],
    ["voucher_biz_type' => 'UNKNOWN_PAYMENT'", 'Unknown payment generation should read UNKNOWN_PAYMENT subject config'],
    ['buildUnknownPaymentVoucherSummary', 'Unknown payment generation should use fixed summary builder'],
    ['assertRowsCanGenerateVoucher', 'Unknown payment generation should reuse regeneration guard'],
    ['hasRequiredAuxConfigs', 'Unknown payment generation should reject required aux subjects'],
    ['fin_voucher', 'Unknown payment generation should write voucher table'],
    ['fin_voucher_detail', 'Unknown payment generation should write voucher detail table'],
    ["'BUSINESS'", 'Unknown payment vouchers should use BUSINESS source type'],
    ["'SUBMITTED'", 'Unknown payment vouchers should be submitted'],
    ['CASE_FUND_UNKNOWN_PAYMENT', 'Unknown payment generation should write audit log'],
];
foreach ($checks as [$needle, $message]) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

if (!function_exists('input')) function input($key = '', $default = null) { return $default; }
if (!function_exists('config')) function config($key = '') {
    if ($key === 'default_account_set_id') return '00000000-0000-0000-0000-000000000101';
    if ($key === 'default_year') return '2026';
    return null;
}
if (!function_exists('getNowTime')) function getNowTime() { return date('Y-m-d H:i:s'); }

require_once __DIR__ . '/../app/finance/model/Common.php';
require_once $modelPath;

use app\finance\model\CaseFund;

class UnknownPaymentGuardProbe extends CaseFund
{
    private $activeVoucher;
    public function __construct($activeVoucher) { $this->activeVoucher = $activeVoucher; }
    protected function hasActiveGeneratedVoucher($row) { return $this->activeVoucher; }
    public function checkRows($rows) { return $this->assertRowsCanGenerateVoucher($rows, '不明款记录'); }
    public function summary($row) { return $this->buildUnknownPaymentVoucherSummary($row); }
}

$probe = new UnknownPaymentGuardProbe(false);
if ($probe->summary(['payer_name' => '王雪梅', 'bank_remark' => '账号不存在账号错误']) !== '收 不明款 王雪梅 账号不存在账号错误') {
    fwrite(STDERR, 'Unknown payment voucher summary should include payer and bank remark' . PHP_EOL);
    exit(1);
}

$generatedRow = ['voucher_status' => 'GENERATED', 'voucher_id' => 'voucher-1', 'case_no' => '不明款'];
if ($probe->checkRows([$generatedRow]) !== null) {
    fwrite(STDERR, 'Generated unknown payment rows should be regeneratable when original voucher is deleted or missing' . PHP_EOL);
    exit(1);
}

$activeProbe = new UnknownPaymentGuardProbe(true);
if (strpos((string)$activeProbe->checkRows([$generatedRow]), '原凭证仍有效') === false) {
    fwrite(STDERR, 'Generated unknown payment rows should be blocked when original voucher remains active' . PHP_EOL);
    exit(1);
}

echo 'Case fund unknown payment generate voucher test passed' . PHP_EOL;
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundUnknownPaymentGenerateVoucherTest.php
```

Expected: FAIL on missing route or public method.

- [ ] **Step 3: Widen subject config voucher type**

Modify `normalizeVoucherBizType`:

```php
protected function normalizeVoucherBizType($value)
{
    $value = strtoupper(trim((string)$value));
    return in_array($value, ['PAYMENT', 'REFUND', 'UNKNOWN_PAYMENT'], true) ? $value : '';
}
```

Modify `allowedSubjectConfigItems`:

```php
protected function allowedSubjectConfigItems($bizType, $voucherBizType)
{
    if ($voucherBizType === 'PAYMENT') return $this->subjectConfigPaymentBusinessTypes($bizType);
    if ($voucherBizType === 'REFUND') return $this->allowedRefundOutTypes($bizType);
    if ($voucherBizType === 'UNKNOWN_PAYMENT') return $bizType === 'CASE_FUND' ? ['不明款'] : [];
    return [];
}
```

- [ ] **Step 4: Add generation route and method**

In `CaseFund::index`, add:

```php
case 'unknownPaymentGenerateVoucher':
    return $this->unknownPaymentGenerateVoucher($data);
```

Add method:

```php
public function unknownPaymentGenerateVoucher($data = [])
{
    $auth = $this->requirePermission('case_fund:generate_voucher');
    if ($auth) return $auth;
    $ids = $data['unknown_payment_ids'] ?? [];
    if (!is_array($ids) || empty($ids)) return $this->error('请选择要生成凭证的不明款记录');
    if ($this->currentAccountSetBizType() !== 'CASE_FUND') {
        return $this->error('当前账套类型不支持不明款业务：' . $this->currentAccountSetBizType());
    }
    $rows = $this->getdb(self::TABLE_UNKNOWN_PAYMENT)
        ->where(['account_set_id' => $this->accountSetId, 'fiscal_year' => $this->currentYear(), 'del_flag' => 0])
        ->where('unknown_payment_id', 'in', $ids)
        ->field(self::UNKNOWN_PAYMENT_FIELD)
        ->order('transaction_time asc, source_row_no asc')
        ->select();
    if (empty($rows)) return $this->error('所选不明款记录不存在');
    foreach ($rows as &$row) {
        if (empty($row['case_no'])) $row['case_no'] = '不明款';
    }
    unset($row);
    $generationCheck = $this->assertRowsCanGenerateVoucher($rows, '不明款记录');
    if ($generationCheck !== null) return $this->error($generationCheck);
    $config = $this->getdb(self::TABLE_SUBJECT_CONFIG)->where([
        'account_set_id' => $this->accountSetId,
        'biz_type' => 'CASE_FUND',
        'voucher_biz_type' => 'UNKNOWN_PAYMENT',
        'business_item_type' => '不明款',
        'del_flag' => 0,
    ])->field(self::SUBJECT_CONFIG_FIELD)->find();
    if (!$config) return $this->error('不明款未配置借方/贷方科目');
    foreach (['debit_subject_code' => '借方科目', 'credit_subject_code' => '贷方科目'] as $field => $label) {
        $check = $this->validateVoucherSubjectCode($config[$field], $label);
        if ($check !== null) return $this->error($check);
        if ($this->hasRequiredAuxConfigs($this->getSubjectAuxConfigs($config[$field]))) {
            return $this->error($label . '存在必填辅助核算，不适合直接用于不明款批量制证');
        }
    }
    $groups = [];
    if ($this->subjectConfigAccountSetFlag() === 1) {
        foreach ($rows as $row) $groups[$row['transaction_date']][] = $row;
    } else {
        foreach ($rows as $row) $groups[$row['unknown_payment_id']] = [$row];
    }
    $generatedCount = 0;
    $voucherInfos = [];
    Db::startTrans();
    try {
        foreach ($groups as $groupRows) {
            $firstRow = $groupRows[0];
            $period = substr($firstRow['transaction_date'], 0, 7);
            $voucherDate = $firstRow['transaction_date'];
            $fiscalYear = $this->fiscalYear($period);
            $voucherId = uuid();
            $voucherNo = $this->generateNextVoucherNo($period);
            $detailsData = [];
            $lineNo = 1;
            $totalCents = 0;
            foreach ($groupRows as $row) {
                $summary = $this->buildUnknownPaymentVoucherSummary($row);
                $amountCents = $this->decimalToCents($row['unknown_amount']);
                $amountDecimal = $this->centsToDecimal($amountCents);
                $totalCents += $amountCents;
                $detailsData[] = [
                    'detail_id' => uuid(),
                    'line_no' => $lineNo++,
                    'subject_code' => $config['debit_subject_code'],
                    'summary' => $summary,
                    'debit_amount' => $amountDecimal,
                    'credit_amount' => '0.00',
                ];
                $detailsData[] = [
                    'detail_id' => uuid(),
                    'line_no' => $lineNo++,
                    'subject_code' => $config['credit_subject_code'],
                    'summary' => $summary,
                    'debit_amount' => '0.00',
                    'credit_amount' => $amountDecimal,
                ];
            }
            if ($totalCents <= 0) throw new \Exception('凭证金额必须大于0');
            $voucher = [
                'voucher_id' => $voucherId,
                'account_set_id' => $this->accountSetId,
                'fiscal_year' => $fiscalYear,
                'period' => $period,
                'voucher_date' => $voucherDate,
                'voucher_word' => '记',
                'voucher_no' => $voucherNo,
                'summary' => $this->buildUnknownPaymentVoucherSummary($firstRow),
                'debit_amount' => $this->centsToDecimal($totalCents),
                'credit_amount' => $this->centsToDecimal($totalCents),
                'attachment_count' => 0,
                'status' => 'SUBMITTED',
                'source_type' => 'BUSINESS',
                'printed_flag' => '0',
                'prepared_by' => $this->userid,
                'prepared_time' => $this->now(),
                'void_flag' => '0',
                'remark' => '',
            ];
            $this->fillCreate($voucher);
            $this->getdb('fin_voucher')->insert($voucher);
            foreach ($detailsData as $detail) {
                $detailRow = [
                    'detail_id' => $detail['detail_id'],
                    'account_set_id' => $this->accountSetId,
                    'fiscal_year' => $fiscalYear,
                    'period' => $period,
                    'voucher_id' => $voucherId,
                    'line_no' => $detail['line_no'],
                    'subject_code' => $detail['subject_code'],
                    'summary' => $detail['summary'],
                    'debit_amount' => $detail['debit_amount'],
                    'credit_amount' => $detail['credit_amount'],
                    'verification_status' => 'NOT_REQUIRED',
                    'aux_desc' => '',
                    'remark' => '',
                ];
                $this->fillCreate($detailRow);
                $this->getdb('fin_voucher_detail')->insert($detailRow);
            }
            foreach ($groupRows as $row) {
                $this->getdb(self::TABLE_UNKNOWN_PAYMENT)->where([
                    'unknown_payment_id' => $row['unknown_payment_id'],
                    'account_set_id' => $this->accountSetId,
                ])->update([
                    'voucher_status' => 'GENERATED',
                    'voucher_id' => $voucherId,
                    'voucher_no' => $voucherNo,
                    'voucher_period' => $period,
                    'voucher_generated_time' => $this->now(),
                    'updated_by' => $this->userid,
                    'updated_time' => $this->now(),
                ]);
            }
            $generatedCount++;
            $voucherInfos[] = ['voucher_id' => $voucherId, 'voucher_no' => $voucherNo, 'period' => $period];
        }
        $this->logAudit('CASE_FUND_UNKNOWN_PAYMENT', 'VOUCHER_GENERATE', 'GENERATE', null, [
            'unknown_payment_count' => count($rows),
            'voucher_count' => $generatedCount,
            'vouchers' => $voucherInfos,
        ]);
        Db::commit();
    } catch (\Exception $e) {
        Db::rollback();
        return $this->error('生成凭证失败：' . $e->getMessage());
    }
    return $this->ok([
        'generated_count' => $generatedCount,
        'unknown_payment_count' => count($rows),
        'vouchers' => $voucherInfos,
    ], '成功生成 ' . $generatedCount . ' 张凭证');
}
```

- [ ] **Step 5: Run generation and subject config tests**

Run:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundUnknownPaymentGenerateVoucherTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundSubjectConfigTest.php
```

Expected: both PASS. `CaseFundSubjectConfigTest.php` should include a source assertion that `allowedSubjectConfigItems` returns `['不明款']` for `CASE_FUND` plus `UNKNOWN_PAYMENT`.

---

### Task 5: Backend Delete API

**Files:**
- Modify: `backend/app/finance/model/CaseFund.php`
- Test: `backend/tests/CaseFundUnknownPaymentGenerateVoucherTest.php`

- [ ] **Step 1: Add failing delete assertions**

Append these source checks to `backend/tests/CaseFundUnknownPaymentGenerateVoucherTest.php`:

```php
foreach ([
    "case 'unknownPaymentDelete'" => 'CaseFund should route unknownPaymentDelete',
    'public function unknownPaymentDelete' => 'CaseFund should expose unknownPaymentDelete',
    "requirePermission('case_fund:delete')" => 'Unknown payment delete should check delete permission',
    "'UNKNOWN_PAYMENT'" => 'Unknown payment delete should use UNKNOWN_PAYMENT reconcile biz type',
] as $needle => $message) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundUnknownPaymentGenerateVoucherTest.php
```

Expected: FAIL on missing delete route.

- [ ] **Step 3: Implement delete route**

In `CaseFund::index`, add:

```php
case 'unknownPaymentDelete':
    return $this->unknownPaymentDelete($data);
```

Add method:

```php
public function unknownPaymentDelete($data = [])
{
    $auth = $this->requirePermission('case_fund:delete');
    if ($auth) return $auth;
    $ids = $this->normalizeIdList($data['unknown_payment_ids'] ?? ($data['ids'] ?? []));
    if (empty($ids)) return $this->error('请选择要删除的不明款记录');
    $where = $this->accountWhere();
    $where['fiscal_year'] = $this->currentYear();
    $where['voucher_status'] = 'UNGENERATED';
    $rows = $this->getdb(self::TABLE_UNKNOWN_PAYMENT)
        ->where($where)
        ->where('unknown_payment_id', 'in', $ids)
        ->field(self::UNKNOWN_PAYMENT_FIELD)
        ->select();
    if (count($rows) !== count($ids)) return $this->error('只能删除未制证的不明款记录');
    $reconcileCount = $this->getdb(self::TABLE_BANK_RECONCILE)->where([
        'account_set_id' => $this->accountSetId,
        'biz_type' => 'UNKNOWN_PAYMENT',
        'del_flag' => 0,
    ])->where('biz_id', 'in', $ids)->count();
    if ((int)$reconcileCount > 0) return $this->error('只能删除未对账的不明款记录');
    $update = ['del_flag' => 1];
    $this->fillUpdate($update);
    Db::startTrans();
    try {
        $this->getdb(self::TABLE_UNKNOWN_PAYMENT)->where([
            'account_set_id' => $this->accountSetId,
            'del_flag' => 0,
        ])->where('unknown_payment_id', 'in', $ids)->update($update);
        $this->logAudit('CASE_FUND_UNKNOWN_PAYMENT', 'DELETE', 'DELETE', $rows, [
            'unknown_payment_ids' => $ids,
            'deleted_count' => count($rows),
        ]);
        Db::commit();
    } catch (\Exception $e) {
        Db::rollback();
        return $this->error('删除不明款记录失败：' . $e->getMessage());
    }
    return $this->ok(['deleted_count' => count($rows)], '删除成功', count($rows));
}
```

- [ ] **Step 4: Run generation test**

Run:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundUnknownPaymentGenerateVoucherTest.php
```

Expected: PASS.

---

### Task 6: Frontend API and Types

**Files:**
- Modify: `frontend/src/api/caseFund.ts`
- Modify: `frontend/src/types/api.ts`
- Create: `frontend/tests/CaseFundUnknownPaymentViewTest.mjs`

- [ ] **Step 1: Write failing frontend API/view test**

Create `frontend/tests/CaseFundUnknownPaymentViewTest.mjs`:

```js
import { readFileSync, existsSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const api = readFileSync(resolve(__dirname, '../src/api/caseFund.ts'), 'utf8')
const types = readFileSync(resolve(__dirname, '../src/types/api.ts'), 'utf8')
const viewPath = resolve(__dirname, '../src/views/case-fund/UnknownPaymentView.vue')

assert(api.includes("apiAction('/caseFund/unknownPaymentList'"), 'case fund API should expose unknown payment list')
assert(api.includes("apiAction('/caseFund/unknownPaymentImport'"), 'case fund API should expose unknown payment import')
assert(api.includes("apiAction('/caseFund/unknownPaymentGenerateVoucher'"), 'case fund API should expose unknown payment voucher generation')
assert(api.includes("apiAction('/caseFund/unknownPaymentDelete'"), 'case fund API should expose unknown payment delete')
assert(types.includes('CaseFundUnknownPayment'), 'API types should include unknown payment rows')
assert(types.includes("'UNKNOWN_PAYMENT'"), 'subject config type should allow UNKNOWN_PAYMENT')
assert(existsSync(viewPath), 'unknown payment view should exist')

const view = existsSync(viewPath) ? readFileSync(viewPath, 'utf8') : ''
assert(view.includes('handleUnknownPaymentImportFile'), 'unknown payment view should handle selected xls file')
assert(view.includes('caseFundApi.importUnknownPayments'), 'unknown payment view should call import API')
assert(view.includes('caseFundApi.unknownPaymentList'), 'unknown payment view should load list API')
assert(view.includes('caseFundApi.unknownPaymentGenerateVoucher'), 'unknown payment view should generate vouchers')
assert(view.includes('caseFundApi.deleteUnknownPayments'), 'unknown payment view should delete rows')
assert(view.includes("voucherBizType: 'UNKNOWN_PAYMENT'"), 'unknown payment subject config should use UNKNOWN_PAYMENT')
assert(view.includes('交易日期'), 'unknown payment view should show transaction date')
assert(view.includes('缴款人'), 'unknown payment view should show payer')
assert(view.includes('银行备注'), 'unknown payment view should show bank remark')
assert(view.includes('法院银行账户'), 'unknown payment view should show court bank account')
assert(view.includes('认领状态'), 'unknown payment view should show claim status')
assert(view.includes('制证状态'), 'unknown payment view should show voucher status')

console.log('Case fund unknown payment frontend test passed')
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
node frontend/tests/CaseFundUnknownPaymentViewTest.mjs
```

Expected: FAIL on missing API wrappers or view.

- [ ] **Step 3: Add types**

In `frontend/src/types/api.ts`, add:

```ts
export interface CaseFundUnknownPayment {
  unknown_payment_id: string
  account_set_id: string
  fiscal_year: number
  period: string
  court_name: string
  claim_status: string
  payer_name: string
  unknown_amount: string | number
  bank_summary?: string
  bank_remark?: string
  transaction_date: string
  transaction_time: string
  court_bank_account_no?: string
  arrival_method?: string
  payer_bank_name?: string
  payer_account_no?: string
  claim_user_name?: string
  claim_date?: string
  last_modified_time?: string
  source_file_name?: string
  source_row_no?: number
  voucher_status: 'UNGENERATED' | 'GENERATED' | 'VOIDED' | string
  voucher_id?: string
  voucher_no?: number
  voucher_period?: string
  voucher_generated_time?: string
}
```

Change:

```ts
voucher_biz_type?: 'PAYMENT' | 'REFUND'
```

to:

```ts
voucher_biz_type?: 'PAYMENT' | 'REFUND' | 'UNKNOWN_PAYMENT'
```

- [ ] **Step 4: Add API wrappers**

In `frontend/src/api/caseFund.ts`, import the new type and add:

```ts
unknownPaymentList(payload: Record<string, any> = {}) {
  return apiAction('/caseFund/unknownPaymentList', payload) as Promise<{
    items: CaseFundUnknownPayment[]
    total: number
    total_amount?: string | number
  }>
},
importUnknownPayments(filename: string, contentBase64: string) {
  return apiAction('/caseFund/unknownPaymentImport', { filename, content_base64: contentBase64 }) as Promise<{
    total: number
    created: number
    skipped: number
  }>
},
deleteUnknownPayments(unknownPaymentIds: string[]) {
  return apiAction('/caseFund/unknownPaymentDelete', { unknown_payment_ids: unknownPaymentIds }) as Promise<{ deleted_count: number }>
},
unknownPaymentGenerateVoucher(unknownPaymentIds: string[]) {
  return apiAction('/caseFund/unknownPaymentGenerateVoucher', { unknown_payment_ids: unknownPaymentIds }) as Promise<{
    generated_count: number
    unknown_payment_count: number
    vouchers: { voucher_id: string; voucher_no: number; period: string }[]
  }>
},
```

Widen API method signatures for subject config:

```ts
subjectConfigList(voucherBizType: 'PAYMENT' | 'REFUND' | 'UNKNOWN_PAYMENT')
saveSubjectConfigs(
  voucherBizType: 'PAYMENT' | 'REFUND' | 'UNKNOWN_PAYMENT',
  items: CaseFundSubjectConfig[],
  generateVoucherByDayFlag = 1,
  generateRefundVoucherByOutOrderNoFlag?: number
)
```

- [ ] **Step 5: Run frontend API/view test**

Run:

```bash
node frontend/tests/CaseFundUnknownPaymentViewTest.mjs
```

Expected: still FAIL until the view is created.

---

### Task 7: Frontend Unknown Payment View

**Files:**
- Create: `frontend/src/views/case-fund/UnknownPaymentView.vue`
- Test: `frontend/tests/CaseFundUnknownPaymentViewTest.mjs`

- [ ] **Step 1: Create view from payment-page pattern**

Create `frontend/src/views/case-fund/UnknownPaymentView.vue` with these key bindings:

```vue
<template>
  <div class="case-fund-board">
    <div class="case-fund-toolbar">
      <div class="case-fund-filters">
        <el-select v-model="filters.quick_range" placeholder="快捷日期" clearable class="quick-range-select" @change="applyQuickRange">
          <el-option label="今天" value="today" />
          <el-option label="本周" value="week" />
          <el-option label="本月" value="month" />
          <el-option label="本年" value="year" />
        </el-select>
        <span class="date-filter-label">交易日期</span>
        <el-date-picker v-model="filters.date_start" type="date" value-format="YYYY-MM-DD" placeholder="开始日期" class="payment-date-picker" />
        <span class="date-separator">至</span>
        <el-date-picker v-model="filters.date_end" type="date" value-format="YYYY-MM-DD" placeholder="结束日期" class="payment-date-picker" />
        <el-select v-model="filters.voucher_status" placeholder="制证状态" clearable class="status-select">
          <el-option label="未生成凭证" value="UNGENERATED" />
          <el-option label="已生成凭证" value="GENERATED" />
          <el-option label="已作废" value="VOIDED" />
        </el-select>
        <el-select v-model="filters.claim_status" placeholder="认领状态" clearable class="status-select">
          <el-option label="待认领" value="待认领" />
          <el-option label="已认领" value="已认领" />
        </el-select>
        <el-input v-model="filters.keyword" placeholder="缴款人 / 备注 / 账号" clearable class="case-fund-search" @keyup.enter="query" />
        <el-button type="primary" :icon="Search" :loading="loading" @click="query">查询</el-button>
      </div>
      <div class="case-fund-actions">
        <input ref="importInputRef" type="file" accept=".xls" class="case-fund-file-input" @change="handleUnknownPaymentImportFile" />
        <el-button v-permission="'case_fund:generate_voucher'" type="success" :icon="DocumentChecked" :loading="generating" @click="handleGenerateVoucher">生成凭证</el-button>
        <el-dropdown v-if="canUseMoreActions" trigger="click" @command="handleMoreAction">
          <el-button :icon="MoreFilled">更多功能<el-icon class="el-icon--right"><ArrowDown /></el-icon></el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item v-permission="'case_fund:delete'" command="delete" :icon="Delete" :disabled="deleting" class="danger-dropdown-item" style="color: var(--el-color-danger)">删除</el-dropdown-item>
              <el-dropdown-item v-permission="'case_fund:subject_config'" command="subjectConfig" :icon="Setting">科目配置</el-dropdown-item>
              <el-dropdown-item v-permission="'case_fund:import'" command="import" :icon="Upload" :disabled="importing">导入不明款</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </div>
    </div>
    <div class="case-fund-summary">
      <div><span>登记笔数</span><strong>{{ total }}</strong></div>
      <div><span>本页金额</span><strong>{{ pageAmountText }}</strong></div>
      <div><span>未制证</span><strong>{{ ungeneratedCount }}</strong></div>
    </div>
    <div class="case-fund-table-shell">
      <el-table :data="rows" border height="calc(100vh - 292px)" v-loading="loading" @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="55" :selectable="isRowSelectable" fixed="left" />
        <el-table-column prop="transaction_date" label="交易日期" width="112" fixed="left" />
        <el-table-column prop="claim_status" label="认领状态" width="100" />
        <el-table-column prop="payer_name" label="缴款人" min-width="150" show-overflow-tooltip />
        <el-table-column prop="unknown_amount" label="金额" width="130" align="right"><template #default="{ row }">{{ money(row.unknown_amount) }}</template></el-table-column>
        <el-table-column prop="bank_summary" label="银行摘要" min-width="160" show-overflow-tooltip />
        <el-table-column prop="bank_remark" label="银行备注" min-width="220" show-overflow-tooltip />
        <el-table-column prop="court_bank_account_no" label="法院银行账户" min-width="190" show-overflow-tooltip />
        <el-table-column prop="arrival_method" label="到账方式" width="110" show-overflow-tooltip />
        <el-table-column prop="payer_bank_name" label="缴款人开户行" min-width="220" show-overflow-tooltip />
        <el-table-column prop="payer_account_no" label="缴款人账号" min-width="180" show-overflow-tooltip />
        <el-table-column prop="claim_user_name" label="认领人姓名" width="120" show-overflow-tooltip />
        <el-table-column prop="claim_date" label="认领日期" width="112" />
        <el-table-column label="制证状态" width="120" align="center" fixed="right">
          <template #default="{ row }"><el-tag :type="voucherStatusType(row.voucher_status)" effect="light">{{ voucherStatusLabel(row.voucher_status) }}</el-tag></template>
        </el-table-column>
        <el-table-column label="凭证" width="110" align="center" fixed="right">
          <template #default="{ row }"><span v-if="row.voucher_no">{{ row.voucher_period }}-{{ row.voucher_no }}</span><span v-else class="muted-text">-</span></template>
        </el-table-column>
      </el-table>
    </div>
  </div>
</template>
```

Use the `PaymentRegisterView.vue` script helpers for `money`, `voucherStatusLabel`, `voucherStatusType`, file-to-base64, quick date ranges, subject loading, subject labels, selection, generation confirmation, delete confirmation, and pagination. Set:

```ts
const voucherBizType: 'UNKNOWN_PAYMENT' = 'UNKNOWN_PAYMENT'
const rows = ref<CaseFundUnknownPayment[]>([])
const selectedUnknownPaymentIds = ref<string[]>([])
const selectedUnknownPaymentRows = ref<CaseFundUnknownPayment[]>([])
```

Use API calls:

```ts
caseFundApi.unknownPaymentList({
  date_start: filters.date_start,
  date_end: filters.date_end,
  voucher_status: filters.voucher_status,
  claim_status: filters.claim_status,
  keyword: filters.keyword,
  page: page.value,
  pagesize: pageSize.value
})
caseFundApi.importUnknownPayments(file.name, base64)
caseFundApi.unknownPaymentGenerateVoucher(selectedUnknownPaymentIds.value)
caseFundApi.deleteUnknownPayments(selectedUnknownPaymentIds.value)
caseFundApi.subjectConfigList(voucherBizType)
caseFundApi.saveSubjectConfigs(voucherBizType, subjectConfigRows.value, generateVoucherByDay.value ? 1 : 0)
```

- [ ] **Step 2: Run frontend view test**

Run:

```bash
node frontend/tests/CaseFundUnknownPaymentViewTest.mjs
```

Expected: PASS.

---

### Task 8: Frontend Route, Menu, Dashboard, Subject Config Tests

**Files:**
- Modify: `frontend/src/router/index.ts`
- Modify: `frontend/src/App.vue`
- Modify: `frontend/src/views/dashboard/DashboardView.vue`
- Modify: `frontend/tests/CaseFundSubjectConfigTest.mjs`
- Test: `frontend/tests/CaseFundMenuTest.mjs`

- [ ] **Step 1: Add route**

In `frontend/src/router/index.ts`, add:

```ts
const UnknownPaymentView = () => import('../views/case-fund/UnknownPaymentView.vue')
```

Add child route:

```ts
{ path: '/case-fund/unknown-payments', component: UnknownPaymentView, meta: { permission: 'menu:case_fund:unknown_payment' } },
```

- [ ] **Step 2: Add sidebar item and title**

In `frontend/src/App.vue`, change the案款 submenu wrapper to include the biz-type gate:

```vue
<el-sub-menu v-if="context.bizType === 'CASE_FUND' && context.hasAnyPermission(caseFundMenuPermissions)" index="/case-fund">
```

Add menu item:

```vue
<el-menu-item v-if="context.hasPermission('menu:case_fund:unknown_payment')" index="/case-fund/unknown-payments">不明款</el-menu-item>
```

Update permission array:

```ts
const caseFundMenuPermissions = ['menu:case_fund:payment', 'menu:case_fund:refund', 'menu:case_fund:bank_statement', 'menu:case_fund:unknown_payment']
```

Update `titleMap`:

```ts
'/case-fund/unknown-payments': '不明款',
```

- [ ] **Step 3: Add dashboard entry**

In `frontend/src/views/dashboard/DashboardView.vue`, inside the `CASE_FUND` quick entries, add:

```ts
{ label: '不明款', desc: '导入待认领到账并生成凭证', path: '/case-fund/unknown-payments', icon: Tickets },
```

- [ ] **Step 4: Update subject config frontend test**

In `frontend/tests/CaseFundSubjectConfigTest.mjs`, add:

```js
const unknownPaymentView = readFileSync(resolve(__dirname, '../src/views/case-fund/UnknownPaymentView.vue'), 'utf8')
assert(unknownPaymentView.includes('openSubjectConfig'), 'unknown payment register should open subject config dialog')
assert(unknownPaymentView.includes("voucherBizType: 'UNKNOWN_PAYMENT'"), 'unknown payment subject config should use UNKNOWN_PAYMENT voucher business type')
assert(unknownPaymentView.includes('不明款科目配置') || unknownPaymentView.includes('科目配置'), 'unknown payment subject config should show subject config title')
assert(unknownPaymentView.includes('借方科目') && unknownPaymentView.includes('贷方科目'), 'unknown payment subject config should edit debit and credit subjects')
assert(unknownPaymentView.includes('是否按天生成凭证'), 'unknown payment subject config should show daily voucher switch')
```

- [ ] **Step 5: Run frontend tests**

Run:

```bash
node frontend/tests/CaseFundMenuTest.mjs
node frontend/tests/CaseFundUnknownPaymentViewTest.mjs
node frontend/tests/CaseFundSubjectConfigTest.mjs
```

Expected: all PASS.

---

### Task 9: Full Verification

**Files:**
- Verify all touched files.

- [ ] **Step 1: Run backend focused tests**

Run:

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundUnknownPaymentImportTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundUnknownPaymentGenerateVoucherTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundSubjectConfigTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundPaymentGenerateVoucherTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundRefundGenerateVoucherTest.php
```

Expected: every command prints that test file's pass message.

- [ ] **Step 2: Run frontend focused tests**

Run:

```bash
node frontend/tests/CaseFundMenuTest.mjs
node frontend/tests/CaseFundUnknownPaymentViewTest.mjs
node frontend/tests/CaseFundSubjectConfigTest.mjs
node frontend/tests/CaseFundDeleteActionsTest.mjs
node frontend/tests/CaseFundRegenerateSelectionTest.mjs
```

Expected: every command prints its pass message.

- [ ] **Step 3: Run frontend build**

Run:

```bash
cd frontend && npm run build
```

Expected: `vue-tsc --noEmit && vite build` completes successfully.

- [ ] **Step 4: Run whitespace check**

Run:

```bash
git diff --check
```

Expected: no output.

- [ ] **Step 5: Review changed files**

Run:

```bash
git diff -- backend/app/finance/model/CaseFund.php backend/database/court_finance_ddl.sql backend/database/finance_standard_patch.sql backend/database/migration_rbac.sql frontend/src/api/caseFund.ts frontend/src/types/api.ts frontend/src/views/case-fund/UnknownPaymentView.vue frontend/src/router/index.ts frontend/src/App.vue frontend/src/views/dashboard/DashboardView.vue
```

Expected: diff only contains the unknown-payment feature and does not revert unrelated existing workspace changes.

---

## Self-Review Notes

- Spec coverage: table, import, list, voucher generation, subject config, menu, route, dashboard, permissions, delete, and tests are all mapped to tasks.
- TDD coverage: each behavioral layer starts with a failing PHP or Node assertion test before production code.
- Type consistency: backend uses `UNKNOWN_PAYMENT`, `unknown_payment_id`, and `unknown_amount`; frontend uses `CaseFundUnknownPayment`, `unknownPaymentList`, and `unknownPaymentGenerateVoucher`.
- AGENTS safety: no bulk deletion commands are used. Delete functionality is implemented as database soft delete only.
