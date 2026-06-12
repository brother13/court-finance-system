import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const app = readFileSync(resolve(__dirname, '../src/App.vue'), 'utf8')
const voucherList = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')

assert(app.includes('<router-view v-slot="{ Component }">'), 'main router-view should expose the routed component for selective caching')
assert(app.includes('<KeepAlive include="VoucherListView">'), 'app shell should cache voucher list only')
assert(app.includes('<component :is="Component" />'), 'cached router-view should render the routed component')
assert(voucherList.includes("defineOptions({ name: 'VoucherListView' })"), 'voucher list should declare a stable component name for KeepAlive')

console.log('Voucher list keep-alive test passed')
