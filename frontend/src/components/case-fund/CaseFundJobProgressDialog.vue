<template>
  <el-dialog
    :model-value="modelValue"
    :title="title"
    width="560px"
    class="finance-dialog case-fund-job-dialog"
    :close-on-click-modal="false"
    append-to-body
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div v-if="job" class="job-progress-content">
      <div class="job-status-row">
        <div>
          <strong>{{ jobTypeLabel || job.job_type }}</strong>
          <p>任务编号：{{ job.job_id }}</p>
        </div>
        <el-tag :type="statusTagType" effect="light">{{ statusLabel }}</el-tag>
      </div>

      <div class="job-stage-row">
        <span>{{ stageLabel }}</span>
        <span>{{ progress }}%</span>
      </div>
      <el-progress
        :percentage="progress"
        :status="progressStatus"
        :stroke-width="12"
        :show-text="false"
      />
      <p class="job-message">{{ job.message || defaultStatusMessage }}</p>

      <el-alert
        v-if="job.launcher_warning"
        title="后台工作进程未能自动启动"
        :description="job.launcher_warning + '。任务仍保留在队列中，请联系管理员检查后台计划任务。'"
        type="warning"
        show-icon
        :closable="false"
      />
      <el-alert
        v-else-if="pollError"
        title="进度查询暂时失败，后台任务可能仍在继续，请勿重复提交。"
        :description="pollError"
        type="warning"
        show-icon
        :closable="false"
      />
      <el-alert
        v-else-if="job.status === 'FAILED'"
        title="后台任务执行失败"
        :description="job.error_message || '请查看任务错误明细后再处理。'"
        type="error"
        show-icon
        :closable="false"
      />
      <el-alert
        v-else-if="job.status === 'PARTIAL_FAILED'"
        title="任务已完成，但有部分数据处理失败"
        :description="job.error_message || '请查看错误明细，确认失败数据后再按需处理。'"
        type="warning"
        show-icon
        :closable="false"
      />

      <div class="job-stat-grid">
        <div>
          <span>任务总数</span>
          <strong>{{ numberText(job.total_count) }}</strong>
        </div>
        <div>
          <span>已处理</span>
          <strong>{{ numberText(job.processed_count) }}</strong>
        </div>
        <div>
          <span>成功</span>
          <strong class="success-text">{{ numberText(job.success_count) }}</strong>
        </div>
        <div>
          <span>失败</span>
          <strong :class="{ 'danger-text': failureCount > 0 }">{{ failureCount }}</strong>
        </div>
      </div>

      <div class="job-time-row">
        <span v-if="job.started_time">开始时间：{{ job.started_time }}</span>
        <span v-if="job.finished_time">完成时间：{{ job.finished_time }}</span>
      </div>
      <p v-if="!isTerminal" class="background-tip">关闭此窗口不会中止后台任务，可稍后重新查看进度。</p>
    </div>
    <el-empty v-else description="暂无任务信息" :image-size="72" />

    <template #footer>
      <el-button v-if="canViewErrors" type="danger" plain @click="emit('view-errors')">查看错误明细</el-button>
      <el-button :loading="statusLoading" :disabled="!job" @click="emit('refresh')">刷新状态</el-button>
      <el-button type="primary" @click="emit('update:modelValue', false)">{{ closeButtonText }}</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { CaseFundJob } from '../../types/api'
import { getCaseFundJobProgress, isCaseFundJobTerminal } from '../../composables/useCaseFundJob'

type TagType = 'primary' | 'success' | 'warning' | 'info' | 'danger'
type ProgressStatus = 'success' | 'warning' | 'exception' | undefined

const props = withDefaults(defineProps<{
  modelValue: boolean
  job?: CaseFundJob | null
  title?: string
  jobTypeLabel?: string
  pollError?: string
  statusLoading?: boolean
}>(), {
  job: null,
  title: '后台任务进度',
  jobTypeLabel: '',
  pollError: '',
  statusLoading: false
})

const emit = defineEmits<{
  (event: 'update:modelValue', value: boolean): void
  (event: 'refresh'): void
  (event: 'view-errors'): void
}>()

const statusLabels: Record<string, string> = {
  QUEUED: '排队中',
  RUNNING: '执行中',
  SUCCEEDED: '已完成',
  PARTIAL_FAILED: '部分失败',
  FAILED: '执行失败'
}

const stageLabels: Record<string, string> = {
  QUEUED: '等待后台执行',
  PARSING: '正在解析文件',
  VALIDATING: '正在校验数据',
  MATCHING: '正在自动匹配',
  WRITING: '正在写入业务数据',
  GENERATING: '正在生成凭证',
  FINALIZING: '正在确认处理结果'
}

const progress = computed(() => getCaseFundJobProgress(props.job))
const failureCount = computed(() => Number(props.job?.failure_count || 0))
const isTerminal = computed(() => isCaseFundJobTerminal(props.job?.status))
const canViewErrors = computed(() => failureCount.value > 0 || props.job?.status === 'FAILED' || props.job?.status === 'PARTIAL_FAILED')
const statusLabel = computed(() => statusLabels[props.job?.status || ''] || props.job?.status || '等待任务')
const stageLabel = computed(() => {
  if (!props.job) return '等待任务'
  return stageLabels[props.job.stage || ''] || props.job.stage || statusLabel.value
})
const statusTagType = computed<TagType>(() => {
  if (props.job?.status === 'SUCCEEDED') return 'success'
  if (props.job?.status === 'PARTIAL_FAILED') return 'warning'
  if (props.job?.status === 'FAILED') return 'danger'
  if (props.job?.status === 'RUNNING') return 'primary'
  return 'info'
})
const progressStatus = computed<ProgressStatus>(() => {
  if (props.job?.status === 'SUCCEEDED') return 'success'
  if (props.job?.status === 'PARTIAL_FAILED') return 'warning'
  if (props.job?.status === 'FAILED') return 'exception'
  return undefined
})
const defaultStatusMessage = computed(() => {
  if (props.job?.status === 'QUEUED') return '任务已提交，正在等待后台处理。'
  if (props.job?.status === 'RUNNING') return '后台正在处理，请勿重复提交。'
  if (props.job?.status === 'SUCCEEDED') return '后台任务已经处理完成。'
  if (props.job?.status === 'PARTIAL_FAILED') return '任务已完成，请核对失败数据。'
  if (props.job?.status === 'FAILED') return '任务执行失败，请查看错误信息。'
  return '正在获取任务状态。'
})
const closeButtonText = computed(() => isTerminal.value ? '完成' : '转到后台')

const numberText = (value?: number) => Number.isFinite(Number(value)) ? Number(value) : 0
</script>

<style scoped>
.job-progress-content {
  display: grid;
  gap: 14px;
  padding: 2px 0;
}

.job-status-row,
.job-stage-row,
.job-time-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.job-status-row strong {
  color: var(--text-main);
  font-size: 16px;
  font-weight: 650;
}

.job-status-row p,
.job-message,
.background-tip {
  margin: 4px 0 0;
  color: var(--text-secondary);
  font-size: 13px;
  line-height: 1.6;
}

.job-status-row p {
  word-break: break-all;
}

.job-stage-row {
  color: var(--text-regular);
  font-size: 13px;
  font-variant-numeric: tabular-nums;
}

.job-message {
  margin-top: -4px;
}

.job-stat-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
}

.job-stat-grid > div {
  min-width: 0;
  padding: 12px;
  border: 1px solid var(--border-light);
  border-radius: 10px;
  background: var(--bg-main);
}

.job-stat-grid span {
  display: block;
  color: var(--text-secondary);
  font-size: 12px;
}

.job-stat-grid strong {
  display: block;
  margin-top: 5px;
  color: var(--text-main);
  font-size: 20px;
  font-variant-numeric: tabular-nums;
}

.job-stat-grid .success-text {
  color: var(--brand-success);
}

.job-stat-grid .danger-text {
  color: var(--brand-danger);
}

.job-time-row {
  flex-wrap: wrap;
  color: var(--text-secondary);
  font-size: 12px;
}

.background-tip {
  margin-top: -4px;
  padding: 9px 12px;
  border-radius: 8px;
  background: var(--bg-soft);
}

@media (max-width: 640px) {
  .job-stat-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
