<template>
  <div class="page-header">
    <div>
      <h1>明细账</h1>
      <p>按科目与日期范围检索凭证明细,支持借贷金额与辅助核算项联动展示。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新数据</el-button>
      <el-button :icon="Printer" :loading="printing" @click="printReport">打印</el-button>
      <el-button v-permission="'book:export'" type="primary" :icon="Download" @click="exportData">导出明细</el-button>
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

  <div class="panel">
    <div class="panel-header">
      <strong>
        <el-icon><Tickets /></el-icon>
        明细账记录 · {{ selectedSubjectLabel }}
      </strong>
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
                {{ Number(row.debit_amount) > 0 ? '¥ ' + Number(row.debit_amount).toFixed(2) : '—' }}
              </span>
            </template>
          </el-table-column>
          <el-table-column prop="credit_amount" label="贷方金额" width="140" align="right">
            <template #default="{ row }">
              <span :class="['text-mono', Number(row.credit_amount) > 0 ? 'amount-credit' : 'muted']">
                {{ Number(row.credit_amount) > 0 ? '¥ ' + Number(row.credit_amount).toFixed(2) : '—' }}
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
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { Download, Printer, Refresh, Search, Tickets } from '@element-plus/icons-vue'
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
const printing = ref(false)

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

const loadAllLedgerRows = async () => {
  if (filters.startPeriod > filters.endPeriod) {
    ElMessage.warning('起始期间不能晚于结束期间')
    return { items: [], debitTotal: 0, creditTotal: 0 }
  }
  const firstPage = await fetchLedgerPage(1, exportPageSize)
  const items = [...(firstPage.items || [])]
  const total = Number(firstPage.total || items.length || 0)
  const pageCount = Math.ceil(total / exportPageSize)
  for (let currentPage = 2; currentPage <= pageCount; currentPage += 1) {
    const result = await fetchLedgerPage(currentPage, exportPageSize)
    items.push(...(result.items || []))
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
  const { items, debitTotal, creditTotal } = await loadAllLedgerRows()
  if (items.length === 0) {
    ElMessage.warning('当前没有可导出的明细账数据')
    return
  }
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
  const sheet = XLSX.utils.aoa_to_sheet([['明细账'], [context.accountSetName || '当前账套', filterSummaryText.value], headers, ...body, totals])
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, sheet, '明细账')
  XLSX.writeFile(workbook, `明细账_${query.subjectCode}_${filters.startPeriod}_${filters.endPeriod}.xlsx`)
}

const printReport = async () => {
  if (printing.value) return
  if (pageTotal.value === 0) {
    ElMessage.warning('当前没有可打印的明细账数据')
    return
  }
  printing.value = true
  try {
    const [startDate] = periodMonthRange(filters.startPeriod)
    const [, endDate] = periodMonthRange(filters.endPeriod)
    const pdf = await booksApi.detailLedgerPdf({
      period: filters.endPeriod,
      startPeriod: filters.startPeriod,
      endPeriod: filters.endPeriod,
      subjectCode: query.subjectCode,
      startDate,
      endDate
    })
    const url = URL.createObjectURL(pdf)
    window.open(url, '_blank')
    ElMessage.success('明细账PDF已生成')
  } catch (error: any) {
    ElMessage.error(error?.message || '生成明细账PDF失败')
  } finally {
    printing.value = false
  }
}

watch(
  () => route.query,
  async () => {
    applyRouteQuery()
    await load()
  },
  { immediate: true }
)

onMounted(loadSubjects)
</script>

<style scoped>
.detail-period-range {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) 24px minmax(0, 1fr);
  gap: 8px;
  align-items: center;
  min-width: 320px;
  width: 100%;
}

.detail-period-range :deep(.el-select) {
  width: 100%;
}

.detail-period-range span {
  color: var(--text-secondary);
  text-align: center;
}

.detail-period-label {
  white-space: nowrap;
}

.detail-ledger-body {
  display: grid;
  grid-template-columns: 260px minmax(0, 1fr);
  gap: 14px;
}

.ledger-subject-panel {
  border-right: 1px solid #e5e7eb;
  padding-right: 12px;
  min-width: 0;
}

.ledger-subject-header {
  color: var(--text-secondary);
  font-weight: 600;
  margin-bottom: 10px;
}

.ledger-subject-tree {
  height: calc(100vh - 330px);
  overflow: auto;
  background: transparent;
}

.ledger-subject-tree :deep(.el-tree-node__content) {
  height: 34px;
}

.ledger-table-panel {
  min-width: 0;
}

.pagination-bar {
  display: flex;
  justify-content: flex-end;
  padding-top: 12px;
}

@media (max-width: 960px) {
  .detail-ledger-body {
    grid-template-columns: 1fr;
  }

  .ledger-subject-panel {
    border-right: 0;
    border-bottom: 1px solid #e5e7eb;
    padding-right: 0;
    padding-bottom: 12px;
  }

  .ledger-subject-tree {
    height: 220px;
  }
}
</style>
