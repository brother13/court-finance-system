import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')
const css = readFileSync(resolve(__dirname, '../src/styles/app.css'), 'utf8')
const queryActionsIndex = view.indexOf('<div class="voucher-query-actions">')
const panelIndex = view.indexOf('<div class="panel voucher-list-panel">')

assert(view.includes('<el-table-column prop="status" label="状态" width="110"'), 'voucher list should keep status column visible')
assert(view.includes('<el-table-column prop="voucher_no" label="凭证号" width="92" align="left"'), 'voucher no column should be compact and left aligned')
assert(view.includes('<el-table-column prop="voucher_date" label="制单日期" width="112" align="left"'), 'voucher date column should be compact and left aligned')
assert(view.includes('<el-table-column prop="summary" label="摘要" min-width="420" align="left"'), 'voucher summary column should be widened for case numbers and names')
assert(view.includes('<el-table-column prop="debit_amount" label="借方金额" width="132" align="right" header-align="right"'), 'voucher debit amount should be strictly right aligned')
assert(view.includes('<el-table-column prop="credit_amount" label="贷方金额" width="132" align="right" header-align="right"'), 'voucher credit amount should be strictly right aligned')
assert(view.includes('voucherAmountClass(row.debit_amount || row.debitAmount)'), 'voucher debit amount should use neutral finance amount styling')
assert(view.includes('voucherAmountClass(row.credit_amount || row.creditAmount)'), 'voucher credit amount should use neutral finance amount styling')
assert(view.includes("return amount !== 0 ? amount.toFixed(2) : '-'"), 'voucher list amounts should render plain numbers without a currency prefix')
assert(!view.includes("'¥ ' + amount.toFixed(2)"), 'voucher list amounts should not include a yuan prefix')
assert(!view.includes("? 'amount-debit' : 'muted'"), 'voucher debit positive amount should not render blue')
assert(!view.includes("? 'amount-credit' : 'muted'"), 'voucher credit positive amount should not render red')
assert(css.includes('.voucher-date-text,\n.voucher-summary-text,\n.voucher-amount-text {\n  color: #333333;'), 'voucher date, summary and amount text should use deep gray')
assert(css.includes('.voucher-summary-text {\n  display: inline-block;\n  padding-left: 10px;'), 'voucher summary text should have extra left padding')
assert(view.includes('<el-table-column prop="prepared_by_name" label="制单人" width="76"'), 'voucher prepared-by column should stay narrow')
assert(view.includes('<el-table-column prop="audit_by_name" label="审核人" width="76"'), 'voucher audit-by column should stay narrow')
assert(view.includes('<el-table-column prop="posted_by_name" label="记账人" width="76"'), 'voucher posted-by column should stay narrow')
assert(view.match(/show-overflow-tooltip/g)?.length >= 3, 'narrow voucher user columns should keep overflow tooltips')
assert(view.includes('<el-table-column label="操作" width="220" fixed="right"'), 'voucher list operation column should stay compact')
assert(!view.includes('<el-table-column label="操作" width="320"'), 'voucher list operation column should not be too wide')
assert(!view.includes('class="page-actions"'), 'voucher page header should not keep a separate top action row')
assert(queryActionsIndex >= 0 && panelIndex > queryActionsIndex, 'voucher toolbar actions should sit inside the query section above the table')
assert(view.indexOf('打印凭证') > queryActionsIndex && view.indexOf('打印凭证') < panelIndex, 'print voucher action should move into the query toolbar')
assert(view.indexOf('批量操作') > queryActionsIndex && view.indexOf('批量操作') < panelIndex, 'batch action should move into the query toolbar')
assert(view.indexOf('新增凭证') > queryActionsIndex && view.indexOf('新增凭证') < panelIndex, 'new voucher action should move into the query toolbar')
assert(view.includes('height="calc(100vh - 180px)"'), 'voucher table should use the extra space freed by the compact toolbar and removed title block')

console.log('Voucher list operation column test passed')
