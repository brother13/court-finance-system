import { apiAction } from './http'

export interface PeriodCheckItem {
  code: string
  message: string
  count?: number
}

export interface PeriodCloseCheckResult {
  period: string
  status: string
  can_close: number
  blocks: PeriodCheckItem[]
  warnings: PeriodCheckItem[]
}

export const periodApi = {
  list() {
    return apiAction('/period/list') as Promise<any[]>
  },
  closeCheck(period: string) {
    return apiAction('/period/closeCheck', { period }) as Promise<PeriodCloseCheckResult>
  },
  close(period: string) {
    return apiAction('/period/close', { period }) as Promise<{ period: string; status: string; next_period: string; warnings: PeriodCheckItem[] }>
  },
  reopen(period: string) {
    return apiAction('/period/reopen', { period }) as Promise<{ period: string; status: string }>
  },
  yearEnd(period: string) {
    return apiAction('/period/yearEnd', { period }) as Promise<{
      source_period: string
      next_year: number
      opening_period: string
      created_periods: number
      subject_opening_count: number
      aux_opening_count: number
    }>
  }
}
