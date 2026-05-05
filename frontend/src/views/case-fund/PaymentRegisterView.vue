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
          <el-option label="已作废" value="VOIDED" />
        </el-select>
        <el-input v-model="filters.keyword" placeholder="案号 / 当事人 / 票据 / 流水号" clearable class="case-fund-search" @keyup.enter="query" />
        <el-button type="primary" :icon="Search" :loading="loading" @click="query">查询</el-button>
      </div>
      <div class="case-fund-actions">
        <input ref="importInputRef" type="file" accept=".xls" class="case-fund-file-input" @change="handlePaymentImportFile" />
        <el-button v-permission="'case_fund:import'" type="primary" :icon="Upload" :loading="importing" @click="choosePaymentImportFile">导入缴费</el-button>
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
    </div>

    <div class="case-fund-table-shell">
      <el-table :data="rows" border height="calc(100vh - 292px)" v-loading="loading">
        <el-table-column prop="payment_date" label="缴费日期" width="112" fixed="left" />
        <el-table-column prop="case_no" label="案号" min-width="190" fixed="left" show-overflow-tooltip />
        <el-table-column prop="business_type" label="业务类型" width="130" show-overflow-tooltip />
        <el-table-column prop="payer_name" label="缴费人" min-width="140" show-overflow-tooltip />
        <el-table-column prop="party_name" label="当事人" min-width="120" show-overflow-tooltip />
        <el-table-column prop="payment_amount" label="金额" width="130" align="right">
          <template #default="{ row }">{{ money(row.payment_amount) }}</template>
        </el-table-column>
        <el-table-column prop="receipt_no" label="票据号码" width="120" show-overflow-tooltip />
        <el-table-column prop="payment_method" label="收费方式" width="110" show-overflow-tooltip />
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
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Search, Upload } from '@element-plus/icons-vue'
import { caseFundApi } from '../../api/caseFund'
import { useContextStore } from '../../stores/context'
import type { CaseFundPayment } from '../../types/api'

const context = useContextStore()
const rows = ref<CaseFundPayment[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(50)
const loading = ref(false)
const importing = ref(false)
const importInputRef = ref<HTMLInputElement | null>(null)
const initialRange = initialPaymentDateRange(context.period)
const filters = reactive({
  quick_range: '',
  date_start: initialRange.start,
  date_end: initialRange.end,
  voucher_status: '',
  keyword: ''
})

const pageAmountText = computed(() => {
  const totalCents = rows.value.reduce((sum, row) => sum + Math.round(Number(row.payment_amount || 0) * 100), 0)
  return money(totalCents / 100)
})

const ungeneratedCount = computed(() => rows.value.filter((row) => row.voucher_status === 'UNGENERATED').length)

const load = async () => {
  loading.value = true
  try {
    const result = await caseFundApi.paymentList({
      date_start: filters.date_start,
      date_end: filters.date_end,
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
  page.value = 1
  await load()
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

onMounted(load)
</script>

<style scoped>
.case-fund-board {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
}

.case-fund-toolbar {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 14px;
  background: var(--card-bg);
  border: 1px solid var(--border-light);
  border-radius: 8px;
}

.case-fund-filters,
.case-fund-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.quick-range-select {
  width: 128px;
}

.date-filter-label,
.date-separator {
  color: var(--text-secondary);
  white-space: nowrap;
}

.payment-date-picker {
  width: 142px;
}

.status-select {
  width: 150px;
}

.case-fund-search {
  width: 300px;
}

.case-fund-file-input {
  display: none;
}

.case-fund-summary {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.case-fund-summary > div {
  padding: 14px 16px;
  background: var(--card-bg);
  border: 1px solid var(--border-light);
  border-radius: 8px;
}

.case-fund-summary span {
  display: block;
  color: var(--text-secondary);
  font-size: 13px;
}

.case-fund-summary strong {
  display: block;
  margin-top: 6px;
  color: var(--text-main);
  font-size: 20px;
  line-height: 1.2;
}

.case-fund-table-shell {
  background: var(--card-bg);
  border: 1px solid var(--border-light);
  border-radius: 8px;
  overflow: hidden;
}

.case-fund-pagination {
  display: flex;
  justify-content: flex-end;
  padding: 8px 0;
}

.muted-text {
  color: var(--text-mute);
}

@media (max-width: 1100px) {
  .case-fund-toolbar {
    flex-direction: column;
  }

  .case-fund-filters {
    flex-wrap: wrap;
  }

  .case-fund-search {
    width: min(100%, 360px);
  }
}
</style>
