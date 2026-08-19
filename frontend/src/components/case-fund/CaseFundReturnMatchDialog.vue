<template>
  <el-dialog v-model="visible" title="退汇匹配详情" width="1180px" destroy-on-close @open="load">
    <el-alert v-if="entityStatus === 'INELIGIBLE'" type="info" :closable="false" show-icon>
      <template #title>该不明款缺少缴款人或付款账号，不参与退汇匹配。</template>
    </el-alert>
    <el-alert v-else-if="!loading && items.length === 0" type="info" :closable="false" show-icon>
      <template #title>当前没有退汇匹配记录。</template>
    </el-alert>

    <el-table v-else :data="items" border v-loading="loading" class="return-match-table">
      <el-table-column label="退汇状态" width="120" align="center" fixed="left">
        <template #default="{ row }">
          <el-tag :type="returnMatchStatusType(row.match_status)" effect="light">
            {{ returnMatchStatusLabel(row.match_status) }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="transaction_date" label="到账日期" width="112" />
      <el-table-column prop="payer_name" label="不明款缴款人" min-width="140" show-overflow-tooltip />
      <el-table-column prop="unknown_amount" label="来账金额" width="120" align="right">
        <template #default="{ row }">{{ money(row.unknown_amount) }}</template>
      </el-table-column>
      <el-table-column prop="payer_account_no" label="付款账号" min-width="190" show-overflow-tooltip />
      <el-table-column prop="refund_date" label="出账日期" width="112" />
      <el-table-column prop="actual_payee_name" label="实际收款人" min-width="140" show-overflow-tooltip />
      <el-table-column prop="refund_amount" label="出账金额" width="120" align="right">
        <template #default="{ row }">{{ money(row.refund_amount) }}</template>
      </el-table-column>
      <el-table-column prop="payee_bank_account_no" label="收款账号" min-width="190" show-overflow-tooltip />
      <el-table-column prop="case_no" label="案号" min-width="190" show-overflow-tooltip />
      <el-table-column prop="out_order_no" label="出账单号" min-width="150" show-overflow-tooltip />
      <el-table-column label="操作" width="250" fixed="right">
        <template #default="{ row }">
          <el-button v-if="unknownPaymentId" link type="primary" @click="openRefund(row.refund_id)">查看出账</el-button>
          <el-button v-else link type="primary" @click="openUnknownPayment(row.unknown_payment_id)">查看不明款</el-button>
          <template v-if="canDecide">
            <el-button
              v-if="row.match_status !== 'CONFIRMED'"
              link
              type="success"
              :loading="decidingMatchId === row.match_id"
              @click="confirmMatch(row)"
            >确认退汇</el-button>
            <el-button
              v-if="row.match_status === 'CONFIRMED'"
              link
              type="warning"
              :loading="decidingMatchId === row.match_id"
              @click="cancelMatch(row)"
            >取消确认</el-button>
            <el-button
              v-else-if="row.match_status !== 'REJECTED'"
              link
              type="danger"
              :loading="decidingMatchId === row.match_id"
              @click="rejectMatch(row)"
            >排除</el-button>
          </template>
        </template>
      </el-table-column>
    </el-table>

    <template #footer>
      <el-button @click="visible = false">关闭</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { caseFundApi } from '../../api/caseFund'
import { useContextStore } from '../../stores/context'
import type { CaseFundReturnMatchItem, CaseFundReturnMatchStatus } from '../../types/api'

const props = withDefaults(defineProps<{
  modelValue: boolean
  unknownPaymentId?: string
  refundId?: string
}>(), {
  unknownPaymentId: '',
  refundId: ''
})

const emit = defineEmits<{
  (event: 'update:modelValue', value: boolean): void
  (event: 'changed'): void
}>()

const context = useContextStore()
const router = useRouter()
const items = ref<CaseFundReturnMatchItem[]>([])
const entityStatus = ref<CaseFundReturnMatchStatus | string>('UNMATCHED')
const loading = ref(false)
const decidingMatchId = ref('')
const visible = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value)
})
const canDecide = computed(() => context.hasPermission('case_fund:return_match'))

const load = async () => {
  if (!visible.value || (!props.unknownPaymentId && !props.refundId)) return
  loading.value = true
  try {
    const result = await caseFundApi.unknownRefundMatchCandidates({
      unknown_payment_id: props.unknownPaymentId || undefined,
      refund_id: props.refundId || undefined
    })
    items.value = result.items || []
    entityStatus.value = result.status || 'UNMATCHED'
  } finally {
    loading.value = false
  }
}

const confirmMatch = async (row: CaseFundReturnMatchItem) => {
  try {
    await ElMessageBox.confirm(
      '确认该不明款是这笔出账的退汇来账吗？确认后双方都会标记为“已确认退汇”。',
      '确认退汇关联',
      { type: 'warning', confirmButtonText: '确认退汇', cancelButtonText: '取消' }
    )
  } catch {
    return
  }
  await runDecision(row.match_id, async () => caseFundApi.confirmUnknownRefundMatch(row.match_id), '退汇关联已确认')
}

const rejectMatch = async (row: CaseFundReturnMatchItem) => {
  let remark = ''
  try {
    const result = await ElMessageBox.prompt(
      '排除后，本次及后续扫描不会再把这两笔数据作为候选。可填写排除原因。',
      '排除退汇候选',
      { type: 'warning', confirmButtonText: '确认排除', cancelButtonText: '取消', inputPlaceholder: '排除原因（可选）' }
    )
    remark = String(result.value || '').trim()
  } catch {
    return
  }
  await runDecision(row.match_id, async () => caseFundApi.rejectUnknownRefundMatch(row.match_id, remark), '该候选已排除')
}

const cancelMatch = async (row: CaseFundReturnMatchItem) => {
  try {
    await ElMessageBox.confirm(
      '取消确认后，系统会重新按严格规则计算本月候选。是否继续？',
      '取消退汇确认',
      { type: 'warning', confirmButtonText: '取消确认', cancelButtonText: '返回' }
    )
  } catch {
    return
  }
  await runDecision(row.match_id, async () => caseFundApi.cancelUnknownRefundMatch(row.match_id), '已取消退汇确认')
}

const runDecision = async (matchId: string, action: () => Promise<unknown>, successMessage: string) => {
  decidingMatchId.value = matchId
  try {
    await action()
    ElMessage.success(successMessage)
    emit('changed')
    await load()
  } finally {
    decidingMatchId.value = ''
  }
}

const openRefund = async (refundId: string) => {
  visible.value = false
  await router.push({ path: '/case-fund/refunds', query: { refund_id: refundId } })
}

const openUnknownPayment = async (unknownPaymentId: string) => {
  visible.value = false
  await router.push({ path: '/case-fund/unknown-payments', query: { unknown_payment_id: unknownPaymentId } })
}

const money = (value: string | number | undefined) => {
  const amount = Number(value || 0)
  return amount.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const returnMatchStatusLabel = (status: CaseFundReturnMatchStatus | string) => {
  const map: Record<string, string> = {
    INELIGIBLE: '不参与匹配',
    UNMATCHED: '未匹配',
    AUTO_CANDIDATE: '疑似退汇',
    AMBIGUOUS: '匹配歧义',
    CONFIRMED: '已确认退汇',
    REJECTED: '已排除'
  }
  return map[status] || status || '未匹配'
}

const returnMatchStatusType = (status: CaseFundReturnMatchStatus | string) => {
  const map: Record<string, 'success' | 'warning' | 'danger' | 'info'> = {
    CONFIRMED: 'success',
    AUTO_CANDIDATE: 'warning',
    AMBIGUOUS: 'danger',
    REJECTED: 'info',
    INELIGIBLE: 'info',
    UNMATCHED: 'info'
  }
  return map[status] || 'info'
}

watch(
  () => [props.modelValue, props.unknownPaymentId, props.refundId],
  ([open]) => {
    if (open) void load()
  }
)
</script>

<style scoped>
.return-match-table {
  margin-top: 12px;
}
</style>
