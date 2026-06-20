import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'node:assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')
const appCss = readFileSync(resolve(__dirname, '../src/styles/app.css'), 'utf8')
const appShell = readFileSync(resolve(__dirname, '../src/App.vue'), 'utf8')

assert(appCss.includes('--brand-blue: #1E5AA8;'), 'primary brand blue should use the restrained government finance blue')
assert(appCss.includes('--bg-main: #F5F7FA;'), 'page background should use a neutral government system background')
assert(appCss.includes('--border-light: #D8DEE8;'), 'global borders should use the stronger neutral finance border')
assert(appCss.includes('--finance-radius: 6px;'), 'global radius token should be constrained for a professional finance system')

assert(!appShell.includes('class="dot"'), 'topbar account-set information should not use a green online-status dot')
assert(appCss.includes('.page-tab.active::after'), 'active tabs should be marked with a bottom blue line')
assert(appCss.includes('.sidebar .el-menu .el-menu-item.is-active::before'), 'active sidebar menu should use a slim blue indicator')

assert(component.includes('voucher-toolbar-primary'), 'voucher list should separate primary actions from query actions')
assert(!component.includes('<el-option label="草稿" value="DRAFT" />'), 'voucher status filter should not expose draft vouchers as a daily query option')
assert(!component.includes('<el-option label="已作废" value="VOIDED" />'), 'voucher status filter should not expose voided vouchers as a daily query option')
assert(component.includes('<el-option label="未审核" value="SUBMITTED" />'), 'voucher status filter should keep unreviewed vouchers')
assert(component.includes('<el-option label="已审核" value="AUDITED" />'), 'voucher status filter should keep audited vouchers')
assert(component.includes('<el-option label="已记账" value="POSTED" />'), 'voucher status filter should keep posted vouchers')
assert(component.includes('<el-option label="已打印" value="PRINTED" />'), 'voucher status filter should keep printed vouchers')
assert(appCss.includes('.voucher-query-toolbar {\n  display: flex;\n  align-items: stretch;\n  flex-direction: column;'), 'voucher query toolbar should stack filters and primary actions into separate rows')
assert(appCss.includes('grid-template-columns: 112px 112px 236px 112px minmax(220px, 1fr) auto;'), 'voucher query fields should use stable finance-form widths instead of squeezing with auto-fit')
assert(appCss.includes('.voucher-toolbar-primary {\n  display: flex;\n  align-items: center;\n  justify-content: flex-end;'), 'voucher primary actions should sit in their own right-aligned row')
assert(component.includes('voucher-workbench-metrics'), 'voucher list should expose compact business metrics above the table')
assert(component.includes('voucher-total-bar'), 'voucher list should expose a fixed total bar with debit, credit and balance difference')
assert(component.includes('pageDebitTotal'), 'voucher list should compute debit total from current page rows')
assert(component.includes('pageCreditTotal'), 'voucher list should compute credit total from current page rows')
assert(component.includes('balanceDiffText'), 'voucher list should compute the debit-credit difference text')

assert(appCss.includes('font-variant-numeric: tabular-nums;'), 'amounts should use tabular numeric rendering')
assert(appCss.includes('.voucher-operation-button'), 'voucher operation links should be visually restrained by default')

console.log('Voucher list government style test passed')
