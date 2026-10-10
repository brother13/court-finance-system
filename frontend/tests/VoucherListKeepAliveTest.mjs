import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const app = readFileSync(resolve(__dirname, '../src/App.vue'), 'utf8')
const voucherList = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')
const auxBalance = readFileSync(resolve(__dirname, '../src/views/books/AuxBalanceView.vue'), 'utf8')
const caseMovementDetail = readFileSync(resolve(__dirname, '../src/views/books/CaseMovementDetailView.vue'), 'utf8')
const paymentRegister = readFileSync(resolve(__dirname, '../src/views/case-fund/PaymentRegisterView.vue'), 'utf8')
const refundRegister = readFileSync(resolve(__dirname, '../src/views/case-fund/RefundRegisterView.vue'), 'utf8')
const unknownPayment = readFileSync(resolve(__dirname, '../src/views/case-fund/UnknownPaymentView.vue'), 'utf8')
const distribution = readFileSync(resolve(__dirname, '../src/views/case-fund/DistributionView.vue'), 'utf8')
const bankStatement = readFileSync(resolve(__dirname, '../src/views/case-fund/BankStatementView.vue'), 'utf8')

assert(app.includes('<router-view v-slot="{ Component }">'), 'main router-view should expose the routed component for selective caching')
assert(app.includes('<KeepAlive include="VoucherListView,AuxBalanceView,CaseMovementDetailView,PaymentRegisterView,RefundRegisterView,UnknownPaymentView,DistributionView,BankStatementView">'), 'app shell should cache voucher, requested book reports, every case-fund business page and the bank statement page')
assert(app.includes('<component :is="Component" />'), 'cached router-view should render the routed component')
assert(voucherList.includes("defineOptions({ name: 'VoucherListView' })"), 'voucher list should declare a stable component name for KeepAlive')
assert(auxBalance.includes("defineOptions({ name: 'AuxBalanceView' })"), 'aux balance should declare a stable component name for KeepAlive')
assert(caseMovementDetail.includes("defineOptions({ name: 'CaseMovementDetailView' })"), 'case movement detail should declare a stable component name for KeepAlive')
assert(paymentRegister.includes("defineOptions({ name: 'PaymentRegisterView' })"), 'payment register should declare a stable component name for KeepAlive')
assert(refundRegister.includes("defineOptions({ name: 'RefundRegisterView' })"), 'refund register should declare a stable component name for KeepAlive')
assert(unknownPayment.includes("defineOptions({ name: 'UnknownPaymentView' })"), 'unknown payment should declare a stable component name for KeepAlive')
assert(distribution.includes("defineOptions({ name: 'DistributionView' })"), 'distribution should declare a stable component name for KeepAlive')
assert(bankStatement.includes("defineOptions({ name: 'BankStatementView' })"), 'bank statement should declare a stable component name for KeepAlive')

console.log('Voucher, book report and case-fund page keep-alive test passed')
