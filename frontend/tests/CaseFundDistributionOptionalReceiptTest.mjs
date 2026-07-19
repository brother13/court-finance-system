import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = fileURLToPath(new URL('.', import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/case-fund/DistributionView.vue'), 'utf8')

assert(
  view.includes('单据号（如有）'),
  'distribution search should explain that document number may be absent',
)
assert(
  view.includes("row.receipt_no || '-'"),
  'distribution table should render an explicit placeholder for rows without document numbers',
)
assert(
  view.includes('仅当所选科目关联了单据号辅助核算时，导入和制证才要求单据号'),
  'distribution subject configuration should explain the conditional document-number rule',
)

console.log('Case fund distribution optional receipt test passed')
