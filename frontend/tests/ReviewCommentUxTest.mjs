import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const voucherEditor = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherEditorView.vue'), 'utf8')
const accountSelect = readFileSync(resolve(__dirname, '../src/views/auth/AccountSetSelectView.vue'), 'utf8')
const dashboard = readFileSync(resolve(__dirname, '../src/views/dashboard/DashboardView.vue'), 'utf8')
const app = readFileSync(resolve(__dirname, '../src/App.vue'), 'utf8')
const router = readFileSync(resolve(__dirname, '../src/router/index.ts'), 'utf8')

assert(voucherEditor.includes(':label="archive.archive_name || archive.archive_code"'), 'aux archive select should display only archive name when available')
assert(!voucherEditor.includes('`${archive.archive_code} ${archive.archive_name}`'), 'aux archive select should not display archive code with name')
assert(voucherEditor.includes('ensureSelectedAuxArchive'), 'voucher detail load should add selected aux values to archive options so selects can display names')

assert(!accountSelect.includes('{{ item.set_code }} · {{ item.enabled_year }}'), 'account-set card should not show set code below the description')
assert(accountSelect.includes('返回'), 'account-set select page should provide a return action')
assert(accountSelect.includes('returnToPreviousAccountSet'), 'account-set select page should implement returning without choosing')

assert(!dashboard.includes(`<el-button @click="$router.push('/select-account-set')">切换账套</el-button>`), 'dashboard page header should not show duplicate switch account button')
assert(dashboard.includes("path: '/case-fund/payments'"), 'case fund receipt dashboard entry should open payment register')
assert(dashboard.includes("path: '/case-fund/refunds'"), 'case fund refund dashboard entry should open refund register')

assert(app.includes("query: { switch: '1' }"), 'topbar switch should enter account selection in switch mode')
assert(!app.includes("context.accountSetId = ''"), 'topbar switch should keep current account set until a new one is confirmed')
assert(router.includes("to.query.switch !== '1'"), 'router should allow account selection page while switching from an existing account set')

console.log('Review comment UX test passed')
