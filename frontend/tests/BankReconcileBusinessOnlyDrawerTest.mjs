import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { parse, compileScript } from '@vue/compiler-sfc'
import ts from 'typescript'
import { reactive, nextTick, effectScope } from 'vue'

const require = createRequire(import.meta.url)
const source = readFileSync(new URL('../src/components/case-fund/BankReconcileBusinessOnlyDrawer.vue', import.meta.url), 'utf8')
const { descriptor } = parse(source)
const script = compileScript(descriptor, { id: 'business-only-test' })
const js = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText
const context = reactive({ accountSetId: 'A', accountSetName: '测试账套', year: 2026 })
const requests = []
const errors = []
const downloads = []
const xlsx = require('xlsx')
const api = { bankReconcileList(payload) {
  return new Promise((resolve, reject) => requests.push({ payload, resolve, reject }))
} }
const module = { exports: {} }
new Function('require', 'module', 'exports', js)((name) => {
  if (name === '../../api/caseFund') return { caseFundApi: api }
  if (name === '../../stores/context') return { useContextStore: () => context }
  if (name === 'element-plus') return { ElMessage: { error: (message) => errors.push(message) } }
  if (name === 'xlsx') return { ...xlsx, writeFile: (book, name) => downloads.push({ book, name }) }
  return require(name)
}, module, module.exports)
const props = reactive({ modelValue: false, dateStart: '2026-05-01', dateEnd: '2026-05-31', canNavigate: () => true })
const effect = effectScope()
const state = effect.run(() => module.exports.default.setup(props, { expose() {}, emit() {} }))
const flush = async () => { await nextTick(); await Promise.resolve(); await nextTick() }
const row = (id) => ({ reconcile_id: id, biz_id: id, biz_type: 'PAYMENT', biz_amount: '100.00', bank_serial_no: '', reconcile_date: '2026-05-13', match_status: 'BIZ_ONLY' })

props.modelValue = true
await flush()
assert.equal(requests[0].payload.status, 'BIZ_ONLY')
assert.equal(requests[0].payload.date_start, '2026-05-01')
props.dateStart = '2026-06-01'
await flush()
requests[0].resolve({ items: [row('old')], total: 1 })
requests[1].resolve({ items: [row('new')], total: 1 })
await flush()
assert.equal(state.rows.value[0].biz_id, 'new', 'Late response must not replace a different date scope')
assert.match(state.investigationHint(row('claim')), /原不明款认领来源/, 'Missing serial must not imply missing bank income')

let exporting = state.exportRows()
let first = requests.at(-1)
first.resolve({ items: Array.from({ length: 200 }, (_, i) => row(String(i))), total: 201 })
await flush()
assert.equal(requests.at(-1).payload.page, 2, 'Export must fetch business-only rows beyond first page')
requests.at(-1).resolve({ items: [row('200')], total: 201 })
await exporting
assert.equal(downloads.length, 1)
assert.equal(xlsx.utils.sheet_to_json(downloads[0].book.Sheets['业务待核查'], { header: 1 }).length, 204)

exporting = state.exportRows()
first = requests.at(-1)
context.accountSetId = 'B'
await flush()
first.resolve({ items: [row('old-account')], total: 1 })
await exporting
assert.equal(downloads.length, 1, 'Changing account during export must not download mixed data')
assert.match(errors.at(-1), /账套或日期范围已变化/)
requests.at(-1).resolve({ items: [row('B')], total: 1 })
await flush()
props.modelValue = false
await flush()
assert.equal(state.rows.value.length, 0, 'Closing drawer must clear stale results')
effect.stop()
console.log('Bank reconcile business-only drawer runtime test passed')
