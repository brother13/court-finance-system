<template>
  <div class="page-header">
    <div>
      <h1>明细账</h1>
      <p>按科目与日期范围检索凭证明细,支持借贷金额与辅助核算项联动展示。</p>
    </div>
    <div class="page-actions">
      <el-button :icon="Refresh" @click="load">刷新数据</el-button>
      <el-button v-permission="'book:export'" type="primary" :icon="Download" @click="exportData">导出明细</el-button>
    </div>
  </div>

  <div class="search-filter-section">
    <div class="filter-grid">
      <el-input v-model="query.subjectCode" placeholder="科目编码" :prefix-icon="Search" clearable />
      <el-date-picker
        v-model="dateRange"
        type="daterange"
        value-format="YYYY-MM-DD"
        range-separator="至"
        start-placeholder="开始日期"
        end-placeholder="结束日期"
        style="width: 100%"
      />
      <el-button type="primary" :icon="Search" @click="load">查询记录</el-button>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header">
      <strong>
        <el-icon><Tickets /></el-icon>
        明细账记录 · {{ context.period }}
      </strong>
      <span class="muted">共 {{ rows.length }} 条记录 · 借方合计 ¥ {{ totalDebit.toFixed(2) }} · 贷方合计 ¥ {{ totalCredit.toFixed(2) }}</span>
    </div>
    <div class="panel-body compact">
      <el-table :data="rows" height="calc(100vh - 320px)">
        <el-table-column prop="voucher_date" label="日期" width="120" align="center" />
        <el-table-column prop="voucher_no" label="凭证号" width="100" align="center">
          <template #default="{ row }">
            <span class="text-mono" style="color: var(--brand-blue); font-weight: 600">记-{{ String(row.voucher_no).padStart(4, '0') }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="summary" label="摘要" min-width="200" />
        <el-table-column prop="subject_code" label="科目" width="160">
          <template #default="{ row }">
            <span class="text-mono">{{ row.subject_code }}</span>
            <span class="muted" v-if="row.subject_name"> · {{ row.subject_name }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="debit_amount" label="借方金额" width="140" align="right">
          <template #default="{ row }">
            <span :class="['text-mono', Number(row.debit_amount) > 0 ? 'amount-debit' : 'muted']">
              {{ Number(row.debit_amount) > 0 ? '¥ ' + Number(row.debit_amount).toFixed(2) : '—' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="credit_amount" label="贷方金额" width="140" align="right">
          <template #default="{ row }">
            <span :class="['text-mono', Number(row.credit_amount) > 0 ? 'amount-credit' : 'muted']">
              {{ Number(row.credit_amount) > 0 ? '¥ ' + Number(row.credit_amount).toFixed(2) : '—' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="aux_desc" label="辅助核算" min-width="200">
          <template #default="{ row }">
            <span v-if="row.aux_desc">{{ row.aux_desc }}</span>
            <span v-else class="muted">—</span>
          </template>
        </el-table-column>
      </el-table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Download, Refresh, Search, Tickets } from '@element-plus/icons-vue'
import { booksApi } from '../../api/books'
import { useContextStore } from '../../stores/context'

const context = useContextStore()
const periodStart = `${context.period}-01`
const lastDay = new Date(Number(context.period.slice(0, 4)), Number(context.period.slice(5, 7)), 0).getDate()
const periodEnd = `${context.period}-${String(lastDay).padStart(2, '0')}`
const dateRange = ref<[string, string]>([periodStart, periodEnd])
const query = reactive({ subjectCode: '' })
const rows = ref<any[]>([])

const totalDebit = computed(() => rows.value.reduce((sum, row) => sum + (Number(row.debit_amount) || 0), 0))
const totalCredit = computed(() => rows.value.reduce((sum, row) => sum + (Number(row.credit_amount) || 0), 0))

const load = async () => {
  rows.value = await booksApi.detailLedger({
    period: context.period,
    subjectCode: query.subjectCode,
    startDate: dateRange.value[0],
    endDate: dateRange.value[1]
  }) as any[]
}

const exportData = () => {
  ElMessage.info('导出功能开发中,可对接 Excel 导出接口')
}
</script>
