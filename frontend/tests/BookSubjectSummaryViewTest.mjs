import { readFileSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const app = readFileSync(resolve(__dirname, '../src/App.vue'), 'utf8')
const router = readFileSync(resolve(__dirname, '../src/router/index.ts'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/books.ts'), 'utf8')
const types = readFileSync(resolve(__dirname, '../src/types/api.ts'), 'utf8')
const viewPath = resolve(__dirname, '../src/views/books/SubjectSummaryView.vue')

assert(existsSync(viewPath), 'subject summary view should exist')
const view = readFileSync(viewPath, 'utf8')

assert(app.includes('menu:book:subject_summary'), 'sidebar should check subject summary menu permission')
assert(app.includes('index="/books/subject-summary"'), 'sidebar should route to subject summary page')
assert(app.includes('科目汇总表'), 'sidebar and title map should show subject summary menu item')

assert(router.includes("const SubjectSummaryView = () => import('../views/books/SubjectSummaryView.vue')"), 'router should lazy-load subject summary view')
assert(router.includes("'/books/subject-summary'"), 'router should register subject summary route')
assert(router.includes("permission: 'menu:book:subject_summary'"), 'subject summary route should require menu permission')

assert(api.includes("apiAction('/book/subjectSummary'"), 'books API should expose subject summary endpoint')
assert(types.includes('SubjectSummaryRow'), 'API types should include subject summary rows')

assert(view.includes('subjectStartCode'), 'view should filter by starting subject code')
assert(view.includes('subjectEndCode'), 'view should filter by ending subject code')
assert(view.includes('subjectLevel'), 'view should filter by subject level')
assert(view.includes('periodMonthRange(context.period)'), 'subject summary date range should default to the selected accounting period')
assert(!view.includes('now.getMonth() + 1'), 'subject summary date range should not use the current natural month')
assert(view.includes('entry_count'), 'view should show voucher entry count')
assert(view.includes('debit_amount'), 'view should show debit amount')
assert(view.includes('credit_amount'), 'view should show credit amount')
assert(view.includes('balance_amount'), 'view should show net amount')
assert(view.includes("v-permission=\"'book:export'\""), 'export button should require book export permission')
assert(!view.includes("import * as XLSX from 'xlsx'"), 'view should not eagerly import xlsx for Excel export')
assert(view.includes("await import('xlsx')"), 'view should dynamically import xlsx only when exporting')
assert(view.includes('XLSX.writeFile'), 'view should write subject summary Excel file')
assert(!view.includes('科目汇总表 · {{ context.period }}'), 'subject summary header should not repeat accounting period')
assert(view.includes('@row-dblclick="openDetailLedger"'), 'summary row double click should drill down to detail ledger')
assert(view.includes('@row-contextmenu="openDetailLedgerMenu"'), 'summary row context menu should drill down to detail ledger')
assert(view.includes("path: '/books/detail-ledger'"), 'summary drilldown should route to detail ledger')
assert(view.includes('subject_code: row.subject_code'), 'summary drilldown should pass subject code')
assert(view.includes('period: context.period'), 'summary drilldown should pass period')
assert(view.includes('year: context.year'), 'summary drilldown should pass year')

console.log('Book subject summary frontend test passed')
