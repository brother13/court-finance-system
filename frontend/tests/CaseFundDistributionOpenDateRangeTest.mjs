import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const view = readFileSync(resolve(__dirname, '../src/views/case-fund/DistributionView.vue'), 'utf8')

assert(
  view.includes('date_start: normalizeDateFilter(filters.date_start)'),
  'distribution queries should normalize a cleared start date'
)
assert(
  view.includes('date_end: normalizeDateFilter(filters.date_end)'),
  'distribution queries should normalize a cleared end date for an open-ended range'
)
assert(
  view.includes('function normalizeDateFilter(value: string | null | undefined)'),
  'distribution date normalization should accept the null value emitted by a cleared date picker'
)

console.log('Case fund distribution open date range test passed')
