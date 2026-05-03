import { apiAction } from './http'
import type { AccountSet, LoginUser, Unit } from '../types/api'

export const authApi = {
  units() {
    return apiAction('/auth/unitList') as Promise<Unit[]>
  },
  login(payload: { unit_id: string; username: string; password: string }) {
    return apiAction('/auth/login', payload) as Promise<LoginUser>
  },
  accountSets() {
    return apiAction('/auth/accountSetList') as Promise<AccountSet[]>
  }
}
