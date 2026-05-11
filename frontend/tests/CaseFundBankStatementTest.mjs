import { readFileSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const app = readFileSync(resolve(__dirname, '../src/App.vue'), 'utf8')
const router = readFileSync(resolve(__dirname, '../src/router/index.ts'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/caseFund.ts'), 'utf8')
const types = readFileSync(resolve(__dirname, '../src/types/api.ts'), 'utf8')
const dashboard = readFileSync(resolve(__dirname, '../src/views/dashboard/DashboardView.vue'), 'utf8')
const viewPath = resolve(__dirname, '../src/views/case-fund/BankStatementView.vue')

assert(existsSync(viewPath), 'bank statement view should exist')
const view = readFileSync(viewPath, 'utf8')

assert(app.includes('menu:case_fund:bank_statement'), 'sidebar should check bank statement menu permission')
assert(app.includes('index="/case-fund/bank-statements"'), 'sidebar should route to bank statement page')
assert(app.includes('银行对账单'), 'sidebar should show bank statement menu item')

assert(router.includes("const BankStatementView = () => import('../views/case-fund/BankStatementView.vue')"), 'router should lazy-load bank statement view')
assert(router.includes("'/case-fund/bank-statements'"), 'router should register bank statement route')
assert(router.includes("permission: 'menu:case_fund:bank_statement'"), 'bank statement route should require menu permission')

assert(api.includes("apiAction('/caseFund/bankStatementList'"), 'case fund API should expose bank statement list')
assert(api.includes("apiAction('/caseFund/bankStatementImport'"), 'case fund API should expose bank statement import')
assert(api.includes("apiAction('/caseFund/bankReconcileRun'"), 'case fund API should expose bank reconcile run')
assert(api.includes("apiAction('/caseFund/bankReconcileList'"), 'case fund API should expose bank reconcile list')
assert(types.includes('CaseFundBankStatement'), 'API types should include bank statement rows')
assert(types.includes('CaseFundBankReconcile'), 'API types should include bank reconcile rows')

assert(view.includes('盛京银行') && view.includes('建设银行'), 'bank statement import should offer Shengjing Bank and CCB')
assert(view.includes('SHENGJING') && view.includes('CCB'), 'bank statement import should use stable bank codes')
assert(view.includes('import * as XLSX from'), 'bank statement view should parse xlsx files in frontend')
assert(view.includes('parseShengjingRows'), 'bank statement view should include Shengjing Bank parser')
assert(view.includes('chooseBankStatementImportFile'), 'bank statement view should provide import file picker')
assert(view.includes('caseFundApi.importBankStatements'), 'bank statement view should call import API')
assert(view.includes('caseFundApi.bankStatementList'), 'bank statement view should load list API')
assert(view.includes('caseFundApi.runBankReconcile'), 'bank statement view should run auto reconcile')
assert(view.includes('caseFundApi.bankReconcileList'), 'bank statement view should load reconcile result list')
assert(view.includes("v-permission=\"'case_fund:reconcile'\""), 'auto reconcile button should require reconcile permission')
assert(view.includes('交易流水号'), 'bank statement table should show bank serial no')
assert(view.includes('对方户名'), 'bank statement table should show counterparty account name')
assert(view.includes('金额不符'), 'bank statement view should show amount difference status')
assert(view.includes('业务未匹配'), 'bank statement view should show business-only status')
assert(view.includes('出账单号'), 'refund reconcile result should expose out order no matching basis')

assert(dashboard.includes("path: '/case-fund/bank-statements'"), 'case fund dashboard should link to bank statement page')

console.log('Case fund bank statement frontend test passed')
