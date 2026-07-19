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
        <span class="date-filter-label">出账日期</span>
        <el-date-picker v-model="filters.date_start" type="date" value-format="YYYY-MM-DD" placeholder="开始日期" class="refund-date-picker" />
        <span class="date-separator">至</span>
        <el-date-picker v-model="filters.date_end" type="date" value-format="YYYY-MM-DD" placeholder="结束日期" class="refund-date-picker" />
        <el-select v-model="filters.voucher_status" placeholder="制证状态" clearable class="status-select">
          <el-option label="未生成凭证" value="UNGENERATED" />
          <el-option label="已生成凭证" value="GENERATED" />
        </el-select>
        <el-input v-model="filters.keyword" placeholder="案号 / 当事人 / 收款人 / 票据" clearable class="case-fund-search" @keyup.enter="query" />
        <el-button type="primary" :icon="Search" :loading="loading" @click="query">查询</el-button>
      </div>
      <div class="case-fund-actions">
        <input ref="importInputRef" type="file" accept=".xls" class="case-fund-file-input" @change="handleRefundImportFile" />
        <el-dropdown v-if="canUseMoreActions" trigger="click" @command="handleMoreAction">
          <el-button :icon="MoreFilled">
            更多功能
            <el-icon class="el-icon--right"><ArrowDown /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item v-permission="'case_fund:delete'" command="delete" :icon="Delete" :disabled="deleting" class="danger-dropdown-item" style="color: var(--el-color-danger)">删除</el-dropdown-item>
              <el-dropdown-item v-permission="'case_fund:subject_config'" command="subjectConfig" :icon="Setting">科目配置</el-dropdown-item>
              <el-dropdown-item v-permission="'case_fund:import'" command="import" :icon="Upload" :disabled="importing">数据导入</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </div>
    </div>

    <el-alert v-if="linkedRefundId" type="info" show-icon :closable="false" class="case-fund-link-alert">
      <template #title>
        <span>正在查看银行对账关联的出账记录</span>
        <el-button link type="primary" @click="clearLinkedRefund">返回当前期间全部数据</el-button>
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
        <el-button v-permission="'case_fund:generate_voucher'" type="success" :icon="DocumentChecked" :loading="generating" @click="handleGenerateVoucher">生成凭证</el-button>
        <el-button v-permission="'case_fund:generate_voucher'" type="success" :icon="DocumentChecked" :loading="generating" @click="handleGenerateVoucherByQuery">按查询生成</el-button>
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
        <el-table-column prop="refund_date" label="出账日期" width="112" fixed="left" />
        <el-table-column prop="case_no" label="案号" min-width="190" fixed="left" show-overflow-tooltip />
        <el-table-column prop="out_type" label="出账种类" width="150" show-overflow-tooltip />
        <el-table-column prop="out_status" label="出账状态" width="120" show-overflow-tooltip />
        <el-table-column prop="party_name" label="当事人" min-width="120" show-overflow-tooltip />
        <el-table-column prop="actual_payee_name" label="实际收款人" min-width="130" show-overflow-tooltip />
        <el-table-column prop="refund_amount" label="出账金额" width="130" align="right">
          <template #default="{ row }">{{ money(row.refund_amount) }}</template>
        </el-table-column>
        <el-table-column prop="total_refund_amount" label="总出账金额" width="130" align="right">
          <template #default="{ row }">{{ money(row.total_refund_amount) }}</template>
        </el-table-column>
        <el-table-column prop="source_receipt_no" label="来源票据号码" min-width="150" show-overflow-tooltip />
        <el-table-column prop="source_receipt_date" label="来源开票日期" width="120" />
        <el-table-column prop="out_order_no" label="出账单号" min-width="160" show-overflow-tooltip />
        <el-table-column prop="payment_method" label="支付方式" width="110" show-overflow-tooltip />
        <el-table-column prop="payee_bank_account_name" label="银行户名" min-width="130" show-overflow-tooltip />
        <el-table-column prop="payee_bank_account_no" label="收款账号" min-width="210" show-overflow-tooltip />
        <el-table-column prop="payee_bank_name" label="开户银行" min-width="220" show-overflow-tooltip />
        <el-table-column prop="unionpay_no" label="银联号" min-width="130" show-overflow-tooltip />
        <el-table-column prop="handler_name" label="经办人" width="100" show-overflow-tooltip />
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

    <el-dialog v-model="subjectConfigVisible" title="退付科目配置" width="760px" destroy-on-close>
      <div class="subject-config-options">
        <span>是否按天生成凭证</span>
        <el-switch v-model="generateVoucherByDay" />
        <span>是否按出账的单号汇总生成</span>
        <el-switch v-model="generateRefundVoucherByOutOrderNo" />
      </div>
      <el-table :data="subjectConfigRows" border v-loading="subjectConfigLoading">
        <el-table-column prop="business_item_type" label="出账种类" min-width="160" />
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
      <template #footer>
        <el-button @click="subjectConfigVisible = false">取消</el-button>
        <el-button type="primary" :loading="subjectConfigSaving" @click="saveSubjectConfig">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowDown, Search, Setting, Upload, DocumentChecked, Delete, MoreFilled } from '@element-plus/icons-vue'
import { baseApi } from '../../api/base'
import { caseFundApi } from '../../api/caseFund'
import { useContextStore } from '../../stores/context'
import { buildSubjectTreeOptions } from '../../utils/subjectTree'
import type { CaseFundRefund, CaseFundSubjectConfig, Subject } from '../../types/api'

const context = useContextStore()
const route = useRoute()
const router = useRouter()
const rows = ref<CaseFundRefund[]>([])
const subjects = ref<Subject[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(50)
const loading = ref(false)
const importing = ref(false)
const generating = ref(false)
const deleting = ref(false)
const importInputRef = ref<HTMLInputElement | null>(null)
const selectedRefundIds = ref<string[]>([])
const selectedRefundRows = ref<CaseFundRefund[]>([])
const subjectConfigVisible = ref(false)
const subjectConfigLoading = ref(false)
const subjectConfigSaving = ref(false)
const subjectConfigRows = ref<CaseFundSubjectConfig[]>([])
const generateVoucherByDay = ref(true)
const generateRefundVoucherByOutOrderNo = ref(false)
const voucherBizType: 'REFUND' = 'REFUND'
const initialRange = initialRefundDateRange(context.period)
const filters = reactive({
  quick_range: '',
  date_start: initialRange.start,
  date_end: initialRange.end,
  voucher_status: '',
  keyword: ''
})
const linkedRefundId = computed(() => routeQueryText(route.query.refund_id))

const pageAmountText = computed(() => {
  const totalCents = rows.value.reduce((sum, row) => sum + Math.round(Number(row.refund_amount || 0) * 100), 0)
  return money(totalCents / 100)
})

const ungeneratedCount = computed(() => rows.value.filter((row) => row.voucher_status === 'UNGENERATED').length)
const canUseMoreActions = computed(() => context.hasAnyPermission(['case_fund:delete', 'case_fund:subject_config', 'case_fund:import']))
const subjectTreeOptions = computed(() => buildSubjectTreeOptions(subjects.value))

const load = async () => {
  loading.value = true
  try {
    const result = await caseFundApi.refundList({
      refund_id: linkedRefundId.value,
      date_start: normalizeDateFilter(filters.date_start),
      date_end: normalizeDateFilter(filters.date_end),
      voucher_status: filters.voucher_status,
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

const query = async () => {
  if (linkedRefundId.value) {
    await clearLinkedRefund()
    return
  }
  page.value = 1
  await load()
}

const clearLinkedRefund = async () => {
  const nextQuery = { ...route.query }
  delete nextQuery.refund_id
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
    generateRefundVoucherByOutOrderNo.value = Number(configResult.generate_refund_voucher_by_out_order_no_flag ?? 0) === 1
    subjectConfigRows.value = (configResult.items || []).map((item) => ({ ...item }))
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
  subjectConfigSaving.value = true
  try {
    await caseFundApi.saveSubjectConfigs(
      voucherBizType,
      subjectConfigRows.value,
      generateVoucherByDay.value ? 1 : 0,
      generateRefundVoucherByOutOrderNo.value ? 1 : 0
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

function normalizeDateFilter(value: string | null | undefined) {
  return value ? String(value).trim() : ''
}

function initialRefundDateRange(period: string) {
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
    void handleDeleteRefunds()
    return
  }
  if (command === 'subjectConfig') {
    void openSubjectConfig()
    return
  }
  if (command === 'import') {
    chooseRefundImportFile()
  }
}

const chooseRefundImportFile = () => {
  if (!importInputRef.value) return
  importInputRef.value.value = ''
  importInputRef.value.click()
}

const handleRefundImportFile = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  if (!file.name.toLowerCase().endsWith('.xls')) {
    ElMessage.warning('请选择 .xls 格式的退付登记文件')
    return
  }
  importing.value = true
  try {
    const contentBase64 = await readFileBase64(file)
    const result = await caseFundApi.importRefunds(file.name, contentBase64)
    ElMessage.success(`导入完成：新增${result.created}条，跳过重复${result.skipped}条`)
    page.value = 1
    await load()
  } catch (error: any) {
    await showRefundImportError(error)
  } finally {
    importing.value = false
  }
}

const showRefundImportError = async (error: any) => {
  const details = Array.isArray(error?.data) ? error.data.filter(Boolean).map(String) : []
  if (details.length === 0) {
    return
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

const isRowSelectable = (row: CaseFundRefund) => {
  return row.voucher_status === 'UNGENERATED' || row.voucher_status === 'GENERATED'
}

const handleSelectionChange = (selection: CaseFundRefund[]) => {
  selectedRefundRows.value = selection
  selectedRefundIds.value = selection.map((row) => row.refund_id)
}

const buildGenerateVoucherQueryPayload = () => ({
  date_start: normalizeDateFilter(filters.date_start),
  date_end: normalizeDateFilter(filters.date_end),
  voucher_status: 'UNGENERATED',
  keyword: filters.keyword
})

const handleGenerateVoucher = async () => {
  if (selectedRefundIds.value.length === 0) {
    ElMessage.warning('请选择要生成凭证的退付记录')
    return
  }
  try {
    await ElMessageBox.confirm(
      `确定要为选中的 ${selectedRefundIds.value.length} 条记录生成凭证吗？`,
      '确认生成凭证',
      { confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning' }
    )
  } catch {
    return
  }
  generating.value = true
  try {
    const result = await caseFundApi.refundGenerateVoucher(selectedRefundIds.value)
    ElMessage.success(`成功生成 ${result.generated_count} 张凭证`)
    selectedRefundIds.value = []
    await load()
  } catch (e: any) {
    ElMessage.error(e?.message || '生成凭证失败')
  } finally {
    generating.value = false
  }
}

const handleGenerateVoucherByQuery = async () => {
  try {
    await ElMessageBox.confirm(
      '确定按当前查询条件生成全部未制证出账记录的凭证吗？该操作不受当前分页限制。',
      '按查询条件生成凭证',
      { confirmButtonText: '按查询生成', cancelButtonText: '取消', type: 'warning' }
    )
  } catch {
    return
  }
  generating.value = true
  try {
    const result = await caseFundApi.refundGenerateVoucherByQuery(buildGenerateVoucherQueryPayload())
    ElMessage.success(`成功生成 ${result.generated_count} 张凭证，处理 ${result.refund_count} 条出账记录`)
    selectedRefundIds.value = []
    selectedRefundRows.value = []
    await load()
  } catch (e: any) {
    ElMessage.error(e?.message || '生成凭证失败')
  } finally {
    generating.value = false
  }
}

const handleDeleteRefunds = async () => {
  if (selectedRefundIds.value.length === 0) {
    ElMessage.warning('请选择要删除的未制证退付记录')
    return
  }
  try {
    await ElMessageBox.confirm(
      `确定要删除选中的 ${selectedRefundIds.value.length} 条退付记录吗？已制证且原凭证仍有效、或已对账的数据不能删除。`,
      '确认删除退付记录',
      { confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning' }
    )
  } catch {
    return
  }
  deleting.value = true
  try {
    const result = await caseFundApi.deleteRefunds(selectedRefundIds.value)
    ElMessage.success(`已删除 ${result.deleted_count} 条退付记录`)
    selectedRefundIds.value = []
    await load()
  } catch (e: any) {
    ElMessage.error(e?.message || '删除退付记录失败')
  } finally {
    deleting.value = false
  }
}

watch(
  () => route.query.refund_id,
  async () => {
    if (route.path !== '/case-fund/refunds') return
    page.value = 1
    await load()
  }
)

onMounted(load)
</script>

<style scoped>
.status-select {
  width: 150px;
}

.case-fund-link-alert {
  margin-bottom: 12px;
}
</style>
