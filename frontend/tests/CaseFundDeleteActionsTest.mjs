import fs from 'node:fs'
import assert from 'assert'

const api = fs.readFileSync('frontend/src/api/caseFund.ts', 'utf8')
const paymentView = fs.readFileSync('frontend/src/views/case-fund/PaymentRegisterView.vue', 'utf8')
const refundView = fs.readFileSync('frontend/src/views/case-fund/RefundRegisterView.vue', 'utf8')
const bankView = fs.readFileSync('frontend/src/views/case-fund/BankStatementView.vue', 'utf8')

assert(api.includes('deletePayments(paymentIds: string[])'), 'case fund API should expose deletePayments')
assert(api.includes("apiAction('/caseFund/paymentDelete'"), 'deletePayments should call paymentDelete')
assert(api.includes('deleteRefunds(refundIds: string[])'), 'case fund API should expose deleteRefunds')
assert(api.includes("apiAction('/caseFund/refundDelete'"), 'deleteRefunds should call refundDelete')
assert(api.includes('deleteBankStatements(statementIds: string[])'), 'case fund API should expose deleteBankStatements')
assert(api.includes("apiAction('/caseFund/bankStatementDelete'"), 'deleteBankStatements should call bankStatementDelete')

for (const [name, source, apiCall] of [
  ['payment', paymentView, 'caseFundApi.deletePayments'],
  ['refund', refundView, 'caseFundApi.deleteRefunds'],
  ['bank statement', bankView, 'caseFundApi.deleteBankStatements']
]) {
  assert(source.includes("v-permission=\"'case_fund:delete'\""), `${name} delete button should require case_fund:delete`)
  assert(source.includes('ElMessageBox.confirm'), `${name} delete should ask for confirmation`)
  assert(source.includes(apiCall), `${name} delete should call API wrapper`)
  assert(source.includes('type="danger"'), `${name} delete button should be danger style`)
}

assert(paymentView.includes("row.voucher_status === 'UNGENERATED'"), 'payment table should only select ungenerated rows')
assert(refundView.includes("row.voucher_status === 'UNGENERATED'"), 'refund table should only select ungenerated rows')
assert(bankView.includes("row.reconcile_status === 'UNMATCHED'"), 'bank statement table should only select unmatched rows')

console.log('Case fund delete actions test passed')
