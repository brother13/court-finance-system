import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import assert from 'node:assert/strict'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherEditorView.vue'), 'utf8')

assert(view.includes('LARGE_VOUCHER_LINE_PAGE_SIZE'), 'voucher editor should define a page size for large voucher detail rendering')
assert(view.includes('renderedVoucherLines'), 'voucher editor should render a paged slice of voucher detail lines')
assert(view.includes('v-for="({ row, index }) in renderedVoucherLines"'), 'voucher editor should not render every voucher detail row at once')
assert(view.includes('visibleLineStart + index + 1'), 'voucher editor should keep global line numbers while rendering a page slice')
assert(view.includes('<el-pagination'), 'voucher editor should expose pagination for vouchers with many detail lines')
assert(view.includes('readonly-cell-text'), 'voucher view mode should render plain text cells instead of disabled input/select controls')
assert(view.includes('subjectDisplayName(row.subjectCode)'), 'voucher view mode should display the selected subject without mounting a subject selector')
assert(!view.includes('v-for="(row, index) in form.details"'), 'voucher editor should avoid full detail-row DOM rendering')

console.log('Voucher editor large detail performance test passed')
