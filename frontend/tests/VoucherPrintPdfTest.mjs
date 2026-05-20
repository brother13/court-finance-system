import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/voucher.ts'), 'utf8')

assert(api.includes("apiBlobAction('/voucher/printPdf'"), 'voucher API should request backend PDF blob')
assert(view.includes('printSelectedVouchers'), 'voucher list should provide selected voucher print handler')
assert(view.includes('voucherApi.printPdf'), 'voucher list should call backend voucher PDF endpoint')
assert(!view.includes('createVoucherPrintPdf'), 'voucher list should not generate image-based voucher PDF in the browser')
assert(view.includes('URL.createObjectURL'), 'voucher list should open a generated PDF blob')
assert(view.includes('window.open'), 'voucher list should preview PDF in browser')
assert(view.includes('voucher:print'), 'voucher print button should use voucher print permission')
assert(view.includes('selectedRows.length === 0'), 'voucher print should require selected vouchers')
assert(view.includes(':loading="printing"'), 'voucher print button should keep loading while backend PDF is generated')
assert(view.includes('if (printing.value) return'), 'voucher print should prevent duplicate clicks while loading')

console.log('Voucher print PDF test passed')
