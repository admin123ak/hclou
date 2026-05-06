<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

const AUTOMATION_RUN_TOKEN = '3619c4e99835a605f6efcb420d06a43104dd0d748f23878b';

$token = $_GET['token'] ?? '';
if (!hash_equals(AUTOMATION_RUN_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cmd = '/usr/bin/php ' . escapeshellarg(__DIR__ . '/automation_daily.php') . ' 2>&1';
$output = [];
$code = 0;
exec($cmd, $output, $code);
$raw = trim(implode("\n", $output));
$json = json_decode($raw, true);

if ($code !== 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'exit_code' => $code, 'output' => $raw], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => true, 'runner' => $json ?: $raw], JSON_UNESCAPED_UNICODE);
