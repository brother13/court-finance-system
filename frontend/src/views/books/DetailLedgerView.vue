<template>
  <div class="page-header">
    <div>
      <h1>明细账</h1>
      <p>按科目与日期范围检索凭证明细,支持借贷金额与辅助核算项联动展示。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新数据</el-button>
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
      <el-button type="primary" :icon="Search" @click="load">查询记录</el-button>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header">
      <strong>
        <el-icon><Tickets /></el-icon>
        明细账记录 · {{ selectedSubjectLabel }}
      </strong>
      <span class="muted">共 {{ rows.length }} 条记录 · 借方合计 ¥ {{ totalDebit.toFixed(2) }} · 贷方合计 ¥ {{ totalCredit.toFixed(2) }}</span>
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
        <el-table :data="rows" height="calc(100vh - 320px)" @row-dblclick="openVoucherDetail">
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
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
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

const totalDebit = computed(() => rows.value.reduce((sum, row) => sum + (Number(row.debit_amount) || 0), 0))
const totalCredit = computed(() => rows.value.reduce((sum, row) => sum + (Number(row.credit_amount) || 0), 0))
const subjectCodeOf = (subject: Subject) => String(subject.subject_code || subject.subjectCode || '')
const subjectNameOf = (subject: Subject) => String(subject.subject_name || subject.subjectName || '')
const parentCodeOf = (subject: Subject) => String(subject.parent_code || subject.parentCode || '')

const selectedSubjectLabel = computed(() => {
  const selected = subjects.value.find((subject) => subjectCodeOf(subject) === query.subjectCode)
  const name = selected ? subjectNameOf(selected) : ''
  return `${query.subjectCode}${name ? ' ' + name : ''}`
})

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

const load = async () => {
  if (filters.startPeriod > filters.endPeriod) {
    ElMessage.warning('起始期间不能晚于结束期间')
    return
  }
  const [startDate] = periodMonthRange(filters.startPeriod)
  const [, endDate] = periodMonthRange(filters.endPeriod)
  rows.value = await booksApi.detailLedger({
    period: filters.endPeriod,
    startPeriod: filters.startPeriod,
    endPeriod: filters.endPeriod,
    subjectCode: query.subjectCode,
    startDate,
    endDate
  }) as any[]
}

const applyRouteQuery = () => {
  const routePeriod = String(route.query.period || context.period)
  filters.startPeriod = String(route.query.start_period || routePeriod)
  filters.endPeriod = String(route.query.end_period || routePeriod)
  query.subjectCode = String(route.query.subject_code || defaultSubjectCode)
}

const loadSubjects = async () => {
  subjects.value = await baseApi.subjects()
}

const handleSubjectSelect = (row: SubjectTreeNode) => {
  const code = subjectCodeOf(row)
  if (!code || code === query.subjectCode) return
  query.subjectCode = code
  load()
}

const openVoucherDetail = (row: any) => {
  if (!row.voucher_id || !row.period) {
    ElMessage.warning('当前明细缺少凭证信息,无法打开凭证')
    return
  }
  router.push({ path: `/vouchers/detail/${row.period}/${row.voucher_id}` })
}

const exportData = () => {
  ElMessage.info('导出功能开发中,可对接 Excel 导出接口')
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
