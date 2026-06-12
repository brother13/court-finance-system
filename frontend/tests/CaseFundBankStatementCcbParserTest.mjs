import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { createRequire } from 'node:module'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const require = createRequire(import.meta.url)
const XLSX = require('xlsx')
const view = readFileSync(resolve(__dirname, '../src/views/case-fund/BankStatementView.vue'), 'utf8')
const samplePath = '/Users/apple/Documents/铁西法院/1-3.xls'
const workbook = XLSX.readFile(samplePath, { cellDates: true })
const sheet = workbook.Sheets[workbook.SheetNames[0]]
const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '', raw: false })
const header = rows[0]
const first = rows[1]

assert(header.includes('账号'), 'CCB sample should contain account no header')
assert(header.includes('账户名称'), 'CCB sample should contain account name header')
assert(header.includes('交易时间'), 'CCB sample should contain transaction time header')
assert(header.includes('借方发生额（支取）'), 'CCB sample should contain debit amount header')
assert(header.includes('贷方发生额（收入）'), 'CCB sample should contain credit amount header')
assert(header.includes('账户明细编号-交易流水号'), 'CCB sample should contain bank serial no header')
assert.strictEqual(first[0], '21050140000809000111', 'CCB sample should expose receipt account number')
assert.strictEqual(first[2], '20260101 04:35:14', 'CCB sample should use yyyyMMdd time format')

assert(view.includes('accept=".xls,.xlsx"'), 'bank statement import should accept legacy .xls and .xlsx files')
assert(!view.includes('建设银行电子对账单模板待配置'), 'CCB import should not be blocked by a placeholder warning')
assert(view.includes('parseCcbRows'), 'bank statement view should include CCB parser')
assert(view.includes("row['借方发生额（支取）']"), 'CCB parser should map debit amount column')
assert(view.includes("row['贷方发生额（收入）']"), 'CCB parser should map credit amount column')
assert(view.includes("row['账户明细编号-交易流水号']"), 'CCB parser should map bank serial no column')
assert(view.includes('normalizeCcbDateTime'), 'CCB parser should normalize yyyyMMdd transaction time')

console.log('Case fund CCB bank statement parser test passed')
