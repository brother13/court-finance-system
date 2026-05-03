import { createRouter, createWebHistory } from 'vue-router'
import DashboardView from '../views/dashboard/DashboardView.vue'
import VoucherListView from '../views/voucher/VoucherListView.vue'
import VoucherEditorView from '../views/voucher/VoucherEditorView.vue'
import DetailLedgerView from '../views/books/DetailLedgerView.vue'
import SubjectBalanceView from '../views/books/SubjectBalanceView.vue'
import SubjectManageView from '../views/base/SubjectManageView.vue'
import OpeningBalanceView from '../views/base/OpeningBalanceView.vue'
import AuxItemManageView from '../views/base/AuxItemManageView.vue'
import AuditLogView from '../views/system/AuditLogView.vue'
import UserManageView from '../views/system/UserManageView.vue'
import RoleManageView from '../views/system/RoleManageView.vue'
import RolePermissionView from '../views/system/RolePermissionView.vue'
import LoginView from '../views/auth/LoginView.vue'
import AccountSetSelectView from '../views/auth/AccountSetSelectView.vue'
import { useContextStore } from '../stores/context'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: '/dashboard' },
    { path: '/login', component: LoginView, meta: { public: true } },
    { path: '/select-account-set', component: AccountSetSelectView, meta: { accountSelect: true } },
    { path: '/dashboard', component: DashboardView, meta: { permission: 'menu:dashboard' } },
    { path: '/vouchers', component: VoucherListView, meta: { permission: 'menu:voucher' } },
    { path: '/vouchers/new', component: VoucherEditorView, meta: { permission: 'voucher:add' } },
    { path: '/vouchers/detail/:period/:voucherId', component: VoucherEditorView, meta: { permission: 'voucher:view' } },
    { path: '/books/detail-ledger', component: DetailLedgerView, meta: { permission: 'menu:book:detail_ledger' } },
    { path: '/books/subject-balance', component: SubjectBalanceView, meta: { permission: 'menu:book:subject_balance' } },
    { path: '/base/subjects', component: SubjectManageView, meta: { permission: 'menu:base:subject' } },
    { path: '/base/opening-balances', component: OpeningBalanceView, meta: { permission: 'menu:base:opening' } },
    { path: '/base/aux-items', component: AuxItemManageView, meta: { permission: 'menu:base:aux' } },
    { path: '/system/users', component: UserManageView, meta: { permission: 'menu:system:user' } },
    { path: '/system/roles', component: RoleManageView, meta: { permission: 'menu:system:role' } },
    { path: '/system/role-permissions', component: RolePermissionView, meta: { permission: 'menu:system:role_permission' } },
    { path: '/system/audit-logs', component: AuditLogView, meta: { permission: 'menu:system:audit_log' } }
  ]
})

router.beforeEach((to) => {
  const context = useContextStore()
  if (to.meta.public && context.isLoggedIn) {
    return context.hasAccountSet ? '/dashboard' : '/select-account-set'
  }
  if (!to.meta.public && !context.isLoggedIn) {
    return {
      path: '/login',
      query: {
        redirect: to.fullPath
      }
    }
  }
  if (!to.meta.public && !to.meta.accountSelect && !context.hasAccountSet) {
    return '/select-account-set'
  }
  if (to.meta.accountSelect && context.hasAccountSet) {
    return '/dashboard'
  }
  if (!to.meta.public && to.meta.permission && !context.hasPermission(String(to.meta.permission))) {
    return '/dashboard'
  }
  return true
})

export default router
