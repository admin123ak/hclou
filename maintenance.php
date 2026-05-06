<?php
require_once __DIR__ . '/config.php';

function runMaintenance(PDO $db): array {
    $out = ['expired_keys'=>0, 'deleted_expired_keys'=>0, 'cancelled_orders'=>0, 'locked_keys'=>0];
    $stmt = $db->prepare("UPDATE `keys` SET status='expired' WHERE status='active' AND expire_at IS NOT NULL AND expire_at < NOW()");
    $stmt->execute();
    $out['expired_keys'] = $stmt->rowCount();

    // Xoá key đã hết hạn quá 3 ngày nếu user không gia hạn.
    $stmt = $db->prepare("DELETE FROM `keys` WHERE status='expired' AND expire_at IS NOT NULL AND expire_at < (NOW() - INTERVAL 3 DAY)");
    $stmt->execute();
    $out['deleted_expired_keys'] = $stmt->rowCount();

    // Hủy đơn pending quá 15 phút để khớp countdown thanh toán trên Mini App.
    $db->beginTransaction();
    try {
        $orders = $db->query("SELECT id FROM orders WHERE status='pending' AND created_at < (NOW() - INTERVAL 15 MINUTE) FOR UPDATE")->fetchAll();
        if ($orders) {
            $ids = array_map(fn($r)=>(int)$r['id'], $orders);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE orders SET status='cancelled', admin_note='Tự huỷ do quá 15 phút chưa thanh toán' WHERE id IN ($in) AND status='pending'");
            $stmt->execute($ids);
            $out['cancelled_orders'] = $stmt->rowCount();
            $stmt = $db->prepare("UPDATE `keys` SET status='locked' WHERE order_id IN ($in) AND status='pending'");
            $stmt->execute($ids);
            $out['locked_keys'] = $stmt->rowCount();
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $out;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $result = runMaintenance(getDB());
        if (PHP_SAPI !== 'cli') header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>true] + $result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } catch (Throwable $e) {
        if (PHP_SAPI !== 'cli') http_response_code(500);
        echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}
