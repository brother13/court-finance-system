import { readFileSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const app = readFileSync(resolve(__dirname, '../src/App.vue'), 'utf8')
const router = readFileSync(resolve(__dirname, '../src/router/index.ts'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/books.ts'), 'utf8')
const types = readFileSync(resolve(__dirname, '../src/types/api.ts'), 'utf8')
const viewPath = resolve(__dirname, '../src/views/books/CaseMovementDetailView.vue')

assert(existsSync(viewPath), 'case movement detail view should exist')
const view = readFileSync(viewPath, 'utf8')

assert(app.includes("menu:book:case_movement_detail"), 'sidebar should require case movement detail permission')
assert(app.includes('index="/books/case-movement-detail"'), 'sidebar should route to the case movement detail page')
assert(app.includes('案号收支明细表'), 'sidebar and title map should use the approved report name')
assert(view.includes("defineOptions({ name: 'CaseMovementDetailView' })"), 'case movement detail view should declare a stable name for page caching')
assert(router.includes("const CaseMovementDetailView = () => import('../views/books/CaseMovementDetailView.vue')"), 'router should lazy-load case movement detail')
assert(router.includes("'/books/case-movement-detail'"), 'router should register case movement detail route')
assert(router.includes("permission: 'menu:book:case_movement_detail'"), 'route should require report menu permission')
assert(api.includes("apiAction('/book/caseMovementDetail'"), 'books API should expose the case movement endpoint')
assert(types.includes('CaseMovementDetailRow'), 'API types should define case movement rows')
assert(types.includes('CaseMovementDetailResult'), 'API types should define the paged report response')

assert(view.includes('账套启用以来'), 'page should explain its cross-year query range')
assert(view.includes('不重复计入年度期初结转'), 'page should explain that annual opening carry-forward is not a movement')
assert(view.includes('query.caseNo'), 'page should filter by case number')
assert(view.includes('query.receiptNo'), 'page should filter by receipt number')
assert(view.includes('query.subjectCode'), 'page should filter by case auxiliary subject')
assert(view.includes('refreshSnapshot'), 'page should allow an explicit cache rebuild')
assert(view.includes('v-model:current-page="page"'), 'page should use server pagination')
assert(view.includes(':total="pageTotal"'), 'pagination should use server total')
assert(view.includes('debit_total'), 'page should display filtered debit total')
assert(view.includes('credit_total'), 'page should display filtered credit total')
assert(view.includes('/vouchers/detail/${row.period}/${row.voucher_id}'), 'voucher number should drill down to exact voucher detail')
assert(view.includes("context.hasPermission('voucher:view')"), 'voucher drill-down should honor voucher view permission')
assert(view.includes("await import('xlsx')"), 'Excel export should lazy-load xlsx')
assert(view.includes('exportPageSize = 200'), 'Excel export should fetch all rows through bounded pages')
assert(view.includes("v-permission=\"'book:export'\""), 'export should require book export permission')

console.log('Case movement detail frontend test passed')
