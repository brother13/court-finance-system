<template>
  <div class="page-header">
    <div>
      <h1>期初</h1>
      <p>维护启用期间科目期初余额，保证借贷试算平衡；挂辅助核算的科目需后续录入辅助期初明细。</p>
    </div>
    <div class="page-actions">
      <el-select v-model="period" style="width: 130px" @change="load">
        <el-option v-for="item in periodOptions" :key="item" :label="item" :value="item" />
      </el-select>
      <el-button :icon="Refresh" @click="load">刷新</el-button>
      <el-button v-permission="['opening:save', 'base:edit']" type="primary" :icon="Check" :loading="saving" @click="save">保存期初</el-button>
    </div>
  </div>

  <div class="opening-summary">
    <div class="opening-summary-card">
      <span>借方合计</span>
      <strong>{{ money(totalDebit) }}</strong>
    </div>
    <div class="opening-summary-card">
      <span>贷方合计</span>
      <strong>{{ money(totalCredit) }}</strong>
    </div>
    <div class="opening-summary-card" :class="{ danger: difference !== 0 }">
      <span>试算差额</span>
      <strong>{{ money(difference) }}</strong>
    </div>
    <el-alert
      class="opening-rule-alert"
      :type="difference === 0 ? 'success' : 'warning'"
      :closable="false"
      show-icon
      :title="difference === 0 ? '当前期初试算平衡' : '借贷不平衡，保存前请核对期初余额'"
    />
  </div>

  <div class="panel management-panel">
    <div class="panel-header">
      <strong>科目期初余额</strong>
      <span class="panel-hint">非末级科目通常由下级汇总，实际录入应以末级科目为准。</span>
    </div>
    <div class="panel-body compact">
      <el-table :data="rows" border height="calc(100vh - 310px)" row-key="subject_code">
        <el-table-column prop="subject_code" label="科目编码" width="130" fixed="left" />
        <el-table-column prop="subject_name" label="科目名称" min-width="180" fixed="left" />
        <el-table-column prop="subject_type" label="类别" width="110">
          <template #default="{ row }">{{ subjectTypeLabel(row.subject_type) }}</template>
        </el-table-column>
        <el-table-column prop="direction" label="余额方向" width="90">
          <template #default="{ row }">{{ row.direction === 'DEBIT' ? '借方' : '贷方' }}</template>
        </el-table-column>
        <el-table-column label="末级" width="80">
          <template #default="{ row }">
            <el-tag size="small" :type="Number(row.leaf_flag) === 1 ? 'success' : 'info'">
              {{ Number(row.leaf_flag) === 1 ? '是' : '否' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="借方期初" width="180">
          <template #default="{ row }">
            <el-input-number
              v-model="row.debit_amount"
              :precision="2"
              :min="0"
              :disabled="Number(row.leaf_flag) !== 1"
              controls-position="right"
              style="width: 150px"
              @change="clearOpposite(row, 'DEBIT')"
            />
          </template>
        </el-table-column>
        <el-table-column label="贷方期初" width="180">
          <template #default="{ row }">
            <el-input-number
              v-model="row.credit_amount"
              :precision="2"
              :min="0"
              :disabled="Number(row.leaf_flag) !== 1"
              controls-position="right"
              style="width: 150px"
              @change="clearOpposite(row, 'CREDIT')"
            />
          </template>
        </el-table-column>
        <el-table-column label="辅助期初" width="150">
          <template #default="{ row }">
            <el-button
              v-if="Number(row.aux_config_count) > 0"
              link
              type="primary"
              @click="openAuxOpening(row)"
            >
              录入明细
            </el-button>
            <el-tag v-else size="small" type="info">无辅助</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="备注" min-width="220">
          <template #default="{ row }">
            <el-input v-model="row.remark" placeholder="备注" clearable />
          </template>
        </el-table-column>
      </el-table>
    </div>
  </div>

  <el-drawer v-model="auxDrawerVisible" title="辅助期初明细" size="720px">
    <template v-if="currentSubject">
      <div class="aux-config-head">
        <strong>{{ currentSubject.subject_code }} {{ currentSubject.subject_name }}</strong>
        <p>辅助期初明细合计必须与该科目期初余额保持一致，后续用于客户往来账、供应商往来账、项目明细账等辅助账。</p>
      </div>

      <div class="filter-row aux-editor-toolbar">
        <el-button v-permission="['opening:save', 'base:edit']" type="primary" :icon="Plus" @click="addAuxRow">新增明细</el-button>
        <el-button :icon="Refresh" :loading="auxLoading" @click="loadAuxOpening">刷新</el-button>
        <span class="panel-hint">
          共 {{ auxRows.length }} 条 · 本页 {{ pagedAuxRows.length }} 条 · 借方合计 {{ money(auxDebitTotal) }} · 贷方合计 {{ money(auxCreditTotal) }}
        </span>
      </div>

      <el-table
        v-loading="auxLoading"
        :data="pagedAuxRows"
        border
        height="calc(100vh - 340px)"
        row-key="__row_key"
      >
        <el-table-column
          v-for="config in auxConfigs"
          :key="config.aux_type_code"
          :label="auxTypeLabel(config.aux_type_code)"
          min-width="160"
        >
          <template #default="{ row }">
            <el-select
              v-if="archives[config.aux_type_code]?.length"
              v-model="row.aux_values[config.aux_type_code]"
              filterable
              clearable
              placeholder="选择档案"
            >
              <el-option
                v-for="archive in archives[config.aux_type_code]"
                :key="archive.archive_code"
                :label="`${archive.archive_code} ${archive.archive_name}`"
                :value="archive.archive_code"
              />
            </el-select>
            <el-input v-else v-model="row.aux_values[config.aux_type_code]" placeholder="手工录入" />
          </template>
        </el-table-column>
        <el-table-column label="借方" width="150">
          <template #default="{ row }">
            <el-input-number v-model="row.debit_amount" :precision="2" :min="0" controls-position="right" style="width: 126px" />
          </template>
        </el-table-column>
        <el-table-column label="贷方" width="150">
          <template #default="{ row }">
            <el-input-number v-model="row.credit_amount" :precision="2" :min="0" controls-position="right" style="width: 126px" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="['opening:save', 'base:edit']" link type="danger" @click="removeAuxRow(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div v-if="auxRows.length > auxPageSize" class="pagination-bar">
        <el-pagination
          v-model:current-page="auxPage"
          v-model:page-size="auxPageSize"
          :page-sizes="[50, 100, 200]"
          layout="total, sizes, prev, pager, next, jumper"
          :total="auxRows.length"
          @size-change="auxPage = 1"
        />
      </div>
    </template>
    <template #footer>
      <el-button @click="auxDrawerVisible = false">取消</el-button>
      <el-button v-permission="['opening:save', 'base:edit']" type="primary" :loading="auxSaving" @click="saveAuxOpening">保存辅助期初</el-button>
    </template>
  </el-drawer>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Check, Plus, Refresh } from '@element-plus/icons-vue'
import { baseApi } from '../../api/base'
import { useContextStore } from '../../stores/context'

const context = useContextStore()
const period = ref(context.period)
const periodOptions = Array.from({ length: 12 }, (_, index) => `${context.year}-${String(index + 1).padStart(2, '0')}`)
const rows = ref<any[]>([])
const saving = ref(false)
const auxDrawerVisible = ref(false)
const auxLoading = ref(false)
const auxSaving = ref(false)
const currentSubject = ref<any>(null)
const auxConfigs = ref<any[]>([])
const archives = ref<Record<string, any[]>>({})
const auxRows = ref<any[]>([])
const auxPage = ref(1)
const auxPageSize = ref(50)
const subjectTypeMap: Record<string, string> = {
  ASSET: '资产',
  LIABILITY: '负债',
  COMMON: '共同',
  EQUITY: '所有者权益',
  COST: '成本',
  PROFIT_LOSS: '损益'
}

const totalDebit = computed(() => rows.value.reduce((sum, row) => sum + Number(row.debit_amount || 0), 0))
const totalCredit = computed(() => rows.value.reduce((sum, row) => sum + Number(row.credit_amount || 0), 0))
const difference = computed(() => Number((totalDebit.value - totalCredit.value).toFixed(2)))
const auxDebitTotal = computed(() => Number(auxRows.value.reduce((sum, row) => sum + Number(row.debit_amount || 0), 0).toFixed(2)))
const auxCreditTotal = computed(() => Number(auxRows.value.reduce((sum, row) => sum + Number(row.credit_amount || 0), 0).toFixed(2)))
const pagedAuxRows = computed(() => {
  const start = (auxPage.value - 1) * auxPageSize.value
  return auxRows.value.slice(start, start + auxPageSize.value)
})

const subjectTypeLabel = (type: string) => subjectTypeMap[type] || type
const auxTypeLabel = (code: string) => {
  const map: Record<string, string> = {
    customer: '客户',
    supplier: '供应商',
    department: '部门',
    employee: '职员',
    project: '项目',
    custom: '自定义',
    case_no: '案号',
    receipt_no: '收据号',
    party_name: '当事人'
  }
  return map[code] || code
}
const money = (value: number) => value.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const clearOpposite = (row: any, side: 'DEBIT' | 'CREDIT') => {
  if (side === 'DEBIT' && Number(row.debit_amount || 0) > 0) row.credit_amount = 0
  if (side === 'CREDIT' && Number(row.credit_amount || 0) > 0) row.debit_amount = 0
}

const load = async () => {
  rows.value = await baseApi.openingBalances(period.value)
}

const save = async () => {
  const invalid = rows.value.find((row) => Number(row.debit_amount || 0) > 0 && Number(row.credit_amount || 0) > 0)
  if (invalid) {
    ElMessage.warning(`${invalid.subject_code} 同一科目不能同时录入借方和贷方期初`)
    return
  }
  if (difference.value !== 0) {
    ElMessage.warning('期初借贷不平衡，请核对后再保存')
    return
  }
  saving.value = true
  try {
    await baseApi.saveOpeningBalances(period.value, rows.value)
    ElMessage.success('期初余额已保存')
  } finally {
    saving.value = false
  }
}

const loadAuxOpening = async () => {
  if (!currentSubject.value) return
  auxLoading.value = true
  try {
    const result = await baseApi.auxOpeningBalances(period.value, currentSubject.value.subject_code)
    auxConfigs.value = result.configs || []
    archives.value = result.archives || {}
    auxRows.value = (result.rows || []).map((row: any, index: number) => ({
      ...row,
      __row_key: row.balance_id || `loaded-${index}`,
      aux_values: row.aux_values || {},
      debit_amount: Number(row.debit_amount || 0),
      credit_amount: Number(row.credit_amount || 0)
    }))
    auxPage.value = 1
  } finally {
    auxLoading.value = false
  }
}

const openAuxOpening = async (row: any) => {
  currentSubject.value = row
  auxRows.value = []
  auxPage.value = 1
  auxDrawerVisible.value = true
  await loadAuxOpening()
}

const addAuxRow = () => {
  const auxValues: Record<string, string> = {}
  auxConfigs.value.forEach((config) => {
    auxValues[config.aux_type_code] = ''
  })
  auxRows.value.push({
    __row_key: `new-${Date.now()}-${Math.random().toString(16).slice(2)}`,
    aux_values: auxValues,
    debit_amount: 0,
    credit_amount: 0,
    remark: ''
  })
  auxPage.value = Math.max(1, Math.ceil(auxRows.value.length / auxPageSize.value))
}

const removeAuxRow = (row: any) => {
  const index = auxRows.value.findIndex((item) => item.__row_key === row.__row_key)
  if (index >= 0) {
    auxRows.value.splice(index, 1)
  }
  const maxPage = Math.max(1, Math.ceil(auxRows.value.length / auxPageSize.value))
  if (auxPage.value > maxPage) {
    auxPage.value = maxPage
  }
}

const saveAuxOpening = async () => {
  if (!currentSubject.value) return
  if (auxDebitTotal.value !== Number(currentSubject.value.debit_amount || 0) || auxCreditTotal.value !== Number(currentSubject.value.credit_amount || 0)) {
    ElMessage.warning('辅助期初明细合计必须等于该科目的期初余额')
    return
  }
  auxSaving.value = true
  try {
    await baseApi.saveAuxOpeningBalances(period.value, currentSubject.value.subject_code, auxRows.value)
    ElMessage.success('辅助期初已保存')
    auxDrawerVisible.value = false
  } finally {
    auxSaving.value = false
  }
}

onMounted(load)
</script>
