import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const views = [
  ['缴费登记', 'PaymentRegisterView.vue'],
  ['出账查询', 'RefundRegisterView.vue'],
  ['不明款', 'UnknownPaymentView.vue']
]

for (const [label, filename] of views) {
  const source = readFileSync(resolve(__dirname, `../src/views/case-fund/${filename}`), 'utf8')
  assert(source.includes('placeholder="最低金额"'), `${label} should expose a minimum amount input`)
  assert(source.includes('placeholder="最高金额"'), `${label} should expose a maximum amount input`)
  assert(source.includes('amount_min: filters.amount_min'), `${label} should send the minimum amount to the list API`)
  assert(source.includes('amount_max: filters.amount_max'), `${label} should send the maximum amount to the list API`)
  assert(source.includes('validateAmountRange'), `${label} should reject an inverted amount range before querying`)
}

console.log('Case fund amount range filter frontend test passed')
