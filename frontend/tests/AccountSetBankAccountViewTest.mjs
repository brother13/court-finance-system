import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/system/AccountSetManageView.vue'), 'utf8')
const types = readFileSync(resolve(__dirname, '../src/types/api.ts'), 'utf8')

for (const text of ['账户设置', '账户户名', '银行账号', '开户银行', '开户支行']) {
  assert(view.includes(text), `account set form should expose ${text}`)
}

for (const field of ['bank_account_name', 'bank_account_no', 'bank_name', 'bank_branch_name']) {
  assert(view.includes(`form.${field}`), `account set form should bind ${field}`)
  assert(view.includes(`row.${field}`), `editing account set should load ${field}`)
  assert(types.includes(`${field}?: string`), `AccountSet type should define ${field}`)
}

assert(view.includes('prop="bank_account_no"'), 'bank account number should have a form item prop')
assert(view.includes("bank_account_no: [{ required: true"), 'bank account number should be required')

console.log('Account set bank account view test passed')
