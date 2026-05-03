import { apiAction } from './http'
import type { Voucher } from '../types/api'

export const voucherApi = {
  nextNo(period: string) {
    return apiAction('/voucher/nextNo', { period }) as Promise<{ period: string; voucher_no: number }>
  },
  page(params: string | Record<string, any>) {
    const payload = typeof params === 'string' ? { period: params } : params
    return apiAction('/voucher/list', payload) as Promise<any>
  },
  detail(period: string, voucherId: string) {
    return apiAction('/voucher/info', { period, voucher_id: voucherId }) as Promise<Voucher>
  },
  saveDraft(payload: Voucher) {
    return apiAction('/voucher/draft', payload) as Promise<Voucher>
  },
  submit(payload: Voucher) {
    return apiAction('/voucher/submit', payload) as Promise<Voucher>
  },
  audit(period: string, voucherId: string) {
    return apiAction('/voucher/audit', { period, voucher_id: voucherId }) as Promise<void>
  },
  unaudit(period: string, voucherId: string) {
    return apiAction('/voucher/unaudit', { period, voucher_id: voucherId }) as Promise<void>
  }
}
