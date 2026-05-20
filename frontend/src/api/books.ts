import { apiAction } from './http'
import type { AuxBalanceRow, Subject, SubjectSummaryRow } from '../types/api'

export const booksApi = {
  detailLedger(params: {
    period: string
    startPeriod?: string
    endPeriod?: string
    subjectCode?: string
    startDate: string
    endDate: string
    page?: number
    pagesize?: number
  }) {
    return apiAction('/book/detailLedger', {
      period: params.period,
      start_period: params.startPeriod,
      end_period: params.endPeriod,
      subject_code: params.subjectCode,
      start_date: params.startDate,
      end_date: params.endDate,
      page: params.page,
      pagesize: params.pagesize
    }) as Promise<{ items: any[]; total: number; debit_total: string | number; credit_total: string | number }>
  },
  subjectBalance(params: string | {
    period?: string
    startPeriod: string
    endPeriod: string
    subjectStartCode?: string
    subjectEndCode?: string
    levelStart?: number
    levelEnd?: number
    zeroFilter?: 'all' | 'nonzero' | 'active' | 'balance'
  }) {
    if (typeof params === 'string') {
      return apiAction('/book/subjectBalance', { period: params }) as Promise<any[]>
    }
    return apiAction('/book/subjectBalance', {
      period: params.period || params.endPeriod,
      start_period: params.startPeriod,
      end_period: params.endPeriod,
      subject_start_code: params.subjectStartCode,
      subject_end_code: params.subjectEndCode,
      level_start: params.levelStart,
      level_end: params.levelEnd,
      zero_filter: params.zeroFilter
    }) as Promise<any[]>
  },
  subjectSummary(params: {
    period: string
    startPeriod: string
    endPeriod: string
    subjectStartCode?: string
    subjectEndCode?: string
  }) {
    return apiAction('/book/subjectSummary', {
      period: params.period,
      start_period: params.startPeriod,
      end_period: params.endPeriod,
      subject_start_code: params.subjectStartCode,
      subject_end_code: params.subjectEndCode
    }) as Promise<SubjectSummaryRow[]>
  },
  auxBalance(params: {
    period: string
    subjectCode?: string
    caseNo?: string
    receiptNo?: string
  }) {
    return apiAction('/book/auxBalance', {
      period: params.period,
      subject_code: params.subjectCode,
      case_no: params.caseNo,
      receipt_no: params.receiptNo
    }) as Promise<{ items: AuxBalanceRow[]; total: number; period: string }>
  },
  auxBalanceSubjects() {
    return apiAction('/book/auxBalanceSubjects') as Promise<Subject[]>
  }
}
