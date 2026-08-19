import { computed, onScopeDispose, ref, shallowRef } from 'vue'
import { caseFundApi } from '../api/caseFund'
import type { CaseFundJob, CaseFundJobCreateRequest, CaseFundJobStatus } from '../types/api'

const TERMINAL_JOB_STATUSES = new Set<CaseFundJobStatus>([
  'SUCCEEDED',
  'PARTIAL_FAILED',
  'FAILED'
])

export const isCaseFundJobTerminal = (status?: CaseFundJobStatus) => (
  Boolean(status && TERMINAL_JOB_STATUSES.has(status))
)

export const getCaseFundJobProgress = (job?: CaseFundJob | null) => {
  if (!job) return 0
  const explicitProgress = Number(job.progress_percent)
  if (Number.isFinite(explicitProgress)) {
    return Math.max(0, Math.min(100, Math.round(explicitProgress)))
  }
  const total = Number(job.total_count || 0)
  const processed = Number(job.processed_count || 0)
  if (total > 0) {
    return Math.max(0, Math.min(100, Math.round(processed / total * 100)))
  }
  return isCaseFundJobTerminal(job.status) ? 100 : 0
}

export const createCaseFundClientRequestId = (prefix = 'case-fund') => {
  const randomPart = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : `${Date.now()}-${Math.random().toString(16).slice(2)}`
  return `${prefix}-${randomPart}`
}

type UseCaseFundJobOptions = {
  pollIntervalMs?: number
  maxPollIntervalMs?: number
  onUpdated?: (job: CaseFundJob) => void
  onFinished?: (job: CaseFundJob) => void
  onPollingError?: (error: unknown) => void
}

const errorMessage = (error: unknown, fallback: string) => {
  if (error instanceof Error && error.message) return error.message
  return fallback
}

const isUncertainRequestError = (error: unknown) => {
  const candidate = error as { code?: string; response?: unknown; result?: unknown } | null
  if (!candidate || candidate.result) return false
  return candidate.code === 'ECONNABORTED'
    || candidate.code === 'ETIMEDOUT'
    || !candidate.response
}

export const useCaseFundJob = (options: UseCaseFundJobOptions = {}) => {
  const job = shallowRef<CaseFundJob | null>(null)
  const dialogVisible = ref(false)
  const creating = ref(false)
  const polling = ref(false)
  const statusLoading = ref(false)
  const createError = ref('')
  const pollError = ref('')
  const pollIntervalMs = Math.max(500, options.pollIntervalMs ?? 1500)
  const maxPollIntervalMs = Math.max(pollIntervalMs, options.maxPollIntervalMs ?? 10000)
  let pollTimer: number | undefined
  let consecutivePollErrors = 0
  let disposed = false
  let finishedNotificationKey = ''

  const progress = computed(() => getCaseFundJobProgress(job.value))
  const isTerminal = computed(() => isCaseFundJobTerminal(job.value?.status))
  const isActive = computed(() => Boolean(job.value && !isTerminal.value))
  const hasFailures = computed(() => Number(job.value?.failure_count || 0) > 0)

  const clearPollTimer = () => {
    if (pollTimer === undefined) return
    window.clearTimeout(pollTimer)
    pollTimer = undefined
  }

  const stopPolling = () => {
    polling.value = false
    clearPollTimer()
  }

  const notifyFinished = (nextJob: CaseFundJob) => {
    if (!isCaseFundJobTerminal(nextJob.status)) return
    const notificationKey = `${nextJob.job_id}:${nextJob.status}`
    if (finishedNotificationKey === notificationKey) return
    finishedNotificationKey = notificationKey
    options.onFinished?.(nextJob)
  }

  const applyJob = (nextJob: CaseFundJob) => {
    job.value = nextJob
    pollError.value = ''
    options.onUpdated?.(nextJob)
    if (isCaseFundJobTerminal(nextJob.status)) {
      stopPolling()
      notifyFinished(nextJob)
      return
    }
    polling.value = true
  }

  const nextPollDelay = () => {
    if (consecutivePollErrors === 0) return pollIntervalMs
    const multiplier = 2 ** Math.min(consecutivePollErrors, 4)
    return Math.min(maxPollIntervalMs, pollIntervalMs * multiplier)
  }

  const schedulePoll = (callback: () => void) => {
    clearPollTimer()
    if (disposed || !polling.value || !job.value || isCaseFundJobTerminal(job.value.status)) return
    pollTimer = window.setTimeout(callback, nextPollDelay())
  }

  const pollCurrentJob = async (): Promise<CaseFundJob | null> => {
    const trackedJobId = job.value?.job_id
    if (!trackedJobId || statusLoading.value) return null
    clearPollTimer()
    statusLoading.value = true
    try {
      const nextJob = await caseFundApi.jobStatus(trackedJobId)
      if (job.value?.job_id !== trackedJobId) return null
      consecutivePollErrors = 0
      applyJob(nextJob)
      return nextJob
    } catch (error) {
      if (job.value?.job_id === trackedJobId) {
        consecutivePollErrors += 1
        pollError.value = errorMessage(error, '任务进度查询暂时失败')
        options.onPollingError?.(error)
      }
      return null
    } finally {
      statusLoading.value = false
      if (job.value?.job_id === trackedJobId) {
        schedulePoll(() => { void pollCurrentJob() })
      }
    }
  }

  const trackJob = (nextJob: CaseFundJob, showDialog = true) => {
    clearPollTimer()
    consecutivePollErrors = 0
    createError.value = ''
    applyJob(nextJob)
    if (showDialog) dialogVisible.value = true
    schedulePoll(() => { void pollCurrentJob() })
    return nextJob
  }

  const recoverCreatedJob = async (request: CaseFundJobCreateRequest) => {
    try {
      const response = await caseFundApi.jobList({
        client_request_id: request.client_request_id,
        job_type: request.job_type,
        page: 1,
        pagesize: 10
      })
      return response.items.find((item) => (
        item.client_request_id === request.client_request_id
        && item.job_type === request.job_type
      )) || null
    } catch {
      return null
    }
  }

  const createJob = async (request: CaseFundJobCreateRequest, showDialog = true) => {
    if (isActive.value) {
      throw new Error('当前已有后台任务正在执行，请等待完成后再提交新任务')
    }
    creating.value = true
    createError.value = ''
    pollError.value = ''
    try {
      const createdJob = await caseFundApi.jobCreate(request)
      return trackJob(createdJob, showDialog)
    } catch (error) {
      if (isUncertainRequestError(error)) {
        const recoveredJob = await recoverCreatedJob(request)
        if (recoveredJob) return trackJob(recoveredJob, showDialog)
        createError.value = '任务提交结果尚未确认，请先查询任务记录，切勿重复提交。'
      } else {
        createError.value = errorMessage(error, '后台任务创建失败')
      }
      throw error
    } finally {
      creating.value = false
    }
  }

  const resumeJob = async (jobId: string, showDialog = true) => {
    clearPollTimer()
    statusLoading.value = true
    pollError.value = ''
    try {
      const nextJob = await caseFundApi.jobStatus(jobId)
      return trackJob(nextJob, showDialog)
    } catch (error) {
      pollError.value = errorMessage(error, '任务进度查询暂时失败')
      throw error
    } finally {
      statusLoading.value = false
    }
  }

  const refreshJob = async () => {
    if (!job.value) return null
    if (!isTerminal.value) polling.value = true
    return pollCurrentJob()
  }

  const openDialog = () => {
    if (job.value) dialogVisible.value = true
  }

  const closeDialog = () => {
    dialogVisible.value = false
  }

  const clearJob = () => {
    stopPolling()
    job.value = null
    createError.value = ''
    pollError.value = ''
    consecutivePollErrors = 0
    finishedNotificationKey = ''
  }

  onScopeDispose(() => {
    disposed = true
    stopPolling()
  })

  return {
    job,
    dialogVisible,
    creating,
    polling,
    statusLoading,
    createError,
    pollError,
    progress,
    isTerminal,
    isActive,
    hasFailures,
    createJob,
    trackJob,
    resumeJob,
    refreshJob,
    openDialog,
    closeDialog,
    stopPolling,
    clearJob
  }
}
