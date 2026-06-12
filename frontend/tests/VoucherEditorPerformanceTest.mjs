import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherEditorView.vue'), 'utf8')
const loadDetailStart = component.indexOf('async function loadVoucherDetail')
const loadDetailBody = loadDetailStart >= 0 ? component.slice(loadDetailStart) : ''

assert(component.includes('loadSubjectConfigsForLines'), 'voucher detail loading should batch-load subject aux configs for loaded lines')
assert(component.includes('Promise.all(subjectCodes.map((subjectCode) => loadSubjectConfig(subjectCode)))'), 'voucher detail subject config loading should be parallel by unique subject code')
assert(!loadDetailBody.includes('await loadSubjectConfig(subjectCode)'), 'voucher detail loading should not await subject config once per detail row')

console.log('Voucher editor performance test passed')
