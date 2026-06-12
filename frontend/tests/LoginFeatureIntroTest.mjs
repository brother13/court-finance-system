import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const login = readFileSync(resolve(__dirname, '../src/views/auth/LoginView.vue'), 'utf8')
const styles = readFileSync(resolve(__dirname, '../src/styles/app.css'), 'utf8')

assert(login.includes('一张网'), 'login page should introduce one-click import from the 一张网 court business system')
assert(login.includes('一键导入') && login.includes('一键生成凭证'), 'login page should mention one-click import and voucher generation')
assert(login.includes('案号+单据号'), 'login page should mention 案号+单据号 auxiliary accounting')
assert(login.includes('生成并设置辅助核算项'), 'login page should mention generating and setting auxiliary accounting items')
assert(login.includes('不明款') && login.includes('案款分配'), 'login page should mention unknown payments and case-fund distribution voucher generation')
assert(login.includes('按收费方式制证'), 'login page should introduce payment-method voucher generation')
assert(login.includes('POS') && login.includes('转账') && login.includes('不明款'), 'login page should mention POS, transfer, and unknown payment methods')
assert(login.includes('收费方式设置科目生成凭证'), 'login page should mention subject configuration by payment method')
assert(styles.includes('grid-template-columns: minmax(560px, 1.24fr) minmax(340px, 0.76fr)'), 'login page should make the brand panel wider and login panel narrower')
assert(styles.includes('grid-template-columns: repeat(4, minmax(0, 1fr))'), 'login feature cards should render four cards in one row on desktop')
assert(!styles.includes('.login-status-grid .login-status-wide'), 'payment-method feature card should not span a separate row')
assert(!login.includes('账务引擎') && !login.includes('双分录') && !login.includes('内网独立'), 'login page should not keep old generic feature cards')

console.log('Login feature intro test passed')
