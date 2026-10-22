import * as XLSX from 'xlsx'
import type { VoucherExportRow } from '../types/api'

export const buildVoucherExportWorkbook = (rows: VoucherExportRow[]) => {
  const statuses: Record<string, string> = { DRAFT: '草稿', SUBMITTED: '未审核', AUDITED: '已审核', POSTED: '已记账', PRINTED: '已打印', VOIDED: '已作废' }
  const sources: Record<string, string> = { MANUAL: '手工录入', AUTO_CARRY: '自动结转', RED_REVERSAL: '红字冲销', BUSINESS: '业务生成', IMPORT: '批量导入' }
  const header = ['会计期间', '日期', '凭证号', '分录序号', '摘要', '科目编码', '科目名称', '辅助核算', '借方本币', '贷方本币', '附件数', '制单人', '审核人', '记账人', '状态', '已打印', '来源', '凭证ID']
  const data = rows.map((row) => [
    row.period, row.voucher_date, `${row.voucher_word}-${String(row.voucher_no).padStart(4, '0')}`,
    row.line_no, row.summary, String(row.subject_code), row.subject_name, row.aux_desc,
    Number(row.debit_amount), Number(row.credit_amount), row.attachment_count,
    row.prepared_by_name, row.audit_by_name, row.posted_by_name,
    statuses[row.status] || row.status, row.printed_flag === '1' ? '是' : '否',
    sources[row.source_type] || row.source_type, row.voucher_id,
  ])
  const sheet = XLSX.utils.aoa_to_sheet([header, ...data])
  sheet['!cols'] = [12, 12, 14, 10, 48, 16, 24, 60, 18, 18, 10, 14, 14, 14, 12, 10, 16, 38].map((wch) => ({ wch }))
  sheet['!autofilter'] = { ref: sheet['!ref']! }
  rows.forEach((_, index) => {
    for (const column of ['I', 'J']) {
      sheet[`${column}${index + 2}`].z = '#,##0.00;[Red]-#,##0.00'
    }
  })
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, sheet, '凭证明细')
  return workbook
}
