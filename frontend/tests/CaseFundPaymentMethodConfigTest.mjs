import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const paymentView = readFileSync(resolve(__dirname, '../src/views/case-fund/PaymentRegisterView.vue'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/caseFund.ts'), 'utf8')
const types = readFileSync(resolve(__dirname, '../src/types/api.ts'), 'utf8')

assert(paymentView.includes('是否按照收费方式生成凭证'), 'payment config should expose payment-method voucher switch')
assert(paymentView.includes('generatePaymentVoucherByMethod'), 'payment config should bind payment-method voucher flag')
assert(paymentView.includes('paymentMethodConfigRows'), 'payment config should keep per-method debit subject rows')
assert(paymentView.includes('POS 收款'), 'payment config should show POS collection option')
assert(paymentView.includes('银行转账'), 'payment config should show transfer option')
assert(paymentView.includes('不明款认领'), 'payment config should show unknown recognition option')
assert(paymentView.includes('filters.payment_method'), 'payment list should have a payment method filter')
assert(paymentView.includes('payment_method: filters.payment_method'), 'payment list query should send payment method filter')
assert(paymentView.includes('placeholder="收费方式"'), 'payment method filter should be a visible dropdown')
assert(paymentView.includes('paymentMethodLabel'), 'payment list should display normalized payment-method labels')
assert(paymentView.includes('<el-tag :type="paymentMethodTagType(row.payment_method)"'), 'payment list should show payment method as a visible tag')
assert(paymentView.includes('未识别'), 'payment list should mark unsupported payment method values as unrecognized')

assert(api.includes('generate_payment_voucher_by_method_flag'), 'case fund API should pass payment-method voucher flag')
assert(api.includes('payment_method_items'), 'case fund API should pass per-method subject configs')
assert(types.includes('payment_method_items?: CaseFundSubjectConfig[]'), 'subject config result should include payment method configs')
assert(types.includes('generate_payment_voucher_by_method_flag?: number'), 'subject config result should include payment-method voucher flag')

console.log('Case fund payment method config frontend test passed')
