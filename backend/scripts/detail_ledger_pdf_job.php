<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI mode.\n");
    exit(1);
}

set_time_limit(0);
ini_set('memory_limit', '1024M');
date_default_timezone_set('PRC');

$jobId = $argv[1] ?? '';
if ($jobId === '' || !preg_match('/^[A-Za-z0-9_-]+(?:-[A-Za-z0-9_-]+)*$/', $jobId)) {
    fwrite(STDERR, "Invalid job id.\n");
    exit(1);
}

$root = dirname(__DIR__);
$jobDir = $root . '/runtime/detail_ledger_pdf_jobs';
$jobPath = $jobDir . '/' . basename($jobId) . '.json';

$readJob = function () use ($jobPath) {
    if (!is_file($jobPath)) {
        throw new RuntimeException('Job file not found.');
    }
    $job = json_decode((string)file_get_contents($jobPath), true);
    if (!is_array($job)) {
        throw new RuntimeException('Job file is invalid.');
    }
    return $job;
};

$writeJob = function ($job) use ($jobPath) {
    $job['updated_time'] = date('Y-m-d H:i:s');
    file_put_contents($jobPath, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
};

try {
    $job = $readJob();
    $job['status'] = 'RUNNING';
    $job['message'] = '正在生成PDF';
    $job['error'] = '';
    $writeJob($job);

    define('APP_PATH', $root . '/app/');
    define('ROOT_PATH', $root . '/');
    define('APIMODE', 'API');
    require $root . '/thinkphp/base.php';
    require $root . '/thinkphp/helper.php';
    require APP_PATH . 'common.php';
    \think\Config::set(include APP_PATH . 'config.php');
    \think\Config::set(include APP_PATH . 'database.php', 'database');
    require APP_PATH . 'finance/model/Common.php';
    require APP_PATH . 'finance/model/Book.php';

    $_GET['account_set_id'] = $job['account_set_id'] ?? '';
    $_POST['account_set_id'] = $job['account_set_id'] ?? '';
    $_REQUEST['account_set_id'] = $job['account_set_id'] ?? '';
    $_GET['year'] = $job['year'] ?? '';
    $_POST['year'] = $job['year'] ?? '';
    $_REQUEST['year'] = $job['year'] ?? '';
    $_GET['action'] = '/book/detailLedgerPdf';
    $_POST['action'] = '/book/detailLedgerPdf';
    $_REQUEST['action'] = '/book/detailLedgerPdf';
    $_SERVER['HTTP_X_USER_ID'] = $job['user_id'] ?? 'system';
    $_SERVER['HTTP_X_USER_NAME'] = $job['user_name'] ?? ($job['user_id'] ?? 'system');

    $book = new \app\finance\model\Book();
    $params = $job['params'] ?? [];
    $lastProgressWrite = 0.0;
    $params['_progress_callback'] = function ($progress) use ($readJob, $writeJob, &$lastProgressWrite) {
        $now = microtime(true);
        $percent = isset($progress['progress']) ? (int)$progress['progress'] : 0;
        if ($percent < 98 && ($now - $lastProgressWrite) < 1.0) {
            return;
        }
        $job = $readJob();
        foreach (['progress', 'total_rows', 'generated_pages', 'total_pages', 'message'] as $field) {
            if (array_key_exists($field, $progress)) {
                $job[$field] = $progress[$field];
            }
        }
        $job['status'] = 'RUNNING';
        $writeJob($job);
        $lastProgressWrite = $now;
    };
    $response = $book->detailLedgerPdf($params);
    if (!$response instanceof \think\Response) {
        $message = is_array($response) ? ($response['message'] ?? '生成PDF失败') : '生成PDF失败';
        throw new RuntimeException($message);
    }
    $content = $response->getContent();
    if (strncmp($content, '%PDF', 4) !== 0) {
        throw new RuntimeException('生成结果不是有效PDF');
    }
    file_put_contents($job['pdf_path'], $content, LOCK_EX);

    $job = $readJob();
    $job['status'] = 'DONE';
    $job['message'] = 'PDF已生成';
    $job['error'] = '';
    $job['progress'] = 100;
    $job['file_size'] = filesize($job['pdf_path']);
    $writeJob($job);
    exit(0);
} catch (Throwable $e) {
    try {
        $job = is_file($jobPath) ? $readJob() : ['job_id' => $jobId];
        $job['status'] = 'FAILED';
        $job['message'] = 'PDF生成失败';
        $job['error'] = $e->getMessage();
        $writeJob($job);
    } catch (Throwable $writeError) {
        fwrite(STDERR, $writeError->getMessage() . "\n");
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
