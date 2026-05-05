import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')

assert(component.includes("row.status === 'AUDITED' && context.hasPermission('voucher:unaudit')"), 'audited vouchers with unaudit permission should show cancel-audit action')
assert(component.includes('@click="unaudit(row)"'), 'cancel-audit action should call unaudit(row)')
assert(component.includes('await voucherApi.unaudit'), 'unaudit(row) should call voucherApi.unaudit')
assert(component.includes("ElMessage.success('已取消审核')"), 'cancel-audit success message should be shown')

console.log('Voucher list unaudit test passed')
