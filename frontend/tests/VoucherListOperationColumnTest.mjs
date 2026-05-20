import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')

assert(view.includes('<el-table-column prop="status" label="状态" width="110"'), 'voucher list should keep status column visible')
assert(view.includes('<el-table-column label="操作" width="220" fixed="right"'), 'voucher list operation column should stay compact')
assert(!view.includes('<el-table-column label="操作" width="320"'), 'voucher list operation column should not be too wide')

console.log('Voucher list operation column test passed')
