<template>
  <div class="fund-isolation-banner">
    <el-icon><Lock /></el-icon>
    <div>
      <strong>账套数据隔离</strong>
      <span>当前仅展示「{{ context.accountSetName }}」资金数据，所有指标按当前会计期间和当前账套取数。</span>
    </div>
  </div>

  <div class="metric-grid">
    <div v-for="metric in dashboardMetrics" :key="metric.key" class="metric">
      <div class="metric-head">
        <span>{{ metric.label }}</span>
        <span :class="['stat-icon', metric.tone]"><el-icon><component :is="metric.icon" /></el-icon></span>
      </div>
      <strong class="amount">{{ metric.value }}</strong>
      <small>{{ metric.desc }}</small>
    </div>
  </div>

  <div class="fund-dashboard-grid dashboard-work-grid">
    <section class="panel dashboard-entry-panel">
      <div class="panel-header">
        <strong>
          <el-icon><Operation /></el-icon>
          工作入口
        </strong>
        <span class="muted">按当前账套类型</span>
      </div>
      <div class="panel-body">
        <div class="dashboard-section" v-for="section in operationSections" :key="section.title">
          <div class="dashboard-section-title">
            <strong>{{ section.title }}</strong>
            <span>{{ section.desc }}</span>
          </div>
          <div class="fund-entry-grid">
            <button v-for="entry in section.entries" :key="entry.label" type="button" class="fund-entry-button" @click="$router.push(entry.path)">
              <el-icon><component :is="entry.icon" /></el-icon>
              <span>{{ entry.label }}</span>
              <small>{{ entry.desc }}</small>
            </button>
          </div>
        </div>
      </div>
    </section>

    <section class="panel dashboard-overview-panel">
      <div class="panel-header">
        <strong>
          <el-icon><DataAnalysis /></el-icon>
          账套运行概览
        </strong>
        <span class="muted">科目余额表</span>
      </div>
      <div class="panel-body">
        <div class="dashboard-compact-status-list">
          <div v-for="item in overviewItems" :key="item.label" class="dashboard-compact-status-row">
            <span>{{ item.label }}</span>
            <strong :class="item.className">{{ item.value }}</strong>
          </div>
        </div>
      </div>
    </section>
  </div>

  <div class="fund-dashboard-grid dashboard-bottom-grid">
    <section class="panel">
      <div class="panel-header">
        <strong>
          <el-icon><DocumentChecked /></el-icon>
          账务核对
        </strong>
        <span :class="['dashboard-balance-tag', isBalanced ? 'is-ok' : 'is-warning']">{{ isBalanced ? '借贷平衡' : '需核对' }}</span>
      </div>
      <div class="panel-body">
        <div class="dashboard-check-grid">
          <div class="dashboard-check-item">
            <span>本期借贷差额</span>
            <strong :class="isBalanced ? 'text-success' : 'text-danger'">{{ money(balanceDifference) }}</strong>
          </div>
          <div class="dashboard-check-item">
            <span>本年累计借贷差额</span>
            <strong :class="isYearBalanced ? 'text-success' : 'text-danger'">{{ money(yearBalanceDifference) }}</strong>
          </div>
          <div class="dashboard-check-item" v-if="context.bizType === 'CASE_FUND'">
            <span>未清收据</span>
            <strong :class="unsettledReceiptCount > 0 ? 'amount-credit' : 'text-success'">{{ unsettledReceiptCount }} 张</strong>
          </div>
          <div class="dashboard-check-item" v-if="context.bizType === 'CASE_FUND'">
            <span>未清金额</span>
            <strong :class="unsettledAmount > 0 ? 'amount-credit' : 'text-success'">{{ money(unsettledAmount) }}</strong>
          </div>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-header">
        <strong>
          <el-icon><Link /></el-icon>
          数据来源
        </strong>
        <span class="muted">可下钻核验</span>
      </div>
      <div class="panel-body">
        <div class="dashboard-source-grid">
          <button v-for="item in sourceItems" :key="item.label" type="button" class="dashboard-source-button" @click="$router.push(item.path)">
            <span>{{ item.label }}</span>
            <small>{{ item.desc }}</small>
          </button>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  Coin,
  CreditCard,
  DataAnalysis,
  DocumentChecked,
  Link,
  Lock,
  Money,
  Notebook,
  Operation,
  Tickets,
  Wallet
} from '@element-plus/icons-vue'
import { booksApi } from '../../api/books'
import { useContextStore } from '../../stores/context'
import type { AuxBalanceRow } from '../../types/api'

const context = useContextStore()
const balances = ref<any[]>([])
const auxBalanceRows = ref<AuxBalanceRow[]>([])

const bizTypeName = computed(() => {
  const map: Record<string, string> = {
    CASE_FUND: '案款',
    LITIGATION_FEE: '诉讼费',
    CANTEEN: '食堂',
    UNION: '工会'
  }
  return map[context.bizType] || '专项资金'
})

const money = (value: number) => value.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const summaryRows = computed(() => {
  const leafRows = balances.value.filter((row) => Number(row.leaf_flag) === 1)
  return leafRows.length > 0 ? leafRows : balances.value
})
const sumAmount = (field: string) => summaryRows.value.reduce((sum, row) => sum + (Number(row[field]) || 0), 0)

const currentDebit = computed(() => sumAmount('debit_amount'))
const currentCredit = computed(() => sumAmount('credit_amount'))
const yearDebit = computed(() => sumAmount('year_debit_amount'))
const yearCredit = computed(() => sumAmount('year_credit_amount'))
const endingDebit = computed(() => sumAmount('ending_debit_amount'))
const endingCredit = computed(() => sumAmount('ending_credit_amount'))
const balanceDifference = computed(() => Math.abs(currentDebit.value - currentCredit.value))
const yearBalanceDifference = computed(() => Math.abs(yearDebit.value - yearCredit.value))
const isBalanced = computed(() => balanceDifference.value < 0.01)
const isYearBalanced = computed(() => yearBalanceDifference.value < 0.01)

const auxReceiptRows = computed(() => auxBalanceRows.value.flatMap((row) => row.children || []))
const unsettledReceiptCount = computed(() => auxReceiptRows.value.filter((row) => row.monitor_flag).length)
const unsettledAmount = computed(() =>
  auxReceiptRows.value
    .filter((row) => row.monitor_flag)
    .reduce((sum, row) => sum + Math.abs(Number(row.ending_balance_amount) || 0), 0)
)

const dashboardMetrics = computed(() => {
  const metrics = [
    { key: 'debit', label: '本期借方发生额', value: money(currentDebit.value), desc: '科目余额表本期借方', icon: Coin, tone: 'green' },
    { key: 'credit', label: '本期贷方发生额', value: money(currentCredit.value), desc: '科目余额表本期贷方', icon: CreditCard, tone: 'rose' },
    { key: 'endingDebit', label: '期末借方余额', value: money(endingDebit.value), desc: '末级科目期末借方合计', icon: Wallet, tone: 'blue' },
    { key: 'endingCredit', label: '期末贷方余额', value: money(endingCredit.value), desc: '末级科目期末贷方合计', icon: Tickets, tone: 'amber' }
  ]
  if (context.bizType === 'CASE_FUND') {
    metrics[3] = {
      key: 'unsettled',
      label: '未清案款收据',
      value: `${unsettledReceiptCount.value} 张`,
      desc: `辅助余额未清金额 ${money(unsettledAmount.value)}`,
      icon: Tickets,
      tone: unsettledReceiptCount.value > 0 ? 'amber' : 'green'
    }
  }
  return metrics
})

const overviewItems = computed(() => [
  { label: '本期借方', value: money(currentDebit.value), desc: '已审核/已打印凭证发生额', className: 'amount-debit' },
  { label: '本期贷方', value: money(currentCredit.value), desc: '已审核/已打印凭证发生额', className: 'amount-credit' },
  { label: '本年累计借方', value: money(yearDebit.value), desc: '自启用/年初至当前期间', className: 'amount-debit' },
  { label: '本年累计贷方', value: money(yearCredit.value), desc: '自启用/年初至当前期间', className: 'amount-credit' }
])

const businessEntries = computed(() => {
  if (context.bizType === 'CASE_FUND') {
    return [
      { label: '案款缴费登记', desc: '登记到账和导入结果', path: '/case-fund/payments', icon: Money },
      { label: '案款退付登记', desc: '登记退付并生成凭证', path: '/case-fund/refunds', icon: CreditCard },
      { label: '银行对账单', desc: '导入流水并自动对账', path: '/case-fund/bank-statements', icon: Tickets },
      { label: '辅助核算余额表', desc: '按案号和收据号查未清', path: '/books/aux-balance', icon: Notebook }
    ]
  }
  return [
    { label: `${bizTypeName.value}凭证录入`, desc: '按当前账套登记业务分录', path: '/vouchers/new', icon: Money },
    { label: `${bizTypeName.value}凭证中心`, desc: '审核、查看和追溯凭证', path: '/vouchers', icon: Tickets },
    { label: '明细账', desc: '按科目查看期间流水', path: '/books/detail-ledger', icon: Notebook },
    { label: '科目余额表', desc: '核对期初、本期和期末', path: '/books/subject-balance', icon: DataAnalysis }
  ]
})

const operationSections = computed(() => [
  {
    title: '业务处理',
    desc: `${bizTypeName.value}日常入口`,
    entries: businessEntries.value
  },
  {
    title: '账务核对',
    desc: '凭证与账簿核验',
    entries: [
      { label: '凭证中心', desc: '查看当前账套凭证状态', path: '/vouchers', icon: Tickets },
      { label: '科目余额表', desc: '核对标准余额口径', path: '/books/subject-balance', icon: DataAnalysis },
      { label: '科目汇总表', desc: '核对期间借贷汇总', path: '/books/subject-summary', icon: Notebook },
      { label: '审计日志', desc: '追溯关键操作记录', path: '/system/audit-logs', icon: DocumentChecked }
    ]
  }
])

const sourceItems = computed(() => {
  const items = [
    { label: '科目余额表', desc: '本期发生额、累计发生额、期末余额', path: '/books/subject-balance' },
    { label: '科目汇总表', desc: '按日期和科目级次汇总借贷发生额', path: '/books/subject-summary' },
    { label: '明细账', desc: '凭证分录流水和辅助核算摘要', path: '/books/detail-ledger' }
  ]
  if (context.bizType === 'CASE_FUND') {
    items.push({ label: '辅助核算余额表', desc: '案号、收据号维度未清余额', path: '/books/aux-balance' })
  }
  return items
})

const load = async () => {
  balances.value = await booksApi.subjectBalance(context.period)
  if (context.bizType === 'CASE_FUND') {
    const result = await booksApi.auxBalance({ period: context.period })
    auxBalanceRows.value = result.items || []
  } else {
    auxBalanceRows.value = []
  }
}

onMounted(load)
</script>
