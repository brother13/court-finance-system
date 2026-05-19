import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const store = readFileSync(resolve(__dirname, '../src/stores/context.ts'), 'utf8')
const detailLedger = readFileSync(resolve(__dirname, '../src/views/books/DetailLedgerView.vue'), 'utf8')
const subjectSummary = readFileSync(resolve(__dirname, '../src/views/books/SubjectSummaryView.vue'), 'utf8')

assert(store.includes('selectedYearMonthPeriod'), 'context store should derive selected year period from current month')
assert(store.includes('periodForSelectedYear'), 'context store should avoid falling back to selectedYear-01')
assert(!store.includes('normalizeLoadedPeriod'), 'context store should not silently rewrite a saved accounting period')
assert(!store.includes('`${year || accountSet.enabled_year || periodYear(currentMonthPeriod())}-01`'), 'account set selection should not default every selected year to January')
assert(detailLedger.includes('startPeriod: String(route.query.start_period || initialPeriod)'), 'detail ledger default start period should align to selected accounting period')
assert(detailLedger.includes('endPeriod: String(route.query.end_period || initialPeriod)'), 'detail ledger default end period should align to selected accounting period')
assert(detailLedger.includes('periodMonthRange(filters.startPeriod)'), 'detail ledger start date should align to selected start accounting period')
assert(detailLedger.includes('periodMonthRange(filters.endPeriod)'), 'detail ledger end date should align to selected ending accounting period')
assert(subjectSummary.includes('startPeriod: context.period'), 'subject summary default start period should align to selected accounting period')
assert(subjectSummary.includes('endPeriod: context.period'), 'subject summary default end period should align to selected accounting period')
assert(!detailLedger.includes('now.getMonth() + 1'), 'detail ledger should not default to the current natural month')

console.log('Context period selection test passed')
