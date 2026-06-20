import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const styles = readFileSync(resolve(__dirname, '../src/styles/app.css'), 'utf8')

const viewFiles = [
  'PaymentRegisterView.vue',
  'RefundRegisterView.vue',
  'BankStatementView.vue',
  'UnknownPaymentView.vue',
  'DistributionView.vue'
]

const globalRequirements = [
  '.case-fund-toolbar {',
  '.case-fund-summary {',
  '.case-fund-table-shell {',
  'border: 0;',
  '.case-fund-pagination {',
  '.case-fund-actions .el-button--success',
  '.case-fund-summary-actions .el-button--success',
  '--el-button-text-color: #FFFFFF',
  '--el-button-hover-text-color: #FFFFFF',
  'color: #FFFFFF !important',
  '.case-fund-summary-actions .el-button--success:not(.is-plain) span',
  'font-variant-numeric: tabular-nums'
]

for (const requirement of globalRequirements) {
  assert(styles.includes(requirement), `global app styles should define ${requirement}`)
}

const summaryBlockStart = styles.indexOf('.case-fund-summary > div {')
assert(summaryBlockStart >= 0, 'case fund summary cards should have global styles')
const summaryBlockEnd = styles.indexOf('.case-fund-summary span {', summaryBlockStart)
assert(summaryBlockEnd > summaryBlockStart, 'case fund summary card block should end before summary text styles')
const summaryBlock = styles.slice(summaryBlockStart, summaryBlockEnd)
assert(summaryBlock.includes('border: 1px solid var(--border-light);'), 'case fund summary cards should use a single border')
assert(!summaryBlock.includes('border-left:'), 'case fund summary cards should not use a separate left accent line')

for (const file of viewFiles) {
  const view = readFileSync(resolve(__dirname, `../src/views/case-fund/${file}`), 'utf8')
  const scopedStyle = view.split('<style scoped>')[1]?.split('</style>')[0] || ''

  assert(!scopedStyle.includes('.case-fund-toolbar {'), `${file} should not duplicate toolbar shell styles`)
  assert(!scopedStyle.includes('.case-fund-summary > div'), `${file} should not duplicate summary card styles`)
  assert(!scopedStyle.includes('.case-fund-table-shell {'), `${file} should not duplicate table shell styles`)
  assert(!scopedStyle.includes('.case-fund-pagination {'), `${file} should not duplicate pagination styles`)
  assert(!scopedStyle.includes('.case-fund-file-input {'), `${file} should not duplicate hidden file input styles`)
  assert(!scopedStyle.includes('--text-primary'), `${file} should not use stale text-primary token`)
  assert(!scopedStyle.includes('--text-placeholder'), `${file} should not use stale text-placeholder token`)
}

const paymentView = readFileSync(resolve(__dirname, '../src/views/case-fund/PaymentRegisterView.vue'), 'utf8')
const refundView = readFileSync(resolve(__dirname, '../src/views/case-fund/RefundRegisterView.vue'), 'utf8')
const voucherStatusFilterFiles = [
  ['PaymentRegisterView.vue', paymentView],
  ['RefundRegisterView.vue', refundView],
  ['UnknownPaymentView.vue', readFileSync(resolve(__dirname, '../src/views/case-fund/UnknownPaymentView.vue'), 'utf8')],
  ['DistributionView.vue', readFileSync(resolve(__dirname, '../src/views/case-fund/DistributionView.vue'), 'utf8')]
]

for (const [name, view] of [['PaymentRegisterView.vue', paymentView], ['RefundRegisterView.vue', refundView]]) {
  assert(view.includes('>按查询生成</el-button>'), `${name} should keep the generate-by-query button`)
  assert(!view.includes('plain :icon="DocumentChecked" :loading="generating" @click="handleGenerateVoucherByQuery"'), `${name} generate-by-query button should use the primary voucher button style`)
}

for (const [name, view] of voucherStatusFilterFiles) {
  assert(!view.includes('<el-option label="已作废" value="VOIDED" />'), `${name} voucher status filter should not expose voided records as a daily query option`)
  assert(view.includes("VOIDED: '已作废'"), `${name} should still render voided voucher status labels when returned by backend`)
}

console.log('Case fund style consolidation test passed')
