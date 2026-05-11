import axios from 'axios'
import { ElMessage } from 'element-plus'
import { useContextStore } from '../stores/context'

const apiBaseURL = import.meta.env.VITE_API_BASE || '../index.php'

export const http = axios.create({
  baseURL: apiBaseURL,
  timeout: 15000
})

http.interceptors.request.use((config) => {
  const context = useContextStore()
  config.headers['X-User-Id'] = context.userId || 'anonymous'
  config.headers['X-User-Name'] = context.username || context.userId || 'anonymous'
  return config
})

http.interceptors.response.use((response) => {
  const result = response.data
  if (result && result.code !== 20000) {
    ElMessage.error(result.message || '请求失败')
    return Promise.reject(new Error(result.message))
  }
  return result.data
})

export const apiAction = <T = any>(action: string, data: Record<string, any> = {}, extra: Record<string, any> = {}) => {
  const context = useContextStore()
  const body = new URLSearchParams()
  body.set('action', action)
  body.set('account_set_id', context.accountSetId)
  body.set('year', String(context.year))
  body.set('data', JSON.stringify(data))
  Object.entries(extra).forEach(([key, value]) => body.set(key, String(value)))
  return http.post('', body, {
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded'
    }
  }) as Promise<T>
}
