<?php
require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

const HCLOU_MONITOR_STATE = __DIR__ . '/data/cron_monitor_state.json';

function hclouMonHttp(string $url, int $timeout = 15): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'HCLOU-CronMonitor/1.0',
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ms = (int)round((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    curl_close($ch);
    return ['ok' => $err === '' && $code >= 200 && $code < 400, 'code' => $code, 'error' => $err, 'ms' => $ms, 'body' => (string)$body];
}
function hclouMonLoadState(): array {
    if (!is_file(HCLOU_MONITOR_STATE)) return [];
    $json = json_decode((string)file_get_contents(HCLOU_MONITOR_STATE), true);
    return is_array($json) ? $json : [];
}
function hclouMonSaveState(array $state): void {
    $dir = dirname(HCLOU_MONITOR_STATE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents(HCLOU_MONITOR_STATE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}
function hclouMonCheckDb(PDO $db): array {
    try {
        $ok = (bool)$db->query('SELECT 1')->fetchColumn();
        return ['ok' => $ok, 'detail' => $ok ? 'SELECT 1 OK' : 'SELECT 1 failed'];
    } catch (Throwable $e) {
        return ['ok' => false, 'detail' => $e->getMessage()];
    }
}
function hclouMonDisk(): array {
    $total = @disk_total_space('/');
    $free = @disk_free_space('/');
    if (!$total || !$free) return ['ok' => false, 'detail' => 'unknown'];
    $used = round((1 - $free / $total) * 100, 1);
    return ['ok' => $used < 90, 'detail' => $used . '% used, free ' . round($free / 1024 / 1024 / 1024, 2) . 'GB'];
}
function hclouMonRam(): array {
    $raw = @file_get_contents('/proc/meminfo');
    if (!$raw) return ['ok' => false, 'detail' => 'unknown'];
    preg_match('/MemAvailable:\s+(\d+)\s+kB/', $raw, $ma);
    preg_match('/MemTotal:\s+(\d+)\s+kB/', $raw, $mt);
    $avail = isset($ma[1]) ? (int)$ma[1] : 0;
    $total = isset($mt[1]) ? (int)$mt[1] : 0;
    if (!$avail || !$total) return ['ok' => false, 'detail' => 'unknown'];
    $pct = round($avail / $total * 100, 1);
    return ['ok' => $pct >= 10, 'detail' => round($avail / 1024) . 'MB available (' . $pct . '%)'];
}
function hclouMonResult(string $name, bool $ok, string $detail): array {
    return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

try {
    $db = getDB();
    $checks = [];

    $home = hclouMonHttp(SITE_URL . '/', 12);
    $checks[] = hclouMonResult('Web home', $home['ok'], 'HTTP ' . $home['code'] . ' / ' . $home['ms'] . 'ms');

    $api = hclouMonHttp(SITE_URL . '/api/?action=games', 12);
    $apiJson = json_decode($api['body'], true);
    $checks[] = hclouMonResult('Mini App API games', $api['ok'] && is_array($apiJson), 'HTTP ' . $api['code'] . ' / ' . $api['ms'] . 'ms');

    $checks[] = ['name' => 'Database', ...hclouMonCheckDb($db)];

    $bankEnabled = defined('MBBANK_AUTO_APPROVE_ENABLED') && MBBANK_AUTO_APPROVE_ENABLED;
    $checks[] = hclouMonResult('MBBANK auto approve', $bankEnabled, $bankEnabled ? 'enabled' : 'disabled');

    $bankCfg = defined('MBBANK_HISTORY_API_TOKEN') && MBBANK_HISTORY_API_TOKEN !== '' && defined('MBBANK_HISTORY_API_URL') && MBBANK_HISTORY_API_URL !== '';
    $checks[] = hclouMonResult('MBBANK API config', $bankCfg, $bankCfg ? 'token/url present' : 'missing token/url');

    $pendingOld = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='pending' AND created_at < (NOW() - INTERVAL 20 MINUTE)")->fetchColumn();
    $checks[] = hclouMonResult('Stuck pending orders', $pendingOld === 0, $pendingOld . ' orders older than 20m');

    $bankBad = (int)$db->query("SELECT COUNT(*) FROM bank_transactions WHERE status IN ('ignored','error') AND created_at >= (NOW() - INTERVAL 2 HOUR)")->fetchColumn();
    $checks[] = hclouMonResult('Recent bank ignored/error', $bankBad === 0, $bankBad . ' tx in 2h');

    $disk = hclouMonDisk();
    $checks[] = ['name' => 'Disk /', ...$disk];

    $ram = hclouMonRam();
    $checks[] = ['name' => 'RAM', ...$ram];

    $failed = array_values(array_filter($checks, fn($c) => !$c['ok']));
    $state = hclouMonLoadState();
    $failKey = sha1(json_encode(array_map(fn($c) => [$c['name'], $c['detail']], $failed), JSON_UNESCAPED_UNICODE));
    $prevKey = $state['fail_key'] ?? '';
    $lastAlert = (int)($state['last_alert'] ?? 0);
    $cooldown = 30 * 60;
    $shouldAlert = !empty($failed) && ($failKey !== $prevKey || time() - $lastAlert >= $cooldown);
    $recovered = empty($failed) && !empty($prevKey);

    if ($shouldAlert) {
        $lines = array_map(fn($c) => '⚠️ ' . $c['name'] . ': ' . $c['detail'], $failed);
        sendTelegram(ADMIN_CHAT_ID, "⚠️ <b>HCLOU REALTIME MONITOR CẢNH BÁO</b>\n\n" . implode("\n", $lines) . "\n\n⏰ " . date('Y-m-d H:i:s'));
        $state['fail_key'] = $failKey;
        $state['last_alert'] = time();
    } elseif ($recovered) {
        sendTelegram(ADMIN_CHAT_ID, "✅ <b>HCLOU REALTIME MONITOR ĐÃ ỔN LẠI</b>\n\nCác check web/API/DB/bank/RAM/disk hiện đã OK.\n⏰ " . date('Y-m-d H:i:s'));
        $state['fail_key'] = '';
        $state['last_alert'] = 0;
    }

    $state['last_run'] = time();
    $state['last_checks'] = $checks;
    hclouMonSaveState($state);

    echo json_encode(['success' => empty($failed), 'alert_sent' => $shouldAlert, 'recovered' => $recovered, 'checks' => $checks], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    $state = hclouMonLoadState();
    $lastAlert = (int)($state['last_alert'] ?? 0);
    if (time() - $lastAlert >= 30 * 60) {
        sendTelegram(ADMIN_CHAT_ID, "❌ <b>HCLOU REALTIME MONITOR FAILED</b>\n\n" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "\n⏰ " . date('Y-m-d H:i:s'));
        $state['fail_key'] = sha1($e->getMessage());
        $state['last_alert'] = time();
        hclouMonSaveState($state);
    }
    fwrite(STDERR, json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
