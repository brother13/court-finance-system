import { apiAction } from './http'
import type { Role } from '../types/api'

export const roleApi = {
  list(params: Record<string, any> = {}) {
    return apiAction('/role/list', params) as Promise<Role[]>
  },
  info(roleId: string) {
    return apiAction('/role/info', { role_id: roleId }) as Promise<Role>
  },
  add(payload: Record<string, any>) {
    return apiAction('/role/add', payload) as Promise<string>
  },
  edit(payload: Record<string, any>) {
    return apiAction('/role/edit', payload) as Promise<string>
  },
  delete(roleId: string) {
    return apiAction('/role/delete', { role_id: roleId }) as Promise<string>
  },
  assignPermissions(roleId: string, permissionCodes: string[]) {
    return apiAction('/role/assignPermissions', { role_id: roleId, permission_codes: permissionCodes }) as Promise<string>
  }
}
