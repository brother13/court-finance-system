import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/base/OpeningBalanceView.vue'), 'utf8')
const model = readFileSync(resolve(__dirname, '../../backend/app/finance/model/Opening.php'), 'utf8')
const rbac = readFileSync(resolve(__dirname, '../../backend/database/migration_rbac.sql'), 'utf8')

assert(component.includes("v-permission=\"['opening:save', 'base:edit']\""), 'opening page save buttons should allow opening:save permission')
assert(component.includes('await baseApi.saveOpeningBalances'), 'opening page should call subject opening save API')
assert(component.includes('await baseApi.saveAuxOpeningBalances'), 'opening page should call auxiliary opening save API')
assert(model.includes("requireOpeningSavePermission()"), 'opening backend saves should use dedicated opening save permission helper')
assert(model.includes("hasPermission('opening:save')"), 'opening backend should accept opening:save permission')
assert(rbac.includes("('opening:save', '期初保存'"), 'RBAC migration should define opening:save permission')
assert(rbac.includes("('00000000-0000-0000-0000-000000010003', 'opening:save')"), 'voucher maker should be able to save opening balances')

console.log('Opening balance save permission test passed')
