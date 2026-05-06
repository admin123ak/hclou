<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

const CRON_RUN_TOKEN = '512b48e26f47d889486ecbecbdd7f21517422ac9ea0849de';
const CRON_RUN_LOG = __DIR__ . '/data/cron_run.log';

function hclouCronRunLog(string $job, int $status, bool $success, int $durationMs, string $detail = ''): void {
    $dir = dirname(CRON_RUN_LOG);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $entry = [
        'ts' => date('c'),
        'ip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
        'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 180),
        'job' => $job,
        'status' => $status,
        'success' => $success,
        'duration_ms' => $durationMs,
        'detail' => substr($detail, 0, 500),
    ];
    @file_put_contents(CRON_RUN_LOG, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

$token = $_GET['token'] ?? '';
$job = $_GET['job'] ?? '';
$requestStarted = microtime(true);

if (!hash_equals(CRON_RUN_TOKEN, $token)) {
    http_response_code(403);
    hclouCronRunLog($job, 403, false, (int)round((microtime(true) - $requestStarted) * 1000), 'Forbidden');
    echo json_encode(['success' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

// MBBANK auto-bank đã chuyển sang VPS local loop: hclou-mbbank-poll.service mỗi 5 giây.
// Chặn riêng cron-job.org gọi job=mbbank để tránh chạy trùng; các job ngoài khác vẫn hoạt động.
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($job === 'mbbank' && stripos($ua, 'cron-job.org') !== false) {
    $ms = (int)round((microtime(true) - $requestStarted) * 1000);
    hclouCronRunLog($job, 204, true, $ms, 'Skipped: mbbank runs locally via hclou-mbbank-poll.service every 5s');
    http_response_code(204);
    exit;
}

$jobs = [
    'mbbank' => ['/usr/bin/php', __DIR__ . '/mbbank_poll.php'],
    'maintenance' => ['/usr/bin/php', __DIR__ . '/maintenance.php'],
    'automation' => ['/usr/bin/php', __DIR__ . '/automation_daily.php'],
    'health' => ['/usr/bin/php', __DIR__ . '/health_check_daily.php'],
    'monitor' => ['/usr/bin/php', __DIR__ . '/cron_monitor.php'],
    'backup' => ['/usr/bin/bash', '/www/backup/hclou_db/backup.sh'],
];

if (!isset($jobs[$job])) {
    http_response_code(400);
    hclouCronRunLog($job, 400, false, (int)round((microtime(true) - $requestStarted) * 1000), 'Invalid job');
    echo json_encode(['success' => false, 'error' => 'Invalid job'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cmd = escapeshellarg($jobs[$job][0]) . ' ' . escapeshellarg($jobs[$job][1]) . ' 2>&1';
$output = [];
$code = 0;
$started = microtime(true);
exec($cmd, $output, $code);
$ms = (int)round((microtime(true) - $started) * 1000);
$raw = trim(implode("\n", $output));
$json = json_decode($raw, true);

if ($code !== 0) {
    http_response_code(500);
    hclouCronRunLog($job, 500, false, $ms, 'exit_code=' . $code . ' output=' . $raw);
    echo json_encode(['success' => false, 'job' => $job, 'exit_code' => $code, 'duration_ms' => $ms, 'output' => $raw], JSON_UNESCAPED_UNICODE);
    exit;
}

hclouCronRunLog($job, 200, true, $ms, is_array($json) ? json_encode($json, JSON_UNESCAPED_UNICODE) : $raw);
echo json_encode(['success' => true, 'job' => $job, 'duration_ms' => $ms, 'result' => $json ?: $raw], JSON_UNESCAPED_UNICODE);
