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
assert(component.includes('整理将按制单日期重排本期间凭证号，同一天缴款凭证在前、退款凭证在后。已记账、已作废、已打印凭证不参与第一版整理。'), 'renumber dialog should warn about date-order and same-day payment/refund sorting')
assert(component.includes('renumberPreviewRows'), 'renumber dialog should render preview rows')
assert(component.includes('await voucherApi.renumberPreview'), 'renumber check should call voucherApi.renumberPreview')
assert(component.includes('await voucherApi.renumber'), 'renumber confirmation should call voucherApi.renumber')
assert(component.includes("case 'renumber':") && component.includes('openRenumberDialog()'), 'dropdown renumber command should open the renumber dialog')
assert(component.includes('await load()'), 'successful renumber should refresh the voucher list')
assert(api.includes("apiAction('/voucher/renumberPreview'"), 'voucher API should expose renumber preview endpoint')
assert(api.includes("apiAction('/voucher/renumber'"), 'voucher API should expose renumber endpoint')

console.log('Voucher renumber dialog test passed')
