<template>
  <main class="account-select-page">
    <section class="account-select-header">
      <div>
        <span class="login-kicker">账套选择 · 数据隔离</span>
        <h1>请选择本次进入的专项资金账套</h1>
        <p>每个款项类型对应独立账套。进入后系统只展示当前账套的数据，严禁跨账套汇总、混合展示。</p>
      </div>
      <div class="account-select-user">
        <el-avatar :size="40">{{ context.displayName.slice(0, 1) }}</el-avatar>
        <div>
          <strong>{{ context.displayName }}</strong>
          <span>{{ context.unitName }}</span>
        </div>
      </div>
    </section>

    <section class="account-set-grid">
      <button
        v-for="item in accountSets"
        :key="item.account_set_id"
        type="button"
        class="account-set-card"
        @click="selectAccountSet(item)"
      >
        <span class="account-set-type">{{ bizTypeMeta(item.biz_type).label }}</span>
        <h2>{{ item.set_name }}</h2>
        <p>{{ bizTypeMeta(item.biz_type).desc }}</p>
        <small>{{ item.set_code }} · {{ item.enabled_year }}</small>
      </button>
    </section>
  </main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { authApi } from '../../api/auth'
import { useContextStore } from '../../stores/context'
import type { AccountSet } from '../../types/api'

const router = useRouter()
const context = useContextStore()
const accountSets = ref<AccountSet[]>([])

const bizTypeMeta = (type: string) => {
  const map: Record<string, { label: string; desc: string }> = {
    CASE_FUND: { label: '案款', desc: '只展示案款收支、余额、未退案款和案款业务入口。' },
    LITIGATION_FEE: { label: '诉讼费', desc: '只展示诉讼费收退、待退、上缴和诉讼费业务入口。' },
    CANTEEN: { label: '食堂', desc: '只展示食堂收入、支出、结余和食堂资金入口。' },
    UNION: { label: '工会', desc: '只展示工会经费收入、支出、结余和工会业务入口。' }
  }
  return map[type] || { label: type, desc: '专项资金独立账套。' }
}

const selectAccountSet = async (item: AccountSet) => {
  context.selectAccountSet(item)
  localStorage.removeItem('court-finance-page-tabs')
  ElMessage.success(`已进入${item.set_name}`)
  await router.replace('/dashboard')
}

onMounted(async () => {
  accountSets.value = await authApi.accountSets()
})
</script>
