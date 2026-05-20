import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/system/AccountSetManageView.vue'), 'utf8')

assert(view.includes('凭证打印分录条数'), 'account set form should expose voucher print line count')
assert(view.includes('<el-option label="8条" :value="8" />'), 'voucher print line count should keep 8 line option')
assert(view.includes('<el-option label="10条" :value="10" />'), 'voucher print line count should keep 10 line option')
assert(view.includes('<el-option label="12条" :value="12" />'), 'voucher print line count should keep 12 line option')
assert(view.includes('<el-option label="20条" :value="20" />'), 'voucher print line count should offer 20 line option')

console.log('Account set print line count test passed')
