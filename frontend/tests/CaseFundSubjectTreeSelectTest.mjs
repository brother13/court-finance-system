import { readFileSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const utilPath = resolve(__dirname, '../src/utils/subjectTree.ts')
const viewPaths = [
  '../src/views/case-fund/PaymentRegisterView.vue',
  '../src/views/case-fund/RefundRegisterView.vue',
  '../src/views/case-fund/UnknownPaymentView.vue',
  '../src/views/case-fund/DistributionView.vue'
]

assert(existsSync(utilPath), 'case-fund subject config should use a shared subject tree helper')

const util = readFileSync(utilPath, 'utf8')
assert(util.includes('buildSubjectTreeOptions'), 'subject tree helper should expose buildSubjectTreeOptions')
assert(util.includes('disabled: !isSelectableVoucherSubject'), 'subject tree should disable non-selectable subjects')
assert(util.includes('Number(subject.leaf_flag ?? subject.leafFlag) === 1'), 'selectable subjects must be leaf subjects')
assert(util.includes('Number(subject.voucher_entry_flag ?? subject.voucherEntryFlag ?? 1) === 1'), 'selectable subjects must allow voucher entry')

for (const relativePath of viewPaths) {
  const view = readFileSync(resolve(__dirname, relativePath), 'utf8')
  assert(view.includes('<el-tree-select'), `${relativePath} should render subject options as a tree`)
  assert(view.includes(':data="subjectTreeOptions"'), `${relativePath} should bind tree-select data to subjectTreeOptions`)
  assert(view.includes('check-strictly'), `${relativePath} should allow choosing enabled leaf nodes without auto-checking parents`)
  assert(view.includes('buildSubjectTreeOptions'), `${relativePath} should use the shared subject tree helper`)
  assert(!view.includes('v-for="subject in subjectOptions"'), `${relativePath} should not keep flat subject option rendering`)
}

console.log('Case fund subject tree-select test passed')
