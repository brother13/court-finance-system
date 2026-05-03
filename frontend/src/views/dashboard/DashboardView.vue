<template>
  <div class="page-header">
    <div>
      <h1>{{ dashboard.title }}</h1>
      <p>{{ context.accountSetName }} · {{ context.period }} · {{ dashboard.subtitle }}</p>
    </div>
    <div class="page-actions">
      <el-button @click="$router.push('/select-account-set')">切换账套</el-button>
      <el-button :icon="Refresh" @click="load">刷新数据</el-button>
    </div>
  </div>

  <div class="fund-isolation-banner">
    <el-icon><Lock /></el-icon>
    <div>
      <strong>账套数据隔离</strong>
      <span>当前仅展示「{{ context.accountSetName }}」资金数据，不汇总、不混合展示其他账套。</span>
    </div>
  </div>

  <div class="metric-grid">
    <div v-for="metric in dashboard.metrics" :key="metric.key" class="metric">
      <div class="metric-head">
        <span>{{ metric.label }}</span>
        <span :class="['stat-icon', metric.tone]"><el-icon><component :is="metric.icon" /></el-icon></span>
      </div>
      <strong class="amount">{{ metric.value }}</strong>
      <small>{{ metric.desc }}</small>
    </div>
  </div>

  <div class="fund-dashboard-grid">
    <section class="panel">
      <div class="panel-header">
        <strong>
          <el-icon><TrendCharts /></el-icon>
          {{ dashboard.trendTitle }}
        </strong>
        <span class="muted">按当前账套期间口径</span>
      </div>
      <div class="panel-body">
        <div class="fund-trend-bars">
          <div v-for="item in dashboard.trend" :key="item.period" class="fund-trend-row">
            <span>{{ item.period }}</span>
            <div class="fund-trend-track">
              <i :style="{ width: item.rate + '%' }" />
            </div>
            <strong>{{ item.amount }}</strong>
          </div>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-header">
        <strong>
          <el-icon><Operation /></el-icon>
          {{ dashboard.entryTitle }}
        </strong>
        <span class="muted">仅限当前账套</span>
      </div>
      <div class="panel-body">
        <div class="fund-entry-grid">
          <button v-for="entry in dashboard.entries" :key="entry.label" type="button" class="fund-entry-button" @click="$router.push(entry.path)">
            <el-icon><component :is="entry.icon" /></el-icon>
            <span>{{ entry.label }}</span>
            <small>{{ entry.desc }}</small>
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
  Lock,
  Money,
  Operation,
  Refresh,
  Tickets,
  TrendCharts,
  Wallet
} from '@element-plus/icons-vue'
import { booksApi } from '../../api/books'
import { useContextStore } from '../../stores/context'

const context = useContextStore()
const balances = ref<any[]>([])

const money = (value: number) => value.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const amountTotal = computed(() => balances.value.reduce((sum, row) => sum + Math.abs(Number(row.balance_amount || 0)), 0))
const debitTotal = computed(() => balances.value.reduce((sum, row) => sum + Number(row.debit_amount || 0), 0))
const creditTotal = computed(() => balances.value.reduce((sum, row) => sum + Number(row.credit_amount || 0), 0))

const dashboardConfig = computed(() => {
  const configs: Record<string, any> = {
    CASE_FUND: {
      title: '案款资金看板',
      subtitle: '案款收支、暂存、待退资金专属看板',
      metrics: [
        { key: 'balance', label: '案款资金余额', value: money(amountTotal.value), desc: '当前案款账套余额', icon: Wallet, tone: 'blue' },
        { key: 'in', label: '本期案款收入', value: money(debitTotal.value), desc: '本期案款入账金额', icon: Coin, tone: 'green' },
        { key: 'out', label: '本期案款支出', value: money(creditTotal.value), desc: '本期案款退付金额', icon: CreditCard, tone: 'rose' },
        { key: 'pending', label: '待核对案款', value: money(Math.max(amountTotal.value - creditTotal.value, 0)), desc: '待核销或待退口径', icon: Tickets, tone: 'amber' }
      ],
      trendTitle: '案款资金趋势',
      entryTitle: '案款业务入口',
      entries: [
        { label: '案款收款登记', desc: '登记案款到账资金', path: '/vouchers/new', icon: Money },
        { label: '案款退付登记', desc: '登记案款退付资金', path: '/vouchers/new', icon: CreditCard },
        { label: '案款资金明细', desc: '查看案款专项资金流水', path: '/books/detail-ledger', icon: Tickets }
      ]
    },
    LITIGATION_FEE: {
      title: '诉讼费资金看板',
      subtitle: '诉讼费收取、退费、上缴专属看板',
      metrics: [
        { key: 'balance', label: '诉讼费余额', value: money(amountTotal.value), desc: '当前诉讼费账套余额', icon: Wallet, tone: 'blue' },
        { key: 'in', label: '本期诉讼费收入', value: money(debitTotal.value), desc: '诉讼费收取金额', icon: Coin, tone: 'green' },
        { key: 'refund', label: '本期诉讼费退费', value: money(creditTotal.value), desc: '诉讼费退还金额', icon: CreditCard, tone: 'rose' },
        { key: 'turnover', label: '待上缴诉讼费', value: money(Math.max(amountTotal.value - creditTotal.value, 0)), desc: '待上缴资金口径', icon: Tickets, tone: 'amber' }
      ],
      trendTitle: '诉讼费资金趋势',
      entryTitle: '诉讼费业务入口',
      entries: [
        { label: '诉讼费收取登记', desc: '登记诉讼费收取资金', path: '/vouchers/new', icon: Money },
        { label: '诉讼费退费登记', desc: '登记诉讼费退还资金', path: '/vouchers/new', icon: CreditCard },
        { label: '诉讼费资金明细', desc: '查看诉讼费资金流水', path: '/books/detail-ledger', icon: Tickets }
      ]
    },
    CANTEEN: {
      title: '食堂资金看板',
      subtitle: '食堂收入、支出、结余专属看板',
      metrics: [
        { key: 'balance', label: '食堂资金结余', value: money(amountTotal.value), desc: '当前食堂账余额', icon: Wallet, tone: 'blue' },
        { key: 'in', label: '本期食堂收入', value: money(debitTotal.value), desc: '食堂收入发生额', icon: Coin, tone: 'green' },
        { key: 'out', label: '本期食堂支出', value: money(creditTotal.value), desc: '食堂采购和支出', icon: CreditCard, tone: 'rose' },
        { key: 'available', label: '可用资金', value: money(Math.max(amountTotal.value, 0)), desc: '食堂可用资金口径', icon: Tickets, tone: 'amber' }
      ],
      trendTitle: '食堂资金趋势',
      entryTitle: '食堂业务入口',
      entries: [
        { label: '食堂收入登记', desc: '登记食堂收入资金', path: '/vouchers/new', icon: Money },
        { label: '食堂支出登记', desc: '登记食堂支出资金', path: '/vouchers/new', icon: CreditCard },
        { label: '食堂资金明细', desc: '查看食堂资金流水', path: '/books/detail-ledger', icon: Tickets }
      ]
    },
    UNION: {
      title: '工会经费看板',
      subtitle: '工会经费收入、支出、结余专属看板',
      metrics: [
        { key: 'balance', label: '工会经费结余', value: money(amountTotal.value), desc: '当前工会账余额', icon: Wallet, tone: 'blue' },
        { key: 'in', label: '本期经费收入', value: money(debitTotal.value), desc: '工会经费收入', icon: Coin, tone: 'green' },
        { key: 'out', label: '本期经费支出', value: money(creditTotal.value), desc: '工会经费支出', icon: CreditCard, tone: 'rose' },
        { key: 'available', label: '可用经费', value: money(Math.max(amountTotal.value, 0)), desc: '可用工会经费口径', icon: Tickets, tone: 'amber' }
      ],
      trendTitle: '工会经费趋势',
      entryTitle: '工会业务入口',
      entries: [
        { label: '经费收入登记', desc: '登记工会经费收入', path: '/vouchers/new', icon: Money },
        { label: '经费支出登记', desc: '登记工会经费支出', path: '/vouchers/new', icon: CreditCard },
        { label: '工会资金明细', desc: '查看工会经费流水', path: '/books/detail-ledger', icon: Tickets }
      ]
    }
  }
  return configs[context.bizType] || configs.CASE_FUND
})

const dashboard = computed(() => ({
  ...dashboardConfig.value,
  trend: ['01', '02', '03', '04', '05', '06'].map((month, index) => {
    const value = amountTotal.value === 0 ? 0 : amountTotal.value * (0.55 + index * 0.09)
    return {
      period: `${context.period.slice(0, 4)}-${month}`,
      amount: money(value),
      rate: amountTotal.value === 0 ? 8 + index * 6 : Math.min(100, 45 + index * 10)
    }
  })
}))

const load = async () => {
  balances.value = await booksApi.subjectBalance(context.period)
}

onMounted(load)
</script>
