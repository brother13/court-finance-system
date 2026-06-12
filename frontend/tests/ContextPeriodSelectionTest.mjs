import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const store = readFileSync(resolve(__dirname, '../src/stores/context.ts'), 'utf8')
const voucherEditor = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherEditorView.vue'), 'utf8')
const detailLedger = readFileSync(resolve(__dirname, '../src/views/books/DetailLedgerView.vue'), 'utf8')
const subjectSummary = readFileSync(resolve(__dirname, '../src/views/books/SubjectSummaryView.vue'), 'utf8')

assert(store.includes('selectedYearMonthPeriod'), 'context store should derive selected year period from current month')
assert(store.includes('periodForSelectedYear'), 'context store should avoid falling back to selectedYear-01')
assert(!store.includes('normalizeLoadedPeriod'), 'context store should not silently rewrite a saved accounting period')
assert(!store.includes('`${year || accountSet.enabled_year || periodYear(currentMonthPeriod())}-01`'), 'account set selection should not default every selected year to January')
assert(voucherEditor.includes('syncCurrentAccountSetPeriod'), 'new voucher editor should refresh current account period before loading')
assert(voucherEditor.includes('currentAccountSet.current_period'), 'new voucher editor should prefer backend current_period')
assert(store.includes('setPeriod(period: string)'), 'context store should expose a period setter for voucher date changes')
assert(voucherEditor.includes('@change="handleVoucherDateChange"'), 'voucher date picker should update accounting period when changed')
assert(voucherEditor.includes('periodFromVoucherDate(form.voucherDate)'), 'voucher editor should derive accounting period from voucher date')
assert(voucherEditor.includes('await loadNextNo()'), 'voucher editor should reload next voucher number after period changes')
const voucherEditorMounted = voucherEditor.slice(voucherEditor.indexOf('onMounted(async () => {'))
assert(voucherEditorMounted.indexOf('await syncCurrentAccountSetPeriod()') < voucherEditorMounted.indexOf('await loadNextNo()'), 'new voucher editor should sync period before loading next voucher number')
assert(detailLedger.includes('startPeriod: String(route.query.start_period || initialPeriod)'), 'detail ledger default start period should align to selected accounting period')
assert(detailLedger.includes('endPeriod: String(route.query.end_period || initialPeriod)'), 'detail ledger default end period should align to selected accounting period')
assert(detailLedger.includes('periodMonthRange(filters.startPeriod)'), 'detail ledger start date should align to selected start accounting period')
assert(detailLedger.includes('periodMonthRange(filters.endPeriod)'), 'detail ledger end date should align to selected ending accounting period')
assert(subjectSummary.includes('startPeriod: context.period'), 'subject summary default start period should align to selected accounting period')
assert(subjectSummary.includes('endPeriod: context.period'), 'subject summary default end period should align to selected accounting period')
assert(!detailLedger.includes('now.getMonth() + 1'), 'detail ledger should not default to the current natural month')

console.log('Context period selection test passed')
