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
        <span class="date-filter-label">到账日期</span>
        <el-date-picker v-model="filters.date_start" type="date" value-format="YYYY-MM-DD" placeholder="开始日期" class="payment-date-picker" />
        <span class="date-separator">至</span>
        <el-date-picker v-model="filters.date_end" type="date" value-format="YYYY-MM-DD" placeholder="结束日期" class="payment-date-picker" />
        <el-select v-model="filters.voucher_status" placeholder="制证状态" clearable class="status-select">
          <el-option label="未生成凭证" value="UNGENERATED" />
          <el-option label="已生成凭证" value="GENERATED" />
        </el-select>
        <el-select v-model="filters.claim_status" placeholder="认领状态" clearable class="status-select">
          <el-option label="未认领" value="未认领" />
          <el-option label="已认领" value="已认领" />
        </el-select>
        <el-input v-model="filters.keyword" placeholder="缴款人 / 摘要 / 账号 / 备注" clearable class="case-fund-search" @keyup.enter="query" />
        <el-button type="primary" :icon="Search" :loading="loading" @click="query">查询</el-button>
      </div>
      <div class="case-fund-actions">
        <input ref="importInputRef" type="file" accept=".xls" class="case-fund-file-input" @change="handleImportFile" />
        <el-button v-permission="'case_fund:generate_voucher'" type="success" :icon="DocumentChecked" :loading="generating" @click="handleGenerateVoucher">生成凭证</el-button>
        <el-dropdown v-if="canUseMoreActions" trigger="click" @command="handleMoreAction">
          <el-button :icon="MoreFilled">
            更多功能
            <el-icon class="el-icon--right"><ArrowDown /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item v-permission="'case_fund:delete'" command="delete" :icon="Delete" :disabled="deleting" class="danger-dropdown-item" style="color: var(--el-color-danger)">删除</el-dropdown-item>
              <el-dropdown-item v-permission="'case_fund:subject_config'" command="subjectConfig" :icon="Setting">科目配置</el-dropdown-item>
              <el-dropdown-item v-permission="'case_fund:import'" command="import" :icon="Upload" :disabled="importing">导入不明款</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </div>
    </div>

    <div class="case-fund-summary">
      <div>
        <span>不明款笔数</span>
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
        <el-table-column prop="transaction_date" label="到账日期" width="112" fixed="left" />
        <el-table-column prop="payer_name" label="缴款人" min-width="140" fixed="left" show-overflow-tooltip />
        <el-table-column prop="unknown_amount" label="金额" width="130" align="right">
          <template #default="{ row }">{{ money(row.unknown_amount) }}</template>
        </el-table-column>
        <el-table-column prop="bank_summary" label="银行摘要" min-width="180" show-overflow-tooltip />
        <el-table-column prop="bank_remark" label="银行备注" min-width="220" show-overflow-tooltip />
        <el-table-column prop="court_bank_account_no" label="法院账号" min-width="210" show-overflow-tooltip />
        <el-table-column prop="arrival_method" label="到账方式" width="110" show-overflow-tooltip />
        <el-table-column prop="payer_bank_name" label="付款银行" min-width="160" show-overflow-tooltip />
        <el-table-column prop="payer_account_no" label="付款账号" min-width="190" show-overflow-tooltip />
        <el-table-column prop="claim_status" label="认领状态" width="110" align="center">
          <template #default="{ row }">
            <el-tag :type="claimStatusType(row.claim_status)" effect="light">{{ row.claim_status || '未认领' }}</el-tag>
          </template>
        </el-table-column>
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

    <el-dialog v-model="subjectConfigVisible" title="不明款科目配置" width="760px" destroy-on-close>
      <div class="subject-config-options">
        <span>是否按天生成凭证</span>
        <el-switch v-model="generateVoucherByDay" />
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
import { ArrowDown, Delete, DocumentChecked, MoreFilled, Search, Setting, Upload } from '@element-plus/icons-vue'
import { baseApi } from '../../api/base'
import { caseFundApi } from '../../api/caseFund'
import { useContextStore } from '../../stores/context'
import { buildSubjectTreeOptions } from '../../utils/subjectTree'
import type { CaseFundSubjectConfig, CaseFundUnknownPayment, Subject } from '../../types/api'

const context = useContextStore()
const rows = ref<CaseFundUnknownPayment[]>([])
const subjects = ref<Subject[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(50)
const loading = ref(false)
const importing = ref(false)
const generating = ref(false)
const deleting = ref(false)
const importInputRef = ref<HTMLInputElement | null>(null)
const selectedUnknownPaymentIds = ref<string[]>([])
const selectedUnknownPaymentRows = ref<CaseFundUnknownPayment[]>([])
const subjectConfigVisible = ref(false)
const subjectConfigLoading = ref(false)
const subjectConfigSaving = ref(false)
const subjectConfigRows = ref<CaseFundSubjectConfig[]>([])
const generateVoucherByDay = ref(true)
const voucherBizType: 'UNKNOWN_PAYMENT' = 'UNKNOWN_PAYMENT'
const initialRange = initialPaymentDateRange(context.period)
const filters = reactive({
  quick_range: '',
  date_start: initialRange.start,
  date_end: initialRange.end,
  voucher_status: '',
  claim_status: '',
  keyword: ''
})

const pageAmountText = computed(() => {
  const totalCents = rows.value.reduce((sum, row) => sum + Math.round(Number(row.unknown_amount || 0) * 100), 0)
  return money(totalCents / 100)
})

const ungeneratedCount = computed(() => rows.value.filter((row) => row.voucher_status === 'UNGENERATED').length)
const canUseMoreActions = computed(() => context.hasAnyPermission(['case_fund:delete', 'case_fund:subject_config', 'case_fund:import']))
const subjectTreeOptions = computed(() => buildSubjectTreeOptions(subjects.value))

const load = async () => {
  loading.value = true
  try {
    const result = await caseFundApi.unknownPaymentList({
      date_start: filters.date_start,
      date_end: filters.date_end,
      voucher_status: filters.voucher_status,
      claim_status: filters.claim_status,
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
    await caseFundApi.saveSubjectConfigs(voucherBizType, subjectConfigRows.value, generateVoucherByDay.value ? 1 : 0)
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
    void handleDeleteUnknownPayments()
    return
  }
  if (command === 'subjectConfig') {
    void openSubjectConfig()
    return
  }
  if (command === 'import') {
    chooseImportFile()
  }
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
  if (!file.name.toLowerCase().endsWith('.xls')) {
    ElMessage.warning('请选择 .xls 格式的不明款文件')
    return
  }
  importing.value = true
  try {
    const contentBase64 = await readFileBase64(file)
    const result = await caseFundApi.importUnknownPayments(file.name, contentBase64)
    ElMessage.success(`导入完成：新增${result.created}条，跳过重复${result.skipped}条`)
    page.value = 1
    await load()
  } finally {
    importing.value = false
  }
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

const claimStatusType = (status: string) => {
  return status === '已认领' || status === 'CLAIMED' ? 'success' : 'info'
}

const isRowSelectable = (row: CaseFundUnknownPayment) => {
  return row.voucher_status === 'UNGENERATED' || row.voucher_status === 'GENERATED'
}

const handleSelectionChange = (selection: CaseFundUnknownPayment[]) => {
  selectedUnknownPaymentRows.value = selection
  selectedUnknownPaymentIds.value = selection.map((row) => row.unknown_payment_id)
}

const handleGenerateVoucher = async () => {
  if (selectedUnknownPaymentIds.value.length === 0) {
    ElMessage.warning('请选择要生成凭证的不明款记录')
    return
  }
  try {
    await ElMessageBox.confirm(
      `确定要为选中的 ${selectedUnknownPaymentIds.value.length} 条不明款生成凭证吗？`,
      '确认生成凭证',
      { confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning' }
    )
  } catch {
    return
  }
  generating.value = true
  try {
    const result = await caseFundApi.unknownPaymentGenerateVoucher(selectedUnknownPaymentIds.value)
    ElMessage.success(`成功生成 ${result.generated_count} 张凭证`)
    selectedUnknownPaymentIds.value = []
    await load()
  } catch (e: any) {
    ElMessage.error(e?.message || '生成凭证失败')
  } finally {
    generating.value = false
  }
}

const handleDeleteUnknownPayments = async () => {
  if (selectedUnknownPaymentIds.value.length === 0) {
    ElMessage.warning('请选择要删除的未制证不明款记录')
    return
  }
  if (selectedUnknownPaymentRows.value.some((row) => row.voucher_status !== 'UNGENERATED')) {
    ElMessage.warning('已制证不明款记录不能删除；如原凭证已删除，请使用生成凭证重新制证')
    return
  }
  try {
    await ElMessageBox.confirm(
      `确定要删除选中的 ${selectedUnknownPaymentIds.value.length} 条不明款记录吗？已制证或已对账的数据不能删除。`,
      '确认删除不明款记录',
      { confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning' }
    )
  } catch {
    return
  }
  deleting.value = true
  try {
    const result = await caseFundApi.deleteUnknownPayments(selectedUnknownPaymentIds.value)
    ElMessage.success(`已删除 ${result.deleted_count} 条不明款记录`)
    selectedUnknownPaymentIds.value = []
    await load()
  } catch (e: any) {
    ElMessage.error(e?.message || '删除不明款记录失败')
  } finally {
    deleting.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.status-select {
  width: 132px;
}
</style>
