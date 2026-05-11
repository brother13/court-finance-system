import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/books/DetailLedgerView.vue'), 'utf8')
const bookModel = readFileSync(resolve(__dirname, '../../backend/app/finance/model/Book.php'), 'utf8')

assert(bookModel.includes('v.voucher_id'), 'detail ledger API should return voucher_id for voucher drilldown')
assert(view.includes('useRoute') && view.includes('useRouter'), 'detail ledger should use route and router')
assert(view.includes('route.query.subject_code'), 'detail ledger should initialize subject from route query')
assert(view.includes('route.query.period'), 'detail ledger should initialize period from route query')
assert(view.includes('periodMonthRange'), 'detail ledger date range should default to the selected accounting period')
assert(!view.includes('now.getMonth() + 1'), 'detail ledger date range should not use the current natural month')
assert(view.includes('@row-dblclick="openVoucherDetail"'), 'detail ledger row double click should open voucher detail')
assert(view.includes("router.push({ path: `/vouchers/detail/${row.period}/${row.voucher_id}`"), 'detail ledger should route to voucher detail with period and voucher id')
assert(view.includes('selectedPeriod'), 'detail ledger should query by selected period instead of only context period')
assert(view.includes('明细账记录'), 'detail ledger header should keep a clean title')
assert(!view.includes('明细账记录 · {{ selectedPeriod }}'), 'detail ledger header should not repeat accounting period')
assert(view.includes('formatAuxDesc(row.aux_desc)'), 'detail ledger should format auxiliary text before display')
assert(view.includes("part.slice(index + 1)"), 'detail ledger auxiliary display should strip technical type prefixes')
assert(!view.includes('<span v-if="row.aux_desc">{{ row.aux_desc }}</span>'), 'detail ledger should not render raw auxiliary descriptions')

console.log('Book detail ledger drilldown test passed')
