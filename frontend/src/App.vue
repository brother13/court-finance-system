<template>
  <router-view v-if="isAuthPage" />
  <el-container v-else class="app-shell">
    <el-aside :width="sidebarWidth" class="sidebar" :class="{ 'is-collapsed': sidebarCollapsed }">
      <div class="brand">
        <img class="brand-logo" :src="raspberryLogo" alt="树莓软件" />
        <div class="brand-info">
          <strong>树莓法衡</strong>
          <small>{{ context.accountSetName || context.unitName || '账套未选择' }}</small>
        </div>
        <el-button
          class="sidebar-toggle"
          :icon="sidebarToggleIcon"
          text
          circle
          :title="sidebarToggleTitle"
          :aria-label="sidebarToggleTitle"
          @click="toggleSidebar"
        />
      </div>

      <el-menu
        ref="sidebarMenuRef"
        router
        :collapse="sidebarCollapsed"
        :default-active="$route.path"
        class="nav-menu"
        @open="handleSidebarMenuOpen"
        @close="handleSidebarMenuClose"
      >
        <el-menu-item v-if="context.hasPermission('menu:dashboard')" index="/dashboard">
          <el-icon><DataLine /></el-icon>
          <span>首页看板</span>
        </el-menu-item>
        <el-menu-item v-if="context.hasPermission('menu:voucher')" index="/vouchers">
          <el-icon><Tickets /></el-icon>
          <span>凭证中心</span>
        </el-menu-item>
        <el-sub-menu v-if="context.hasAnyPermission(bookMenuPermissions)" index="/books">
          <template #title>
            <el-icon><Notebook /></el-icon>
            <span>账簿报表</span>
          </template>
          <el-menu-item v-if="context.hasPermission('menu:book:detail_ledger')" index="/books/detail-ledger">明细账</el-menu-item>
          <el-menu-item v-if="context.hasPermission('menu:book:subject_balance')" index="/books/subject-balance">科目余额表</el-menu-item>
          <el-menu-item v-if="context.hasPermission('menu:book:subject_summary')" index="/books/subject-summary">科目汇总表</el-menu-item>
          <el-menu-item v-if="context.hasPermission('menu:book:aux_balance')" index="/books/aux-balance">辅助核算余额表</el-menu-item>
          <el-menu-item v-if="context.hasPermission('menu:book:case_movement_detail')" index="/books/case-movement-detail">案号收支明细表</el-menu-item>
        </el-sub-menu>
        <el-sub-menu v-if="fundBusinessMenuBizTypes.includes(context.bizType) && context.hasAnyPermission(caseFundMenuPermissions)" index="/case-fund">
          <template #title>
            <el-icon><Coin /></el-icon>
            <span>案款业务</span>
          </template>
          <el-menu-item v-if="context.hasPermission('menu:case_fund:payment')" index="/case-fund/payments">缴费登记</el-menu-item>
          <el-menu-item v-if="context.hasPermission('menu:case_fund:refund')" index="/case-fund/refunds">出账查询</el-menu-item>
          <el-menu-item v-if="context.hasPermission('menu:case_fund:bank_statement')" index="/case-fund/bank-statements">银行对账单</el-menu-item>
          <el-menu-item v-if="context.bizType === 'CASE_FUND' && context.hasPermission('menu:case_fund:unknown_payment')" index="/case-fund/unknown-payments">不明款</el-menu-item>
          <el-menu-item v-if="context.bizType === 'CASE_FUND' && context.hasPermission('menu:case_fund:distribution')" index="/case-fund/distributions">案款分配</el-menu-item>
        </el-sub-menu>
        <el-menu-item v-if="context.hasPermission('period:close')" index="/period/close">
          <el-icon><Calendar /></el-icon>
          <span>期末结账</span>
        </el-menu-item>
        <el-sub-menu v-if="context.hasAnyPermission(baseMenuPermissions)" index="/base">
          <template #title>
            <el-icon><Grid /></el-icon>
            <span>基础资料</span>
          </template>
          <el-menu-item v-if="context.hasPermission('menu:base:subject')" index="/base/subjects">科目</el-menu-item>
          <el-menu-item v-if="context.hasPermission('menu:base:opening')" index="/base/opening-balances">期初</el-menu-item>
          <el-menu-item v-if="context.hasPermission('menu:base:aux')" index="/base/aux-items">辅助核算项</el-menu-item>
          <el-menu-item v-if="context.hasPermission('menu:base:carry_forward')" index="/base/carry-forward-schemes">收支结转方案</el-menu-item>
        </el-sub-menu>
        <el-sub-menu v-if="context.hasAnyPermission(systemMenuPermissions)" index="/system">
          <template #title>
            <el-icon><Setting /></el-icon>
            <span>系统管理</span>
          </template>
          <el-menu-item v-if="context.hasPermission('menu:system:user')" index="/system/users">用户管理</el-menu-item>
          <el-menu-item v-if="context.hasPermission('menu:system:role')" index="/system/roles">角色管理</el-menu-item>
          <el-menu-item v-if="context.hasPermission('menu:system:role_permission')" index="/system/role-permissions">角色权限配置</el-menu-item>
          <el-menu-item v-if="context.hasPermission('menu:system:account_set')" index="/system/account-sets">账套管理</el-menu-item>
          <el-menu-item v-if="context.hasPermission('menu:system:audit_log')" index="/system/audit-logs">审计日志</el-menu-item>
        </el-sub-menu>
      </el-menu>

      <div class="sidebar-footer">
        <div class="sidebar-user">
          <el-avatar :size="32" :icon="UserFilled" />
          <div class="sidebar-user-info">
            <p>{{ context.displayName }}</p>
            <small>{{ context.period }} 会计期间 · {{ context.roleNames || 'user' }}</small>
          </div>
        </div>
      </div>
    </el-aside>

    <el-container>
      <el-header class="topbar">
        <div class="topbar-title">
          <strong>{{ pageTitle }}</strong>
          <span>{{ context.unitName || '当前单位' }}</span>
        </div>
        <div class="topbar-actions">
          <div class="topbar-account-set">
            <span class="account-set-chip">{{ context.accountSetName || '未选账套' }}</span>
            <small>
              <span>{{ bizTypeLabel }}</span>
              <span>{{ context.year }}年</span>
              <span>{{ context.period }} 期间</span>
            </small>
          </div>
          <span class="topbar-divider" />
          <el-button type="primary" plain @click="switchAccountSet">切换账套</el-button>
          <el-button :icon="Key" plain title="修改密码" @click="openChangePasswordDialog">修改密码</el-button>
          <el-button :icon="SwitchButton" circle title="退出登录" @click="handleLogout" />
        </div>
      </el-header>
      <div class="page-tabs-bar">
        <button
          v-for="tab in openedTabs"
          :key="tab.path"
          type="button"
          class="page-tab"
          :class="{ active: tab.path === route.path }"
          @click="activateTab(tab.path)"
        >
          <span>{{ tab.title }}</span>
          <el-icon v-if="tab.closable" class="page-tab-close" @click.stop="closeTab(tab.path)">
            <Close />
          </el-icon>
        </button>
      </div>
      <el-main class="main">
        <router-view v-slot="{ Component }">
          <KeepAlive include="VoucherListView,BankStatementView">
            <component :is="Component" />
          </KeepAlive>
        </router-view>
      </el-main>
    </el-container>
  </el-container>
  <ForceChangePasswordDialog
    :manual-visible="changePasswordDialogVisible"
    @update:manual-visible="changePasswordDialogVisible = $event"
  />
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Calendar, Close, Coin, DataLine, Expand, Fold, Grid, Key, Notebook, Setting, SwitchButton, Tickets, UserFilled } from '@element-plus/icons-vue'
import type { MenuInstance } from 'element-plus'
import ForceChangePasswordDialog from './components/ForceChangePasswordDialog.vue'
import { useContextStore } from './stores/context'
import raspberryLogo from './assets/raspberry-logo.png'

const route = useRoute()
const router = useRouter()
const context = useContextStore()
const tabsStorageKey = 'court-finance-page-tabs'
const bookMenuPermissions = ['menu:book:detail_ledger', 'menu:book:subject_balance', 'menu:book:subject_summary', 'menu:book:aux_balance', 'menu:book:case_movement_detail']
const caseFundMenuPermissions = ['menu:case_fund:payment', 'menu:case_fund:refund', 'menu:case_fund:bank_statement', 'menu:case_fund:unknown_payment', 'menu:case_fund:distribution']
const fundBusinessMenuBizTypes = ['CASE_FUND', 'LITIGATION_FEE']
const baseMenuPermissions = ['menu:base:subject', 'menu:base:opening', 'menu:base:aux', 'menu:base:carry_forward']
const systemMenuPermissions = ['menu:system:user', 'menu:system:role', 'menu:system:role_permission', 'menu:system:account_set', 'menu:system:audit_log']

interface PageTab {
  path: string
  title: string
  closable: boolean
}

const titleMap: Record<string, string> = {
  '/dashboard': '首页看板',
  '/select-account-set': '选择账套',
  '/vouchers': '凭证中心',
  '/vouchers/new': '凭证录入',
  '/books/detail-ledger': '明细账',
  '/books/subject-balance': '科目余额表',
  '/books/subject-summary': '科目汇总表',
  '/books/aux-balance': '辅助核算余额表',
  '/books/case-movement-detail': '案号收支明细表',
  '/case-fund/payments': '缴费登记',
  '/case-fund/refunds': '出账查询',
  '/case-fund/bank-statements': '银行对账单',
  '/case-fund/unknown-payments': '不明款',
  '/case-fund/distributions': '案款分配',
  '/period/close': '期末结账',
  '/base/subjects': '科目',
  '/base/opening-balances': '期初',
  '/base/aux-items': '辅助核算项',
  '/base/carry-forward-schemes': '收支结转方案',
  '/system/users': '用户管理',
  '/system/roles': '角色管理',
  '/system/role-permissions': '角色权限配置',
  '/system/account-sets': '账套管理',
  '/system/audit-logs': '审计日志'
}

const pageTitle = computed(() => titleMap[route.path] || '法院专项账务记账系统')
const accountSetShort = computed(() => context.accountSetId ? context.accountSetId.slice(-4).toUpperCase() : '----')
const isAuthPage = computed(() => route.path === '/login' || route.path === '/select-account-set')
const defaultTabs: PageTab[] = [{ path: '/dashboard', title: '首页看板', closable: false }]
const openedTabs = ref<PageTab[]>(loadTabs())
const sidebarCollapsed = ref(false)
const sidebarMenuRef = ref<MenuInstance>()
const openedSidebarMenus = ref<string[]>([])
const maxOpenedSidebarMenus = 2
const changePasswordDialogVisible = ref(false)
const sidebarWidth = computed(() => sidebarCollapsed.value ? '64px' : '200px')
const sidebarToggleTitle = computed(() => sidebarCollapsed.value ? '展开菜单' : '收起菜单')
const sidebarToggleIcon = computed(() => sidebarCollapsed.value ? Expand : Fold)
const bizTypeLabel = computed(() => {
  const map: Record<string, string> = {
    CASE_FUND: '案款',
    LITIGATION_FEE: '诉讼费',
    CANTEEN: '食堂',
    UNION: '工会'
  }
  return map[context.bizType] || '专项账套'
})

function loadTabs() {
  try {
    const saved = JSON.parse(localStorage.getItem(tabsStorageKey) || '[]') as PageTab[]
    const validTabs = saved.filter((tab) => titleMap[tab.path])
    const merged = [...defaultTabs]
    validTabs.forEach((tab) => {
      if (!merged.some((item) => item.path === tab.path)) {
        merged.push({
          path: tab.path,
          title: titleMap[tab.path],
          closable: tab.path !== '/dashboard'
        })
      }
    })
    return merged
  } catch {
    return [...defaultTabs]
  }
}

const persistTabs = () => {
  localStorage.setItem(tabsStorageKey, JSON.stringify(openedTabs.value))
}

const addRouteTab = () => {
  if (isAuthPage.value || !titleMap[route.path]) return
  if (!openedTabs.value.some((tab) => tab.path === route.path)) {
    openedTabs.value.push({
      path: route.path,
      title: titleMap[route.path],
      closable: route.path !== '/dashboard'
    })
  }
  persistTabs()
}

const activateTab = (path: string) => {
  if (path !== route.path) {
    router.push(path)
  }
}

const closeTab = (path: string) => {
  const index = openedTabs.value.findIndex((tab) => tab.path === path)
  if (index < 0 || !openedTabs.value[index].closable) return
  openedTabs.value.splice(index, 1)
  persistTabs()
  if (route.path === path) {
    const next = openedTabs.value[index - 1] || openedTabs.value[index] || defaultTabs[0]
    router.push(next.path)
  }
}

const handleLogout = () => {
  context.logout()
  localStorage.removeItem(tabsStorageKey)
  router.replace('/login')
}

const switchAccountSet = () => {
  localStorage.removeItem(tabsStorageKey)
  openedTabs.value = [...defaultTabs]
  router.push({ path: '/select-account-set', query: { switch: '1' } })
}

const openChangePasswordDialog = () => {
  changePasswordDialogVisible.value = true
}

const toggleSidebar = () => {
  sidebarCollapsed.value = !sidebarCollapsed.value
  if (sidebarCollapsed.value) {
    openedSidebarMenus.value = []
  }
}

const handleSidebarMenuOpen = (index: string) => {
  const nextOpenedMenus = [
    ...openedSidebarMenus.value.filter((item) => item !== index),
    index
  ]
  while (nextOpenedMenus.length > maxOpenedSidebarMenus) {
    const oldestIndex = nextOpenedMenus.shift()
    if (oldestIndex) {
      sidebarMenuRef.value?.close(oldestIndex)
    }
  }
  openedSidebarMenus.value = nextOpenedMenus
}

const handleSidebarMenuClose = (index: string) => {
  openedSidebarMenus.value = openedSidebarMenus.value.filter((item) => item !== index)
}

watch(
  () => route.path,
  () => addRouteTab(),
  { immediate: true }
)
</script>
