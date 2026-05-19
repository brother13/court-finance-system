import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherEditorView.vue'), 'utf8')
const appCss = readFileSync(resolve(__dirname, '../src/styles/app.css'), 'utf8')

assert(!component.includes('按用友 U8 / 金蝶标准凭证格式录入，保存后进入未审核状态。'), 'voucher editor should not show the entry help text')
assert(!component.includes('placeholder="// 复制首行，.. 复制上行"'), 'summary cells should not show shortcut placeholder text')
assert(component.includes('terminalVoucherSubjects'), 'subject options should come from terminal voucher subjects')
assert(!component.includes('\n                remote\n'), 'subject select should use local filtering over terminal subjects only')
assert(component.includes('<th class="entry-subject">科目</th>'), 'voucher detail subject header should use the concise label 科目')
assert(!component.includes('科目编码 / 科目名称'), 'voucher detail subject header should not use the long code/name label')
assert(appCss.includes('.entry-summary {\n  width: 290px;'), 'voucher summary column should be widened to use space freed from subject column')
assert(appCss.includes('.entry-subject {\n  width: 180px;'), 'voucher subject column should be narrower while still fitting subject display text')
assert(component.includes('auditStampVisible'), 'voucher detail view should gate the audit stamp with a dedicated computed flag')
assert(component.includes("statusForForm.value === 'AUDITED'"), 'audit stamp should only appear for audited voucher workflow status')
assert(component.includes('v-if="auditStampVisible"'), 'voucher panel should render the audit stamp only when the gate is true')
assert(component.includes('auditStampUrl'), 'voucher editor should import the audit stamp image as a Vite asset')
assert(appCss.includes('.voucher-u8-panel .el-input.is-disabled .el-input__inner'), 'voucher readonly inputs should override disabled gray text')
assert(appCss.includes('.voucher-u8-panel .el-select.is-disabled .el-select__placeholder'), 'voucher readonly selects should override disabled gray text')
assert(appCss.includes('--el-disabled-text-color: #1f2937'), 'voucher readonly controls should use normal dark text color')

console.log('Voucher editor review test passed')
