import { apiAction } from './http'
import type { Permission, RoleBrief } from '../types/api'

export const permissionApi = {
  list() {
    return apiAction('/permission/list') as Promise<{ items: Permission[]; groups: Record<string, Permission[]> }>
  },
  userPermissions() {
    return apiAction('/permission/userPermissions') as Promise<{
      permissions: string[]
      view_scope: 'ALL' | 'SELF'
      roles: RoleBrief[]
    }>
  }
}
