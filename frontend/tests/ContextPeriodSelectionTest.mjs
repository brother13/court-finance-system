import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const store = readFileSync(resolve(__dirname, '../src/stores/context.ts'), 'utf8')
const app = readFileSync(resolve(__dirname, '../src/App.vue'), 'utf8')
const voucherEditor = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherEditorView.vue'), 'utf8')
const voucherList = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')
const detailLedger = readFileSync(resolve(__dirname, '../src/views/books/DetailLedgerView.vue'), 'utf8')
const subjectSummary = readFileSync(resolve(__dirname, '../src/views/books/SubjectSummaryView.vue'), 'utf8')
const subjectBalance = readFileSync(resolve(__dirname, '../src/views/books/SubjectBalanceView.vue'), 'utf8')
const dashboard = readFileSync(resolve(__dirname, '../src/views/dashboard/DashboardView.vue'), 'utf8')
const opening = readFileSync(resolve(__dirname, '../src/views/base/OpeningBalanceView.vue'), 'utf8')
const caseFundViews = [
  'PaymentRegisterView.vue',
  'RefundRegisterView.vue',
  'BankStatementView.vue',
  'UnknownPaymentView.vue',
  'DistributionView.vue'
].map((file) => readFileSync(resolve(__dirname, `../src/views/case-fund/${file}`), 'utf8'))

assert(store.includes('selectedYearMonthPeriod'), 'context store should derive selected year period from current month')
assert(store.includes('periodForSelectedYear'), 'context store should avoid falling back to selectedYear-01')
assert(!store.includes('normalizeLoadedPeriod'), 'context store should not silently rewrite a saved accounting period')
assert(!store.includes('`${year || accountSet.enabled_year || periodYear(currentMonthPeriod())}-01`'), 'account set selection should not default every selected year to January')
assert(voucherEditor.includes('syncCurrentAccountSetPeriod'), 'new voucher editor should refresh current account period before loading')
assert(voucherEditor.includes('currentAccountSet.current_period'), 'new voucher editor should prefer backend current_period')
assert(store.includes('setPeriod(period: string)'), 'context store should expose a period setter for voucher date changes')
assert(app.includes('class="topbar-period-select"'), 'top bar should render an accounting period selector')
assert(app.includes('@change="handleGlobalPeriodChange"'), 'top bar period selector should handle changes')
assert(app.includes('context.setPeriod(period)'), 'top bar period selector should update the shared accounting period')
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
assert(voucherList.includes('() => context.period'), 'voucher list should react to the shared accounting period')
assert(voucherList.includes('topFilter.period_start = period') && voucherList.includes('topFilter.period_end = period'), 'voucher list should reset its period range to the shared accounting period')
assert(voucherEditor.includes('period === form.period'), 'new voucher editor should react when the shared accounting period changes')
assert(detailLedger.includes('filters.startPeriod = period') && detailLedger.includes('filters.endPeriod = period'), 'detail ledger should follow shared accounting period changes')
assert(subjectSummary.includes('() => context.period'), 'subject summary should react to shared accounting period changes')
assert(subjectBalance.includes('() => context.period'), 'subject balance should react to shared accounting period changes')
assert(dashboard.includes('watch(() => context.period, load)'), 'dashboard should reload for shared accounting period changes')
assert(opening.includes('period.value = selectedPeriod'), 'opening balance should follow shared accounting period changes')
for (const view of caseFundViews) {
  assert(view.includes('() => context.period'), 'every case-fund page should react to shared accounting period changes')
  assert(view.includes('filters.date_start = range.start') && view.includes('filters.date_end = range.end'), 'every case-fund page should reset its date range to the selected month')
}

console.log('Context period selection test passed')
