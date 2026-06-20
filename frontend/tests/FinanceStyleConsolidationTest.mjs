import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const styles = readFileSync(resolve(__dirname, '../src/styles/app.css'), 'utf8')
const login = readFileSync(resolve(__dirname, '../src/views/auth/LoginView.vue'), 'utf8')
const accountSelect = readFileSync(resolve(__dirname, '../src/views/auth/AccountSetSelectView.vue'), 'utf8')

const block = (start, end) => {
  const startIndex = styles.indexOf(start)
  assert(startIndex >= 0, `missing style block start: ${start}`)
  const endIndex = styles.indexOf(end, startIndex)
  assert(endIndex > startIndex, `missing style block end: ${end}`)
  return styles.slice(startIndex, endIndex)
}

const loginStyles = block('.login-page {', '.account-select-page {')
const accountStyles = block('.account-select-page {', '@media (max-width: 920px)')
const dashboardStyles = block('.metric-grid {', '/* ============================================================\n * Info tip 卡片')
const tableStyles = block('.el-table {', '/* ============================================================\n * 按钮 / 输入框 / 标签')

assert(login.includes('raspberryLogo'), 'login should use the same raspberry logo asset as the app shell')
assert(!login.includes('login-brand-mark'), 'login should not keep the old decorative CSS brand mark')
assert(!login.includes('login-brand-panel'), 'login should not keep the old left-side introduction panel')
assert(!login.includes('login-status-grid'), 'login should not keep feature introduction cards')
assert(!loginStyles.includes('radial-gradient'), 'login should not use heavy radial marketing backgrounds')
assert(!loginStyles.includes('border-radius: 24px'), 'login should avoid large rounded marketing panels')
assert(!loginStyles.includes('box-shadow: 0 28px'), 'login should avoid heavy marketing shadows')

assert(accountStyles.includes('background: var(--bg-main)'), 'account set selection should use the finance workspace background')
assert(!accountStyles.includes('border-radius: 999px'), 'account set selection should not use pill badges')
assert(!accountStyles.includes('translateY('), 'account set cards should not hover-lift like SaaS cards')
assert(accountSelect.includes('border-left: 4px solid transparent'), 'account set cards should use a restrained left-line selected pattern')

assert(dashboardStyles.includes('border-left: 3px solid var(--brand-blue)'), 'dashboard metrics should use a restrained finance indicator line')
assert(!dashboardStyles.includes('border-radius: 16px'), 'dashboard metrics and panels should not use large SaaS card radii')
assert(!dashboardStyles.includes('translateY('), 'dashboard cards should not use hover-lift motion')

assert(tableStyles.includes('.el-table__inner-wrapper::before'), 'tables should suppress duplicate Element Plus helper borders')
assert(tableStyles.includes('display: none !important'), 'tables should keep a single visible outer border')

console.log('Finance style consolidation test passed')
