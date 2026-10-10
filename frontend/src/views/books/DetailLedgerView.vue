<template>
  <div class="page-header">
    <div>
      <h1>明细账</h1>
      <p>按科目与日期范围检索凭证明细,支持借贷金额与辅助核算项联动展示。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新数据</el-button>
      <el-button :icon="Download" :loading="downloadingPdf" @click="downloadPdf">下载PDF</el-button>
      <el-button v-permission="'book:export'" type="primary" :icon="Download" :loading="exportingExcel" @click="exportData">导出明细</el-button>
    </div>
  </div>

  <div class="search-filter-section">
    <div class="filter-grid">
      <el-input v-model="query.subjectCode" placeholder="科目编码" :prefix-icon="Search" clearable />
      <div class="detail-period-range">
        <span class="detail-period-label">会计期间</span>
        <el-select v-model="filters.startPeriod" placeholder="起始期间" :teleported="false">
          <el-option v-for="period in periodOptions" :key="period" :label="periodLabel(period)" :value="period" />
        </el-select>
        <span>至</span>
        <el-select v-model="filters.endPeriod" placeholder="结束期间" :teleported="false">
          <el-option v-for="period in periodOptions" :key="period" :label="periodLabel(period)" :value="period" />
        </el-select>
      </div>
      <el-button type="primary" :icon="Search" @click="queryLedger">查询记录</el-button>
    </div>
  </div>

  <div class="panel ledger-report-panel">
    <div class="panel-header ledger-report-toolbar">
      <div class="ledger-toolbar-left">
        <strong>
          <el-icon><Tickets /></el-icon>
          明细账记录 · {{ selectedSubjectLabel }}
        </strong>
      </div>
      <span class="muted">共 {{ pageTotal }} 条记录 · 借方合计 ¥ {{ totalDebit.toFixed(2) }} · 贷方合计 ¥ {{ totalCredit.toFixed(2) }}</span>
    </div>
    <div class="panel-body compact detail-ledger-body">
      <aside class="ledger-subject-panel">
        <div class="ledger-subject-header">科目</div>
        <el-tree
          class="ledger-subject-tree"
          :data="subjectTreeRows"
          node-key="subject_code"
          :props="{ label: 'label', children: 'children' }"
          default-expand-all
          highlight-current
          :current-node-key="query.subjectCode"
          @node-click="handleSubjectSelect"
        />
      </aside>
      <div class="ledger-table-panel">
        <el-table :data="rows" height="calc(100vh - 380px)" @row-dblclick="openVoucherDetail">
          <el-table-column prop="voucher_date" label="日期" width="120" align="center" />
          <el-table-column prop="voucher_no" label="凭证号" width="100" align="center">
            <template #default="{ row }">
              <span class="text-mono" style="color: var(--brand-blue); font-weight: 600">记-{{ String(row.voucher_no).padStart(4, '0') }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="summary" label="摘要" min-width="200" />
          <el-table-column prop="subject_code" label="科目" width="160">
            <template #default="{ row }">
              <span class="text-mono">{{ row.subject_code }}</span>
              <span class="muted" v-if="row.subject_name"> · {{ row.subject_name }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="debit_amount" label="借方金额" width="140" align="right">
            <template #default="{ row }">
              <span :class="['text-mono', Number(row.debit_amount) > 0 ? 'amount-debit' : 'muted']">
                {{ formatLedgerAmount(row.debit_amount) }}
              </span>
            </template>
          </el-table-column>
          <el-table-column prop="credit_amount" label="贷方金额" width="140" align="right">
            <template #default="{ row }">
              <span :class="['text-mono', Number(row.credit_amount) > 0 ? 'amount-credit' : 'muted']">
                {{ formatLedgerAmount(row.credit_amount) }}
              </span>
            </template>
          </el-table-column>
          <el-table-column prop="aux_desc" label="辅助核算" min-width="200">
            <template #default="{ row }">
              <span v-if="formatAuxDesc(row.aux_desc)">{{ formatAuxDesc(row.aux_desc) }}</span>
              <span v-else class="muted">—</span>
            </template>
          </el-table-column>
        </el-table>
        <div class="pagination-bar">
          <el-pagination
            v-model:current-page="page"
            v-model:page-size="pageSize"
            :page-sizes="[50, 100, 200]"
            :total="pageTotal"
            layout="total, sizes, prev, pager, next"
            @current-change="load"
            @size-change="handlePageSizeChange"
          />
        </div>
      </div>
    </div>
  </div>

  <el-dialog
    v-model="pdfProgressVisible"
    width="420px"
    align-center
    :show-close="false"
    :close-on-click-modal="false"
    :close-on-press-escape="false"
    class="pdf-progress-dialog"
  >
    <div class="pdf-progress-content">
      <strong>正在生成明细账PDF</strong>
      <p>{{ pdfProgressText }}</p>
      <el-progress :percentage="pdfProgressPercentage" :indeterminate="pdfProgressPercentage <= 0" :duration="2" />
    </div>
  </el-dialog>

  <el-dialog
    v-model="excelProgressVisible"
    width="420px"
    align-center
    :show-close="false"
    :close-on-click-modal="false"
    :close-on-press-escape="false"
    class="pdf-progress-dialog"
  >
    <div class="pdf-progress-content">
      <strong>正在导出明细Excel</strong>
      <p>{{ excelProgressText }}</p>
      <el-progress :percentage="excelProgressPercentage" :indeterminate="excelProgressPercentage <= 0" :duration="2" />
    </div>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Download, Refresh, Search, Tickets } from '@element-plus/icons-vue'
import { baseApi } from '../../api/base'
import { booksApi } from '../../api/books'
import { useContextStore } from '../../stores/context'
import type { Subject } from '../../types/api'

type SubjectTreeNode = Subject & {
  subject_code: string
  subject_name: string
  parent_code?: string
  label: string
  children?: SubjectTreeNode[]
}

const context = useContextStore()
const route = useRoute()
const router = useRouter()
const defaultSubjectCode = '1002'
const periodOptions = computed(() =>
  Array.from({ length: 12 }, (_, index) => `${context.year}-${String(index + 1).padStart(2, '0')}`)
)
const periodLabel = (period: string) => `${period.substring(0, 4)}年第${period.substring(5, 7)}期`
const periodMonthRange = (period: string): [string, string] => {
  const now = new Date()
  const matched = /^(\d{4})-(0[1-9]|1[0-2])$/.exec(period)
  const year = matched ? Number(matched[1]) : now.getFullYear()
  const month = matched ? Number(matched[2]) : 1
  const targetPeriod = `${year}-${String(month).padStart(2, '0')}`
  const lastDay = new Date(year, month, 0).getDate()
  return [`${targetPeriod}-01`, `${targetPeriod}-${String(lastDay).padStart(2, '0')}`]
}
const initialPeriod = String(route.query.period || context.period)
const filters = reactive({
  startPeriod: String(route.query.start_period || initialPeriod),
  endPeriod: String(route.query.end_period || initialPeriod)
})
const query = reactive({ subjectCode: String(route.query.subject_code || defaultSubjectCode) })
const rows = ref<any[]>([])
const subjects = ref<Subject[]>([])
const page = ref(1)
const pageSize = ref(100)
const pageTotal = ref(0)
const totalDebit = ref(0)
const totalCredit = ref(0)
const exportPageSize = 200
const downloadingPdf = ref(false)
const exportingExcel = ref(false)
const excelProgressVisible = ref(false)
const excelProgressText = ref('请稍候，系统正在读取明细账数据并生成Excel文件。')
const excelProgressPercentage = ref(0)
const pdfProgressVisible = ref(false)
const pdfProgressText = ref('请稍候，系统正在整理明细账数据并生成PDF文件。')
const pdfProgressPercentage = ref(0)

const subjectCodeOf = (subject: Subject) => String(subject.subject_code || subject.subjectCode || '')
const subjectNameOf = (subject: Subject) => String(subject.subject_name || subject.subjectName || '')
const parentCodeOf = (subject: Subject) => String(subject.parent_code || subject.parentCode || '')

const selectedSubjectLabel = computed(() => {
  const selected = subjects.value.find((subject) => subjectCodeOf(subject) === query.subjectCode)
  const name = selected ? subjectNameOf(selected) : ''
  return `${query.subjectCode}${name ? ' ' + name : ''}`
})
const filterSummaryText = computed(() => `${periodLabel(filters.startPeriod)} 至 ${periodLabel(filters.endPeriod)} · ${selectedSubjectLabel.value}`)

const subjectTreeRows = computed(() => {
  const activeSubjects = subjects.value.filter((subject) => Number(subject.status ?? 1) === 1)
  return buildSubjectTree(activeSubjects)
})

const buildSubjectTree = (items: Subject[]) => {
  const sorted = [...items].sort((a, b) => subjectCodeOf(a).localeCompare(subjectCodeOf(b), 'zh-CN', { numeric: true }))
  const nodeMap = new Map<string, SubjectTreeNode>()
  const roots: SubjectTreeNode[] = []
  sorted.forEach((subject) => {
    const code = subjectCodeOf(subject)
    if (!code) return
    const name = subjectNameOf(subject)
    nodeMap.set(code, {
      ...subject,
      subject_code: code,
      subject_name: name,
      parent_code: parentCodeOf(subject),
      label: `${code} ${name}`.trim(),
      children: []
    })
  })
  sorted.forEach((subject) => {
    const code = subjectCodeOf(subject)
    const node = nodeMap.get(code)
    if (!node) return
    const parent = node.parent_code ? nodeMap.get(node.parent_code) : null
    if (parent) {
      parent.children = parent.children || []
      parent.children.push(node)
    } else {
      roots.push(node)
    }
  })
  return roots
}

const formatAuxDesc = (auxDesc?: string) => {
  return String(auxDesc || '')
    .split(/[;；/]/)
    .map((part) => part.trim())
    .filter(Boolean)
    .map((part) => {
      const index = part.indexOf(':')
      return (index >= 0 ? part.slice(index + 1) : part).trim()
    })
    .filter(Boolean)
    .join('；')
}

const formatLedgerAmount = (value: unknown) => `¥ ${Number(value || 0).toFixed(2)}`

const fetchLedgerPage = async (targetPage: number, targetPageSize: number) => {
  const [startDate] = periodMonthRange(filters.startPeriod)
  const [, endDate] = periodMonthRange(filters.endPeriod)
  return booksApi.detailLedger({
    period: filters.endPeriod,
    startPeriod: filters.startPeriod,
    endPeriod: filters.endPeriod,
    subjectCode: query.subjectCode,
    startDate,
    endDate,
    page: targetPage,
    pagesize: targetPageSize
  })
}

const load = async () => {
  if (filters.startPeriod > filters.endPeriod) {
    ElMessage.warning('起始期间不能晚于结束期间')
    return
  }
  const result = await fetchLedgerPage(page.value, pageSize.value)
  rows.value = result.items || []
  pageTotal.value = Number(result.total || rows.value.length || 0)
  totalDebit.value = Number(result.debit_total || 0)
  totalCredit.value = Number(result.credit_total || 0)
}

const queryLedger = async () => {
  page.value = 1
  await load()
}

const loadAllLedgerRows = async (onProgress?: (loaded: number, total: number) => void) => {
  if (filters.startPeriod > filters.endPeriod) {
    ElMessage.warning('起始期间不能晚于结束期间')
    return { items: [], debitTotal: 0, creditTotal: 0 }
  }
  const firstPage = await fetchLedgerPage(1, exportPageSize)
  const items = [...(firstPage.items || [])]
  const total = Number(firstPage.total || items.length || 0)
  onProgress?.(items.length, total)
  const pageCount = Math.ceil(total / exportPageSize)
  for (let currentPage = 2; currentPage <= pageCount; currentPage += 1) {
    const result = await fetchLedgerPage(currentPage, exportPageSize)
    items.push(...(result.items || []))
    onProgress?.(items.length, total)
  }
  return {
    items,
    debitTotal: Number(firstPage.debit_total || 0),
    creditTotal: Number(firstPage.credit_total || 0)
  }
}

const handlePageSizeChange = async () => {
  page.value = 1
  await load()
}

const applyRouteQuery = () => {
  const routePeriod = String(route.query.period || context.period)
  filters.startPeriod = String(route.query.start_period || routePeriod)
  filters.endPeriod = String(route.query.end_period || routePeriod)
  query.subjectCode = String(route.query.subject_code || defaultSubjectCode)
  page.value = 1
}

const loadSubjects = async () => {
  subjects.value = await baseApi.subjects()
}

const handleSubjectSelect = (row: SubjectTreeNode) => {
  const code = subjectCodeOf(row)
  if (!code || code === query.subjectCode) return
  query.subjectCode = code
  queryLedger()
}

const openVoucherDetail = (row: any) => {
  if (!row.voucher_id || !row.period) {
    ElMessage.warning('当前明细缺少凭证信息,无法打开凭证')
    return
  }
  router.push({ path: `/vouchers/detail/${row.period}/${row.voucher_id}` })
}

const exportData = async () => {
  if (exportingExcel.value) return
  exportingExcel.value = true
  excelProgressVisible.value = true
  excelProgressPercentage.value = 0
  excelProgressText.value = '正在读取明细账数据。'
  try {
    const { items, debitTotal, creditTotal } = await loadAllLedgerRows((loaded, total) => {
      const safeTotal = Math.max(1, total)
      const percentage = Math.min(80, Math.max(1, Math.floor((loaded / safeTotal) * 80)))
      excelProgressPercentage.value = percentage
      excelProgressText.value = total > 0
        ? `正在读取明细账数据，已读取 ${Math.min(loaded, total)} / ${total} 条。`
        : '正在读取明细账数据。'
    })
    if (items.length === 0) {
      ElMessage.warning('当前没有可导出的明细账数据')
      return
    }
    excelProgressPercentage.value = 85
    excelProgressText.value = '正在整理Excel表格格式。'
    const XLSX = await import('xlsx')
  const headers = ['日期', '凭证号', '摘要', '科目编码', '科目名称', '借方金额', '贷方金额', '辅助核算']
  const body = items.map((row) => [
    row.voucher_date || '',
    row.voucher_no ? `记-${String(row.voucher_no).padStart(4, '0')}` : '',
    row.summary || '',
    row.subject_code || '',
    row.subject_name || '',
    Number(row.debit_amount || 0),
    Number(row.credit_amount || 0),
    formatAuxDesc(row.aux_desc)
  ])
  const totals = ['合计', '', '', '', '', debitTotal, creditTotal, '']
  const aoa = [
    ['明细账', '', '', '', '', '', '', ''],
    [`账套：${context.accountSetName || '当前账套'}    ${filterSummaryText.value}`, '', '', '', '', '', '', ''],
    headers,
    ...body,
    totals
  ]
  const sheet = XLSX.utils.aoa_to_sheet(aoa)
  sheet['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: headers.length - 1 } },
    { s: { r: 1, c: 0 }, e: { r: 1, c: headers.length - 1 } }
  ]
  sheet['!cols'] = [
    { wch: 11 },
    { wch: 10 },
    { wch: 42 },
    { wch: 12 },
    { wch: 18 },
    { wch: 14 },
    { wch: 14 },
    { wch: 24 }
  ]
  sheet['!rows'] = [
    { hpt: 24 },
    { hpt: 20 },
    { hpt: 22 }
  ]
  sheet['!autofilter'] = { ref: `A3:H${aoa.length}` }
  sheet['!margins'] = { left: 0.35, right: 0.35, top: 0.55, bottom: 0.45, header: 0.25, footer: 0.25 }
  sheet['!pageSetup'] = {
    paperSize: 9,
    orientation: 'landscape',
    fitToWidth: 1,
    fitToHeight: 0,
    scale: 85
  }
  const thinBorder = {
    top: { style: 'thin', color: { rgb: '000000' } },
    bottom: { style: 'thin', color: { rgb: '000000' } },
    left: { style: 'thin', color: { rgb: '000000' } },
    right: { style: 'thin', color: { rgb: '000000' } }
  }
  const baseCellStyle = {
    border: thinBorder,
    alignment: { vertical: 'center', wrapText: true },
    font: { name: '宋体', sz: 10 }
  }
  for (let rowIndex = 0; rowIndex < aoa.length; rowIndex += 1) {
    for (let colIndex = 0; colIndex < headers.length; colIndex += 1) {
      const address = XLSX.utils.encode_cell({ r: rowIndex, c: colIndex })
      const cell = (sheet[address] ||= { t: 's', v: '' }) as any
      cell.s = {
        ...baseCellStyle,
        alignment: {
          ...baseCellStyle.alignment,
          horizontal: colIndex >= 5 && rowIndex >= 3 ? 'right' : rowIndex <= 2 ? 'center' : 'left'
        }
      }
    }
  }
  ;(sheet.A1 as any).s = {
    ...baseCellStyle,
    alignment: { horizontal: 'center', vertical: 'center' },
    font: { name: '宋体', sz: 16, bold: true },
    border: thinBorder
  }
  ;(sheet.A2 as any).s = {
    ...baseCellStyle,
    alignment: { horizontal: 'left', vertical: 'center' },
    font: { name: '宋体', sz: 10 },
    border: thinBorder
  }
  headers.forEach((_, index) => {
    const address = XLSX.utils.encode_cell({ r: 2, c: index })
    const cell = sheet[address] as any
    if (cell) {
      cell.s = {
        ...baseCellStyle,
        alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
        font: { name: '宋体', sz: 10, bold: true },
        fill: { fgColor: { rgb: 'F2F2F2' } },
        border: thinBorder
      }
    }
  })
  for (let rowIndex = 4; rowIndex <= aoa.length; rowIndex += 1) {
    ;['F', 'G'].forEach((column) => {
      const cell = sheet[`${column}${rowIndex}`] as any
      if (cell) {
        cell.z = '#,##0.00'
        cell.s = {
          ...(cell.s || baseCellStyle),
          alignment: { horizontal: 'right', vertical: 'center' },
          border: thinBorder
        }
      }
    })
  }
  const totalRowNumber = aoa.length
  for (let colIndex = 0; colIndex < headers.length; colIndex += 1) {
    const address = XLSX.utils.encode_cell({ r: totalRowNumber - 1, c: colIndex })
    const cell = sheet[address] as any
    if (cell) {
      cell.s = {
        ...(cell.s || baseCellStyle),
        font: { name: '宋体', sz: 10, bold: true },
        fill: { fgColor: { rgb: 'F7F7F7' } },
        border: thinBorder
      }
    }
  }
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, sheet, '明细账')
  excelProgressPercentage.value = 96
  excelProgressText.value = '正在写入Excel文件。'
  XLSX.writeFile(workbook, `明细账_${query.subjectCode}_${filters.startPeriod}_${filters.endPeriod}.xlsx`)
  excelProgressPercentage.value = 100
  excelProgressText.value = 'Excel已生成。'
  ElMessage.success('明细账Excel已导出')
  } catch (error: any) {
    ElMessage.error(error?.message || '导出明细失败')
  } finally {
    excelProgressVisible.value = false
    exportingExcel.value = false
  }
}

const detailLedgerPdfParams = () => {
  const [startDate] = periodMonthRange(filters.startPeriod)
  const [, endDate] = periodMonthRange(filters.endPeriod)
  return {
    period: filters.endPeriod,
    startPeriod: filters.startPeriod,
    endPeriod: filters.endPeriod,
    subjectCode: query.subjectCode,
    startDate,
    endDate
  }
}

const downloadBlob = (blob: Blob, filename: string) => {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

const sleep = (ms: number) => new Promise((resolve) => window.setTimeout(resolve, ms))

const confirmLargePdfDownload = async (confirmButtonText = '继续下载PDF') => {
  const total = Number(pageTotal.value || 0)
  if (total < 3000) return true
  const estimatedPages = Math.max(1, Math.ceil(total / 30))
  try {
    await ElMessageBox.confirm(
      `当前筛选结果共有 ${total} 条明细，预计约 ${estimatedPages} 页，生成PDF会比较久。建议优先导出Excel；如需纸质归档，可继续下载PDF。`,
      '明细账PDF数据量较大',
      {
        confirmButtonText,
        cancelButtonText: '取消',
        type: 'warning'
      }
    )
    return true
  } catch {
    return false
  }
}

const downloadPdf = async () => {
  if (downloadingPdf.value) return
  if (pageTotal.value === 0) {
    ElMessage.warning('当前没有可下载的明细账数据')
    return
  }
  const confirmed = await confirmLargePdfDownload()
  if (!confirmed) return
  downloadingPdf.value = true
  pdfProgressPercentage.value = 0
  pdfProgressText.value = pageTotal.value >= 3000
    ? `当前共有 ${pageTotal.value} 条明细，后台正在生成PDF，请不要重复点击。`
    : '后台正在整理明细账数据并生成PDF文件。'
  pdfProgressVisible.value = true
  try {
    const job = await booksApi.startDetailLedgerPdfJob(detailLedgerPdfParams())
    const pdf = await pollDetailLedgerPdfJob(job.job_id)
    downloadBlob(pdf, `明细账_${query.subjectCode}_${filters.startPeriod}_${filters.endPeriod}.pdf`)
    ElMessage.success('明细账PDF已下载')
  } catch (error: any) {
    ElMessage.error(error?.message || '下载明细账PDF失败')
  } finally {
    pdfProgressVisible.value = false
    downloadingPdf.value = false
  }
}

const pollDetailLedgerPdfJob = async (jobId: string) => {
  for (let attempt = 0; attempt < 180; attempt += 1) {
    await sleep(attempt < 10 ? 1000 : 2000)
    const status = await booksApi.detailLedgerPdfJobStatus(jobId)
    const progress = Math.max(0, Math.min(100, Number(status.progress || 0)))
    pdfProgressPercentage.value = Math.max(pdfProgressPercentage.value, progress)
    if (status.status === 'DONE') {
      pdfProgressPercentage.value = 100
      pdfProgressText.value = 'PDF已生成，正在准备下载。'
      return booksApi.detailLedgerPdfJobDownload(jobId)
    }
    if (status.status === 'FAILED') {
      throw new Error(status.error || '后台生成PDF失败')
    }
    const pageText = status.total_pages
      ? `已生成 ${status.generated_pages || 0}/${status.total_pages} 页。`
      : ''
    pdfProgressText.value = `${status.message || (status.status === 'QUEUED' ? 'PDF任务排队中。' : '后台正在生成PDF，请稍候。')}${pageText ? ' ' + pageText : ''}`
  }
  throw new Error('PDF生成时间过长，请稍后重试')
}

watch(
  () => route.query,
  async () => {
    applyRouteQuery()
    await load()
  },
  { immediate: true }
)

watch(
  () => context.period,
  async (period) => {
    filters.startPeriod = period
    filters.endPeriod = period
    page.value = 1
    await load()
  }
)

onMounted(loadSubjects)
</script>

<style scoped>
.pdf-progress-content {
  display: grid;
  gap: 12px;
  padding: 4px 0 2px;
}

.pdf-progress-content strong {
  color: var(--text-primary);
  font-size: 16px;
}

.pdf-progress-content p {
  color: var(--text-secondary);
  line-height: 1.6;
  margin: 0;
}
</style>
