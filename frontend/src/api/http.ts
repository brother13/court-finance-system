import axios from 'axios'
import { ElMessage } from 'element-plus'
import { useContextStore } from '../stores/context'

const apiBaseURL = import.meta.env.VITE_API_BASE || '../index.php'

type ApiActionOptions = {
  timeout?: number
}

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
    const error = new Error(result.message || '请求失败')
    ;(error as any).data = result.data
    ;(error as any).result = result
    return Promise.reject(error)
  }
  return result.data
})

export const apiAction = <T = any>(
  action: string,
  data: Record<string, any> = {},
  extra: Record<string, any> = {},
  options: ApiActionOptions = {}
) => {
  const context = useContextStore()
  const body = new URLSearchParams()
  body.set('action', action)
  body.set('account_set_id', context.accountSetId)
  body.set('year', String(context.year))
  body.set('data', JSON.stringify(data))
  Object.entries(extra).forEach(([key, value]) => body.set(key, String(value)))
  return http.post('', body, {
    timeout: options.timeout,
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded'
    }
  }) as Promise<T>
}

export const apiBlobAction = async (
  action: string,
  data: Record<string, any> = {},
  extra: Record<string, any> = {},
  options: ApiActionOptions = {}
) => {
  const context = useContextStore()
  const body = new URLSearchParams()
  body.set('action', action)
  body.set('account_set_id', context.accountSetId)
  body.set('year', String(context.year))
  body.set('data', JSON.stringify(data))
  Object.entries(extra).forEach(([key, value]) => body.set(key, String(value)))
  const response = await axios.post(apiBaseURL, body, {
    timeout: options.timeout || 30000,
    responseType: 'blob',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-User-Id': context.userId || 'anonymous',
      'X-User-Name': context.username || context.userId || 'anonymous'
    }
  })
  const contentType = String(response.headers['content-type'] || '')
  if (contentType.includes('application/pdf')) {
    return response.data as Blob
  }
  const text = await (response.data as Blob).text()
  try {
    const result = JSON.parse(text)
    throw new Error(result.message || '生成PDF失败')
  } catch (error: any) {
    if (error?.message && error.message !== 'Unexpected end of JSON input') {
      throw error
    }
    throw new Error(text || '生成PDF失败')
  }
}
