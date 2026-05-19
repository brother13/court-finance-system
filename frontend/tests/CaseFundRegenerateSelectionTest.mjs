import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const paymentView = readFileSync(resolve(__dirname, '../src/views/case-fund/PaymentRegisterView.vue'), 'utf8')
const refundView = readFileSync(resolve(__dirname, '../src/views/case-fund/RefundRegisterView.vue'), 'utf8')

for (const [name, source] of [
  ['payment', paymentView],
  ['refund', refundView]
]) {
  assert(source.includes("row.voucher_status === 'UNGENERATED' || row.voucher_status === 'GENERATED'"), `${name} rows should allow selecting generated rows for voucher regeneration`)
}

console.log('Case fund regenerate selection frontend test passed')
