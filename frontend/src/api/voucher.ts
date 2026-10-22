import { apiAction, apiBlobAction } from './http'
import type { Voucher, VoucherExportResult } from '../types/api'

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
  exportData(params: Record<string, any>) {
    return apiAction<VoucherExportResult>('/voucher/exportData', params, {}, { timeout: 120000 })
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
  batchAudit(period: string, voucherIds: string[]) {
    return apiAction('/voucher/batchAudit', { period, voucher_ids: voucherIds }) as Promise<{ audited_count: number }>
  },
  unaudit(period: string, voucherId: string) {
    return apiAction('/voucher/unaudit', { period, voucher_id: voucherId }) as Promise<void>
  },
  batchUnaudit(period: string, voucherIds: string[]) {
    return apiAction('/voucher/batchUnaudit', { period, voucher_ids: voucherIds }) as Promise<{ unaudited_count: number }>
  },
  post(period: string, voucherId: string) {
    return apiAction('/voucher/post', { period, voucher_id: voucherId }) as Promise<{ posted_count: number }>
  },
  batchPost(period: string, voucherIds: string[]) {
    return apiAction('/voucher/batchPost', { period, voucher_ids: voucherIds }) as Promise<{ posted_count: number }>
  },
  unpost(period: string, voucherId: string) {
    return apiAction('/voucher/unpost', { period, voucher_id: voucherId }) as Promise<{ unposted_count: number }>
  },
  batchUnpost(period: string, voucherIds: string[]) {
    return apiAction('/voucher/batchUnpost', { period, voucher_ids: voucherIds }) as Promise<{ unposted_count: number }>
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
  printPdf(vouchers: { period: string; voucher_id: string }[]) {
    return apiBlobAction('/voucher/printPdf', { vouchers }, {}, { timeout: 120000 })
  },
  import(payload: { vouchers: any[] }) {
    return apiAction('/voucher/import', payload, {}, { timeout: 120000 }) as Promise<{ success: number; failed: number; errors: string[] }>
  },
  renumberPreview(payload: { period: string; start_no?: number }) {
    return apiAction('/voucher/renumberPreview', payload) as Promise<{
      period: string
      start_no: number
      voucher_count: number
      change_count: number
      locked_count: number
      has_gap: boolean
      items: any[]
    }>
  },
  renumber(payload: { period: string; start_no?: number }) {
    return apiAction('/voucher/renumber', payload, {}, { timeout: 60000 }) as Promise<{ period: string; renumbered_count: number; items: any[] }>
  }
}
