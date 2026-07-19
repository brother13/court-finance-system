import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const http = readFileSync(resolve(__dirname, '../src/api/http.ts'), 'utf8')
const voucherApi = readFileSync(resolve(__dirname, '../src/api/voucher.ts'), 'utf8')
const voucherList = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')

assert(http.includes('type ApiActionOptions'), 'apiAction should accept per-request options')
assert(http.includes('timeout: options.timeout'), 'apiAction should forward per-request timeout to axios')
assert(voucherApi.includes("apiAction('/voucher/import', payload, {}, { timeout: 120000 })"), 'voucher import should allow enough time for batch persistence')
assert(voucherList.includes('const VOUCHER_IMPORT_BATCH_SIZE = 200'), 'large voucher imports should be split into bounded batches')
assert(voucherList.includes('offset += VOUCHER_IMPORT_BATCH_SIZE'), 'voucher import should submit every batch sequentially')
assert(voucherList.includes('importVouchers.value.slice(offset, offset + VOUCHER_IMPORT_BATCH_SIZE)'), 'each request should contain only the current batch')
assert(voucherList.includes('importProgress.completed = Math.min(offset + batch.length, total)'), 'confirmed batch progress should be tracked')
assert(voucherList.includes('offsetImportErrorNumber(error, offset)'), 'batch-local error indexes should be converted to file-wide voucher indexes')
assert(voucherList.includes('isRequestTimeout'), 'voucher import should detect request timeout separately')
assert(voucherList.includes('后台可能仍在处理当前批次'), 'timeout message should explain that the current backend batch may still be processing')
assert(voucherList.includes('importVouchers.value = []'), 'an interrupted or partially failed import should prevent blind resubmission')

console.log('Voucher import timeout test passed')
