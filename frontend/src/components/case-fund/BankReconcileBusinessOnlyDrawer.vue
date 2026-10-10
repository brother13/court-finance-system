<template>
  <el-drawer :model-value="modelValue" title="业务待核查" size="85%" @update:model-value="emit('update:modelValue', $event)">
    <el-alert type="info" :closable="false" show-icon>
      当前账套 {{ dateStart || '不限' }} 至 {{ dateEnd || '不限' }} 的业务单边结果，不受银行流水的户名、用途、收支方向筛选影响。
      未匹配表示尚未建立对应关系，不代表银行一定没有到账；不明款认领需核查原不明款来源，不能重复作为银行收入。
    </el-alert>
    <div class="actions">
      <span>共 {{ total }} 条。结果来自最近一次自动对账。</span>
      <el-button :loading="loading" @click="load">刷新</el-button>
      <el-button :loading="exporting" :disabled="loading || !total" @click="exportRows">导出业务待核查</el-button>
    </div>
    <el-table :data="rows" border height="520px" v-loading="loading" empty-text="暂无业务单边结果；新规则需重新执行自动对账后生效">
      <el-table-column prop="reconcile_date" label="业务日期" width="110" />
      <el-table-column label="业务来源" width="110"><template #default="{ row }">{{ typeLabel(row.biz_type) }}</template></el-table-column>
      <el-table-column prop="biz_no" label="业务编号" min-width="180" show-overflow-tooltip />
      <el-table-column prop="biz_summary" label="业务摘要" min-width="200" show-overflow-tooltip />
      <el-table-column label="业务金额" width="130" align="right"><template #default="{ row }">{{ money(row.biz_amount) }}</template></el-table-column>
      <el-table-column label="核查提示" min-width="230"><template #default="{ row }">{{ investigationHint(row) }}</template></el-table-column>
      <el-table-column label="操作" width="100" fixed="right"><template #default="{ row }">
        <el-button link type="primary" :disabled="!canNavigate(row)" @click="emit('view-business', row)">查看业务</el-button>
      </template></el-table-column>
    </el-table>
    <el-pagination v-model:current-page="page" :page-size="50" :total="total" layout="total, prev, pager, next" @current-change="load" />
  </el-drawer>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import * as XLSX from 'xlsx'
import { caseFundApi } from '../../api/caseFund'
import { useContextStore } from '../../stores/context'
import type { CaseFundBankReconcile } from '../../types/api'

const props = defineProps<{
  modelValue: boolean
  dateStart: string
  dateEnd: string
  canNavigate: (row: CaseFundBankReconcile) => boolean
}>()
const emit = defineEmits<{
  (event: 'update:modelValue', value: boolean): void
  (event: 'view-business', row: CaseFundBankReconcile): void
}>()
const context = useContextStore()
const rows = ref<CaseFundBankReconcile[]>([])
const total = ref(0)
const page = ref(1)
const loading = ref(false)
const exporting = ref(false)
let requestVersion = 0
const scopeKey = () => JSON.stringify([context.accountSetId, context.year, props.dateStart, props.dateEnd])
const requestScope = () => ({ status: 'BIZ_ONLY', date_start: props.dateStart, date_end: props.dateEnd })
const typeLabel = (type?: string) => ({ PAYMENT: '缴费登记', UNKNOWN_PAYMENT: '不明款', REFUND: '退付登记' }[type || ''] || type || '-')
const money = (amount: string | number) => Number(amount || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const investigationHint = (row: CaseFundBankReconcile) => row.biz_type === 'PAYMENT' && !row.bank_serial_no
  ? '缺少银行流水号；核查收款凭据或原不明款认领来源'
  : '未建立银行对应关系，请核查日期、账号及金额'

async function load() {
  const version = ++requestVersion
  const scope = scopeKey()
  rows.value = []
  total.value = 0
  loading.value = true
  try {
    const result = await caseFundApi.bankReconcileList({ ...requestScope(), page: page.value, pagesize: 50 })
    if (version !== requestVersion || scope !== scopeKey() || !props.modelValue) return
    rows.value = result.items || []
    total.value = Number(result.total || 0)
  } catch (error: any) {
    if (version === requestVersion) ElMessage.error(error?.message || '加载业务待核查失败')
  } finally {
    if (version === requestVersion) loading.value = false
  }
}

async function exportRows() {
  if (exporting.value) return
  exporting.value = true
  const scope = scopeKey()
  const filters = requestScope()
  const accountName = context.accountSetName
  try {
    const items: CaseFundBankReconcile[] = []
    let expectedTotal = 0
    for (let currentPage = 1; ; currentPage++) {
      if (scope !== scopeKey()) throw new Error('账套或日期范围已变化，请重新导出')
      const result = await caseFundApi.bankReconcileList({ ...filters, page: currentPage, pagesize: 200 })
      if (scope !== scopeKey()) throw new Error('账套或日期范围已变化，请重新导出')
      if (currentPage === 1) expectedTotal = Number(result.total || 0)
      if (Number(result.total || 0) !== expectedTotal) throw new Error('对账结果已变化，请重新导出')
      const batch = result.items || []
      items.push(...batch)
      if (items.length >= expectedTotal) break
      if (!batch.length) throw new Error('未取得完整对账结果，请重新导出')
    }
    if (new Set(items.map((row) => row.reconcile_id)).size !== items.length) throw new Error('对账结果分页发生变化，请重新导出')
    const sheet = XLSX.utils.aoa_to_sheet([
      ['业务待核查', accountName, filters.date_start, filters.date_end],
      ['不明款认领需核查原到账来源，不能重复作为银行收入；此表为业务单边结果。'],
      ['业务日期', '业务来源', '业务编号', '业务摘要', '业务金额', '核查提示', '业务ID', '对账结果ID'],
      ...items.map((row) => [row.reconcile_date, typeLabel(row.biz_type), row.biz_no || '', row.biz_summary || '',
        Number(row.biz_amount), investigationHint(row), row.biz_id || '', row.reconcile_id])
    ])
    const book = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(book, sheet, '业务待核查')
    XLSX.writeFile(book, `业务待核查_${filters.date_start || '全部'}_${filters.date_end || '全部'}.xlsx`)
  } catch (error: any) {
    ElMessage.error(error?.message || '导出业务待核查失败')
  } finally {
    exporting.value = false
  }
}

watch(() => [props.modelValue, scopeKey()], () => {
  ++requestVersion
  rows.value = []
  total.value = 0
  page.value = 1
  loading.value = false
  if (props.modelValue) void load()
}, { immediate: true })
</script>

<style scoped>
.actions { display: flex; align-items: center; gap: 12px; margin: 16px 0; }
.actions span { flex: 1; color: var(--el-text-color-secondary); }
.el-pagination { margin-top: 16px; }
</style>
