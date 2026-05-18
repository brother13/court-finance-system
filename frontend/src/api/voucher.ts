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
  printData(vouchers: { period: string; voucher_id: string }[]) {
    return apiAction('/voucher/printData', { vouchers }) as Promise<{
      account_set: {
        account_set_id: string
        set_code?: string
        set_name?: string
        paper_size: 'A4' | 'A5' | string
        voucher_print_line_count: number
        finance_manager?: string
      }
      paper_size: 'A4' | 'A5' | string
      voucher_print_line_count: number
      vouchers: any[]
    }>
  },
  import(payload: { vouchers: any[] }) {
    return apiAction('/voucher/import', payload, {}, { timeout: 120000 }) as Promise<{ success: number; failed: number; errors: string[] }>
  }
}
