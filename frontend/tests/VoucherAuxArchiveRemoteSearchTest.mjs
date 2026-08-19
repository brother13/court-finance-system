import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'node:assert/strict'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherEditorView.vue'), 'utf8')

assert(component.includes('remote'), 'voucher auxiliary archive select should use server-side remote search')
assert(
  component.includes(':remote-method="(keyword: string) => searchAuxArchives(config.aux_type_code, keyword)"'),
  'each auxiliary dimension should search within its own archive type'
)
assert(
  component.includes('baseApi.auxArchives(code, normalizedKeyword, 1, AUX_ARCHIVE_PAGE_SIZE)'),
  'remote search should send the keyword to the paginated archive API'
)
assert(
  component.includes('auxArchiveRequestIds[code]'),
  'stale remote search responses should not overwrite newer results'
)
assert(
  component.includes('完整案号会忽略括号、空格差异，也可输入“2025968”快捷检索'),
  'large case archives should explain normalized complete search and shortcut search modes'
)
assert(
  component.includes('mergeAuxArchiveOptions'),
  'remote search should preserve the currently selected archive option'
)

console.log('Voucher auxiliary archive remote search test passed')
