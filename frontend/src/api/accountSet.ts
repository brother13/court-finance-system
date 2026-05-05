import { apiAction } from './http'
import type { AccountSet } from '../types/api'

export interface AccountSetPage {
  items: AccountSet[]
  total: number
}

export const accountSetApi = {
  page() {
    return apiAction('/accountSet/list') as Promise<AccountSetPage>
  },
  add(payload: Record<string, any>) {
    return apiAction('/accountSet/add', payload) as Promise<string>
  },
  edit(payload: Record<string, any>) {
    return apiAction('/accountSet/edit', payload) as Promise<string>
  }
}
