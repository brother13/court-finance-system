import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
const require = createRequire(import.meta.url)
const ts = require('typescript')
const XLSX = require('xlsx')
const source = readFileSync(new URL('../src/utils/voucherExport.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText
const module = { exports: {} }
new Function('require', 'module', 'exports', compiled)(require, module, module.exports)
const { buildVoucherExportWorkbook } = module.exports
const rows = Array.from({ length: 102 }, (_, index) => ({
  voucher_id: `voucher-${Math.floor(index / 2)}`, period: '2026-08', voucher_date: '2026-08-07', voucher_word: '记', voucher_no: '33',
  line_no: index % 2 + 1, summary: '=SUM(A1:A2)', subject_code: '001002', subject_name: '银行存款', aux_desc: '收据号：000123',
  debit_amount: index % 2 === 0 ? '10.30' : '0.00', credit_amount: index % 2 === 1 ? '10.30' : '0.00', attachment_count: 0,
  prepared_by_name: '测试制单人', audit_by_name: '', posted_by_name: '', status: 'SUBMITTED', printed_flag: '1', source_type: 'MANUAL',
}))
const workbook = buildVoucherExportWorkbook(rows)
const bytes = XLSX.write(workbook, { type: 'buffer', bookType: 'xlsx' })
assert.equal(bytes.subarray(0, 2).toString(), 'PK', 'Export must be a real xlsx ZIP file')
const sheet = XLSX.read(bytes, { type: 'buffer', cellNF: true }).Sheets['凭证明细']
assert.equal(XLSX.utils.sheet_to_json(sheet).length, 102, 'Export must include rows beyond the first page')
assert.equal(sheet.F2.t, 's')
assert.equal(sheet.F2.v, '001002', 'Subject codes must preserve leading zeros')
assert.equal(sheet.E2.t, 's')
assert.equal(sheet.E2.f, undefined, 'User content must remain text, never an Excel formula')
assert.equal(sheet.I2.t, 'n')
assert.equal(sheet.I2.v, 10.3)
assert(sheet.I2.z.includes('0.00'), 'Money cells must display two decimal places')
assert.equal(sheet.H2.v, '收据号：000123')
assert.equal(sheet.C2.v, '记-0033')
const view = readFileSync(new URL('../src/views/voucher/VoucherListView.vue', import.meta.url), 'utf8')
assert(view.includes("context.hasPermission('voucher:export')"))
assert(view.includes('command="export"'))
assert(view.includes('voucherApi.exportData(filters)'))
assert(view.includes('pagesize: _pagesize'), 'Export should remove pagination from the request')
console.log('Voucher Excel export test passed')
