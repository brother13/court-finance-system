import { apiAction } from './http'
import type {
  CaseFundBankReconcile,
  CaseFundBankStatement,
  CaseFundDistribution,
  CaseFundJob,
  CaseFundJobCreateRequest,
  CaseFundJobErrorListResult,
  CaseFundJobListParams,
  CaseFundJobListResult,
  CaseFundPayment,
  CaseFundRefund,
  CaseFundReturnMatchItem,
  CaseFundReturnMatchStats,
  CaseFundSubjectConfig,
  CaseFundUnknownPayment
} from '../types/api'

type CaseFundVoucherBizType = 'PAYMENT' | 'PAYMENT_METHOD' | 'REFUND' | 'UNKNOWN_PAYMENT' | 'DISTRIBUTION'

export const caseFundApi = {
  paymentList(payload: Record<string, any> = {}) {
    return apiAction('/caseFund/paymentList', payload) as Promise<{ items: CaseFundPayment[]; total: number }>
  },
  importPayments(filename: string, contentBase64: string) {
    return apiAction('/caseFund/paymentImport', { filename, content_base64: contentBase64 }) as Promise<{
      total: number
      created: number
      updated: number
      skipped: number
    }>
  },
  deletePayments(paymentIds: string[]) {
    return apiAction('/caseFund/paymentDelete', { payment_ids: paymentIds }) as Promise<{ deleted_count: number }>
  },
  unknownPaymentList(payload: Record<string, any> = {}) {
    return apiAction('/caseFund/unknownPaymentList', payload) as Promise<{
      items: CaseFundUnknownPayment[]
      total: number
      total_amount?: string | number
    }>
  },
  importUnknownPayments(filename: string, contentBase64: string) {
    return apiAction('/caseFund/unknownPaymentImport', { filename, content_base64: contentBase64 }) as Promise<{
      total: number
      created: number
      skipped: number
    }>
  },
  deleteUnknownPayments(unknownPaymentIds: string[]) {
    return apiAction('/caseFund/unknownPaymentDelete', { unknown_payment_ids: unknownPaymentIds }) as Promise<{ deleted_count: number }>
  },
  previewUnknownRefundMatches(period: string) {
    return apiAction('/caseFund/unknownRefundMatchPreview', { period }) as Promise<{
      period: string
      stats: CaseFundReturnMatchStats
    }>
  },
  scanUnknownRefundMatches(period: string) {
    return apiAction('/caseFund/unknownRefundMatchScan', { period }) as Promise<{
      period: string
      stats: CaseFundReturnMatchStats
    }>
  },
  unknownRefundMatchCandidates(payload: { unknown_payment_id?: string; refund_id?: string }) {
    return apiAction('/caseFund/unknownRefundMatchCandidates', payload) as Promise<{
      items: CaseFundReturnMatchItem[]
      status: string
      candidate_count: number
    }>
  },
  confirmUnknownRefundMatch(matchId: string) {
    return apiAction('/caseFund/unknownRefundMatchConfirm', { match_id: matchId }) as Promise<{ match_id: string }>
  },
  rejectUnknownRefundMatch(matchId: string, remark = '') {
    return apiAction('/caseFund/unknownRefundMatchReject', { match_id: matchId, remark }) as Promise<{ match_id: string }>
  },
  cancelUnknownRefundMatch(matchId: string) {
    return apiAction('/caseFund/unknownRefundMatchCancel', { match_id: matchId }) as Promise<{ match_id: string }>
  },
  distributionList(payload: Record<string, any> = {}) {
    return apiAction('/caseFund/distributionList', payload) as Promise<{
      items: CaseFundDistribution[]
      total: number
      total_amount?: string | number
    }>
  },
  importDistributions(filename: string, contentBase64: string) {
    return apiAction('/caseFund/distributionImport', { filename, content_base64: contentBase64 }) as Promise<{
      total: number
      created: number
      skipped: number
    }>
  },
  deleteDistributions(distributionIds: string[]) {
    return apiAction('/caseFund/distributionDelete', { distribution_ids: distributionIds }) as Promise<{ deleted_count: number }>
  },
  refundList(payload: Record<string, any> = {}) {
    return apiAction('/caseFund/refundList', payload) as Promise<{ items: CaseFundRefund[]; total: number; total_amount?: string | number }>
  },
  importRefunds(filename: string, contentBase64: string) {
    return apiAction('/caseFund/refundImport', { filename, content_base64: contentBase64 }) as Promise<{
      total: number
      created: number
      skipped: number
    }>
  },
  deleteRefunds(refundIds: string[]) {
    return apiAction('/caseFund/refundDelete', { refund_ids: refundIds }) as Promise<{ deleted_count: number }>
  },
  bankStatementList(payload: Record<string, any> = {}) {
    return apiAction('/caseFund/bankStatementList', payload) as Promise<{
      items: CaseFundBankStatement[]
      total: number
      debit_amount?: string | number
      credit_amount?: string | number
      banks?: Record<string, string>
    }>
  },
  importBankStatements(bankCode: string, filename: string, rows: Partial<CaseFundBankStatement>[]) {
    return apiAction('/caseFund/bankStatementImport', { bank_code: bankCode, filename, rows }, {}, { timeout: 120000 }) as Promise<{
      total: number
      created: number
      skipped: number
    }>
  },
  deleteBankStatements(statementIds: string[]) {
    return apiAction('/caseFund/bankStatementDelete', { statement_ids: statementIds }) as Promise<{ deleted_count: number }>
  },
  runBankReconcile(payload: Record<string, any> = {}) {
    return apiAction('/caseFund/bankReconcileRun', payload) as Promise<{
      total: number
      counts: Record<string, number>
    }>
  },
  bankReconcileList(payload: Record<string, any> = {}) {
    return apiAction('/caseFund/bankReconcileList', payload) as Promise<{
      items: CaseFundBankReconcile[]
      total: number
      summary: Record<string, number>
    }>
  },
  subjectConfigList(voucherBizType: CaseFundVoucherBizType) {
    return apiAction('/caseFund/subjectConfigList', { voucher_biz_type: voucherBizType }) as Promise<{
      items: CaseFundSubjectConfig[]
      biz_type: string
      voucher_biz_type: CaseFundVoucherBizType
      generate_voucher_by_day_flag: number
      generate_payment_voucher_by_method_flag: number
      generate_refund_voucher_by_out_order_no_flag: number
      skip_confirmed_return_voucher_flag: number
      payment_method_items?: CaseFundSubjectConfig[]
    }>
  },
  saveSubjectConfigs(
    voucherBizType: CaseFundVoucherBizType,
    items: CaseFundSubjectConfig[],
    generateVoucherByDayFlag = 1,
    generateRefundVoucherByOutOrderNoFlag?: number,
    generatePaymentVoucherByMethodFlag?: number,
    paymentMethodItems?: CaseFundSubjectConfig[],
    skipConfirmedReturnVoucherFlag?: number
  ) {
    const payload: Record<string, any> = {
      voucher_biz_type: voucherBizType,
      generate_voucher_by_day_flag: generateVoucherByDayFlag,
      items
    }
    if (generateRefundVoucherByOutOrderNoFlag !== undefined) {
      payload.generate_refund_voucher_by_out_order_no_flag = generateRefundVoucherByOutOrderNoFlag
    }
    if (generatePaymentVoucherByMethodFlag !== undefined) {
      payload.generate_payment_voucher_by_method_flag = generatePaymentVoucherByMethodFlag
    }
    if (paymentMethodItems !== undefined) {
      payload.payment_method_items = paymentMethodItems
    }
    if (skipConfirmedReturnVoucherFlag !== undefined) {
      payload.skip_confirmed_return_voucher_flag = skipConfirmedReturnVoucherFlag
    }
    return apiAction('/caseFund/subjectConfigSave', payload) as Promise<{ saved: number }>
  },
  paymentGenerateVoucher(paymentIds: string[]) {
    return apiAction('/caseFund/paymentGenerateVoucher', { payment_ids: paymentIds }) as Promise<{
      generated_count: number
      payment_count: number
      vouchers: { voucher_id: string; voucher_no: number; period: string }[]
    }>
  },
  paymentGenerateVoucherByQuery(payload: Record<string, any>) {
    return apiAction('/caseFund/paymentGenerateVoucherByQuery', payload) as Promise<{
      generated_count: number
      payment_count: number
      vouchers: { voucher_id: string; voucher_no: number; period: string }[]
    }>
  },
  refundGenerateVoucher(refundIds: string[]) {
    return apiAction('/caseFund/refundGenerateVoucher', { refund_ids: refundIds }) as Promise<{
      generated_count: number
      refund_count: number
      vouchers: { voucher_id: string; voucher_no: number; period: string }[]
    }>
  },
  refundGenerateVoucherByQuery(payload: Record<string, any>) {
    return apiAction('/caseFund/refundGenerateVoucherByQuery', payload) as Promise<{
      generated_count: number
      refund_count: number
      vouchers: { voucher_id: string; voucher_no: number; period: string }[]
    }>
  },
  unknownPaymentGenerateVoucher(unknownPaymentIds: string[]) {
    return apiAction('/caseFund/unknownPaymentGenerateVoucher', { unknown_payment_ids: unknownPaymentIds }) as Promise<{
      generated_count: number
      unknown_payment_count: number
      vouchers: { voucher_id: string; voucher_no: number; period: string }[]
    }>
  },
  distributionGenerateVoucher(distributionIds: string[]) {
    return apiAction('/caseFund/distributionGenerateVoucher', { distribution_ids: distributionIds }) as Promise<{
      generated_count: number
      distribution_count: number
      vouchers: { voucher_id: string; voucher_no: number; period: string }[]
    }>
  },
  jobCreate(request: CaseFundJobCreateRequest) {
    return apiAction('/caseFund/jobCreate', request, {}, { timeout: 120000 }) as Promise<CaseFundJob>
  },
  jobStatus(jobId: string) {
    return apiAction('/caseFund/jobStatus', { job_id: jobId }) as Promise<CaseFundJob>
  },
  jobList(payload: CaseFundJobListParams = {}) {
    return apiAction('/caseFund/jobList', payload) as Promise<CaseFundJobListResult>
  },
  jobErrorList(jobId: string, payload: { page?: number; pagesize?: number } = {}) {
    return apiAction('/caseFund/jobErrorList', { job_id: jobId, ...payload }) as Promise<CaseFundJobErrorListResult>
  }
}
