import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const unknownView = readFileSync(resolve(__dirname, '../src/views/case-fund/UnknownPaymentView.vue'), 'utf8')
const refundView = readFileSync(resolve(__dirname, '../src/views/case-fund/RefundRegisterView.vue'), 'utf8')
const dialog = readFileSync(resolve(__dirname, '../src/components/case-fund/CaseFundReturnMatchDialog.vue'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/caseFund.ts'), 'utf8')

assert(
  unknownView.includes("v-permission=\"'case_fund:return_match'\"")
    && unknownView.includes('识别退汇'),
  'unknown-payment page should expose return scanning behind the dedicated permission'
)
assert(
  unknownView.includes('label="不参与匹配" value="INELIGIBLE"')
    && unknownView.includes('缺少缴款人或付款账号，将跳过'),
  'missing payer name or account should be displayed and counted as skipped instead of unmatched'
)
assert(
  unknownView.includes("const startPeriod = dateStart.slice(0, 7)")
    && unknownView.includes("const endPeriod = dateEnd.slice(0, 7)")
    && unknownView.includes('previewUnknownRefundMatches(selectedPeriod)')
    && unknownView.includes('scanUnknownRefundMatches(selectedPeriod)')
    && !unknownView.includes('previewUnknownRefundMatches(context.period)')
    && !unknownView.includes('scanUnknownRefundMatches(context.period)'),
  'return matching should use the month selected by the arrival-date range instead of the global accounting period'
)
assert(
  unknownView.includes('if (startPeriod !== endPeriod)')
    && unknownView.includes('退汇识别按自然月执行，请将到账日期选择在同一个月份内'),
  'return matching should reject a date range spanning multiple natural months'
)
assert(
  unknownView.includes('return_match_status: filters.return_match_status')
    && refundView.includes('return_match_status: filters.return_match_status'),
  'both business lists should support filtering by the derived return-match status'
)
assert(
  refundView.includes('label="退汇状态"')
    && refundView.includes("CONFIRMED: '已确认退汇'"),
  'outgoing query should display a separate return status'
)
assert(
  refundView.includes('prop="out_status" label="出账状态"'),
  'return matching must retain the original outgoing status column'
)
assert(
  refundView.includes('已确认退汇的不生成凭证')
    && refundView.includes('v-model="skipConfirmedReturnVoucher"')
    && refundView.includes('configResult.skip_confirmed_return_voucher_flag')
    && refundView.includes('skipConfirmedReturnVoucher.value ? 1 : 0'),
  'refund subject config should expose and persist the confirmed-return voucher skip switch'
)
assert(
  api.includes('skipConfirmedReturnVoucherFlag?: number')
    && api.includes('payload.skip_confirmed_return_voucher_flag = skipConfirmedReturnVoucherFlag'),
  'case-fund API should send the confirmed-return voucher skip flag'
)
assert(
  dialog.includes('confirmUnknownRefundMatch')
    && dialog.includes('rejectUnknownRefundMatch')
    && dialog.includes('cancelUnknownRefundMatch'),
  'return-match details should support auditable confirm, reject and cancel actions'
)
for (const action of [
  '/caseFund/unknownRefundMatchPreview',
  '/caseFund/unknownRefundMatchScan',
  '/caseFund/unknownRefundMatchCandidates',
  '/caseFund/unknownRefundMatchConfirm',
  '/caseFund/unknownRefundMatchReject',
  '/caseFund/unknownRefundMatchCancel'
]) {
  assert(api.includes(action), `case-fund API should expose ${action}`)
}

console.log('Case fund return-match test passed')
