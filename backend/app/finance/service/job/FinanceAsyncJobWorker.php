<?php

namespace app\finance\service\job;

/**
 * 单进程任务消费循环。进程级互斥由 CLI 入口的 flock 保证。
 */
class FinanceAsyncJobWorker
{
    protected $service;
    protected $handlerResolver;
    protected $logger;
    protected $activeJob;
    protected $activeWorkerToken = '';
    protected $fatalMemoryReserve;
    protected $fatalShutdownRegistered = false;

    public function __construct(FinanceAsyncJobService $service, $handlerResolver, $logger = null)
    {
        if (!is_callable($handlerResolver)) {
            throw new \InvalidArgumentException('异步任务 handler resolver 必须可调用');
        }
        if ($logger !== null && !is_callable($logger)) {
            throw new \InvalidArgumentException('异步任务 logger 必须可调用');
        }
        $this->service = $service;
        $this->handlerResolver = $handlerResolver;
        $this->logger = $logger;
    }

    /**
     * 默认持续消费至队列为空；once=true 时最多认领一项。
     */
    public function run(array $options = [])
    {
        $this->registerFatalShutdownGuard();
        $once = !empty($options['once']);
        $jobTypes = is_array($options['job_types'] ?? null) ? $options['job_types'] : [];
        $staleSeconds = max(0, (int)($options['stale_seconds'] ?? 0));
        $idleGraceMilliseconds = max(0, min(5000, (int)($options['idle_grace_milliseconds'] ?? 1000)));
        $idlePollMilliseconds = max(50, min(500, (int)($options['idle_poll_milliseconds'] ?? 200)));
        $workerToken = trim((string)($options['worker_token'] ?? ''));
        if ($workerToken === '') {
            $workerToken = $this->defaultWorkerToken();
        }

        $summary = [
            'worker_token' => $workerToken,
            'claimed' => 0,
            'succeeded' => 0,
            'partial_failed' => 0,
            'failed' => 0,
        ];

        $idleWaitedMilliseconds = 0;
        while (true) {
            $job = $this->service->claimNext($workerToken, $jobTypes, $staleSeconds);
            if (!$job) {
                // 避免“旧 worker 正准备退出、新任务 launcher 因 flock 退出”的窄竞态窗口。
                if (!$once && $idleWaitedMilliseconds < $idleGraceMilliseconds) {
                    usleep($idlePollMilliseconds * 1000);
                    $idleWaitedMilliseconds += $idlePollMilliseconds;
                    continue;
                }
                break;
            }
            $idleWaitedMilliseconds = 0;
            $summary['claimed']++;
            // claim 已经把任务置为 RUNNING；必须先登记当前任务再做日志或业务处理，
            // 这样 PHP 致命错误也能在 shutdown 阶段将其可靠收口为 FAILED。
            $this->activeJob = $job;
            $this->activeWorkerToken = $workerToken;
            $this->log('CLAIMED', $job, '任务已认领');
            try {
                $result = $this->execute($job, $workerToken);
                $completionStatus = $this->completionStatus($result);
                $atomicCompletionStatus = $this->atomicCompletionStatus($result, $completionStatus);
                if ($atomicCompletionStatus !== '') {
                    // 业务 handler 已经把正式数据、审计和任务终态放在同一事务提交；
                    // 此处只确认 durable 状态，绝不能再执行第二次 complete()。
                    $completed = $this->service->assertCompletedByWorker(
                        $job['job_id'],
                        $workerToken,
                        $atomicCompletionStatus
                    );
                } else {
                    $completed = $this->service->complete(
                        $job['job_id'],
                        $workerToken,
                        $this->completionResult($result),
                        $completionStatus
                    );
                }
                $this->recordCompletionSummary($summary, $completed);
                $this->log($completed['status'], $completed, $completed['message'] ?? '任务完成');
            } catch (\Throwable $e) {
                // Handler 可能在“业务+成功终态”原子提交后、返回标记前发生异常。
                // 先识别 durable 成功，避免把已经完成的财务任务反向标成 FAILED。
                $completed = null;
                try {
                    $completed = $this->service->completedByWorker($job['job_id'], $workerToken);
                } catch (\Throwable $completionReadError) {
                    // 无法确认成功时继续走原有失败收口；fail() 仍有 worker token 栅栏。
                }
                if ($completed) {
                    $this->recordCompletionSummary($summary, $completed);
                    $this->log(
                        'ATOMIC_COMPLETION_RECOVERED',
                        $completed,
                        '业务与任务终态已原子提交，忽略提交后的进程异常：' . $e->getMessage()
                    );
                } else {
                    $summary['failed']++;
                    try {
                        $failed = $this->service->fail($job['job_id'], $workerToken, $e->getMessage(), [
                            'exception_class' => get_class($e),
                        ]);
                        $this->log('FAILED', $failed, $e->getMessage());
                    } catch (\Throwable $stateError) {
                        // 被其他 worker 接管后，旧 worker 不得覆盖任务状态。
                        $this->log('STATE_UPDATE_FAILED', $job, $stateError->getMessage());
                    }
                }
            }
            $this->activeJob = null;
            $this->activeWorkerToken = '';

            if ($once) {
                break;
            }
        }

        $this->fatalMemoryReserve = null;
        return $summary;
    }

    protected function registerFatalShutdownGuard()
    {
        if ($this->fatalShutdownRegistered) {
            return;
        }
        $this->fatalShutdownRegistered = true;
        // 内存耗尽时先释放这段空间，给任务状态更新和数据库回滚留出余量。
        $this->fatalMemoryReserve = str_repeat('R', 2 * 1024 * 1024);
        register_shutdown_function([$this, 'handleFatalShutdown']);
    }

    /**
     * shutdown 回调不能依赖 Throwable：allowed memory exhausted 等 E_ERROR 不会进入 catch。
     * 可选参数仅用于脚本式测试注入，正常回调由 error_get_last() 提供错误。
     */
    public function handleFatalShutdown($fatalError = null)
    {
        $error = is_array($fatalError) ? $fatalError : error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!$this->activeJob || !is_array($error)
            || !in_array((int)($error['type'] ?? 0), $fatalTypes, true)
        ) {
            return;
        }

        $this->fatalMemoryReserve = null;
        // 致命错误可能发生在凭证或导入事务中。shutdown 早于连接析构，必须先
        // 回滚未完成的业务事务，再用独立任务事务写 FAILED；否则失败状态也会
        // 随未完成事务一起在进程退出时被回滚。
        if (class_exists('\\think\\Db')) {
            // TP5 通过 transTimes 模拟嵌套事务，单次 rollback 只减少一层且没有
            // 公开的事务深度读取接口。应用内事务嵌套远低于 32 层；重复调用在
            // 深度归零后是安全空操作。随后清除连接缓存，让 fail() 必定使用一条
            // 不携带旧事务计数的新连接提交终态。
            for ($rollbackDepth = 0; $rollbackDepth < 32; $rollbackDepth++) {
                try {
                    \think\Db::rollback();
                } catch (\Throwable $rollbackError) {
                    break;
                }
            }
            try {
                \think\Db::clear();
            } catch (\Throwable $clearError) {
                // fail() 仍会按 worker token 校验；连接无法刷新时宁可拒绝覆盖。
            }
        }
        $job = $this->activeJob;
        $workerToken = $this->activeWorkerToken;
        try {
            // 必须在上面的事务回滚和连接清理之后读取：若 fatal 发生在外层 commit
            // 之前，任务成功终态仍是未提交数据，回滚后这里应读取不到；若发生在
            // commit 之后，则保留已与业务数据一起提交的成功终态。
            $completed = $this->service->completedByWorker($job['job_id'], $workerToken);
            if ($completed) {
                $this->log(
                    'ATOMIC_COMPLETION_SURVIVED_FATAL',
                    $completed,
                    '业务与任务终态已原子提交，fatal 不覆盖成功状态'
                );
                $this->activeJob = null;
                $this->activeWorkerToken = '';
                return;
            }
        } catch (\Throwable $completionReadError) {
            // 终态暂不可读时仍尝试 fail()；它会再次校验 RUNNING + worker token。
        }
        $rawMessage = trim((string)($error['message'] ?? 'PHP 致命错误'));
        if (stripos($rawMessage, 'Allowed memory size') !== false) {
            $message = '后台任务内存不足，已安全终止；请缩小查询范围或拆分导入文件后重新提交';
        } else {
            $message = '后台任务进程发生致命错误：' . substr($rawMessage, 0, 1500);
        }
        try {
            $failed = $this->service->fail($job['job_id'], $workerToken, $message, [
                'fatal_type' => (int)($error['type'] ?? 0),
            ]);
            $this->log('FAILED_FATAL', $failed, $message);
        } catch (\Throwable $stateError) {
            // 若任务已被其他 token 接管，旧进程不得覆盖新 worker 的状态。
            $this->log('FATAL_STATE_UPDATE_FAILED', $job, $stateError->getMessage());
        }
        $this->activeJob = null;
        $this->activeWorkerToken = '';
    }

    protected function execute(array $job, $workerToken)
    {
        $job['_progress_callback'] = function (array $progress) use ($job, $workerToken) {
            return $this->service->updateProgress($job['job_id'], $workerToken, $progress);
        };
        $job['_heartbeat_callback'] = function ($stage = '', $message = '') use ($job, $workerToken) {
            return $this->service->heartbeat($job['job_id'], $workerToken, $stage, $message);
        };
        $handler = call_user_func($this->handlerResolver, $job);
        if (!is_callable($handler)) {
            throw new \RuntimeException('任务类型未注册处理器：' . ($job['job_type'] ?? 'UNKNOWN'));
        }
        $result = call_user_func($handler, $job);
        if (!is_array($result)) {
            throw new \RuntimeException('异步任务处理器必须返回数组');
        }
        if (array_key_exists('code', $result) && (int)$result['code'] !== 20000) {
            throw new \RuntimeException(trim((string)($result['message'] ?? '异步任务处理失败')) ?: '异步任务处理失败');
        }
        return $result;
    }

    protected function completionStatus(array $result)
    {
        $status = trim((string)($result['_job_status'] ?? ''));
        if ($status === '') {
            return '';
        }
        if (!in_array($status, FinanceAsyncJobService::COMPLETION_STATUSES, true)) {
            throw new \RuntimeException('任务处理器返回了不支持的完成状态：' . $status);
        }
        return $status;
    }

    protected function atomicCompletionStatus(array $result, $completionStatus)
    {
        $status = trim((string)($result[FinanceAsyncJobService::ATOMIC_COMPLETION_KEY] ?? ''));
        if ($status === '') {
            return '';
        }
        if (!in_array($status, FinanceAsyncJobService::COMPLETION_STATUSES, true)) {
            throw new \RuntimeException('任务处理器返回了不支持的原子完成状态：' . $status);
        }
        $completionStatus = trim((string)$completionStatus);
        if ($completionStatus !== '' && $completionStatus !== $status) {
            throw new \RuntimeException('任务处理器返回的完成状态与原子完成状态不一致');
        }
        return $status;
    }

    protected function completionResult(array $result)
    {
        unset($result['_job_status'], $result[FinanceAsyncJobService::ATOMIC_COMPLETION_KEY]);
        return $result;
    }

    protected function recordCompletionSummary(array &$summary, array $completed)
    {
        if (($completed['status'] ?? '') === 'PARTIAL_FAILED') {
            $summary['partial_failed']++;
            return;
        }
        $summary['succeeded']++;
    }

    protected function defaultWorkerToken()
    {
        $host = function_exists('gethostname') ? (string)gethostname() : 'host';
        try {
            $suffix = bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            $suffix = str_replace('.', '', uniqid('', true));
        }
        return substr($host . '-' . getmypid() . '-' . $suffix, 0, 100);
    }

    protected function log($event, array $job, $message)
    {
        if ($this->logger === null) {
            return;
        }
        call_user_func($this->logger, [
            'time' => date('Y-m-d H:i:s'),
            'event' => $event,
            'job_id' => $job['job_id'] ?? '',
            'job_type' => $job['job_type'] ?? '',
            'message' => (string)$message,
        ]);
    }
}
