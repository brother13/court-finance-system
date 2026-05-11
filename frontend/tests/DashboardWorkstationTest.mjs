import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const dashboard = readFileSync(resolve(__dirname, '../src/views/dashboard/DashboardView.vue'), 'utf8')

assert(!dashboard.includes('class="page-header"'), 'dashboard should rely on the app shell title and not render a duplicate page header')
assert(!dashboard.includes('Refresh,'), 'dashboard should not import a refresh icon for the removed header action')
assert(!dashboard.includes('fund-trend-bars'), 'dashboard should remove the synthetic trend panel')
assert(!dashboard.includes('dashboard.trend'), 'dashboard should not generate fake trend data')
assert(!dashboard.includes('待核对案款'), 'dashboard should not show unclear fake pending case-fund metric')
assert(!dashboard.includes('Math.abs(Number(row.balance_amount'), 'dashboard should not sum absolute subject balances across parent/child rows')
assert(dashboard.includes('summaryRows'), 'dashboard should calculate totals from leaf/summary-safe subject rows')
assert(dashboard.includes('ending_debit_amount'), 'dashboard should use standard subject balance ending debit field')
assert(dashboard.includes('ending_credit_amount'), 'dashboard should use standard subject balance ending credit field')
assert(dashboard.includes('year_debit_amount'), 'dashboard should use year-to-date debit field')
assert(dashboard.includes('year_credit_amount'), 'dashboard should use year-to-date credit field')
assert(dashboard.includes('booksApi.auxBalance'), 'dashboard should load auxiliary balance for case-fund monitoring')
assert(dashboard.includes('unsettledReceiptCount'), 'dashboard should expose unsettled receipt count from auxiliary balance')
assert(dashboard.includes('operationSections'), 'dashboard should group useful work entries by business and accounting work')
assert(dashboard.includes('sourceItems'), 'dashboard should show data source links for dashboard numbers')
assert(dashboard.includes('dashboard-work-grid'), 'dashboard should prioritize work entries over the overview panel')
assert(dashboard.includes('dashboard-entry-panel'), 'dashboard work entries should use the compact entry panel layout')
assert(dashboard.includes('dashboard-compact-status-list'), 'dashboard overview should use a compact status layout')
assert(dashboard.includes("path: '/case-fund/payments'"), 'case-fund dashboard should keep payment register entry')
assert(dashboard.includes("path: '/case-fund/refunds'"), 'case-fund dashboard should keep refund register entry')
assert(dashboard.includes("path: '/case-fund/bank-statements'"), 'case-fund dashboard should keep bank statement entry')
assert(dashboard.includes("path: '/books/subject-balance'"), 'dashboard should link to subject balance source report')
assert(dashboard.includes("path: '/books/aux-balance'"), 'dashboard should link to auxiliary balance source report')

console.log('Dashboard workstation test passed')
