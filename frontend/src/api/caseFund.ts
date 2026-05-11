import { apiAction } from './http'
import type { CaseFundBankReconcile, CaseFundBankStatement, CaseFundPayment, CaseFundRefund, CaseFundSubjectConfig } from '../types/api'

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
    return apiAction('/caseFund/bankStatementImport', { bank_code: bankCode, filename, rows }) as Promise<{
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
  subjectConfigList(voucherBizType: 'PAYMENT' | 'REFUND') {
    return apiAction('/caseFund/subjectConfigList', { voucher_biz_type: voucherBizType }) as Promise<{
      items: CaseFundSubjectConfig[]
      biz_type: string
      voucher_biz_type: 'PAYMENT' | 'REFUND'
      generate_voucher_by_day_flag: number
    }>
  },
  saveSubjectConfigs(voucherBizType: 'PAYMENT' | 'REFUND', items: CaseFundSubjectConfig[], generateVoucherByDayFlag = 1) {
    return apiAction('/caseFund/subjectConfigSave', {
      voucher_biz_type: voucherBizType,
      generate_voucher_by_day_flag: generateVoucherByDayFlag,
      items
    }) as Promise<{ saved: number }>
  },
  paymentGenerateVoucher(paymentIds: string[]) {
    return apiAction('/caseFund/paymentGenerateVoucher', { payment_ids: paymentIds }) as Promise<{
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
  }
}
