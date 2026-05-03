import { defineStore } from 'pinia'
import type { AccountSet, LoginUser, RoleBrief } from '../types/api'

const STORAGE_KEY = 'court-finance-auth'

const loadAuth = (): Partial<LoginUser> => {
  try {
    return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}')
  } catch {
    return {}
  }
}

export const useContextStore = defineStore('context', {
  state: () => {
    const auth = loadAuth()
    return {
      accountSetId: auth.account_set_id || '00000000-0000-0000-0000-000000000101',
      accountSetCode: (auth as any).set_code || '',
      accountSetName: (auth as any).set_name || '',
      bizType: (auth as any).biz_type || '',
      userId: auth.user_id || '',
      username: auth.username || '',
      realName: auth.real_name || '',
      unitId: auth.unit_id || '',
      unitCode: auth.unit_code || '',
      unitName: auth.unit_name || '',
      permissions: auth.permissions || [],
      viewScope: auth.view_scope || 'ALL',
      mustChangePassword: Boolean(auth.must_change_password),
      roles: auth.roles || [],
      accountSets: auth.account_sets || [],
      period: '2026-05'
    }
  },
  getters: {
    isLoggedIn: (state) => Boolean(state.userId && state.unitId),
    hasAccountSet: (state) => Boolean(state.accountSetId && state.bizType),
    displayName: (state) => state.realName || state.username || '未登录',
    roleNames: (state) => state.roles.map((role: RoleBrief) => role.role_name).join('、'),
    hasPermission: (state) => (code: string) =>
      state.permissions.includes('*') || state.permissions.includes(code),
    hasAnyPermission: (state) => (codes: string[]) =>
      codes.some((code) => state.permissions.includes('*') || state.permissions.includes(code))
  },
  actions: {
    setAuth(user: LoginUser) {
      this.accountSetId = user.account_set_id || ''
      this.accountSetCode = ''
      this.accountSetName = ''
      this.bizType = ''
      this.userId = user.user_id
      this.username = user.username
      this.realName = user.real_name
      this.unitId = user.unit_id
      this.unitCode = user.unit_code
      this.unitName = user.unit_name
      this.permissions = user.permissions || []
      this.viewScope = user.view_scope || 'ALL'
      this.mustChangePassword = Boolean(user.must_change_password)
      this.roles = user.roles || []
      this.accountSets = user.account_sets || []
      localStorage.setItem(STORAGE_KEY, JSON.stringify(user))
    },
    selectAccountSet(accountSet: AccountSet) {
      this.accountSetId = accountSet.account_set_id
      this.accountSetCode = accountSet.set_code
      this.accountSetName = accountSet.set_name
      this.bizType = accountSet.biz_type
      localStorage.setItem(STORAGE_KEY, JSON.stringify({
        user_id: this.userId,
        username: this.username,
        real_name: this.realName,
        unit_id: this.unitId,
        unit_code: this.unitCode,
        unit_name: this.unitName,
        permissions: this.permissions,
        view_scope: this.viewScope,
        must_change_password: this.mustChangePassword,
        roles: this.roles,
        account_sets: this.accountSets,
        account_set_id: this.accountSetId,
        set_code: this.accountSetCode,
        set_name: this.accountSetName,
        biz_type: this.bizType
      }))
    },
    logout() {
      this.accountSetId = ''
      this.accountSetCode = ''
      this.accountSetName = ''
      this.bizType = ''
      this.userId = ''
      this.username = ''
      this.realName = ''
      this.unitId = ''
      this.unitCode = ''
      this.unitName = ''
      this.permissions = []
      this.viewScope = 'ALL'
      this.mustChangePassword = false
      this.roles = []
      this.accountSets = []
      localStorage.removeItem(STORAGE_KEY)
    },
    markPasswordChanged() {
      this.mustChangePassword = false
      const saved = loadAuth()
      localStorage.setItem(STORAGE_KEY, JSON.stringify({
        ...saved,
        must_change_password: false
      }))
    }
  }
})
