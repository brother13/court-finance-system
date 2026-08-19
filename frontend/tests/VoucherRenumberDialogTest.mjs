import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/voucher.ts'), 'utf8')

assert(component.includes("context.hasPermission('voucher:renumber')"), 'voucher list should check voucher:renumber permission')
assert(component.includes('command="renumber"'), 'batch dropdown should include renumber command')
assert(component.includes('凭证号整理'), 'voucher renumber action/dialog should use visible label 凭证号整理')
assert(component.includes('已打印凭证保留原号并锁定，只整理未打印凭证'), 'renumber dialog should explain that printed voucher numbers stay locked')
assert(component.includes("row.locked"), 'renumber preview should mark printed vouchers as locked')
assert(component.includes("renumberPreview?.locked_count"), 'renumber preview should show the locked voucher count')
assert(component.includes('renumberPreviewRows'), 'renumber dialog should render preview rows')
assert(component.includes('await voucherApi.renumberPreview'), 'renumber check should call voucherApi.renumberPreview')
assert(component.includes('await voucherApi.renumber'), 'renumber confirmation should call voucherApi.renumber')
assert(component.includes("case 'renumber':") && component.includes('openRenumberDialog()'), 'dropdown renumber command should open the renumber dialog')
assert(component.includes('await load()'), 'successful renumber should refresh the voucher list')
assert(api.includes("apiAction('/voucher/renumberPreview'"), 'voucher API should expose renumber preview endpoint')
assert(api.includes("apiAction('/voucher/renumber'"), 'voucher API should expose renumber endpoint')
assert(api.includes("apiAction('/voucher/renumber', payload, {}, { timeout: 60000 })"), 'voucher renumber should allow up to 60 seconds')

console.log('Voucher renumber dialog test passed')
