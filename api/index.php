<?php
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$db = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateRules = [
    'auth' => [60, 60], 'games' => [120, 60], 'packages' => [120, 60],
    'create_order' => [8, 60], 'pending_orders' => [40, 60], 'order_status' => [80, 60], 'my_keys' => [80, 60],
    'get_free_link' => [10, 60], 'claim_free_key' => [10, 60],
    'reset_key' => [12, 60], 'reset_hwid' => [12, 60], 'delete_key' => [20, 60], 'search_key' => [60, 60],
];
if (isset($rateRules[$action])) { [$lim,$win] = $rateRules[$action]; rateLimit('api_'.$action, $lim, $win, $ip); }

// Xác thực Telegram Mini App initData cho các action cần user.
$tgVerifiedUser = null;
$initData = $_POST['init_data'] ?? $_GET['init_data'] ?? '';
if ($initData) $tgVerifiedUser = telegramUserFromInitData($initData);

function makeAppToken($telegramId) {
    return base64_encode($telegramId . '|' . time() . '|' . hash_hmac('sha256', $telegramId . '|' . time(), BOT_TOKEN));
}
function verifyAppToken($token) {
    if (!$token) return 0;
    $raw = base64_decode($token, true);
    if (!$raw) return 0;
    $parts = explode('|', $raw);
    if (count($parts) !== 3) return 0;
    [$telegramId, $ts, $sig] = $parts;
    if (!ctype_digit((string)$telegramId) || !ctype_digit((string)$ts)) return 0;
    if (time() - (int)$ts > 86400) return 0;
    $calc = hash_hmac('sha256', $telegramId . '|' . $ts, BOT_TOKEN);
    return hash_equals($calc, $sig) ? (int)$telegramId : 0;
}

$user = null;
$tokenTelegramId = verifyAppToken($_POST['app_token'] ?? $_GET['app_token'] ?? '');
// Fallback tạm thời: nếu Telegram WebApp không gửi initData ổn định thì dùng telegram_id từ frontend.
// Web thường vẫn bị chặn ở index.php bằng màn hình Telegram-only.
$fallbackTelegramId = $_POST['telegram_id'] ?? $_GET['telegram_id'] ?? 0;
$lookupTelegramId = $tgVerifiedUser['id'] ?? ($tokenTelegramId ?: $fallbackTelegramId);
if ($lookupTelegramId) {
    $stmt = $db->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->execute([$lookupTelegramId]);
    $user = $stmt->fetch();
}

switch ($action) {

    // ===== ĐĂNG NHẬP / TẠO USER =====
    case 'auth':
        $tg_id = $tgVerifiedUser['id'] ?? ($_POST['telegram_id'] ?? 0);
        if (!$tg_id) jsonResponse(['error' => 'Thiếu Telegram ID'], 401);
        $username = $tgVerifiedUser['username'] ?? ($_POST['username'] ?? '');
        $fullname = $tgVerifiedUser ? trim(($tgVerifiedUser['first_name'] ?? '') . ' ' . ($tgVerifiedUser['last_name'] ?? '')) : ($_POST['full_name'] ?? '');
        $avatar = $tgVerifiedUser['photo_url'] ?? ($_POST['avatar_url'] ?? '');
        
        $stmt = $db->prepare("SELECT * FROM users WHERE telegram_id = ?");
        $stmt->execute([$tg_id]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $db->prepare("UPDATE users SET telegram_username=?, full_name=?, avatar_url=? WHERE telegram_id=?")
               ->execute([$username, $fullname, $avatar, $tg_id]);
            $existing['telegram_username'] = $username;
            $existing['full_name'] = $fullname;
            jsonResponse(['success' => true, 'user' => $existing, 'app_token' => makeAppToken($tg_id)]);
        } else {
            $db->prepare("INSERT INTO users (telegram_id, telegram_username, full_name, avatar_url) VALUES (?,?,?,?)")
               ->execute([$tg_id, $username, $fullname, $avatar]);
            $user_id = $db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            jsonResponse(['success' => true, 'user' => $stmt->fetch(), 'app_token' => makeAppToken($tg_id)]);
        }

    // ===== DANH SÁCH GAME =====
    case 'games':
        $stmt = $db->query("SELECT * FROM games WHERE is_active=1 ORDER BY sort_order ASC");
        jsonResponse(['success' => true, 'games' => $stmt->fetchAll()]);

    // ===== GÓI THEO GAME =====
    case 'packages':
        $game_id = $_GET['game_id'] ?? 0;
        $stmt = $db->prepare("SELECT * FROM packages WHERE game_id=? AND is_active=1 ORDER BY days ASC");
        $stmt->execute([$game_id]);
        $packages = $stmt->fetchAll();
        $freeStmt = $db->prepare("SELECT fk.*, p.key_type FROM free_keys fk JOIN packages p ON fk.package_id=p.id LEFT JOIN free_key_claims c ON c.free_key_id=fk.id WHERE fk.game_id=? AND fk.is_active=1 AND fk.expire_at > NOW() AND c.id IS NULL ORDER BY fk.created_at DESC LIMIT 1");
        $freeStmt->execute([$game_id]);
        $free = $freeStmt->fetch();
        if ($free) {
            array_unshift($packages, [
                'id' => 'free',
                'name' => 'Get Key Free',
                'days' => (int)$free['days'],
                'price' => 0,
                'key_type' => $free['key_type'],
                'is_free' => 1,
                'free_key_id' => (int)$free['id']
            ]);
        }
        jsonResponse(['success' => true, 'packages' => $packages]);

    // ===== TẠO ĐƠN HÀNG (Panel Kuro style: custom key + max_devices) =====
    case 'create_order':
        if (!$user) jsonResponse(['error' => 'Chưa đăng nhập'], 401);
        $game_id = $_POST['game_id'] ?? 0;
        $package_id = $_POST['package_id'] ?? 0;
        $max_devices = max(1, (int)($_POST['max_devices'] ?? 1));
        $custom_key = trim($_POST['custom_key'] ?? '');

        $pkg = $db->prepare("SELECT p.*, g.name as game_name FROM packages p JOIN games g ON p.game_id=g.id WHERE p.id=? AND p.game_id=? AND p.is_active=1 AND g.is_active=1");
        $pkg->execute([$package_id, $game_id]);
        $package = $pkg->fetch();
        if (!$package) jsonResponse(['error' => 'Gói không tồn tại'], 404);

        // Calculate price: base price * max_devices
        $duration_hours = $package['duration_hours'] ?? ($package['days'] * 24);
        $final_price = calculatePrice($duration_hours, $max_devices);

        // Chống spam mua
        $dup = $db->prepare("SELECT o.order_code FROM orders o WHERE o.user_id=? AND o.game_id=? AND o.package_id=? AND o.status='pending' ORDER BY o.created_at DESC LIMIT 1");
        $dup->execute([$user['id'], $game_id, $package_id]);
        $pending_same = $dup->fetch();
        if ($pending_same) {
            jsonResponse(['error' => 'Bạn đã có đơn gói này đang chờ thanh toán', 'order_code' => $pending_same['order_code']], 429);
        }

        $recent = $db->prepare("SELECT COUNT(*) FROM orders WHERE user_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)");
        $recent->execute([$user['id']]);
        if ((int)$recent->fetchColumn() >= 1) {
            jsonResponse(['error' => 'Bạn thao tác quá nhanh, vui lòng chờ 30 giây rồi thử lại'], 429);
        }

        $pending_count = $db->prepare("SELECT COUNT(*) FROM orders WHERE user_id=? AND status='pending'");
        $pending_count->execute([$user['id']]);
        if ((int)$pending_count->fetchColumn() >= 3) {
            jsonResponse(['error' => 'Bạn đang có quá nhiều đơn chờ thanh toán, vui lòng hoàn tất hoặc chờ đơn cũ hết hiệu lực'], 429);
        }

        // Generate or use custom key
        if ($custom_key) {
            if (strlen($custom_key) < 6 || strlen($custom_key) > 30) {
                jsonResponse(['error' => 'Key phải từ 6-30 ký tự'], 400);
            }
            if (!preg_match('/^[a-zA-Z0-9]+$/', $custom_key)) {
                jsonResponse(['error' => 'Key chỉ được chứa chữ và số'], 400);
            }
            $check = $db->prepare("SELECT id FROM `keys` WHERE key_code=?");
            $check->execute([$custom_key]);
            if ($check->fetch()) {
                jsonResponse(['error' => 'Key này đã tồn tại, vui lòng chọn key khác'], 400);
            }
            $key_code = $custom_key;
        } else {
            $key_code = generateKey();
            $check = $db->prepare("SELECT id FROM `keys` WHERE key_code=?");
            do {
                $check->execute([$key_code]);
                if (!$check->fetch()) break;
                $key_code = generateKey();
            } while (true);
        }

        $order_code = generateOrderCode();
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO orders (order_code, user_id, game_id, package_id, amount, status) VALUES (?,?,?,?,?,'pending')")
               ->execute([$order_code, $user['id'], $game_id, $package_id, $final_price]);
            $order_id = $db->lastInsertId();

            // Tạo key pending với duration_hours và max_devices
            $db->prepare("INSERT INTO `keys` (key_code,user_id,game_id,package_id,order_id,status,days,duration_hours,max_devices,start_at,expire_at) VALUES (?,?,?,?,?,'pending',?,?,?,NULL,NULL)")
               ->execute([$key_code, $user['id'], $game_id, $package_id, $order_id, $package['days'], $duration_hours, $max_devices]);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['error' => 'Lỗi tạo đơn hàng'], 500);
        }

        // Thông báo admin qua Telegram
        $amt = number_format($final_price, 0, ',', '.');
        $username = $user['telegram_username'] ?? $user['full_name'];
        $msg = "🔔 <b>ĐƠN HÀNG MỚI #{$order_code}</b>\n\n👤 User: @{$username} (ID: {$user['telegram_id']})\n🎮 Game: {$package['game_name']}\n📦 Gói: {$package['name']} ({$duration_hours}h)\n🔑 Key: <code>{$key_code}</code>\n📱 Max devices: {$max_devices}\n💰 Số tiền: {$amt}đ\n🕐 " . date('d/m/Y H:i:s');
        $markup = ['inline_keyboard' => [[
            ['text' => '❌ Từ chối', 'callback_data' => 'reject_' . $order_code]
        ]]];
        sendTelegram(ADMIN_CHAT_ID, $msg . "\n\n🤖 Auto-bank sẽ tự duyệt khi nhận đúng tiền + nội dung.", $markup);

        jsonResponse([
            'success' => true,
            'order_code' => $order_code,
            'key_code' => $key_code,
            'amount' => $final_price,
            'max_devices' => $max_devices,
            'duration_hours' => $duration_hours,
            'bank_account' => BANK_ACCOUNT,
            'bank_name' => BANK_NAME,
            'bank_owner' => BANK_OWNER,
            'transfer_content' => $order_code,
            'vietqr_url' => buildVietQrUrl($final_price, $order_code),
            'created_at' => date('Y-m-d H:i:s'),
            'pay_expires_at' => date('Y-m-d H:i:s', time()+900),
            'server_time' => date('Y-m-d H:i:s'),
        ]);



    case 'claim_free_key':
        if (!$user) jsonResponse(['error' => 'Chưa đăng nhập'], 401);
        $token = $_POST['token'] ?? $_GET['token'] ?? '';
        if (!$token) jsonResponse(['error' => 'Thiếu token claim'], 400);
        $stmt = $db->prepare("SELECT fk.*, g.name game_name, p.name pkg_name FROM free_keys fk JOIN games g ON fk.game_id=g.id JOIN packages p ON fk.package_id=p.id WHERE fk.claim_token=?");
        $stmt->execute([$token]);
        $fk = $stmt->fetch();
        if (!$fk) jsonResponse(['error' => 'Link claim không hợp lệ'], 404);
        if (!$fk['is_active'] || strtotime($fk['expire_at']) < time()) jsonResponse(['error' => 'Key free đã hết hạn'], 410);
        $chk = $db->prepare("SELECT c.*, k.key_code, c.user_id AS claimed_user_id FROM free_key_claims c LEFT JOIN `keys` k ON c.key_id=k.id WHERE c.free_key_id=? LIMIT 1");
        $chk->execute([$fk['id']]);
        $old = $chk->fetch();
        if ($old && (int)$old['claimed_user_id'] === (int)$user['id']) jsonResponse(['success'=>true, 'already'=>true, 'message'=>'Bạn đã nhận key free này rồi', 'key_code'=>$old['key_code']]);
        if ($old) jsonResponse(['error'=>'Key free này đã có người nhận'], 410);
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO `keys` (key_code,user_id,game_id,package_id,status,days,start_at,expire_at) VALUES (?,?,?,?, 'active', ?, ?, ?)")
               ->execute([$fk['key_code'],$user['id'],$fk['game_id'],$fk['package_id'],$fk['days'],$fk['start_at'],$fk['expire_at']]);
            $kid = $db->lastInsertId();
            $db->prepare("INSERT INTO free_key_claims (free_key_id,user_id,key_id) VALUES (?,?,?)")->execute([$fk['id'],$user['id'],$kid]);
            $db->prepare("UPDATE free_keys SET is_active=0 WHERE id=?")->execute([$fk['id']]);
            $db->commit();
            jsonResponse(['success'=>true, 'message'=>'Nhận key free thành công', 'key_code'=>$fk['key_code']]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['error'=>'Không nhận được key: '.$e->getMessage()], 500);
        }

    case 'get_free_link':
        if (!$user) jsonResponse(['error' => 'Chưa đăng nhập'], 401);
        if (!FREE_GETKEY_ENABLED) jsonResponse(['error' => 'GetKey Free đang tắt'], 403);
        $game_id = (int)($_POST['game_id'] ?? $_GET['game_id'] ?? 0);
        $free_key_id = (int)($_POST['package_id'] ?? $_GET['package_id'] ?? 0);
        $where = "fk.is_active=1 AND fk.expire_at > NOW() AND c.id IS NULL";
        $params = [];
        if ($game_id > 0) { $where .= " AND fk.game_id=?"; $params[] = $game_id; }
        if ($free_key_id > 0) { $where .= " AND fk.id=?"; $params[] = $free_key_id; }
        $stmt = $db->prepare("SELECT fk.*, g.name game_name, p.name pkg_name FROM free_keys fk JOIN games g ON fk.game_id=g.id JOIN packages p ON fk.package_id=p.id LEFT JOIN free_key_claims c ON c.free_key_id=fk.id WHERE {$where} ORDER BY fk.created_at DESC LIMIT 1");
        $stmt->execute($params);
        $fk = $stmt->fetch();
        if (!$fk) jsonResponse(['error' => 'Chưa có key free khả dụng'], 404);
        $chk = $db->prepare("SELECT id FROM free_key_claims WHERE free_key_id=? AND user_id=?");
        $chk->execute([$fk['id'], $user['id']]);
        if ($chk->fetch()) jsonResponse(['error' => 'Bạn đã nhận key free này rồi'], 429);
        $url = $fk['short_url'];
        if (!$url) {
            $claimUrl = SITE_URL . '/claim.php?t=' . urlencode($fk['claim_token']);
            $url = buildFreeShortlink($claimUrl);
            $db->prepare("UPDATE free_keys SET short_url=? WHERE id=?")->execute([$url, $fk['id']]);
        }
        jsonResponse(['success'=>true, 'url'=>$url, 'game_name'=>$fk['game_name'], 'pkg_name'=>$fk['pkg_name'], 'expire_at'=>$fk['expire_at']]);

    // ===== ĐƠN CHỜ THANH TOÁN CỦA USER =====
    case 'pending_orders':
        if (!$user) jsonResponse(['error' => 'Chưa đăng nhập'], 401);
        $stmt = $db->prepare("SELECT o.order_code,o.amount,o.created_at, DATE_ADD(o.created_at, INTERVAL 15 MINUTE) pay_expires_at, GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(o.created_at, INTERVAL 15 MINUTE))) pay_seconds_left, NOW() server_time, g.name game_name,p.name pkg_name,p.days,k.key_code
            FROM orders o
            JOIN games g ON o.game_id=g.id
            JOIN packages p ON o.package_id=p.id
            LEFT JOIN `keys` k ON k.order_id=o.id AND k.status='pending'
            WHERE o.user_id=? AND o.status='pending' AND o.created_at >= (NOW() - INTERVAL 15 MINUTE)
            ORDER BY o.created_at DESC LIMIT 5");
        $stmt->execute([$user['id']]);
        $orders = $stmt->fetchAll();
        foreach ($orders as &$o) {
            $o['bank_account'] = BANK_ACCOUNT;
            $o['bank_name'] = BANK_NAME;
            $o['bank_owner'] = BANK_OWNER;
            $o['transfer_content'] = $o['order_code'];
            $o['vietqr_url'] = buildVietQrUrl($o['amount'], $o['order_code']);
        }
        unset($o);
        jsonResponse(['success'=>true,'orders'=>$orders]);

    // ===== KEY CỦA USER =====
    case 'my_keys':
        if (!$user) jsonResponse(['error' => 'Chưa đăng nhập'], 401);
        $filter = $_GET['filter'] ?? 'all'; // all, active, expired, locked
        
        $sql = "SELECT k.*, g.name as game_name, g.package_name, p.name as pkg_name, p.key_type 
                FROM `keys` k JOIN games g ON k.game_id=g.id JOIN packages p ON k.package_id=p.id
                WHERE k.user_id=?";
        $params = [$user['id']];
        
        // Cập nhật trạng thái expired
        $db->prepare("UPDATE `keys` SET status='expired' WHERE user_id=? AND status='active' AND expire_at < NOW()")
           ->execute([$user['id']]);
        
        if ($filter === 'active') $sql .= " AND k.status='active'";
        elseif ($filter === 'expired') $sql .= " AND k.status='expired'";
        elseif ($filter === 'locked') $sql .= " AND k.status='locked'";
        $sql .= " ORDER BY k.created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $keys = $stmt->fetchAll();
        foreach ($keys as &$k) {
            if ($k['status'] === 'expired' && !empty($k['expire_at'])) {
                $deleteAt = date('Y-m-d H:i:s', strtotime($k['expire_at'] . ' +3 days'));
                $k['delete_at'] = $deleteAt;
                $k['delete_note'] = 'Không gia hạn sau 3 ngày kể từ lúc hết hạn, key sẽ tự xoá.';
            }
        }
        unset($k);
        
        // Stats
        $stats = $db->prepare("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status='expired' THEN 1 ELSE 0 END) as expired
            FROM `keys` WHERE user_id=?");
        $stats->execute([$user['id']]);
        $stats_data = $stats->fetch();
        
        jsonResponse(['success' => true, 'keys' => $keys, 'stats' => $stats_data]);

    // ===== TÌM KIẾM KEY =====
    case 'search_key':
        if (!$user) jsonResponse(['error' => 'Chưa đăng nhập'], 401);
        $q = $_GET['q'] ?? '';
        $stmt = $db->prepare("SELECT k.*, g.name as game_name, g.package_name, p.name as pkg_name, p.key_type 
            FROM `keys` k JOIN games g ON k.game_id=g.id JOIN packages p ON k.package_id=p.id
            WHERE k.user_id=? AND k.key_code LIKE ?");
        $stmt->execute([$user['id'], "%$q%"]);
        jsonResponse(['success' => true, 'keys' => $stmt->fetchAll()]);

    // ===== RESET KEY =====
    case 'reset_key':
        if (!$user) jsonResponse(['error' => 'Chưa đăng nhập'], 401);
        $key_id = $_POST['key_id'] ?? 0;
        $stmt = $db->prepare("SELECT * FROM `keys` WHERE id=? AND user_id=?");
        $stmt->execute([$key_id, $user['id']]);
        $key = $stmt->fetch();
        if (!$key) jsonResponse(['error' => 'Key không tồn tại'], 404);
        if ($key['reset_count'] >= $key['max_reset']) jsonResponse(['error' => 'Đã hết lượt reset!'], 400);
        if ($key['status'] !== 'active') jsonResponse(['error' => 'Key không active!'], 400);

        $db->prepare("UPDATE `keys` SET reset_count=reset_count+1 WHERE id=?")->execute([$key_id]);
        jsonResponse(['success' => true, 'remaining_resets' => $key['max_reset'] - $key['reset_count'] - 1]);

    // ===== RESET HWID (Panel Kuro style: clear devices) =====
    case 'reset_hwid':
        if (!$user) jsonResponse(['error' => 'Chưa đăng nhập'], 401);
        $key_id = $_POST['key_id'] ?? 0;

        $stmt = $db->prepare("SELECT * FROM `keys` WHERE id=? AND user_id=?");
        $stmt->execute([$key_id, $user['id']]);
        $key = $stmt->fetch();

        if (!$key) jsonResponse(['error' => 'Key không tồn tại'], 404);
        if ($key['status'] !== 'active') jsonResponse(['error' => 'Key không active!'], 400);

        // Reset devices column (clear all registered device IDs)
        $db->prepare("UPDATE `keys` SET devices=NULL WHERE id=?")->execute([$key_id]);

        jsonResponse(['success' => true, 'message' => 'Reset HWID thành công! Tất cả thiết bị đã được xóa.']);

    // ===== XOÁ KEY =====
    case 'delete_key':
        if (!$user) jsonResponse(['error' => 'Chưa đăng nhập'], 401);
        $key_id = $_POST['key_id'] ?? 0;
        $db->prepare("DELETE FROM `keys` WHERE id=? AND user_id=? AND status IN ('expired','locked')")->execute([$key_id, $user['id']]);
        jsonResponse(['success' => true]);

    // ===== TRẠNG THÁI ĐƠN HÀNG =====
    case 'order_status':
        if (!$user) jsonResponse(['error' => 'Chưa đăng nhập'], 401);
        $order_code = $_GET['order_code'] ?? '';
        $stmt = $db->prepare("SELECT o.*, g.name as game_name, p.name as pkg_name FROM orders o JOIN games g ON o.game_id=g.id JOIN packages p ON o.package_id=p.id WHERE o.order_code=? AND o.user_id=?");
        $stmt->execute([$order_code, $user['id']]);
        jsonResponse(['success' => true, 'order' => $stmt->fetch()]);

    default:
        jsonResponse(['error' => 'Action không hợp lệ'], 400);
}
