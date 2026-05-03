<template>
  <div class="page-header">
    <div>
      <h1>科目余额表</h1>
      <p>按当前会计期间汇总各科目本期发生额与期末余额,支持快速核对凭证录入结果。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新数据</el-button>
      <el-button v-permission="'book:export'" type="primary" :icon="Download" @click="exportData">导出余额表</el-button>
    </div>
  </div>

  <div class="search-filter-section">
    <div class="filter-grid">
      <el-input v-model="keyword" placeholder="科目编码 / 名称" :prefix-icon="Search" clearable />
      <el-select v-model="zeroFilter" placeholder="发生额过滤">
        <el-option label="显示全部科目" value="all" />
        <el-option label="仅显示有发生额" value="active" />
        <el-option label="仅显示有余额" value="balance" />
      </el-select>
      <el-button type="primary" :icon="Search" @click="load">查询记录</el-button>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header">
      <strong>
        <el-icon><Notebook /></el-icon>
        科目余额表 · {{ context.period }}
      </strong>
      <span class="muted">共 {{ filteredRows.length }} 个科目 · 借方合计 ¥ {{ totalDebit.toFixed(2) }} · 贷方合计 ¥ {{ totalCredit.toFixed(2) }}</span>
    </div>
    <div class="panel-body compact">
      <el-table :data="filteredRows" height="calc(100vh - 320px)" show-summary :summary-method="summaryMethod">
        <el-table-column prop="subject_code" label="科目编码" width="140" align="center">
          <template #default="{ row }">
            <span class="text-mono" style="color: var(--brand-blue); font-weight: 600">{{ row.subject_code }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="subject_name" label="科目名称" min-width="220" />
        <el-table-column label="本期发生额" align="center">
          <el-table-column prop="debit_amount" label="借方" width="160" align="right">
            <template #default="{ row }">
              <span :class="['text-mono', Number(row.debit_amount) > 0 ? 'amount-debit' : 'muted']">
                {{ Number(row.debit_amount) > 0 ? '¥ ' + Number(row.debit_amount).toFixed(2) : '—' }}
              </span>
            </template>
          </el-table-column>
          <el-table-column prop="credit_amount" label="贷方" width="160" align="right">
            <template #default="{ row }">
              <span :class="['text-mono', Number(row.credit_amount) > 0 ? 'amount-credit' : 'muted']">
                {{ Number(row.credit_amount) > 0 ? '¥ ' + Number(row.credit_amount).toFixed(2) : '—' }}
              </span>
            </template>
          </el-table-column>
        </el-table-column>
        <el-table-column prop="balance_amount" label="期末余额" width="180" align="right">
          <template #default="{ row }">
            <span :class="['text-mono', balanceClass(row.balance_amount)]">
              {{ formatBalance(row.balance_amount) }}
            </span>
          </template>
        </el-table-column>
      </el-table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Download, Notebook, Refresh, Search } from '@element-plus/icons-vue'
import { booksApi } from '../../api/books'
import { useContextStore } from '../../stores/context'

const context = useContextStore()
const keyword = ref('')
const zeroFilter = ref<'all' | 'active' | 'balance'>('all')
const rows = ref<any[]>([])

const filteredRows = computed(() => {
  let result = rows.value
  if (keyword.value) {
    const kw = keyword.value.toLowerCase()
    result = result.filter((row) =>
      String(row.subject_code).toLowerCase().includes(kw) ||
      String(row.subject_name || '').toLowerCase().includes(kw)
    )
  }
  if (zeroFilter.value === 'active') {
    result = result.filter((row) => Number(row.debit_amount) > 0 || Number(row.credit_amount) > 0)
  } else if (zeroFilter.value === 'balance') {
    result = result.filter((row) => Math.abs(Number(row.balance_amount) || 0) > 0.001)
  }
  return result
})

const totalDebit = computed(() => filteredRows.value.reduce((sum, row) => sum + (Number(row.debit_amount) || 0), 0))
const totalCredit = computed(() => filteredRows.value.reduce((sum, row) => sum + (Number(row.credit_amount) || 0), 0))
const totalBalance = computed(() => filteredRows.value.reduce((sum, row) => sum + (Number(row.balance_amount) || 0), 0))

const formatBalance = (val: any) => {
  const num = Number(val) || 0
  if (Math.abs(num) < 0.001) return '—'
  return (num >= 0 ? '¥ ' : '-¥ ') + Math.abs(num).toFixed(2)
}

const balanceClass = (val: any) => {
  const num = Number(val) || 0
  if (Math.abs(num) < 0.001) return 'muted'
  return num >= 0 ? 'amount-debit' : 'amount-credit'
}

const summaryMethod = ({ columns }: { columns: any[] }) => {
  return columns.map((_, idx) => {
    if (idx === 0) return '合计'
    if (idx === 1) return ''
    if (idx === 2) return '¥ ' + totalDebit.value.toFixed(2)
    if (idx === 3) return '¥ ' + totalCredit.value.toFixed(2)
    if (idx === 4) return formatBalance(totalBalance.value)
    return ''
  })
}

const load = async () => {
  rows.value = await booksApi.subjectBalance(context.period) as any[]
}

const exportData = () => {
  ElMessage.info('导出功能开发中,可对接 Excel 导出接口')
}

onMounted(load)
</script>
