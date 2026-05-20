<template>
  <div class="page-header subject-summary-header">
    <div>
      <h1>科目汇总表</h1>
      <p>{{ context.accountSetName || '当前账套' }} · {{ summaryFilterText }}</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新</el-button>
      <el-button :icon="Printer" :loading="printing" @click="printReport">打印</el-button>
      <el-button v-permission="'book:export'" :icon="Download" @click="exportData">导出</el-button>
    </div>
  </div>

  <div class="panel subject-summary-panel">
    <div class="panel-header summary-report-toolbar">
      <div class="summary-toolbar-left">
        <strong>
          <el-icon><Collection /></el-icon>
          科目汇总表
        </strong>
        <el-popover
          v-model:visible="filterDropdownVisible"
          placement="bottom-start"
          trigger="click"
          :width="720"
          popper-class="subject-summary-filter-popper"
        >
          <template #reference>
            <el-button type="primary" class="summary-filter-button">
              <span>{{ summaryFilterText }}</span>
              <el-icon class="filter-button-caret"><CaretBottom /></el-icon>
            </el-button>
          </template>
          <div class="summary-filter-popover">
            <el-form label-position="left" label-width="82px" class="summary-query-form">
              <div class="filter-form-row">
                <el-form-item label="会计期间">
                  <div class="period-range">
                    <el-select v-model="filters.startPeriod" placeholder="起始期间" :teleported="false">
                      <el-option v-for="period in periodOptions" :key="period" :label="periodLabel(period)" :value="period" />
                    </el-select>
                    <span>至</span>
                    <el-select v-model="filters.endPeriod" placeholder="结束期间" :teleported="false">
                      <el-option v-for="period in periodOptions" :key="period" :label="periodLabel(period)" :value="period" />
                    </el-select>
                  </div>
                </el-form-item>
              </div>
              <div class="filter-form-row two-columns">
                <el-form-item label="起始科目">
                  <el-input v-model.trim="filters.subjectStartCode" placeholder="科目编码" clearable />
                </el-form-item>
                <el-form-item label="结束科目">
                  <el-input v-model.trim="filters.subjectEndCode" placeholder="科目编码" clearable />
                </el-form-item>
              </div>
            </el-form>
            <div class="filter-popover-actions">
              <el-button type="primary" :icon="Search" @click="applyFilters">确定</el-button>
              <el-button @click="closeFilterDropdown">取消</el-button>
              <el-button :icon="Refresh" @click="resetFilters">重置</el-button>
            </div>
          </div>
        </el-popover>
      </div>
      <span class="muted">
        共 {{ rows.length }} 个汇总科目 · 分录 {{ totalEntries }} 条 · 借方合计 ¥ {{ totalDebit.toFixed(2) }} · 贷方合计 ¥ {{ totalCredit.toFixed(2) }}
      </span>
    </div>
    <div class="panel-body compact">
      <el-table
        class="subject-summary-table"
        :data="visibleRows"
        height="calc(100vh - 260px)"
        show-summary
        :summary-method="summaryMethod"
        @row-dblclick="openDetailLedger"
        @row-contextmenu="openDetailLedgerMenu"
      >
        <el-table-column prop="subject_code" label="科目编码" width="140" align="center">
          <template #default="{ row }">
            <span class="text-mono" style="color: var(--brand-blue); font-weight: 600">{{ row.subject_code }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="subject_name" label="科目名称" min-width="220">
          <template #default="{ row }">
            <span
              :class="['subject-name', hasChildSubject(row) ? 'subject-name-toggle' : '']"
              :style="{ paddingLeft: `${Math.max(0, Number(row.level_no || 1) - 1) * 18}px` }"
              @click.stop="toggleSubjectCollapse(row)"
            >
              <el-icon v-if="hasChildSubject(row)" class="tree-caret">
                <CaretRight v-if="isSubjectCollapsed(row)" />
                <CaretBottom v-else />
              </el-icon>
              <span v-else class="leaf-spacer" />
              {{ row.subject_name || '未维护名称' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="debit_amount" label="金额合计借方" width="180" align="right">
          <template #default="{ row }">
            <span :class="['text-mono', Number(row.debit_amount) > 0 ? 'amount-debit' : 'muted']">
              {{ amountText(row.debit_amount) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="credit_amount" label="金额合计贷方" width="180" align="right">
          <template #default="{ row }">
            <span :class="['text-mono', Number(row.credit_amount) > 0 ? 'amount-credit' : 'muted']">
              {{ amountText(row.credit_amount) }}
            </span>
          </template>
        </el-table-column>
      </el-table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { CaretBottom, CaretRight, Collection, Download, Printer, Refresh, Search } from '@element-plus/icons-vue'
import { booksApi } from '../../api/books'
import { useContextStore } from '../../stores/context'
import type { SubjectSummaryRow } from '../../types/api'

const context = useContextStore()
const router = useRouter()
const periodMonthRange = (periodValue: string): [string, string] => {
  const now = new Date()
  const matched = /^(\d{4})-(0[1-9]|1[0-2])$/.exec(periodValue)
  const year = matched ? Number(matched[1]) : now.getFullYear()
  const month = matched ? Number(matched[2]) : 1
  const period = `${year}-${String(month).padStart(2, '0')}`
  const lastDay = new Date(year, month, 0).getDate()
  return [`${period}-01`, `${period}-${String(lastDay).padStart(2, '0')}`]
}
const filterDropdownVisible = ref(false)
const filters = reactive({
  startPeriod: context.period,
  endPeriod: context.period,
  subjectStartCode: '',
  subjectEndCode: ''
})
const rows = ref<SubjectSummaryRow[]>([])
const collapsedSubjectCodes = ref<Set<string>>(new Set())
const printing = ref(false)
const subjectTypeOrder: Record<string, number> = {
  ASSET: 1,
  LIABILITY: 2,
  COMMON: 3,
  EQUITY: 4,
  COST: 5,
  PROFIT_LOSS: 6
}

const periodOptions = computed(() =>
  Array.from({ length: 12 }, (_, index) => `${context.year}-${String(index + 1).padStart(2, '0')}`)
)
const periodLabel = (period: string) => `${period.substring(0, 4)}年第${period.substring(5, 7)}期`

const isDescendantSubject = (row: SubjectSummaryRow, parent: SubjectSummaryRow | string) => {
  const parentCode = typeof parent === 'string' ? parent : parent.subject_code
  if (!parentCode || row.subject_code === parentCode) return false
  return row.subject_code.startsWith(parentCode)
}

const compareSubjectRows = (a: SubjectSummaryRow, b: SubjectSummaryRow) => {
  const aType = subjectTypeOrder[String(a.subject_type || '')] || 99
  const bType = subjectTypeOrder[String(b.subject_type || '')] || 99
  if (aType !== bType) return aType - bType
  return String(a.subject_code).localeCompare(String(b.subject_code), 'zh-CN', { numeric: true })
}

const parentCodeFor = (row: SubjectSummaryRow, allRows: SubjectSummaryRow[]) => {
  const code = String(row.subject_code || '')
  return allRows
    .filter((item) =>
      item.subject_code !== row.subject_code &&
      Number(item.level_no) < Number(row.level_no) &&
      code.startsWith(String(item.subject_code || ''))
    )
    .sort((a, b) => String(b.subject_code).length - String(a.subject_code).length)[0]?.subject_code || ''
}

const appendSubjectTree = (
  parentCode: string,
  childrenByParent: Map<string, SubjectSummaryRow[]>,
  result: SubjectSummaryRow[]
) => {
  const children = [...(childrenByParent.get(parentCode) || [])].sort(compareSubjectRows)
  for (const child of children) {
    result.push(child)
    appendSubjectTree(child.subject_code, childrenByParent, result)
  }
}

const orderedRows = computed(() => {
  const childrenByParent = new Map<string, SubjectSummaryRow[]>()
  for (const row of rows.value) {
    const parentCode = parentCodeFor(row, rows.value)
    const group = childrenByParent.get(parentCode) || []
    group.push(row)
    childrenByParent.set(parentCode, group)
  }
  const result: SubjectSummaryRow[] = []
  appendSubjectTree('', childrenByParent, result)
  return result
})

const hasChildSubject = (row: SubjectSummaryRow) => orderedRows.value.some((item) =>
  Number(item.level_no) > Number(row.level_no) &&
  isDescendantSubject(item, row)
)

const isSubjectCollapsed = (row: SubjectSummaryRow) => collapsedSubjectCodes.value.has(row.subject_code)

const toggleSubjectCollapse = (row: SubjectSummaryRow) => {
  if (!hasChildSubject(row)) return
  const next = new Set(collapsedSubjectCodes.value)
  if (next.has(row.subject_code)) {
    next.delete(row.subject_code)
  } else {
    next.add(row.subject_code)
  }
  collapsedSubjectCodes.value = next
}

const visibleRows = computed(() => orderedRows.value.filter((row) =>
  !Array.from(collapsedSubjectCodes.value).some((subjectCode) => isDescendantSubject(row, subjectCode))
))

const summaryRows = computed(() => {
  const leafRows = rows.value.filter((row) => Number(row.leaf_flag) === 1)
  return leafRows.length > 0 ? leafRows : rows.value
})

const totalDebit = computed(() => summaryRows.value.reduce((sum, row) => sum + (Number(row.debit_amount) || 0), 0))
const totalCredit = computed(() => summaryRows.value.reduce((sum, row) => sum + (Number(row.credit_amount) || 0), 0))
const totalEntries = computed(() => summaryRows.value.reduce((sum, row) => sum + (Number(row.entry_count) || 0), 0))
const summaryFilterText = computed(() => `${periodLabel(filters.startPeriod)} 至 ${periodLabel(filters.endPeriod)}`)
const queryDateRange = computed(() => {
  const [startDate] = periodMonthRange(filters.startPeriod)
  const [, endDate] = periodMonthRange(filters.endPeriod)
  return [startDate, endDate] as [string, string]
})
const moneyText = (value: string | number) => {
  const amount = Number(value) || 0
  return Math.abs(amount) < 0.001 ? '' : amount.toFixed(2)
}
const summaryReportRows = computed(() => orderedRows.value.map((row) => ({
  subject_code: row.subject_code,
  subject_name: `${'  '.repeat(Math.max(0, Number(row.level_no || 1) - 1))}${row.subject_name || '未维护名称'}`,
  debit_amount: moneyText(row.debit_amount),
  credit_amount: moneyText(row.credit_amount)
})))
const amountText = (val: string | number) => {
  const num = Number(val) || 0
  return num > 0 ? '¥ ' + num.toFixed(2) : '—'
}

const summaryMethod = ({ columns }: { columns: any[] }) => {
  return columns.map((_, idx) => {
    if (idx === 0) return '合计'
    if (idx === 2) return '¥ ' + totalDebit.value.toFixed(2)
    if (idx === 3) return '¥ ' + totalCredit.value.toFixed(2)
    return ''
  })
}

const resetFilters = () => {
  filters.startPeriod = context.period
  filters.endPeriod = context.period
  filters.subjectStartCode = ''
  filters.subjectEndCode = ''
  collapsedSubjectCodes.value = new Set()
  load()
}

const applyFilters = () => {
  filterDropdownVisible.value = false
  load()
}

const closeFilterDropdown = () => {
  filterDropdownVisible.value = false
}

const load = async () => {
  if (filters.startPeriod > filters.endPeriod) {
    ElMessage.warning('起始期间不能晚于结束期间')
    return
  }
  collapsedSubjectCodes.value = new Set()
  rows.value = await booksApi.subjectSummary({
    period: filters.endPeriod,
    startPeriod: filters.startPeriod,
    endPeriod: filters.endPeriod,
    subjectStartCode: filters.subjectStartCode,
    subjectEndCode: filters.subjectEndCode
  })
}

const openDetailLedger = (row: SubjectSummaryRow) => {
  router.push({
    path: '/books/detail-ledger',
    query: {
      year: context.year,
      period: filters.endPeriod,
      start_period: filters.startPeriod,
      end_period: filters.endPeriod,
      subject_code: row.subject_code,
      start_date: queryDateRange.value[0],
      end_date: queryDateRange.value[1]
    }
  })
}

const openDetailLedgerMenu = (row: SubjectSummaryRow, _column: unknown, event: MouseEvent) => {
  event.preventDefault()
  openDetailLedger(row)
}

const exportData = async () => {
  if (rows.value.length === 0) {
    ElMessage.warning('当前没有可导出的科目汇总数据')
    return
  }
  const XLSX = await import('xlsx')
  const headers = ['科目编码', '科目名称', '金额合计借方', '金额合计贷方']
  const body = summaryReportRows.value.map((row) => [
    row.subject_code,
    row.subject_name,
    Number(row.debit_amount || 0),
    Number(row.credit_amount || 0)
  ])
  const totals = ['合计', '', totalDebit.value, totalCredit.value]
  const sheet = XLSX.utils.aoa_to_sheet([['科目汇总表'], [context.accountSetName || '当前账套', summaryFilterText.value], headers, ...body, totals])
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, sheet, '科目汇总表')
  XLSX.writeFile(workbook, `科目汇总表_${filters.startPeriod}_${filters.endPeriod}.xlsx`)
}

const printReport = async () => {
  if (printing.value) return
  if (rows.value.length === 0) {
    ElMessage.warning('当前没有可打印的科目汇总数据')
    return
  }
  printing.value = true
  try {
    const pdf = await booksApi.subjectSummaryPdf({
      period: filters.endPeriod,
      startPeriod: filters.startPeriod,
      endPeriod: filters.endPeriod,
      subjectStartCode: filters.subjectStartCode,
      subjectEndCode: filters.subjectEndCode
    })
    const url = URL.createObjectURL(pdf)
    window.open(url, '_blank')
    ElMessage.success('科目汇总表PDF已生成')
  } catch (error: any) {
    ElMessage.error(error?.message || '生成科目汇总表PDF失败')
  } finally {
    printing.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.subject-summary-header {
  align-items: center;
}

.subject-summary-panel {
  border-radius: 8px;
}

.summary-report-toolbar {
  align-items: center;
  gap: 16px;
}

.summary-toolbar-left {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  min-width: 0;
}

.summary-filter-button {
  min-width: 290px;
  justify-content: space-between;
  font-weight: 600;
}

.filter-button-caret {
  margin-left: 10px;
}

.summary-filter-popover {
  padding: 4px 2px 2px;
}

.summary-query-form :deep(.el-form-item) {
  margin-bottom: 14px;
}

.summary-query-form :deep(.el-form-item__label) {
  color: var(--text-regular);
  font-weight: 600;
}

.summary-query-form :deep(.el-input),
.summary-query-form :deep(.el-select),
.summary-query-form :deep(.el-date-editor) {
  width: 100%;
}

.period-range {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 24px minmax(0, 1fr);
  gap: 8px;
  align-items: center;
  width: 100%;
}

.period-range span {
  color: var(--text-secondary);
  text-align: center;
}

.filter-form-row {
  width: 100%;
}

.filter-form-row.two-columns {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.filter-popover-actions {
  display: flex;
  justify-content: center;
  gap: 10px;
  padding-top: 2px;
}

:global(.subject-summary-filter-popper) {
  padding: 18px 20px 16px;
}

.subject-name {
  display: inline-flex;
  align-items: center;
  min-height: 24px;
}

.subject-name-toggle {
  cursor: pointer;
  user-select: none;
}

.subject-name-toggle:hover {
  color: var(--brand-blue-active);
}

.tree-caret {
  margin-right: 4px;
  color: var(--text-secondary);
  font-size: 13px;
}

.leaf-spacer {
  display: inline-block;
  width: 17px;
}

.subject-summary-table :deep(.el-table__cell) {
  padding: 4px 0;
}

.subject-summary-table :deep(.cell) {
  line-height: 20px;
}

@media (max-width: 1180px) {
  .filter-form-row.two-columns {
    grid-template-columns: 1fr 1fr;
  }
}

@media (max-width: 760px) {
  .filter-form-row.two-columns {
    grid-template-columns: 1fr;
  }

  .summary-filter-button {
    width: 100%;
    min-width: 0;
  }
}
</style>
