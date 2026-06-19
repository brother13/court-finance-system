import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')

assert(component.includes('period_start: string'), 'voucher list top filter should include period_start')
assert(component.includes('period_end: string'), 'voucher list top filter should include period_end')
assert(component.includes('period_start: topFilter.period_start'), 'voucher query should send period_start')
assert(component.includes('period_end: topFilter.period_end'), 'voucher query should send period_end')
assert(component.includes('periodRangeLabel'), 'voucher list header should show the selected period range')
assert(!component.includes('page-header voucher-list-header'), 'voucher list should not render a duplicate page title block')
assert(!component.includes('<h1>凭证中心</h1>'), 'voucher list should rely on the app shell title instead of an inner page title')
assert(!component.includes('<span>年度</span>'), 'voucher list should not show a top-level year filter')
assert(!component.includes('v-model="topFilter.year"'), 'voucher list should not expose year switching in the top filter')
assert(!component.includes('v-model="topFilter.voucher_no_start"'), 'voucher no range should not stay in the top filter')
assert(!component.includes('刷新数据'), 'voucher list should not show a refresh button')
assert(!component.includes('@click="resetFilters"'), 'voucher list should not show a reset filter button')
assert(component.includes('v-model="advancedDraft.voucher_no_start"'), 'voucher no range should move into advanced filters')
assert(component.includes('voucher_no_start: string'), 'advanced filters should include voucher_no_start')
assert(!component.includes('v-model="topFilter.voucher_word"'), 'voucher word query control should be removed')
assert(!component.includes('voucher_word: topFilter.voucher_word'), 'voucher query should not send voucher_word')
assert(component.includes('await voucherApi.audit(row.period, row.voucher_id)'), 'audit should use the row period when period range is active')
assert(component.includes('await voucherApi.unaudit(row.period, row.voucher_id)'), 'unaudit should use the row period when period range is active')
assert(component.includes('rowsByPeriod'), 'batch delete should group selected vouchers by period')

console.log('Voucher period range filter test passed')
