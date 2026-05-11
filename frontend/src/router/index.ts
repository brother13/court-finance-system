import { createRouter, createWebHashHistory } from 'vue-router'
import { useContextStore } from '../stores/context'

const DashboardView = () => import('../views/dashboard/DashboardView.vue')
const VoucherListView = () => import('../views/voucher/VoucherListView.vue')
const VoucherEditorView = () => import('../views/voucher/VoucherEditorView.vue')
const DetailLedgerView = () => import('../views/books/DetailLedgerView.vue')
const SubjectBalanceView = () => import('../views/books/SubjectBalanceView.vue')
const SubjectSummaryView = () => import('../views/books/SubjectSummaryView.vue')
const AuxBalanceView = () => import('../views/books/AuxBalanceView.vue')
const PaymentRegisterView = () => import('../views/case-fund/PaymentRegisterView.vue')
const RefundRegisterView = () => import('../views/case-fund/RefundRegisterView.vue')
const BankStatementView = () => import('../views/case-fund/BankStatementView.vue')
const SubjectManageView = () => import('../views/base/SubjectManageView.vue')
const OpeningBalanceView = () => import('../views/base/OpeningBalanceView.vue')
const AuxItemManageView = () => import('../views/base/AuxItemManageView.vue')
const AuditLogView = () => import('../views/system/AuditLogView.vue')
const AccountSetManageView = () => import('../views/system/AccountSetManageView.vue')
const UserManageView = () => import('../views/system/UserManageView.vue')
const RoleManageView = () => import('../views/system/RoleManageView.vue')
const RolePermissionView = () => import('../views/system/RolePermissionView.vue')
const LoginView = () => import('../views/auth/LoginView.vue')
const AccountSetSelectView = () => import('../views/auth/AccountSetSelectView.vue')

const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/', redirect: '/dashboard' },
    { path: '/login', component: LoginView, meta: { public: true } },
    { path: '/select-account-set', component: AccountSetSelectView, meta: { accountSelect: true } },
    { path: '/dashboard', component: DashboardView, meta: { permission: 'menu:dashboard' } },
    { path: '/vouchers', component: VoucherListView, meta: { permission: 'menu:voucher' } },
    { path: '/vouchers/new', component: VoucherEditorView, meta: { permission: 'voucher:add', mode: 'new' } },
    { path: '/vouchers/edit/:period/:voucherId', component: VoucherEditorView, meta: { permission: 'voucher:edit', mode: 'edit' } },
    { path: '/vouchers/detail/:period/:voucherId', component: VoucherEditorView, meta: { permission: 'voucher:view', mode: 'view' } },
    { path: '/books/detail-ledger', component: DetailLedgerView, meta: { permission: 'menu:book:detail_ledger' } },
    { path: '/books/subject-balance', component: SubjectBalanceView, meta: { permission: 'menu:book:subject_balance' } },
    { path: '/books/subject-summary', component: SubjectSummaryView, meta: { permission: 'menu:book:subject_summary' } },
    { path: '/books/aux-balance', component: AuxBalanceView, meta: { permission: 'menu:book:aux_balance' } },
    { path: '/case-fund/payments', component: PaymentRegisterView, meta: { permission: 'menu:case_fund:payment' } },
    { path: '/case-fund/refunds', component: RefundRegisterView, meta: { permission: 'menu:case_fund:refund' } },
    { path: '/case-fund/bank-statements', component: BankStatementView, meta: { permission: 'menu:case_fund:bank_statement' } },
    { path: '/base/subjects', component: SubjectManageView, meta: { permission: 'menu:base:subject' } },
    { path: '/base/opening-balances', component: OpeningBalanceView, meta: { permission: 'menu:base:opening' } },
    { path: '/base/aux-items', component: AuxItemManageView, meta: { permission: 'menu:base:aux' } },
    { path: '/system/users', component: UserManageView, meta: { permission: 'menu:system:user' } },
    { path: '/system/roles', component: RoleManageView, meta: { permission: 'menu:system:role' } },
    { path: '/system/role-permissions', component: RolePermissionView, meta: { permission: 'menu:system:role_permission' } },
    { path: '/system/account-sets', component: AccountSetManageView, meta: { permission: 'menu:system:account_set' } },
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
  if (!to.meta.public && !to.meta.accountSelect && (!context.hasAccountSet || context.year <= 0)) {
    return '/select-account-set'
  }
  if (to.meta.accountSelect && context.hasAccountSet && context.year > 0 && to.query.switch !== '1') {
    return '/dashboard'
  }
  if (!to.meta.public && to.meta.permission && !context.hasPermission(String(to.meta.permission))) {
    return '/dashboard'
  }
  return true
})

export default router
