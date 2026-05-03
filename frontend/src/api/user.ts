import { apiAction } from './http'
import type { ManagedUser } from '../types/api'

export const userApi = {
  page(params: Record<string, any>) {
    return apiAction('/user/list', params) as Promise<{ items: ManagedUser[]; total: number }>
  },
  info(userId: string) {
    return apiAction('/user/info', { user_id: userId }) as Promise<ManagedUser>
  },
  add(payload: Record<string, any>) {
    return apiAction('/user/add', payload) as Promise<string>
  },
  edit(payload: Record<string, any>) {
    return apiAction('/user/edit', payload) as Promise<string>
  },
  delete(userId: string) {
    return apiAction('/user/delete', { user_id: userId }) as Promise<string>
  },
  toggleStatus(userId: string, status: number) {
    return apiAction('/user/toggleStatus', { user_id: userId, status }) as Promise<string>
  },
  resetPassword(userId: string, newPassword: string) {
    return apiAction('/user/resetPassword', { user_id: userId, new_password: newPassword }) as Promise<string>
  },
  changePassword(oldPassword: string, newPassword: string) {
    return apiAction('/user/changePassword', { old_password: oldPassword, new_password: newPassword }) as Promise<string>
  },
  assignRoles(userId: string, roleIds: string[]) {
    return apiAction('/user/assignRoles', { user_id: userId, role_ids: roleIds }) as Promise<string>
  },
  assignAccountSets(userId: string, accountSetIds: string[]) {
    return apiAction('/user/assignAccountSets', { user_id: userId, account_set_ids: accountSetIds }) as Promise<string>
  }
}
