import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherListView.vue'), 'utf8')

assert(component.includes('<span>借方科目</span>'), 'advanced filters should show a debit subject field')
assert(component.includes('v-model="advancedDraft.debit_subject_code"'), 'debit subject field should bind to the applied query model')
assert(component.includes('<span>贷方科目</span>'), 'advanced filters should show a credit subject field')
assert(component.includes('v-model="advancedDraft.credit_subject_code"'), 'credit subject field should bind to the applied query model')
assert(component.includes('v-for="subject in subjectOptions"'), 'directional subject fields should render selectable subject options')
assert(component.includes(':label="subjectOptionLabel(subject)"'), 'subject options should show both code and name')
assert(component.includes(':value="subjectCodeOf(subject)"'), 'subject selection should submit the exact subject code')
assert(component.includes('filterable'), 'subject selections should support searching by code or name')
assert(component.includes('subjects.value = await baseApi.subjects()'), 'voucher list should load subjects from the existing base API')
assert(component.includes('!parentCodes.has(code)'), 'subject selections should only show actual leaf subjects')
assert(component.includes('debit_subject_code: string'), 'advanced filter type should include debit subject code')
assert(component.includes('credit_subject_code: string'), 'advanced filter type should include credit subject code')
assert(component.includes("debit_subject_code: ''"), 'advanced filter reset should clear debit subject code')
assert(component.includes("credit_subject_code: ''"), 'advanced filter reset should clear credit subject code')
assert(component.includes('...cleanFilter(advancedApplied.value)'), 'applied directional subject filters should be sent to the list API')

console.log('Voucher directional subject filter test passed')
