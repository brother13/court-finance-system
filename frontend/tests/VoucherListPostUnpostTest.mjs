import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/voucher.ts'), 'utf8')

assert(component.includes("statusForRow(row) === 'AUDITED' && context.hasPermission('voucher:post')"), 'audited vouchers should show post action with voucher:post permission')
assert(component.includes("statusForRow(row) === 'POSTED' && context.hasPermission('voucher:unpost')"), 'posted vouchers should show unpost action with voucher:unpost permission')
assert(component.includes('@click="post(row)"'), 'post action should call post(row)')
assert(component.includes('@click="unpost(row)"'), 'unpost action should call unpost(row)')
assert(component.includes('<el-dropdown') && component.includes('@command="handleBatchCommand"'), 'toolbar batch actions should be grouped in a dropdown')
assert(component.includes('<el-dropdown-item') && component.includes('command="post"'), 'batch post action should be inside the dropdown')
assert(component.includes('command="delete"'), 'batch delete action should be inside the dropdown')
assert(component.includes('command="import"'), 'batch import action should be inside the dropdown')
assert(component.includes('await voucherApi.post'), 'post(row) should call voucherApi.post')
assert(component.includes('await voucherApi.unpost'), 'unpost(row) should call voucherApi.unpost')
assert(component.includes('await voucherApi.batchPost'), 'batchPost() should call voucherApi.batchPost')
assert(component.includes("case 'post':") && component.includes('batchPost()'), 'dropdown post command should call batchPost()')
assert(component.includes("case 'delete':") && component.includes('batchDelete()'), 'dropdown delete command should call batchDelete()')
assert(component.includes("case 'import':") && component.includes('openImportDialog()'), 'dropdown import command should call openImportDialog()')
assert(component.includes('canSelectRow = (row: any) => canDeleteRow(row) || canPrintRow(row) || canPostRow(row)'), 'audited postable vouchers should be selectable for batch posting')
assert(api.includes("apiAction('/voucher/post'"), 'voucher API should expose post endpoint')
assert(api.includes("apiAction('/voucher/unpost'"), 'voucher API should expose unpost endpoint')
assert(api.includes("apiAction('/voucher/batchPost'"), 'voucher API should expose batch post endpoint')

console.log('Voucher list post/unpost test passed')
