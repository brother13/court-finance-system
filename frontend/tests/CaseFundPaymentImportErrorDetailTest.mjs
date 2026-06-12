import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const http = readFileSync(resolve(__dirname, '../src/api/http.ts'), 'utf8')
const paymentView = readFileSync(resolve(__dirname, '../src/views/case-fund/PaymentRegisterView.vue'), 'utf8')
const refundView = readFileSync(resolve(__dirname, '../src/views/case-fund/RefundRegisterView.vue'), 'utf8')

assert(http.includes('(error as any).data = result.data'), 'API error should keep backend detail data')
assert(paymentView.includes('showPaymentImportError'), 'payment import should handle validation detail errors')
assert(paymentView.includes('校验明细'), 'payment import should show a validation detail dialog')
assert(paymentView.includes('Array.isArray(error?.data)'), 'payment import should read backend error detail array')
assert(refundView.includes('showRefundImportError'), 'refund import should handle validation detail errors')
assert(refundView.includes('校验明细'), 'refund import should show a validation detail dialog')
assert(refundView.includes('Array.isArray(error?.data)'), 'refund import should read backend error detail array')

console.log('Case fund payment import error detail test passed')
