import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')

assert(component.includes("{ col: '案号', labelCol: '案号', type: 'case_no' }"), 'voucher import should map 案号 column to case_no aux type')
assert(component.includes("{ col: '收据号', labelCol: '收据号', type: 'receipt_no' }"), 'voucher import should map 收据号 column to receipt_no aux type')
assert(component.includes('const voucherSummary = details.find'), 'voucher import should derive voucher header summary from detail summary')
assert(component.includes('summary: voucherSummary'), 'voucher import should send voucher header summary to backend')

console.log('Voucher import aux mapping test passed')
