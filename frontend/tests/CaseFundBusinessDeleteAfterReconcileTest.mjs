import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import assert from 'node:assert/strict'

const __dirname = dirname(fileURLToPath(import.meta.url))
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor
const cases = [
  ['UnknownPaymentView.vue', 'UnknownPayments', 'UnknownPayment', '不明款记录'],
  ['DistributionView.vue', 'Distributions', 'Distribution', '案款分配记录'],
  ['RefundRegisterView.vue', 'Refunds', 'Refund', '退付记录'],
  ['PaymentRegisterView.vue', 'Payments', 'Payment', '缴费记录'],
]

for (const [view, action, selection, label] of cases) {
  const source = readFileSync(resolve(__dirname, `../src/views/case-fund/${view}`), 'utf8')
  const handler = source.match(new RegExp(`const handleDelete${action} = async \\(\\) => \\{([\\s\\S]*?)\\n\\}`))
  assert(handler, `${view} should expose its delete action`)
  const runHandler = new AsyncFunction(
    `selected${selection}Ids`, `selected${selection}Rows`,
    'ElMessage', 'ElMessageBox', 'caseFundApi', 'deleting', 'load',
    handler[1].replaceAll('catch (e: any)', 'catch (e)')
  )

  for (const rejected of [false, true]) {
    const selectedIds = { value: ['business-row'] }
    const selectedRows = { value: [{ voucher_status: 'GENERATED' }] }
    const deleting = { value: false }
    const messages = { success: [], error: [], warning: [] }
    let confirmation = ''
    let calls = 0
    let reloads = 0
    await runHandler(
      selectedIds, selectedRows,
      Object.fromEntries(Object.keys(messages).map((type) => [type, (text) => messages[type].push(text)])),
      { confirm: async (text) => { confirmation = text } },
      { [`delete${action}`]: async (ids) => {
        calls += 1
        assert.deepEqual(ids, ['business-row'])
        if (rejected) throw new Error('原凭证仍有效，不能删除')
        return { deleted_count: 1 }
      } },
      deleting,
      async () => { reloads += 1 }
    )

    assert.equal(calls, 1, `${view} should let the backend check whether a generated row's original voucher still exists`)
    assert(confirmation.includes('已制证且原凭证仍有效的数据不能删除'), `${view} should explain the active voucher restriction`)
    assert(confirmation.includes('相关对账结果失效') && confirmation.includes('银行流水保留'), `${view} should explain reconciliation invalidation without deleting bank statements`)
    assert(confirmation.includes('重新对账'), `${view} should tell the user to reconcile again`)
    assert(!confirmation.includes('或已对账的数据不能删除'), `${view} should not retain the old reconciliation deletion restriction`)
    assert.equal(deleting.value, false)
    if (rejected) {
      assert.deepEqual(messages.error, ['原凭证仍有效，不能删除'])
      assert.equal(messages.success.length, 0)
      assert.deepEqual(selectedIds.value, ['business-row'])
      assert.equal(reloads, 0)
    } else {
      assert(messages.success[0].includes(`已删除 1 条${label}`))
      assert(messages.success[0].includes('重新对账'), `${view} should remind the user to refresh reconciliation after deletion`)
      assert.deepEqual(selectedIds.value, [])
      assert.equal(reloads, 1)
    }
  }
}

console.log('Case fund business delete after reconciliation frontend test passed')
