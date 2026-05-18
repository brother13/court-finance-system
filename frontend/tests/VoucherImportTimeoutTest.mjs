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
assert(voucherList.includes('isRequestTimeout'), 'voucher import should detect request timeout separately')
assert(voucherList.includes('导入请求等待超时'), 'timeout message should explain that backend may still be processing')
assert(voucherList.indexOf('导入请求等待超时') < voucherList.indexOf("ElMessage.error('导入失败：' + (err.message || '未知错误'))"), 'timeout branch should be handled before generic import failure')

console.log('Voucher import timeout test passed')
