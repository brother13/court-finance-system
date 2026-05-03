<template>
  <div class="page-header">
    <div>
      <h1>凭证中心</h1>
      <p>按当前账套、年度和会计期间查询凭证；顶部条件常驻，高级条件应用后参与查询。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新数据</el-button>
      <el-button v-if="context.hasPermission('voucher:add')" type="primary" :icon="Plus" @click="$router.push('/vouchers/new')">新增凭证</el-button>
    </div>
  </div>

  <div class="search-filter-section voucher-query-section">
    <div class="voucher-query-grid">
      <label class="query-field">
        <span>会计年度</span>
        <el-select v-model="topFilter.year" placeholder="年度">
          <el-option v-for="year in yearOptions" :key="year" :label="year" :value="year" />
        </el-select>
      </label>
      <label class="query-field">
        <span>会计期间</span>
        <el-select v-model="topFilter.period" placeholder="期间">
          <el-option v-for="period in periodOptions" :key="period" :label="period" :value="period" />
        </el-select>
      </label>
      <label class="query-field">
        <span>凭证字</span>
        <el-select v-model="topFilter.voucher_word" placeholder="全部" clearable>
          <el-option label="记" value="记" />
          <el-option label="收" value="收" />
          <el-option label="付" value="付" />
          <el-option label="转" value="转" />
        </el-select>
      </label>
      <label class="query-field voucher-no-range">
        <span>凭证号起止</span>
        <div class="range-inputs">
          <el-input v-model="topFilter.voucher_no_start" placeholder="起" clearable />
          <em>-</em>
          <el-input v-model="topFilter.voucher_no_end" placeholder="止" clearable />
        </div>
      </label>
      <label class="query-field voucher-date-range">
        <span>制单日期起止</span>
        <el-date-picker
          v-model="dateRange"
          type="daterange"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          value-format="YYYY-MM-DD"
          range-separator="-"
          clearable
        />
      </label>
      <label class="query-field">
        <span>凭证状态</span>
        <el-select v-model="topFilter.status" placeholder="全部状态" clearable>
          <el-option label="草稿" value="DRAFT" />
          <el-option label="未审核" value="SUBMITTED" />
          <el-option label="已审核" value="AUDITED" />
          <el-option label="已记账" value="POSTED" />
          <el-option label="已打印" value="PRINTED" />
          <el-option label="已作废" value="VOIDED" />
        </el-select>
      </label>
      <label class="query-field">
        <span>摘要关键字</span>
        <el-input v-model="topFilter.summary_keyword" placeholder="输入摘要关键字" :prefix-icon="Search" clearable />
      </label>
      <div class="voucher-query-actions">
        <el-button type="primary" :icon="Search" @click="load">查询</el-button>
        <el-button :icon="RefreshLeft" @click="resetFilters">重置</el-button>
        <el-button :class="{ 'has-advanced-filter': advancedHasApplied }" :icon="Filter" @click="openAdvanced">
          高级筛选
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
      <el-table v-loading="loading" :data="rows" height="calc(100vh - 420px)">
        <el-table-column prop="voucher_no" label="凭证号" width="110" align="center">
          <template #default="{ row }">
            <span class="text-mono" style="color: var(--brand-blue); font-weight: 600">
              {{ row.voucher_word || '记' }}-{{ String(row.voucher_no).padStart(4, '0') }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="voucher_date" label="制单日期" width="130" align="center" />
        <el-table-column prop="summary" label="摘要" min-width="240" />
        <el-table-column prop="source_type" label="来源" width="110" align="center">
          <template #default="{ row }">{{ sourceTypeText(row.source_type) }}</template>
        </el-table-column>
        <el-table-column prop="prepared_by" label="制单人" width="120" align="center" />
        <el-table-column prop="audit_by" label="审核人" width="120" align="center" />
        <el-table-column prop="posted_by" label="记账人" width="120" align="center" />
        <el-table-column prop="status" label="状态" width="110" align="center">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" effect="light" size="small">{{ statusText(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="220" fixed="right" align="center">
          <template #default="{ row }">
            <el-button link type="primary" :icon="View" @click="openDetail(row)">查看</el-button>
            <el-button v-if="row.status === 'SUBMITTED' && context.hasPermission('voucher:audit')" link type="success" :icon="Check" @click="audit(row)">审核</el-button>
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
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { Check, Filter, Plus, Refresh, RefreshLeft, Search, Tickets, View } from '@element-plus/icons-vue'
import { voucherApi } from '../../api/voucher'
import { useContextStore } from '../../stores/context'

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
const pageTotal = ref(0)
const loading = ref(false)
const advancedVisible = ref(false)
const dateRange = ref<string[]>([])

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

const audit = async (row: any) => {
  await voucherApi.audit(topFilter.period, row.voucher_id)
  ElMessage.success('审核完成')
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
  ({ MANUAL: '手工录入', AUTO_CARRY: '自动结转', RED_REVERSAL: '红字冲销', BUSINESS: '业务生成' }[sourceType] || sourceType || '-')

onMounted(load)
</script>
