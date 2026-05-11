<template>
  <main class="account-select-page">
    <section class="account-select-header">
      <div>
        <span class="login-kicker">账套选择 · 数据隔离</span>
        <h1>请选择本次进入的账套</h1>
        <p>每个款项类型对应独立账套。进入后系统只展示当前账套的数据，严禁跨账套汇总、混合展示。</p>
      </div>
      <div class="account-select-side">
        <el-button v-if="context.hasAccountSet" :icon="ArrowLeft" @click="returnToPreviousAccountSet">返回</el-button>
        <div class="account-select-user">
          <el-avatar :size="40">{{ context.displayName.slice(0, 1) }}</el-avatar>
          <div>
            <strong>{{ context.displayName }}</strong>
            <span>{{ context.unitName }}</span>
          </div>
        </div>
      </div>
    </section>

    <section class="account-set-grid">
      <div
        v-for="item in accountSets"
        :key="item.account_set_id"
        class="account-set-card-wrapper"
      >
        <button
          type="button"
          class="account-set-card"
          :class="{ active: selectedAccountSet?.account_set_id === item.account_set_id }"
          @click="selectCard(item)"
        >
          <span class="account-set-type">{{ bizTypeMeta(item.biz_type).label }}</span>
          <h2>{{ item.set_name }}</h2>
          <p>{{ bizTypeMeta(item.biz_type).desc }}</p>
        </button>
        <div
          v-if="selectedAccountSet?.account_set_id === item.account_set_id"
          class="year-select-panel"
        >
          <div class="year-select-content">
            <span class="year-select-label">选择年度</span>
            <el-radio-group v-model="selectedYear" size="large">
              <el-radio-button
                v-for="year in item.available_years"
                :key="year"
                :label="year"
              >
                {{ year }}年
              </el-radio-button>
            </el-radio-group>
            <div class="year-select-actions">
              <el-button @click="cancelSelect">取消</el-button>
              <el-button type="primary" @click="confirmEnter">进入系统</el-button>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { ArrowLeft } from '@element-plus/icons-vue'
import { authApi } from '../../api/auth'
import { useContextStore } from '../../stores/context'
import type { AccountSet } from '../../types/api'

const router = useRouter()
const context = useContextStore()
const accountSets = ref<AccountSet[]>([])
const selectedAccountSet = ref<AccountSet | null>(null)
const selectedYear = ref<number>(0)

const bizTypeMeta = (type: string) => {
  const map: Record<string, { label: string; desc: string }> = {
    CASE_FUND: { label: '案款', desc: '只展示案款收支、余额、未退案款和案款业务入口。' },
    LITIGATION_FEE: { label: '诉讼费', desc: '只展示诉讼费收退、待退、上缴和诉讼费业务入口。' },
    CANTEEN: { label: '食堂', desc: '只展示食堂收入、支出、结余和食堂资金入口。' },
    UNION: { label: '工会', desc: '只展示工会经费收入、支出、结余和工会业务入口。' }
  }
  return map[type] || { label: type, desc: '专项资金独立账套。' }
}

const selectCard = (item: AccountSet) => {
  selectedAccountSet.value = item
  selectedYear.value = item.enabled_year || (item.available_years?.[0] ?? 0)
}

const cancelSelect = () => {
  selectedAccountSet.value = null
  selectedYear.value = 0
}

const returnToPreviousAccountSet = () => {
  router.replace('/dashboard')
}

const confirmEnter = async () => {
  if (!selectedAccountSet.value || !selectedYear.value) {
    ElMessage.warning('请选择账套和年度')
    return
  }
  context.selectAccountSet(selectedAccountSet.value, selectedYear.value)
  localStorage.removeItem('court-finance-page-tabs')
  ElMessage.success(`已进入${selectedAccountSet.value.set_name} · ${selectedYear.value}年`)
  await router.replace('/dashboard')
}

onMounted(async () => {
  accountSets.value = await authApi.accountSets()
})
</script>

<style scoped>
.account-select-page {
  max-width: 960px;
  margin: 0 auto;
  padding: 40px 24px;
}

.account-select-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 32px;
}

.account-select-header h1 {
  margin: 8px 0 4px;
  font-size: 22px;
  font-weight: 600;
}

.account-select-header p {
  margin: 0;
  color: var(--text-secondary);
  font-size: 14px;
}

.login-kicker {
  color: var(--primary);
  font-size: 13px;
  font-weight: 500;
}

.account-select-user {
  display: flex;
  align-items: center;
  gap: 12px;
}

.account-select-user strong {
  display: block;
  font-size: 15px;
}

.account-select-user span {
  display: block;
  color: var(--text-secondary);
  font-size: 13px;
}

.account-select-side {
  display: flex;
  align-items: center;
  gap: 12px;
}

.account-set-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 16px;
}

.account-set-card-wrapper {
  display: flex;
  flex-direction: column;
}

.account-set-card {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  padding: 20px;
  background: var(--card-bg);
  border: 2px solid var(--border-light);
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.2s;
  text-align: left;
  width: 100%;
}

.account-set-card:hover {
  border-color: var(--primary);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.account-set-card.active {
  border-color: var(--primary);
  background: var(--primary-bg);
}

.account-set-type {
  display: inline-block;
  padding: 2px 10px;
  background: var(--primary);
  color: #fff;
  font-size: 12px;
  font-weight: 500;
  border-radius: 4px;
}

.account-set-card h2 {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
  color: var(--text-main);
}

.account-set-card p {
  margin: 0;
  font-size: 13px;
  color: var(--text-secondary);
  line-height: 1.5;
}

.account-set-card small {
  font-size: 12px;
  color: var(--text-mute);
}

.year-select-panel {
  margin-top: 8px;
  padding: 16px 20px;
  background: var(--card-bg);
  border: 2px solid var(--primary);
  border-radius: 10px;
}

.year-select-content {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.year-select-label {
  font-size: 14px;
  font-weight: 500;
  color: var(--text-main);
}

.year-select-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 4px;
}
</style>
