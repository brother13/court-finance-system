<template>
  <div class="case-fund-board">
    <div class="case-fund-toolbar">
      <div class="case-fund-filters">
        <el-select v-model="filters.bank_code" placeholder="开户银行" class="bank-select" @change="query">
          <el-option v-for="bank in bankOptions" :key="bank.code" :label="bank.name" :value="bank.code" />
        </el-select>
        <span class="date-filter-label">交易日期</span>
        <el-date-picker v-model="filters.date_start" type="date" value-format="YYYY-MM-DD" placeholder="开始日期" class="statement-date-picker" />
        <span class="date-separator">至</span>
        <el-date-picker v-model="filters.date_end" type="date" value-format="YYYY-MM-DD" placeholder="结束日期" class="statement-date-picker" />
        <el-select v-model="filters.direction" placeholder="收支方向" clearable class="direction-select">
          <el-option label="收入" value="CREDIT" />
          <el-option label="支出" value="DEBIT" />
        </el-select>
        <el-input v-model="filters.keyword" placeholder="对方户名 / 账号 / 用途 / 流水号" clearable class="case-fund-search" @keyup.enter="query" />
        <el-button type="primary" :icon="Search" :loading="loading" @click="query">查询</el-button>
      </div>
      <div class="case-fund-actions">
        <input ref="importInputRef" type="file" accept=".xlsx" class="case-fund-file-input" @change="handleBankStatementImportFile" />
        <el-button v-permission="'case_fund:reconcile'" type="success" :icon="DocumentChecked" :loading="reconciling" @click="runAutoReconcile">自动对账</el-button>
        <el-button v-permission="'case_fund:delete'" type="danger" :icon="Delete" :loading="deleting" @click="handleDeleteBankStatements">删除</el-button>
        <el-button v-permission="'case_fund:import'" type="primary" :icon="Upload" :loading="importing" @click="chooseBankStatementImportFile">导入对账单</el-button>
      </div>
    </div>

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
        <span>已对账</span>
        <strong>{{ reconcileSummary.MATCHED || 0 }}</strong>
      </div>
      <div>
        <span>金额不符</span>
        <strong>{{ reconcileSummary.AMOUNT_DIFF || 0 }}</strong>
      </div>
    </div>

    <div class="case-fund-table-shell">
      <el-table :data="rows" border height="330px" v-loading="loading" @selection-change="handleStatementSelectionChange">
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
        <el-table-column prop="reconcile_status" label="对账状态" width="110" align="center" fixed="right">
          <template #default="{ row }">
            <el-tag effect="light" :type="reconcileStatusType(row.reconcile_status)">{{ reconcileStatusLabel(row.reconcile_status) }}</el-tag>
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

    <div class="case-fund-table-shell">
      <div class="reconcile-panel-header">
        <strong>对账结果</strong>
        <span>缴费按银行流水号匹配，退付按出账单号匹配。</span>
      </div>
      <el-table :data="reconcileRows" border height="320px" v-loading="reconcileLoading">
        <el-table-column prop="reconcile_date" label="日期" width="112" fixed="left" />
        <el-table-column prop="match_status" label="结果" width="110" fixed="left">
          <template #default="{ row }">
            <el-tag effect="light" :type="reconcileStatusType(row.match_status)">{{ reconcileStatusLabel(row.match_status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="biz_type" label="业务类型" width="100">
          <template #default="{ row }">{{ bizTypeLabel(row.biz_type) }}</template>
        </el-table-column>
        <el-table-column prop="bank_serial_no" label="交易流水号 / 出账单号" min-width="170" show-overflow-tooltip />
        <el-table-column prop="bank_amount" label="银行金额" width="120" align="right">
          <template #default="{ row }">{{ money(row.bank_amount) }}</template>
        </el-table-column>
        <el-table-column prop="biz_amount" label="业务金额" width="120" align="right">
          <template #default="{ row }">{{ money(row.biz_amount) }}</template>
        </el-table-column>
        <el-table-column prop="diff_amount" label="差额" width="120" align="right">
          <template #default="{ row }">{{ money(row.diff_amount) }}</template>
        </el-table-column>
        <el-table-column prop="bank_summary" label="银行摘要" min-width="200" show-overflow-tooltip />
        <el-table-column prop="biz_summary" label="业务摘要" min-width="240" show-overflow-tooltip />
        <el-table-column prop="match_rule" label="规则" width="110">
          <template #default>流水号</template>
        </el-table-column>
      </el-table>
    </div>

    <div class="case-fund-pagination">
      <el-pagination
        v-model:current-page="reconcilePage"
        v-model:page-size="reconcilePageSize"
        background
        layout="total, sizes, prev, pager, next"
        :page-sizes="[20, 50, 100, 200]"
        :total="reconcileTotal"
        @size-change="loadReconcile"
        @current-change="loadReconcile"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { DocumentChecked, Search, Upload, Delete } from '@element-plus/icons-vue'
import * as XLSX from 'xlsx'
import { caseFundApi } from '../../api/caseFund'
import { useContextStore } from '../../stores/context'
import type { CaseFundBankReconcile, CaseFundBankStatement } from '../../types/api'

const context = useContextStore()
const bankOptions = [
  { code: 'SHENGJING', name: '盛京银行' },
  { code: 'CCB', name: '建设银行' }
]
const rows = ref<CaseFundBankStatement[]>([])
const reconcileRows = ref<CaseFundBankReconcile[]>([])
const total = ref(0)
const reconcileTotal = ref(0)
const debitAmount = ref(0)
const creditAmount = ref(0)
const reconcileSummary = ref<Record<string, number>>({})
const page = ref(1)
const pageSize = ref(50)
const reconcilePage = ref(1)
const reconcilePageSize = ref(50)
const loading = ref(false)
const reconcileLoading = ref(false)
const importing = ref(false)
const reconciling = ref(false)
const deleting = ref(false)
const importInputRef = ref<HTMLInputElement | null>(null)
const selectedStatementIds = ref<string[]>([])
const initialRange = initialStatementDateRange(context.period)
const filters = reactive({
  bank_code: 'SHENGJING',
  date_start: initialRange.start,
  date_end: initialRange.end,
  direction: '',
  keyword: ''
})

const selectedBankName = computed(() => bankOptions.find((bank) => bank.code === filters.bank_code)?.name || '')

const load = async () => {
  loading.value = true
  try {
    const result = await caseFundApi.bankStatementList({
      bank_code: filters.bank_code,
      date_start: filters.date_start,
      date_end: filters.date_end,
      direction: filters.direction,
      keyword: filters.keyword,
      page: page.value,
      pagesize: pageSize.value
    })
    rows.value = result.items || []
    total.value = result.total || 0
    debitAmount.value = Number(result.debit_amount || 0)
    creditAmount.value = Number(result.credit_amount || 0)
  } finally {
    loading.value = false
  }
}

const query = async () => {
  page.value = 1
  reconcilePage.value = 1
  await load()
  await loadReconcile()
}

const loadReconcile = async () => {
  reconcileLoading.value = true
  try {
    const result = await caseFundApi.bankReconcileList({
      date_start: filters.date_start,
      date_end: filters.date_end,
      keyword: filters.keyword,
      page: reconcilePage.value,
      pagesize: reconcilePageSize.value
    })
    reconcileRows.value = result.items || []
    reconcileTotal.value = result.total || 0
    reconcileSummary.value = result.summary || {}
  } finally {
    reconcileLoading.value = false
  }
}

const runAutoReconcile = async () => {
  reconciling.value = true
  try {
    const result = await caseFundApi.runBankReconcile({
      bank_code: filters.bank_code,
      date_start: filters.date_start,
      date_end: filters.date_end
    })
    const amountDiff = result.counts?.AMOUNT_DIFF || 0
    ElMessage.success(`自动对账完成：生成${result.total}条结果，金额不符${amountDiff}条`)
    page.value = 1
    reconcilePage.value = 1
    await load()
    await loadReconcile()
  } catch (e: any) {
    ElMessage.error(e?.message || '自动对账失败')
  } finally {
    reconciling.value = false
  }
}

const isStatementSelectable = (row: CaseFundBankStatement) => {
  return row.reconcile_status === 'UNMATCHED'
}

const handleStatementSelectionChange = (selection: CaseFundBankStatement[]) => {
  selectedStatementIds.value = selection.map((row) => row.statement_id)
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
    await loadReconcile()
  } catch (e: any) {
    ElMessage.error(e?.message || '删除银行流水失败')
  } finally {
    deleting.value = false
  }
}

const chooseBankStatementImportFile = () => {
  if (filters.bank_code === 'CCB') {
    ElMessage.warning('建设银行电子对账单模板待配置，请先使用盛京银行模板')
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
  if (!file.name.toLowerCase().endsWith('.xlsx')) {
    ElMessage.warning('请选择 .xlsx 格式的银行电子对账单')
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
    await loadReconcile()
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

const bizTypeLabel = (type: string) => {
  const map: Record<string, string> = {
    PAYMENT: '缴费',
    REFUND: '退付'
  }
  return map[type] || type || '-'
}

onMounted(async () => {
  await load()
  await loadReconcile()
})
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

.bank-select,
.direction-select {
  width: 132px;
}

.date-filter-label,
.date-separator {
  color: var(--text-secondary);
  white-space: nowrap;
}

.statement-date-picker {
  width: 142px;
}

.case-fund-search {
  width: 300px;
}

.case-fund-file-input {
  display: none;
}

.case-fund-summary {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
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

.reconcile-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 14px;
  border-bottom: 1px solid var(--border-light);
}

.reconcile-panel-header span {
  color: var(--text-secondary);
  font-size: 13px;
}

.case-fund-pagination {
  display: flex;
  justify-content: flex-end;
  padding: 8px 0;
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
