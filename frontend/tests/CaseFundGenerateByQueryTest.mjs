import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const paymentView = readFileSync(resolve(__dirname, '../src/views/case-fund/PaymentRegisterView.vue'), 'utf8')
const refundView = readFileSync(resolve(__dirname, '../src/views/case-fund/RefundRegisterView.vue'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/caseFund.ts'), 'utf8')

assert(paymentView.includes('handleGenerateVoucherByQuery'), 'payment register should expose generate-by-query action')
assert(refundView.includes('handleGenerateVoucherByQuery'), 'refund register should expose generate-by-query action')
assert(paymentView.includes('case-fund-summary-actions'), 'payment generate actions should live in the compact summary row')
assert(refundView.includes('case-fund-summary-actions'), 'refund generate actions should live in the compact summary row')
assert(paymentView.includes('caseFundApi.paymentGenerateVoucherByQuery(buildGenerateVoucherQueryPayload())'), 'payment register should send current filters for full query generation')
assert(refundView.includes('caseFundApi.refundGenerateVoucherByQuery(buildGenerateVoucherQueryPayload())'), 'refund register should send current filters for full query generation')
assert(paymentView.includes("voucher_status: 'UNGENERATED'"), 'payment query generation should force ungenerated records')
assert(refundView.includes("voucher_status: 'UNGENERATED'"), 'refund query generation should force ungenerated records')
assert(!paymentView.includes('paymentGenerateVoucherByQuery({') || !paymentView.includes('pagesize'), 'payment query generation should not depend on current page size')
assert(!refundView.includes('refundGenerateVoucherByQuery({') || !refundView.includes('pagesize'), 'refund query generation should not depend on current page size')
assert(api.includes("apiAction('/caseFund/paymentGenerateVoucherByQuery'"), 'caseFund API should expose paymentGenerateVoucherByQuery endpoint')
assert(api.includes("apiAction('/caseFund/refundGenerateVoucherByQuery'"), 'caseFund API should expose refundGenerateVoucherByQuery endpoint')

console.log('Case fund generate-by-query frontend test passed')
