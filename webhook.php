<?php
require_once 'config.php';

$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) exit;

$db = getDB();

// Xử lý callback_query (nút bấm inline)
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $data = $callback['data'];
    $from = $callback['from'];
    $chat_id = $callback['message']['chat']['id'];
    $message_id = $callback['message']['message_id'];

    // Kiểm tra admin
    $stmt = $db->prepare("SELECT * FROM admins WHERE telegram_id = ?");
    $stmt->execute([$from['id']]);
    $admin = $stmt->fetch();
    if (!$admin) {
        answerCallback($callback['id'], '❌ Bạn không có quyền admin!');
        exit;
    }

    // Duyệt tay đã tắt: đơn được auto duyệt bằng MBBANK API.
    if (strpos($data, 'approve_') === 0) {
        answerCallback($callback['id'], 'Duyệt tay đã tắt. Hệ thống sẽ tự duyệt khi bank ghi nhận.');
        exit;
    }

    // TỪ CHỐI đơn: reject_ORDERCODE
    if (strpos($data, 'reject_') === 0) {
        $order_code = substr($data, 7);
        rejectOrder($db, $order_code, $from['username'] ?? $from['first_name'], $callback['id'], $chat_id, $message_id);
    }

    // KHOÁ key: lock_KEYID
    if (strpos($data, 'lock_') === 0) {
        $key_id = substr($data, 5);
        $db->prepare("UPDATE `keys` SET status='locked' WHERE id=?")->execute([$key_id]);
        answerCallback($callback['id'], '🔒 Đã khoá key!');
    }
    exit;
}

// Xử lý message text
if (isset($update['message'])) {
    $msg = $update['message'];
    $chat_id = $msg['chat']['id'];
    $text = trim($msg['text'] ?? '');
    $from = $msg['from'];

    // Kiểm tra admin
    $stmt = $db->prepare("SELECT * FROM admins WHERE telegram_id = ?");
    $stmt->execute([$from['id']]);
    $admin = $stmt->fetch();

    if ($text === '/start') {
        // Bot phục vụ user mua hàng và admin quản lý.
        $keyboard = ['inline_keyboard' => [[
            ['text' => '🛒 Mua Key', 'web_app' => ['url' => SITE_URL . '/?v=payauto20260428_1']]
        ], [
            ['text' => '📢 HCLOU SERVER TEAM', 'url' => 'https://t.me/hclouserver']
        ]]];

        $welcome = "<b>Bot này có thể làm gì?</b>\n\n" .
                   "Chào mừng bạn đến với <b>HCLOU SERVER Bot</b>\n\n" .
                   "✅ Quản lý key chính bạn\n" .
                   "✅ Nhận key ngay sau khi bank\n" .
                   "✅ Reset key không giới hạn\n" .
                   "✅ Cập nhật đầy đủ bản giá mods\n\n" .
                   "<b>Lưu ý:</b> toàn bộ thông tin và link tải root và noroot đều được cập nhật tại kênh Telegram <b>HCLOU SERVER TEAM</b> chính thức.\n\n" .
                   "Nhấp vào nút <b>[Mua Key]</b> bên dưới để quản lý key của bạn.\n\n" .
                   "🆔 ID Telegram của bạn: <code>{$from['id']}</code>";

        if ($admin) {
            $welcome .= "\n\n🔑 Lệnh nhanh: /mykeys\n🔐 <b>Admin:</b> /orders · /stats";
        }
        sendTelegram($chat_id, $welcome, $keyboard);
    }



    if ($text === '/help') {
        $help = "🆘 <b>HCLOU SERVER - Hướng dẫn nhanh</b>

" .
                "🛒 <b>Mua key:</b> bấm nút Mua Key, chọn game/gói, xác nhận đơn.
" .
                "💳 <b>Thanh toán:</b> quét VietQR, hệ thống tự điền số tiền + nội dung ORD. Không sửa nội dung chuyển khoản.
" .
                "⏳ <b>Lỡ thoát app:</b> mở lại Mini App trong 15 phút để hiện lại QR thanh toán.
" .
                "✅ <b>Đã thanh toán:</b> auto-bank kiểm tra MBBANK mỗi phút; đúng tiền + đúng mã ORD sẽ tự active key.
" .
                "🎁 <b>GetKey Free:</b> vào Mini App, chọn Get Key Free và đi theo Link4M → YeuMoney → HCLOU claim.
" .
                "🔑 <b>Xem key:</b> dùng /mykeys hoặc mở Mini App.
" .
                "📢 <b>Hỗ trợ:</b> vào HCLOU SERVER TEAM nếu chuyển tiền sai nội dung/sai số tiền.";
        sendTelegram($chat_id, $help, ['inline_keyboard'=>[[['text'=>'🛒 Mở Mini App','web_app'=>['url'=>SITE_URL . '/?v=payauto20260428_1']],[ 'text'=>'📢 Team hỗ trợ','url'=>'https://t.me/hclouserver']]]]);
    }


    if ($text === '/mykeys') {
        $stmt = $db->prepare("SELECT k.*, g.name AS game_name, p.name AS pkg_name FROM `keys` k JOIN games g ON k.game_id=g.id JOIN packages p ON k.package_id=p.id JOIN users u ON k.user_id=u.id WHERE u.telegram_id=? ORDER BY k.created_at DESC LIMIT 10");
        $stmt->execute([$from['id']]);
        $keys = $stmt->fetchAll();
        if (!$keys) {
            sendTelegram($chat_id, "🔑 Bạn chưa có key nào. Bấm <b>Mua Key</b> để tạo đơn.", ['inline_keyboard'=>[[['text'=>'🛒 Mua Key','web_app'=>['url'=>SITE_URL . '/?v=payauto20260428_1']]]]]);
        } else {
            $out = "🔑 <b>KEY CỦA BẠN</b>\n\n";
            foreach ($keys as $k) {
                $exp = $k['expire_at'] ? date('d/m/Y H:i', strtotime($k['expire_at'])) : 'Chờ thanh toán';
                $out .= "🎮 <b>{$k['game_name']}</b> - {$k['pkg_name']}\n";
                $out .= "🔐 <code>{$k['key_code']}</code>\n";
                $out .= "📌 Trạng thái: <b>{$k['status']}</b> · Hết hạn: {$exp}\n\n";
            }
            sendTelegram($chat_id, $out, ['inline_keyboard'=>[[['text'=>'🛒 Mua / Quản lý Key','web_app'=>['url'=>SITE_URL . '/?v=payauto20260428_1']]]]]);
        }
    }

    if ($text === '/orders' && $admin) {
        $stmt = $db->query("SELECT o.*, u.telegram_username, g.name as game_name, p.name as pkg_name, p.days, k.key_code 
            FROM orders o JOIN users u ON o.user_id=u.id JOIN games g ON o.game_id=g.id JOIN packages p ON o.package_id=p.id LEFT JOIN `keys` k ON k.order_id=o.id AND k.status='pending'
            WHERE o.status='pending' ORDER BY o.created_at DESC LIMIT 10");
        $orders = $stmt->fetchAll();
        if (empty($orders)) {
            sendTelegram($chat_id, "✅ Không có đơn hàng nào đang chờ thanh toán.");
        } else {
            foreach ($orders as $o) {
                $amt = number_format($o['amount'], 0, ',', '.');
                $text = "🛒 <b>ĐƠN HÀNG #{$o['order_code']}</b>\n";
                $text .= "👤 User: @{$o['telegram_username']}\n";
                $text .= "🎮 Game: {$o['game_name']}\n";
                $text .= "📦 Gói: {$o['pkg_name']} ({$o['days']} ngày)\n";
                $text .= "🔑 Key đã tạo: <code>" . ($o['key_code'] ?: 'Chưa có') . "</code>\n";
                $text .= "💰 Số tiền: {$amt}đ\n";
                $text .= "🕐 Thời gian: " . date('d/m/Y H:i', strtotime($o['created_at']));
                $markup = ['inline_keyboard' => [[
                    ['text' => '❌ Từ chối', 'callback_data' => 'reject_' . $o['order_code']]
                ]]];
                sendTelegram($chat_id, $text, $markup);
            }
        }
    }

    if ($text === '/stats' && $admin) {
        $total_orders = $db->query("SELECT COUNT(*) FROM orders WHERE status='approved'")->fetchColumn();
        $total_revenue = $db->query("SELECT SUM(amount) FROM orders WHERE status='approved'")->fetchColumn();
        $total_keys = $db->query("SELECT COUNT(*) FROM `keys`")->fetchColumn();
        $active_keys = $db->query("SELECT COUNT(*) FROM `keys` WHERE status='active'")->fetchColumn();
        $total_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $rev = number_format($total_revenue ?? 0, 0, ',', '.');
        sendTelegram($chat_id, "📊 <b>THỐNG KÊ HỆ THỐNG</b>\n\n👥 Người dùng: {$total_users}\n🛒 Đơn thành công: {$total_orders}\n💰 Doanh thu: {$rev}đ\n🔑 Tổng key: {$total_keys}\n✅ Key đang active: {$active_keys}");
    }
    exit;
}

// =============================================
// HÀM XỬ LÝ
// =============================================

function rejectOrder($db, $order_code, $admin_name, $callback_id, $chat_id, $message_id) {
    $stmt = $db->prepare("SELECT * FROM orders WHERE order_code=? AND status='pending'");
    $stmt->execute([$order_code]);
    $order = $stmt->fetch();
    if (!$order) { answerCallback($callback_id, '❌ Đơn không tồn tại hoặc đã xử lý!'); return; }

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE orders SET status='rejected', approved_by=? WHERE id=?")
            ->execute([$admin_name, $order['id']]);
        $db->prepare("UPDATE `keys` SET status='locked' WHERE order_id=? AND status='pending'")
            ->execute([$order['id']]);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        answerCallback($callback_id, '❌ Lỗi hệ thống!');
        return;
    }

    answerCallback($callback_id, '❌ Đã từ chối đơn!');
    editMessage($chat_id, $message_id, "❌ <b>ĐÃ TỪ CHỐI #{$order_code}</b>
Admin: @{$admin_name}");

    $userStmt = $db->prepare("SELECT telegram_id FROM users WHERE id=?");
    $userStmt->execute([$order['user_id']]);
    $user = $userStmt->fetch();
    if ($user) sendTelegram($user['telegram_id'], "❌ <b>Đơn hàng #{$order_code} bị từ chối.</b>
Vui lòng liên hệ admin để được hỗ trợ.");
}


function answerCallback($callback_id, $text) {
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/answerCallbackQuery");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['callback_query_id' => $callback_id, 'text' => $text, 'show_alert' => true]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch); curl_close($ch);
}

function editMessage($chat_id, $message_id, $text) {
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/editMessageText");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text, 'parse_mode' => 'HTML']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch); curl_close($ch);
}
