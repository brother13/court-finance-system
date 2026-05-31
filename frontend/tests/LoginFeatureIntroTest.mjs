import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const login = readFileSync(resolve(__dirname, '../src/views/auth/LoginView.vue'), 'utf8')

assert(login.includes('一张网'), 'login page should introduce one-click import from the 一张网 court business system')
assert(login.includes('一键导入') && login.includes('一键生成凭证'), 'login page should mention one-click import and voucher generation')
assert(login.includes('案号+单据号'), 'login page should mention 案号+单据号 auxiliary accounting')
assert(login.includes('生成并设置辅助核算项'), 'login page should mention generating and setting auxiliary accounting items')
assert(login.includes('不明款') && login.includes('案款分配'), 'login page should mention unknown payments and case-fund distribution voucher generation')
assert(!login.includes('账务引擎') && !login.includes('双分录') && !login.includes('内网独立'), 'login page should not keep old generic feature cards')

console.log('Login feature intro test passed')
