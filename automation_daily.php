<?php
require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function hclouMoneyFmt($n): string { return number_format((float)$n, 0, ',', '.'); }
function hclouScalar(PDO $db, string $sql, array $params=[]){
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

function runHclouAutomation(PDO $db): array {
    $out = [
        'reminded' => 0,
        'cancel_notified' => 0,
        'admin_bank_alerts' => 0,
        'daily_report' => 0,
    ];

    // 1) Nhắc user khi đơn còn <= 5 phút trước khi hết hạn 15 phút.
    $orders = $db->query("SELECT o.*, u.telegram_id, g.name game_name, p.name pkg_name,
        TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(o.created_at, INTERVAL 15 MINUTE)) seconds_left
        FROM orders o
        JOIN users u ON o.user_id = u.id
        JOIN games g ON o.game_id = g.id
        JOIN packages p ON o.package_id = p.id
        WHERE o.status = 'pending'
          AND o.created_at >= (NOW() - INTERVAL 15 MINUTE)
          AND TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(o.created_at, INTERVAL 15 MINUTE)) BETWEEN 1 AND 300
          AND (o.admin_note IS NULL OR o.admin_note NOT LIKE '%AUTO_REMIND_5M%')
        ORDER BY o.created_at ASC
        LIMIT 50")->fetchAll();

    foreach ($orders as $o) {
        $min = max(1, (int)ceil(((int)$o['seconds_left']) / 60));
        sendTelegram($o['telegram_id'],
            "⏰ <b>Nhắc thanh toán đơn #{$o['order_code']}</b>\n\n" .
            "🎮 {$o['game_name']} - {$o['pkg_name']}\n" .
            "💰 Số tiền: " . hclouMoneyFmt($o['amount']) . "đ\n" .
            "⏳ Còn khoảng {$min} phút trước khi đơn tự huỷ.\n\n" .
            "Bấm mở Mini App để xem lại QR/thông tin chuyển khoản.",
            ['inline_keyboard' => [[
                ['text' => '💳 Mở lại thanh toán', 'web_app' => ['url' => SITE_URL . '/?v=payauto20260428_1']]
            ]]]
        );
        $db->prepare("UPDATE orders SET admin_note = CONCAT(COALESCE(admin_note,''), '\nAUTO_REMIND_5M ', NOW()) WHERE id = ?")
           ->execute([$o['id']]);
        $out['reminded']++;
    }

    // 2) Báo user các đơn đã tự huỷ do quá hạn 15 phút.
    $cancelled = $db->query("SELECT o.*, u.telegram_id, g.name game_name, p.name pkg_name
        FROM orders o
        JOIN users u ON o.user_id = u.id
        JOIN games g ON o.game_id = g.id
        JOIN packages p ON o.package_id = p.id
        WHERE o.status = 'cancelled'
          AND o.admin_note LIKE '%Tự huỷ do quá 15 phút%'
          AND o.admin_note NOT LIKE '%AUTO_CANCEL_NOTIFIED%'
        ORDER BY o.created_at DESC
        LIMIT 50")->fetchAll();

    foreach ($cancelled as $o) {
        sendTelegram($o['telegram_id'],
            "❌ <b>Đơn #{$o['order_code']} đã tự huỷ</b>\n\n" .
            "Lý do: quá 15 phút chưa ghi nhận thanh toán.\n" .
            "Nếu bạn đã chuyển tiền, vui lòng liên hệ admin và gửi mã đơn này để kiểm tra."
        );
        $db->prepare("UPDATE orders SET admin_note = CONCAT(COALESCE(admin_note,''), '\nAUTO_CANCEL_NOTIFIED ', NOW()) WHERE id = ?")
           ->execute([$o['id']]);
        $out['cancel_notified']++;
    }

    // 3) Báo admin giao dịch bank lỗi/ignored mới.
    $txs = $db->query("SELECT * FROM bank_transactions
        WHERE status IN ('ignored','error')
          AND created_at >= (NOW() - INTERVAL 2 DAY)
          AND (note IS NULL OR note NOT LIKE '%ADMIN_ALERTED%')
        ORDER BY created_at ASC
        LIMIT 20")->fetchAll();

    foreach ($txs as $tx) {
        $desc = substr(trim((string)$tx['description']), 0, 180);
        sendTelegram(ADMIN_CHAT_ID,
            "⚠️ <b>Giao dịch bank cần kiểm tra</b>\n\n" .
            "📌 Trạng thái: <b>{$tx['status']}</b>\n" .
            "💰 Số tiền: " . hclouMoneyFmt($tx['amount']) . "đ\n" .
            "🧾 Mã đơn: <code>" . ($tx['order_code'] ?: 'Không có') . "</code>\n" .
            "📝 Ghi chú: " . htmlspecialchars($tx['note'] ?: 'Không có', ENT_QUOTES, 'UTF-8') . "\n" .
            "📄 Nội dung: <code>" . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . "</code>\n" .
            "⏰ {$tx['created_at']}"
        );
        $db->prepare("UPDATE bank_transactions SET note = CONCAT(COALESCE(note,''), '\nADMIN_ALERTED ', NOW()) WHERE id = ?")
           ->execute([$tx['id']]);
        $out['admin_bank_alerts']++;
    }

    // 4) Báo cáo ngày: chỉ gửi nếu script được chạy trong khung 23:55-23:59.
    $hour = (int)date('H');
    $minute = (int)date('i');
    if ($hour === 23 && $minute >= 55) {
        try {
            $exists = (int)hclouScalar($db, "SELECT COUNT(*) FROM admin_config_logs WHERE config_key = ? AND DATE(created_at) = CURDATE()", ['DAILY_REPORT_SENT']);
        } catch (Throwable $e) {
            $exists = 1;
        }

        if (!$exists) {
            $approved = (int)hclouScalar($db, "SELECT COUNT(*) FROM orders WHERE status='approved' AND DATE(approved_at)=CURDATE()");
            $revenue = hclouScalar($db, "SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='approved' AND DATE(approved_at)=CURDATE()");
            $pending = (int)hclouScalar($db, "SELECT COUNT(*) FROM orders WHERE status='pending'");
            $cancelledToday = (int)hclouScalar($db, "SELECT COUNT(*) FROM orders WHERE status IN ('cancelled','rejected') AND DATE(created_at)=CURDATE()");
            $active = (int)hclouScalar($db, "SELECT COUNT(*) FROM `keys` WHERE status='active'");
            $expired = (int)hclouScalar($db, "SELECT COUNT(*) FROM `keys` WHERE status='expired'");
            $bankBad = (int)hclouScalar($db, "SELECT COUNT(*) FROM bank_transactions WHERE status IN ('ignored','error') AND DATE(created_at)=CURDATE()");

            sendTelegram(ADMIN_CHAT_ID,
                "📊 <b>BÁO CÁO HCLOU HÔM NAY</b>\n\n" .
                "✅ Đơn thành công: {$approved}\n" .
                "💰 Doanh thu: " . hclouMoneyFmt($revenue) . "đ\n" .
                "⏳ Đơn pending hiện tại: {$pending}\n" .
                "❌ Đơn huỷ/từ chối hôm nay: {$cancelledToday}\n" .
                "🔑 Key active: {$active}\n" .
                "⏰ Key expired: {$expired}\n" .
                "🏦 Bank lỗi/cần kiểm tra hôm nay: {$bankBad}"
            );
            $db->prepare("INSERT INTO admin_config_logs (admin, config_key, old_value, new_value, created_at) VALUES (?,?,?,?,NOW())")
               ->execute(['automation_daily', 'DAILY_REPORT_SENT', '', date('Y-m-d')]);
            $out['daily_report'] = 1;
        }
    }

    return $out;
}

try {
    $out = runHclouAutomation(getDB());
    echo json_encode(['success' => true] + $out, JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
