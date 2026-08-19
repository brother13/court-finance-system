<template>
  <div class="page-header">
    <div>
      <h1>辅助核算余额表</h1>
      <p>按案号分页查看辅助核算期初、累计发生额与期末余额；逐笔收支请使用案号收支明细表。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" :loading="loading" @click="refreshData">刷新数据</el-button>
      <el-button v-permission="'book:export'" type="primary" :icon="Download" :loading="exporting" @click="exportData">导出余额表</el-button>
    </div>
  </div>

  <div class="search-filter-section">
    <div class="filter-grid aux-balance-filter">
      <el-select
        v-model="filters.subjectCode"
        filterable
        clearable
        :loading="subjectLoading"
        placeholder="当前账套辅助科目"
      >
        <el-option
          v-for="subject in subjectOptions"
          :key="subject.subject_code || subject.subjectCode"
          :label="subjectLabel(subject)"
          :value="subject.subject_code || subject.subjectCode"
        />
      </el-select>
      <el-input v-model.trim="filters.caseNo" placeholder="案号" :prefix-icon="Search" clearable @keyup.enter="queryRecords" />
      <el-input v-model.trim="filters.receiptNo" placeholder="收据号" :prefix-icon="Search" clearable @keyup.enter="queryRecords" />
      <el-switch v-model="onlyMonitor" active-text="仅看未清明细" inactive-text="全部" @change="queryRecords" />
      <el-button type="primary" :icon="Search" @click="queryRecords">查询记录</el-button>
    </div>
  </div>

  <div class="panel aux-balance-panel">
    <div class="panel-header">
      <strong>
        <el-icon><Grid /></el-icon>
        案号余额汇总
      </strong>
      <span class="muted">
        案号 {{ pageTotal }} 个 · 未清明细 {{ monitorTotal }} 条 · 期末余额 {{ balanceText(totalEnding) }}
      </span>
    </div>
    <div class="panel-body compact">
      <el-table
        v-loading="loading"
        :data="rows"
        row-key="row_key"
        height="calc(100vh - 380px)"
      >
        <el-table-column prop="case_no" label="案号" min-width="280" fixed="left">
          <template #default="{ row }">
            <div class="case-cell">
              <strong>{{ row.case_no }}</strong>
              <el-tag v-if="row.monitor_flag" type="danger" size="small">未清</el-tag>
              <span v-if="row.monitor_count > 0" class="muted">{{ row.monitor_count }} 条未清明细</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="subject_code" label="科目" min-width="200">
          <template #default="{ row }">
            <span class="text-mono">{{ row.subject_code }}</span>
            <span class="muted" v-if="row.subject_name"> · {{ row.subject_name }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="opening_balance_amount" label="期初余额" width="140" align="right">
          <template #default="{ row }">
            <span :class="['text-mono', amountClass(row.opening_balance_amount)]">{{ balanceText(row.opening_balance_amount) }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="debit_amount" label="累计借方" width="140" align="right">
          <template #default="{ row }">
            <span :class="['text-mono', Number(row.debit_amount) > 0 ? 'amount-debit' : 'muted']">{{ amountText(row.debit_amount) }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="credit_amount" label="累计贷方" width="140" align="right">
          <template #default="{ row }">
            <span :class="['text-mono', Number(row.credit_amount) > 0 ? 'amount-credit' : 'muted']">{{ amountText(row.credit_amount) }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="ending_balance_amount" label="期末余额" width="150" align="right">
          <template #default="{ row }">
            <span :class="['text-mono', row.monitor_flag ? 'amount-credit strong-balance' : amountClass(row.ending_balance_amount)]">
              {{ balanceText(row.ending_balance_amount) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="entry_count" label="收支笔数" width="96" align="center" />
      </el-table>

      <div class="pagination-bar">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="pageSize"
          :page-sizes="[20, 50, 100, 200]"
          :total="pageTotal"
          layout="total, sizes, prev, pager, next, jumper"
          @current-change="handlePageChange"
          @size-change="handlePageSizeChange"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Download, Grid, Refresh, Search } from '@element-plus/icons-vue'
import { booksApi } from '../../api/books'
import { useContextStore } from '../../stores/context'
import type { AuxBalanceRow, Subject } from '../../types/api'

const context = useContextStore()
const filters = reactive({
  subjectCode: '',
  caseNo: '',
  receiptNo: ''
})
const onlyMonitor = ref(false)
const rows = ref<AuxBalanceRow[]>([])
const subjectOptions = ref<Subject[]>([])
const subjectLoading = ref(false)
const loading = ref(false)
const exporting = ref(false)
const page = ref(1)
const pageSize = ref(50)
const pageTotal = ref(0)
const monitorTotal = ref(0)
const totalEnding = ref(0)

const amountText = (val: string | number) => {
  const num = Number(val) || 0
  return num > 0 ? '¥ ' + num.toFixed(2) : '—'
}

const balanceText = (val: string | number) => {
  const num = Number(val) || 0
  if (Math.abs(num) < 0.001) return '—'
  return (num >= 0 ? '¥ ' : '-¥ ') + Math.abs(num).toFixed(2)
}

const amountClass = (val: string | number) => {
  const num = Number(val) || 0
  return Math.abs(num) < 0.001 ? 'muted' : 'amount-debit'
}

const subjectLabel = (subject: Subject) => {
  const code = subject.subject_code || subject.subjectCode
  const name = subject.subject_name || subject.subjectName
  return `${code} ${name || ''}`.trim()
}

const loadSubjectOptions = async () => {
  subjectLoading.value = true
  try {
    subjectOptions.value = await booksApi.auxBalanceSubjects()
    if (filters.subjectCode && !subjectOptions.value.some((subject) => (subject.subject_code || subject.subjectCode) === filters.subjectCode)) {
      filters.subjectCode = ''
    }
  } finally {
    subjectLoading.value = false
  }
}

const load = async (refreshSnapshot = false) => {
  loading.value = true
  try {
    const result = await booksApi.auxBalance({
      period: context.period,
      subjectCode: filters.subjectCode,
      caseNo: filters.caseNo,
      receiptNo: filters.receiptNo,
      onlyMonitor: onlyMonitor.value,
      page: page.value,
      pagesize: pageSize.value,
      refreshSnapshot
    })
    rows.value = result.items || []
    pageTotal.value = Number(result.total || 0)
    monitorTotal.value = Number(result.monitor_total || 0)
    totalEnding.value = Number(result.ending_total || 0)
  } finally {
    loading.value = false
  }
}

const queryRecords = async () => {
  page.value = 1
  await load()
}

const refreshData = async () => {
  page.value = 1
  await load(true)
}

const handlePageChange = async () => {
  await load()
}

const handlePageSizeChange = async () => {
  page.value = 1
  await load()
}

const balanceLeafRows = (items: AuxBalanceRow[]) => items.flatMap((row) => (row.children || []).length > 0 ? row.children || [] : [row])

const exportData = async () => {
  exporting.value = true
  try {
    const result = await booksApi.auxBalance({
      period: context.period,
      subjectCode: filters.subjectCode,
      caseNo: filters.caseNo,
      receiptNo: filters.receiptNo,
      onlyMonitor: onlyMonitor.value,
      exportAll: true
    })
    const exportRows = balanceLeafRows(result.items || [])
    if (exportRows.length === 0) {
      ElMessage.warning('当前没有可导出的辅助核算余额数据')
      return
    }
    const XLSX = await import('xlsx')
    const headers = ['科目编码', '科目名称', '案号', '收据号', '期初余额', '累计借方', '累计贷方', '期末余额', '监控状态']
    const body = exportRows.map((row) => [
      row.subject_code,
      row.subject_name,
      row.case_no,
      row.receipt_no || '',
      Number(row.opening_balance_amount || 0),
      Number(row.debit_amount || 0),
      Number(row.credit_amount || 0),
      Number(row.ending_balance_amount || 0),
      row.monitor_flag ? '未清' : '已清'
    ])
    const sheet = XLSX.utils.aoa_to_sheet([headers, ...body])
    const workbook = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(workbook, sheet, '辅助核算余额表')
    XLSX.writeFile(workbook, `辅助核算余额表_${context.period}.xlsx`)
  } finally {
    exporting.value = false
  }
}

onMounted(async () => {
  await loadSubjectOptions()
  await load()
})
</script>

<style scoped>
.aux-balance-filter {
  grid-template-columns: minmax(180px, 0.9fr) minmax(220px, 1.2fr) minmax(170px, 0.8fr) 160px auto;
}

.aux-balance-panel .panel-body {
  padding-bottom: 10px;
}

.case-cell {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.case-cell strong {
  color: var(--text-main);
}

.strong-balance {
  font-weight: 700;
}

@media (max-width: 1180px) {
  .aux-balance-filter {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
