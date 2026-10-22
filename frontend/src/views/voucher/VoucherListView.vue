<template>
  <div class="voucher-list-page">
    <div class="search-filter-section voucher-query-section compact">
      <div class="voucher-query-toolbar">
        <div class="voucher-query-grid compact">
          <label class="query-field">
            <span>期间起</span>
            <el-select v-model="topFilter.period_start" placeholder="起始期间" size="small" style="width: 112px">
              <el-option v-for="period in periodOptions" :key="period" :label="period" :value="period" />
            </el-select>
          </label>
          <label class="query-field">
            <span>期间止</span>
            <el-select v-model="topFilter.period_end" placeholder="结束期间" size="small" style="width: 112px">
              <el-option v-for="period in periodOptions" :key="period" :label="period" :value="period" />
            </el-select>
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
              size="small"
              style="width: 236px"
            />
          </label>
          <label class="query-field">
            <span>状态</span>
            <el-select v-model="topFilter.status" placeholder="全部" clearable size="small" style="width: 112px">
              <el-option label="未审核" value="SUBMITTED" />
              <el-option label="已审核" value="AUDITED" />
              <el-option label="已记账" value="POSTED" />
              <el-option label="已打印" value="PRINTED" />
            </el-select>
          </label>
          <label class="query-field voucher-summary-query">
            <span>摘要</span>
            <el-input v-model="topFilter.summary_keyword" placeholder="关键字" :prefix-icon="Search" clearable size="small" />
          </label>
          <div class="voucher-query-actions">
            <el-button type="primary" :icon="Search" size="small" @click="query">查询</el-button>
            <el-button :class="{ 'has-advanced-filter': advancedHasApplied }" :icon="Filter" size="small" @click="openAdvanced">
              高级
              <span v-if="advancedHasApplied" class="filter-red-dot" />
            </el-button>
          </div>
        </div>
        <div class="voucher-toolbar-primary">
          <el-button
            v-if="context.hasPermission('voucher:print')"
            plain
            :icon="Printer"
            :loading="printing"
            :disabled="selectedRows.length === 0"
            size="small"
            @click="printSelectedVouchers"
          >
            打印凭证
          </el-button>
          <el-dropdown v-if="hasBatchActions" trigger="click" @command="handleBatchCommand">
            <el-button plain :icon="Operation" :loading="importing || exporting" size="small">
              批量操作
              <el-icon class="el-icon--right"><ArrowDown /></el-icon>
            </el-button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item v-if="context.hasPermission('voucher:audit')" command="audit" :disabled="selectedRows.length === 0">
                  批量审核
                </el-dropdown-item>
                <el-dropdown-item v-if="context.hasPermission('voucher:unaudit')" command="unaudit" :disabled="selectedRows.length === 0">
                  批量取消审核
                </el-dropdown-item>
                <el-dropdown-item v-if="context.hasPermission('voucher:post')" command="post" :disabled="selectedRows.length === 0">
                  批量记账
                </el-dropdown-item>
                <el-dropdown-item v-if="context.hasPermission('voucher:unpost')" command="unpost" :disabled="selectedRows.length === 0">
                  批量反记账
                </el-dropdown-item>
                <el-dropdown-item v-if="context.hasPermission('voucher:delete')" command="delete" :disabled="selectedRows.length === 0">
                  批量删除
                </el-dropdown-item>
                <el-dropdown-item v-if="context.hasPermission('voucher:import')" command="import">
                  批量导入
                </el-dropdown-item>
                <el-dropdown-item v-if="context.hasPermission('voucher:export')" command="export" :disabled="exporting || loading">
                  批量导出
                </el-dropdown-item>
                <el-dropdown-item v-if="context.hasPermission('voucher:renumber')" command="renumber">
                  凭证号整理
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
          <el-button v-if="context.hasPermission('voucher:add')" type="primary" :icon="Plus" size="small" @click="$router.push('/vouchers/new')">新增凭证</el-button>
        </div>
      </div>
    </div>

    <div class="voucher-workbench-metrics">
      <div class="voucher-metric">
        <span>本期凭证数</span>
        <strong>{{ pageTotal }}</strong>
      </div>
      <div class="voucher-metric">
        <span>未审核</span>
        <strong>{{ pendingReviewCount }}</strong>
      </div>
      <div class="voucher-metric">
        <span>已记账</span>
        <strong>{{ postedCount }}</strong>
      </div>
      <div class="voucher-metric" :class="{ balanced: pageBalanceDiffCents === 0, unbalanced: pageBalanceDiffCents !== 0 }">
        <span>本页借贷</span>
        <strong>{{ pageBalanceDiffCents === 0 ? '平衡' : '差额' }}</strong>
      </div>
    </div>

    <div class="panel voucher-list-panel">
      <div class="panel-header">
        <strong>
          <el-icon><Tickets /></el-icon>
          凭证列表 · {{ periodRangeLabel }}
        </strong>
        <span class="muted">共 {{ pageTotal }} 条记录</span>
      </div>
      <div class="panel-body compact">
        <el-table v-loading="loading" :data="rows" height="100%" @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="46" :selectable="canSelectRow" />
        <el-table-column prop="voucher_no" label="凭证号" width="92" align="left">
          <template #default="{ row }">
            <el-link type="primary" class="text-mono voucher-no-text" @click.stop="openDetail(row)">
              {{ row.voucher_word || '记' }}-{{ String(row.voucher_no).padStart(4, '0') }}
            </el-link>
          </template>
        </el-table-column>
        <el-table-column prop="voucher_date" label="制单日期" width="112" align="left">
          <template #default="{ row }">
            <span class="voucher-date-text">{{ row.voucher_date }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="summary" label="摘要" min-width="420" align="left">
          <template #default="{ row }">
            <span class="voucher-summary-text">{{ row.summary }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="debit_amount" label="借方金额" width="132" align="right" header-align="right">
          <template #default="{ row }">
            <span :class="voucherAmountClass(row.debit_amount || row.debitAmount)">
              {{ amountText(row.debit_amount || row.debitAmount) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="credit_amount" label="贷方金额" width="132" align="right" header-align="right">
          <template #default="{ row }">
            <span :class="voucherAmountClass(row.credit_amount || row.creditAmount)">
              {{ amountText(row.credit_amount || row.creditAmount) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="source_type" label="来源" width="110" align="center">
          <template #default="{ row }">{{ sourceTypeText(row.source_type) }}</template>
        </el-table-column>
        <el-table-column prop="prepared_by_name" label="制单人" width="76" align="center" show-overflow-tooltip>
          <template #default="{ row }">{{ row.prepared_by_name || row.prepared_by || '-' }}</template>
        </el-table-column>
        <el-table-column prop="audit_by_name" label="审核人" width="76" align="center" show-overflow-tooltip>
          <template #default="{ row }">{{ row.audit_by_name || row.audit_by || '-' }}</template>
        </el-table-column>
        <el-table-column prop="posted_by_name" label="记账人" width="76" align="center" show-overflow-tooltip>
          <template #default="{ row }">{{ row.posted_by_name || row.posted_by || '-' }}</template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="110" align="center">
          <template #default="{ row }">
            <div class="status-tags">
              <el-tag :type="statusType(statusForRow(row))" effect="light" size="small">{{ statusText(statusForRow(row)) }}</el-tag>
              <el-tag v-if="isPrintedRow(row)" type="primary" effect="plain" size="small">已打印</el-tag>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="220" fixed="right" align="center">
          <template #default="{ row }">
            <el-button v-if="canEditRow(row)" class="voucher-operation-button" link :icon="Edit" @click="openEdit(row)">编辑</el-button>
            <el-button v-if="canAuditRow(row)" class="voucher-operation-button" link :icon="Check" @click="audit(row)">审核</el-button>
            <el-button v-if="canUnauditRow(row)" class="voucher-operation-button" link :icon="RefreshLeft" @click="unaudit(row)">取消审核</el-button>
            <el-button v-if="canPostRow(row)" class="voucher-operation-button" link :icon="Check" @click="post(row)">记账</el-button>
            <el-button v-if="canUnpostRow(row)" class="voucher-operation-button" link :icon="RefreshLeft" @click="unpost(row)">反记账</el-button>
            <el-button v-if="canDeleteRow(row)" class="voucher-operation-button is-delete" link :icon="DeleteIcon" @click="deleteOne(row)">删除</el-button>
          </template>
        </el-table-column>
        </el-table>
        <div class="voucher-total-bar">
          <span>本页借方合计：<strong class="text-mono">{{ pageDebitTotal }}</strong></span>
          <span>本页贷方合计：<strong class="text-mono">{{ pageCreditTotal }}</strong></span>
          <span :class="{ 'is-balanced': pageBalanceDiffCents === 0, 'is-unbalanced': pageBalanceDiffCents !== 0 }">
            差额：<strong class="text-mono">{{ balanceDiffText }}</strong>
          </span>
        </div>
        <div class="pagination-bar fixed">
          <el-pagination
            v-model:current-page="page"
            v-model:page-size="pageSize"
            :page-sizes="[20, 50, 100, 200]"
            :total="pageTotal"
            layout="total, sizes, prev, pager, next"
            @current-change="load"
            @size-change="handlePageSizeChange"
          />
        </div>
      </div>
    </div>
  </div>

  <el-dialog v-model="advancedVisible" title="高级筛选" width="760px" class="finance-dialog">
    <div class="advanced-filter-grid">
      <label class="query-field">
        <span>凭证号区间</span>
        <div class="range-inputs">
          <el-input v-model="advancedDraft.voucher_no_start" placeholder="起始号" clearable />
          <em>-</em>
          <el-input v-model="advancedDraft.voucher_no_end" placeholder="结束号" clearable />
        </div>
      </label>
      <label class="query-field">
        <span>借方科目</span>
        <el-select
          v-model="advancedDraft.debit_subject_code"
          filterable
          clearable
          :loading="subjectOptionsLoading"
          placeholder="选择借方科目"
        >
          <el-option
            v-for="subject in subjectOptions"
            :key="`debit-${subjectCodeOf(subject)}`"
            :label="subjectOptionLabel(subject)"
            :value="subjectCodeOf(subject)"
          />
        </el-select>
      </label>
      <label class="query-field">
        <span>贷方科目</span>
        <el-select
          v-model="advancedDraft.credit_subject_code"
          filterable
          clearable
          :loading="subjectOptionsLoading"
          placeholder="选择贷方科目"
        >
          <el-option
            v-for="subject in subjectOptions"
            :key="`credit-${subjectCodeOf(subject)}`"
            :label="subjectOptionLabel(subject)"
            :value="subjectCodeOf(subject)"
          />
        </el-select>
      </label>
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

  <el-dialog
    v-model="renumberVisible"
    title="凭证号整理"
    width="820px"
    class="finance-dialog"
    :close-on-click-modal="false"
    @close="closeRenumberDialog"
  >
    <div class="renumber-dialog-content">
      <el-alert
        title="整理将按制单日期重排本期间凭证号，同一天缴款凭证在前、退款凭证在后。已打印凭证保留原号并锁定，只整理未打印凭证；已记账、已作废或已结账期间不能整理。"
        type="warning"
        :closable="false"
        show-icon
      />
      <div class="renumber-form-row">
        <label class="query-field">
          <span>整理期间</span>
          <el-select v-model="renumberForm.period" placeholder="期间" style="width: 130px">
            <el-option v-for="period in periodOptions" :key="period" :label="period" :value="period" />
          </el-select>
        </label>
        <label class="query-field">
          <span>起始号</span>
          <el-input-number v-model="renumberForm.startNo" :min="1" :step="1" controls-position="right" style="width: 130px" />
        </label>
        <el-button type="primary" :loading="renumberChecking" @click="checkRenumber">检查</el-button>
      </div>
      <div v-if="renumberPreviewRows.length > 0" class="renumber-summary">
        <span>凭证 {{ renumberPreview?.voucher_count || 0 }} 张</span>
        <span>变更 {{ renumberPreview?.change_count || 0 }} 张</span>
        <span>锁定 {{ renumberPreview?.locked_count || 0 }} 张</span>
        <span>{{ renumberPreview?.has_gap ? '存在断号' : '无断号' }}</span>
      </div>
      <el-table v-if="renumberPreviewRows.length > 0" :data="renumberPreviewRows" max-height="340" size="small" border>
        <el-table-column label="原凭证号" width="100" align="center">
          <template #default="{ row }">{{ row.voucher_word || '记' }}-{{ String(row.old_voucher_no).padStart(4, '0') }}</template>
        </el-table-column>
        <el-table-column label="新凭证号" width="100" align="center">
          <template #default="{ row }">
            <span :class="{ 'amount-debit': row.changed }">{{ row.voucher_word || '记' }}-{{ String(row.new_voucher_no).padStart(4, '0') }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="voucher_date" label="制单日期" width="110" align="center" />
        <el-table-column prop="summary" label="摘要" min-width="220" show-overflow-tooltip />
        <el-table-column prop="source_type" label="来源" width="90" align="center">
          <template #default="{ row }">{{ sourceTypeText(row.source_type) }}</template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="90" align="center">
          <template #default="{ row }">{{ statusText(row.status) }}</template>
        </el-table-column>
        <el-table-column label="是否变更" width="86" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.locked" type="info" size="small">已锁定</el-tag>
            <el-tag v-else-if="row.changed" type="warning" size="small">变更</el-tag>
            <span v-else class="muted">不变</span>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-else-if="renumberChecked" description="本期间暂无需要展示的凭证整理结果" />
    </div>
    <template #footer>
      <el-button @click="closeRenumberDialog">取消</el-button>
      <el-button type="primary" :loading="renumberSubmitting" :disabled="!renumberChecked" @click="confirmRenumber">确认整理</el-button>
    </template>
  </el-dialog>

  <!-- 批量导入凭证对话框 -->
  <el-dialog
    v-model="importVisible"
    title="批量导入凭证"
    width="560px"
    class="finance-dialog"
    :close-on-click-modal="false"
    :close-on-press-escape="!importing"
    :show-close="!importing"
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
          <el-button link type="primary" :disabled="importing" @click="chooseImportFile">重新选择</el-button>
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

        <div v-if="importing && importProgress.total > 0" class="import-progress">
          <el-progress :percentage="importProgressPercent" :stroke-width="10" />
          <p>
            正在导入第 {{ importProgress.batch }} / {{ importProgress.batchCount }} 批，
            已确认处理 {{ importProgress.completed }} / {{ importProgress.total }} 张
          </p>
        </div>

        <div v-if="importErrorList.length > 0" class="import-errors">
          <p class="error-title">{{ importErrorTitle }}（{{ importErrorList.length }} 条）</p>
          <el-scrollbar max-height="120px">
            <ul>
              <li v-for="(err, idx) in importErrorList" :key="idx">{{ err }}</li>
            </ul>
          </el-scrollbar>
        </div>
      </div>
    </div>

    <template #footer>
      <el-button :disabled="importing" @click="closeImportDialog">取消</el-button>
      <el-button
        type="primary"
        :loading="importing"
        :disabled="!importPreview || importPreview.voucherCount === 0 || importVouchers.length === 0"
        @click="confirmImport"
      >
        确认导入
      </el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowDown, Check, Delete as DeleteIcon, Document, Edit, Filter, Operation, Plus, Printer, RefreshLeft, Search, Tickets, Upload } from '@element-plus/icons-vue'
import { baseApi } from '../../api/base'
import { voucherApi } from '../../api/voucher'
import { useContextStore } from '../../stores/context'
import type { Subject } from '../../types/api'
import * as XLSX from 'xlsx'
import { buildVoucherExportWorkbook } from '../../utils/voucherExport'

defineOptions({ name: 'VoucherListView' })

type TopFilter = {
  year: string
  period_start: string
  period_end: string
  status: string
  summary_keyword: string
}

type AdvancedFilter = {
  voucher_no_start: string
  voucher_no_end: string
  debit_subject_code: string
  credit_subject_code: string
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
const route = useRoute()
const router = useRouter()
const subjects = ref<Subject[]>([])
const subjectOptionsLoading = ref(false)
const rows = ref<any[]>([])
const selectedRows = ref<any[]>([])
const pageTotal = ref(0)
const page = ref(1)
const pageSize = ref(50)
const loading = ref(false)
const advancedVisible = ref(false)
const dateRange = ref<string[]>([])
const importVisible = ref(false)
const importing = ref(false)
const printing = ref(false)
const importFileName = ref('')
const importPreview = ref<{ voucherCount: number; detailCount: number; periodRange: string } | null>(null)
const importErrorList = ref<string[]>([])
const importErrorTitle = ref('解析警告')
const importInputRef = ref<HTMLInputElement | null>(null)
const importVouchers = ref<any[]>([])
const VOUCHER_IMPORT_BATCH_SIZE = 200
const importProgress = reactive({ completed: 0, total: 0, batch: 0, batchCount: 0 })
const importProgressPercent = computed(() => {
  if (importProgress.total === 0) return 0
  return Math.min(100, Math.round((importProgress.completed / importProgress.total) * 100))
})
const renumberVisible = ref(false)
const renumberChecking = ref(false)
const renumberSubmitting = ref(false)
const renumberChecked = ref(false)
const renumberPreview = ref<any | null>(null)
const renumberPreviewRows = ref<any[]>([])
const renumberForm = reactive({
  period: context.period,
  startNo: 1
})

const routeQueryText = (value: unknown) => {
  const target = Array.isArray(value) ? value[0] : value
  return String(target ?? '').trim()
}

const normalizeVoucherPeriod = (value: unknown) => {
  const period = routeQueryText(value)
  return /^\d{4}-(0[1-9]|1[0-2])$/.test(period) ? period : ''
}

const initialPeriod = normalizeVoucherPeriod(route.query.period) || context.period
const defaultYear = initialPeriod.slice(0, 4)
const topFilter = reactive<TopFilter>({
  year: defaultYear,
  period_start: initialPeriod,
  period_end: initialPeriod,
  status: '',
  summary_keyword: ''
})

const emptyAdvanced = (): AdvancedFilter => ({
  voucher_no_start: '',
  voucher_no_end: '',
  debit_subject_code: '',
  credit_subject_code: '',
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

const subjectCodeOf = (subject: Subject) => String(subject.subject_code || subject.subjectCode || '')
const subjectNameOf = (subject: Subject) => String(subject.subject_name || subject.subjectName || '')
const subjectOptionLabel = (subject: Subject) => `${subjectCodeOf(subject)} ${subjectNameOf(subject)}`.trim()
const subjectOptions = computed(() => {
  const parentCodes = new Set(subjects.value.map((subject) => String(subject.parent_code || subject.parentCode || '')).filter(Boolean))
  return subjects.value.filter((subject) => {
    const code = subjectCodeOf(subject)
    return code !== '' && !parentCodes.has(code)
  })
})

const periodOptions = computed(() =>
  Array.from({ length: 12 }, (_, index) => `${topFilter.year}-${String(index + 1).padStart(2, '0')}`)
)
const periodRangeLabel = computed(() => {
  if (topFilter.period_start === topFilter.period_end) {
    return topFilter.period_start
  }
  return `${topFilter.period_start} ~ ${topFilter.period_end}`
})

const showFundAuxFilters = computed(() => ['CASE_FUND', 'LITIGATION_FEE'].includes(context.bizType))
const hasBatchActions = computed(() =>
  context.hasPermission('voucher:export') ||
  context.hasPermission('voucher:audit') ||
  context.hasPermission('voucher:unaudit') ||
  context.hasPermission('voucher:post') ||
  context.hasPermission('voucher:unpost') ||
  context.hasPermission('voucher:delete') ||
  context.hasPermission('voucher:import') ||
  context.hasPermission('voucher:renumber')
)

const cleanFilter = (filter: Record<string, any>) =>
  Object.fromEntries(Object.entries(filter).filter(([, value]) => value !== undefined && value !== null && String(value).trim() !== ''))

const advancedHasApplied = computed(() => Object.keys(cleanFilter(advancedApplied.value)).length > 0)
const toAmountCents = (value: any) => Math.round(Number(value || 0) * 100)
const formatCents = (cents: number) => (cents / 100).toFixed(2)
const pageDebitTotalCents = computed(() =>
  rows.value.reduce((sum, row) => sum + toAmountCents(row.debit_amount || row.debitAmount), 0)
)
const pageCreditTotalCents = computed(() =>
  rows.value.reduce((sum, row) => sum + toAmountCents(row.credit_amount || row.creditAmount), 0)
)
const pageBalanceDiffCents = computed(() => pageDebitTotalCents.value - pageCreditTotalCents.value)
const pageDebitTotal = computed(() => formatCents(pageDebitTotalCents.value))
const pageCreditTotal = computed(() => formatCents(pageCreditTotalCents.value))
const balanceDiffText = computed(() => formatCents(Math.abs(pageBalanceDiffCents.value)))
const pendingReviewCount = computed(() =>
  rows.value.filter((row) => statusForRow(row) === 'SUBMITTED').length
)
const postedCount = computed(() =>
  rows.value.filter((row) => statusForRow(row) === 'POSTED').length
)

watch(
  () => topFilter.year,
  (year) => {
    const startMonth = topFilter.period_start.slice(5, 7) || context.period.slice(5, 7) || '01'
    const endMonth = topFilter.period_end.slice(5, 7) || startMonth
    topFilter.period_start = `${year}-${startMonth}`
    topFilter.period_end = `${year}-${endMonth}`
  }
)

watch(
  () => [topFilter.period_start, topFilter.period_end],
  ([start, end]) => {
    if (start && end && start > end) {
      topFilter.period_end = start
    }
  }
)

watch(
  () => context.period,
  async (period) => {
    topFilter.year = period.slice(0, 4)
    topFilter.period_start = period
    topFilter.period_end = period
    dateRange.value = []
    renumberForm.period = period
    page.value = 1
    if (route.path === '/vouchers' && (route.query.period || route.query.voucher_id)) {
      const nextQuery = { ...route.query }
      delete nextQuery.period
      delete nextQuery.voucher_id
      await router.replace({ path: route.path, query: nextQuery })
      return
    }
    await load()
  }
)

const buildQueryParams = () => {
  const [dateStart, dateEnd] = dateRange.value || []
  return cleanFilter({
    voucher_id: routeQueryText(route.query.voucher_id),
    year: topFilter.year,
    period_start: topFilter.period_start,
    period_end: topFilter.period_end,
    page: page.value,
    pagesize: pageSize.value,
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

const loadSubjectOptions = async () => {
  subjectOptionsLoading.value = true
  try {
    subjects.value = await baseApi.subjects()
  } finally {
    subjectOptionsLoading.value = false
  }
}

const query = async () => {
  page.value = 1
  await load()
}

const applyVoucherRouteFilter = () => {
  const voucherId = routeQueryText(route.query.voucher_id)
  if (!voucherId) return
  const period = normalizeVoucherPeriod(route.query.period) || context.period
  topFilter.year = period.slice(0, 4)
  topFilter.period_start = period
  topFilter.period_end = period
  topFilter.status = ''
  topFilter.summary_keyword = ''
  dateRange.value = []
  advancedApplied.value = emptyAdvanced()
}

watch(
  () => [route.path, route.query.period, route.query.voucher_id],
  async ([path]) => {
    if (path !== '/vouchers') return
    applyVoucherRouteFilter()
    await query()
  }
)

const handlePageSizeChange = async () => {
  page.value = 1
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
  await query()
}

const openDetail = (row: any) => {
  router.push(`/vouchers/detail/${row.period}/${row.voucher_id}`)
}

const openEdit = (row: any) => {
  router.push(`/vouchers/edit/${row.period}/${row.voucher_id}`)
}

const isPrintedRow = (row: any) => String(row.printed_flag ?? row.printedFlag ?? '') === '1' || row.status === 'PRINTED'
const statusForRow = (row: any) => {
  if (row.status !== 'PRINTED') {
    return row.status
  }
  return row.audit_by || row.auditBy || row.audit_time || row.auditTime ? 'AUDITED' : 'SUBMITTED'
}
const unreviewedStatuses = ['DRAFT', 'SUBMITTED']
const isUnreviewed = (row: any) => unreviewedStatuses.includes(statusForRow(row))
const isAutoCarry = (row: any) => row.source_type === 'AUTO_CARRY'
const canEditRow = (row: any) => context.hasPermission('voucher:edit') && isUnreviewed(row)
const canDeleteRow = (row: any) => context.hasPermission('voucher:delete') && isUnreviewed(row) && !isAutoCarry(row)
const canAuditRow = (row: any) => statusForRow(row) === 'SUBMITTED' && context.hasPermission('voucher:audit')
const canUnauditRow = (row: any) => statusForRow(row) === 'AUDITED' && context.hasPermission('voucher:unaudit')
const canPostRow = (row: any) => statusForRow(row) === 'AUDITED' && context.hasPermission('voucher:post')
const canUnpostRow = (row: any) => statusForRow(row) === 'POSTED' && context.hasPermission('voucher:unpost')
const canPrintRow = (row: any) => context.hasPermission('voucher:print') && statusForRow(row) !== 'VOIDED'
const canSelectRow = (row: any) => canDeleteRow(row) || canPrintRow(row) || canAuditRow(row) || canUnauditRow(row) || canPostRow(row) || canUnpostRow(row)

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
  const deletableRows = selectedRows.value.filter(canDeleteRow)
  if (deletableRows.length === 0) {
    ElMessage.warning('请选择可删除的未审核凭证')
    return
  }
  try {
    await ElMessageBox.confirm(`确认删除选中的 ${deletableRows.length} 张凭证？`, '批量删除凭证', {
      type: 'warning',
      confirmButtonText: '批量删除',
      cancelButtonText: '取消'
    })
    const rowsByPeriod = deletableRows.reduce((groups: Record<string, string[]>, row: any) => {
      const period = row.period || topFilter.period_start
      if (!groups[period]) groups[period] = []
      groups[period].push(row.voucher_id)
      return groups
    }, {})
    for (const [period, voucherIds] of Object.entries(rowsByPeriod)) {
      await voucherApi.batchRemove(period, voucherIds)
    }
    ElMessage.success('凭证已批量删除')
    selectedRows.value = []
    await load()
  } catch {
    // 用户取消时不提示。
  }
}

const printSelectedVouchers = async () => {
  if (printing.value) return
  const printableRows = selectedRows.value.filter(canPrintRow)
  if (printableRows.length === 0) {
    ElMessage.warning('请选择要打印的凭证')
    return
  }
  printing.value = true
  try {
    const pdf = await voucherApi.printPdf(printableRows.map((row) => ({
      period: row.period || topFilter.period_start,
      voucher_id: row.voucher_id
    })))
    const url = URL.createObjectURL(pdf)
    window.open(url, '_blank')
    ElMessage.success('凭证PDF已生成')
    await load()
  } catch (error: any) {
    ElMessage.error(error?.message || '生成凭证PDF失败')
  } finally {
    printing.value = false
  }
}

const exporting = ref(false)
const batchExport = async () => {
  if (exporting.value) return
  exporting.value = true
  const { page: _page, pagesize: _pagesize, ...filters } = buildQueryParams()
  try {
    const result = await voucherApi.exportData(filters)
    if (result.items.length === 0) {
      ElMessage.warning('当前筛选条件下没有可导出的凭证明细')
      return
    }
    XLSX.writeFile(buildVoucherExportWorkbook(result.items), `凭证明细_${filters.period_start}_${filters.period_end}.xlsx`)
    ElMessage.success(`已导出 ${result.voucher_count} 张凭证，共 ${result.items.length} 条分录`)
  } catch (error: any) {
    ElMessage.error(error?.message || '导出凭证明细失败')
  } finally {
    exporting.value = false
  }
}

const handleBatchCommand = (command: string | number | object) => {
  switch (command) {
    case 'audit':
      batchAudit()
      break
    case 'unaudit':
      batchUnaudit()
      break
    case 'post':
      batchPost()
      break
    case 'unpost':
      batchUnpost()
      break
    case 'delete':
      batchDelete()
      break
    case 'import':
      openImportDialog()
      break
    case 'export':
      batchExport()
      break
    case 'renumber':
      openRenumberDialog()
      break
  }
}

const openRenumberDialog = () => {
  renumberForm.period = topFilter.period_start === topFilter.period_end ? topFilter.period_start : context.period
  renumberForm.startNo = 1
  renumberPreview.value = null
  renumberPreviewRows.value = []
  renumberChecked.value = false
  renumberVisible.value = true
}

const closeRenumberDialog = () => {
  renumberVisible.value = false
  renumberPreview.value = null
  renumberPreviewRows.value = []
  renumberChecked.value = false
}

const checkRenumber = async () => {
  if (!renumberForm.period) {
    ElMessage.warning('请选择整理期间')
    return
  }
  renumberChecking.value = true
  try {
    const result: any = await voucherApi.renumberPreview({
      period: renumberForm.period,
      start_no: renumberForm.startNo
    })
    renumberPreview.value = result
    renumberPreviewRows.value = result.items || []
    renumberChecked.value = true
    ElMessage.success('凭证号整理检查完成')
  } catch (error: any) {
    ElMessage.error(error?.message || '凭证号整理检查失败')
  } finally {
    renumberChecking.value = false
  }
}

const confirmRenumber = async () => {
  if (!renumberChecked.value) {
    ElMessage.warning('请先检查凭证号整理结果')
    return
  }
  try {
    await ElMessageBox.confirm(`确认按制单日期整理 ${renumberForm.period} 的凭证号？`, '确认凭证号整理', {
      type: 'warning',
      confirmButtonText: '确认整理',
      cancelButtonText: '取消'
    })
    renumberSubmitting.value = true
    const result: any = await voucherApi.renumber({
      period: renumberForm.period,
      start_no: renumberForm.startNo
    })
    ElMessage.success(`凭证号整理完成，变更 ${result.renumbered_count || 0} 张`)
    closeRenumberDialog()
    await load()
  } catch (error: any) {
    if (error !== 'cancel') {
      ElMessage.error(error?.message || '凭证号整理失败')
    }
  } finally {
    renumberSubmitting.value = false
  }
}

const batchAudit = async () => {
  const auditableRows = selectedRows.value.filter(canAuditRow)
  if (auditableRows.length === 0) {
    ElMessage.warning('请选择可审核的未审核凭证')
    return
  }
  try {
    await ElMessageBox.confirm(`确认将选中的 ${auditableRows.length} 张未审核凭证审核？`, '批量审核', {
      type: 'warning',
      confirmButtonText: '批量审核',
      cancelButtonText: '取消'
    })
    const rowsByPeriod = auditableRows.reduce((groups: Record<string, string[]>, row: any) => {
      const period = row.period || topFilter.period_start
      if (!groups[period]) groups[period] = []
      groups[period].push(row.voucher_id)
      return groups
    }, {})
    for (const [period, voucherIds] of Object.entries(rowsByPeriod)) {
      await voucherApi.batchAudit(period, voucherIds)
    }
    ElMessage.success('凭证已批量审核')
    selectedRows.value = []
    await load()
  } catch {
    // 用户取消时不提示。
  }
}

const batchUnaudit = async () => {
  const unauditableRows = selectedRows.value.filter(canUnauditRow)
  if (unauditableRows.length === 0) {
    ElMessage.warning('请选择可取消审核的已审核凭证')
    return
  }
  try {
    await ElMessageBox.confirm(`确认取消选中 ${unauditableRows.length} 张已审核凭证的审核？`, '批量取消审核', {
      type: 'warning',
      confirmButtonText: '批量取消审核',
      cancelButtonText: '取消'
    })
    const rowsByPeriod = unauditableRows.reduce((groups: Record<string, string[]>, row: any) => {
      const period = row.period || topFilter.period_start
      if (!groups[period]) groups[period] = []
      groups[period].push(row.voucher_id)
      return groups
    }, {})
    for (const [period, voucherIds] of Object.entries(rowsByPeriod)) {
      await voucherApi.batchUnaudit(period, voucherIds)
    }
    ElMessage.success('凭证已批量取消审核')
    selectedRows.value = []
    await load()
  } catch {
    // 用户取消时不提示。
  }
}

const batchPost = async () => {
  const postableRows = selectedRows.value.filter(canPostRow)
  if (postableRows.length === 0) {
    ElMessage.warning('请选择可记账的已审核凭证')
    return
  }
  try {
    await ElMessageBox.confirm(`确认将选中的 ${postableRows.length} 张已审核凭证记账？`, '批量记账', {
      type: 'warning',
      confirmButtonText: '批量记账',
      cancelButtonText: '取消'
    })
    const rowsByPeriod = postableRows.reduce((groups: Record<string, string[]>, row: any) => {
      const period = row.period || topFilter.period_start
      if (!groups[period]) groups[period] = []
      groups[period].push(row.voucher_id)
      return groups
    }, {})
    for (const [period, voucherIds] of Object.entries(rowsByPeriod)) {
      await voucherApi.batchPost(period, voucherIds)
    }
    ElMessage.success('凭证已批量记账')
    selectedRows.value = []
    await load()
  } catch {
    // 用户取消时不提示。
  }
}

const batchUnpost = async () => {
  const unpostableRows = selectedRows.value.filter(canUnpostRow)
  if (unpostableRows.length === 0) {
    ElMessage.warning('请选择可反记账的已记账凭证')
    return
  }
  try {
    await ElMessageBox.confirm(`确认将选中的 ${unpostableRows.length} 张已记账凭证反记账？`, '批量反记账', {
      type: 'warning',
      confirmButtonText: '批量反记账',
      cancelButtonText: '取消'
    })
    const rowsByPeriod = unpostableRows.reduce((groups: Record<string, string[]>, row: any) => {
      const period = row.period || topFilter.period_start
      if (!groups[period]) groups[period] = []
      groups[period].push(row.voucher_id)
      return groups
    }, {})
    for (const [period, voucherIds] of Object.entries(rowsByPeriod)) {
      await voucherApi.batchUnpost(period, voucherIds)
    }
    ElMessage.success('凭证已批量反记账')
    selectedRows.value = []
    await load()
  } catch {
    // 用户取消时不提示。
  }
}

const audit = async (row: any) => {
  await voucherApi.audit(row.period, row.voucher_id)
  ElMessage.success('审核完成')
  await load()
}

const unaudit = async (row: any) => {
  await voucherApi.unaudit(row.period, row.voucher_id)
  ElMessage.success('已取消审核')
  await load()
}

const post = async (row: any) => {
  await voucherApi.post(row.period, row.voucher_id)
  ElMessage.success('记账完成')
  await load()
}

const unpost = async (row: any) => {
  await voucherApi.unpost(row.period, row.voucher_id)
  ElMessage.success('已反记账')
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
  return amount !== 0 ? amount.toFixed(2) : '-'
}

const voucherAmountClass = (value: any) => {
  const amount = Number(value || 0)
  if (amount < 0) {
    return ['text-mono', 'voucher-amount-text', 'amount-negative']
  }
  return amount > 0 ? ['text-mono', 'voucher-amount-text'] : ['text-mono', 'muted']
}

// === 批量导入 ===
const openImportDialog = () => {
  importVisible.value = true
}

const closeImportDialog = () => {
  if (importing.value) return
  importVisible.value = false
  importFileName.value = ''
  importPreview.value = null
  importErrorList.value = []
  importErrorTitle.value = '解析警告'
  importVouchers.value = []
  Object.assign(importProgress, { completed: 0, total: 0, batch: 0, batchCount: 0 })
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
  importErrorTitle.value = '解析警告'

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
        { col: '案号', labelCol: '案号', type: 'case_no' },
        { col: '收据号', labelCol: '收据号', type: 'receipt_no' },
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
    const voucherSummary = details.find((detail) => String(detail.summary || '').trim() !== '')?.summary || ''

    vouchers.push({
      period,
      voucher_date: dateStr,
      voucher_word: '记',
      voucher_no: voucherNo,
      summary: voucherSummary,
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
  const total = importVouchers.value.length
  const batchCount = Math.ceil(total / VOUCHER_IMPORT_BATCH_SIZE)
  const parseWarnings = [...importErrorList.value]
  let success = 0
  let failed = 0
  const errors: string[] = []
  Object.assign(importProgress, { completed: 0, total, batch: 0, batchCount })
  try {
    for (let offset = 0; offset < total; offset += VOUCHER_IMPORT_BATCH_SIZE) {
      const batch = importVouchers.value.slice(offset, offset + VOUCHER_IMPORT_BATCH_SIZE)
      importProgress.batch = Math.floor(offset / VOUCHER_IMPORT_BATCH_SIZE) + 1
      const result = await voucherApi.import({ vouchers: batch })
      success += result.success
      failed += result.failed
      errors.push(...result.errors.map((error) => offsetImportErrorNumber(error, offset)))
      importProgress.completed = Math.min(offset + batch.length, total)
    }

    const msg = `导入完成：成功 ${success} 张，失败 ${failed} 张`
    if (failed > 0) {
      ElMessage.warning(msg)
      importErrorTitle.value = '导入失败明细'
      importErrorList.value = [...parseWarnings, ...errors]
      importVouchers.value = []
    } else {
      ElMessage.success(msg)
      importing.value = false
      closeImportDialog()
      await load()
    }
  } catch (err: any) {
    const isRequestTimeout = err?.code === 'ECONNABORTED' || String(err?.message || '').toLowerCase().includes('timeout')
    if (isRequestTimeout) {
      ElMessage.warning(`第 ${importProgress.batch} 批请求等待超时，已确认导入 ${success} 张；后台可能仍在处理当前批次，请刷新列表确认，避免重复导入`)
    } else {
      ElMessage.error(`第 ${importProgress.batch} 批导入失败：` + (err.message || '未知错误'))
    }
    importErrorTitle.value = '导入中断明细'
    importErrorList.value = [
      ...parseWarnings,
      ...errors,
      `第 ${importProgress.batch} 批未能确认完成，已确认成功 ${success} 张、失败 ${failed} 张，请刷新凭证列表核对后再处理。`,
    ]
    importVouchers.value = []
    try {
      await load()
    } catch {
      // 保留导入中断明细，列表可由用户稍后手动刷新。
    }
  } finally {
    importing.value = false
  }
}

const offsetImportErrorNumber = (error: string, offset: number) =>
  error.replace(/^第(\d+)张/, (_, batchIndex) => `第${offset + Number(batchIndex)}张`)

onMounted(async () => {
  applyVoucherRouteFilter()
  await Promise.all([load(), loadSubjectOptions()])
})
</script>

<style scoped>
.import-file-input {
  display: none;
}

.renumber-dialog-content {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.renumber-form-row {
  display: flex;
  align-items: end;
  gap: 12px;
  flex-wrap: wrap;
}

.renumber-summary {
  display: flex;
  gap: 16px;
  color: var(--el-text-color-secondary);
  font-size: 13px;
}

.status-tags {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  flex-wrap: wrap;
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

.import-progress {
  margin-bottom: 16px;
  padding: 12px;
  background: var(--el-color-primary-light-9);
  border-radius: 6px;
}

.import-progress p {
  margin: 8px 0 0;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  text-align: center;
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
