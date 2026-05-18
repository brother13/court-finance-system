import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const refundView = readFileSync(resolve(__dirname, '../src/views/case-fund/RefundRegisterView.vue'), 'utf8')

assert(
  refundView.includes('prop="out_order_no" label="出账单号"'),
  'refund register list should display the out order number from fin_case_fund_refund.out_order_no'
)
assert(
  !refundView.includes('prop="applicant_name" label="申请人"'),
  'refund register list should not display applicant_name as a visible column'
)

console.log('Case fund refund columns test passed')
