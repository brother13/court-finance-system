import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const router = readFileSync(resolve(__dirname, '../src/router/index.ts'), 'utf8')

const viewPaths = [
  '../views/dashboard/DashboardView.vue',
  '../views/voucher/VoucherListView.vue',
  '../views/voucher/VoucherEditorView.vue',
  '../views/books/DetailLedgerView.vue',
  '../views/books/SubjectBalanceView.vue',
  '../views/books/SubjectSummaryView.vue',
  '../views/books/AuxBalanceView.vue',
  '../views/case-fund/PaymentRegisterView.vue',
  '../views/case-fund/RefundRegisterView.vue',
  '../views/case-fund/BankStatementView.vue',
  '../views/base/SubjectManageView.vue',
  '../views/base/OpeningBalanceView.vue',
  '../views/base/AuxItemManageView.vue',
  '../views/system/AuditLogView.vue',
  '../views/system/AccountSetManageView.vue',
  '../views/system/UserManageView.vue',
  '../views/system/RoleManageView.vue',
  '../views/system/RolePermissionView.vue',
  '../views/auth/LoginView.vue',
  '../views/auth/AccountSetSelectView.vue'
]

for (const viewPath of viewPaths) {
  assert(router.includes(`() => import('${viewPath}')`), `${viewPath} should be lazy-loaded by Vue Router`)
  assert(!router.includes(` from '${viewPath}'`), `${viewPath} should not be statically imported by the router`)
}

console.log('Route lazy load test passed')
