<?php

namespace app\finance\service\job;

use think\Db;

/**
 * 数据库持久化财务异步任务服务。
 *
 * 业务 handler 负责正式财务业务事务和审计；本服务只负责任务控制状态、
 * worker 所有权、进度以及任务明细。所有 RUNNING 状态写入都必须带
 * worker_token，防止失去执行权的 worker 覆盖新 worker 的结果。
 */
class FinanceAsyncJobService
{
    const JOB_STATUSES = ['QUEUED', 'RUNNING', 'SUCCEEDED', 'PARTIAL_FAILED', 'FAILED'];
    const TERMINAL_STATUSES = ['SUCCEEDED', 'PARTIAL_FAILED', 'FAILED'];
    const COMPLETION_STATUSES = ['SUCCEEDED', 'PARTIAL_FAILED'];
    const ITEM_STATUSES = ['QUEUED', 'RUNNING', 'SUCCEEDED', 'FAILED', 'SKIPPED'];
    // Handler 仅在业务数据与任务成功终态已经由同一数据库事务提交时返回此标记。
    // Worker 收到后只确认终态，不再执行第二次 complete()。
    const ATOMIC_COMPLETION_KEY = '_job_atomic_completion';

    protected $hooks;
    protected $tables;

    public function __construct(array $hooks, array $tables = [])
    {
        foreach (['get_db', 'transaction', 'new_id', 'now'] as $name) {
            if (!isset($hooks[$name]) || !is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('异步任务服务缺少回调：' . $name);
            }
        }
        $tables = array_merge([
            'job' => 'fin_async_job',
            'item' => 'fin_async_job_item',
        ], $tables);
        foreach (['job', 'item'] as $name) {
            if (trim((string)$tables[$name]) === '') {
                throw new \InvalidArgumentException('异步任务服务缺少数据表：' . $name);
            }
        }
        $this->hooks = $hooks;
        $this->tables = $tables;
    }

    /**
     * ThinkPHP 运行环境默认工厂。测试可通过构造函数注入内存数据库替身。
     */
    public static function createDefault()
    {
        return new self([
            'get_db' => function ($table) {
                return db($table);
            },
            'transaction' => function ($callback) {
                return Db::transaction($callback);
            },
            'new_id' => function () {
                return uuid();
            },
            'now' => function () {
                return getNowTime();
            },
        ]);
    }

    /**
     * 幂等创建任务。
     *
     * 返回：['created' => bool, 'job' => array]。同一账套、任务类型和
     * client_request_id 永远返回同一任务；唯一键竞争时重新读取已存在任务。
     */
    public function create(array $data)
    {
        $accountSetId = $this->requiredText($data, 'account_set_id', '账套ID');
        $jobType = $this->requiredText($data, 'job_type', '任务类型');
        $clientRequestId = $this->requiredText($data, 'client_request_id', '客户端请求ID');
        $fiscalYear = (int)($data['fiscal_year'] ?? 0);
        $period = trim((string)($data['period'] ?? ''));
        if ($fiscalYear <= 0) {
            throw new \InvalidArgumentException('任务会计年度不能为空');
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $period) || (int)substr($period, 0, 4) !== $fiscalYear) {
            throw new \InvalidArgumentException('任务会计期间格式不正确或与年度不一致');
        }
        if (strlen($jobType) > 50 || strlen($clientRequestId) > 64) {
            throw new \InvalidArgumentException('任务类型或客户端请求ID长度超限');
        }

        $existing = $this->findByClientRequestId($accountSetId, $jobType, $clientRequestId, true);
        if ($existing) {
            return ['created' => false, 'job' => $existing];
        }

        $now = $this->now();
        $operatorId = trim((string)($data['created_by'] ?? 'system')) ?: 'system';
        $sourceFileHash = $this->nullableText($data['source_file_hash'] ?? null);
        if ($sourceFileHash !== null && !preg_match('/^[a-fA-F0-9]{64}$/', $sourceFileHash)) {
            throw new \InvalidArgumentException('任务源文件哈希必须是 SHA-256');
        }
        $row = [
            'job_id' => $this->newId(),
            'account_set_id' => $accountSetId,
            'fiscal_year' => $fiscalYear,
            'period' => $period,
            'job_type' => $jobType,
            'client_request_id' => $clientRequestId,
            'status' => 'QUEUED',
            'stage' => $this->limitText(trim((string)($data['stage'] ?? 'QUEUED')) ?: 'QUEUED', 30),
            'payload_json' => $this->encodeJson($data['payload'] ?? []),
            'result_json' => null,
            'source_file_name' => $this->limitNullableText($data['source_file_name'] ?? null, 255),
            'source_file_path' => $this->limitNullableText($data['source_file_path'] ?? null, 1000),
            'source_file_hash' => $sourceFileHash,
            'total_count' => max(0, (int)($data['total_count'] ?? 0)),
            'processed_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'progress_percent' => 0,
            'message' => $this->limitText(trim((string)($data['message'] ?? '任务已进入队列')), 500),
            'error_message' => null,
            'worker_token' => null,
            'attempt_count' => 0,
            'queued_time' => $now,
            'started_time' => null,
            'heartbeat_time' => null,
            'finished_time' => null,
            'created_by' => $operatorId,
            'created_time' => $now,
            'updated_by' => $operatorId,
            'updated_time' => $now,
            'del_flag' => 0,
            'version' => 0,
            'remark' => $this->limitNullableText($data['remark'] ?? null, 500),
        ];

        try {
            $inserted = $this->db($this->tables['job'])->insert($row);
            if ((int)$inserted !== 1) {
                throw new \RuntimeException('异步任务写入失败');
            }
        } catch (\Throwable $e) {
            // 唯一键并发竞争应返回先创建的任务；其他异常继续抛出。
            $existing = $this->findByClientRequestId($accountSetId, $jobType, $clientRequestId, true);
            if ($existing) {
                return ['created' => false, 'job' => $existing];
            }
            throw $e;
        }

        return ['created' => true, 'job' => $this->normalizeJob($row)];
    }

    public function findById($jobId, $accountSetId = '')
    {
        $where = [
            'job_id' => trim((string)$jobId),
            'del_flag' => 0,
        ];
        if (trim((string)$accountSetId) !== '') {
            $where['account_set_id'] = trim((string)$accountSetId);
        }
        $row = $this->db($this->tables['job'])->where($where)->find();
        return $row ? $this->normalizeJob($row) : null;
    }

    public function findByClientRequestId($accountSetId, $jobType, $clientRequestId, $includeDeleted = false)
    {
        $where = [
            'account_set_id' => trim((string)$accountSetId),
            'job_type' => trim((string)$jobType),
            'client_request_id' => trim((string)$clientRequestId),
        ];
        if (!$includeDeleted) {
            $where['del_flag'] = 0;
        }
        $row = $this->db($this->tables['job'])->where($where)->find();
        return $row ? $this->normalizeJob($row) : null;
    }

    /**
     * 原子认领一项任务。默认只认领 QUEUED；staleSeconds > 0 时，才允许
     * 接管超过阈值无心跳的 RUNNING 任务。
     */
    public function claimNext($workerToken, array $jobTypes = [], $staleSeconds = 0)
    {
        $workerToken = trim((string)$workerToken);
        if ($workerToken === '' || strlen($workerToken) > 100) {
            throw new \InvalidArgumentException('worker token 不能为空且不能超过100字符');
        }
        $jobTypes = $this->cleanTextList($jobTypes);
        $staleSeconds = max(0, (int)$staleSeconds);

        return $this->transaction(function () use ($workerToken, $jobTypes, $staleSeconds) {
            $row = $this->claimCandidate('QUEUED', $jobTypes, 'queued_time asc,job_id asc');
            $isStaleTakeover = false;
            if (!$row && $staleSeconds > 0) {
                $cutoff = date('Y-m-d H:i:s', strtotime($this->now()) - $staleSeconds);
                $query = $this->db($this->tables['job'])->where([
                    'status' => 'RUNNING',
                    'del_flag' => 0,
                ])->where('heartbeat_time', '<=', $cutoff);
                if (!empty($jobTypes)) {
                    $query->where('job_type', 'in', $jobTypes);
                }
                $row = $query->order('heartbeat_time asc,job_id asc')->lock(true)->find();
                $isStaleTakeover = (bool)$row;
            }
            if (!$row) {
                return null;
            }

            $now = $this->now();
            $version = (int)($row['version'] ?? 0);
            $where = [
                'job_id' => $row['job_id'],
                'status' => $row['status'],
                'del_flag' => 0,
                'version' => $version,
            ];
            if ($isStaleTakeover) {
                $where['worker_token'] = $row['worker_token'] ?? null;
                $where['heartbeat_time'] = $row['heartbeat_time'] ?? null;
            }
            $update = [
                'status' => 'RUNNING',
                'stage' => 'STARTING',
                'worker_token' => $workerToken,
                'attempt_count' => (int)($row['attempt_count'] ?? 0) + 1,
                'started_time' => empty($row['started_time']) ? $now : $row['started_time'],
                'heartbeat_time' => $now,
                'finished_time' => null,
                'message' => $isStaleTakeover ? '任务由新工作进程接管' : '任务开始处理',
                'error_message' => null,
                'updated_by' => 'worker',
                'updated_time' => $now,
                'version' => $version + 1,
            ];
            $affected = $this->db($this->tables['job'])->where($where)->update($update);
            if ((int)$affected !== 1) {
                return null;
            }
            return $this->normalizeJob(array_merge($row, $update));
        });
    }

    public function heartbeat($jobId, $workerToken, $stage = '', $message = '')
    {
        return $this->mutateOwnedRunningJob($jobId, $workerToken, function ($row) use ($stage, $message) {
            $update = [];
            if (trim((string)$stage) !== '') {
                $update['stage'] = $this->limitText(trim((string)$stage), 30);
            }
            if (trim((string)$message) !== '') {
                $update['message'] = $this->limitText(trim((string)$message), 500);
            }
            return $update;
        });
    }

    /**
     * 必须在正式财务业务事务已经开启后调用。锁住任务头并刷新心跳，
     * 使 stale 接管在当前凭证/导入事务提交或回滚前无法替换 worker token。
     */
    public function fenceBusinessTransaction($jobId, $workerToken, $stage = '', $message = '')
    {
        $row = $this->findOwnedRunningRow($jobId, $workerToken);
        $now = $this->now();
        $update = [
            'heartbeat_time' => $now,
            'updated_by' => 'worker',
            'updated_time' => $now,
            'version' => (int)$row['version'] + 1,
        ];
        if (trim((string)$stage) !== '') {
            $update['stage'] = $this->limitText(trim((string)$stage), 30);
        }
        if (trim((string)$message) !== '') {
            $update['message'] = $this->limitText(trim((string)$message), 500);
        }
        $this->assertOwnedUpdate($row, $workerToken, $update);
        return $this->normalizeJob(array_merge($row, $update));
    }

    /**
     * 后台进程启动失败时，把可操作提示持久化在仍处于 QUEUED 的任务上。
     * 若任务已被 worker 认领，仅返回最新状态，不覆盖 RUNNING 信息。
     */
    public function noteQueuedLaunchWarning($jobId, $message)
    {
        $jobId = trim((string)$jobId);
        $message = $this->limitText(trim((string)$message), 500);
        if ($jobId === '' || $message === '') {
            throw new \InvalidArgumentException('任务ID和启动告警不能为空');
        }
        return $this->transaction(function () use ($jobId, $message) {
            $row = $this->db($this->tables['job'])->where([
                'job_id' => $jobId,
                'del_flag' => 0,
            ])->lock(true)->find();
            if (!$row) {
                throw new \RuntimeException('异步任务不存在');
            }
            if (($row['status'] ?? '') !== 'QUEUED') {
                return $this->normalizeJob($row);
            }
            $update = [
                'message' => $message,
                'updated_by' => 'system',
                'updated_time' => $this->now(),
                'version' => (int)($row['version'] ?? 0) + 1,
            ];
            $affected = $this->db($this->tables['job'])->where([
                'job_id' => $jobId,
                'status' => 'QUEUED',
                'del_flag' => 0,
                'version' => (int)($row['version'] ?? 0),
            ])->update($update);
            if ((int)$affected !== 1) {
                $latest = $this->db($this->tables['job'])->where([
                    'job_id' => $jobId,
                    'del_flag' => 0,
                ])->find();
                return $this->normalizeJob($latest ?: $row);
            }
            return $this->normalizeJob(array_merge($row, $update));
        });
    }

    /**
     * 进度字段支持 *_count，也兼容 total/processed/success/failure/progress。
     * RUNNING 阶段进度最高为 99.99，只有 complete() 才写入 100。
     */
    public function updateProgress($jobId, $workerToken, array $progress)
    {
        return $this->mutateOwnedRunningJob($jobId, $workerToken, function ($row) use ($progress) {
            $replaceCounts = !empty($progress['replace_counts']);
            $total = $this->progressNumber($progress, 'total_count', 'total', $row['total_count'] ?? 0);
            $processed = $this->progressNumber($progress, 'processed_count', 'processed', $row['processed_count'] ?? 0);
            $success = $this->progressNumber($progress, 'success_count', 'success', $row['success_count'] ?? 0);
            $failure = $this->progressNumber($progress, 'failure_count', 'failure', $row['failure_count'] ?? 0);

            if ($replaceCounts) {
                // stale 接管后的重试可按任务明细重新汇总，清除上一轮遗留失败数。
                $processed = max($processed, $success + $failure);
            } else {
                $total = max((int)($row['total_count'] ?? 0), $total);
                $processed = max((int)($row['processed_count'] ?? 0), $processed, $success + $failure);
                $success = max((int)($row['success_count'] ?? 0), $success);
                $failure = max((int)($row['failure_count'] ?? 0), $failure);
            }
            if ($processed > $total) {
                $total = $processed;
            }

            if (array_key_exists('progress_percent', $progress)) {
                $percent = (float)$progress['progress_percent'];
            } elseif (array_key_exists('progress', $progress)) {
                $percent = (float)$progress['progress'];
            } elseif ($total > 0) {
                $percent = $processed * 100 / $total;
            } else {
                $percent = (float)($row['progress_percent'] ?? 0);
            }
            $percent = min(99.99, max(0, $percent));
            if (!$replaceCounts) {
                $percent = max((float)($row['progress_percent'] ?? 0), $percent);
            }

            $update = [
                'total_count' => $total,
                'processed_count' => $processed,
                'success_count' => $success,
                'failure_count' => $failure,
                'progress_percent' => number_format($percent, 2, '.', ''),
            ];
            if (trim((string)($progress['stage'] ?? '')) !== '') {
                $update['stage'] = $this->limitText(trim((string)$progress['stage']), 30);
            }
            if (trim((string)($progress['message'] ?? '')) !== '') {
                $update['message'] = $this->limitText(trim((string)$progress['message']), 500);
            }
            return $update;
        });
    }

    public function complete($jobId, $workerToken, array $result = [], $status = '')
    {
        return $this->transaction(function () use ($jobId, $workerToken, $result, $status) {
            return $this->completeOwnedRunningJob($jobId, $workerToken, $result, $status);
        });
    }

    /**
     * 在调用方已经开启的正式业务事务内写入成功终态。
     *
     * 本方法故意不调用 transaction()：ThinkPHP 5 使用 transTimes 管理嵌套事务
     * 层级，内层 commit 不构成独立的 durable commit，最终原子性仍取决于调用方
     * 的最外层 commit。调用方必须保证任务表与业务表使用同一默认数据库连接。
     */
    public function completeInBusinessTransaction($jobId, $workerToken, array $result = [], $status = '')
    {
        return $this->completeOwnedRunningJob($jobId, $workerToken, $result, $status);
    }

    /**
     * 读取由指定 worker 原子提交的成功终态。返回 null 表示任务尚未成功收口、
     * 已被其他 worker 接管，或仍处于 FAILED 等非成功终态。
     */
    public function completedByWorker($jobId, $workerToken)
    {
        $jobId = trim((string)$jobId);
        $workerToken = trim((string)$workerToken);
        if ($jobId === '' || $workerToken === '') {
            return null;
        }
        $row = $this->db($this->tables['job'])->where([
            'job_id' => $jobId,
            'worker_token' => $workerToken,
            'del_flag' => 0,
        ])->find();
        if (!$row || !in_array($row['status'] ?? '', self::COMPLETION_STATUSES, true)) {
            return null;
        }
        return $this->normalizeJob($row);
    }

    public function assertCompletedByWorker($jobId, $workerToken, $expectedStatus = '')
    {
        $row = $this->completedByWorker($jobId, $workerToken);
        if (!$row) {
            throw new \RuntimeException('任务未持久化原子完成终态，禁止跳过 worker 收口');
        }
        $expectedStatus = trim((string)$expectedStatus);
        if ($expectedStatus !== '' && ($row['status'] ?? '') !== $expectedStatus) {
            throw new \RuntimeException('任务原子完成状态与 handler 声明不一致');
        }
        return $row;
    }

    protected function completeOwnedRunningJob($jobId, $workerToken, array $result, $status)
    {
        $row = $this->findOwnedRunningRow($jobId, $workerToken);
        $resultCounts = $this->completionCounts($result);
        $total = array_key_exists('total_count', $resultCounts)
            ? $resultCounts['total_count']
            : max(0, (int)($row['total_count'] ?? 0));
        $success = array_key_exists('success_count', $resultCounts)
            ? $resultCounts['success_count']
            : max(0, (int)($row['success_count'] ?? 0));
        $failure = array_key_exists('failure_count', $resultCounts)
            ? $resultCounts['failure_count']
            : max(0, (int)($row['failure_count'] ?? 0));
        $processed = array_key_exists('processed_count', $resultCounts)
            ? $resultCounts['processed_count']
            : max(0, (int)($row['processed_count'] ?? 0));
        $status = trim((string)$status);
        if ($status === '') {
            $status = $failure > 0 ? 'PARTIAL_FAILED' : 'SUCCEEDED';
        }
        if (!in_array($status, self::COMPLETION_STATUSES, true)) {
            throw new \InvalidArgumentException('任务完成状态不支持：' . $status);
        }
        if ($status === 'SUCCEEDED') {
            // 成功重试必须清除旧 attempt 遗留的 failure_count。
            $failure = 0;
        }
        $now = $this->now();
        $processed = max($processed, $success + $failure, $total);
        $total = max($total, $processed);
        $update = [
            'status' => $status,
            'stage' => 'COMPLETED',
            'result_json' => $this->encodeJson($result),
            'total_count' => $total,
            'processed_count' => $processed,
            'success_count' => $success,
            'failure_count' => $failure,
            'progress_percent' => '100.00',
            'message' => $this->limitText(
                trim((string)($result['message'] ?? '')) ?: ($status === 'SUCCEEDED' ? '任务处理完成' : '任务部分处理失败'),
                500
            ),
            'error_message' => $status === 'PARTIAL_FAILED'
                ? $this->limitNullableText($result['error_message'] ?? ($row['error_message'] ?? null), 2000)
                : null,
            'heartbeat_time' => $now,
            'finished_time' => $now,
            'updated_by' => 'worker',
            'updated_time' => $now,
            'version' => (int)$row['version'] + 1,
        ];
        $this->assertOwnedUpdate($row, $workerToken, $update);
        return $this->normalizeJob(array_merge($row, $update));
    }

    public function fail($jobId, $workerToken, $errorMessage, array $result = [])
    {
        $errorMessage = trim((string)$errorMessage);
        if ($errorMessage === '') {
            $errorMessage = '后台任务执行失败';
        }
        $errorMessage = $this->limitText($errorMessage, 2000);
        return $this->transaction(function () use ($jobId, $workerToken, $errorMessage, $result) {
            $row = $this->findOwnedRunningRow($jobId, $workerToken);
            $now = $this->now();
            $success = max(0, (int)($row['success_count'] ?? 0));
            $failure = max(1, (int)($row['failure_count'] ?? 0));
            $processed = max(
                (int)($row['processed_count'] ?? 0),
                $success + $failure
            );
            $total = max(1, (int)($row['total_count'] ?? 0), $processed);
            $update = [
                'status' => 'FAILED',
                'stage' => 'FAILED',
                'result_json' => $this->encodeJson($result),
                'total_count' => $total,
                'processed_count' => $processed,
                'success_count' => $success,
                'failure_count' => $failure,
                'progress_percent' => '100.00',
                'message' => '任务处理失败',
                'error_message' => $errorMessage,
                'heartbeat_time' => $now,
                'finished_time' => $now,
                'updated_by' => 'worker',
                'updated_time' => $now,
                'version' => (int)$row['version'] + 1,
            ];
            $this->assertOwnedUpdate($row, $workerToken, $update);
            return $this->normalizeJob(array_merge($row, $update));
        });
    }

    public function listJobs(array $filters, $page = 1, $pageSize = 20)
    {
        $accountSetId = $this->requiredText($filters, 'account_set_id', '账套ID');
        $page = max(1, (int)$page);
        $pageSize = max(1, min(200, (int)$pageSize));
        $build = function () use ($filters, $accountSetId) {
            $query = $this->db($this->tables['job'])->where([
                'account_set_id' => $accountSetId,
                'del_flag' => 0,
            ]);
            foreach (['fiscal_year', 'period', 'job_type', 'status', 'created_by', 'client_request_id'] as $field) {
                if (trim((string)($filters[$field] ?? '')) !== '') {
                    $query->where($field, $filters[$field]);
                }
            }
            return $query;
        };
        $total = (int)$build()->count();
        $rows = $build()->order('created_time desc,job_id desc')->page($page, $pageSize)->select();
        return [
            'items' => array_map([$this, 'normalizeJob'], $rows ?: []),
            'total' => $total,
            'page' => $page,
            'pagesize' => $pageSize,
        ];
    }

    public function list(array $filters, $page = 1, $pageSize = 20)
    {
        return $this->listJobs($filters, $page, $pageSize);
    }

    public function addItem(array $data)
    {
        $jobId = $this->requiredText($data, 'job_id', '任务ID');
        $sourceType = $this->requiredText($data, 'source_type', '任务明细来源类型');
        $sourceId = $this->requiredText($data, 'source_id', '任务明细来源ID');
        if (strlen($sourceType) > 50 || strlen($sourceId) > 36) {
            throw new \InvalidArgumentException('任务明细来源类型或来源ID长度超限');
        }
        $job = $this->findById($jobId, (string)($data['account_set_id'] ?? ''));
        if (!$job) {
            throw new \RuntimeException('异步任务不存在');
        }
        $existing = $this->findItemBySource($jobId, $sourceType, $sourceId);
        if ($existing) {
            return ['created' => false, 'item' => $existing];
        }
        $itemNo = (int)($data['item_no'] ?? 0);
        if ($itemNo <= 0) {
            throw new \InvalidArgumentException('任务明细序号必须大于0');
        }
        $status = trim((string)($data['status'] ?? 'QUEUED')) ?: 'QUEUED';
        if (!in_array($status, self::ITEM_STATUSES, true)) {
            throw new \InvalidArgumentException('任务明细状态不支持：' . $status);
        }
        $now = $this->now();
        $operatorId = trim((string)($data['created_by'] ?? ($job['created_by'] ?? 'system'))) ?: 'system';
        $row = [
            'item_id' => $this->newId(),
            'job_id' => $jobId,
            'account_set_id' => $job['account_set_id'],
            'fiscal_year' => (int)$job['fiscal_year'],
            'period' => $job['period'],
            'item_no' => $itemNo,
            'source_row_no' => isset($data['source_row_no']) ? (int)$data['source_row_no'] : null,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'group_key' => $this->limitNullableText($data['group_key'] ?? null, 255),
            'amount' => $this->normalizeAmount($data['amount'] ?? 0),
            'status' => $status,
            'result_json' => array_key_exists('result', $data) ? $this->encodeJson($data['result']) : null,
            'error_code' => $this->limitNullableText($data['error_code'] ?? null, 100),
            'error_message' => $this->limitNullableText($data['error_message'] ?? null, 2000),
            'voucher_id' => $this->nullableText($data['voucher_id'] ?? null),
            'voucher_no' => isset($data['voucher_no']) ? (int)$data['voucher_no'] : null,
            'voucher_period' => $this->nullableText($data['voucher_period'] ?? null),
            'started_time' => $this->nullableText($data['started_time'] ?? null),
            'finished_time' => $this->nullableText($data['finished_time'] ?? null),
            'created_by' => $operatorId,
            'created_time' => $now,
            'updated_by' => $operatorId,
            'updated_time' => $now,
            'del_flag' => 0,
            'version' => 0,
            'remark' => $this->limitNullableText($data['remark'] ?? null, 500),
        ];
        try {
            $inserted = $this->db($this->tables['item'])->insert($row);
            if ((int)$inserted !== 1) {
                throw new \RuntimeException('异步任务明细写入失败');
            }
        } catch (\Throwable $e) {
            $existing = $this->findItemBySource($jobId, $sourceType, $sourceId);
            if ($existing) {
                return ['created' => false, 'item' => $existing];
            }
            throw $e;
        }
        return ['created' => true, 'item' => $this->normalizeItem($row)];
    }

    /**
     * 批量冻结任务明细。每批使用一条 insertAll，默认200行；每个短事务都会
     * 重新锁定任务头并验证 account_set_id + RUNNING + worker_token fence。
     * 重试时按 (job_id, source_type, source_id) 跳过已存在明细。
     */
    public function batchAddItems(array $items, $jobId, $accountSetId, $workerToken, $chunkSize = 200)
    {
        $jobId = trim((string)$jobId);
        $accountSetId = trim((string)$accountSetId);
        $workerToken = trim((string)$workerToken);
        if ($jobId === '' || $accountSetId === '' || $workerToken === '') {
            throw new \InvalidArgumentException('批量任务明细的任务ID、账套ID和 worker token 不能为空');
        }
        $chunkSize = max(1, min(500, (int)$chunkSize));
        $job = $this->findOwnedRunningRowInAccount($jobId, $accountSetId, $workerToken, false);
        if (empty($items)) {
            return ['created' => 0, 'skipped' => 0, 'total' => 0, 'items' => []];
        }

        // 先完整校验，任何格式错误都发生在第一条 insertAll 之前。
        $now = $this->now();
        $candidateRows = [];
        $orderedKeys = [];
        $duplicateCount = 0;
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('任务明细第' . ($index + 1) . '项格式不正确');
            }
            $row = $this->buildBatchItemRow($item, $job, $now);
            $sourceKey = $this->itemSourceKey($row['source_type'], $row['source_id']);
            if (isset($candidateRows[$sourceKey])) {
                $duplicateCount++;
                continue;
            }
            $candidateRows[$sourceKey] = $row;
            $orderedKeys[] = $sourceKey;
        }

        $existing = $this->loadExistingBatchItems($jobId, $accountSetId, array_values($candidateRows));
        $insertRows = [];
        $itemRefs = [];
        $skipped = $duplicateCount;
        foreach ($orderedKeys as $sourceKey) {
            if (isset($existing[$sourceKey])) {
                $skipped++;
                $itemRefs[] = $this->batchItemReference($existing[$sourceKey], false);
                continue;
            }
            $insertRows[] = $candidateRows[$sourceKey];
            $itemRefs[] = $this->batchItemReference($candidateRows[$sourceKey], true);
        }

        $created = 0;
        foreach (array_chunk($insertRows, $chunkSize) as $chunk) {
            $affected = $this->transaction(function () use ($jobId, $accountSetId, $workerToken, $chunk) {
                $this->findOwnedRunningRowInAccount($jobId, $accountSetId, $workerToken, true);
                return $this->db($this->tables['item'])->insertAll($chunk);
            });
            if ((int)$affected !== count($chunk)) {
                throw new \RuntimeException('异步任务明细批量写入不完整，请按同一 client_request_id 重试');
            }
            $created += (int)$affected;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'total' => count($items),
            'items' => $itemRefs,
        ];
    }

    /**
     * 对一组 item_id 执行一条共同字段 UPDATE。不同凭证/结果应先按共同值分组，
     * 再分别调用本方法，禁止退化成逐行 SQL。
     */
    public function batchUpdateItems(array $itemIds, $jobId, $accountSetId, $workerToken, array $data)
    {
        $jobId = trim((string)$jobId);
        $accountSetId = trim((string)$accountSetId);
        $workerToken = trim((string)$workerToken);
        if ($jobId === '' || $accountSetId === '' || $workerToken === '') {
            throw new \InvalidArgumentException('批量更新任务明细的任务ID、账套ID和 worker token 不能为空');
        }
        $ids = [];
        foreach ($itemIds as $itemId) {
            $itemId = trim((string)$itemId);
            if ($itemId === '' || strlen($itemId) > 36) {
                throw new \InvalidArgumentException('批量更新的任务明细ID格式不正确');
            }
            $ids[$itemId] = true;
        }
        $itemIds = array_keys($ids);
        if (empty($itemIds)) {
            throw new \InvalidArgumentException('批量更新的任务明细ID不能为空');
        }
        if (count($itemIds) > 500) {
            throw new \InvalidArgumentException('单次批量更新任务明细不能超过500条');
        }
        $update = $this->buildBatchItemUpdate($data);

        return $this->transaction(function () use ($itemIds, $jobId, $accountSetId, $workerToken, $update) {
            $this->findOwnedRunningRowInAccount($jobId, $accountSetId, $workerToken, true);
            $scope = [
                'job_id' => $jobId,
                'account_set_id' => $accountSetId,
                'del_flag' => 0,
            ];
            $expected = (int)$this->db($this->tables['item'])
                ->where($scope)
                ->where('item_id', 'in', $itemIds)
                ->count();
            if ($expected !== count($itemIds)) {
                throw new \RuntimeException('部分任务明细不存在或不属于当前账套任务');
            }
            $affected = (int)$this->db($this->tables['item'])
                ->where($scope)
                ->where('item_id', 'in', $itemIds)
                ->update($update);
            if ($affected !== count($itemIds)) {
                throw new \RuntimeException('任务明细批量状态已变化，请停止当前 worker');
            }
            return [
                'affected' => $affected,
                'item_ids' => $itemIds,
                'status' => $update['status'] ?? null,
            ];
        });
    }

    public function updateItem($itemId, $jobId, array $data, $workerToken = '')
    {
        $itemId = trim((string)$itemId);
        $jobId = trim((string)$jobId);
        if ($itemId === '' || $jobId === '') {
            throw new \InvalidArgumentException('任务明细ID和任务ID不能为空');
        }
        if (trim((string)$workerToken) !== '') {
            $this->assertJobOwned($jobId, $workerToken);
        }
        return $this->transaction(function () use ($itemId, $jobId, $data) {
            $row = $this->db($this->tables['item'])->where([
                'item_id' => $itemId,
                'job_id' => $jobId,
                'del_flag' => 0,
            ])->lock(true)->find();
            if (!$row) {
                throw new \RuntimeException('异步任务明细不存在');
            }
            $allowed = [
                'source_row_no', 'group_key', 'amount', 'status', 'error_code', 'error_message',
                'voucher_id', 'voucher_no', 'voucher_period', 'started_time', 'finished_time', 'remark',
            ];
            $update = [];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $data)) {
                    $update[$field] = $data[$field];
                }
            }
            if (isset($update['status']) && !in_array($update['status'], self::ITEM_STATUSES, true)) {
                throw new \InvalidArgumentException('任务明细状态不支持：' . $update['status']);
            }
            if (array_key_exists('amount', $update)) {
                $update['amount'] = $this->normalizeAmount($update['amount']);
            }
            foreach (['group_key' => 255, 'error_code' => 100, 'error_message' => 2000, 'remark' => 500] as $field => $length) {
                if (array_key_exists($field, $update)) {
                    $update[$field] = $this->limitNullableText($update[$field], $length);
                }
            }
            if (array_key_exists('result', $data)) {
                $update['result_json'] = $this->encodeJson($data['result']);
            }
            $update['updated_by'] = 'worker';
            $update['updated_time'] = $this->now();
            $update['version'] = (int)($row['version'] ?? 0) + 1;
            $affected = $this->db($this->tables['item'])->where([
                'item_id' => $itemId,
                'job_id' => $jobId,
                'del_flag' => 0,
                'version' => (int)($row['version'] ?? 0),
            ])->update($update);
            if ((int)$affected !== 1) {
                throw new \RuntimeException('异步任务明细状态已变化，请刷新后重试');
            }
            return $this->normalizeItem(array_merge($row, $update));
        });
    }

    public function listItems($jobId, $accountSetId, $page = 1, $pageSize = 100, $errorsOnly = false)
    {
        $jobId = trim((string)$jobId);
        $accountSetId = trim((string)$accountSetId);
        if (!$this->findById($jobId, $accountSetId)) {
            throw new \RuntimeException('异步任务不存在');
        }
        $page = max(1, (int)$page);
        $pageSize = max(1, min(500, (int)$pageSize));
        $build = function () use ($jobId, $accountSetId, $errorsOnly) {
            $query = $this->db($this->tables['item'])->where([
                'job_id' => $jobId,
                'account_set_id' => $accountSetId,
                'del_flag' => 0,
            ]);
            if ($errorsOnly) {
                $query->where('status', 'FAILED');
            }
            return $query;
        };
        $total = (int)$build()->count();
        $rows = $build()->order('item_no asc,item_id asc')->page($page, $pageSize)->select();
        return [
            'items' => array_map([$this, 'normalizeItem'], $rows ?: []),
            'total' => $total,
            'page' => $page,
            'pagesize' => $pageSize,
        ];
    }

    public function listErrors($jobId, $accountSetId, $page = 1, $pageSize = 100)
    {
        return $this->listItems($jobId, $accountSetId, $page, $pageSize, true);
    }

    public function items($jobId, $accountSetId, $page = 1, $pageSize = 100)
    {
        return $this->listItems($jobId, $accountSetId, $page, $pageSize, false);
    }

    /**
     * Worker 冻结/恢复范围时一次读取任务明细，避免 500 行分页反复执行 count。
     * HTTP 错误列表仍使用 listItems 分页，防止一次返回过多数据。
     */
    public function allItems($jobId, $accountSetId, $sourceType = '')
    {
        $jobId = trim((string)$jobId);
        $accountSetId = trim((string)$accountSetId);
        if (!$this->findById($jobId, $accountSetId)) {
            throw new \RuntimeException('异步任务不存在');
        }
        $where = [
            'job_id' => $jobId,
            'account_set_id' => $accountSetId,
            'del_flag' => 0,
        ];
        $sourceType = trim((string)$sourceType);
        if ($sourceType !== '') {
            $where['source_type'] = $sourceType;
        }
        $rows = $this->db($this->tables['item'])
            ->where($where)
            ->order('item_no asc,item_id asc')
            ->select();
        return array_map([$this, 'normalizeItem'], $rows ?: []);
    }

    public function errors($jobId, $accountSetId, $page = 1, $pageSize = 100)
    {
        return $this->listErrors($jobId, $accountSetId, $page, $pageSize);
    }

    protected function buildBatchItemRow(array $data, array $job, $now)
    {
        $sourceType = $this->requiredText($data, 'source_type', '任务明细来源类型');
        $sourceId = $this->requiredText($data, 'source_id', '任务明细来源ID');
        if (strlen($sourceType) > 50 || strlen($sourceId) > 36) {
            throw new \InvalidArgumentException('任务明细来源类型或来源ID长度超限');
        }
        $itemNo = (int)($data['item_no'] ?? 0);
        if ($itemNo <= 0) {
            throw new \InvalidArgumentException('任务明细序号必须大于0');
        }
        $status = trim((string)($data['status'] ?? 'QUEUED')) ?: 'QUEUED';
        if (!in_array($status, self::ITEM_STATUSES, true)) {
            throw new \InvalidArgumentException('任务明细状态不支持：' . $status);
        }
        $operatorId = trim((string)($data['created_by'] ?? ($job['created_by'] ?? 'system'))) ?: 'system';
        return [
            'item_id' => $this->newId(),
            'job_id' => $job['job_id'],
            'account_set_id' => $job['account_set_id'],
            'fiscal_year' => (int)$job['fiscal_year'],
            'period' => $job['period'],
            'item_no' => $itemNo,
            'source_row_no' => isset($data['source_row_no']) ? (int)$data['source_row_no'] : null,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'group_key' => $this->limitNullableText($data['group_key'] ?? null, 255),
            'amount' => $this->normalizeAmount($data['amount'] ?? 0),
            'status' => $status,
            'result_json' => array_key_exists('result', $data) ? $this->encodeJson($data['result']) : null,
            'error_code' => $this->limitNullableText($data['error_code'] ?? null, 100),
            'error_message' => $this->limitNullableText($data['error_message'] ?? null, 2000),
            'voucher_id' => $this->limitNullableText($data['voucher_id'] ?? null, 36),
            'voucher_no' => isset($data['voucher_no']) ? (int)$data['voucher_no'] : null,
            'voucher_period' => $this->limitNullableText($data['voucher_period'] ?? null, 7),
            'started_time' => $this->nullableText($data['started_time'] ?? null),
            'finished_time' => $this->nullableText($data['finished_time'] ?? null),
            'created_by' => $operatorId,
            'created_time' => $now,
            'updated_by' => $operatorId,
            'updated_time' => $now,
            'del_flag' => 0,
            'version' => 0,
            'remark' => $this->limitNullableText($data['remark'] ?? null, 500),
        ];
    }

    protected function loadExistingBatchItems($jobId, $accountSetId, array $candidateRows)
    {
        $sourceIdsByType = [];
        foreach ($candidateRows as $row) {
            if (!isset($sourceIdsByType[$row['source_type']])) {
                $sourceIdsByType[$row['source_type']] = [];
            }
            $sourceIdsByType[$row['source_type']][$row['source_id']] = true;
        }
        $existing = [];
        foreach ($sourceIdsByType as $sourceType => $sourceIds) {
            // 同一 job/source_type 下不会存在本次范围之外的历史明细；一次走
            // 唯一索引前缀读取，比 5 万条拆成 100 个 IN 查询更适合低配数据库。
            $rows = $this->db($this->tables['item'])->where([
                'job_id' => $jobId,
                'account_set_id' => $accountSetId,
                'source_type' => $sourceType,
                'del_flag' => 0,
            ])->select();
            foreach ($rows ?: [] as $row) {
                $sourceId = (string)($row['source_id'] ?? '');
                if (!isset($sourceIds[$sourceId])) {
                    continue;
                }
                $key = $this->itemSourceKey($row['source_type'] ?? '', $sourceId);
                $existing[$key] = $row;
            }
        }
        return $existing;
    }

    protected function batchItemReference(array $row, $created)
    {
        return [
            'item_id' => $row['item_id'],
            'item_no' => (int)($row['item_no'] ?? 0),
            'source_type' => $row['source_type'],
            'source_id' => $row['source_id'],
            'created' => (bool)$created,
        ];
    }

    protected function itemSourceKey($sourceType, $sourceId)
    {
        $sourceType = (string)$sourceType;
        return strlen($sourceType) . ':' . $sourceType . ':' . (string)$sourceId;
    }

    protected function buildBatchItemUpdate(array $data)
    {
        $allowed = [
            'source_row_no', 'group_key', 'amount', 'status', 'error_code', 'error_message',
            'voucher_id', 'voucher_no', 'voucher_period', 'started_time', 'finished_time', 'remark',
        ];
        $update = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        if (array_key_exists('status', $update) && !in_array($update['status'], self::ITEM_STATUSES, true)) {
            throw new \InvalidArgumentException('任务明细状态不支持：' . $update['status']);
        }
        if (array_key_exists('amount', $update)) {
            $update['amount'] = $this->normalizeAmount($update['amount']);
        }
        foreach (['group_key' => 255, 'error_code' => 100, 'error_message' => 2000, 'remark' => 500] as $field => $length) {
            if (array_key_exists($field, $update)) {
                $update[$field] = $this->limitNullableText($update[$field], $length);
            }
        }
        if (array_key_exists('voucher_id', $update)) {
            $update['voucher_id'] = $this->limitNullableText($update['voucher_id'], 36);
        }
        if (array_key_exists('voucher_no', $update) && $update['voucher_no'] !== null) {
            $update['voucher_no'] = (int)$update['voucher_no'];
        }
        if (array_key_exists('voucher_period', $update)) {
            $update['voucher_period'] = $this->limitNullableText($update['voucher_period'], 7);
        }
        if (array_key_exists('source_row_no', $update) && $update['source_row_no'] !== null) {
            $update['source_row_no'] = (int)$update['source_row_no'];
        }
        if (array_key_exists('result', $data)) {
            $update['result_json'] = $this->encodeJson($data['result']);
        }
        $now = $this->now();
        if (($update['status'] ?? '') === 'RUNNING' && empty($update['started_time'])) {
            $update['started_time'] = $now;
        }
        if (in_array($update['status'] ?? '', ['SUCCEEDED', 'FAILED', 'SKIPPED'], true)
            && empty($update['finished_time'])
        ) {
            $update['finished_time'] = $now;
        }
        if (($update['status'] ?? '') === 'SUCCEEDED') {
            $update['error_code'] = null;
            $update['error_message'] = null;
        }
        if (empty($update)) {
            throw new \InvalidArgumentException('批量更新任务明细缺少共同更新字段');
        }
        $update['updated_by'] = 'worker';
        $update['updated_time'] = $now;
        $update['version'] = ['inc', 1];
        return $update;
    }

    protected function findItemBySource($jobId, $sourceType, $sourceId)
    {
        $row = $this->db($this->tables['item'])->where([
            'job_id' => $jobId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'del_flag' => 0,
        ])->find();
        return $row ? $this->normalizeItem($row) : null;
    }

    protected function claimCandidate($status, array $jobTypes, $order)
    {
        $query = $this->db($this->tables['job'])->where([
            'status' => $status,
            'del_flag' => 0,
        ]);
        if (!empty($jobTypes)) {
            $query->where('job_type', 'in', $jobTypes);
        }
        return $query->order($order)->lock(true)->find();
    }

    protected function mutateOwnedRunningJob($jobId, $workerToken, $builder)
    {
        return $this->transaction(function () use ($jobId, $workerToken, $builder) {
            $row = $this->findOwnedRunningRow($jobId, $workerToken);
            $update = call_user_func($builder, $row);
            if (!is_array($update)) {
                throw new \RuntimeException('任务状态更新回调必须返回数组');
            }
            $now = $this->now();
            $update['heartbeat_time'] = $now;
            $update['updated_by'] = 'worker';
            $update['updated_time'] = $now;
            $update['version'] = (int)$row['version'] + 1;
            $this->assertOwnedUpdate($row, $workerToken, $update);
            return $this->normalizeJob(array_merge($row, $update));
        });
    }

    protected function findOwnedRunningRow($jobId, $workerToken)
    {
        $jobId = trim((string)$jobId);
        $workerToken = trim((string)$workerToken);
        if ($jobId === '' || $workerToken === '') {
            throw new \InvalidArgumentException('任务ID和 worker token 不能为空');
        }
        $row = $this->db($this->tables['job'])->where([
            'job_id' => $jobId,
            'status' => 'RUNNING',
            'worker_token' => $workerToken,
            'del_flag' => 0,
        ])->lock(true)->find();
        if (!$row) {
            throw new \RuntimeException('任务执行权已失效，禁止继续更新');
        }
        return $row;
    }

    protected function findOwnedRunningRowInAccount($jobId, $accountSetId, $workerToken, $lock)
    {
        $query = $this->db($this->tables['job'])->where([
            'job_id' => trim((string)$jobId),
            'account_set_id' => trim((string)$accountSetId),
            'status' => 'RUNNING',
            'worker_token' => trim((string)$workerToken),
            'del_flag' => 0,
        ]);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!$row) {
            throw new \RuntimeException('任务执行权或账套范围已失效，禁止批量更新任务明细');
        }
        return $row;
    }

    protected function assertJobOwned($jobId, $workerToken)
    {
        $row = $this->db($this->tables['job'])->where([
            'job_id' => trim((string)$jobId),
            'status' => 'RUNNING',
            'worker_token' => trim((string)$workerToken),
            'del_flag' => 0,
        ])->find();
        if (!$row) {
            throw new \RuntimeException('任务执行权已失效，禁止更新任务明细');
        }
    }

    protected function assertOwnedUpdate(array $row, $workerToken, array $update)
    {
        $affected = $this->db($this->tables['job'])->where([
            'job_id' => $row['job_id'],
            'status' => 'RUNNING',
            'worker_token' => trim((string)$workerToken),
            'del_flag' => 0,
            'version' => (int)$row['version'],
        ])->update($update);
        if ((int)$affected !== 1) {
            throw new \RuntimeException('异步任务状态已变化，请停止当前 worker');
        }
    }

    public function normalizeJob(array $row)
    {
        $row['payload'] = $this->decodeJson($row['payload_json'] ?? null);
        $row['result'] = $this->decodeJson($row['result_json'] ?? null);
        unset($row['payload_json'], $row['result_json']);
        foreach (['fiscal_year', 'total_count', 'processed_count', 'success_count', 'failure_count', 'attempt_count', 'del_flag', 'version'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (int)$row[$field];
            }
        }
        if (array_key_exists('progress_percent', $row)) {
            $row['progress_percent'] = (float)$row['progress_percent'];
        }
        return $row;
    }

    public function normalizeItem(array $row)
    {
        $row['result'] = $this->decodeJson($row['result_json'] ?? null);
        unset($row['result_json']);
        foreach (['fiscal_year', 'item_no', 'source_row_no', 'voucher_no', 'del_flag', 'version'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int)$row[$field];
            }
        }
        return $row;
    }

    /**
     * HTTP 接口可直接使用的安全视图，不暴露服务器文件路径、worker token 和 payload。
     */
    public function publicJob(array $job)
    {
        if (array_key_exists('payload_json', $job) || array_key_exists('result_json', $job)) {
            $job = $this->normalizeJob($job);
        }
        $fields = [
            'job_id', 'account_set_id', 'fiscal_year', 'period', 'job_type', 'client_request_id',
            'status', 'stage', 'source_file_name', 'total_count', 'processed_count',
            'success_count', 'failure_count', 'progress_percent', 'message', 'error_message',
            'attempt_count', 'queued_time', 'started_time', 'heartbeat_time', 'finished_time',
            'created_by', 'created_time', 'updated_time', 'result', 'launcher_warning',
        ];
        $public = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $job)) {
                $public[$field] = $job[$field];
            }
        }
        return $public;
    }

    protected function progressNumber(array $data, $primaryKey, $aliasKey, $default)
    {
        if (array_key_exists($primaryKey, $data)) {
            return max(0, (int)$data[$primaryKey]);
        }
        if (array_key_exists($aliasKey, $data)) {
            return max(0, (int)$data[$aliasKey]);
        }
        return max(0, (int)$default);
    }

    protected function completionCounts(array $result)
    {
        $source = is_array($result['data'] ?? null) ? $result['data'] : [];
        // handler 顶层显式计数优先于通用响应 data。
        $source = array_merge($source, $result);
        $counts = [];
        foreach ([
            'total_count' => 'total',
            'processed_count' => 'processed',
            'success_count' => 'success',
            'failure_count' => 'failure',
        ] as $field => $alias) {
            if (array_key_exists($field, $source)) {
                $counts[$field] = max(0, (int)$source[$field]);
            } elseif (array_key_exists($alias, $source)) {
                $counts[$field] = max(0, (int)$source[$alias]);
            }
        }
        return $counts;
    }

    protected function normalizeAmount($amount)
    {
        if (is_array($amount) || is_object($amount)) {
            throw new \InvalidArgumentException('任务明细金额格式不正确');
        }
        if (is_float($amount)) {
            if (!is_finite($amount)) {
                throw new \InvalidArgumentException('任务明细金额格式不正确');
            }
            $roundedAmount = round($amount, 2);
            if (abs($amount - $roundedAmount) > 0.0000001) {
                throw new \InvalidArgumentException('任务明细金额格式不正确');
            }
            // 显式指定两位小数，避免 PHP precision 暴露二进制浮点尾差。
            $amount = sprintf('%.2F', $roundedAmount);
        } else {
            $amount = trim((string)$amount);
        }
        $amount = str_replace([',', ' '], '', $amount);
        if ($amount === '' || !preg_match('/^-?\d{1,16}(?:\.\d{1,2})?$/', $amount)) {
            throw new \InvalidArgumentException('任务明细金额格式不正确');
        }
        $negative = $amount[0] === '-';
        if ($negative) {
            $amount = substr($amount, 1);
        }
        $parts = explode('.', $amount, 2);
        $yuan = ltrim($parts[0], '0');
        if ($yuan === '') {
            $yuan = '0';
        }
        $cent = str_pad($parts[1] ?? '', 2, '0', STR_PAD_RIGHT);
        $normalized = $yuan . '.' . substr($cent, 0, 2);
        return $negative && $normalized !== '0.00' ? '-' . $normalized : $normalized;
    }

    protected function requiredText(array $data, $field, $label)
    {
        $value = trim((string)($data[$field] ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException($label . '不能为空');
        }
        return $value;
    }

    protected function nullableText($value)
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    protected function limitNullableText($value, $maxLength)
    {
        $value = $this->nullableText($value);
        return $value === null ? null : $this->limitText($value, $maxLength);
    }

    protected function limitText($value, $maxLength)
    {
        $value = (string)$value;
        $maxLength = max(0, (int)$maxLength);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return substr($value, 0, $maxLength);
    }

    protected function cleanTextList(array $values)
    {
        $clean = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $clean[$value] = true;
            }
        }
        return array_keys($clean);
    }

    protected function encodeJson($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \InvalidArgumentException('任务JSON序列化失败：' . json_last_error_msg());
        }
        return $json;
    }

    protected function decodeJson($value)
    {
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function db($table)
    {
        return call_user_func($this->hooks['get_db'], $table);
    }

    protected function transaction($callback)
    {
        return call_user_func($this->hooks['transaction'], $callback);
    }

    protected function newId()
    {
        return call_user_func($this->hooks['new_id']);
    }

    protected function now()
    {
        return call_user_func($this->hooks['now']);
    }
}
