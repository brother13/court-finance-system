<template>
  <div class="case-fund-board">
    <div class="case-fund-toolbar">
      <div class="case-fund-filters">
        <div class="bank-auto-field">
          <span>开户银行</span>
          <strong>{{ selectedBankName || '未配置开户银行' }}</strong>
        </div>
        <span class="date-filter-label">交易日期</span>
        <el-date-picker v-model="filters.date_start" type="date" value-format="YYYY-MM-DD" placeholder="开始日期" class="statement-date-picker" />
        <span class="date-separator">至</span>
        <el-date-picker v-model="filters.date_end" type="date" value-format="YYYY-MM-DD" placeholder="结束日期" class="statement-date-picker" />
        <el-select v-model="filters.direction" placeholder="收支方向" clearable class="direction-select">
          <el-option label="收入" value="CREDIT" />
          <el-option label="支出" value="DEBIT" />
        </el-select>
        <el-select v-model="filters.reconcile_status" aria-label="对账状态" placeholder="对账状态" clearable class="status-select">
          <el-option label="未对账" value="UNMATCHED" />
          <el-option label="已对账" value="MATCHED" />
          <el-option label="金额不符" value="AMOUNT_DIFF" />
          <el-option label="银行未匹配" value="BANK_ONLY" />
          <el-option label="重复候选" value="DUPLICATE" />
          <el-option label="已忽略" value="IGNORED" />
        </el-select>
        <el-input v-model="filters.keyword" placeholder="对方户名 / 账号 / 用途 / 流水号" clearable class="case-fund-search" @keyup.enter="query" />
        <el-button type="primary" :icon="Search" :loading="loading" @click="query">查询</el-button>
      </div>
      <div class="case-fund-actions">
        <input ref="importInputRef" type="file" accept=".xls,.xlsx" class="case-fund-file-input" @change="handleBankStatementImportFile" />
        <el-button :icon="Download" :loading="exporting" @click="exportReconcileResults">导出对账结果</el-button>
        <el-button v-if="reconcileJob" type="primary" plain @click="openReconcileJobDialog">后台任务</el-button>
        <el-button v-permission="'case_fund:reconcile'" type="success" :icon="DocumentChecked" :loading="reconciling" :disabled="reconcileJobBusy" @click="runAutoReconcile">自动对账</el-button>
        <el-button v-permission="'case_fund:delete'" type="danger" :icon="Delete" :loading="deleting" @click="handleDeleteBankStatements">删除</el-button>
        <el-button v-permission="'case_fund:import'" type="primary" :icon="Upload" :loading="importing" @click="chooseBankStatementImportFile">导入对账单</el-button>
      </div>
    </div>

    <el-alert v-if="reconcileRecoveryUnconfirmed" type="warning" show-icon :closable="false" class="case-fund-link-alert">
      <template #title>
        <span>上次自动对账任务的提交或运行结果尚未确认，为避免重复对账，自动对账已暂时锁定。</span>
        <el-button link type="warning" :loading="reconcileRecoveryLoading" @click="retryReconcileJobRecovery">重新确认</el-button>
      </template>
    </el-alert>

    <div class="case-fund-summary">
      <div>
        <span>流水笔数</span>
        <strong>{{ total }}</strong>
      </div>
      <div>
        <span>收入合计</span>
        <strong>{{ money(creditAmount) }}</strong>
      </div>
      <div>
        <span>支出合计</span>
        <strong>{{ money(debitAmount) }}</strong>
      </div>
      <div>
        <span>本页已对账</span>
        <strong>{{ statementStatusSummary.MATCHED || 0 }}</strong>
      </div>
      <div>
        <span>本页金额不符</span>
        <strong>{{ statementStatusSummary.AMOUNT_DIFF || 0 }}</strong>
      </div>
    </div>

    <div class="case-fund-table-shell">
      <el-table :data="rows" border height="620px" v-loading="loading" @selection-change="handleStatementSelectionChange">
        <el-table-column type="selection" width="55" :selectable="isStatementSelectable" fixed="left" />
        <el-table-column prop="transaction_time" label="交易时间" width="170" fixed="left" />
        <el-table-column prop="bank_name" label="银行" width="100" />
        <el-table-column prop="debit_amount" label="支出" width="120" align="right">
          <template #default="{ row }">{{ amountText(row.debit_amount) }}</template>
        </el-table-column>
        <el-table-column prop="credit_amount" label="收入" width="120" align="right">
          <template #default="{ row }">{{ amountText(row.credit_amount) }}</template>
        </el-table-column>
        <el-table-column prop="balance_amount" label="账户余额" width="140" align="right">
          <template #default="{ row }">{{ money(row.balance_amount) }}</template>
        </el-table-column>
        <el-table-column prop="counterparty_account_no" label="对方账号" min-width="190" show-overflow-tooltip />
        <el-table-column prop="counterparty_account_name" label="对方户名" min-width="150" show-overflow-tooltip />
        <el-table-column prop="counterparty_bank_name" label="对方开户行" min-width="220" show-overflow-tooltip />
        <el-table-column prop="purpose" label="用途" min-width="180" show-overflow-tooltip />
        <el-table-column prop="postscript" label="留言" min-width="180" show-overflow-tooltip />
        <el-table-column prop="bank_serial_no" label="交易流水号" min-width="150" show-overflow-tooltip />
        <el-table-column label="关联业务" width="250" fixed="right">
          <template #default="{ row }">
            <div v-if="primaryBusinessItem(row)" class="reconcile-business-cell">
              <el-link
                type="primary"
                :underline="false"
                class="reconcile-business-link"
                @click.stop="navigatePrimaryBusiness(row)"
              >
                {{ primaryBusinessLabel(row) }}
              </el-link>
              <span class="reconcile-business-amount">{{ money(primaryBusinessItem(row)?.biz_amount) }}</span>
            </div>
            <el-button
              v-else-if="businessReconcileItems(row).length > 0"
              link
              type="warning"
              @click.stop="openReconcileDetail(row)"
            >
              查看 {{ businessReconcileItems(row).length }} 条候选
            </el-button>
            <span v-else class="muted-text">未找到关联业务</span>
          </template>
        </el-table-column>
        <el-table-column prop="reconcile_status" label="对账状态" width="110" align="center" fixed="right">
          <template #default="{ row }">
            <el-tag
              effect="light"
              :type="reconcileStatusType(row.reconcile_status)"
              :class="{ 'reconcile-status-clickable': reconcileItems(row).length > 0 }"
              @click.stop="openReconcileDetail(row)"
            >
              {{ reconcileStatusLabel(row.reconcile_status) }}
            </el-tag>
          </template>
        </el-table-column>
      </el-table>
    </div>

    <div class="case-fund-pagination">
      <el-pagination
        v-model:current-page="page"
        v-model:page-size="pageSize"
        background
        layout="total, sizes, prev, pager, next"
        :page-sizes="[50, 100, 150, 200]"
        :total="total"
        @size-change="load"
        @current-change="load"
      />
    </div>

    <el-drawer v-model="reconcileDetailVisible" title="银行对账详情" size="760px">
      <template v-if="selectedStatement">
        <el-descriptions :column="2" border class="reconcile-bank-detail">
          <el-descriptions-item label="交易时间">{{ selectedStatement.transaction_time }}</el-descriptions-item>
          <el-descriptions-item label="收支方向">{{ selectedStatement.direction === 'CREDIT' ? '收入' : '支出' }}</el-descriptions-item>
          <el-descriptions-item label="银行金额">{{ money(statementAmount(selectedStatement)) }}</el-descriptions-item>
          <el-descriptions-item label="交易流水号">{{ selectedStatement.bank_serial_no || '-' }}</el-descriptions-item>
          <el-descriptions-item label="对方户名">{{ selectedStatement.counterparty_account_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="对方账号">{{ selectedStatement.counterparty_account_no || '-' }}</el-descriptions-item>
          <el-descriptions-item label="用途" :span="2">{{ selectedStatement.purpose || selectedStatement.postscript || '-' }}</el-descriptions-item>
        </el-descriptions>

        <div class="reconcile-detail-title">关联结果</div>
        <el-table :data="reconcileItems(selectedStatement)" border empty-text="暂无有效对账结果">
          <el-table-column label="业务来源" width="100">
            <template #default="{ row }">{{ row.biz_id ? businessTypeLabel(row.biz_type) : '-' }}</template>
          </el-table-column>
          <el-table-column label="业务编号" min-width="150" show-overflow-tooltip>
            <template #default="{ row }">{{ row.biz_id ? businessIdentifier(row) : '-' }}</template>
          </el-table-column>
          <el-table-column prop="biz_summary" label="业务摘要" min-width="190" show-overflow-tooltip />
          <el-table-column label="业务金额" width="110" align="right">
            <template #default="{ row }">{{ money(row.biz_amount) }}</template>
          </el-table-column>
          <el-table-column label="差额" width="105" align="right">
            <template #default="{ row }">
              <span :class="{ 'reconcile-diff': Number(row.diff_amount || 0) !== 0 }">{{ money(row.diff_amount) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="结果" width="100" align="center">
            <template #default="{ row }">
              <el-tag size="small" :type="reconcileStatusType(row.match_status)">{{ reconcileStatusLabel(row.match_status) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="100" fixed="right" align="center">
            <template #default="{ row }">
              <el-button v-if="row.biz_id && canNavigateBusiness(row)" link type="primary" @click="navigateToBusiness(row)">查看业务</el-button>
              <span v-else class="muted-text">-</span>
            </template>
          </el-table-column>
        </el-table>

        <el-descriptions v-if="selectedReconcileItem" :column="2" border class="reconcile-rule-detail">
          <el-descriptions-item label="匹配规则">{{ matchRuleLabel(selectedReconcileItem.match_rule) }}</el-descriptions-item>
          <el-descriptions-item label="对账时间">{{ selectedReconcileItem.matched_time || '-' }}</el-descriptions-item>
          <el-descriptions-item label="银行摘要" :span="2">{{ selectedReconcileItem.bank_summary || '-' }}</el-descriptions-item>
        </el-descriptions>
      </template>
    </el-drawer>

    <CaseFundJobProgressDialog
      v-model="reconcileJobDialogVisible"
      :job="reconcileJob"
      title="银行自动对账后台任务"
      job-type-label="银行自动对账"
      :poll-error="reconcileJobPollError"
      :status-loading="reconcileJobStatusLoading"
      @refresh="refreshReconcileJob"
      @view-errors="showReconcileJobErrors"
    />

  </div>
</template>

<script setup lang="ts">
import { computed, onActivated, onDeactivated, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Delete, DocumentChecked, Download, Search, Upload } from '@element-plus/icons-vue'
import * as XLSX from 'xlsx'
import { caseFundApi } from '../../api/caseFund'
import { useContextStore } from '../../stores/context'
import CaseFundJobProgressDialog from '../../components/case-fund/CaseFundJobProgressDialog.vue'
import { createCaseFundClientRequestId, isCaseFundJobTerminal, useCaseFundJob } from '../../composables/useCaseFundJob'
import type { CaseFundBankReconcile, CaseFundBankStatement, CaseFundJob } from '../../types/api'

const context = useContextStore()
const router = useRouter()
const bankOptions = [
  { code: 'SHENGJING', name: '盛京银行' },
  { code: 'CCB', name: '建设银行' }
]
const rows = ref<CaseFundBankStatement[]>([])
const total = ref(0)
const debitAmount = ref(0)
const creditAmount = ref(0)
const page = ref(1)
const pageSize = ref(150)
const loading = ref(false)
const importing = ref(false)
const exporting = ref(false)
const deleting = ref(false)
const importInputRef = ref<HTMLInputElement | null>(null)
const selectedStatementIds = ref<string[]>([])
const reconcileDetailVisible = ref(false)
const selectedStatement = ref<CaseFundBankStatement | null>(null)
const restoreReconcileDetailOnActivate = ref(false)
const initialRange = initialStatementDateRange(context.period)
const filters = reactive({
  bank_code: bankCodeFromAccountSetName(context.accountSetBankName),
  date_start: initialRange.start,
  date_end: initialRange.end,
  direction: '',
  reconcile_status: '',
  keyword: ''
})

const selectedBankName = computed(() => bankOptions.find((bank) => bank.code === filters.bank_code)?.name || context.accountSetBankName || '')
const statementStatusSummary = computed(() => rows.value.reduce<Record<string, number>>((summary, row) => {
  const status = row.reconcile_status || 'UNMATCHED'
  summary[status] = (summary[status] || 0) + 1
  return summary
}, {}))
const selectedReconcileItem = computed(() => reconcileItems(selectedStatement.value)[0] || null)

const statementListParams = (targetPage: number, targetPageSize: number, filterSnapshot = filters) => ({
  bank_code: filterSnapshot.bank_code,
  date_start: filterSnapshot.date_start,
  date_end: filterSnapshot.date_end,
  direction: filterSnapshot.direction,
  reconcile_status: filterSnapshot.reconcile_status,
  keyword: filterSnapshot.keyword,
  page: targetPage,
  pagesize: targetPageSize
})

const load = async () => {
  loading.value = true
  try {
    const result = await caseFundApi.bankStatementList(statementListParams(page.value, pageSize.value))
    rows.value = result.items || []
    total.value = result.total || 0
    debitAmount.value = Number(result.debit_amount || 0)
    creditAmount.value = Number(result.credit_amount || 0)
  } finally {
    loading.value = false
  }
}

const BANK_RECONCILE_JOB_TYPE = 'CASE_FUND_BANK_RECONCILE'
type PendingReconcileJobSubmission = {
  client_request_id: string
  job_type: string
}

const reconcileJobStorageKey = () => `court-finance-case-fund-job:${context.accountSetId}:${context.userId}:${context.year}:bank-statements`
const reconcilePendingStorageKey = () => `${reconcileJobStorageKey()}:pending`

const readStoredReconcileJobId = () => {
  try {
    return String(localStorage.getItem(reconcileJobStorageKey()) || '').trim()
  } catch {
    return ''
  }
}

const readPendingReconcileSubmission = (): PendingReconcileJobSubmission | null => {
  try {
    const raw = localStorage.getItem(reconcilePendingStorageKey())
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<PendingReconcileJobSubmission>
    const clientRequestId = String(parsed.client_request_id || '').trim()
    const jobType = String(parsed.job_type || '').trim()
    return clientRequestId && jobType ? { client_request_id: clientRequestId, job_type: jobType } : null
  } catch {
    return null
  }
}

const storedReconcileJobId = ref(readStoredReconcileJobId())
const pendingReconcileSubmission = ref<PendingReconcileJobSubmission | null>(readPendingReconcileSubmission())
const reconcileRecoveryUnconfirmed = ref(false)
const reconcileRecoveryLoading = ref(false)

const persistPendingReconcileSubmission = (pending: PendingReconcileJobSubmission) => {
  pendingReconcileSubmission.value = pending
  reconcileRecoveryUnconfirmed.value = false
  try {
    localStorage.setItem(reconcilePendingStorageKey(), JSON.stringify(pending))
  } catch {
    // 内存状态仍会阻止当前页面重复提交。
  }
}

const clearPendingReconcileSubmission = () => {
  pendingReconcileSubmission.value = null
  reconcileRecoveryUnconfirmed.value = false
  try {
    localStorage.removeItem(reconcilePendingStorageKey())
  } catch {
    // 本地存储清理失败不影响已经取得的任务状态。
  }
}

const persistReconcileJob = (job: CaseFundJob) => {
  storedReconcileJobId.value = job.job_id
  try {
    localStorage.setItem(reconcileJobStorageKey(), job.job_id)
    clearPendingReconcileSubmission()
  } catch {
    // 保留 pending，刷新后仍可按幂等号查回任务。
  }
}

const removeStoredReconcileJob = (jobId?: string) => {
  if (!jobId || storedReconcileJobId.value === jobId) {
    storedReconcileJobId.value = ''
  }
  try {
    const storageKey = reconcileJobStorageKey()
    if (!jobId || localStorage.getItem(storageKey) === jobId) {
      localStorage.removeItem(storageKey)
    }
  } catch {
    // 本地存储清理失败不影响后台任务结果。
  }
}

const resultCount = (counts: Record<string, any>, status: string) => {
  const value = Number(counts?.[status] || 0)
  return Number.isFinite(value) ? value : 0
}

const reconcileResultText = (finishedJob: CaseFundJob) => {
  const result = finishedJob.result || {}
  const counts = (result.counts && typeof result.counts === 'object' ? result.counts : {}) as Record<string, any>
  const resultTotal = Number(result.total ?? finishedJob.success_count ?? finishedJob.total_count ?? 0)
  const totalText = Number.isFinite(resultTotal) ? resultTotal : 0
  return `结果${totalText}条，已匹配${resultCount(counts, 'MATCHED')}条，金额不符${resultCount(counts, 'AMOUNT_DIFF')}条，银行未匹配${resultCount(counts, 'BANK_ONLY')}条，业务未匹配${resultCount(counts, 'BIZ_ONLY')}条，重复候选${resultCount(counts, 'DUPLICATE')}条`
}

const refreshStatementsAfterReconcile = async () => {
  page.value = 1
  selectedStatementIds.value = []
  try {
    await load()
  } catch {
    ElMessage.warning('自动对账任务已经结束，但列表刷新失败，请稍后点击查询刷新结果')
  }
}

const handleReconcileJobFinished = (finishedJob: CaseFundJob) => {
  removeStoredReconcileJob(finishedJob.job_id)
  clearPendingReconcileSubmission()
  const resultText = reconcileResultText(finishedJob)
  if (finishedJob.status === 'SUCCEEDED') {
    ElMessage.success(`自动对账完成：${resultText}`)
  } else if (finishedJob.status === 'PARTIAL_FAILED') {
    ElMessage.warning(`自动对账部分完成：${resultText}；失败${Number(finishedJob.failure_count || 0)}条，请查看错误明细`)
  } else {
    ElMessage.error(finishedJob.error_message || '银行自动对账后台任务执行失败')
  }
  void refreshStatementsAfterReconcile()
}

const {
  job: reconcileJob,
  dialogVisible: reconcileJobDialogVisible,
  creating: reconcileJobCreating,
  statusLoading: reconcileJobStatusLoading,
  createError: reconcileJobCreateError,
  pollError: reconcileJobPollError,
  isActive: reconcileJobActive,
  createJob: createReconcileJob,
  trackJob: trackReconcileJob,
  resumeJob: resumeReconcileJob,
  refreshJob: refreshReconcileJob,
  openDialog: openReconcileJobDialog
} = useCaseFundJob({
  onUpdated: persistReconcileJob,
  onFinished: handleReconcileJobFinished
})

const reconcileJobUnresolved = computed(() => (
  Boolean(pendingReconcileSubmission.value)
  || Boolean(storedReconcileJobId.value && !reconcileJob.value)
))
const reconcileJobBusy = computed(() => (
  reconcileJobCreating.value
  || reconcileJobActive.value
  || reconcileJobUnresolved.value
  || reconcileRecoveryLoading.value
))
const reconciling = computed(() => reconcileJobCreating.value || reconcileJobActive.value)

const recoverPendingReconcileJob = async (showMessage = true) => {
  const pending = pendingReconcileSubmission.value
  if (!pending) return null
  reconcileRecoveryLoading.value = true
  try {
    const result = await caseFundApi.jobList({
      client_request_id: pending.client_request_id,
      job_type: pending.job_type,
      page: 1,
      pagesize: 10
    })
    const recoveredJob = result.items.find((item) => (
      item.client_request_id === pending.client_request_id
      && item.job_type === pending.job_type
    ))
    if (!recoveredJob) {
      // 只有精确任务查询成功且确认不存在时才解除锁定；查询失败仍保留 pending，
      // 避免把“暂时查不到”误判成“任务未创建”而造成重复对账。
      clearPendingReconcileSubmission()
      if (showMessage) {
        ElMessage.info('已确认后台不存在上次待提交的自动对账任务，操作锁定已解除，请重新发起操作')
      }
      return null
    }
    reconcileRecoveryUnconfirmed.value = false
    trackReconcileJob(recoveredJob, true)
    if (showMessage && !isCaseFundJobTerminal(recoveredJob.status)) {
      ElMessage.info('已恢复上次待确认的银行自动对账后台任务')
    }
    return recoveredJob
  } catch (error: any) {
    reconcileRecoveryUnconfirmed.value = true
    if (showMessage) {
      ElMessage.warning(error?.message || '暂时无法确认上次自动对账任务状态，原任务可能仍在运行，请勿重复提交')
    }
    return null
  } finally {
    reconcileRecoveryLoading.value = false
  }
}

const restoreReconcileJob = async (showMessage = true) => {
  const jobId = storedReconcileJobId.value
  if (!jobId) return recoverPendingReconcileJob(showMessage)
  reconcileRecoveryLoading.value = true
  try {
    const restoredJob = await resumeReconcileJob(jobId, true)
    reconcileRecoveryUnconfirmed.value = false
    if (showMessage && !isCaseFundJobTerminal(restoredJob.status)) {
      ElMessage.info('已恢复上次未完成的银行自动对账后台任务')
    }
    return restoredJob
  } catch (error: any) {
    reconcileRecoveryUnconfirmed.value = true
    if (showMessage) {
      ElMessage.warning(error?.message || '暂时无法恢复自动对账任务状态，原任务可能仍在运行，请勿重复提交')
    }
    return null
  } finally {
    reconcileRecoveryLoading.value = false
  }
}

const retryReconcileJobRecovery = () => { void restoreReconcileJob(true) }

const keepOrClearPendingReconcileSubmission = (error: any) => {
  if (error?.result) {
    clearPendingReconcileSubmission()
    return
  }
  if (pendingReconcileSubmission.value) {
    reconcileRecoveryUnconfirmed.value = true
  }
}

const query = async () => {
  page.value = 1
  await load()
}

const exportPageSize = 200

const loadAllBankStatements = async (filterSnapshot: typeof filters) => {
  const firstPage = await caseFundApi.bankStatementList(statementListParams(1, exportPageSize, filterSnapshot))
  const items = [...(firstPage.items || [])]
  const total = Number(firstPage.total || items.length || 0)
  const pageCount = Math.ceil(total / exportPageSize)
  for (let currentPage = 2; currentPage <= pageCount; currentPage += 1) {
    const result = await caseFundApi.bankStatementList(statementListParams(currentPage, exportPageSize, filterSnapshot))
    const pageItems = result.items || []
    if (pageItems.length === 0) break
    items.push(...pageItems)
  }
  return {
    items,
    total,
    debitAmount: Number(firstPage.debit_amount || 0),
    creditAmount: Number(firstPage.credit_amount || 0)
  }
}

const exportReconcileRows = (statements: CaseFundBankStatement[]) => statements.flatMap((statement) => {
  const items: Array<CaseFundBankReconcile | null> = reconcileItems(statement).length > 0
    ? reconcileItems(statement)
    : [null]
  return items.map((item) => [
    statement.transaction_time || statement.transaction_date || '',
    statement.bank_name || selectedBankName.value || statement.bank_code || '',
    statement.direction === 'CREDIT' ? '收入' : statement.direction === 'DEBIT' ? '支出' : '',
    Number(statement.debit_amount || 0),
    Number(statement.credit_amount || 0),
    Number(statement.balance_amount || 0),
    statement.counterparty_account_no || '',
    statement.counterparty_account_name || '',
    statement.counterparty_bank_name || '',
    statement.purpose || '',
    statement.postscript || '',
    statement.bank_serial_no || '',
    reconcileStatusLabel(statement.reconcile_status || 'UNMATCHED'),
    item?.biz_id ? businessTypeLabel(item.biz_type) : '',
    item?.biz_id ? businessIdentifier(item) : '',
    item?.biz_summary || '',
    item?.biz_id ? Number(item.biz_amount || 0) : null,
    item ? Number(item.diff_amount || 0) : null,
    item ? reconcileStatusLabel(item.match_status) : '',
    item ? matchRuleLabel(item.match_rule) : '',
    item?.matched_time || '',
    statement.statement_id || '',
    item?.reconcile_id || '',
    statement.source_file_name || '',
    statement.source_row_no || ''
  ])
})

const exportReconcileResults = async () => {
  if (exporting.value) return
  exporting.value = true
  const filterSnapshot = { ...filters }
  try {
    const result = await loadAllBankStatements(filterSnapshot)
    if (result.items.length === 0) {
      ElMessage.warning('当前筛选条件下没有可导出的银行对账结果')
      return
    }
    const headers = [
      '交易时间', '银行', '收支方向', '支出', '收入', '账户余额', '对方账号', '对方户名', '对方开户行',
      '用途', '留言', '交易流水号', '对账状态', '业务来源', '业务编号', '业务摘要', '业务金额', '差额',
      '匹配结果', '匹配规则', '对账时间', '银行流水ID', '对账结果ID', '源文件', '原始行号'
    ]
    const body = exportReconcileRows(result.items)
    const directionLabel = filterSnapshot.direction === 'CREDIT' ? '收入' : filterSnapshot.direction === 'DEBIT' ? '支出' : '全部'
    const statusLabel = filterSnapshot.reconcile_status
      ? reconcileStatusLabel(filterSnapshot.reconcile_status)
      : '全部状态'
    const filterText = [
      `账套：${context.accountSetName || '当前账套'}`,
      `开户银行：${selectedBankName.value || '全部'}`,
      `交易日期：${filterSnapshot.date_start || '不限'} 至 ${filterSnapshot.date_end || '不限'}`,
      `收支方向：${directionLabel}`,
      `对账状态：${statusLabel}`,
      `关键词：${filterSnapshot.keyword || '无'}`
    ].join('    ')
    const summaryText = `银行流水 ${result.total} 条    导出明细 ${body.length} 行    支出合计 ${money(result.debitAmount)}    收入合计 ${money(result.creditAmount)}`
    const aoa = [
      ['银行对账结果'],
      [filterText],
      [summaryText],
      headers,
      ...body
    ]
    const sheet = XLSX.utils.aoa_to_sheet(aoa)
    sheet['!merges'] = [
      { s: { r: 0, c: 0 }, e: { r: 0, c: headers.length - 1 } },
      { s: { r: 1, c: 0 }, e: { r: 1, c: headers.length - 1 } },
      { s: { r: 2, c: 0 }, e: { r: 2, c: headers.length - 1 } }
    ]
    sheet['!autofilter'] = { ref: `A4:Y${aoa.length}` }
    sheet['!cols'] = [
      { wch: 20 }, { wch: 12 }, { wch: 10 }, { wch: 14 }, { wch: 14 }, { wch: 14 },
      { wch: 22 }, { wch: 18 }, { wch: 24 }, { wch: 24 }, { wch: 24 }, { wch: 24 },
      { wch: 12 }, { wch: 12 }, { wch: 22 }, { wch: 28 }, { wch: 14 }, { wch: 14 },
      { wch: 12 }, { wch: 22 }, { wch: 20 }, { wch: 38 }, { wch: 38 }, { wch: 24 }, { wch: 12 }
    ]
    for (let rowNumber = 5; rowNumber <= aoa.length; rowNumber += 1) {
      ;['D', 'E', 'F', 'Q', 'R'].forEach((column) => {
        const cell = sheet[`${column}${rowNumber}`]
        if (cell) cell.z = '#,##0.00'
      })
    }
    const workbook = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(workbook, sheet, '银行对账结果')
    const filenameStatus = filterSnapshot.reconcile_status ? reconcileStatusLabel(filterSnapshot.reconcile_status) : '全部状态'
    XLSX.writeFile(workbook, `银行对账结果_${filterSnapshot.date_start || '全部'}_${filterSnapshot.date_end || '全部'}_${filenameStatus}.xlsx`)
    ElMessage.success(`已导出 ${result.total} 条银行流水、${body.length} 行对账明细`)
  } catch (error: any) {
    ElMessage.error(error?.message || '导出银行对账结果失败')
  } finally {
    exporting.value = false
  }
}

const runAutoReconcile = async () => {
  if (reconcileJobBusy.value) {
    ElMessage.warning('已有自动对账任务或待确认提交，请先确认任务状态')
    return
  }
  const clientRequestId = createCaseFundClientRequestId('bank-reconcile')
  persistPendingReconcileSubmission({
    client_request_id: clientRequestId,
    job_type: BANK_RECONCILE_JOB_TYPE
  })
  try {
    await createReconcileJob({
      job_type: BANK_RECONCILE_JOB_TYPE,
      client_request_id: clientRequestId,
      payload: {
        period: context.period,
        bank_code: filters.bank_code,
        date_start: filters.date_start,
        date_end: filters.date_end
      }
    })
    ElMessage.success('自动对账任务已提交，后台正在处理')
  } catch (error: any) {
    keepOrClearPendingReconcileSubmission(error)
    const message = reconcileJobCreateError.value || error?.message || '自动对账任务提交失败'
    error?.result ? ElMessage.error(message) : ElMessage.warning(message)
  }
}

const isStatementSelectable = (row: CaseFundBankStatement) => {
  return row.reconcile_status === 'UNMATCHED'
}

const handleStatementSelectionChange = (selection: CaseFundBankStatement[]) => {
  selectedStatementIds.value = selection.map((row) => row.statement_id)
}

const reconcileItems = (row: CaseFundBankStatement | null | undefined) => row?.reconcile_items || []

const businessReconcileItems = (row: CaseFundBankStatement | null | undefined) => {
  return reconcileItems(row).filter((item) => Boolean(item.biz_id))
}

const primaryBusinessItem = (row: CaseFundBankStatement | null | undefined) => {
  const items = businessReconcileItems(row)
  if (items.length !== 1 || row?.reconcile_status === 'DUPLICATE') return null
  return items[0]
}

const openReconcileDetail = (row: CaseFundBankStatement) => {
  if (reconcileItems(row).length === 0) return
  selectedStatement.value = row
  reconcileDetailVisible.value = true
}

const businessTypeLabel = (bizType: string | undefined) => {
  const labels: Record<string, string> = {
    PAYMENT: '缴费登记',
    UNKNOWN_PAYMENT: '不明款',
    REFUND: '出账查询'
  }
  return labels[bizType || ''] || '业务记录'
}

const businessIdentifier = (item: CaseFundBankReconcile) => {
  return String(item.biz_no || item.biz_summary || '查看记录').trim()
}

const primaryBusinessLabel = (row: CaseFundBankStatement) => {
  const item = primaryBusinessItem(row)
  return item ? `${businessTypeLabel(item.biz_type)} · ${businessIdentifier(item)}` : '-'
}

const businessNavigation = (item: CaseFundBankReconcile) => {
  if (!item.biz_id) return null
  if (item.biz_type === 'PAYMENT') {
    return { permission: 'menu:case_fund:payment', path: '/case-fund/payments', query: { payment_id: item.biz_id } }
  }
  if (item.biz_type === 'UNKNOWN_PAYMENT') {
    return { permission: 'menu:case_fund:unknown_payment', path: '/case-fund/unknown-payments', query: { unknown_payment_id: item.biz_id } }
  }
  if (item.biz_type === 'REFUND') {
    return { permission: 'menu:case_fund:refund', path: '/case-fund/refunds', query: { refund_id: item.biz_id } }
  }
  return null
}

const canNavigateBusiness = (item: CaseFundBankReconcile) => {
  const target = businessNavigation(item)
  return Boolean(target && context.hasPermission(target.permission))
}

const navigateToBusiness = async (item: CaseFundBankReconcile) => {
  const target = businessNavigation(item)
  if (!target) {
    ElMessage.warning('当前对账结果没有可查看的业务记录')
    return
  }
  if (!context.hasPermission(target.permission)) {
    ElMessage.warning('当前账号没有查看该业务页面的权限')
    return
  }
  restoreReconcileDetailOnActivate.value = Boolean(selectedStatement.value)
  reconcileDetailVisible.value = false
  await router.push({ path: target.path, query: target.query })
}

const navigatePrimaryBusiness = async (row: CaseFundBankStatement) => {
  const item = primaryBusinessItem(row)
  if (item) await navigateToBusiness(item)
}

const statementAmount = (row: CaseFundBankStatement) => {
  return row.direction === 'CREDIT' ? row.credit_amount : row.debit_amount
}

const matchRuleLabel = (rule: string | undefined) => {
  const labels: Record<string, string> = {
    SERIAL_NO: '流水号',
    SERIAL_NO_DATE: '交易日期 + 流水号',
    PAYER_ACCOUNT_DATE: '交易日期 + 付款账号',
    PAYEE_ACCOUNT_DATE: '交易日期 + 收款账号/户名',
    PAYEE_ACCOUNT_DATE_AMOUNT: '交易日期 + 收款账号/户名 + 出账金额'
  }
  return labels[rule || ''] || rule || '-'
}

const handleDeleteBankStatements = async () => {
  if (selectedStatementIds.value.length === 0) {
    ElMessage.warning('请选择要删除的未对账银行流水')
    return
  }
  try {
    await ElMessageBox.confirm(
      `确定要删除选中的 ${selectedStatementIds.value.length} 条银行流水吗？已对账的数据不能删除。`,
      '确认删除银行流水',
      { confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning' }
    )
  } catch {
    return
  }
  deleting.value = true
  try {
    const result = await caseFundApi.deleteBankStatements(selectedStatementIds.value)
    ElMessage.success(`已删除 ${result.deleted_count} 条银行流水`)
    selectedStatementIds.value = []
    await load()
  } catch (e: any) {
    ElMessage.error(e?.message || '删除银行流水失败')
  } finally {
    deleting.value = false
  }
}

const chooseBankStatementImportFile = () => {
  if (!filters.bank_code) {
    ElMessage.warning('当前账套未配置可识别的开户银行，请先在账套设置中维护开户银行')
    return
  }
  if (!importInputRef.value) return
  importInputRef.value.value = ''
  importInputRef.value.click()
}

const handleBankStatementImportFile = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  if (!/\.(xls|xlsx)$/i.test(file.name)) {
    ElMessage.warning('请选择 .xls 或 .xlsx 格式的银行电子对账单')
    return
  }
  importing.value = true
  try {
    const workbook = await readWorkbook(file)
    const statements = parseBankStatementRows(workbook)
    const result = await caseFundApi.importBankStatements(filters.bank_code, file.name, statements)
    ElMessage.success(`导入${selectedBankName.value}对账单完成：新增${result.created}条，跳过重复${result.skipped}条`)
    page.value = 1
    await load()
  } catch (e: any) {
    ElMessage.error(e?.message || '导入银行对账单失败')
  } finally {
    importing.value = false
  }
}

const parseBankStatementRows = (workbook: XLSX.WorkBook) => {
  if (filters.bank_code === 'SHENGJING') {
    return parseShengjingRows(workbook)
  }
  if (filters.bank_code === 'CCB') {
    return parseCcbRows(workbook)
  }
  throw new Error('当前银行模板暂未配置')
}

const parseShengjingRows = (workbook: XLSX.WorkBook) => {
  const sheetName = workbook.SheetNames[0]
  const sheet = workbook.Sheets[sheetName]
  const table = XLSX.utils.sheet_to_json<Record<string, any>>(sheet, { defval: '', raw: false })
  const required = ['交易时间', '支出', '收入', '账户余额', '交易对手账号', '交易对手户名', '交易对手开户行', '用途', '给收款人留言', '交易流水号']
  const first = table[0] || {}
  const missing = required.filter((name) => !(name in first))
  if (missing.length > 0) {
    throw new Error(`盛京银行模板缺少表头：${missing.join('、')}`)
  }
  return table
    .map((row, index) => ({
      transaction_time: normalizeExcelDateTime(row['交易时间']),
      debit_amount: normalizeAmount(row['支出']),
      credit_amount: normalizeAmount(row['收入']),
      balance_amount: normalizeAmount(row['账户余额']),
      counterparty_account_no: cellText(row['交易对手账号']),
      counterparty_account_name: cellText(row['交易对手户名']),
      counterparty_bank_name: cellText(row['交易对手开户行']),
      purpose: cellText(row['用途']),
      postscript: cellText(row['给收款人留言']),
      bank_serial_no: cellText(row['交易流水号']),
      source_row_no: index + 2
    }))
    .filter((row) => row.transaction_time || row.debit_amount || row.credit_amount || row.bank_serial_no)
}

const parseCcbRows = (workbook: XLSX.WorkBook) => {
  const sheetName = workbook.SheetNames[0]
  const sheet = workbook.Sheets[sheetName]
  const table = XLSX.utils.sheet_to_json<Record<string, any>>(sheet, { defval: '', raw: false })
  const required = [
    '账号',
    '账户名称',
    '交易时间',
    '借方发生额（支取）',
    '贷方发生额（收入）',
    '余额',
    '对方户名',
    '对方账号',
    '对方开户机构',
    '摘要',
    '备注',
    '账户明细编号-交易流水号'
  ]
  const first = table[0] || {}
  const missing = required.filter((name) => !(name in first))
  if (missing.length > 0) {
    throw new Error(`建设银行模板缺少表头：${missing.join('、')}`)
  }
  return table
    .map((row, index) => ({
      transaction_time: normalizeCcbDateTime(row['交易时间']),
      debit_amount: normalizeAmount(row['借方发生额（支取）']),
      credit_amount: normalizeAmount(row['贷方发生额（收入）']),
      balance_amount: normalizeAmount(row['余额']),
      counterparty_account_no: cellText(row['对方账号']),
      counterparty_account_name: cellText(row['对方户名']),
      counterparty_bank_name: cellText(row['对方开户机构']),
      purpose: cellText(row['摘要']),
      postscript: cellText(row['备注']),
      bank_serial_no: cellText(row['账户明细编号-交易流水号']),
      source_row_no: index + 2
    }))
    .filter((row) => row.transaction_time || row.debit_amount || row.credit_amount || row.bank_serial_no)
}

const readWorkbook = (file: File) => {
  return new Promise<XLSX.WorkBook>((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => {
      try {
        const workbook = XLSX.read(reader.result, { type: 'array', cellDates: true })
        resolve(workbook)
      } catch (e) {
        reject(e)
      }
    }
    reader.onerror = () => reject(reader.error)
    reader.readAsArrayBuffer(file)
  })
}

const normalizeExcelDateTime = (value: any) => {
  if (value instanceof Date) return formatDateTime(value)
  const text = cellText(value)
  if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/.test(text)) return text
  if (/^\d{4}\/\d{1,2}\/\d{1,2}/.test(text)) return formatDateTime(new Date(text))
  return text
}

const normalizeCcbDateTime = (value: any) => {
  if (value instanceof Date) return formatDateTime(value)
  const text = cellText(value)
  const match = text.match(/^(\d{4})(\d{2})(\d{2})(?:\s+(\d{2}):(\d{2}):(\d{2}))?$/)
  if (!match) return normalizeExcelDateTime(value)
  const [, year, month, day, hour = '00', minute = '00', second = '00'] = match
  return `${year}-${month}-${day} ${hour}:${minute}:${second}`
}

const normalizeAmount = (value: any) => {
  const text = cellText(value).replace(/,/g, '')
  if (!text) return '0.00'
  const amount = Number(text)
  return Number.isFinite(amount) ? amount.toFixed(2) : '0.00'
}

const cellText = (value: any) => String(value ?? '').trim()

function formatDateTime(date: Date) {
  return `${formatDate(date)} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}:${String(date.getSeconds()).padStart(2, '0')}`
}

function formatDate(date: Date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function initialStatementDateRange(period: string) {
  if (!/^\d{4}-\d{2}$/.test(period || '')) {
    return { start: '', end: '' }
  }
  const [yearText, monthText] = period.split('-')
  const year = Number(yearText)
  const month = Number(monthText)
  return {
    start: `${period}-01`,
    end: formatDate(new Date(year, month, 0))
  }
}

function bankCodeFromAccountSetName(bankName: string) {
  const name = String(bankName || '').trim()
  if (name.includes('盛京')) return 'SHENGJING'
  if (name.includes('建设') || name.includes('建行') || /CCB/i.test(name)) return 'CCB'
  return ''
}

const money = (value: string | number | undefined) => {
  const amount = Number(value || 0)
  return amount.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const amountText = (value: string | number | undefined) => {
  const amount = Number(value || 0)
  return amount === 0 ? '-' : money(amount)
}

const reconcileStatusLabel = (status: string) => {
  const map: Record<string, string> = {
    UNMATCHED: '未对账',
    MATCHED: '已对账',
    AMOUNT_DIFF: '金额不符',
    BANK_ONLY: '银行未匹配',
    BIZ_ONLY: '业务未匹配',
    DUPLICATE: '重复候选',
    IGNORED: '已忽略'
  }
  return map[status] || status || '未对账'
}

const reconcileStatusType = (status: string) => {
  const map: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'primary'> = {
    MATCHED: 'success',
    AMOUNT_DIFF: 'danger',
    BANK_ONLY: 'warning',
    BIZ_ONLY: 'warning',
    DUPLICATE: 'primary',
    UNMATCHED: 'info',
    IGNORED: 'info'
  }
  return map[status] || 'info'
}

const showReconcileJobErrors = async () => {
  if (!reconcileJob.value) return
  let result
  try {
    result = await caseFundApi.jobErrorList(reconcileJob.value.job_id, { page: 1, pagesize: 80 })
  } catch (error: any) {
    ElMessage.error(error?.message || '自动对账任务错误明细查询失败')
    return
  }
  if (!result.items.length) {
    ElMessage.info(reconcileJob.value.error_message || '当前任务没有可展示的逐条错误明细')
    return
  }
  const details = result.items.map((item) => {
    const itemLabel = item.source_row_no
      ? `第${item.source_row_no}行`
      : (item.source_id ? `流水${item.source_id}` : `第${item.item_no || '-'}项`)
    return `${itemLabel}：${item.error_message || item.error_code || '处理失败'}`
  })
  const moreText = result.total > details.length ? `\n还有 ${result.total - details.length} 条错误未显示。` : ''
  await ElMessageBox.alert(
    `${details.join('\n')}${moreText}`,
    '自动对账任务错误明细',
    {
      type: 'error',
      confirmButtonText: '知道了',
      customClass: 'import-validation-dialog'
    }
  ).catch(() => {})
}

onMounted(() => {
  void load()
  void restoreReconcileJob()
})

onDeactivated(() => {
  if (reconcileDetailVisible.value && selectedStatement.value) {
    restoreReconcileDetailOnActivate.value = true
    reconcileDetailVisible.value = false
  }
})

onActivated(() => {
  if (!restoreReconcileDetailOnActivate.value || !selectedStatement.value) return
  restoreReconcileDetailOnActivate.value = false
  reconcileDetailVisible.value = true
})
</script>

<style scoped>
.case-fund-summary {
  grid-template-columns: repeat(auto-fit, minmax(142px, 1fr));
}

.reconcile-business-cell {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 3px;
  min-width: 0;
}

.reconcile-business-link {
  max-width: 100%;
}

.reconcile-business-link :deep(.el-link__inner) {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.reconcile-business-amount {
  color: var(--el-text-color-secondary);
  font-variant-numeric: tabular-nums;
}

.reconcile-status-clickable {
  cursor: pointer;
}

.reconcile-bank-detail,
.reconcile-rule-detail {
  margin-bottom: 20px;
}

.reconcile-rule-detail {
  margin-top: 20px;
}

.reconcile-detail-title {
  margin: 4px 0 12px;
  color: var(--el-text-color-primary);
  font-size: 15px;
  font-weight: 600;
}

.reconcile-diff {
  color: var(--el-color-danger);
  font-weight: 600;
}
</style>
