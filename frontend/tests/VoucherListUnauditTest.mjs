import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/voucher.ts'), 'utf8')

assert(component.includes('statusForRow'), 'voucher list should derive workflow status separately from printed marker')
assert(component.includes("canUnauditRow(row)"), 'audited vouchers with unaudit permission should show cancel-audit action')
assert(component.includes("statusForRow(row) === 'AUDITED' && context.hasPermission('voucher:unaudit')"), 'cancel-audit action should use workflow status')
assert(component.includes('@click="unaudit(row)"'), 'cancel-audit action should call unaudit(row)')
assert(component.includes('await voucherApi.unaudit'), 'unaudit(row) should call voucherApi.unaudit')
assert(component.includes("ElMessage.success('已取消审核')"), 'cancel-audit success message should be shown')
assert(component.includes('command="unaudit"'), 'batch cancel-audit action should be inside the dropdown')
assert(component.includes("case 'unaudit':") && component.includes('batchUnaudit()'), 'batch cancel-audit command should call batchUnaudit()')
assert(component.includes('await voucherApi.batchUnaudit'), 'batchUnaudit() should call voucherApi.batchUnaudit')
assert(component.includes("ElMessage.success('凭证已批量取消审核')"), 'batch cancel-audit success message should be shown')
assert(component.includes('canUnauditRow(row) || canPostRow(row)'), 'audited vouchers should be selectable for batch cancel-audit')
assert(api.includes("apiAction('/voucher/batchUnaudit'"), 'voucher API should expose batch cancel-audit endpoint')

console.log('Voucher list unaudit test passed')
