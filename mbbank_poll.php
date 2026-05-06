<?php
require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    $secret = $_GET['secret'] ?? '';
    if (!hash_equals(MBBANK_POLL_SECRET, $secret)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function mbLog($msg) {
    if (PHP_SAPI === 'cli') echo $msg . PHP_EOL;
}

function ensureMBBankTables(PDO $db) {
    $db->exec("CREATE TABLE IF NOT EXISTS bank_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tx_hash CHAR(64) NOT NULL UNIQUE,
        tx_date VARCHAR(32) NOT NULL,
        amount DECIMAL(12,0) NOT NULL,
        description TEXT NOT NULL,
        order_code VARCHAR(50) DEFAULT NULL,
        status ENUM('seen','matched','approved','ignored','error') DEFAULT 'seen',
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_order_code (order_code),
        INDEX idx_status (status),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function fetchMBBankTransactions() {
    $res = httpJsonRequest(MBBANK_HISTORY_API_URL, 'GET', [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 HCLOU-AutoBank/1.0'
    ]);
    if (!$res['ok'] || !is_array($res['json'])) {
        throw new Exception('API MBBANK lỗi HTTP '.$res['code']);
    }
    $json = $res['json'];
    $txs = $json['transactions'] ?? ($json['data']['mb_data']['transactions'] ?? null);
    if (!is_array($txs)) throw new Exception('API MBBANK không có transactions hợp lệ');
    return $txs;
}

function normalizeMBBankTx(array $tx) {
    $date = (string)($tx['formatted_date'] ?? $tx['transaction_date'] ?? '');
    $desc = trim((string)($tx['description'] ?? ''));
    $amount = 0;
    if (isset($tx['amount']) && strtoupper((string)($tx['type'] ?? 'IN')) === 'IN') {
        $amount = (float)$tx['amount'];
    } elseif (isset($tx['credit_amount'])) {
        $amount = (float)$tx['credit_amount'];
    }
    return [$date, $amount, $desc];
}

function approvePaidOrder(PDO $db, string $orderCode, float $amount, string $txHash) {
    $stmt = $db->prepare("SELECT o.*, p.days, p.key_type, p.price, g.name AS game_name, g.package_name, u.telegram_id
        FROM orders o
        JOIN packages p ON o.package_id=p.id
        JOIN games g ON o.game_id=g.id
        JOIN users u ON o.user_id=u.id
        WHERE o.order_code=? AND o.status='pending'
        LIMIT 1");
    $stmt->execute([$orderCode]);
    $order = $stmt->fetch();
    if (!$order) return ['status'=>'ignored', 'note'=>'Không tìm thấy đơn pending'];
    if ((float)$order['amount'] > $amount) return ['status'=>'ignored', 'note'=>'Số tiền nhận nhỏ hơn đơn'];

    $db->beginTransaction();
    try {
        $keyStmt = $db->prepare("SELECT * FROM `keys` WHERE order_id=? AND status='pending' LIMIT 1 FOR UPDATE");
        $keyStmt->execute([$order['id']]);
        $key = $keyStmt->fetch();
        if (!$key) throw new Exception('Không tìm thấy key pending');

        $finalKeyCode = $key['key_code'];
        $start = date('Y-m-d H:i:s');
        $expire = date('Y-m-d H:i:s', strtotime('+'.((int)$order['days']).' days'));
        $db->prepare("UPDATE `keys` SET key_code=?, status='active', start_at=?, expire_at=? WHERE id=?")
           ->execute([$finalKeyCode, $start, $expire, $key['id']]);
        $key['key_code'] = $finalKeyCode;
        $db->prepare("UPDATE orders SET status='approved', approved_at=NOW(), approved_by=? WHERE id=? AND status='pending'")
           ->execute(['mbbank_api', $order['id']]);
        $db->prepare("UPDATE bank_transactions SET status='approved', processed_at=NOW(), note=? WHERE tx_hash=?")
           ->execute(['Auto approved '.$orderCode, $txHash]);
        $db->commit();

        $shortOrder = preg_replace('/^ORD/i', '', $orderCode);
        $packageName = $order['package_name'] ?: $order['game_name'];
        $type = $order['key_type'] ?: 'Normal';
        $type = strtoupper($type) === 'VIP' ? 'VIP' : 'Normal';
        $userMsg = "✅ <b>Key Purchase Successful!</b>

" .
            "• Order code : <code>{$shortOrder}</code>
" .
            "• License : <code>{$key['key_code']}</code>
" .
            "• Package : <code>{$packageName}</code>
" .
            "• Type : {$type} — {$order['days']} days / " . number_format((float)$order['price'], 0, ',', '.') . "đ

" .
            "Duration will start when license login.

" .
            "<b>Lưu ý:</b> để sử dụng một cách an toàn vui lòng không sử dụng bất cứ thứ gì có liên quan tới mod khác hoặc ứng dụng lạ trên thiết bị của bạn.";
        sendTelegram($order['telegram_id'], $userMsg);
        sendTelegram(ADMIN_CHAT_ID, "🤖 <b>AUTO MBBANK DUYỆT ĐƠN</b>\n#{$orderCode}\n💰 Nhận: ".number_format($amount,0,',','.')."đ\n🔑 <code>{$key['key_code']}</code>");
        return ['status'=>'approved', 'note'=>'OK'];
    } catch (Exception $e) {
        $db->rollBack();
        return ['status'=>'error', 'note'=>$e->getMessage()];
    }
}

try {
    if (!defined('MBBANK_AUTO_APPROVE_ENABLED') || !MBBANK_AUTO_APPROVE_ENABLED) throw new Exception('Auto approve disabled');
    $db = getDB();
    ensureMBBankTables($db);
    $txs = fetchMBBankTransactions();
    $seen = $approved = $matched = 0;
    foreach ($txs as $tx) {
        [$date, $amount, $desc] = normalizeMBBankTx($tx);
        if ($amount <= 0 || $desc === '') continue;
        $hash = hash('sha256', $date.'|'.$amount.'|'.$desc);
        preg_match('/\b(ORD[0-9A-Z]+)\b/i', $desc, $m);
        $orderCode = strtoupper($m[1] ?? '');

        // MBBank đôi khi trả cùng một giao dịch 2 lần với description khác khoảng trắng/dấu chấm,
        // làm tx_hash khác nhau. Nếu cùng order_code + amount đã approved thì bỏ qua để tránh báo ignored giả.
        if ($orderCode) {
            $dupStmt = $db->prepare("SELECT id FROM bank_transactions WHERE order_code=? AND amount=? AND status='approved' LIMIT 1");
            $dupStmt->execute([$orderCode, $amount]);
            if ($dupStmt->fetchColumn()) continue;
        }

        $ins = $db->prepare("INSERT IGNORE INTO bank_transactions (tx_hash, tx_date, amount, description, order_code, status) VALUES (?,?,?,?,?,?)");
        $ins->execute([$hash, $date, $amount, $desc, $orderCode ?: null, $orderCode ? 'matched' : 'seen']);
        if ($ins->rowCount() === 0) continue;
        $seen++;
        if ($orderCode) {
            $matched++;
            $res = approvePaidOrder($db, $orderCode, $amount, $hash);
            if ($res['status'] === 'approved') $approved++;
            elseif ($res['status'] !== 'approved') {
                $db->prepare("UPDATE bank_transactions SET status=?, processed_at=NOW(), note=? WHERE tx_hash=?")
                   ->execute([$res['status'], $res['note'], $hash]);
            }
        }
    }
    $out = ['success'=>true, 'seen_new'=>$seen, 'matched'=>$matched, 'approved'=>$approved];
    if (PHP_SAPI === 'cli') mbLog(json_encode($out, JSON_UNESCAPED_UNICODE)); else jsonResponse($out);
} catch (Exception $e) {
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); }
    jsonResponse(['success'=>false, 'error'=>$e->getMessage()], 500);
}
