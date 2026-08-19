import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const api = readFileSync(resolve(__dirname, '../src/api/caseFund.ts'), 'utf8')
const types = readFileSync(resolve(__dirname, '../src/types/api.ts'), 'utf8')
const composable = readFileSync(resolve(__dirname, '../src/composables/useCaseFundJob.ts'), 'utf8')
const dialog = readFileSync(resolve(__dirname, '../src/components/case-fund/CaseFundJobProgressDialog.vue'), 'utf8')

for (const action of ['jobCreate', 'jobStatus', 'jobList', 'jobErrorList']) {
  assert(api.includes(`apiAction('/caseFund/${action}'`), `case fund API should expose /caseFund/${action}`)
}
assert(api.includes("apiAction('/caseFund/jobCreate', request, {}, { timeout: 120000 })"), 'job creation should allow enough time to upload an import file')

assert(types.includes('export interface CaseFundJobCreateRequest'), 'async job creation payload should have a shared type')
assert(types.includes('filename?: string'), 'import jobs should accept the original file name at job creation')
assert(types.includes('content_base64?: string'), 'import jobs should accept file content at job creation')
assert(types.includes('client_request_id: string'), 'async job creation should require an idempotency request id')
assert(types.includes('progress_percent?: number'), 'job status should expose persisted progress')
assert(types.includes('failure_count?: number'), 'job status should expose failed item count')
for (const field of ['account_set_id?: string', 'fiscal_year?: number', 'period?: string', 'source_file_name?: string']) {
  assert(types.includes(field), `job status should expose ${field}`)
}

for (const status of ['QUEUED', 'RUNNING', 'SUCCEEDED', 'PARTIAL_FAILED', 'FAILED']) {
  assert(types.includes(`'${status}'`), `job types should support ${status}`)
  assert(dialog.includes(`${status}:`), `progress dialog should render ${status}`)
}

assert(composable.includes('caseFundApi.jobStatus(trackedJobId)'), 'job composable should poll the persisted backend status')
assert(composable.includes('consecutivePollErrors += 1'), 'temporary polling errors should use retry backoff')
assert(composable.includes('schedulePoll(() => { void pollCurrentJob() })'), 'polling should continue after a temporary status error')
assert(!composable.includes("job.value.status = 'FAILED'"), 'a polling request error must not mark the backend job failed')
assert(composable.includes('recoverCreatedJob'), 'an uncertain create response should be recovered by client request id')
assert(composable.includes('job_type: request.job_type'), 'create-response recovery should filter by job type')
assert(composable.includes('item.client_request_id === request.client_request_id'), 'create-response recovery should match the client request id')
assert(composable.includes('item.job_type === request.job_type'), 'create-response recovery should also match the job type')
assert(dialog.includes('后台任务可能仍在继续，请勿重复提交'), 'progress dialog should explain uncertain polling results')
assert(dialog.includes('关闭此窗口不会中止后台任务'), 'progress dialog should explain background execution')
assert(dialog.includes("emit('view-errors')"), 'failed job items should expose the error-detail action')
assert(types.includes("| 'MATCHING'"), 'job stages should include automatic matching')
assert(dialog.includes("MATCHING: '正在自动匹配'"), 'progress dialog should render the automatic matching stage')
assert(dialog.includes("props.job?.status === 'FAILED'"), 'a failed job should allow opening its error details even without a failed item count')

console.log('Case fund async job foundation test passed')
