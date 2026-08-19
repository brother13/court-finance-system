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
assert(component.indexOf('批量审核') > -1 && component.indexOf('批量审核') < component.indexOf('批量记账'), 'batch audit action should appear above batch post action')
assert(component.indexOf('批量记账') > -1 && component.indexOf('批量记账') < component.indexOf('批量反记账'), 'batch unpost action should appear after batch post action')
assert(component.includes('<el-dropdown-item') && component.includes('command="audit"'), 'batch audit action should be inside the dropdown')
assert(component.includes('<el-dropdown-item') && component.includes('command="post"'), 'batch post action should be inside the dropdown')
assert(component.includes('<el-dropdown-item') && component.includes('command="unpost"'), 'batch unpost action should be inside the dropdown')
assert(component.includes('command="delete"'), 'batch delete action should be inside the dropdown')
assert(component.includes('command="import"'), 'batch import action should be inside the dropdown')
assert(component.includes('await voucherApi.post'), 'post(row) should call voucherApi.post')
assert(component.includes('await voucherApi.unpost'), 'unpost(row) should call voucherApi.unpost')
assert(component.includes('await voucherApi.batchAudit'), 'batchAudit() should call voucherApi.batchAudit')
assert(component.includes("case 'audit':") && component.includes('batchAudit()'), 'dropdown audit command should call batchAudit()')
assert(component.includes('await voucherApi.batchPost'), 'batchPost() should call voucherApi.batchPost')
assert(component.includes("case 'post':") && component.includes('batchPost()'), 'dropdown post command should call batchPost()')
assert(component.includes('await voucherApi.batchUnpost'), 'batchUnpost() should call voucherApi.batchUnpost')
assert(component.includes("case 'unpost':") && component.includes('batchUnpost()'), 'dropdown unpost command should call batchUnpost()')
assert(component.includes("case 'delete':") && component.includes('batchDelete()'), 'dropdown delete command should call batchDelete()')
assert(component.includes("case 'import':") && component.includes('openImportDialog()'), 'dropdown import command should call openImportDialog()')
assert(component.includes('canSelectRow = (row: any) => canDeleteRow(row) || canPrintRow(row) || canAuditRow(row) || canUnauditRow(row) || canPostRow(row) || canUnpostRow(row)'), 'submitted, audited, and posted vouchers should be selectable for batch workflow actions')
assert(api.includes("apiAction('/voucher/post'"), 'voucher API should expose post endpoint')
assert(api.includes("apiAction('/voucher/unpost'"), 'voucher API should expose unpost endpoint')
assert(api.includes("apiAction('/voucher/batchAudit'"), 'voucher API should expose batch audit endpoint')
assert(api.includes("apiAction('/voucher/batchPost'"), 'voucher API should expose batch post endpoint')
assert(api.includes("apiAction('/voucher/batchUnpost'"), 'voucher API should expose batch unpost endpoint')

console.log('Voucher list post/unpost test passed')
