import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'node:assert/strict'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/base/OpeningBalanceView.vue'), 'utf8')
const model = readFileSync(resolve(__dirname, '../../backend/app/finance/model/Opening.php'), 'utf8')

assert(component.includes("config.value_source === 'ARCHIVE'"), 'archive-backed dimensions should be controlled by value_source')
assert(component.includes(':disabled="!archives[config.aux_type_code]?.length"'), 'archive-backed dimensions should not fall back to free text when empty')
assert(component.includes('请先维护辅助核算项'), 'empty archive-backed dimensions should explain how to proceed')
assert(component.includes('手工录入，保存后同步档案'), 'manual dimensions should explain archive synchronization')
assert(component.includes("router.push('/base/aux-items')"), 'opening drawer should link to auxiliary archive maintenance')
assert(model.includes("$config['value_source']"), 'opening API should return each auxiliary dimension value source')
assert(model.includes("'status' => 1"), 'opening archive lookup should only expose enabled archives')

console.log('Opening auxiliary archive test passed')
