import { apiAction } from './http'

export const booksApi = {
  detailLedger(params: { period: string; subjectCode?: string; startDate: string; endDate: string }) {
    return apiAction('/book/detailLedger', {
      period: params.period,
      subject_code: params.subjectCode,
      start_date: params.startDate,
      end_date: params.endDate
    }) as Promise<any[]>
  },
  subjectBalance(period: string) {
    return apiAction('/book/subjectBalance', { period }) as Promise<any[]>
  }
}
