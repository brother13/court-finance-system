import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/books/SubjectBalanceView.vue'), 'utf8')

assert(view.includes('科目余额表'), 'subject balance header should keep report name')
assert(!view.includes('科目余额表 · {{ context.period }}'), 'subject balance header should not repeat accounting period')

console.log('Book subject balance header test passed')
