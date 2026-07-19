import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))

const cases = [
  {
    view: 'PaymentRegisterView.vue',
    id: 'payment_id',
    linked: 'linkedPaymentId',
    route: '/case-fund/payments',
    notice: '正在查看银行对账关联的缴费记录',
  },
  {
    view: 'RefundRegisterView.vue',
    id: 'refund_id',
    linked: 'linkedRefundId',
    route: '/case-fund/refunds',
    notice: '正在查看银行对账关联的出账记录',
  },
  {
    view: 'UnknownPaymentView.vue',
    id: 'unknown_payment_id',
    linked: 'linkedUnknownPaymentId',
    route: '/case-fund/unknown-payments',
    notice: '正在查看银行对账关联的不明款记录',
  },
]

for (const item of cases) {
  const source = readFileSync(resolve(__dirname, `../src/views/case-fund/${item.view}`), 'utf8')
  assert(source.includes("import { useRoute, useRouter } from 'vue-router'"), `${item.view} should read exact route parameters`)
  assert(source.includes(`${item.id}: ${item.linked}.value`), `${item.view} should send the exact linked id to its list API`)
  assert(source.includes(`() => route.query.${item.id}`), `${item.view} should react to cached route changes`)
  assert(source.includes(`route.path !== '${item.route}'`), `${item.view} should only reload while its own route is active`)
  assert(source.includes(`delete nextQuery.${item.id}`), `${item.view} should let the user clear exact drilldown mode`)
  assert(source.includes(item.notice), `${item.view} should explain that the current result came from bank reconciliation`)
}

const types = readFileSync(resolve(__dirname, '../src/types/api.ts'), 'utf8')
assert(types.includes('reconcile_items?: CaseFundBankReconcile[]'), 'bank statement API type should expose attached reconcile rows')

console.log('Case fund bank reconcile drilldown test passed')
