import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import assert from 'node:assert/strict'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/voucher/VoucherEditorView.vue'), 'utf8')
const appCss = readFileSync(resolve(__dirname, '../src/styles/app.css'), 'utf8')

assert(view.includes('v-if="!isNewMode" class="voucher-line-locator"'), 'line locator should only appear in voucher view and edit modes')
assert(view.includes('placeholder="输入辅助核算名称"'), 'line locator should query by auxiliary display name')
assert(view.includes('<template #prepend>辅助核算</template>'), 'line locator should use the requested auxiliary accounting label')
assert(view.includes('<template #prepend>精确金额</template>'), 'line locator should expose an exact amount field')
assert(!view.includes('仅查询当前凭证；'), 'line locator should omit the explanatory row to stay compact')
assert(view.includes('lineAuxiliaryDisplayNames(line)'), 'auxiliary matching should use line auxiliary display names')
assert(view.includes('[line.debitText, line.creditText]'), 'amount matching should check both debit and credit sides')
assert(view.includes('Math.round(parseAmount(amount) * 100) === amountCents'), 'amount matching should compare integer cents exactly')
assert(view.includes('if (matchesAuxName && matchesAmount) indices.push(index)'), 'auxiliary name and amount should be combined with AND semantics')
assert(view.includes('Math.floor(lineIndex / LARGE_VOUCHER_LINE_PAGE_SIZE) + 1'), 'locating a line should jump to its detail page')
assert(view.includes(':class="{ \'is-locator-active\': activeLocatedLineIndex === visibleLineStart + index }"'), 'located line should receive a highlight class')
assert(appCss.includes('.voucher-entry-table tbody tr.is-locator-active'), 'located line should have a visible highlight style')

const matcherStart = view.indexOf('const locatedLineIndices = computed')
const matcherEnd = view.indexOf('const activeLocatedOrdinal = computed')
const matcher = matcherStart >= 0 && matcherEnd > matcherStart ? view.slice(matcherStart, matcherEnd) : ''
assert(matcher && !matcher.includes('subjectCode') && !matcher.includes('summary'), 'line locator must not query subject or summary fields')

console.log('Voucher line locator test passed')
