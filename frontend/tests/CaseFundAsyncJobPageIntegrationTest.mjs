import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const payment = readFileSync(resolve(__dirname, '../src/views/case-fund/PaymentRegisterView.vue'), 'utf8')
const refund = readFileSync(resolve(__dirname, '../src/views/case-fund/RefundRegisterView.vue'), 'utf8')
const bankStatement = readFileSync(resolve(__dirname, '../src/views/case-fund/BankStatementView.vue'), 'utf8')

for (const [name, source] of [['payment', payment], ['refund', refund], ['bank statement', bankStatement]]) {
  assert(source.includes("import CaseFundJobProgressDialog from '../../components/case-fund/CaseFundJobProgressDialog.vue'"), `${name} view should mount the shared job progress dialog`)
  assert(source.includes('useCaseFundJob({'), `${name} view should use the shared persisted job poller`)
  assert(source.includes('localStorage.setItem'), `${name} view should persist an unfinished job id`)
  assert(source.includes('context.accountSetId'), `${name} job storage should be isolated by account set`)
  assert(source.includes('context.userId'), `${name} job storage should be isolated by user`)
  assert(source.includes('context.year'), `${name} job storage should be isolated by fiscal year`)
  assert(source.includes('resumeJob:'), `${name} view should restore a persisted job id`)
  assert(source.includes('trackJob:'), `${name} view should track a job recovered from a pending request`)
  assert(source.includes("job_type: pending.job_type"), `${name} pending recovery should filter by job type`)
  assert(source.includes('item.client_request_id === pending.client_request_id'), `${name} pending recovery should match the request id`)
  assert(source.includes('item.job_type === pending.job_type'), `${name} pending recovery should match the job type`)
  assert(source.includes('RecoveryUnconfirmed.value = true'), `${name} view should retain an unresolved submission warning`)
  assert(source.includes('只有精确任务查询成功且确认不存在时才解除锁定'), `${name} view should only unlock after an exact successful lookup confirms no job exists`)
  assert(source.includes('JobUnresolved.value'), `${name} unresolved submission should keep risky actions disabled`)
  assert(source.includes("finishedJob.status === 'PARTIAL_FAILED'"), `${name} view should report partial failure explicitly`)
  assert(source.includes('jobErrorList'), `${name} view should expose row-level job errors`)
  assert(!source.includes('stopPolling('), `${name} dialog close should not cancel background polling`)
}

assert(payment.includes("const PAYMENT_IMPORT_JOB_TYPE = 'CASE_FUND_PAYMENT_IMPORT'"), 'payment import should use the payment import job type')
assert(payment.includes("const PAYMENT_VOUCHER_JOB_TYPE = 'CASE_FUND_PAYMENT_VOUCHER'"), 'payment voucher generation should use the payment voucher job type')
assert(payment.includes('`court-finance-case-fund-job:${context.accountSetId}:${context.userId}:${context.year}:payments`'), 'payment job storage key should include account set, user and fiscal year')
assert(payment.includes('clearPendingPaymentSubmission()\n      if (showMessage)'), 'an exact successful empty payment job lookup should release the pending lock')
assert(payment.includes('已确认后台不存在上次待提交任务，操作锁定已解除，请重新发起操作'), 'payment recovery should tell the user it is safe to retry after confirmed absence')
assert(payment.includes('filename: file.name'), 'payment import job should include the source filename')
assert(payment.includes('content_base64: contentBase64'), 'payment import job should include uploaded file content')
assert(payment.includes('file.size > 10 * 1024 * 1024'), 'payment import should reject oversized files before Base64 allocation')
assert(!payment.includes('caseFundApi.importPayments('), 'payment import should no longer wait for the synchronous import endpoint')
assert(payment.includes("generate_scope: 'SELECTED'"), 'selected payment generation should declare SELECTED scope')
assert(payment.includes('payment_ids: [...selectedPaymentIds.value]'), 'selected payment generation should freeze selected payment ids')
assert(payment.includes("generate_scope: 'QUERY'"), 'query payment generation should declare QUERY scope')
assert(payment.includes('...buildGenerateVoucherQueryPayload()'), 'query payment generation should freeze existing filters')

assert(refund.includes("const REFUND_VOUCHER_JOB_TYPE = 'CASE_FUND_REFUND_VOUCHER'"), 'refund generation should use the refund voucher job type')
assert(refund.includes('`court-finance-case-fund-job:${context.accountSetId}:${context.userId}:${context.year}:refunds`'), 'refund job storage key should include account set, user and fiscal year')
assert(refund.includes('clearPendingRefundSubmission()\n      if (showMessage)'), 'an exact successful empty refund job lookup should release the pending lock')
assert(refund.includes('已确认后台不存在上次待提交的退付凭证任务，操作锁定已解除，请重新发起操作'), 'refund recovery should tell the user it is safe to retry after confirmed absence')
assert(refund.includes("generate_scope: 'SELECTED'"), 'selected refund generation should declare SELECTED scope')
assert(refund.includes('refund_ids: [...selectedRefundIds.value]'), 'selected refund generation should freeze selected refund ids')
assert(refund.includes("generate_scope: 'QUERY'"), 'query refund generation should declare QUERY scope')
assert(refund.includes('...buildGenerateVoucherQueryPayload()'), 'query refund generation should freeze existing filters')
assert(refund.includes('caseFundApi.importRefunds(file.name, contentBase64)'), 'refund import should remain on its existing synchronous flow')

assert(bankStatement.includes("const BANK_RECONCILE_JOB_TYPE = 'CASE_FUND_BANK_RECONCILE'"), 'bank statement auto reconcile should use its background job type')
assert(bankStatement.includes('`court-finance-case-fund-job:${context.accountSetId}:${context.userId}:${context.year}:bank-statements`'), 'bank reconcile job storage key should include account set, user and fiscal year')
assert(bankStatement.includes('clearPendingReconcileSubmission()\n      if (showMessage)'), 'an exact successful empty bank reconcile lookup should release the pending lock')
assert(bankStatement.includes('已确认后台不存在上次待提交的自动对账任务，操作锁定已解除，请重新发起操作'), 'bank reconcile recovery should tell the user it is safe to retry after confirmed absence')
assert(bankStatement.includes('caseFundApi.importBankStatements(filters.bank_code, file.name, statements)'), 'bank statement import should remain on its existing synchronous flow')
assert(!bankStatement.includes('caseFundApi.runBankReconcile('), 'bank statement auto reconcile should no longer use the synchronous API')

assert(payment.match(/period: context\.period/g)?.length >= 3, 'all payment background job payloads should include the accounting period')
assert(refund.match(/period: context\.period/g)?.length >= 2, 'all refund voucher job payloads should include the accounting period')
assert(bankStatement.match(/period: context\.period/g)?.length >= 1, 'bank reconcile background job payload should include the accounting period')

const assertPendingStoredBeforeCreate = (source, requestPrefix, persistCall, createCall) => {
  const requestIndex = source.indexOf(`createCaseFundClientRequestId('${requestPrefix}')`)
  const persistIndex = source.indexOf(persistCall, requestIndex)
  const createIndex = source.indexOf(createCall, requestIndex)
  assert(requestIndex >= 0, `${requestPrefix} should create a stable client request id`)
  assert(persistIndex > requestIndex, `${requestPrefix} should persist the pending request`)
  assert(createIndex > persistIndex, `${requestPrefix} should persist pending state before calling jobCreate`)
}

assertPendingStoredBeforeCreate(payment, 'payment-import', 'persistPendingPaymentSubmission({', 'await createPaymentJob({')
assertPendingStoredBeforeCreate(payment, 'payment-voucher-selected', 'persistPendingPaymentSubmission({', 'await createPaymentJob({')
assertPendingStoredBeforeCreate(payment, 'payment-voucher-query', 'persistPendingPaymentSubmission({', 'await createPaymentJob({')
assertPendingStoredBeforeCreate(refund, 'refund-voucher-selected', 'persistPendingRefundSubmission({', 'await createRefundJob({')
assertPendingStoredBeforeCreate(refund, 'refund-voucher-query', 'persistPendingRefundSubmission({', 'await createRefundJob({')
assertPendingStoredBeforeCreate(bankStatement, 'bank-reconcile', 'persistPendingReconcileSubmission({', 'await createReconcileJob({')

console.log('Case fund async job page integration test passed')
