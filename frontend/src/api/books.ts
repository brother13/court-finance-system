import { apiAction, apiBlobAction } from './http'
import type { AuxBalanceResult, CaseMovementDetailResult, Subject, SubjectSummaryRow } from '../types/api'

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
  detailLedgerPdf(params: {
    period: string
    startPeriod?: string
    endPeriod?: string
    subjectCode?: string
    startDate: string
    endDate: string
    disposition?: 'inline' | 'attachment'
  }) {
    return apiBlobAction('/book/detailLedgerPdf', {
      period: params.period,
      start_period: params.startPeriod,
      end_period: params.endPeriod,
      subject_code: params.subjectCode,
      start_date: params.startDate,
      end_date: params.endDate,
      disposition: params.disposition
    }, {}, { timeout: 300000 })
  },
  startDetailLedgerPdfJob(params: {
    period: string
    startPeriod?: string
    endPeriod?: string
    subjectCode?: string
    startDate: string
    endDate: string
  }) {
    return apiAction('/book/detailLedgerPdfJob', {
      period: params.period,
      start_period: params.startPeriod,
      end_period: params.endPeriod,
      subject_code: params.subjectCode,
      start_date: params.startDate,
      end_date: params.endDate,
      disposition: 'attachment'
    }) as Promise<{
      job_id: string
      status: string
      message?: string
      error?: string
      file_size?: number
      progress?: number
      total_rows?: number
      generated_pages?: number
      total_pages?: number
    }>
  },
  detailLedgerPdfJobStatus(jobId: string) {
    return apiAction('/book/detailLedgerPdfJobStatus', { job_id: jobId }) as Promise<{
      job_id: string
      status: 'QUEUED' | 'RUNNING' | 'DONE' | 'FAILED'
      message?: string
      error?: string
      file_size?: number
      progress?: number
      total_rows?: number
      generated_pages?: number
      total_pages?: number
    }>
  },
  detailLedgerPdfJobDownload(jobId: string) {
    return apiBlobAction('/book/detailLedgerPdfJobDownload', { job_id: jobId }, {}, { timeout: 120000 })
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
  subjectBalancePdf(params: {
    period: string
    startPeriod: string
    endPeriod: string
    subjectStartCode?: string
    subjectEndCode?: string
    levelStart?: number
    levelEnd?: number
    zeroFilter?: 'all' | 'nonzero' | 'active' | 'balance'
  }) {
    return apiBlobAction('/book/subjectBalancePdf', {
      period: params.period,
      start_period: params.startPeriod,
      end_period: params.endPeriod,
      subject_start_code: params.subjectStartCode,
      subject_end_code: params.subjectEndCode,
      level_start: params.levelStart,
      level_end: params.levelEnd,
      zero_filter: params.zeroFilter
    }, {}, { timeout: 60000 })
  },
  subjectSummaryPdf(params: {
    period: string
    startPeriod: string
    endPeriod: string
    subjectStartCode?: string
    subjectEndCode?: string
  }) {
    return apiBlobAction('/book/subjectSummaryPdf', {
      period: params.period,
      start_period: params.startPeriod,
      end_period: params.endPeriod,
      subject_start_code: params.subjectStartCode,
      subject_end_code: params.subjectEndCode
    }, {}, { timeout: 60000 })
  },
  auxBalance(params: {
    period: string
    subjectCode?: string
    caseNo?: string
    receiptNo?: string
    onlyMonitor?: boolean
    page?: number
    pagesize?: number
    exportAll?: boolean
    refreshSnapshot?: boolean
  }) {
    return apiAction('/book/auxBalance', {
      period: params.period,
      subject_code: params.subjectCode,
      case_no: params.caseNo,
      receipt_no: params.receiptNo,
      only_monitor: params.onlyMonitor ? 1 : 0,
      page: params.page,
      pagesize: params.pagesize,
      export_all: params.exportAll ? 1 : 0,
      refresh_snapshot: params.refreshSnapshot ? 1 : 0
    }) as Promise<AuxBalanceResult>
  },
  auxBalanceSubjects() {
    return apiAction('/book/auxBalanceSubjects') as Promise<Subject[]>
  },
  caseMovementDetail(params: {
    period: string
    subjectCode?: string
    caseNo?: string
    receiptNo?: string
    page?: number
    pagesize?: number
    refreshSnapshot?: boolean
  }) {
    return apiAction('/book/caseMovementDetail', {
      period: params.period,
      subject_code: params.subjectCode,
      case_no: params.caseNo,
      receipt_no: params.receiptNo,
      page: params.page,
      pagesize: params.pagesize,
      refresh_snapshot: params.refreshSnapshot ? 1 : 0
    }) as Promise<CaseMovementDetailResult>
  }
}
