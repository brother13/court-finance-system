import { readFileSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const utilPath = resolve(__dirname, '../src/utils/voucherPrintPdf.ts')
const view = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/voucher.ts'), 'utf8')

assert(existsSync(utilPath), 'voucher print PDF utility should exist')
const util = existsSync(utilPath) ? readFileSync(utilPath, 'utf8') : ''

assert(api.includes("apiAction('/voucher/printData'"), 'voucher API should fetch printable voucher data')
assert(view.includes('printSelectedVouchers'), 'voucher list should provide selected voucher print handler')
assert(view.includes('createVoucherPrintPdf'), 'voucher list should generate PDF from printable data')
assert(view.includes('URL.createObjectURL'), 'voucher list should open a generated PDF blob')
assert(view.includes('window.open'), 'voucher list should preview PDF in browser')
assert(view.includes('voucher:print'), 'voucher print button should use voucher print permission')
assert(view.includes('selectedRows.length === 0'), 'voucher print should require selected vouchers')
assert(util.includes('paper_size'), 'PDF utility should use account-set paper size')
assert(util.includes('voucher_print_line_count'), 'PDF utility should use configured line count per page')
assert(util.includes('landscapePaperSizeMm'), 'PDF utility should render voucher pages in landscape orientation')
assert(util.includes('PRINT_FONT_SIZES'), 'PDF utility should centralize larger print font sizes')
assert(util.includes('title: 30'), 'PDF utility should use a larger voucher title font')
assert(util.includes('SUMMARY_COLUMN_RATIO = 0.4'), 'PDF utility should widen the summary column')
assert(util.includes('SUBJECT_COLUMN_RATIO = 0.22'), 'PDF utility should shrink the subject column')
assert(util.includes('SUMMARY_MAX_CHARS = 25'), 'PDF utility should wrap summary text after 25 characters')
assert(util.includes('splitVoucherPages'), 'PDF utility should paginate voucher details')
assert(util.includes('canvas.toDataURL'), 'PDF utility should rasterize each voucher page before PDF assembly')
assert(util.includes('/DCTDecode'), 'PDF utility should embed JPEG page images into a real PDF')

console.log('Voucher print PDF test passed')
