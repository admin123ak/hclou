<?php
require_once 'config.php';

// Chạy file này 1 lần để đăng ký webhook
// Sau đó xoá hoặc đổi tên file này đi!


$setupToken = substr(hash('sha256', BOT_TOKEN . '|' . ADMIN_CHAT_ID), 0, 16);
if (($_GET['token'] ?? '') !== $setupToken) {
    http_response_code(403);
    echo '<h2>403 Forbidden</h2><p>Thiếu setup token.</p>';
    exit;
}

$action = $_GET['action'] ?? 'set';

if ($action === 'set') {
    $webhook_url = SITE_URL . '/webhook.php';
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/setWebhook");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['url' => $webhook_url, 'allowed_updates' => json_encode(['message','callback_query'])]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);
    echo "<h2>Set Webhook</h2><pre>" . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    echo "<p>Webhook URL: <b>$webhook_url</b></p>";
    echo "<a href='?action=info'>Kiểm tra webhook</a> | <a href='?action=delete'>Xoá webhook</a>";

} elseif ($action === 'info') {
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/getWebhookInfo");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);
    echo "<h2>Webhook Info</h2><pre>" . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    echo "<a href='?action=set'>Set lại</a> | <a href='?action=delete'>Xoá webhook</a>";

} elseif ($action === 'delete') {
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/deleteWebhook");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);
    echo "<h2>Delete Webhook</h2><pre>" . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
}
