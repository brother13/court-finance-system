<template>
  <div class="page-header">
    <div>
      <h1>案号收支明细表</h1>
      <p>按案号追溯账套启用以来的全部凭证收支明细，不重复计入年度期初结转。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" :loading="refreshingSnapshot" @click="refreshSnapshot">刷新缓存</el-button>
      <el-button
        v-permission="'book:export'"
        type="primary"
        :icon="Download"
        :loading="exporting"
        @click="exportData"
      >导出明细</el-button>
    </div>
  </div>

  <div class="search-filter-section case-movement-filter">
    <div class="case-filter-grid">
      <el-select v-model="query.subjectCode" placeholder="全部案款科目" clearable filterable :teleported="false">
        <el-option
          v-for="subject in subjectOptions"
          :key="subjectCodeOf(subject)"
          :label="`${subjectCodeOf(subject)} ${subjectNameOf(subject)}`"
          :value="subjectCodeOf(subject)"
        />
      </el-select>
      <el-input v-model="query.caseNo" :prefix-icon="Search" placeholder="案号（支持模糊查询）" clearable @keyup.enter="search" />
      <el-input v-model="query.receiptNo" :prefix-icon="Search" placeholder="收据号（支持模糊查询）" clearable @keyup.enter="search" />
      <el-button type="primary" :icon="Search" @click="search">查询明细</el-button>
    </div>
  </div>

  <div class="panel case-movement-panel">
    <div class="panel-header case-movement-summary">
      <div class="summary-main">
        <strong>
          <el-icon><List /></el-icon>
          收支明细清单
        </strong>
        <span class="range-chip">{{ rangeText }}</span>
      </div>
      <div class="summary-totals">
        <span>明细 <b>{{ pageTotal }}</b> 条</span>
        <span>借方合计 <b class="amount-debit">¥ {{ formatCurrency(totalDebit) }}</b></span>
        <span>贷方合计 <b class="amount-credit">¥ {{ formatCurrency(totalCredit) }}</b></span>
        <span class="muted">快照：{{ snapshotTime || '尚未生成' }}</span>
      </div>
    </div>

    <div class="case-movement-table-wrap">
      <el-table
        v-loading="loading"
        :data="rows"
        row-key="snapshot_id"
        height="calc(100vh - 345px)"
        stripe
      >
        <el-table-column prop="case_no" label="案号" min-width="220" fixed="left" show-overflow-tooltip>
          <template #default="{ row }"><strong class="case-no-cell">{{ row.case_no }}</strong></template>
        </el-table-column>
        <el-table-column prop="voucher_date" label="凭证日期" width="112" align="center" />
        <el-table-column prop="period" label="期间" width="92" align="center" />
        <el-table-column prop="voucher_label" label="凭证号" width="92" align="center">
          <template #default="{ row }">
            <el-button v-if="context.hasPermission('voucher:view')" link type="primary" @click="openVoucher(row)">
              {{ row.voucher_label }}
            </el-button>
            <span v-else class="text-mono">{{ row.voucher_label }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="voucher_status" label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="statusType(row.voucher_status)" size="small" effect="plain">{{ statusLabel(row.voucher_status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="科目" min-width="190" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="text-mono">{{ row.subject_code }}</span>
            <span class="muted"> · {{ row.subject_name }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="summary" label="摘要" min-width="220" show-overflow-tooltip />
        <el-table-column prop="receipt_no" label="收据号" min-width="140" show-overflow-tooltip>
          <template #default="{ row }"><span :class="row.receipt_no ? 'text-mono' : 'muted'">{{ row.receipt_no || '—' }}</span></template>
        </el-table-column>
        <el-table-column prop="debit_amount" label="借方金额" width="140" align="right">
          <template #default="{ row }"><span :class="['text-mono', Number(row.debit_amount) ? 'amount-debit' : 'muted']">{{ formatAmount(row.debit_amount) }}</span></template>
        </el-table-column>
        <el-table-column prop="credit_amount" label="贷方金额" width="140" align="right">
          <template #default="{ row }"><span :class="['text-mono', Number(row.credit_amount) ? 'amount-credit' : 'muted']">{{ formatAmount(row.credit_amount) }}</span></template>
        </el-table-column>
      </el-table>
    </div>

    <div class="pagination-bar case-movement-pagination">
      <el-pagination
        v-model:current-page="page"
        v-model:page-size="pageSize"
        :page-sizes="[50, 100, 200]"
        :total="pageTotal"
        layout="total, sizes, prev, pager, next, jumper"
        @current-change="loadPage"
        @size-change="handlePageSizeChange"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { Download, List, Refresh, Search } from '@element-plus/icons-vue'
import { booksApi } from '../../api/books'
import { useContextStore } from '../../stores/context'
import type { CaseMovementDetailRow, Subject } from '../../types/api'

const context = useContextStore()
const router = useRouter()
const query = reactive({ subjectCode: '', caseNo: '', receiptNo: '' })
const subjectOptions = ref<Subject[]>([])
const rows = ref<CaseMovementDetailRow[]>([])
const page = ref(1)
const pageSize = ref(100)
const pageTotal = ref(0)
const totalDebit = ref(0)
const totalCredit = ref(0)
const startPeriod = ref('')
const endPeriod = ref(context.period)
const snapshotTime = ref('')
const loading = ref(false)
const refreshingSnapshot = ref(false)
const exporting = ref(false)
const exportPageSize = 200

const subjectCodeOf = (subject: Subject) => String(subject.subject_code || subject.subjectCode || '')
const subjectNameOf = (subject: Subject) => String(subject.subject_name || subject.subjectName || '')
const rangeText = ref('账套启用以来')

const updateRangeText = () => {
  rangeText.value = startPeriod.value && endPeriod.value
    ? `${startPeriod.value}（启用期）至 ${endPeriod.value}`
    : '账套启用以来'
}

const requestParams = (targetPage: number, targetPageSize: number, refresh = false) => ({
  period: context.period,
  subjectCode: query.subjectCode,
  caseNo: query.caseNo.trim(),
  receiptNo: query.receiptNo.trim(),
  page: targetPage,
  pagesize: targetPageSize,
  refreshSnapshot: refresh
})

const load = async (refresh = false) => {
  loading.value = true
  try {
    const result = await booksApi.caseMovementDetail(requestParams(page.value, pageSize.value, refresh))
    rows.value = result.items || []
    pageTotal.value = Number(result.total || 0)
    totalDebit.value = Number(result.debit_total || 0)
    totalCredit.value = Number(result.credit_total || 0)
    startPeriod.value = result.start_period || ''
    endPeriod.value = result.end_period || context.period
    snapshotTime.value = result.snapshot_time || ''
    updateRangeText()
    return true
  } catch (error: any) {
    ElMessage.error(error?.message || '加载案号收支明细失败')
    return false
  } finally {
    loading.value = false
  }
}

const search = () => {
  page.value = 1
  load()
}

const loadPage = () => load(false)

const refreshSnapshot = async () => {
  refreshingSnapshot.value = true
  try {
    page.value = 1
    const loaded = await load(true)
    if (loaded) ElMessage.success('案号收支明细缓存已刷新')
  } finally {
    refreshingSnapshot.value = false
  }
}

const handlePageSizeChange = () => {
  page.value = 1
  load()
}

const openVoucher = (row: CaseMovementDetailRow) => {
  router.push(`/vouchers/detail/${row.period}/${row.voucher_id}`)
}

const statusLabels: Record<string, string> = {
  DRAFT: '草稿',
  SUBMITTED: '已提交',
  AUDITED: '已审核',
  POSTED: '已记账',
  PRINTED: '已打印',
  VOIDED: '已作废'
}
const statusLabel = (status: string) => statusLabels[status] || status || '未知'

const statusTypes: Record<string, 'success' | 'warning' | 'info' | 'danger' | 'primary'> = {
  DRAFT: 'info',
  SUBMITTED: 'warning',
  AUDITED: 'primary',
  POSTED: 'success',
  PRINTED: 'success',
  VOIDED: 'danger'
}
const statusType = (status: string): 'success' | 'warning' | 'info' | 'danger' | 'primary' => statusTypes[status] || 'info'

const formatCurrency = (amount: number | string) => Number(amount || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const formatAmount = (amount: number | string) => Number(amount || 0) === 0 ? '—' : formatCurrency(amount)

const exportData = async () => {
  exporting.value = true
  try {
    const allRows: CaseMovementDetailRow[] = []
    let exportPage = 1
    let total = 0
    do {
      const result = await booksApi.caseMovementDetail(requestParams(exportPage, exportPageSize))
      total = Number(result.total || 0)
      const pageItems = result.items || []
      allRows.push(...pageItems)
      exportPage += 1
      if (!pageItems.length) break
    } while (allRows.length < total)

    if (!allRows.length) {
      ElMessage.warning('当前筛选条件下没有可导出的明细')
      return
    }
    const XLSX = await import('xlsx')
    const sheet = XLSX.utils.json_to_sheet(allRows.map((row) => ({
      案号: row.case_no,
      凭证日期: row.voucher_date,
      会计期间: row.period,
      凭证号: row.voucher_label,
      凭证状态: statusLabel(row.voucher_status),
      科目编码: row.subject_code,
      科目名称: row.subject_name,
      摘要: row.summary,
      收据号: row.receipt_no,
      借方金额: Number(row.debit_amount || 0),
      贷方金额: Number(row.credit_amount || 0)
    })))
    const workbook = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(workbook, sheet, '案号收支明细')
    XLSX.writeFile(workbook, `案号收支明细表_${startPeriod.value}_${endPeriod.value}.xlsx`)
  } catch (error: any) {
    ElMessage.error(error?.message || '导出案号收支明细失败')
  } finally {
    exporting.value = false
  }
}

onMounted(async () => {
  try {
    subjectOptions.value = await booksApi.auxBalanceSubjects()
  } catch (error: any) {
    ElMessage.error(error?.message || '加载案款科目失败')
  }
  await load()
})

watch(() => context.period, () => {
  page.value = 1
  load()
})
</script>

<style scoped>
.case-filter-grid {
  display: grid;
  grid-template-columns: minmax(210px, 1fr) minmax(260px, 1.35fr) minmax(210px, 1fr) auto;
  gap: 12px;
}

.case-movement-panel {
  overflow: hidden;
}

.case-movement-summary,
.summary-main,
.summary-totals {
  display: flex;
  align-items: center;
}

.case-movement-summary {
  gap: 18px;
  padding: 14px 18px;
}

.summary-main {
  gap: 14px;
  flex-shrink: 0;
}

.summary-totals {
  justify-content: flex-end;
  gap: 18px;
  min-width: 0;
  font-size: 12px;
  color: var(--text-secondary);
}

.summary-totals b {
  margin-left: 4px;
  font-variant-numeric: tabular-nums;
}

.range-chip {
  padding: 4px 9px;
  border: 1px solid #dbeafe;
  border-radius: 999px;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 12px;
  white-space: nowrap;
}

.case-movement-table-wrap {
  padding: 0 18px;
}

.case-no-cell {
  color: var(--text-main);
  font-weight: 600;
}

.case-movement-pagination {
  padding: 13px 18px;
  border-top: 1px solid var(--border-soft);
  background: #fff;
}

@media (max-width: 1180px) {
  .case-filter-grid {
    grid-template-columns: 1fr 1fr;
  }

  .case-movement-summary {
    align-items: flex-start;
    flex-direction: column;
  }

  .summary-totals {
    flex-wrap: wrap;
    justify-content: flex-start;
  }
}
</style>
