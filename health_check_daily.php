<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/maintenance.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function hclouHealthMoney($n): string { return number_format((float)$n, 0, ',', '.'); }
function hclouHealthScalar(PDO $db, string $sql, array $params = []) {
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}
function hclouHealthHttp(string $url, int $timeout = 12): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'HCLOU-HealthCheck/1.0',
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => $err === '' && $code >= 200 && $code < 400, 'code' => $code, 'error' => $err, 'body' => (string)$body];
}
function hclouHealthDisk(): array {
    $total = @disk_total_space('/');
    $free = @disk_free_space('/');
    if (!$total || !$free) return ['ok' => false, 'text' => 'unknown'];
    $usedPct = round((1 - ($free / $total)) * 100, 1);
    return ['ok' => $usedPct < 90, 'text' => $usedPct . '% used / free ' . round($free / 1024 / 1024 / 1024, 2) . 'GB'];
}
function hclouHealthMemory(): array {
    $raw = @file_get_contents('/proc/meminfo');
    if (!$raw) return ['ok' => false, 'text' => 'unknown'];
    preg_match('/MemAvailable:\s+(\d+)\s+kB/', $raw, $ma);
    preg_match('/MemTotal:\s+(\d+)\s+kB/', $raw, $mt);
    $avail = isset($ma[1]) ? (int)$ma[1] : 0;
    $total = isset($mt[1]) ? (int)$mt[1] : 0;
    if (!$avail || !$total) return ['ok' => false, 'text' => 'unknown'];
    $pct = round($avail / $total * 100, 1);
    return ['ok' => $pct >= 10, 'text' => round($avail / 1024) . 'MB available (' . $pct . '%)'];
}

try {
    $db = getDB();
    $checks = [];

    $web = hclouHealthHttp(SITE_URL . '/');
    $checks[] = ['name' => 'Web home', 'ok' => $web['ok'], 'detail' => 'HTTP ' . $web['code']];

    $api = hclouHealthHttp(SITE_URL . '/api/?action=games');
    $apiJson = json_decode($api['body'], true);
    $checks[] = ['name' => 'Mini App API games', 'ok' => $api['ok'] && is_array($apiJson), 'detail' => 'HTTP ' . $api['code']];

    $dbOk = (bool)hclouHealthScalar($db, 'SELECT 1');
    $checks[] = ['name' => 'Database', 'ok' => $dbOk, 'detail' => $dbOk ? 'SELECT 1 OK' : 'SELECT 1 failed'];

    $bankEnabled = defined('MBBANK_AUTO_APPROVE_ENABLED') && MBBANK_AUTO_APPROVE_ENABLED;
    $checks[] = ['name' => 'MBBANK auto approve', 'ok' => $bankEnabled, 'detail' => $bankEnabled ? 'enabled' : 'disabled'];

    $maint = runMaintenance($db);
    $checks[] = ['name' => 'Maintenance dry run', 'ok' => true, 'detail' => json_encode($maint, JSON_UNESCAPED_UNICODE)];

    $disk = hclouHealthDisk();
    $checks[] = ['name' => 'Disk /', 'ok' => $disk['ok'], 'detail' => $disk['text']];

    $mem = hclouHealthMemory();
    $checks[] = ['name' => 'RAM', 'ok' => $mem['ok'], 'detail' => $mem['text']];

    $pending = (int)hclouHealthScalar($db, "SELECT COUNT(*) FROM orders WHERE status='pending'");
    $approvedToday = (int)hclouHealthScalar($db, "SELECT COUNT(*) FROM orders WHERE status='approved' AND DATE(approved_at)=CURDATE()");
    $revenueToday = hclouHealthScalar($db, "SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='approved' AND DATE(approved_at)=CURDATE()");
    $bankBadToday = (int)hclouHealthScalar($db, "SELECT COUNT(*) FROM bank_transactions WHERE status IN ('ignored','error') AND DATE(created_at)=CURDATE()");
    $keysActive = (int)hclouHealthScalar($db, "SELECT COUNT(*) FROM `keys` WHERE status='active'");
    $keysExpired = (int)hclouHealthScalar($db, "SELECT COUNT(*) FROM `keys` WHERE status='expired'");

    $bad = array_values(array_filter($checks, fn($c) => !$c['ok']));
    $lines = [];
    foreach ($checks as $c) {
        $lines[] = ($c['ok'] ? '✅' : '⚠️') . ' ' . $c['name'] . ': ' . $c['detail'];
    }

    $title = empty($bad) ? '✅ HCLOU DAILY HEALTH CHECK OK' : '⚠️ HCLOU DAILY HEALTH CHECK CẦN KIỂM TRA';
    $msg = '<b>' . $title . '</b>' . "\n\n" .
        implode("\n", $lines) . "\n\n" .
        "📊 <b>Hôm nay</b>\n" .
        "• Đơn approved: {$approvedToday}\n" .
        "• Doanh thu: " . hclouHealthMoney($revenueToday) . "đ\n" .
        "• Pending hiện tại: {$pending}\n" .
        "• Bank ignored/error hôm nay: {$bankBadToday}\n" .
        "• Key active/expired: {$keysActive}/{$keysExpired}\n" .
        "\n⏰ " . date('Y-m-d H:i:s');

    sendTelegram(ADMIN_CHAT_ID, $msg);

    echo json_encode([
        'success' => empty($bad),
        'checks' => $checks,
        'stats' => [
            'approved_today' => $approvedToday,
            'revenue_today' => (float)$revenueToday,
            'pending' => $pending,
            'bank_bad_today' => $bankBadToday,
            'keys_active' => $keysActive,
            'keys_expired' => $keysExpired,
        ],
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(empty($bad) ? 0 : 2);
} catch (Throwable $e) {
    $err = '❌ <b>HCLOU DAILY HEALTH CHECK FAILED</b>' . "\n\n" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "\n⏰ " . date('Y-m-d H:i:s');
    if (defined('ADMIN_CHAT_ID')) sendTelegram(ADMIN_CHAT_ID, $err);
    fwrite(STDERR, json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
