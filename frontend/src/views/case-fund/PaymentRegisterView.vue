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
        <span class="date-filter-label">缴费日期</span>
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
        <el-input v-model="filters.keyword" placeholder="案号 / 当事人 / 票据 / 流水号" clearable class="case-fund-search" @keyup.enter="query" />
        <el-button type="primary" :icon="Search" :loading="loading" @click="query">查询</el-button>
      </div>
      <div class="case-fund-actions">
        <input ref="importInputRef" type="file" accept=".xls" class="case-fund-file-input" @change="handlePaymentImportFile" />
        <el-dropdown v-if="canUseMoreActions" trigger="click" @command="handleMoreAction">
          <el-button :icon="MoreFilled">
            更多功能
            <el-icon class="el-icon--right"><ArrowDown /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item v-permission="'case_fund:delete'" command="delete" :icon="Delete" :disabled="deleting" class="danger-dropdown-item" style="color: var(--el-color-danger)">删除</el-dropdown-item>
              <el-dropdown-item v-permission="'case_fund:subject_config'" command="subjectConfig" :icon="Setting">科目配置</el-dropdown-item>
              <el-dropdown-item v-permission="'case_fund:import'" command="import" :icon="Upload" :disabled="importing">导入缴费</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </div>
    </div>

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
        <el-table-column prop="payment_date" label="缴费日期" width="112" fixed="left" />
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
            <span v-if="row.voucher_no">{{ row.voucher_period }}-{{ row.voucher_no }}</span>
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
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowDown, Search, Setting, Upload, DocumentChecked, Delete, MoreFilled } from '@element-plus/icons-vue'
import { baseApi } from '../../api/base'
import { caseFundApi } from '../../api/caseFund'
import { useContextStore } from '../../stores/context'
import { buildSubjectTreeOptions } from '../../utils/subjectTree'
import type { CaseFundPayment, CaseFundSubjectConfig, Subject } from '../../types/api'

const context = useContextStore()
const rows = ref<CaseFundPayment[]>([])
const subjects = ref<Subject[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(50)
const loading = ref(false)
const importing = ref(false)
const generating = ref(false)
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
  keyword: ''
})

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
      date_start: filters.date_start,
      date_end: filters.date_end,
      voucher_status: filters.voucher_status,
      payment_method: filters.payment_method,
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
  page.value = 1
  await load()
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
  importing.value = true
  try {
    const contentBase64 = await readFileBase64(file)
    const result = await caseFundApi.importPayments(file.name, contentBase64)
    ElMessage.success(`导入完成：新增${result.created}条，覆盖${result.updated || 0}条，跳过重复${result.skipped}条`)
    page.value = 1
    await load()
  } catch (error: any) {
    await showPaymentImportError(error)
  } finally {
    importing.value = false
  }
}

const showPaymentImportError = async (error: any) => {
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
  generating.value = true
  try {
    const result = await caseFundApi.paymentGenerateVoucher(selectedPaymentIds.value)
    ElMessage.success(`成功生成 ${result.generated_count} 张凭证`)
    selectedPaymentIds.value = []
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
      '确定按当前查询条件生成全部未制证缴费记录的凭证吗？该操作不受当前分页限制。',
      '按查询条件生成凭证',
      { confirmButtonText: '按查询生成', cancelButtonText: '取消', type: 'warning' }
    )
  } catch {
    return
  }
  generating.value = true
  try {
    const result = await caseFundApi.paymentGenerateVoucherByQuery(buildGenerateVoucherQueryPayload())
    ElMessage.success(`成功生成 ${result.generated_count} 张凭证，处理 ${result.payment_count} 条缴费记录`)
    selectedPaymentIds.value = []
    selectedPaymentRows.value = []
    await load()
  } catch (e: any) {
    ElMessage.error(e?.message || '生成凭证失败')
  } finally {
    generating.value = false
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

onMounted(load)
</script>

<style scoped>
.payment-method-select {
  width: 140px;
}
</style>
