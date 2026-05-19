import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const router = readFileSync(resolve(__dirname, '../src/router/index.ts'), 'utf8')
const app = readFileSync(resolve(__dirname, '../src/App.vue'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/period.ts'), 'utf8')
const view = readFileSync(resolve(__dirname, '../src/views/period/PeriodCloseView.vue'), 'utf8')

assert(router.includes("const PeriodCloseView"), 'router should lazy-load period close view')
assert(router.includes("path: '/period/close'"), 'router should register period close route')
assert(router.includes("permission: 'period:close'"), 'period close route should require period:close permission')
assert(app.includes("index=\"/period/close\""), 'sidebar should show period close menu entry')
assert(app.includes("'/period/close': '期末结账'"), 'tab title map should include period close page')
assert(api.includes("apiAction('/period/closeCheck'"), 'period API should expose closeCheck')
assert(api.includes("apiAction('/period/close'"), 'period API should expose close')
assert(api.includes("apiAction('/period/reopen'"), 'period API should expose reopen')
assert(api.includes("apiAction('/period/yearEnd'"), 'period API should expose yearEnd')
assert(view.includes('期末检查'), 'period close view should show close check section')
assert(view.includes('年结生成下一年度'), 'period close view should expose year-end generation')
assert(view.includes('warnings'), 'period close view should render non-blocking warnings')

console.log('Period close view test passed')
