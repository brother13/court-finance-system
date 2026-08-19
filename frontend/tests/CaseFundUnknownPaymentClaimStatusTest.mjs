import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/case-fund/UnknownPaymentView.vue'), 'utf8')

assert(
  view.includes('<el-option label="待认领" value="待认领" />'),
  'unknown-payment filters should use the stored pending claim status'
)
assert(
  view.includes('<el-option label="已确认" value="已确认" />'),
  'unknown-payment filters should use the stored confirmed claim status'
)
assert(
  !view.includes('<el-option label="未认领" value="未认领" />')
    && !view.includes('<el-option label="已认领" value="已认领" />'),
  'unknown-payment filters should not send claim status values absent from the current list data'
)
assert(
  view.includes("status === '已确认'"),
  'confirmed unknown payments should use the confirmed status tag style'
)

console.log('Case fund unknown-payment claim status test passed')
