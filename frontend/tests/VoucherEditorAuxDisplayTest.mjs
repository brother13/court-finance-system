import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'node:assert/strict'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherEditorView.vue'), 'utf8')

assert(/:title="row\.summary \|\| ''"/.test(component), 'readonly summary text should expose full content on hover')
assert(component.includes('const configuredAuxEntries = (line: VoucherLine)'), 'voucher editor should centralize configured auxiliary entries')
assert(component.includes('return lineAuxConfigs(line)'), 'configured auxiliary entries should come from the selected subject config')
assert(component.includes('return configuredAuxEntries(line)'), 'auxiliary summary should filter out unconfigured stored aux values')
assert(component.includes('aux_values: configuredAuxEntries(line)'), 'voucher save payload should only submit configured auxiliary values')
assert(component.includes(':title="auxSummary(row)"'), 'auxiliary pill should expose the full configured auxiliary summary on hover')

console.log('Voucher editor auxiliary display test passed')
