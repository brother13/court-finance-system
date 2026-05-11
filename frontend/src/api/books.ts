import { apiAction } from './http'
import type { AuxBalanceRow, Subject, SubjectSummaryRow } from '../types/api'

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
  },
  subjectSummary(params: {
    period: string
    startDate: string
    endDate: string
    subjectStartCode?: string
    subjectEndCode?: string
    subjectLevel?: number
  }) {
    return apiAction('/book/subjectSummary', {
      period: params.period,
      start_date: params.startDate,
      end_date: params.endDate,
      subject_start_code: params.subjectStartCode,
      subject_end_code: params.subjectEndCode,
      subject_level: params.subjectLevel
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
