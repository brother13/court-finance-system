<template>
  <div class="page-header">
    <div>
      <h1>辅助核算余额表</h1>
      <p>按科目、案号、收据号汇总辅助核算余额,用于识别尚未划拨或退还的案款收据。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新数据</el-button>
      <el-button v-permission="'book:export'" type="primary" :icon="Download" @click="exportData">导出余额表</el-button>
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
      <el-input v-model.trim="filters.caseNo" placeholder="案号" :prefix-icon="Search" clearable />
      <el-input v-model.trim="filters.receiptNo" placeholder="收据号" :prefix-icon="Search" clearable />
      <el-switch v-model="onlyMonitor" active-text="仅看未清收据" inactive-text="全部" />
      <el-button type="primary" :icon="Search" @click="load">查询记录</el-button>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header">
      <strong>
        <el-icon><Grid /></el-icon>
        辅助核算余额表
      </strong>
      <span class="muted">
        案号 {{ filteredRows.length }} 个 · 未清收据 {{ monitorReceiptCount }} 张 · 期末余额 ¥ {{ totalEnding.toFixed(2) }}
      </span>
    </div>
    <div class="panel-body compact">
      <el-table
        :data="filteredRows"
        row-key="row_key"
        height="calc(100vh - 330px)"
        :tree-props="{ children: 'children' }"
        default-expand-all
      >
        <el-table-column prop="case_no" label="案号 / 收据号" min-width="260">
          <template #default="{ row }">
            <div class="aux-tree-cell">
              <strong v-if="row.row_type === 'CASE'">{{ row.case_no }}</strong>
              <span v-else>{{ row.receipt_no || '未填收据号' }}</span>
              <el-tag v-if="row.monitor_flag" type="danger" size="small">未清</el-tag>
              <el-tag v-else-if="row.row_type === 'CASE' && row.monitor_count > 0" type="warning" size="small">{{ row.monitor_count }} 张未清</el-tag>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="subject_code" label="科目" width="180">
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
        <el-table-column prop="entry_count" label="分录数" width="90" align="center" />
      </el-table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
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

const filteredRows = computed(() => {
  if (!onlyMonitor.value) return rows.value
  return rows.value
    .map((row) => ({
      ...row,
      children: (row.children || []).filter((child) => child.monitor_flag)
    }))
    .filter((row) => (row.children || []).length > 0)
})

const leafRows = computed(() => rows.value.flatMap((row) => row.children || []))
const filteredLeafRows = computed(() => filteredRows.value.flatMap((row) => row.children || []))
const monitorReceiptCount = computed(() => leafRows.value.filter((row) => row.monitor_flag).length)
const totalEnding = computed(() => filteredLeafRows.value.reduce((sum, row) => sum + Math.abs(Number(row.ending_balance_amount) || 0), 0))

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

const load = async () => {
  const result = await booksApi.auxBalance({
    period: context.period,
    subjectCode: filters.subjectCode,
    caseNo: filters.caseNo,
    receiptNo: filters.receiptNo
  })
  rows.value = result.items || []
}

const exportData = async () => {
  const exportRows = filteredLeafRows.value
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
    row.receipt_no || '未填收据号',
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
}

onMounted(async () => {
  await loadSubjectOptions()
  await load()
})
</script>

<style scoped>
.aux-balance-filter {
  grid-template-columns: minmax(150px, 0.8fr) minmax(220px, 1.2fr) minmax(150px, 0.8fr) 160px auto;
}

.aux-tree-cell {
  display: flex;
  align-items: center;
  gap: 8px;
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
