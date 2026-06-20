import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const styles = readFileSync(resolve(__dirname, '../src/styles/app.css'), 'utf8')

const globalRequirements = [
  '.balance-report-panel,',
  '.subject-summary-panel,',
  '.ledger-report-panel {',
  '.balance-report-toolbar,',
  '.summary-report-toolbar,',
  '.ledger-report-toolbar {',
  '.balance-query-form .el-form-item',
  '.summary-query-form .el-form-item',
  '.detail-ledger-body {',
  'grid-template-columns: 248px minmax(0, 1fr)',
  '.ledger-subject-tree {',
  '.pagination-bar {',
  'font-variant-numeric: tabular-nums'
]

for (const requirement of globalRequirements) {
  assert(styles.includes(requirement), `global app styles should define ${requirement}`)
}

const viewFiles = [
  'SubjectBalanceView.vue',
  'SubjectSummaryView.vue',
  'DetailLedgerView.vue'
]

const forbiddenScopedRules = [
  '.balance-report-panel {',
  '.subject-summary-panel {',
  '.balance-report-toolbar {',
  '.summary-report-toolbar {',
  '.balance-toolbar-left {',
  '.summary-toolbar-left {',
  '.detail-ledger-body {',
  '.ledger-subject-panel {',
  '.ledger-subject-tree {',
  '.pagination-bar {',
  '.filter-form-row.two-columns {',
  '.period-range {',
  '.detail-period-range {'
]

for (const file of viewFiles) {
  const view = readFileSync(resolve(__dirname, `../src/views/books/${file}`), 'utf8')
  const scopedStyle = view.split('<style scoped>')[1]?.split('</style>')[0] || ''

  for (const rule of forbiddenScopedRules) {
    assert(!scopedStyle.includes(rule), `${file} should not duplicate report shell rule ${rule}`)
  }
}

const detailLedger = readFileSync(resolve(__dirname, '../src/views/books/DetailLedgerView.vue'), 'utf8')
assert(detailLedger.includes('ledger-report-panel'), 'detail ledger should use the shared report panel class')
assert(detailLedger.includes('ledger-report-toolbar'), 'detail ledger should use the shared report toolbar class')

console.log('Book report style consolidation test passed')
