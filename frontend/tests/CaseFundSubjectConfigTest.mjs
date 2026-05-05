import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const api = readFileSync(resolve(__dirname, '../src/api/caseFund.ts'), 'utf8')
const types = readFileSync(resolve(__dirname, '../src/types/api.ts'), 'utf8')
const paymentView = readFileSync(resolve(__dirname, '../src/views/case-fund/PaymentRegisterView.vue'), 'utf8')
const refundView = readFileSync(resolve(__dirname, '../src/views/case-fund/RefundRegisterView.vue'), 'utf8')

assert(api.includes("apiAction('/caseFund/subjectConfigList'"), 'case fund API should expose subject config list')
assert(api.includes("apiAction('/caseFund/subjectConfigSave'"), 'case fund API should expose subject config save')
assert(api.includes('generate_voucher_by_day_flag'), 'case fund API should expose daily voucher generation flag')
assert(types.includes('CaseFundSubjectConfig'), 'API types should include case fund subject config')
assert(types.includes('generate_voucher_by_day_flag?: number'), 'API types should include account-set daily voucher flag')

assert(paymentView.includes('openSubjectConfig'), 'payment register should open subject config dialog')
assert(paymentView.includes("voucherBizType: 'PAYMENT'"), 'payment subject config should use PAYMENT voucher business type')
assert(paymentView.includes('business_type'), 'payment subject config should bind imported payment business type')
assert(paymentView.includes('借方科目') && paymentView.includes('贷方科目'), 'payment subject config should edit debit and credit subjects')
assert(paymentView.includes('是否按天生成凭证'), 'payment subject config should show daily voucher switch')
assert(paymentView.includes('<el-switch'), 'payment subject config should use a switch for daily voucher generation')
assert(paymentView.includes('generateVoucherByDay'), 'payment subject config should bind daily voucher flag')

assert(refundView.includes('openSubjectConfig'), 'refund register should open subject config dialog')
assert(refundView.includes("voucherBizType: 'REFUND'"), 'refund subject config should use REFUND voucher business type')
assert(refundView.includes('out_type'), 'refund subject config should bind refund out type')
assert(refundView.includes('借方科目') && refundView.includes('贷方科目'), 'refund subject config should edit debit and credit subjects')
assert(refundView.includes('是否按天生成凭证'), 'refund subject config should show daily voucher switch')
assert(refundView.includes('<el-switch'), 'refund subject config should use a switch for daily voucher generation')
assert(refundView.includes('generateVoucherByDay'), 'refund subject config should bind daily voucher flag')

console.log('Case fund subject config frontend test passed')
