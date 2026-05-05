import { readFileSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const app = readFileSync(resolve(__dirname, '../src/App.vue'), 'utf8')
const router = readFileSync(resolve(__dirname, '../src/router/index.ts'), 'utf8')
const apiPath = resolve(__dirname, '../src/api/caseFund.ts')
const viewPath = resolve(__dirname, '../src/views/case-fund/PaymentRegisterView.vue')

assert(app.includes('menu:case_fund:payment'), 'sidebar should check payment register menu permission')
assert(app.includes('index="/case-fund/payments"'), 'sidebar should route to case fund payment register')
assert(app.includes('案款业务'), 'sidebar should show case fund business menu')
assert(app.includes('案款缴费登记'), 'sidebar should show payment register menu item')
assert(app.includes('案款退付登记'), 'sidebar should reserve refund register menu item')

assert(router.includes("PaymentRegisterView"), 'router should import payment register view')
assert(router.includes("'/case-fund/payments'"), 'router should register payment page route')
assert(router.includes("permission: 'menu:case_fund:payment'"), 'payment route should require payment menu permission')

assert(existsSync(apiPath), 'case fund API wrapper should exist')
assert(existsSync(viewPath), 'payment register view should exist')

const api = readFileSync(apiPath, 'utf8')
const view = readFileSync(viewPath, 'utf8')
assert(api.includes("apiAction('/caseFund/paymentList'"), 'case fund API should expose payment list')
assert(api.includes("apiAction('/caseFund/paymentImport'"), 'case fund API should expose payment import')
assert(view.includes('choosePaymentImportFile'), 'payment view should provide import file picker')
assert(view.includes('handlePaymentImportFile'), 'payment view should handle selected xls file')
assert(view.includes('caseFundApi.importPayments'), 'payment view should call payment import API')
assert(view.includes('caseFundApi.paymentList'), 'payment view should load payment list API')
assert(view.includes('voucher_status'), 'payment view should show voucher generation status')
assert(view.includes('date_start'), 'payment view should query by payment start date')
assert(view.includes('date_end'), 'payment view should query by payment end date')
assert(!view.includes('v-model="filters.period"'), 'payment view should not use accounting period as the first date filter')
assert(view.includes('今天') && view.includes('本周') && view.includes('本月') && view.includes('本年'), 'payment view should provide quick payment date ranges')
assert(view.includes('@click="query"'), 'payment view should include a query button')
assert(!view.includes('>刷新</el-button>'), 'payment view should not keep a separate refresh button')

console.log('Case fund menu test passed')
