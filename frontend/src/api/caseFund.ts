import { apiAction } from './http'
import type { CaseFundPayment, CaseFundRefund, CaseFundSubjectConfig } from '../types/api'

export const caseFundApi = {
  paymentList(payload: Record<string, any> = {}) {
    return apiAction('/caseFund/paymentList', payload) as Promise<{ items: CaseFundPayment[]; total: number }>
  },
  importPayments(filename: string, contentBase64: string) {
    return apiAction('/caseFund/paymentImport', { filename, content_base64: contentBase64 }) as Promise<{
      total: number
      created: number
      skipped: number
    }>
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
  }
}
