import fs from 'node:fs'
import assert from 'assert'

const api = fs.readFileSync('frontend/src/api/caseFund.ts', 'utf8')
const paymentView = fs.readFileSync('frontend/src/views/case-fund/PaymentRegisterView.vue', 'utf8')
const refundView = fs.readFileSync('frontend/src/views/case-fund/RefundRegisterView.vue', 'utf8')
const unknownPaymentView = fs.readFileSync('frontend/src/views/case-fund/UnknownPaymentView.vue', 'utf8')
const distributionView = fs.readFileSync('frontend/src/views/case-fund/DistributionView.vue', 'utf8')
const bankView = fs.readFileSync('frontend/src/views/case-fund/BankStatementView.vue', 'utf8')

assert(api.includes('deletePayments(paymentIds: string[])'), 'case fund API should expose deletePayments')
assert(api.includes("apiAction('/caseFund/paymentDelete'"), 'deletePayments should call paymentDelete')
assert(api.includes('deleteRefunds(refundIds: string[])'), 'case fund API should expose deleteRefunds')
assert(api.includes("apiAction('/caseFund/refundDelete'"), 'deleteRefunds should call refundDelete')
assert(api.includes('deleteUnknownPayments(unknownPaymentIds: string[])'), 'case fund API should expose deleteUnknownPayments')
assert(api.includes("apiAction('/caseFund/unknownPaymentDelete'"), 'deleteUnknownPayments should call unknownPaymentDelete')
assert(api.includes('deleteDistributions(distributionIds: string[])'), 'case fund API should expose deleteDistributions')
assert(api.includes("apiAction('/caseFund/distributionDelete'"), 'deleteDistributions should call distributionDelete')
assert(api.includes('deleteBankStatements(statementIds: string[])'), 'case fund API should expose deleteBankStatements')
assert(api.includes("apiAction('/caseFund/bankStatementDelete'"), 'deleteBankStatements should call bankStatementDelete')

for (const [name, source, apiCall] of [
  ['payment', paymentView, 'caseFundApi.deletePayments'],
  ['refund', refundView, 'caseFundApi.deleteRefunds'],
  ['unknown payment', unknownPaymentView, 'caseFundApi.deleteUnknownPayments'],
  ['distribution', distributionView, 'caseFundApi.deleteDistributions'],
  ['bank statement', bankView, 'caseFundApi.deleteBankStatements']
]) {
  assert(source.includes("v-permission=\"'case_fund:delete'\""), `${name} delete button should require case_fund:delete`)
  assert(source.includes('ElMessageBox.confirm'), `${name} delete should ask for confirmation`)
  assert(source.includes(apiCall), `${name} delete should call API wrapper`)
  if (name === 'bank statement') {
    assert(source.includes('type="danger"'), `${name} delete button should be danger style`)
  } else {
    assert(source.includes('class="danger-dropdown-item"'), `${name} delete dropdown item should use danger style`)
    assert(source.includes('command="delete"'), `${name} delete action should be available from the more-actions dropdown`)
  }
}

assert(paymentView.includes("selectedPaymentRows.value.some((row) => row.voucher_status !== 'UNGENERATED')"), 'payment delete should reject selected generated rows before calling delete API')
assert(refundView.includes("selectedRefundRows.value.some((row) => row.voucher_status !== 'UNGENERATED')"), 'refund delete should reject selected generated rows before calling delete API')
assert(unknownPaymentView.includes("selectedUnknownPaymentRows.value.some((row) => row.voucher_status !== 'UNGENERATED')"), 'unknown payment delete should reject selected generated rows before calling delete API')
assert(distributionView.includes("selectedDistributionRows.value.some((row) => row.voucher_status !== 'UNGENERATED')"), 'distribution delete should reject selected generated rows before calling delete API')
assert(bankView.includes("row.reconcile_status === 'UNMATCHED'"), 'bank statement table should only select unmatched rows')

console.log('Case fund delete actions test passed')
