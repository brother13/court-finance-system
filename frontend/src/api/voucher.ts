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
  save(payload: Voucher) {
    return apiAction('/voucher/save', payload) as Promise<Voucher>
  },
  remove(period: string, voucherId: string) {
    return apiAction('/voucher/delete', { period, voucher_id: voucherId }) as Promise<{ deleted_count: number }>
  },
  batchRemove(period: string, voucherIds: string[]) {
    return apiAction('/voucher/batchDelete', { period, voucher_ids: voucherIds }) as Promise<{ deleted_count: number }>
  },
  audit(period: string, voucherId: string) {
    return apiAction('/voucher/audit', { period, voucher_id: voucherId }) as Promise<void>
  },
  unaudit(period: string, voucherId: string) {
    return apiAction('/voucher/unaudit', { period, voucher_id: voucherId }) as Promise<void>
  },
  import(payload: { vouchers: any[] }) {
    return apiAction('/voucher/import', payload) as Promise<{ success: number; failed: number; errors: string[] }>
  }
}
