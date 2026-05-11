<template>
  <div class="page-header">
    <div>
      <h1>科目汇总表</h1>
      <p>按日期范围、科目范围和科目级次汇总已审核凭证的借贷发生额,用于核对凭证汇总与试算平衡。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新数据</el-button>
      <el-button v-permission="'book:export'" type="primary" :icon="Download" @click="exportData">导出汇总表</el-button>
    </div>
  </div>

  <div class="search-filter-section">
    <div class="filter-grid subject-summary-filter">
      <el-date-picker
        v-model="dateRange"
        type="daterange"
        value-format="YYYY-MM-DD"
        range-separator="至"
        start-placeholder="开始日期"
        end-placeholder="结束日期"
        style="width: 100%"
      />
      <el-input v-model.trim="filters.subjectStartCode" placeholder="起始科目编码" :prefix-icon="Search" clearable />
      <el-input v-model.trim="filters.subjectEndCode" placeholder="结束科目编码" :prefix-icon="Search" clearable />
      <el-select v-model="filters.subjectLevel" placeholder="汇总级次">
        <el-option label="一级科目" :value="1" />
        <el-option label="二级科目" :value="2" />
        <el-option label="三级科目" :value="3" />
        <el-option label="四级科目" :value="4" />
      </el-select>
      <el-button type="primary" :icon="Search" @click="load">查询记录</el-button>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header">
      <strong>
        <el-icon><Collection /></el-icon>
        科目汇总表
      </strong>
      <span class="muted">
        共 {{ rows.length }} 个汇总科目 · 分录 {{ totalEntries }} 条 · 借方合计 ¥ {{ totalDebit.toFixed(2) }} · 贷方合计 ¥ {{ totalCredit.toFixed(2) }}
      </span>
    </div>
    <div class="panel-body compact">
      <el-table
        :data="rows"
        height="calc(100vh - 330px)"
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
            <span>{{ row.subject_name || '未维护名称' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="level_no" label="级次" width="80" align="center" />
        <el-table-column prop="entry_count" label="分录数" width="100" align="center" />
        <el-table-column label="本期发生额" align="center">
          <el-table-column prop="debit_amount" label="借方" width="160" align="right">
            <template #default="{ row }">
              <span :class="['text-mono', Number(row.debit_amount) > 0 ? 'amount-debit' : 'muted']">
                {{ amountText(row.debit_amount) }}
              </span>
            </template>
          </el-table-column>
          <el-table-column prop="credit_amount" label="贷方" width="160" align="right">
            <template #default="{ row }">
              <span :class="['text-mono', Number(row.credit_amount) > 0 ? 'amount-credit' : 'muted']">
                {{ amountText(row.credit_amount) }}
              </span>
            </template>
          </el-table-column>
        </el-table-column>
        <el-table-column prop="balance_amount" label="借贷净额" width="180" align="right">
          <template #default="{ row }">
            <span :class="['text-mono', balanceClass(row.balance_amount)]">
              {{ balanceText(row.balance_amount) }}
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
import { Collection, Download, Refresh, Search } from '@element-plus/icons-vue'
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
const dateRange = ref<[string, string]>(periodMonthRange(context.period))
const filters = reactive({
  subjectStartCode: '',
  subjectEndCode: '',
  subjectLevel: 1
})
const rows = ref<SubjectSummaryRow[]>([])

const totalDebit = computed(() => rows.value.reduce((sum, row) => sum + (Number(row.debit_amount) || 0), 0))
const totalCredit = computed(() => rows.value.reduce((sum, row) => sum + (Number(row.credit_amount) || 0), 0))
const totalBalance = computed(() => rows.value.reduce((sum, row) => sum + (Number(row.balance_amount) || 0), 0))
const totalEntries = computed(() => rows.value.reduce((sum, row) => sum + (Number(row.entry_count) || 0), 0))

const amountText = (val: string | number) => {
  const num = Number(val) || 0
  return num > 0 ? '¥ ' + num.toFixed(2) : '—'
}

const balanceText = (val: string | number) => {
  const num = Number(val) || 0
  if (Math.abs(num) < 0.001) return '—'
  return (num >= 0 ? '¥ ' : '-¥ ') + Math.abs(num).toFixed(2)
}

const balanceClass = (val: string | number) => {
  const num = Number(val) || 0
  if (Math.abs(num) < 0.001) return 'muted'
  return num >= 0 ? 'amount-debit' : 'amount-credit'
}

const summaryMethod = ({ columns }: { columns: any[] }) => {
  return columns.map((_, idx) => {
    if (idx === 0) return '合计'
    if (idx === 3) return totalEntries.value
    if (idx === 4) return '¥ ' + totalDebit.value.toFixed(2)
    if (idx === 5) return '¥ ' + totalCredit.value.toFixed(2)
    if (idx === 6) return balanceText(totalBalance.value)
    return ''
  })
}

const load = async () => {
  rows.value = await booksApi.subjectSummary({
    period: context.period,
    startDate: dateRange.value[0],
    endDate: dateRange.value[1],
    subjectStartCode: filters.subjectStartCode,
    subjectEndCode: filters.subjectEndCode,
    subjectLevel: filters.subjectLevel
  })
}

const openDetailLedger = (row: SubjectSummaryRow) => {
  router.push({
    path: '/books/detail-ledger',
    query: {
      year: context.year,
      period: context.period,
      subject_code: row.subject_code,
      start_date: dateRange.value[0],
      end_date: dateRange.value[1]
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
  const headers = ['科目编码', '科目名称', '级次', '分录数', '借方发生额', '贷方发生额', '借贷净额']
  const body = rows.value.map((row) => [
    row.subject_code,
    row.subject_name,
    row.level_no,
    row.entry_count,
    Number(row.debit_amount || 0),
    Number(row.credit_amount || 0),
    Number(row.balance_amount || 0)
  ])
  const totals = ['合计', '', '', totalEntries.value, totalDebit.value, totalCredit.value, totalBalance.value]
  const sheet = XLSX.utils.aoa_to_sheet([headers, ...body, totals])
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, sheet, '科目汇总表')
  XLSX.writeFile(workbook, `科目汇总表_${context.period}.xlsx`)
}

onMounted(load)
</script>

<style scoped>
.subject-summary-filter {
  grid-template-columns: minmax(260px, 1.4fr) minmax(150px, 0.8fr) minmax(150px, 0.8fr) minmax(130px, 0.6fr) auto;
}

@media (max-width: 1180px) {
  .subject-summary-filter {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
