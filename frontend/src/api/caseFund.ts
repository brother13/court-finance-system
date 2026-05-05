import { apiAction } from './http'
import type { CaseFundPayment, CaseFundRefund } from '../types/api'

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
  }
}
