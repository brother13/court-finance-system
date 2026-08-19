import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const paymentView = readFileSync(resolve(__dirname, '../src/views/case-fund/PaymentRegisterView.vue'), 'utf8')
const refundView = readFileSync(resolve(__dirname, '../src/views/case-fund/RefundRegisterView.vue'), 'utf8')
assert(paymentView.includes('handleGenerateVoucherByQuery'), 'payment register should expose generate-by-query action')
assert(refundView.includes('handleGenerateVoucherByQuery'), 'refund register should expose generate-by-query action')
assert(paymentView.includes('case-fund-summary-actions'), 'payment generate actions should live in the compact summary row')
assert(refundView.includes('case-fund-summary-actions'), 'refund generate actions should live in the compact summary row')
assert(paymentView.includes('...buildGenerateVoucherQueryPayload()'), 'payment register should freeze current filters into the full-query job payload')
assert(refundView.includes('...buildGenerateVoucherQueryPayload()'), 'refund register should freeze current filters into the full-query job payload')
assert(paymentView.includes("generate_scope: 'QUERY'"), 'payment full-query job should declare QUERY scope')
assert(refundView.includes("generate_scope: 'QUERY'"), 'refund full-query job should declare QUERY scope')
assert(paymentView.includes('period: context.period'), 'payment full-query job should include the accounting period')
assert(refundView.includes('period: context.period'), 'refund full-query job should include the accounting period')
assert(paymentView.includes("voucher_status: 'UNGENERATED'"), 'payment query generation should force ungenerated records')
assert(refundView.includes("voucher_status: 'UNGENERATED'"), 'refund query generation should force ungenerated records')
assert(paymentView.includes('amount_min: filters.amount_min'), 'payment query generation should keep the visible minimum amount')
assert(paymentView.includes('amount_max: filters.amount_max'), 'payment query generation should keep the visible maximum amount')
assert(refundView.includes('amount_min: filters.amount_min'), 'refund query generation should keep the visible minimum amount')
assert(refundView.includes('amount_max: filters.amount_max'), 'refund query generation should keep the visible maximum amount')

const paymentQueryStart = paymentView.indexOf("createCaseFundClientRequestId('payment-voucher-query')")
const refundQueryStart = refundView.indexOf("createCaseFundClientRequestId('refund-voucher-query')")
const paymentQueryJobBlock = paymentView.slice(paymentQueryStart, paymentView.indexOf("ElMessage.success('按查询条件生成缴费凭证", paymentQueryStart))
const refundQueryJobBlock = refundView.slice(refundQueryStart, refundView.indexOf("ElMessage.success('按查询条件生成退付凭证", refundQueryStart))
assert(paymentQueryStart >= 0, 'payment query job should create one stable client request id')
assert(refundQueryStart >= 0, 'refund query job should create one stable client request id')
assert(!paymentQueryJobBlock.includes('pagesize'), 'payment query generation should not depend on current page size')
assert(!refundQueryJobBlock.includes('pagesize'), 'refund query generation should not depend on current page size')

console.log('Case fund generate-by-query frontend test passed')
