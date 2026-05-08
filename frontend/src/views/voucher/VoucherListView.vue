<template>
  <div class="page-header">
    <div>
      <h1>凭证中心</h1>
      <p>按当前账套、年度和会计期间查询凭证；顶部条件常驻，高级条件应用后参与查询。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新数据</el-button>
      <el-button
        v-if="context.hasPermission('voucher:delete')"
        type="danger"
        :icon="DeleteIcon"
        :disabled="selectedRows.length === 0"
        @click="batchDelete"
      >
        批量删除
      </el-button>
      <el-button
        v-if="context.hasPermission('voucher:import')"
        type="success"
        :icon="Upload"
        :loading="importing"
        @click="openImportDialog"
      >
        批量导入
      </el-button>
      <el-button v-if="context.hasPermission('voucher:add')" type="primary" :icon="Plus" @click="$router.push('/vouchers/new')">新增凭证</el-button>
    </div>
  </div>

  <div class="search-filter-section voucher-query-section compact">
    <div class="voucher-query-grid compact">
      <label class="query-field">
        <span>年度</span>
        <el-select v-model="topFilter.year" placeholder="年度" style="width: 90px">
          <el-option v-for="year in yearOptions" :key="year" :label="year" :value="year" />
        </el-select>
      </label>
      <label class="query-field">
        <span>期间</span>
        <el-select v-model="topFilter.period" placeholder="期间" style="width: 100px">
          <el-option v-for="period in periodOptions" :key="period" :label="period" :value="period" />
        </el-select>
      </label>
      <label class="query-field">
        <span>凭证字</span>
        <el-select v-model="topFilter.voucher_word" placeholder="全部" clearable style="width: 80px">
          <el-option label="记" value="记" />
          <el-option label="收" value="收" />
          <el-option label="付" value="付" />
          <el-option label="转" value="转" />
        </el-select>
      </label>
      <label class="query-field voucher-no-range">
        <span>凭证号</span>
        <div class="range-inputs">
          <el-input v-model="topFilter.voucher_no_start" placeholder="起" clearable style="width: 60px" />
          <em>-</em>
          <el-input v-model="topFilter.voucher_no_end" placeholder="止" clearable style="width: 60px" />
        </div>
      </label>
      <label class="query-field voucher-date-range">
        <span>日期</span>
        <el-date-picker
          v-model="dateRange"
          type="daterange"
          start-placeholder="开始"
          end-placeholder="结束"
          value-format="YYYY-MM-DD"
          range-separator="-"
          clearable
          style="width: 220px"
        />
      </label>
      <label class="query-field">
        <span>状态</span>
        <el-select v-model="topFilter.status" placeholder="全部" clearable style="width: 100px">
          <el-option label="草稿" value="DRAFT" />
          <el-option label="未审核" value="SUBMITTED" />
          <el-option label="已审核" value="AUDITED" />
          <el-option label="已记账" value="POSTED" />
          <el-option label="已打印" value="PRINTED" />
          <el-option label="已作废" value="VOIDED" />
        </el-select>
      </label>
      <label class="query-field" style="flex: 1; min-width: 160px">
        <span>摘要</span>
        <el-input v-model="topFilter.summary_keyword" placeholder="关键字" :prefix-icon="Search" clearable />
      </label>
      <div class="voucher-query-actions">
        <el-button type="primary" :icon="Search" size="small" @click="load">查询</el-button>
        <el-button :icon="RefreshLeft" size="small" @click="resetFilters">重置</el-button>
        <el-button :class="{ 'has-advanced-filter': advancedHasApplied }" :icon="Filter" size="small" @click="openAdvanced">
          高级
          <span v-if="advancedHasApplied" class="filter-red-dot" />
        </el-button>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header">
      <strong>
        <el-icon><Tickets /></el-icon>
        凭证列表 · {{ topFilter.period }}
      </strong>
      <span class="muted">共 {{ pageTotal }} 条记录</span>
    </div>
    <div class="panel-body compact">
      <el-table v-loading="loading" :data="rows" height="calc(100vh - 260px)" @row-dblclick="openDetail" @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="46" :selectable="canSelectRow" />
        <el-table-column prop="voucher_no" label="凭证号" width="110" align="center">
          <template #default="{ row }">
            <span class="text-mono" style="color: var(--brand-blue); font-weight: 600">
              {{ row.voucher_word || '记' }}-{{ String(row.voucher_no).padStart(4, '0') }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="voucher_date" label="制单日期" width="130" align="center" />
        <el-table-column prop="summary" label="摘要" min-width="240" />
        <el-table-column prop="debit_amount" label="借方金额" width="150" align="right">
          <template #default="{ row }">
            <span :class="['text-mono', Number(row.debit_amount || row.debitAmount) > 0 ? 'amount-debit' : 'muted']">
              {{ amountText(row.debit_amount || row.debitAmount) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="credit_amount" label="贷方金额" width="150" align="right">
          <template #default="{ row }">
            <span :class="['text-mono', Number(row.credit_amount || row.creditAmount) > 0 ? 'amount-credit' : 'muted']">
              {{ amountText(row.credit_amount || row.creditAmount) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="source_type" label="来源" width="110" align="center">
          <template #default="{ row }">{{ sourceTypeText(row.source_type) }}</template>
        </el-table-column>
        <el-table-column prop="prepared_by_name" label="制单人" width="120" align="center">
          <template #default="{ row }">{{ row.prepared_by_name || row.prepared_by || '-' }}</template>
        </el-table-column>
        <el-table-column prop="audit_by_name" label="审核人" width="120" align="center">
          <template #default="{ row }">{{ row.audit_by_name || row.audit_by || '-' }}</template>
        </el-table-column>
        <el-table-column prop="posted_by_name" label="记账人" width="120" align="center">
          <template #default="{ row }">{{ row.posted_by_name || row.posted_by || '-' }}</template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="110" align="center">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" effect="light" size="small">{{ statusText(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="240" fixed="right" align="center">
          <template #default="{ row }">
            <el-button v-if="canEditRow(row)" link type="primary" :icon="Edit" @click="openEdit(row)">编辑</el-button>
            <el-button v-if="row.status === 'SUBMITTED' && context.hasPermission('voucher:audit')" link type="success" :icon="Check" @click="audit(row)">审核</el-button>
            <el-button v-if="row.status === 'AUDITED' && context.hasPermission('voucher:unaudit')" link type="warning" :icon="RefreshLeft" @click="unaudit(row)">取消审核</el-button>
            <el-button v-if="canDeleteRow(row)" link type="danger" :icon="DeleteIcon" @click="deleteOne(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </div>
  </div>

  <el-dialog v-model="advancedVisible" title="高级筛选" width="760px" class="finance-dialog">
    <div class="advanced-filter-grid">
      <label class="query-field">
        <span>借方金额区间</span>
        <div class="range-inputs">
          <el-input v-model="advancedDraft.debit_min" placeholder="最小金额" clearable />
          <em>-</em>
          <el-input v-model="advancedDraft.debit_max" placeholder="最大金额" clearable />
        </div>
      </label>
      <label class="query-field">
        <span>贷方金额区间</span>
        <div class="range-inputs">
          <el-input v-model="advancedDraft.credit_min" placeholder="最小金额" clearable />
          <em>-</em>
          <el-input v-model="advancedDraft.credit_max" placeholder="最大金额" clearable />
        </div>
      </label>
      <label class="query-field">
        <span>制单人</span>
        <el-input v-model="advancedDraft.prepared_by" placeholder="制单人ID/名称" clearable />
      </label>
      <label class="query-field">
        <span>审核人</span>
        <el-input v-model="advancedDraft.audit_by" placeholder="审核人ID/名称" clearable />
      </label>
      <label class="query-field">
        <span>记账人</span>
        <el-input v-model="advancedDraft.posted_by" placeholder="记账人ID/名称" clearable />
      </label>
      <label class="query-field">
        <span>是否红字</span>
        <el-select v-model="advancedDraft.red_flag" placeholder="全部" clearable>
          <el-option label="红字凭证" value="1" />
          <el-option label="非红字凭证" value="0" />
        </el-select>
      </label>
      <label class="query-field">
        <span>凭证来源</span>
        <el-select v-model="advancedDraft.source_type" placeholder="全部来源" clearable>
          <el-option label="手工录入" value="MANUAL" />
          <el-option label="自动结转" value="AUTO_CARRY" />
          <el-option label="红字冲销" value="RED_REVERSAL" />
          <el-option label="业务生成" value="BUSINESS" />
        </el-select>
      </label>
      <template v-if="showFundAuxFilters">
        <label class="query-field">
          <span>案号</span>
          <el-input v-model="advancedDraft.case_no" placeholder="案号关键字" clearable />
        </label>
        <label class="query-field">
          <span>收据号</span>
          <el-input v-model="advancedDraft.receipt_no" placeholder="收据号关键字" clearable />
        </label>
      </template>
    </div>
    <template #footer>
      <el-button @click="clearAdvancedDraft">清空高级条件</el-button>
      <el-button @click="advancedVisible = false">取消</el-button>
      <el-button type="primary" @click="applyAdvanced">应用高级筛选</el-button>
    </template>
  </el-dialog>

  <!-- 批量导入凭证对话框 -->
  <el-dialog
    v-model="importVisible"
    title="批量导入凭证"
    width="560px"
    class="finance-dialog"
    :close-on-click-modal="false"
    @close="closeImportDialog"
  >
    <div class="import-dialog-content">
      <div v-if="!importFileName" class="import-upload-area">
        <input ref="importInputRef" type="file" accept=".xlsx" class="import-file-input" @change="handleImportFile" />
        <div class="upload-placeholder" @click="chooseImportFile">
          <el-icon :size="48" color="var(--brand-blue)"><Upload /></el-icon>
          <p>点击上传 Excel 凭证文件</p>
          <p class="muted">支持 .xlsx 格式，文件大小不超过 10MB</p>
        </div>
      </div>

      <div v-else class="import-preview">
        <div class="import-file-info">
          <el-icon :size="20"><Document /></el-icon>
          <span>{{ importFileName }}</span>
          <el-button link type="primary" @click="chooseImportFile">重新选择</el-button>
        </div>

        <div v-if="importPreview" class="import-stats">
          <div class="stat-row">
            <span class="stat-label">凭证总数</span>
            <span class="stat-value">{{ importPreview.voucherCount }} 张</span>
          </div>
          <div class="stat-row">
            <span class="stat-label">分录总数</span>
            <span class="stat-value">{{ importPreview.detailCount }} 行</span>
          </div>
          <div class="stat-row">
            <span class="stat-label">期间范围</span>
            <span class="stat-value">{{ importPreview.periodRange }}</span>
          </div>
        </div>

        <div v-if="importErrorList.length > 0" class="import-errors">
          <p class="error-title">解析警告（{{ importErrorList.length }} 条）</p>
          <el-scrollbar max-height="120px">
            <ul>
              <li v-for="(err, idx) in importErrorList" :key="idx">{{ err }}</li>
            </ul>
          </el-scrollbar>
        </div>
      </div>
    </div>

    <template #footer>
      <el-button @click="closeImportDialog">取消</el-button>
      <el-button
        type="primary"
        :loading="importing"
        :disabled="!importPreview || importPreview.voucherCount === 0"
        @click="confirmImport"
      >
        确认导入
      </el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Check, Delete as DeleteIcon, Document, Edit, Filter, Plus, Refresh, RefreshLeft, Search, Tickets, Upload } from '@element-plus/icons-vue'
import { voucherApi } from '../../api/voucher'
import { useContextStore } from '../../stores/context'
import * as XLSX from 'xlsx'

type TopFilter = {
  year: string
  period: string
  voucher_word: string
  voucher_no_start: string
  voucher_no_end: string
  status: string
  summary_keyword: string
}

type AdvancedFilter = {
  debit_min: string
  debit_max: string
  credit_min: string
  credit_max: string
  prepared_by: string
  audit_by: string
  posted_by: string
  red_flag: string
  source_type: string
  case_no: string
  receipt_no: string
}

const context = useContextStore()
const router = useRouter()
const rows = ref<any[]>([])
const selectedRows = ref<any[]>([])
const pageTotal = ref(0)
const loading = ref(false)
const advancedVisible = ref(false)
const dateRange = ref<string[]>([])
const importVisible = ref(false)
const importing = ref(false)
const importFileName = ref('')
const importPreview = ref<{ voucherCount: number; detailCount: number; periodRange: string } | null>(null)
const importErrorList = ref<string[]>([])
const importInputRef = ref<HTMLInputElement | null>(null)
const importVouchers = ref<any[]>([])

const defaultYear = context.period.slice(0, 4)
const topFilter = reactive<TopFilter>({
  year: defaultYear,
  period: context.period,
  voucher_word: '',
  voucher_no_start: '',
  voucher_no_end: '',
  status: '',
  summary_keyword: ''
})

const emptyAdvanced = (): AdvancedFilter => ({
  debit_min: '',
  debit_max: '',
  credit_min: '',
  credit_max: '',
  prepared_by: '',
  audit_by: '',
  posted_by: '',
  red_flag: '',
  source_type: '',
  case_no: '',
  receipt_no: ''
})

const advancedDraft = reactive<AdvancedFilter>(emptyAdvanced())
const advancedApplied = ref<AdvancedFilter>(emptyAdvanced())

const yearOptions = computed(() => {
  const current = Number(defaultYear || new Date().getFullYear())
  return Array.from({ length: 5 }, (_, index) => String(current - 2 + index))
})

const periodOptions = computed(() =>
  Array.from({ length: 12 }, (_, index) => `${topFilter.year}-${String(index + 1).padStart(2, '0')}`)
)

const showFundAuxFilters = computed(() => ['CASE_FUND', 'LITIGATION_FEE'].includes(context.bizType))

const cleanFilter = (filter: Record<string, any>) =>
  Object.fromEntries(Object.entries(filter).filter(([, value]) => value !== undefined && value !== null && String(value).trim() !== ''))

const advancedHasApplied = computed(() => Object.keys(cleanFilter(advancedApplied.value)).length > 0)

watch(
  () => topFilter.year,
  (year) => {
    const month = topFilter.period.slice(5, 7) || context.period.slice(5, 7) || '01'
    topFilter.period = `${year}-${month}`
  }
)

const buildQueryParams = () => {
  const [dateStart, dateEnd] = dateRange.value || []
  return cleanFilter({
    year: topFilter.year,
    period: topFilter.period,
    voucher_word: topFilter.voucher_word,
    voucher_no_start: topFilter.voucher_no_start,
    voucher_no_end: topFilter.voucher_no_end,
    date_start: dateStart,
    date_end: dateEnd,
    status: topFilter.status,
    summary_keyword: topFilter.summary_keyword,
    ...cleanFilter(advancedApplied.value)
  })
}

const load = async () => {
  loading.value = true
  try {
    const page: any = await voucherApi.page(buildQueryParams())
    rows.value = page.items || []
    selectedRows.value = []
    pageTotal.value = Number(page.total || rows.value.length || 0)
  } finally {
    loading.value = false
  }
}

const resetFilters = async () => {
  Object.assign(topFilter, {
    year: defaultYear,
    period: context.period,
    voucher_word: '',
    voucher_no_start: '',
    voucher_no_end: '',
    status: '',
    summary_keyword: ''
  })
  dateRange.value = []
  Object.assign(advancedDraft, emptyAdvanced())
  advancedApplied.value = emptyAdvanced()
  await load()
}

const openAdvanced = () => {
  Object.assign(advancedDraft, advancedApplied.value)
  advancedVisible.value = true
}

const clearAdvancedDraft = () => {
  Object.assign(advancedDraft, emptyAdvanced())
}

const applyAdvanced = async () => {
  advancedApplied.value = { ...advancedDraft }
  advancedVisible.value = false
  await load()
}

const openDetail = (row: any) => {
  router.push(`/vouchers/detail/${row.period}/${row.voucher_id}`)
}

const openEdit = (row: any) => {
  router.push(`/vouchers/edit/${row.period}/${row.voucher_id}`)
}

const unreviewedStatuses = ['DRAFT', 'SUBMITTED']
const isUnreviewed = (row: any) => unreviewedStatuses.includes(row.status)
const isAutoCarry = (row: any) => row.source_type === 'AUTO_CARRY'
const canEditRow = (row: any) => context.hasPermission('voucher:edit') && isUnreviewed(row)
const canDeleteRow = (row: any) => context.hasPermission('voucher:delete') && isUnreviewed(row) && !isAutoCarry(row)
const canSelectRow = (row: any) => canDeleteRow(row)

const handleSelectionChange = (selection: any[]) => {
  selectedRows.value = selection
}

const deleteOne = async (row: any) => {
  try {
    await ElMessageBox.confirm(`确认删除凭证 ${row.voucher_word || '记'}-${String(row.voucher_no).padStart(4, '0')}？`, '删除凭证', {
      type: 'warning',
      confirmButtonText: '删除',
      cancelButtonText: '取消'
    })
    await voucherApi.remove(row.period, row.voucher_id)
    ElMessage.success('凭证已删除')
    await load()
  } catch {
    // 用户取消时不提示。
  }
}

const batchDelete = async () => {
  if (selectedRows.value.length === 0) return
  try {
    await ElMessageBox.confirm(`确认删除选中的 ${selectedRows.value.length} 张凭证？`, '批量删除凭证', {
      type: 'warning',
      confirmButtonText: '批量删除',
      cancelButtonText: '取消'
    })
    await voucherApi.batchRemove(topFilter.period, selectedRows.value.map((row) => row.voucher_id))
    ElMessage.success('凭证已批量删除')
    selectedRows.value = []
    await load()
  } catch {
    // 用户取消时不提示。
  }
}

const audit = async (row: any) => {
  await voucherApi.audit(topFilter.period, row.voucher_id)
  ElMessage.success('审核完成')
  await load()
}

const unaudit = async (row: any) => {
  await voucherApi.unaudit(topFilter.period, row.voucher_id)
  ElMessage.success('已取消审核')
  await load()
}

const statusText = (status: string) =>
  ({
    DRAFT: '草稿',
    SUBMITTED: '未审核',
    AUDITED: '已审核',
    POSTED: '已记账',
    PRINTED: '已打印',
    VOIDED: '已作废'
  }[status] || status)

const statusType = (status: string) =>
  (({
    AUDITED: 'success',
    SUBMITTED: 'warning',
    POSTED: 'success',
    PRINTED: 'primary',
    VOIDED: 'info'
  } as Record<string, 'success' | 'warning' | 'primary' | 'info' | 'danger'>)[status] || 'info')

const sourceTypeText = (sourceType: string) =>
  ({ MANUAL: '手工录入', AUTO_CARRY: '自动结转', RED_REVERSAL: '红字冲销', BUSINESS: '业务生成', IMPORT: '批量导入' }[sourceType] || sourceType || '-')

const amountText = (value: any) => {
  const amount = Number(value || 0)
  return amount > 0 ? '¥ ' + amount.toFixed(2) : '-'
}

// === 批量导入 ===
const openImportDialog = () => {
  importVisible.value = true
}

const closeImportDialog = () => {
  importVisible.value = false
  importFileName.value = ''
  importPreview.value = null
  importErrorList.value = []
  importVouchers.value = []
}

const chooseImportFile = () => {
  if (!importInputRef.value) return
  importInputRef.value.value = ''
  importInputRef.value.click()
}

const handleImportFile = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  if (!file.name.toLowerCase().endsWith('.xlsx')) {
    ElMessage.warning('请选择 .xlsx 格式的文件')
    return
  }
  if (file.size > 10 * 1024 * 1024) {
    ElMessage.warning('文件大小不能超过 10MB')
    return
  }

  importFileName.value = file.name
  importErrorList.value = []

  try {
    const data = await file.arrayBuffer()
    const workbook = XLSX.read(data, { type: 'array' })
    const sheet = workbook.Sheets[workbook.SheetNames[0]]
    const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, raw: false }) as any[][]

    if (rows.length < 2) {
      importErrorList.value.push('Excel 数据为空')
      importPreview.value = { voucherCount: 0, detailCount: 0, periodRange: '-' }
      return
    }

    const { vouchers, errors } = buildVouchersFromExcel(rows)
    importErrorList.value = errors
    importVouchers.value = vouchers

    const periods = [...new Set(vouchers.map((v) => v.period))].sort()
    importPreview.value = {
      voucherCount: vouchers.length,
      detailCount: vouchers.reduce((sum, v) => sum + v.details.length, 0),
      periodRange: periods.length > 1 ? `${periods[0]} ~ ${periods[periods.length - 1]}` : periods[0] || '-',
    }
  } catch (err: any) {
    ElMessage.error('Excel 解析失败：' + (err.message || '未知错误'))
    importFileName.value = ''
  }
}

const parseExcelDate = (val: any): string => {
  if (!val) return ''
  const s = String(val).trim()

  // 格式1: 2025-03-31 或 2025/03/31
  let m = s.match(/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/)
  if (m) {
    return `${m[1]}-${m[2].padStart(2, '0')}-${m[3].padStart(2, '0')}`
  }

  // 格式2: 03/31/2025 (MM/DD/YYYY)
  m = s.match(/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/)
  if (m) {
    return `${m[3]}-${m[1].padStart(2, '0')}-${m[2].padStart(2, '0')}`
  }

  // 格式3: 3/31/25 (MM/DD/YY) — Excel 美式日期
  m = s.match(/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{2})$/)
  if (m) {
    const yy = parseInt(m[3])
    const yyyy = yy < 50 ? 2000 + yy : 1900 + yy
    return `${yyyy}-${m[1].padStart(2, '0')}-${m[2].padStart(2, '0')}`
  }

  return s
}

const buildVouchersFromExcel = (rows: any[][]): { vouchers: any[]; errors: string[] } => {
  const errors: string[] = []
  const headers = rows[0] as string[]
  const colMap: Record<string, number> = {}
  headers.forEach((h, i) => {
    if (h) colMap[String(h).trim()] = i
  })

  // 检查必要列
  const requiredCols = ['日期', '凭证号', '科目编码', '摘要', '借方本币', '贷方本币']
  const missing = requiredCols.filter((c) => !(c in colMap))
  if (missing.length > 0) {
    errors.push('缺少必要列：' + missing.join('、'))
    return { vouchers: [], errors }
  }

  // 按 (日期, 凭证号) 分组
  const groups: Record<string, any[]> = {}
  for (let i = 1; i < rows.length; i++) {
    const row = rows[i]
    if (!row || row.length === 0) continue
    const date = parseExcelDate(row[colMap['日期']])
    const vn = row[colMap['凭证号']] || ''
    if (!date || !vn) continue
    const key = `${date}_${vn}`
    if (!groups[key]) groups[key] = []
    groups[key].push(row)
  }

  const vouchers: any[] = []
  Object.entries(groups).forEach(([, lines]) => {
    const firstLine = lines[0]
    const dateStr = parseExcelDate(firstLine[colMap['日期']])
    const vn = String(firstLine[colMap['凭证号']] || '')
    const period = dateStr.slice(0, 7)

    const voucherNoMatch = vn.match(/-(\d+)$/)
    const voucherNo = voucherNoMatch ? parseInt(voucherNoMatch[1]) : null

    const details = lines.map((line) => {
      const debit = parseFloat(line[colMap['借方本币']] || '0')
      const credit = parseFloat(line[colMap['贷方本币']] || '0')
      const auxValues: any[] = []

      const auxMappings = [
        { col: '客户编码', labelCol: '客户名称', type: 'customer' },
        { col: '供应商编码', labelCol: '供应商名称', type: 'supplier' },
        { col: '职员编码', labelCol: '职员名称', type: 'employee' },
        { col: '部门编码', labelCol: '部门名称', type: 'department' },
        { col: '项目编码', labelCol: '项目名称', type: 'project' },
      ]

      auxMappings.forEach(({ col, labelCol, type }) => {
        if (!(col in colMap)) return
        const code = line[colMap[col]]
        if (code !== undefined && code !== null && String(code).trim() !== '') {
          auxValues.push({
            aux_type_code: type,
            aux_value: String(code).trim(),
            aux_label: String(line[colMap[labelCol]] || code).trim(),
          })
        }
      })

      return {
        subject_code: String(line[colMap['科目编码']] || '').trim(),
        summary: String(line[colMap['摘要']] || '').trim(),
        debit_amount: debit !== 0 ? debit.toFixed(2) : '0',
        credit_amount: credit !== 0 ? credit.toFixed(2) : '0',
        aux_values: auxValues,
      }
    })

    // 验证借贷平衡
    const totalDebit = details.reduce((sum, d) => sum + parseFloat(d.debit_amount), 0)
    const totalCredit = details.reduce((sum, d) => sum + parseFloat(d.credit_amount), 0)
    if (Math.abs(totalDebit - totalCredit) > 0.01) {
      errors.push(`凭证 ${vn}（${dateStr}）借贷不平衡：借方 ${totalDebit.toFixed(2)} ≠ 贷方 ${totalCredit.toFixed(2)}`)
    }

    vouchers.push({
      period,
      voucher_date: dateStr,
      voucher_word: '记',
      voucher_no: voucherNo,
      summary: '',
      attachment_count: parseInt(firstLine[colMap['附件数']] || '0') || 0,
      prepared_by_name: String(firstLine[colMap['制单人']] || '').trim(),
      audit_by_name: String(firstLine[colMap['审核人']] || '').trim(),
      details,
    })
  })

  return { vouchers, errors }
}

const confirmImport = async () => {
  if (importVouchers.value.length === 0) return
  if (importErrorList.value.length > 0) {
    try {
      await ElMessageBox.confirm(`存在 ${importErrorList.value.length} 条解析警告，是否继续导入？`, '确认导入', {
        type: 'warning',
        confirmButtonText: '继续导入',
        cancelButtonText: '取消',
      })
    } catch {
      return
    }
  }

  importing.value = true
  try {
    const result = await voucherApi.import({ vouchers: importVouchers.value })
    const msg = `导入完成：成功 ${result.success} 张，失败 ${result.failed} 张`
    if (result.failed > 0) {
      ElMessage.warning(msg)
      importErrorList.value = result.errors
    } else {
      ElMessage.success(msg)
      closeImportDialog()
      await load()
    }
  } catch (err: any) {
    ElMessage.error('导入失败：' + (err.message || '未知错误'))
  } finally {
    importing.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.import-file-input {
  display: none;
}

.import-upload-area {
  border: 2px dashed var(--el-border-color);
  border-radius: 8px;
  padding: 40px 20px;
  text-align: center;
  cursor: pointer;
  transition: border-color 0.2s;
}

.import-upload-area:hover {
  border-color: var(--brand-blue);
}

.upload-placeholder p {
  margin: 8px 0 0;
  font-size: 14px;
  color: var(--el-text-color-regular);
}

.upload-placeholder .muted {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.import-preview {
  padding: 16px;
}

.import-file-info {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px;
  background: var(--el-fill-color-light);
  border-radius: 6px;
  margin-bottom: 16px;
}

.import-file-info span {
  flex: 1;
  font-size: 14px;
  color: var(--el-text-color-primary);
}

.import-stats {
  margin-bottom: 16px;
}

.stat-row {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.stat-row:last-child {
  border-bottom: none;
}

.stat-label {
  color: var(--el-text-color-secondary);
  font-size: 13px;
}

.stat-value {
  color: var(--el-text-color-primary);
  font-size: 13px;
  font-weight: 600;
}

.import-errors {
  padding: 12px;
  background: var(--el-color-danger-light-9);
  border-radius: 6px;
}

.import-errors .error-title {
  margin: 0 0 8px;
  font-size: 13px;
  color: var(--el-color-danger);
  font-weight: 600;
}

.import-errors ul {
  margin: 0;
  padding-left: 16px;
}

.import-errors li {
  font-size: 12px;
  color: var(--el-color-danger);
  line-height: 1.8;
}
</style>
