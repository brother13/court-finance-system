<template>
  <div class="page-header">
    <div>
      <h1>期末结账</h1>
      <p>按账套会计期间执行期末检查、月结关闭、反结账和年结生成下一年度。</p>
    </div>
    <div class="page-actions">
      <el-select v-model="selectedPeriod" style="width: 130px" @change="runCheck">
        <el-option v-for="period in periodOptions" :key="period" :label="period" :value="period" />
      </el-select>
      <el-button :icon="Refresh" @click="runCheck">期末检查</el-button>
      <el-button type="primary" :icon="CircleCheck" :disabled="!checkResult || checkResult.can_close !== 1" @click="closePeriod">
        结账
      </el-button>
      <el-button :icon="RefreshLeft" @click="reopenPeriod">反结账</el-button>
      <el-button type="success" plain :icon="Calendar" :disabled="!canYearEnd" @click="yearEnd">
        年结生成下一年度
      </el-button>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header">
      <strong>期末检查 · {{ selectedPeriod }}</strong>
      <span class="muted">{{ statusText(checkResult?.status || currentStatus) }}</span>
    </div>
    <div class="panel-body">
      <el-alert
        v-if="checkResult && checkResult.can_close === 1"
        type="success"
        show-icon
        :closable="false"
        title="强制检查已通过，可以执行结账。"
      />
      <el-alert
        v-else-if="checkResult"
        type="error"
        show-icon
        :closable="false"
        title="存在阻断项，请处理后再结账。"
      />
      <el-empty v-if="!checkResult" description="请选择期间并执行期末检查" />

      <div v-if="checkResult" class="period-check-grid">
        <section>
          <h3>阻断项</h3>
          <el-empty v-if="checkResult.blocks.length === 0" description="无阻断项" />
          <el-table v-else :data="checkResult.blocks" size="small" border>
            <el-table-column prop="message" label="说明" min-width="260" />
            <el-table-column prop="count" label="数量" width="90" align="right" />
          </el-table>
        </section>
        <section>
          <h3>风险提示</h3>
          <el-empty v-if="checkResult.warnings.length === 0" description="无风险提示" />
          <el-table v-else :data="checkResult.warnings" size="small" border>
            <el-table-column prop="message" label="说明" min-width="260" />
            <el-table-column prop="count" label="数量" width="90" align="right" />
          </el-table>
        </section>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-header">
      <strong>会计期间</strong>
      <span class="muted">关闭后的期间禁止凭证写操作</span>
    </div>
    <div class="panel-body compact">
      <el-table :data="periodRows" border>
        <el-table-column prop="period" label="期间" width="120" align="center" />
        <el-table-column prop="start_date" label="开始日期" width="140" align="center" />
        <el-table-column prop="end_date" label="结束日期" width="140" align="center" />
        <el-table-column prop="status" label="状态" width="120" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 'CLOSED' ? 'info' : 'success'" size="small">{{ statusText(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="180" />
      </el-table>
    </div>
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
const periodRows = ref<any[]>([])
const checkResult = ref<PeriodCloseCheckResult | null>(null)

const periodOptions = computed(() =>
  Array.from({ length: 12 }, (_, index) => `${context.year}-${String(index + 1).padStart(2, '0')}`)
)
const currentStatus = computed(() => periodRows.value.find((row) => row.period === selectedPeriod.value)?.status || '')
const canYearEnd = computed(() => selectedPeriod.value.endsWith('-12') && currentStatus.value === 'CLOSED')

const statusText = (status: string) =>
  ({
    OPEN: '开放',
    CLOSED: '已结账'
  }[status] || status || '-')

const loadPeriods = async () => {
  periodRows.value = await periodApi.list()
}

const runCheck = async () => {
  checkResult.value = await periodApi.closeCheck(selectedPeriod.value)
  await loadPeriods()
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
  const result = await periodApi.close(selectedPeriod.value)
  ElMessage.success(`${result.period} 已结账`)
  if (result.next_period && !selectedPeriod.value.endsWith('-12')) {
    context.period = result.next_period
    context.year = Number(result.next_period.slice(0, 4))
  }
  await loadPeriods()
  await runCheck()
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

const yearEnd = async () => {
  await ElMessageBox.confirm(`确认以 ${selectedPeriod.value} 生成下一年度期间和期初余额？`, '年结生成下一年度', {
    type: 'warning',
    confirmButtonText: '生成',
    cancelButtonText: '取消'
  })
  const result = await periodApi.yearEnd(selectedPeriod.value)
  ElMessage.success(`已生成 ${result.opening_period} 期初余额`)
  context.period = result.opening_period
  context.year = result.next_year
  selectedPeriod.value = result.opening_period
  await loadPeriods()
  await runCheck()
}

onMounted(async () => {
  await loadPeriods()
  await runCheck()
})
</script>

<style scoped>
.period-check-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
  margin-top: 16px;
}

.period-check-grid h3 {
  margin: 0 0 10px;
  font-size: 15px;
  font-weight: 700;
}

@media (max-width: 900px) {
  .period-check-grid {
    grid-template-columns: 1fr;
  }
}
</style>
