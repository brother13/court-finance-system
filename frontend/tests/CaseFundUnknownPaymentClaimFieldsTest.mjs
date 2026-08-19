import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/case-fund/UnknownPaymentView.vue'), 'utf8')
const types = readFileSync(resolve(__dirname, '../src/types/api.ts'), 'utf8')

assert(
  view.includes('prop="claim_user_name" label="认领人姓名"'),
  'unknown-payment list should display claim user name'
)
assert(
  view.includes('prop="claim_date" label="认领日期"'),
  'unknown-payment list should display claim date'
)
assert(types.includes('claim_user_name?: string'), 'unknown-payment API type should include claim user name')
assert(types.includes('claim_date?: string'), 'unknown-payment API type should include claim date')

console.log('Case fund unknown-payment claim fields test passed')
