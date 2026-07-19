import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const caseFundViews = [
  'PaymentRegisterView.vue',
  'RefundRegisterView.vue',
  'UnknownPaymentView.vue',
  'DistributionView.vue',
]

for (const viewName of caseFundViews) {
  const view = readFileSync(resolve(__dirname, `../src/views/case-fund/${viewName}`), 'utf8')
  assert(view.includes('class="voucher-number-link"'), `${viewName} should render the voucher number as a link`)
  assert(view.includes("path: '/vouchers'"), `${viewName} should navigate to voucher center`)
  assert(view.includes('period: row.voucher_period'), `${viewName} should pass the generated voucher period`)
  assert(view.includes('voucher_id: row.voucher_id'), `${viewName} should pass the exact voucher id`)
  assert(view.includes("context.hasPermission('menu:voucher')"), `${viewName} should only expose navigation when voucher center is permitted`)
}

const voucherList = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')
assert(voucherList.includes('const route = useRoute()'), 'voucher center should read route query parameters')
assert(voucherList.includes('voucher_id: routeQueryText(route.query.voucher_id)'), 'voucher center should send exact voucher id to the backend')
assert(voucherList.includes('normalizeVoucherPeriod(route.query.period)'), 'voucher center should scope the query to the linked period')
assert(voucherList.includes('advancedApplied.value = emptyAdvanced()'), 'exact voucher navigation should clear stale advanced filters')
assert(voucherList.includes("path !== '/vouchers'"), 'cached voucher center should only react while its route is active')

const styles = readFileSync(resolve(__dirname, '../src/styles/app.css'), 'utf8')
assert(styles.includes('.voucher-number-link:hover'), 'voucher number link should expose a hover affordance')

console.log('Case fund voucher drilldown test passed')
