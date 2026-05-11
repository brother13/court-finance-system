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
assert(detailLedger.includes('periodMonthRange(selectedPeriod.value)'), 'detail ledger default date range should align to selected accounting period month')
assert(subjectSummary.includes('periodMonthRange(context.period)'), 'subject summary default date range should align to selected accounting period month')
assert(!detailLedger.includes('now.getMonth() + 1'), 'detail ledger should not default to the current natural month')
assert(!subjectSummary.includes('now.getMonth() + 1'), 'subject summary should not default to the current natural month')

console.log('Context period selection test passed')
