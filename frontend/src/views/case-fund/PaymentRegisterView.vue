<template>
  <div class="case-fund-board">
    <div class="case-fund-toolbar">
      <div class="case-fund-filters">
        <el-select v-model="filters.quick_range" placeholder="快捷日期" clearable class="quick-range-select" @change="applyQuickRange">
          <el-option label="今天" value="today" />
          <el-option label="本周" value="week" />
          <el-option label="本月" value="month" />
          <el-option label="本年" value="year" />
        </el-select>
        <span class="date-filter-label">制证日期</span>
        <el-date-picker v-model="filters.date_start" type="date" value-format="YYYY-MM-DD" placeholder="开始日期" class="payment-date-picker" />
        <span class="date-separator">至</span>
        <el-date-picker v-model="filters.date_end" type="date" value-format="YYYY-MM-DD" placeholder="结束日期" class="payment-date-picker" />
        <el-select v-model="filters.voucher_status" placeholder="制证状态" clearable class="status-select">
          <el-option label="未生成凭证" value="UNGENERATED" />
          <el-option label="已生成凭证" value="GENERATED" />
        </el-select>
        <el-select v-model="filters.payment_method" placeholder="收费方式" clearable class="payment-method-select">
          <el-option label="POS 收款" value="POS" />
          <el-option label="银行转账" value="TRANSFER" />
          <el-option label="不明款认领" value="UNKNOWN" />
        </el-select>
        <div class="amount-range-filter" role="group" aria-label="金额区间">
          <el-input-number v-model="filters.amount_min" :min="0" :precision="2" :controls="false" placeholder="最低金额" class="amount-range-input" />
          <span class="date-separator">至</span>
          <el-input-number v-model="filters.amount_max" :min="0" :precision="2" :controls="false" placeholder="最高金额" class="amount-range-input" />
        </div>
        <el-input v-model="filters.keyword" placeholder="案号 / 当事人 / 票据 / 流水号" clearable class="case-fund-search" @keyup.enter="query" />
        <el-button type="primary" :icon="Search" :loading="loading" @click="query">查询</el-button>
      </div>
      <div class="case-fund-actions">
        <input ref="importInputRef" type="file" accept=".xls" class="case-fund-file-input" @change="handlePaymentImportFile" />
        <el-button v-if="paymentJob" type="primary" plain @click="openPaymentJobDialog">后台任务</el-button>
        <el-dropdown v-if="canUseMoreActions" trigger="click" @command="handleMoreAction">
          <el-button :icon="MoreFilled">
            更多功能
            <el-icon class="el-icon--right"><ArrowDown /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item v-permission="'case_fund:delete'" command="delete" :icon="Delete" :disabled="deleting || paymentJobBusy" class="danger-dropdown-item" style="color: var(--el-color-danger)">删除</el-dropdown-item>
              <el-dropdown-item v-permission="'case_fund:subject_config'" command="subjectConfig" :icon="Setting" :disabled="paymentJobBusy">科目配置</el-dropdown-item>
              <el-dropdown-item v-permission="'case_fund:import'" command="import" :icon="Upload" :disabled="paymentJobBusy">导入缴费</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </div>
    </div>

    <el-alert v-if="linkedPaymentId" type="info" show-icon :closable="false" class="case-fund-link-alert">
      <template #title>
        <span>正在查看银行对账关联的缴费记录</span>
        <el-button link type="primary" @click="clearLinkedPayment">返回当前期间全部数据</el-button>
      </template>
    </el-alert>

    <el-alert v-if="paymentRecoveryUnconfirmed" type="warning" show-icon :closable="false" class="case-fund-link-alert">
      <template #title>
        <span>上次后台任务的提交或运行结果尚未确认，为避免重复导入或重复制证，相关操作已暂时锁定。</span>
        <el-button link type="warning" :loading="paymentRecoveryLoading" @click="retryPaymentJobRecovery">重新确认</el-button>
      </template>
    </el-alert>

    <div class="case-fund-summary">
      <div>
        <span>登记笔数</span>
        <strong>{{ total }}</strong>
      </div>
      <div>
        <span>本页金额</span>
        <strong>{{ pageAmountText }}</strong>
      </div>
      <div>
        <span>未制证</span>
        <strong>{{ ungeneratedCount }}</strong>
      </div>
      <div class="case-fund-summary-actions">
        <el-button v-permission="'case_fund:generate_voucher'" type="success" :icon="DocumentChecked" :loading="generating" :disabled="paymentJobBusy" @click="handleGenerateVoucher">生成凭证</el-button>
        <el-button v-permission="'case_fund:generate_voucher'" type="success" :icon="DocumentChecked" :loading="generating" :disabled="paymentJobBusy" @click="handleGenerateVoucherByQuery">按查询生成</el-button>
      </div>
    </div>

    <div class="case-fund-table-shell">
      <el-table
        :data="rows"
        border
        height="calc(100vh - 292px)"
        v-loading="loading"
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="55" :selectable="isRowSelectable" fixed="left" />
        <el-table-column prop="payment_date" label="缴费日期" width="112" fixed="left" />
        <el-table-column prop="claim_date" label="认领日期" width="112">
          <template #default="{ row }">{{ row.claim_date || '-' }}</template>
        </el-table-column>
        <el-table-column prop="posting_date" label="制证日期" width="112" />
        <el-table-column prop="case_no" label="案号" min-width="190" fixed="left" show-overflow-tooltip />
        <el-table-column prop="business_type" label="业务类型" width="130" show-overflow-tooltip />
        <el-table-column prop="payer_name" label="缴费人" min-width="140" show-overflow-tooltip />
        <el-table-column prop="party_name" label="当事人" min-width="120" show-overflow-tooltip />
        <el-table-column prop="payment_amount" label="金额" width="130" align="right">
          <template #default="{ row }">{{ money(row.payment_amount) }}</template>
        </el-table-column>
        <el-table-column prop="receipt_no" label="票据号码" width="120" show-overflow-tooltip />
        <el-table-column prop="payment_method" label="收费方式" width="130" show-overflow-tooltip>
          <template #default="{ row }">
            <el-tag :type="paymentMethodTagType(row.payment_method)" effect="light">
              {{ paymentMethodLabel(row.payment_method) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="department_name" label="承办部门" min-width="140" show-overflow-tooltip />
        <el-table-column prop="judge_name" label="承办法官" width="110" show-overflow-tooltip />
        <el-table-column prop="bank_account_no" label="收款账号" min-width="210" show-overflow-tooltip />
        <el-table-column prop="bank_serial_no" label="银行流水号" min-width="180" show-overflow-tooltip />
        <el-table-column prop="payment_order_no" label="缴费单号" min-width="150" show-overflow-tooltip />
        <el-table-column label="制证状态" width="120" align="center" fixed="right">
          <template #default="{ row }">
            <el-tag :type="voucherStatusType(row.voucher_status)" effect="light">{{ voucherStatusLabel(row.voucher_status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="凭证" width="110" align="center" fixed="right">
          <template #default="{ row }">
            <router-link
              v-if="row.voucher_no && row.voucher_id && context.hasPermission('menu:voucher')"
              class="voucher-number-link"
              :to="{ path: '/vouchers', query: { period: row.voucher_period, voucher_id: row.voucher_id } }"
              title="在凭证中心查看该凭证"
            >
              {{ row.voucher_period }}-{{ row.voucher_no }}
            </router-link>
            <span v-else-if="row.voucher_no">{{ row.voucher_period }}-{{ row.voucher_no }}</span>
            <span v-else class="muted-text">-</span>
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
        :page-sizes="[20, 50, 100, 200]"
        :total="total"
        @size-change="load"
        @current-change="load"
      />
    </div>

    <el-dialog v-model="subjectConfigVisible" title="缴费科目配置" width="760px" destroy-on-close>
      <div class="subject-config-options">
        <span>是否按天生成凭证</span>
        <el-switch v-model="generateVoucherByDay" />
      </div>
      <div class="subject-config-options">
        <span>是否按照收费方式生成凭证</span>
        <el-switch v-model="generatePaymentVoucherByMethod" />
      </div>
      <el-table :data="subjectConfigRows" border v-loading="subjectConfigLoading">
        <el-table-column prop="business_item_type" label="业务类型" min-width="160" />
        <el-table-column label="借方科目" min-width="240">
          <template #default="{ row }">
            <el-tree-select
              v-model="row.debit_subject_code"
              :data="subjectTreeOptions"
              filterable
              clearable
              check-strictly
              default-expand-all
              placeholder="借方科目"
              style="width: 100%"
            />
          </template>
        </el-table-column>
        <el-table-column label="贷方科目" min-width="240">
          <template #default="{ row }">
            <el-tree-select
              v-model="row.credit_subject_code"
              :data="subjectTreeOptions"
              filterable
              clearable
              check-strictly
              default-expand-all
              placeholder="贷方科目"
              style="width: 100%"
            />
          </template>
        </el-table-column>
      </el-table>
      <el-table v-if="generatePaymentVoucherByMethod" :data="paymentMethodConfigRows" border class="payment-method-config-table">
        <el-table-column prop="business_item_label" label="收费方式" min-width="160">
          <template #default="{ row }">{{ row.business_item_label || paymentMethodLabel(row.business_item_type) }}</template>
        </el-table-column>
        <el-table-column label="借方科目" min-width="280">
          <template #default="{ row }">
            <el-tree-select
              v-model="row.debit_subject_code"
              :data="subjectTreeOptions"
              filterable
              clearable
              check-strictly
              default-expand-all
              placeholder="借方科目"
              style="width: 100%"
            />
          </template>
        </el-table-column>
      </el-table>
      <template #footer>
        <el-button @click="subjectConfigVisible = false">取消</el-button>
        <el-button type="primary" :loading="subjectConfigSaving" @click="saveSubjectConfig">保存</el-button>
      </template>
    </el-dialog>

    <CaseFundJobProgressDialog
      v-model="paymentJobDialogVisible"
      :job="paymentJob"
      title="缴费业务后台任务"
      :job-type-label="paymentJobTypeLabel"
      :poll-error="paymentJobPollError"
      :status-loading="paymentJobStatusLoading"
      @refresh="refreshPaymentJob"
      @view-errors="showPaymentJobErrors"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowDown, Search, Setting, Upload, DocumentChecked, Delete, MoreFilled } from '@element-plus/icons-vue'
import { baseApi } from '../../api/base'
import { caseFundApi } from '../../api/caseFund'
import CaseFundJobProgressDialog from '../../components/case-fund/CaseFundJobProgressDialog.vue'
import { createCaseFundClientRequestId, isCaseFundJobNotFoundError, isCaseFundJobTerminal, useCaseFundJob } from '../../composables/useCaseFundJob'
import { useContextStore } from '../../stores/context'
import { buildSubjectTreeOptions } from '../../utils/subjectTree'
import type { CaseFundJob, CaseFundPayment, CaseFundSubjectConfig, Subject } from '../../types/api'

defineOptions({ name: 'PaymentRegisterView' })

const context = useContextStore()
const route = useRoute()
const router = useRouter()
const rows = ref<CaseFundPayment[]>([])
const subjects = ref<Subject[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(50)
const loading = ref(false)
const deleting = ref(false)
const importInputRef = ref<HTMLInputElement | null>(null)
const selectedPaymentIds = ref<string[]>([])
const selectedPaymentRows = ref<CaseFundPayment[]>([])
const subjectConfigVisible = ref(false)
const subjectConfigLoading = ref(false)
const subjectConfigSaving = ref(false)
const subjectConfigRows = ref<CaseFundSubjectConfig[]>([])
const paymentMethodConfigRows = ref<CaseFundSubjectConfig[]>([])
const generateVoucherByDay = ref(true)
const generatePaymentVoucherByMethod = ref(false)
const voucherBizType: 'PAYMENT' = 'PAYMENT'
const initialRange = initialPaymentDateRange(context.period)
const filters = reactive({
  quick_range: '',
  date_start: initialRange.start,
  date_end: initialRange.end,
  voucher_status: '',
  payment_method: '',
  amount_min: null as number | null,
  amount_max: null as number | null,
  keyword: ''
})
const linkedPaymentId = computed(() => routeQueryText(route.query.payment_id))

const pageAmountText = computed(() => {
  const totalCents = rows.value.reduce((sum, row) => sum + Math.round(Number(row.payment_amount || 0) * 100), 0)
  return money(totalCents / 100)
})

const ungeneratedCount = computed(() => rows.value.filter((row) => row.voucher_status === 'UNGENERATED').length)
const canUseMoreActions = computed(() => context.hasAnyPermission(['case_fund:delete', 'case_fund:subject_config', 'case_fund:import']))
const subjectTreeOptions = computed(() => buildSubjectTreeOptions(subjects.value))

const load = async () => {
  loading.value = true
  try {
    const result = await caseFundApi.paymentList({
      payment_id: linkedPaymentId.value,
      date_start: filters.date_start,
      date_end: filters.date_end,
      voucher_status: filters.voucher_status,
      payment_method: filters.payment_method,
      amount_min: filters.amount_min,
      amount_max: filters.amount_max,
      keyword: filters.keyword,
      page: page.value,
      pagesize: pageSize.value
    })
    rows.value = result.items || []
    total.value = result.total || 0
  } finally {
    loading.value = false
  }
}

const PAYMENT_IMPORT_JOB_TYPE = 'CASE_FUND_PAYMENT_IMPORT'
const PAYMENT_VOUCHER_JOB_TYPE = 'CASE_FUND_PAYMENT_VOUCHER'
type PendingPaymentJobSubmission = {
  client_request_id: string
  job_type: string
}

const paymentJobStorageKey = () => `court-finance-case-fund-job:${context.accountSetId}:${context.userId}:${context.year}:payments`
const paymentPendingStorageKey = () => `${paymentJobStorageKey()}:pending`

const readStoredPaymentJobId = () => {
  try {
    return String(localStorage.getItem(paymentJobStorageKey()) || '').trim()
  } catch {
    return ''
  }
}

const readPendingPaymentSubmission = (): PendingPaymentJobSubmission | null => {
  try {
    const raw = localStorage.getItem(paymentPendingStorageKey())
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<PendingPaymentJobSubmission>
    const clientRequestId = String(parsed.client_request_id || '').trim()
    const jobType = String(parsed.job_type || '').trim()
    return clientRequestId && jobType ? { client_request_id: clientRequestId, job_type: jobType } : null
  } catch {
    return null
  }
}

const storedPaymentJobId = ref(readStoredPaymentJobId())
const pendingPaymentSubmission = ref<PendingPaymentJobSubmission | null>(readPendingPaymentSubmission())
const paymentRecoveryUnconfirmed = ref(false)
const paymentRecoveryLoading = ref(false)

const persistPendingPaymentSubmission = (pending: PendingPaymentJobSubmission) => {
  pendingPaymentSubmission.value = pending
  paymentRecoveryUnconfirmed.value = false
  try {
    localStorage.setItem(paymentPendingStorageKey(), JSON.stringify(pending))
  } catch {
    // 内存状态仍会阻止当前页面重复提交。
  }
}

const clearPendingPaymentSubmission = () => {
  pendingPaymentSubmission.value = null
  paymentRecoveryUnconfirmed.value = false
  try {
    localStorage.removeItem(paymentPendingStorageKey())
  } catch {
    // 本地存储清理失败不影响已经取得的任务状态。
  }
}

const persistPaymentJob = (job: CaseFundJob) => {
  storedPaymentJobId.value = job.job_id
  try {
    localStorage.setItem(paymentJobStorageKey(), job.job_id)
    clearPendingPaymentSubmission()
  } catch {
    // 保留 pending，刷新后仍可按幂等号查回任务。
  }
}

const removeStoredPaymentJob = (jobId?: string) => {
  if (!jobId || storedPaymentJobId.value === jobId) {
    storedPaymentJobId.value = ''
  }
  try {
    const storageKey = paymentJobStorageKey()
    if (!jobId || localStorage.getItem(storageKey) === jobId) {
      localStorage.removeItem(storageKey)
    }
  } catch {
    // 本地存储清理失败不影响后台任务结果。
  }
}

const handlePaymentJobFinished = async (finishedJob: CaseFundJob) => {
  removeStoredPaymentJob(finishedJob.job_id)
  clearPendingPaymentSubmission()
  const result = finishedJob.result || {}
  if (finishedJob.job_type === PAYMENT_VOUCHER_JOB_TYPE) {
    selectedPaymentIds.value = []
    selectedPaymentRows.value = []
  }
  if (finishedJob.status === 'SUCCEEDED') {
    if (finishedJob.job_type === PAYMENT_IMPORT_JOB_TYPE) {
      ElMessage.success(`缴费导入完成：新增${Number(result.created || 0)}条，覆盖${Number(result.updated || 0)}条，跳过重复${Number(result.skipped || 0)}条`)
      page.value = 1
    } else {
      ElMessage.success(`凭证生成完成：成功生成 ${Number(result.generated_count || 0)} 张凭证，处理 ${Number(result.payment_count || finishedJob.success_count || 0)} 条缴费记录`)
    }
  } else if (finishedJob.status === 'PARTIAL_FAILED') {
    ElMessage.warning(`后台任务已完成，但有 ${Number(finishedJob.failure_count || 0)} 条数据失败，请查看错误明细`)
  } else {
    ElMessage.error(finishedJob.error_message || '缴费业务后台任务执行失败')
  }
  await load().catch(() => {})
}

const {
  job: paymentJob,
  dialogVisible: paymentJobDialogVisible,
  creating: paymentJobCreating,
  statusLoading: paymentJobStatusLoading,
  createError: paymentJobCreateError,
  pollError: paymentJobPollError,
  isActive: paymentJobActive,
  createJob: createPaymentJob,
  trackJob: trackPaymentJob,
  resumeJob: resumePaymentJob,
  refreshJob: refreshPaymentJob,
  openDialog: openPaymentJobDialog
} = useCaseFundJob({
  onUpdated: persistPaymentJob,
  onFinished: (job) => { void handlePaymentJobFinished(job) }
})

const paymentJobUnresolved = computed(() => (
  Boolean(pendingPaymentSubmission.value)
  || Boolean(storedPaymentJobId.value && !paymentJob.value)
))
const paymentJobBusy = computed(() => (
  paymentJobCreating.value
  || paymentJobActive.value
  || paymentJobUnresolved.value
  || paymentRecoveryLoading.value
))
const generating = computed(() => (
  pendingPaymentSubmission.value?.job_type === PAYMENT_VOUCHER_JOB_TYPE
  || (paymentJobActive.value && paymentJob.value?.job_type === PAYMENT_VOUCHER_JOB_TYPE)
))
const paymentJobTypeLabel = computed(() => (
  paymentJob.value?.job_type === PAYMENT_IMPORT_JOB_TYPE ? '缴费登记导入' : '缴费凭证生成'
))

const recoverPendingPaymentJob = async (showMessage = true) => {
  const pending = pendingPaymentSubmission.value
  if (!pending) return null
  paymentRecoveryLoading.value = true
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
      // 避免把“暂时查不到”误判成“任务未创建”而造成重复导入或重复制证。
      clearPendingPaymentSubmission()
      if (showMessage) {
        ElMessage.info('已确认后台不存在上次待提交任务，操作锁定已解除，请重新发起操作')
      }
      return null
    }
    paymentRecoveryUnconfirmed.value = false
    trackPaymentJob(recoveredJob, true)
    if (showMessage && !isCaseFundJobTerminal(recoveredJob.status)) {
      ElMessage.info('已恢复上次待确认的缴费后台任务')
    }
    return recoveredJob
  } catch (error: any) {
    paymentRecoveryUnconfirmed.value = true
    if (showMessage) {
      ElMessage.warning(error?.message || '暂时无法确认上次任务状态，原任务可能仍在运行，请勿重复提交')
    }
    return null
  } finally {
    paymentRecoveryLoading.value = false
  }
}

const restorePaymentJob = async (showMessage = true) => {
  const jobId = storedPaymentJobId.value
  if (!jobId) return recoverPendingPaymentJob(showMessage)
  paymentRecoveryLoading.value = true
  try {
    const restoredJob = await resumePaymentJob(jobId, true)
    paymentRecoveryUnconfirmed.value = false
    if (showMessage && !isCaseFundJobTerminal(restoredJob.status)) {
      ElMessage.info('已恢复上次未完成的缴费后台任务')
    }
    return restoredJob
  } catch (error: any) {
    if (isCaseFundJobNotFoundError(error)) {
      removeStoredPaymentJob(jobId)
      paymentRecoveryUnconfirmed.value = false
      if (pendingPaymentSubmission.value) {
        return recoverPendingPaymentJob(showMessage)
      }
      if (showMessage) {
        ElMessage.info('上次缴费后台任务已不存在，失效记录已清除，操作锁定已解除')
      }
      return null
    }
    paymentRecoveryUnconfirmed.value = true
    if (showMessage) {
      ElMessage.warning(error?.message || '暂时无法恢复后台任务状态，原任务可能仍在运行，请勿重复提交')
    }
    return null
  } finally {
    paymentRecoveryLoading.value = false
  }
}

const retryPaymentJobRecovery = () => { void restorePaymentJob(true) }

const keepOrClearPendingPaymentSubmission = (error: any) => {
  if (error?.result) {
    clearPendingPaymentSubmission()
    return
  }
  if (pendingPaymentSubmission.value) {
    paymentRecoveryUnconfirmed.value = true
  }
}

const query = async () => {
  if (!validateAmountRange()) return
  if (linkedPaymentId.value) {
    await clearLinkedPayment()
    return
  }
  page.value = 1
  await load()
}

const validateAmountRange = () => {
  if (filters.amount_min !== null && filters.amount_max !== null && filters.amount_min > filters.amount_max) {
    ElMessage.warning('最低金额不能大于最高金额')
    return false
  }
  return true
}

const clearLinkedPayment = async () => {
  const nextQuery = { ...route.query }
  delete nextQuery.payment_id
  await router.replace({ path: route.path, query: nextQuery })
}

const routeQueryText = (value: unknown) => {
  const target = Array.isArray(value) ? value[0] : value
  return typeof target === 'string' || typeof target === 'number' ? String(target).trim() : ''
}

const openSubjectConfig = async () => {
  subjectConfigVisible.value = true
  subjectConfigLoading.value = true
  try {
    const [configResult] = await Promise.all([
      caseFundApi.subjectConfigList(voucherBizType),
      loadSubjects()
    ])
    generateVoucherByDay.value = Number(configResult.generate_voucher_by_day_flag ?? 1) === 1
    generatePaymentVoucherByMethod.value = Number(configResult.generate_payment_voucher_by_method_flag ?? 0) === 1
    subjectConfigRows.value = (configResult.items || []).map((item) => ({ ...item }))
    paymentMethodConfigRows.value = normalizePaymentMethodConfigRows(configResult.payment_method_items || [])
  } finally {
    subjectConfigLoading.value = false
  }
}

const saveSubjectConfig = async () => {
  const missing = subjectConfigRows.value.find((row) => !row.debit_subject_code || !row.credit_subject_code)
  if (missing) {
    ElMessage.warning(`${missing.business_item_type} 的借方科目和贷方科目都不能为空`)
    return
  }
  if (generatePaymentVoucherByMethod.value) {
    const missingMethod = paymentMethodConfigRows.value.find((row) => !row.debit_subject_code)
    if (missingMethod) {
      ElMessage.warning(`${missingMethod.business_item_label || paymentMethodLabel(missingMethod.business_item_type)} 的借方科目不能为空`)
      return
    }
  }
  subjectConfigSaving.value = true
  try {
    await caseFundApi.saveSubjectConfigs(
      voucherBizType,
      subjectConfigRows.value,
      generateVoucherByDay.value ? 1 : 0,
      undefined,
      generatePaymentVoucherByMethod.value ? 1 : 0,
      paymentMethodConfigRows.value
    )
    ElMessage.success('科目配置已保存')
    subjectConfigVisible.value = false
  } finally {
    subjectConfigSaving.value = false
  }
}

const loadSubjects = async () => {
  if (subjects.value.length > 0) return
  subjects.value = await baseApi.subjects()
}

const paymentMethodLabels: Record<string, string> = {
  POS: 'POS 收款',
  TRANSFER: '银行转账',
  UNKNOWN: '不明款认领'
}

const paymentMethodLabel = (method?: string) => {
  const value = String(method || '').trim()
  if (!value) return '-'
  return paymentMethodLabels[value] || `未识别：${value}`
}

const paymentMethodTagType = (method?: string) => {
  const value = String(method || '').trim()
  if (value === 'POS') return 'warning'
  if (value === 'TRANSFER') return 'success'
  if (value === 'UNKNOWN') return 'info'
  return 'danger'
}

const normalizePaymentMethodConfigRows = (items: CaseFundSubjectConfig[]): CaseFundSubjectConfig[] => {
  const defaultDebitSubjectCode = subjectConfigRows.value.find((row) => row.debit_subject_code)?.debit_subject_code || ''
  const byType = new Map(items.map((item) => [item.business_item_type, item]))
  return ([
    ['POS', 'POS 收款'],
    ['TRANSFER', '银行转账'],
    ['UNKNOWN', '不明款认领']
  ] as Array<[string, string]>).map(([type, label]) => ({
    ...(byType.get(type) || {}),
    voucher_biz_type: 'PAYMENT_METHOD' as const,
    business_item_type: type,
    business_item_label: label,
    debit_subject_code: byType.get(type)?.debit_subject_code || defaultDebitSubjectCode,
    credit_subject_code: byType.get(type)?.credit_subject_code || byType.get(type)?.debit_subject_code || defaultDebitSubjectCode
  }))
}

const applyQuickRange = () => {
  const today = new Date()
  if (filters.quick_range === 'today') {
    setDateRange(today, today)
    return
  }
  if (filters.quick_range === 'week') {
    const day = today.getDay() || 7
    const start = addDays(today, 1 - day)
    const end = addDays(start, 6)
    setDateRange(start, end)
    return
  }
  if (filters.quick_range === 'month') {
    setDateRange(new Date(today.getFullYear(), today.getMonth(), 1), new Date(today.getFullYear(), today.getMonth() + 1, 0))
    return
  }
  if (filters.quick_range === 'year') {
    setDateRange(new Date(today.getFullYear(), 0, 1), new Date(today.getFullYear(), 11, 31))
    return
  }
}

function setDateRange(start: Date, end: Date) {
  filters.date_start = formatDate(start)
  filters.date_end = formatDate(end)
}

function addDays(date: Date, days: number) {
  const next = new Date(date)
  next.setDate(next.getDate() + days)
  return next
}

function formatDate(date: Date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function initialPaymentDateRange(period: string) {
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

const handleMoreAction = (command: string | number | object) => {
  if (command === 'delete') {
    void handleDeletePayments()
    return
  }
  if (command === 'subjectConfig') {
    void openSubjectConfig()
    return
  }
  if (command === 'import') {
    choosePaymentImportFile()
  }
}

const choosePaymentImportFile = () => {
  if (!importInputRef.value) return
  importInputRef.value.value = ''
  importInputRef.value.click()
}

const handlePaymentImportFile = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  if (!file.name.toLowerCase().endsWith('.xls')) {
    ElMessage.warning('请选择 .xls 格式的缴费登记文件')
    return
  }
  if (file.size > 10 * 1024 * 1024) {
    ElMessage.warning('缴费登记文件不能超过10MB，请拆分后导入')
    return
  }
  if (paymentJobBusy.value) {
    ElMessage.warning('已有后台任务或待确认提交，请先确认任务状态')
    return
  }
  try {
    const contentBase64 = await readFileBase64(file)
    const clientRequestId = createCaseFundClientRequestId('payment-import')
    persistPendingPaymentSubmission({
      client_request_id: clientRequestId,
      job_type: PAYMENT_IMPORT_JOB_TYPE
    })
    await createPaymentJob({
      job_type: PAYMENT_IMPORT_JOB_TYPE,
      client_request_id: clientRequestId,
      payload: { period: context.period },
      filename: file.name,
      content_base64: contentBase64
    })
    ElMessage.success('缴费导入任务已提交，后台正在处理')
  } catch (error: any) {
    keepOrClearPendingPaymentSubmission(error)
    const detailsShown = await showPaymentImportError(error)
    if (!detailsShown) {
      const message = paymentJobCreateError.value || error?.message || '缴费导入任务提交失败'
      error?.result ? ElMessage.error(message) : ElMessage.warning(message)
    }
  }
}

const showPaymentImportError = async (error: any) => {
  const details = Array.isArray(error?.data) ? error.data.filter(Boolean).map(String) : []
  if (details.length === 0) {
    return false
  }
  const visibleDetails = details.slice(0, 80)
  const moreText = details.length > visibleDetails.length ? `\n还有 ${details.length - visibleDetails.length} 条错误未显示，请先处理前面错误后重新导入。` : ''
  await ElMessageBox.alert(
    `${visibleDetails.join('\n')}${moreText}`,
    '校验明细',
    {
      type: 'error',
      confirmButtonText: '知道了',
      customClass: 'import-validation-dialog'
    }
  ).catch(() => {})
  return true
}

const readFileBase64 = (file: File) => {
  return new Promise<string>((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => {
      const result = String(reader.result || '')
      resolve(result.includes(',') ? result.split(',')[1] : result)
    }
    reader.onerror = () => reject(reader.error)
    reader.readAsDataURL(file)
  })
}

const money = (value: string | number | undefined) => {
  const amount = Number(value || 0)
  return amount.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const voucherStatusLabel = (status: string) => {
  const map: Record<string, string> = {
    UNGENERATED: '未制证',
    GENERATED: '已制证',
    VOIDED: '已作废'
  }
  return map[status] || status || '未制证'
}

const voucherStatusType = (status: string) => {
  const map: Record<string, 'success' | 'warning' | 'danger' | 'info'> = {
    UNGENERATED: 'warning',
    GENERATED: 'success',
    VOIDED: 'danger'
  }
  return map[status] || 'info'
}

const isRowSelectable = (row: CaseFundPayment) => {
  return row.voucher_status === 'UNGENERATED' || row.voucher_status === 'GENERATED'
}

const handleSelectionChange = (selection: CaseFundPayment[]) => {
  selectedPaymentRows.value = selection
  selectedPaymentIds.value = selection.map((row) => row.payment_id)
}

const buildGenerateVoucherQueryPayload = () => ({
  date_start: filters.date_start,
  date_end: filters.date_end,
  voucher_status: 'UNGENERATED',
  payment_method: filters.payment_method,
  amount_min: filters.amount_min,
  amount_max: filters.amount_max,
  keyword: filters.keyword
})

const handleGenerateVoucher = async () => {
  if (selectedPaymentIds.value.length === 0) {
    ElMessage.warning('请选择要生成凭证的缴费记录')
    return
  }
  try {
    await ElMessageBox.confirm(
      `确定要为选中的 ${selectedPaymentIds.value.length} 条记录生成凭证吗？`,
      '确认生成凭证',
      { confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning' }
    )
  } catch {
    return
  }
  if (paymentJobBusy.value) {
    ElMessage.warning('已有后台任务或待确认提交，请先确认任务状态')
    return
  }
  const clientRequestId = createCaseFundClientRequestId('payment-voucher-selected')
  persistPendingPaymentSubmission({
    client_request_id: clientRequestId,
    job_type: PAYMENT_VOUCHER_JOB_TYPE
  })
  try {
    await createPaymentJob({
      job_type: PAYMENT_VOUCHER_JOB_TYPE,
      client_request_id: clientRequestId,
      payload: {
        period: context.period,
        generate_scope: 'SELECTED',
        payment_ids: [...selectedPaymentIds.value]
      }
    })
    ElMessage.success('缴费凭证生成任务已提交，后台正在处理')
  } catch (e: any) {
    keepOrClearPendingPaymentSubmission(e)
    const message = paymentJobCreateError.value || e?.message || '凭证生成任务提交失败'
    e?.result ? ElMessage.error(message) : ElMessage.warning(message)
  }
}

const handleGenerateVoucherByQuery = async () => {
  if (!validateAmountRange()) return
  try {
    await ElMessageBox.confirm(
      '确定按当前查询条件生成全部未制证缴费记录的凭证吗？该操作不受当前分页限制。',
      '按查询条件生成凭证',
      { confirmButtonText: '按查询生成', cancelButtonText: '取消', type: 'warning' }
    )
  } catch {
    return
  }
  if (paymentJobBusy.value) {
    ElMessage.warning('已有后台任务或待确认提交，请先确认任务状态')
    return
  }
  const clientRequestId = createCaseFundClientRequestId('payment-voucher-query')
  persistPendingPaymentSubmission({
    client_request_id: clientRequestId,
    job_type: PAYMENT_VOUCHER_JOB_TYPE
  })
  try {
    await createPaymentJob({
      job_type: PAYMENT_VOUCHER_JOB_TYPE,
      client_request_id: clientRequestId,
      payload: {
        period: context.period,
        generate_scope: 'QUERY',
        ...buildGenerateVoucherQueryPayload()
      }
    })
    ElMessage.success('按查询条件生成缴费凭证的任务已提交，后台正在处理')
  } catch (e: any) {
    keepOrClearPendingPaymentSubmission(e)
    const message = paymentJobCreateError.value || e?.message || '凭证生成任务提交失败'
    e?.result ? ElMessage.error(message) : ElMessage.warning(message)
  }
}

const handleDeletePayments = async () => {
  if (selectedPaymentIds.value.length === 0) {
    ElMessage.warning('请选择要删除的未制证缴费记录')
    return
  }
  try {
    await ElMessageBox.confirm(
      `确定要删除选中的 ${selectedPaymentIds.value.length} 条缴费记录吗？已制证且原凭证仍有效、或已对账的数据不能删除。`,
      '确认删除缴费记录',
      { confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning' }
    )
  } catch {
    return
  }
  deleting.value = true
  try {
    const result = await caseFundApi.deletePayments(selectedPaymentIds.value)
    ElMessage.success(`已删除 ${result.deleted_count} 条缴费记录`)
    selectedPaymentIds.value = []
    await load()
  } catch (e: any) {
    ElMessage.error(e?.message || '删除缴费记录失败')
  } finally {
    deleting.value = false
  }
}

const showPaymentJobErrors = async () => {
  if (!paymentJob.value) return
  let result
  try {
    result = await caseFundApi.jobErrorList(paymentJob.value.job_id, { page: 1, pagesize: 80 })
  } catch (error: any) {
    ElMessage.error(error?.message || '任务错误明细查询失败')
    return
  }
  if (!result.items.length) {
    ElMessage.info(paymentJob.value.error_message || '当前任务没有可展示的逐条错误明细')
    return
  }
  const details = result.items.map((item) => {
    const rowLabel = item.source_row_no ? `第${item.source_row_no}行` : `第${item.item_no || '-'}项`
    return `${rowLabel}：${item.error_message || item.error_code || '处理失败'}`
  })
  const moreText = result.total > details.length ? `\n还有 ${result.total - details.length} 条错误未显示。` : ''
  await ElMessageBox.alert(
    `${details.join('\n')}${moreText}`,
    '任务错误明细',
    {
      type: 'error',
      confirmButtonText: '知道了',
      customClass: 'import-validation-dialog'
    }
  ).catch(() => {})
}

watch(
  () => route.query.payment_id,
  async () => {
    if (route.path !== '/case-fund/payments') return
    page.value = 1
    await load()
  }
)

watch(
  () => context.period,
  async (period) => {
    const range = initialPaymentDateRange(period)
    filters.quick_range = ''
    filters.date_start = range.start
    filters.date_end = range.end
    page.value = 1
    await load()
  }
)

onMounted(() => {
  void load()
  void restorePaymentJob()
})
</script>

<style scoped>
.payment-method-select {
  width: 140px;
}

.case-fund-link-alert {
  margin-bottom: 12px;
}
</style>
