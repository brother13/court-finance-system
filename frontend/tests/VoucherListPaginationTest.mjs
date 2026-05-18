import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')

assert(component.includes('<el-pagination'), 'voucher list should render pagination controls')
assert(component.includes('v-model:current-page="page"'), 'voucher pagination should bind current page')
assert(component.includes('v-model:page-size="pageSize"'), 'voucher pagination should bind page size')
assert(component.includes(':total="pageTotal"'), 'voucher pagination should use backend total')
assert(component.includes('page: page.value'), 'voucher query should send current page')
assert(component.includes('pagesize: pageSize.value'), 'voucher query should send page size')
assert(component.includes('const query = async () =>'), 'voucher list should have a query helper that resets to first page')
assert(component.includes('@click="query"'), 'top query button should reset to first page before loading')
assert(component.includes('const handlePageSizeChange = async () =>'), 'page size changes should reset to first page and reload')

console.log('Voucher list pagination test passed')
