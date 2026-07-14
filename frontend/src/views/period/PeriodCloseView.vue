<template>
  <div class="page-header">
    <div>
      <h1>期末结账</h1>
      <p>按账套会计期间执行期末检查、月结关闭、反结账和年终结转生成下一年度。</p>
    </div>
    <div class="page-actions">
      <el-select v-model="selectedYear" style="width: 120px" @change="changeYear">
        <el-option v-for="year in yearOptions" :key="year" :label="`${year}年`" :value="year" />
      </el-select>
    </div>
  </div>

  <div class="period-summary-strip">
    <div class="period-summary-item">
      <span>已结账</span>
      <strong>{{ closedPeriodCount }}</strong>
    </div>
    <div class="period-summary-item">
      <span>当前开放</span>
      <strong>{{ firstOpenPeriod || '-' }}</strong>
    </div>
    <div class="period-summary-item" :class="{ danger: blockCount > 0, success: checkResult && blockCount === 0 }">
      <span>阻断项</span>
      <strong>{{ blockCount }}</strong>
    </div>
    <div class="period-summary-item" :class="{ warning: warningCount > 0 }">
      <span>风险提示</span>
      <strong>{{ warningCount }}</strong>
    </div>
  </div>

  <div class="period-close-workbench">
    <section class="period-ledger-panel">
      <div class="ledger-panel-header">
        <div>
          <strong>会计期间台账</strong>
          <span>点击期间查看检查结果和可执行操作</span>
        </div>
        <el-tag size="small" type="info">{{ selectedYear }} 年度</el-tag>
      </div>
      <el-table
        :data="periodRows"
        border
        highlight-current-row
        :row-class-name="periodRowClassName"
        @row-click="selectPeriodFromRow"
      >
        <el-table-column prop="period" label="期间" width="110" align="center" />
        <el-table-column prop="start_date" label="开始日期" width="120" align="center" />
        <el-table-column prop="end_date" label="结束日期" width="120" align="center" />
        <el-table-column prop="status" label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" size="small">{{ statusText(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="检查结果" min-width="140">
          <template #default="{ row }">
            <span :class="['period-check-state', checkStateClass(row)]">{{ checkStatusText(row) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="风险提示" min-width="130">
          <template #default="{ row }">
            <span class="period-risk-state">{{ riskStatusText(row) }}</span>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <aside class="period-action-panel">
      <div class="action-panel-header">
        <div>
          <strong>{{ selectedPeriod }} 处理</strong>
          <span>{{ selectedPeriodRow?.start_date || '-' }} 至 {{ selectedPeriodRow?.end_date || '-' }}</span>
        </div>
        <el-tag :type="statusTagType(currentStatus)" size="small">{{ statusText(currentStatus) }}</el-tag>
      </div>

      <div class="action-buttons">
        <el-button class="primary-action" type="primary" :icon="CircleCheck" :disabled="!checkResult || checkResult.can_close !== 1" @click="closePeriod">
          结账
        </el-button>
        <el-button plain :icon="Refresh" :disabled="isSelectedPeriodClosed" @click="runCheck">重新检查</el-button>
        <el-button :icon="RefreshLeft" :disabled="currentStatus !== 'CLOSED'" @click="reopenPeriod">反结账</el-button>
        <el-button
          class="preopen-year-action"
          type="warning"
          plain
          :icon="Calendar"
          :disabled="!canPreopenNextYear"
          :title="preopenYearDisabledReason"
          @click="preopenNextYear"
        >
          预开下一年度
        </el-button>
        <el-button class="year-end-action" type="success" :icon="Calendar" :disabled="!canYearEnd" @click="yearEnd">
          年终结转生成下一年度
        </el-button>
      </div>

      <el-alert
        v-if="checkResult && checkResult.can_close === 1"
        type="success"
        show-icon
        :closable="false"
        title="强制检查已通过，可以执行结账。"
      />
      <el-alert
        v-else-if="isSelectedPeriodClosed"
        type="info"
        show-icon
        :closable="false"
        title="该期间已结账。关闭期间不再执行结账检查，可按内控要求执行反结账。"
      />
      <el-alert
        v-else-if="checkResult"
        type="error"
        show-icon
        :closable="false"
        title="存在阻断项，请处理后再结账。"
      />
      <el-empty v-else description="请选择期间并执行期末检查" />

      <section class="action-section">
        <div class="action-section-title">
          <strong>阻断项</strong>
          <span>{{ blockCount }} 项</span>
        </div>
        <el-empty v-if="!checkResult || checkResult.blocks.length === 0" description="无阻断项" />
        <el-table v-else :data="checkResult.blocks" size="small" border>
          <el-table-column prop="message" label="说明" min-width="220" />
          <el-table-column prop="count" label="数量" width="80" align="right" />
        </el-table>
      </section>

      <section class="action-section">
        <div class="action-section-title">
          <strong>风险提示</strong>
          <span>{{ warningCount }} 项</span>
        </div>
        <el-empty v-if="!checkResult || checkResult.warnings.length === 0" description="无风险提示" />
        <el-table v-else :data="checkResult.warnings" size="small" border>
          <el-table-column prop="message" label="说明" min-width="220" />
          <el-table-column prop="count" label="数量" width="80" align="right" />
        </el-table>
      </section>
    </aside>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Calendar, CircleCheck, Refresh, RefreshLeft } from '@element-plus/icons-vue'
import { periodApi, type PeriodCloseCheckResult } from '../../api/period'
import { useContextStore } from '../../stores/context'

const context = useContextStore()
const selectedPeriod = ref(context.period)
const selectedYear = ref(context.year)
const currentCalendarYear = new Date().getFullYear()
const periodRows = ref<any[]>([])
const checkResult = ref<PeriodCloseCheckResult | null>(null)

const yearOptions = computed(() =>
  Array.from(new Set([context.year, selectedYear.value])).sort((a, b) => b - a)
)
const selectedPeriodRow = computed(() => periodRows.value.find((row) => row.period === selectedPeriod.value))
const currentStatus = computed(() => periodRows.value.find((row) => row.period === selectedPeriod.value)?.status || '')
const isSelectedPeriodClosed = computed(() => currentStatus.value === 'CLOSED')
const canYearEnd = computed(() => selectedPeriod.value.endsWith('-12') && currentStatus.value === 'CLOSED')
const canPreopenNextYear = computed(() => selectedYear.value + 1 <= currentCalendarYear)
const preopenYearDisabledReason = computed(() => canPreopenNextYear.value ? '' : '预开年度不能大于当前年份')
const closedPeriodCount = computed(() => periodRows.value.filter((row) => row.status === 'CLOSED').length)
const firstOpenPeriod = computed(() => periodRows.value.find((row) => row.status === 'OPEN')?.period || '')
const blockCount = computed(() => checkResult.value?.blocks.length || 0)
const warningCount = computed(() => checkResult.value?.warnings.length || 0)

const statusText = (status: string) =>
  ({
    OPEN: '开放',
    CLOSED: '已结账'
  }[status] || status || '-')

const statusTagType = (status: string) => (status === 'CLOSED' ? 'info' : 'success')

const checkStatusText = (row: any) => {
  if (row.status === 'CLOSED') {
    return '已结账'
  }
  if (!checkResult.value) {
    return '待检查'
  }
  if (row.period !== selectedPeriod.value) {
    return '待检查'
  }
  return checkResult.value.can_close === 1 ? '可结账' : '有阻断'
}

const checkStateClass = (row: any) => {
  if (row.status === 'CLOSED') {
    return 'is-muted'
  }
  if (row.period !== selectedPeriod.value || !checkResult.value) {
    return 'is-pending'
  }
  return checkResult.value.can_close === 1 ? 'is-pass' : 'is-blocked'
}

const riskStatusText = (row: any) => {
  if (row.period !== selectedPeriod.value || !checkResult.value) {
    return '-'
  }
  return warningCount.value > 0 ? `${warningCount.value} 项` : '无'
}

const periodRowClassName = ({ row }: { row: any }) => (row.period === selectedPeriod.value ? 'is-selected-period' : '')

const loadPeriods = async () => {
  periodRows.value = await periodApi.list()
}

const switchPeriod = (period: string) => {
  selectedPeriod.value = period
  context.period = period
  context.year = Number(period.slice(0, 4))
  selectedYear.value = context.year
}

const runCheck = async () => {
  if (isSelectedPeriodClosed.value) {
    checkResult.value = null
    await loadPeriods()
    return
  }
  checkResult.value = await periodApi.closeCheck(selectedPeriod.value)
  await loadPeriods()
}

const selectPeriod = async (period: string) => {
  switchPeriod(period)
  await runCheck()
}

const selectPeriodFromRow = async (row: any) => {
  await selectPeriod(row.period)
}

const changeYear = async () => {
  const nextPeriod = `${selectedYear.value}-01`
  switchPeriod(nextPeriod)
  await loadPeriods()
  await runCheck()
}

const closePeriod = async () => {
  if (!checkResult.value || checkResult.value.can_close !== 1) {
    ElMessage.warning('请先完成期末检查并处理阻断项')
    return
  }
  await ElMessageBox.confirm(`确认关闭 ${selectedPeriod.value} 会计期间？`, '期末结账', {
    type: 'warning',
    confirmButtonText: '结账',
    cancelButtonText: '取消'
  })
  const closedPeriod = selectedPeriod.value
  const result = await periodApi.close(closedPeriod)
  ElMessage.success(`${result.period} 已结账`)
  if (result.next_period && !closedPeriod.endsWith('-12')) {
    switchPeriod(result.next_period)
    await runCheck()
    return
  }
  await loadPeriods()
  checkResult.value = null
}

const reopenPeriod = async () => {
  await ElMessageBox.confirm(`确认反结账 ${selectedPeriod.value}？`, '反结账', {
    type: 'warning',
    confirmButtonText: '反结账',
    cancelButtonText: '取消'
  })
  await periodApi.reopen(selectedPeriod.value)
  ElMessage.success(`${selectedPeriod.value} 已恢复开放`)
  await loadPeriods()
  await runCheck()
}

const preopenNextYear = async () => {
  const sourceYear = selectedYear.value
  const targetYear = sourceYear + 1
  if (!canPreopenNextYear.value) {
    ElMessage.warning('预开年度不能大于当前年份')
    return
  }
  await ElMessageBox.confirm(
    `确认在当前账套下预开 ${targetYear} 年度？该操作只生成 ${targetYear} 年度会计期间，不生成期初余额。`,
    '预开下一年度',
    {
      type: 'warning',
      confirmButtonText: '预开',
      cancelButtonText: '取消'
    }
  )
  const result = await periodApi.preopenYear(sourceYear)
  ElMessage.success(`已预开 ${result.target_year} 年度，新增 ${result.created_periods} 个期间，不生成期初余额`)
  switchPeriod(result.first_period)
  await loadPeriods()
  await runCheck()
}

const yearEnd = async () => {
  await ElMessageBox.confirm(`确认以 ${selectedPeriod.value} 进行年终结转，并生成下一年度期间和期初余额？`, '年终结转生成下一年度', {
    type: 'warning',
    confirmButtonText: '生成',
    cancelButtonText: '取消'
  })
  const result = await periodApi.yearEnd(selectedPeriod.value)
  ElMessage.success(`已按${result.carry_label || '年终结转'}生成 ${result.opening_period} 期初余额`)
  switchPeriod(result.opening_period)
  await loadPeriods()
  await runCheck()
}

onMounted(async () => {
  await loadPeriods()
  await runCheck()
})
</script>

<style scoped>
.period-summary-strip {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 14px;
}

.period-summary-item {
  min-height: 70px;
  padding: 12px 14px;
  border: 1px solid var(--border-soft);
  border-radius: 8px;
  background: #fff;
}

.period-summary-item span {
  display: block;
  margin-bottom: 8px;
  color: var(--text-secondary);
  font-size: 12px;
}

.period-summary-item strong {
  color: var(--text-main);
  font-size: 22px;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}

.period-summary-item.success strong {
  color: var(--brand-success);
}

.period-summary-item.warning strong {
  color: var(--brand-warning);
}

.period-summary-item.danger strong {
  color: var(--brand-danger);
}

.period-close-workbench {
  display: grid;
  grid-template-columns: minmax(640px, 1fr) 420px;
  gap: 14px;
  align-items: start;
}

.period-ledger-panel,
.period-action-panel {
  background: #fff;
  border: 1px solid var(--border-soft);
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
}

.ledger-panel-header,
.action-panel-header {
  min-height: 58px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 16px;
  border-bottom: 1px solid var(--border-soft);
  background: #fbfdff;
}

.ledger-panel-header strong,
.action-panel-header strong {
  display: block;
  font-size: 15px;
  font-weight: 700;
  color: var(--text-main);
}

.ledger-panel-header span,
.action-panel-header span {
  display: block;
  margin-top: 4px;
  color: var(--text-secondary);
  font-size: 12px;
}

.period-ledger-panel :deep(.is-selected-period td.el-table__cell) {
  background: #eff6ff !important;
}

.period-check-state {
  font-weight: 700;
}

.period-check-state.is-pass {
  color: var(--brand-success);
}

.period-check-state.is-blocked {
  color: var(--brand-danger);
}

.period-check-state.is-pending,
.period-check-state.is-muted,
.period-risk-state {
  color: var(--text-secondary);
}

.period-action-panel {
  padding-bottom: 14px;
}

.action-buttons {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  padding: 14px 16px;
}

.action-buttons .primary-action {
  grid-column: 1 / -1;
}

.action-buttons .preopen-year-action {
  margin-left: 0;
}

.action-buttons .year-end-action {
  grid-column: 1 / -1;
  min-height: 46px;
  margin-left: 0;
  font-size: 15px;
  font-weight: 700;
  letter-spacing: 0;
}

.period-action-panel :deep(.el-alert) {
  margin: 0 16px 14px;
}

.action-section {
  padding: 0 16px 14px;
}

.action-section + .action-section {
  padding-top: 2px;
}

.action-section-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}

.action-section-title strong {
  color: var(--text-main);
  font-size: 14px;
}

.action-section-title span {
  color: var(--text-secondary);
  font-size: 12px;
}

@media (max-width: 900px) {
  .period-summary-strip,
  .period-close-workbench {
    grid-template-columns: 1fr;
  }

  .period-close-workbench {
    gap: 12px;
  }
}
</style>
