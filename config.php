<?php
// =============================================
// CẤU HÌNH HỆ THỐNG - SỬA THÔNG TIN Ở ĐÂY
// =============================================

// Database
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'hcloucom_panel');        // Tên database trên cPanel
define('DB_USER', 'hcloucom_panel');           // Username database cPanel
define('DB_PASS', 'hcloucom_panel');               // Password database cPanel
define('DB_CHARSET', 'utf8mb4');

// Telegram Bot
define('BOT_TOKEN', '8625693694:AAFWvN0PkneImU3okv-UvPFdRv_vKZFbvOY');   // Token từ @BotFather
define('ADMIN_CHAT_ID', '1985248892');          // Chat ID admin nhận thông báo
define('BOT_USERNAME', 'hclougetkey_bot');   // Username bot (không có @)

// Website
define('SITE_URL', 'https://hclou.com');  // Domain của bạn
define('SITE_NAME', 'HCLOU SERVER');

// Admin panel security
define('ADMIN_PASSWORD_HASH', '$2y$10$GX6oh7tAaEd5DQ6SkyJKGuZ9t24ijSwZHx9NArCs3q1yJxaGrCtJ.');
define('ADMIN_SESSION_TTL', 3600);

// Thông tin thanh toán (hiển thị cho user)
define('BANK_NAME', 'MBBANK');
define('BANK_ACCOUNT', '0868641019');
define('BANK_OWNER', 'TRẦN VĂN HOÀNG');
define('VIETQR_BANK_ID', '970422'); // MBBank BIN/NAPAS bank id


// MBBANK transaction history API (Queenvps)
// Admin chỉ cần nhập token, hệ thống tự ghép URL: https://queenvps.com/api/historymb/{TOKEN}
define('MBBANK_HISTORY_API_TOKEN', 'MB_FREE_021FA4D804026B08');
define('MBBANK_HISTORY_API_URL', 'http://127.0.0.1:3120/history');
define('MBBANK_AUTO_APPROVE_ENABLED', true);
define('MBBANK_POLL_SECRET', hash_hmac('sha256', MBBANK_HISTORY_API_URL, BOT_TOKEN));

// Free GetKey shortlink APIs
define('LINK4M_API_TOKEN', '69f0894f3bb1c61f3703a5d7');
define('YEUMONEY_API_TOKEN', '5f2e7edafeea3ff426347f8c1eb5f77d55ec266a4be3a11a6bf8d612fe0bc8c3');
define('FREE_GETKEY_ENABLED', true);

// Timezone
date_default_timezone_set('Asia/Ho_Chi_Minh');



// Telegram Mini App initData verification
function verifyTelegramInitData($initData) {
    if (!$initData || !is_string($initData)) return false;
    parse_str($initData, $data);
    if (empty($data['hash'])) return false;
    $hash = $data['hash'];
    unset($data['hash']);
    ksort($data);
    $pairs = [];
    foreach ($data as $k => $v) $pairs[] = $k . '=' . $v;
    $checkString = implode("\n", $pairs);
    $secret = hash_hmac('sha256', BOT_TOKEN, 'WebAppData', true);
    $calc = hash_hmac('sha256', $checkString, $secret);
    if (!hash_equals($calc, $hash)) return false;
    if (!empty($data['auth_date']) && time() - (int)$data['auth_date'] > 86400) return false;
    return $data;
}

function telegramUserFromInitData($initData) {
    $verified = verifyTelegramInitData($initData);
    if (!$verified || empty($verified['user'])) return null;
    $user = json_decode($verified['user'], true);
    return is_array($user) ? $user : null;
}

// =============================================
// KẾT NỐI DATABASE
// =============================================
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Database connection failed']));
        }
    }
    return $pdo;
}

// =============================================
// GỬI TELEGRAM
// =============================================
function sendTelegram($chat_id, $text, $reply_markup = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}



function httpJsonRequest($url, $method='GET', $headers=[], $body=null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>2]);
    if ($method !== 'GET') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $json = json_decode($raw, true);
    return ['ok'=>!$err && $code>=200 && $code<300, 'code'=>$code, 'raw'=>$raw, 'json'=>$json, 'error'=>$err];
}
function pickShortUrl($json, $raw='') {
    if (is_array($json)) {
        foreach (['shorturl','short_url','shortenedUrl','shortened_url','url','link','short','result'] as $k) {
            if (!empty($json[$k]) && is_string($json[$k]) && preg_match('~^https?://~', $json[$k])) return $json[$k];
        }
        if (!empty($json['data']) && is_array($json['data'])) {
            $u = pickShortUrl($json['data']); if ($u) return $u;
        }
    }
    if ($raw && preg_match('~https?://[^\s"\']+~', $raw, $m)) return $m[0];
    return '';
}
function shortenLink4M($longUrl, &$debug = null) {
    // Link4M endpoint owner provided. This endpoint returns the monetized task page directly,
    // so the endpoint URL itself is the Link4M layer.
    $st = 'https://link4m.co/st?api='.rawurlencode(LINK4M_API_TOKEN).'&url='.rawurlencode($longUrl);
    $debug = [];
    $res = httpJsonRequest($st);
    $raw = (string)$res['raw'];
    $debug[] = ['endpoint'=>$st, 'code'=>$res['code'], 'ok'=>$res['ok'], 'short'=>$st, 'raw'=>substr($raw, 0, 220)];
    if ($res['code'] >= 200 && $res['code'] < 400 && stripos($raw, 'Vượt') !== false) return $st;

    // Fallback formats if Link4M changes API response to plain/json short URL later.
    foreach ([
        'https://link4m.co/api?api='.rawurlencode(LINK4M_API_TOKEN).'&url='.rawurlencode($longUrl),
        'https://my.link4m.com/api?api='.rawurlencode(LINK4M_API_TOKEN).'&url='.rawurlencode($longUrl),
    ] as $ep) {
        $res = httpJsonRequest($ep);
        $u = pickShortUrl($res['json'], '');
        if (!$u && is_string($res['raw']) && preg_match('~https?://[^\s"\'<>]+~', $res['raw'], $m)) $u = $m[0];
        $debug[] = ['endpoint'=>$ep, 'code'=>$res['code'], 'ok'=>$res['ok'], 'short'=>$u, 'raw'=>substr((string)$res['raw'], 0, 220)];
        if ($u && $u !== $longUrl && preg_match('~^https?://([^/]+\.)?link4m\.co/~i', $u)) return $u;
    }
    return '';
}
function shortenYeuMoney($longUrl, &$debug = null) {
    $endpoints = [
        'https://yeumoney.com/QL_api.php?token='.rawurlencode(YEUMONEY_API_TOKEN).'&format=json&url='.rawurlencode($longUrl),
        'https://yeumoney.com/api?api='.rawurlencode(YEUMONEY_API_TOKEN).'&url='.rawurlencode($longUrl),
        'https://yeumoney.com/st?api='.rawurlencode(YEUMONEY_API_TOKEN).'&url='.rawurlencode($longUrl),
    ];
    $debug = [];
    foreach ($endpoints as $ep) {
        $res=httpJsonRequest($ep); $u=pickShortUrl($res['json'],$res['raw']);
        $debug[] = ['endpoint'=>$ep, 'code'=>$res['code'], 'ok'=>$res['ok'], 'short'=>$u, 'raw'=>substr((string)$res['raw'], 0, 220)];
        if ($u && $u !== $longUrl && preg_match('~yeumoney~i', $u)) return $u;
    }
    return '';
}
function buildFreeShortlink($claimUrl, &$debug = null) {
    // Required chain: Link4M -> YeuMoney -> HCLOU claim.
    // User must pass Link4M first, then YeuMoney, then claim key.
    $debug = [];
    $ym = shortenYeuMoney($claimUrl, $ymDebug);
    $debug['yeumoney'] = $ymDebug;
    if (!$ym) throw new Exception('YeuMoney API không tạo được link');
    $link4 = shortenLink4M($ym, $l4Debug);
    $debug['link4m'] = $l4Debug;
    if (!$link4) throw new Exception('Link4M API không tạo được link. Kiểm tra lại token/API Link4M.');
    return $link4;
}


function buildVietQrUrl($amount, $content) {
    $bank = defined('VIETQR_BANK_ID') ? VIETQR_BANK_ID : '970422';
    $account = BANK_ACCOUNT;
    $template = 'qr_only';
    $params = [
        'amount' => (int)$amount,
        'addInfo' => $content,
        'accountName' => BANK_OWNER,
    ];
    return 'https://img.vietqr.io/image/' . rawurlencode($bank) . '-' . rawurlencode($account) . '-' . $template . '.png?' . http_build_query($params);
}

// =============================================
// SINH KEY NGẪU NHIÊN (Panel Kuro style: 20 chars)
// =============================================
function generateKey() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $key = '';
    for ($i = 0; $i < 20; $i++) {
        $key .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $key;
}

// =============================================
// TÍNH GIÁ THEO DURATION VÀ MAX_DEVICES (Panel Kuro pricing)
// =============================================
function calculatePrice($durationHours, $maxDevices, $packagePrice = null) {
    // Nếu có giá từ package (database) thì dùng luôn
    if ($packagePrice !== null) {
        $base = max(0, (float)$packagePrice);
        return $base > 0 ? $base * $maxDevices : 0;
    }
    // Fallback: pricing map mặc định
    $priceMap = [
        2 => 5000,      // 2 Hours
        5 => 10000,     // 5 Hours
        24 => 25000,    // 1 Day
        72 => 50000,    // 3 Days
        168 => 75000,   // 7 Days
        336 => 100000,  // 14 Days
        720 => 120000,  // 30 Days
        1440 => 200000, // 60 Days
    ];
    $basePrice = $priceMap[$durationHours] ?? 0;
    return $basePrice * $maxDevices;
}

// =============================================
// SINH MÃ ĐƠN HÀNG
// =============================================
function generateOrderCode() {
    return 'ORD' . date('ymd') . strtoupper(substr(uniqid(), -6));
}

// Header JSON
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Kiểm tra đăng nhập
function getUser() {
    session_start();
    if (!isset($_SESSION['user_id'])) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}


// Admin config editor helpers
function hclouConfigEditableKeys() {
    return [
        'SITE_URL'=>'string','SITE_NAME'=>'string','ADMIN_CHAT_ID'=>'string','BOT_USERNAME'=>'string',
        'BANK_NAME'=>'string','BANK_ACCOUNT'=>'string','BANK_OWNER'=>'string','VIETQR_BANK_ID'=>'string',
        'MBBANK_HISTORY_API_TOKEN'=>'string','MBBANK_AUTO_APPROVE_ENABLED'=>'bool',
        'LINK4M_API_TOKEN'=>'string','YEUMONEY_API_TOKEN'=>'string','FREE_GETKEY_ENABLED'=>'bool',
    ];
}
function hclouConfigValue($key) { return defined($key) ? constant($key) : null; }
function hclouWriteConfigValues(array $updates, $admin='web_admin') {
    $allowed = hclouConfigEditableKeys();
    $configFile = __FILE__;
    $src = file_get_contents($configFile);
    $changed = [];
    foreach ($updates as $key=>$val) {
        if (!isset($allowed[$key])) continue;
        $type = $allowed[$key];
        $old = hclouConfigValue($key);
        if ($type === 'bool') {
            $newVal = !empty($val) && !in_array(strtolower((string)$val), ['0','false','off','no'], true);
            $replacement = "define('{$key}', " . ($newVal ? 'true' : 'false') . ");";
        } else {
            $newVal = trim((string)$val);
            $replacement = "define('{$key}', " . var_export($newVal, true) . ");";
        }
        if ((string)$old === (string)$newVal) continue;
        $pattern = "/define\\('".preg_quote($key,'/')."'\\s*,\\s*.*?\\);/";
        $count = 0;
        $src = preg_replace($pattern, $replacement, $src, 1, $count);
        if ($count !== 1) throw new Exception("Không tìm thấy config {$key}");
        $changed[$key] = ['old'=>$old, 'new'=>$newVal];
    }
    if (!$changed) return [];
    $backup = $configFile . '.bk_admincfg_' . date('Ymd_His');
    copy($configFile, $backup);
    if (file_put_contents($configFile, $src, LOCK_EX) === false) throw new Exception('Không ghi được config.php');
    try {
        $db = getDB();
        ensureAdminConfigLogTable($db);
        $stmt = $db->prepare("INSERT INTO admin_config_logs (admin, config_key, old_value, new_value, created_at) VALUES (?,?,?,?,NOW())");
        foreach ($changed as $k=>$v) {
            $mask = preg_match('/TOKEN|API|SECRET|PASS|HASH/i', $k);
            $stmt->execute([$admin, $k, $mask ? '[hidden]' : (string)$v['old'], $mask ? '[hidden]' : (string)$v['new']]);
        }
    } catch (Throwable $e) { /* config already written; do not fail UI */ }
    return $changed;
}
function ensureAdminConfigLogTable(PDO $db) {
    $db->exec("CREATE TABLE IF NOT EXISTS admin_config_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin VARCHAR(100) NOT NULL,
        config_key VARCHAR(100) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_config_logs_created (created_at),
        INDEX idx_config_logs_key (config_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}


function rateLimit($scope, $limit, $windowSeconds, $identity = null) {
    $identity = $identity ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $dir = sys_get_temp_dir() . '/hclou_rate_limit';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $scope . '_' . hash('sha256', $identity)) . '.json';
    $now = time();
    $data = ['start'=>$now, 'count'=>0];
    if (is_file($file)) {
        $old = json_decode((string)@file_get_contents($file), true);
        if (is_array($old) && !empty($old['start']) && ($now - (int)$old['start']) < $windowSeconds) $data = $old;
    }
    if (($now - (int)$data['start']) >= $windowSeconds) $data = ['start'=>$now, 'count'=>0];
    $data['count'] = (int)$data['count'] + 1;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    if ($data['count'] > $limit) {
        jsonResponse(['error'=>'Bạn thao tác quá nhanh, vui lòng thử lại sau.'], 429);
    }
}
