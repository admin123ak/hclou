<?php
/**
 * PANEL KURO CONNECT API — HCLOU Edition
 *
 * Endpoint: POST https://hclou.com/api/connect.php
 * Params: game, user_key, serial
 *
 * Response giống y chang Panel Kuro Connect.php:
 *   status: true/false
 *   data: { modname, mod_status, credit, token, device, EXP, rng }
 *   reason: error message (nếu status=false)
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Static secret — giống Panel Kuro Connect.php line 33
$staticWords = defined('CONNECT_STATIC_WORDS') ? CONNECT_STATIC_WORDS : 'Vm8Lk7Uj2JmsjCPVPVjrLa7zgfx3uz9E';

$db = getDB();

// ===== LẤY PARAMS =====
$game  = trim($_POST['game']  ?? '');
$uKey  = trim($_POST['user_key'] ?? '');
$sDev  = trim($_POST['serial'] ?? '');

if (!$game || !$uKey || !$sDev) {
    echo json_encode(['status' => false, 'reason' => 'INVALID PARAMETER']);
    exit;
}

// ===== KIỂM TRA GAME =====
$stmt = $db->prepare("SELECT * FROM games WHERE (name = ? OR package_name = ?) AND is_active = 1 LIMIT 1");
$stmt->execute([$game, $game]);
$gameRow = $stmt->fetch();

if (!$gameRow) {
    echo json_encode(['status' => false, 'reason' => 'USER OR GAME NOT REGISTERED']);
    exit;
}

// ===== KIỂM TRA KEY (giống KeysModel::getKeysGame) =====
$stmt = $db->prepare("SELECT k.*, g.package_name as game_pkg_name, g.name as game_name
    FROM `keys` k
    JOIN games g ON k.game_id = g.id
    WHERE k.key_code = ? AND k.status = 'active'
    LIMIT 1");
$stmt->execute([$uKey]);
$findKey = $stmt->fetch();

if (!$findKey) {
    echo json_encode(['status' => false, 'reason' => 'USER OR GAME NOT REGISTERED']);
    exit;
}

$id_keys   = $findKey['id'];
$duration  = $findKey['duration_hours'] ?? ($findKey['days'] * 24);
$expired   = $findKey['expire_at'];
$max_dev   = $findKey['max_devices'] ?? 1;
$devices   = $findKey['devices'] ?? '';

// ===== CHECK STATUS (Panel Kuro: status != 1 → USER BLOCKED) =====
// HCLOU dùng ENUM active/expired/locked — locked = blocked
if ($findKey['status'] === 'locked') {
    echo json_encode(['status' => false, 'reason' => 'USER BLOCKED']);
    exit;
}

// ===== KIỂM TRA HẾT HẠN =====
$now = time();
$data = [];

if (!$expired) {
    // Key chưa set expire → set ngay (giống Panel Kuro line 221)
    $newExpiry = date('Y-m-d H:i:s', $now + ($duration * 3600));
    $db->prepare("UPDATE `keys` SET expire_at = ? WHERE id = ?")->execute([$newExpiry, $id_keys]);
    $data['status'] = true;
} else {
    $expTime = strtotime($expired);
    if ($expTime > $now) {
        $data['status'] = true;
    } else {
        $db->prepare("UPDATE `keys` SET status = 'expired' WHERE id = ?")->execute([$id_keys]);
        echo json_encode(['status' => false, 'reason' => 'EXPIRED KEY']);
        exit;
    }
}

// ===== DEVICE CHECK (giống checkDevicesAdd trong Panel Kuro Connect.php line 200-218) =====
$lsDevice = $devices ? explode(',', $devices) : [];
// Lọc phần tử rỗng
$lsDevice = array_values(array_filter($lsDevice, function($v) { return trim($v) !== ''; }));
$serialOn = in_array($sDev, $lsDevice, true);

if ($serialOn) {
    // Device đã đăng ký → cho qua
    $devicesUpdated = false;
} else {
    if (count($lsDevice) < $max_dev) {
        // Thêm device mới
        $lsDevice[] = $sDev;
        // reduce_multiples equivalent: implode rồi trim dấu phẩy thừa
        $setDevice = trim(implode(',', $lsDevice), ',');
        $db->prepare("UPDATE `keys` SET devices = ? WHERE id = ?")->execute([$setDevice, $id_keys]);
        $devicesUpdated = true;
    } else {
        echo json_encode(['status' => false, 'reason' => 'MAX DEVICE REACHED']);
        exit;
    }
}

// ===== BUILD RESPONSE (giống Panel Kuro Connect.php line 255-272) =====
if ($data['status']) {
    // modname = game package_name (giống SELECT * FROM modname WHERE id=1)
    $modname = $gameRow['package_name'] ?: $gameRow['name'];
    // mod_status = 'active' (giống SELECT * FROM _ftext WHERE id=1 → _status)
    $mod_status = 'active';
    // credit = key_code (giống _ftext → _ftext field)
    $credit = $uKey;
    // expiry
    $expiry = $findKey['expire_at'];
    if ($expiry == null) {
        $expiry = date('Y-m-d H:i:s', $now + ($duration * 3600));
    }

    $real = "{$game}-{$uKey}-{$sDev}-{$staticWords}";
    $token = md5($real);

    $data = [
        'status' => true,
        'data' => [
            'modname'    => $modname,
            'mod_status' => $mod_status,
            'credit'     => $credit,
            'token'      => $token,
            'device'     => (int)$max_dev,
            'EXP'        => $expiry,
            'rng'        => $now,
        ],
    ];
}

echo json_encode($data);
