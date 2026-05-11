import { readFileSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const app = readFileSync(resolve(__dirname, '../src/App.vue'), 'utf8')
const router = readFileSync(resolve(__dirname, '../src/router/index.ts'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/books.ts'), 'utf8')
const types = readFileSync(resolve(__dirname, '../src/types/api.ts'), 'utf8')
const viewPath = resolve(__dirname, '../src/views/books/AuxBalanceView.vue')

assert(existsSync(viewPath), 'auxiliary balance view should exist')
const view = readFileSync(viewPath, 'utf8')

assert(app.includes('menu:book:aux_balance'), 'sidebar should check auxiliary balance menu permission')
assert(app.includes('index="/books/aux-balance"'), 'sidebar should route to auxiliary balance page')
assert(app.includes('辅助核算余额表'), 'sidebar and title map should show auxiliary balance menu item')

assert(router.includes("const AuxBalanceView = () => import('../views/books/AuxBalanceView.vue')"), 'router should lazy-load auxiliary balance view')
assert(router.includes("'/books/aux-balance'"), 'router should register auxiliary balance route')
assert(router.includes("permission: 'menu:book:aux_balance'"), 'auxiliary balance route should require menu permission')

assert(api.includes("apiAction('/book/auxBalance'"), 'books API should expose aux balance endpoint')
assert(api.includes("apiAction('/book/auxBalanceSubjects'"), 'books API should expose current account set auxiliary balance subjects endpoint')
assert(types.includes('AuxBalanceRow'), 'API types should include auxiliary balance rows')

assert(view.includes('<el-select'), 'aux balance subject filter should be a current account set subject selector')
assert(view.includes('subjectOptions'), 'aux balance view should load selectable subjects from API')
assert(view.includes('booksApi.auxBalanceSubjects'), 'aux balance view should use backend filtered subject options')
assert(!view.includes("subjectCode: '220101'"), 'aux balance view should not hard-code a subject from another account set')
assert(view.includes('row-key="row_key"'), 'aux balance table should support tree rows')
assert(view.includes('children'), 'aux balance table should show receipt rows under case rows')
assert(view.includes('case_no'), 'aux balance table should show case number')
assert(view.includes('receipt_no'), 'aux balance table should show receipt number')
assert(view.includes('opening_balance_amount'), 'aux balance table should show opening balance')
assert(view.includes('ending_balance_amount'), 'aux balance table should show ending balance')
assert(view.includes('累计借方'), 'aux balance table should label movements as cumulative debit')
assert(view.includes('累计贷方'), 'aux balance table should label movements as cumulative credit')
assert(view.includes('monitor_flag'), 'aux balance table should highlight non-zero receipt balances')
assert(view.includes('仅看未清收据'), 'aux balance view should filter monitor items')
assert(view.includes("v-permission=\"'book:export'\""), 'export button should require book export permission')
assert(!view.includes("import * as XLSX from 'xlsx'"), 'view should not eagerly import xlsx for Excel export')
assert(view.includes("await import('xlsx')"), 'view should dynamically import xlsx only when exporting')
assert(view.includes('XLSX.writeFile'), 'view should write auxiliary balance Excel file')
assert(!view.includes('辅助核算余额表 · {{ context.period }}'), 'aux balance header should not repeat accounting period')

console.log('Book aux balance frontend test passed')
