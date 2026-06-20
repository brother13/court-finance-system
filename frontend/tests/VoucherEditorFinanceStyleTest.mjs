import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherEditorView.vue'), 'utf8')
const styles = readFileSync(resolve(__dirname, '../src/styles/app.css'), 'utf8')

assert(view.includes("type=\"primary\" :icon=\"DocumentChecked\""), 'voucher save buttons should use the primary blue action style')
assert(!view.includes('type="success" :icon="DocumentChecked"'), 'voucher save buttons should not use green success styling')
assert(view.includes('class="finance-dialog"'), 'voucher auxiliary dialog should use the shared finance dialog style')
assert(view.includes('context.accountSetName'), 'voucher page header should show account set context')
assert(view.includes('statusText(statusForForm)'), 'voucher page header should show voucher status context')
assert(!view.includes('calc(100vh'), 'voucher editor should not force viewport-height scrolling like list pages')
assert(!view.includes('height="calc('), 'voucher editor should not set a fixed table height')
assert(styles.includes('.voucher-u8-panel {\n  position: relative;\n  padding: 22px;\n  overflow: visible;'), 'voucher paper panel should not create an inner vertical scroll container')
assert(styles.includes('.voucher-entry-wrap {\n  overflow-x: auto;'), 'voucher entry table should only provide horizontal overflow for dense voucher columns')

const styleRequirements = [
  '.voucher-u8-panel {',
  'border-color: #C7D0DC',
  '.voucher-head-grid {',
  'background: #F8FAFD',
  '.voucher-entry-table th {',
  'background: #F2F5F9',
  '.entry-action .el-button.is-link.el-button--danger:hover',
  '.entry-money-total {',
  'font-family: ui-monospace, Menlo, Consolas, monospace',
  '.balance-indicator {',
  'border-left: 3px solid var(--brand-success)',
  '.balance-indicator.unbalanced {',
  'border-left-color: var(--brand-danger)'
]

for (const requirement of styleRequirements) {
  assert(styles.includes(requirement), `voucher editor styles should define ${requirement}`)
}

console.log('Voucher editor finance style test passed')
