<?php

namespace app\finance\service\job;

/**
 * 兼容 Windows XAMPP 与 Unix 的后台 worker 启动器。
 *
 * 启动失败不会改变 QUEUED 任务；调用方应把 launcher_warning 返回前端，
 * 生产环境仍可由计划任务直接运行 finance_job_worker.php 接管队列。
 */
class FinanceAsyncJobLauncher
{
    protected $options;

    public function __construct(array $options = [])
    {
        $backendRoot = dirname(__DIR__, 4);
        $this->options = array_merge([
            'script_path' => $backendRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'finance_job_worker.php',
            'runtime_dir' => $backendRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'finance_async_jobs',
            'php_binary' => '',
        ], $options);
    }

    public function launch(array $options = [])
    {
        $script = realpath($this->options['script_path']);
        if (!$script || !is_file($script)) {
            return $this->warning('财务后台任务脚本不存在，请确认 backend/scripts/finance_job_worker.php 已部署');
        }
        $runtimeDir = $this->options['runtime_dir'];
        if (!is_dir($runtimeDir) && !@mkdir($runtimeDir, 0775, true) && !is_dir($runtimeDir)) {
            return $this->warning('财务后台任务日志目录不可写：' . $runtimeDir);
        }
        $php = $this->findPhpCliBinary();
        if ($php === '') {
            return $this->warning('未找到 PHP CLI 可执行文件，任务已保留在队列中，可由计划任务接管');
        }

        $args = [];
        if (!empty($options['once'])) {
            $args[] = '--once';
        }
        $jobTypes = $this->cleanJobTypes($options['job_types'] ?? []);
        if (!empty($jobTypes)) {
            $args[] = '--job-type=' . implode(',', $jobTypes);
        }
        $staleSeconds = max(0, (int)($options['stale_seconds'] ?? 0));
        if ($staleSeconds > 0) {
            $args[] = '--stale-seconds=' . $staleSeconds;
        }

        $command = escapeshellarg($php) . ' ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $logPath = $runtimeDir . DIRECTORY_SEPARATOR . 'worker.log';

        if ($this->isWindows()) {
            if (!$this->functionAvailable('popen') || !$this->functionAvailable('pclose')) {
                return $this->warning('生产环境禁用了 popen/pclose，任务已排队，请配置 Windows 计划任务运行 worker');
            }
            $detached = 'cmd /C start "" /B ' . $command . ' >> ' . escapeshellarg($logPath) . ' 2>&1';
            $handle = @popen($detached, 'r');
            if (!$handle) {
                return $this->warning('Windows 后台 worker 启动失败，任务已排队，请检查 XAMPP 运行账户的 cmd/start 权限');
            }
            pclose($handle);
            return [
                'ok' => true,
                'launcher_warning' => '',
                'launcher_pid' => '',
            ];
        }

        if (!$this->functionAvailable('exec')) {
            return $this->warning('生产环境禁用了 exec，任务已排队，请配置 cron/systemd 定期运行 worker');
        }
        $detached = $command . ' >> ' . escapeshellarg($logPath) . ' 2>&1 & echo $!';
        $output = [];
        $code = 0;
        exec($detached, $output, $code);
        if ($code !== 0) {
            return $this->warning('Unix 后台 worker 启动命令失败，任务已排队，可由 cron/systemd 接管');
        }
        return [
            'ok' => true,
            'launcher_warning' => '',
            'launcher_pid' => trim((string)($output[0] ?? '')),
        ];
    }

    public function findPhpCliBinary()
    {
        $configured = trim((string)$this->options['php_binary']);
        if ($configured !== '' && is_file($configured) && $this->isPhpCliBinaryPath($configured)) {
            return $configured;
        }
        $isWindows = $this->isWindows();
        $names = $isWindows ? ['php.exe', 'php'] : ['php'];
        $baseDirs = [PHP_BINDIR, dirname(PHP_BINARY)];
        if ($isWindows) {
            foreach ([PHP_BINDIR, dirname(PHP_BINARY)] as $dir) {
                $dir = rtrim($dir, '/\\');
                $baseDirs[] = dirname(dirname($dir)) . DIRECTORY_SEPARATOR . 'php';
                $baseDirs[] = dirname(dirname(dirname($dir))) . DIRECTORY_SEPARATOR . 'php';
            }
            foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $pathDir) {
                if ($pathDir !== '') {
                    $baseDirs[] = $pathDir;
                }
            }
        }
        $candidates = [];
        if ($this->isPhpCliBinaryPath(PHP_BINARY)) {
            $candidates[] = PHP_BINARY;
        }
        foreach (array_unique($baseDirs) as $dir) {
            foreach ($names as $name) {
                $candidates[] = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
            }
        }
        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && $this->isPhpCliBinaryPath($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    public function isPhpCliBinaryPath($path)
    {
        $name = strtolower(basename((string)$path));
        return $name === 'php' || $name === 'php.exe';
    }

    protected function isWindows()
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    protected function functionAvailable($function)
    {
        if (!function_exists($function)) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array($function, $disabled, true);
    }

    protected function cleanJobTypes($jobTypes)
    {
        if (!is_array($jobTypes)) {
            $jobTypes = explode(',', (string)$jobTypes);
        }
        $clean = [];
        foreach ($jobTypes as $jobType) {
            $jobType = trim((string)$jobType);
            if ($jobType === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_:-]{1,50}$/', $jobType)) {
                throw new \InvalidArgumentException('worker 任务类型格式不正确：' . $jobType);
            }
            $clean[$jobType] = true;
        }
        return array_keys($clean);
    }

    protected function warning($message)
    {
        return [
            'ok' => false,
            'launcher_warning' => $message,
            'launcher_pid' => '',
        ];
    }
}
