<template>
  <div class="page-header subject-balance-header">
    <div>
      <h1>科目余额表</h1>
      <p>{{ context.accountSetName || '当前账套' }} · {{ filters.startPeriod }} 至 {{ filters.endPeriod }}</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新</el-button>
      <el-button :icon="Printer" :loading="printing" @click="printReport">打印</el-button>
      <el-button v-permission="'book:export'" :icon="Download" @click="exportData">导出</el-button>
    </div>
  </div>

  <div class="subject-balance-workspace">
    <section class="panel balance-report-panel">
      <div class="panel-header balance-report-toolbar">
        <div class="balance-toolbar-left">
          <strong>
            <el-icon><Notebook /></el-icon>
            科目余额表
          </strong>
          <el-popover
            v-model:visible="filterDropdownVisible"
            placement="bottom-start"
            trigger="click"
            :width="720"
            popper-class="subject-balance-filter-popper"
          >
            <template #reference>
              <el-button type="primary" class="balance-filter-button">
                <span>{{ filterSummaryText }}</span>
                <el-icon class="filter-button-caret"><CaretBottom /></el-icon>
              </el-button>
            </template>
            <div class="balance-filter-popover">
              <el-form label-position="left" label-width="82px" class="balance-query-form">
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
                <div class="filter-form-row two-columns">
                  <el-form-item label="科目级别">
                    <div class="level-range">
                      <el-select v-model="filters.levelStart" :teleported="false">
                        <el-option v-for="level in levelOptions" :key="level" :label="String(level)" :value="level" />
                      </el-select>
                      <span>至</span>
                      <el-select v-model="filters.levelEnd" :teleported="false">
                        <el-option v-for="level in levelOptions" :key="level" :label="String(level)" :value="level" />
                      </el-select>
                    </div>
                  </el-form-item>
                  <el-form-item label="显示范围">
                    <el-select v-model="filters.zeroFilter" :teleported="false">
                      <el-option label="全部科目" value="all" />
                      <el-option label="有金额科目" value="nonzero" />
                      <el-option label="有发生额科目" value="active" />
                      <el-option label="有期末余额科目" value="balance" />
                    </el-select>
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
          共 {{ rows.length }} 个科目 · 本期借方 {{ amountText(totalDebit, true) }} · 本期贷方 {{ amountText(totalCredit, true) }}
        </span>
      </div>
      <div class="panel-body compact">
        <el-table
          :data="balanceDisplayRows"
          height="calc(100vh - 260px)"
          show-summary
          :summary-method="summaryMethod"
          :row-class-name="tableRowClassName"
          border
        >
          <el-table-column prop="subject_code" label="科目编码" width="150" fixed>
            <template #default="{ row }">
              <span v-if="row.row_type === 'subtotal'" class="subtotal-label">{{ row.subject_code }}</span>
              <span v-else class="text-mono subject-code">{{ row.subject_code }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="subject_name" label="科目名称" min-width="230" fixed>
            <template #default="{ row }">
              <span v-if="row.row_type === 'subtotal'" class="muted">小计</span>
              <span
                v-else
                :class="['subject-name', hasChildSubject(row) ? 'subject-name-toggle' : '']"
                :style="{ paddingLeft: `${Math.max(0, Number(row.level_no || 1) - 1) * 18}px` }"
                @click="toggleSubjectCollapse(row)"
              >
                <el-icon v-if="hasChildSubject(row)" class="tree-caret">
                  <CaretRight v-if="isSubjectCollapsed(row)" />
                  <CaretBottom v-else />
                </el-icon>
                <span v-else class="leaf-spacer" />
                {{ row.subject_name }}
              </span>
            </template>
          </el-table-column>
          <el-table-column label="期初余额" align="center">
            <el-table-column prop="opening_debit_amount" label="借方" width="140" align="right">
              <template #default="{ row }">{{ amountText(row.opening_debit_amount) }}</template>
            </el-table-column>
            <el-table-column prop="opening_credit_amount" label="贷方" width="140" align="right">
              <template #default="{ row }">{{ amountText(row.opening_credit_amount) }}</template>
            </el-table-column>
          </el-table-column>
          <el-table-column label="本期发生额" align="center">
            <el-table-column prop="debit_amount" label="借方" width="150" align="right">
              <template #default="{ row }">{{ amountText(row.debit_amount) }}</template>
            </el-table-column>
            <el-table-column prop="credit_amount" label="贷方" width="150" align="right">
              <template #default="{ row }">{{ amountText(row.credit_amount) }}</template>
            </el-table-column>
          </el-table-column>
          <el-table-column label="本年累计发生额" align="center">
            <el-table-column prop="year_debit_amount" label="借方" width="150" align="right">
              <template #default="{ row }">{{ amountText(row.year_debit_amount) }}</template>
            </el-table-column>
            <el-table-column prop="year_credit_amount" label="贷方" width="150" align="right">
              <template #default="{ row }">{{ amountText(row.year_credit_amount) }}</template>
            </el-table-column>
          </el-table-column>
          <el-table-column label="期末余额" align="center">
            <el-table-column prop="ending_debit_amount" label="借方" width="150" align="right">
              <template #default="{ row }">{{ amountText(row.ending_debit_amount) }}</template>
            </el-table-column>
            <el-table-column prop="ending_credit_amount" label="贷方" width="150" align="right">
              <template #default="{ row }">{{ amountText(row.ending_credit_amount) }}</template>
            </el-table-column>
          </el-table-column>
        </el-table>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { CaretBottom, CaretRight, Download, Notebook, Printer, Refresh, Search } from '@element-plus/icons-vue'
import { booksApi } from '../../api/books'
import { useContextStore } from '../../stores/context'

type BalanceRow = {
  subject_code: string
  subject_name: string
  subject_type?: string
  level_no: number
  leaf_flag: number
  row_type?: 'subject' | 'subtotal'
  opening_debit_amount: string | number
  opening_credit_amount: string | number
  debit_amount: string | number
  credit_amount: string | number
  year_debit_amount: string | number
  year_credit_amount: string | number
  ending_debit_amount: string | number
  ending_credit_amount: string | number
}

const context = useContextStore()
const levelOptions = [1, 2, 3, 4]
const amountFields = [
  'opening_debit_amount',
  'opening_credit_amount',
  'debit_amount',
  'credit_amount',
  'year_debit_amount',
  'year_credit_amount',
  'ending_debit_amount',
  'ending_credit_amount'
] as const

const rows = ref<BalanceRow[]>([])
const collapsedSubjectCodes = ref<Set<string>>(new Set())
const filterDropdownVisible = ref(false)
const printing = ref(false)
const filters = reactive({
  startPeriod: context.period,
  endPeriod: context.period,
  subjectStartCode: '',
  subjectEndCode: '',
  levelStart: 1,
  levelEnd: 4,
  zeroFilter: 'all' as 'all' | 'nonzero' | 'active' | 'balance'
})

const periodOptions = computed(() =>
  Array.from({ length: 12 }, (_, index) => `${context.year}-${String(index + 1).padStart(2, '0')}`)
)

const typeLabels: Record<string, string> = {
  ASSET: '资产',
  LIABILITY: '负债',
  COMMON: '共同',
  EQUITY: '所有者权益',
  COST: '成本',
  PROFIT_LOSS: '损益'
}

const periodLabel = (period: string) => `${period.substring(0, 4)}年第${period.substring(5, 7)}期`
const numericAmount = (value: string | number | undefined) => Number(value || 0)

const amountText = (value: string | number | undefined, keepZero = false) => {
  const amount = numericAmount(value)
  if (!keepZero && Math.abs(amount) < 0.001) return ''
  return Math.abs(amount).toLocaleString('zh-CN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  })
}

const makeSubtotalRow = (typeCode: string, groupRows: BalanceRow[]): BalanceRow => {
  const leafRows = groupRows.filter((row) => Number(row.leaf_flag) === 1)
  const sourceRows = leafRows.length > 0 ? leafRows : groupRows
  const subtotal = {
    row_type: 'subtotal' as const,
    subject_code: `${typeLabels[typeCode] || '未分类'}小计`,
    subject_name: '',
    subject_type: typeCode,
    level_no: 0,
    leaf_flag: 0
  } as BalanceRow
  for (const field of amountFields) {
    subtotal[field] = sourceRows.reduce((sum, row) => sum + numericAmount(row[field]), 0).toFixed(2)
  }
  return subtotal
}

const isDescendantSubject = (row: BalanceRow, parent: BalanceRow | string) => {
  const parentCode = typeof parent === 'string' ? parent : parent.subject_code
  if (!parentCode || row.subject_code === parentCode) return false
  return row.subject_code.startsWith(parentCode)
}

const hasChildSubject = (row: BalanceRow) => {
  if (row.row_type === 'subtotal') return false
  return rows.value.some((item) =>
    Number(item.level_no) > Number(row.level_no) &&
    isDescendantSubject(item, row)
  )
}

const isSubjectCollapsed = (row: BalanceRow) => collapsedSubjectCodes.value.has(row.subject_code)

const toggleSubjectCollapse = (row: BalanceRow) => {
  if (!hasChildSubject(row)) return
  const next = new Set(collapsedSubjectCodes.value)
  if (next.has(row.subject_code)) {
    next.delete(row.subject_code)
  } else {
    next.add(row.subject_code)
  }
  collapsedSubjectCodes.value = next
}

const visibleSubjectRows = computed(() => rows.value.filter((row) =>
  !Array.from(collapsedSubjectCodes.value).some((subjectCode) => isDescendantSubject(row, subjectCode))
))

const balanceDisplayRows = computed(() => {
  const displayRows: BalanceRow[] = []
  let currentType = ''
  let groupRows: BalanceRow[] = []
  const flushGroup = () => {
    if (groupRows.length === 0) return
    displayRows.push(makeSubtotalRow(currentType, groupRows))
    groupRows = []
  }

  for (const row of visibleSubjectRows.value) {
    const rowType = row.subject_type || 'OTHER'
    if (groupRows.length > 0 && rowType !== currentType) {
      flushGroup()
    }
    currentType = rowType
    displayRows.push({ ...row, row_type: 'subject' })
    groupRows.push(row)
  }
  flushGroup()
  return displayRows
})

const summaryRows = computed(() => {
  const leafRows = rows.value.filter((row) => Number(row.leaf_flag) === 1)
  return leafRows.length > 0 ? leafRows : rows.value
})

const sumAmount = (field: string) => summaryRows.value.reduce((sum, row) => sum + numericAmount(row[field as keyof BalanceRow] as any), 0)
const totalDebit = computed(() => sumAmount('debit_amount'))
const totalCredit = computed(() => sumAmount('credit_amount'))
const filterSummaryText = computed(() => `${periodLabel(filters.startPeriod)} 至 ${periodLabel(filters.endPeriod)}`)
const moneyText = (value: string | number | undefined) => {
  const amount = numericAmount(value)
  return Math.abs(amount) < 0.001 ? '' : amount.toFixed(2)
}
const balanceReportRows = computed(() => balanceDisplayRows.value.map((row) => ({
  subject_code: row.subject_code,
  subject_name: row.row_type === 'subtotal' ? '小计' : `${'  '.repeat(Math.max(0, Number(row.level_no || 1) - 1))}${row.subject_name}`,
  opening_debit_amount: moneyText(row.opening_debit_amount),
  opening_credit_amount: moneyText(row.opening_credit_amount),
  debit_amount: moneyText(row.debit_amount),
  credit_amount: moneyText(row.credit_amount),
  year_debit_amount: moneyText(row.year_debit_amount),
  year_credit_amount: moneyText(row.year_credit_amount),
  ending_debit_amount: moneyText(row.ending_debit_amount),
  ending_credit_amount: moneyText(row.ending_credit_amount)
})))

const summaryMethod = ({ columns }: { columns: any[] }) => {
  return columns.map((column, idx) => {
    if (idx === 0) return '合计'
    const property = column.property
    if (amountFields.includes(property)) {
      return amountText(sumAmount(property), true)
    }
    return ''
  })
}

const tableRowClassName = ({ row }: { row: BalanceRow }) => {
  return row.row_type === 'subtotal' ? 'balance-subtotal-row' : ''
}

const resetFilters = () => {
  filters.startPeriod = context.period
  filters.endPeriod = context.period
  filters.subjectStartCode = ''
  filters.subjectEndCode = ''
  filters.levelStart = 1
  filters.levelEnd = 4
  filters.zeroFilter = 'all'
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
  rows.value = await booksApi.subjectBalance({
    period: filters.endPeriod,
    startPeriod: filters.startPeriod,
    endPeriod: filters.endPeriod,
    subjectStartCode: filters.subjectStartCode,
    subjectEndCode: filters.subjectEndCode,
    levelStart: filters.levelStart,
    levelEnd: filters.levelEnd,
    zeroFilter: filters.zeroFilter
  }) as BalanceRow[]
}

const exportData = async () => {
  if (rows.value.length === 0) {
    ElMessage.warning('当前没有可导出的科目余额数据')
    return
  }
  const XLSX = await import('xlsx')
  const headers = ['科目编码', '科目名称', '期初借方', '期初贷方', '本期借方', '本期贷方', '本年累计借方', '本年累计贷方', '期末借方', '期末贷方']
  const body = balanceReportRows.value.map((row) => [
    row.subject_code,
    row.subject_name,
    Number(row.opening_debit_amount || 0),
    Number(row.opening_credit_amount || 0),
    Number(row.debit_amount || 0),
    Number(row.credit_amount || 0),
    Number(row.year_debit_amount || 0),
    Number(row.year_credit_amount || 0),
    Number(row.ending_debit_amount || 0),
    Number(row.ending_credit_amount || 0)
  ])
  const totals = ['合计', '', sumAmount('opening_debit_amount'), sumAmount('opening_credit_amount'), sumAmount('debit_amount'), sumAmount('credit_amount'), sumAmount('year_debit_amount'), sumAmount('year_credit_amount'), sumAmount('ending_debit_amount'), sumAmount('ending_credit_amount')]
  const sheet = XLSX.utils.aoa_to_sheet([['科目余额表'], [context.accountSetName || '当前账套', filterSummaryText.value], headers, ...body, totals])
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, sheet, '科目余额表')
  XLSX.writeFile(workbook, `科目余额表_${filters.startPeriod}_${filters.endPeriod}.xlsx`)
}

const printReport = async () => {
  if (printing.value) return
  if (rows.value.length === 0) {
    ElMessage.warning('当前没有可打印的科目余额数据')
    return
  }
  printing.value = true
  try {
    const pdf = await booksApi.subjectBalancePdf({
      period: filters.endPeriod,
      startPeriod: filters.startPeriod,
      endPeriod: filters.endPeriod,
      subjectStartCode: filters.subjectStartCode,
      subjectEndCode: filters.subjectEndCode,
      levelStart: filters.levelStart,
      levelEnd: filters.levelEnd,
      zeroFilter: filters.zeroFilter
    })
    const url = URL.createObjectURL(pdf)
    window.open(url, '_blank')
    ElMessage.success('科目余额表PDF已生成')
  } catch (error: any) {
    ElMessage.error(error?.message || '生成科目余额表PDF失败')
  } finally {
    printing.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.subject-balance-header {
  align-items: center;
}

.subject-balance-workspace {
  min-width: 0;
}

:global(.subject-balance-filter-popper) {
  padding: 18px 20px 16px;
}

.subtotal-label {
  font-weight: 700;
  color: var(--text-main);
}

:deep(.balance-subtotal-row) {
  background: #f8fafc;
  font-weight: 700;
}

:deep(.balance-subtotal-row td) {
  border-top: 1px solid #d8e0ea;
}

:deep(.el-table th.el-table__cell) {
  background: #f4f7fb;
  color: var(--text-regular);
  font-weight: 700;
}

:deep(.el-table .cell) {
  font-variant-numeric: tabular-nums;
}
</style>
