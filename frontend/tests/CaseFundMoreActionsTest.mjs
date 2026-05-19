import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const paymentView = readFileSync(resolve(__dirname, '../src/views/case-fund/PaymentRegisterView.vue'), 'utf8')
const refundView = readFileSync(resolve(__dirname, '../src/views/case-fund/RefundRegisterView.vue'), 'utf8')

for (const [name, source, importLabel] of [
  ['payment', paymentView, '导入缴费'],
  ['refund', refundView, '导入退付']
]) {
  assert(source.includes('<el-dropdown'), `${name} register should use a more-actions dropdown`)
  assert(source.includes('更多功能'), `${name} register should label the dropdown trigger as more actions`)
  assert(source.includes('<el-dropdown-menu'), `${name} register should render a dropdown menu`)
  assert(source.includes('<el-dropdown-item') && source.includes('删除'), `${name} delete action should be inside the dropdown`)
  assert(source.includes('科目配置'), `${name} subject config action should be inside the dropdown`)
  assert(source.includes(importLabel), `${name} import action should be inside the dropdown`)
  assert(source.includes('command="delete"'), `${name} dropdown should expose a delete command`)
  assert(source.includes('command="subjectConfig"'), `${name} dropdown should expose a subject config command`)
  assert(source.includes('command="import"'), `${name} dropdown should expose an import command`)
}

console.log('Case fund more actions frontend test passed')
