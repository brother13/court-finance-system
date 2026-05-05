import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherEditorView.vue'), 'utf8')

assert(!component.includes('按用友 U8 / 金蝶标准凭证格式录入，保存后进入未审核状态。'), 'voucher editor should not show the entry help text')
assert(!component.includes('placeholder="// 复制首行，.. 复制上行"'), 'summary cells should not show shortcut placeholder text')
assert(component.includes('terminalVoucherSubjects'), 'subject options should come from terminal voucher subjects')
assert(!component.includes('\n                remote\n'), 'subject select should use local filtering over terminal subjects only')

console.log('Voucher editor review test passed')
