<?php
require_once '../config.php';
session_start();

// Admin auth: session + CSRF + timeout
function admin_login_page($error = '') {
    $csrf = $_SESSION['admin_csrf'] ?? bin2hex(random_bytes(16));
    $_SESSION['admin_csrf'] = $csrf;
    $err = $error ? '<div class="err">'.htmlspecialchars($error).'</div>' : '';
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>HCLOU SERVER Admin</title>
        <style>*{margin:0;padding:0;box-sizing:border-box}body{min-height:100vh;background:radial-gradient(circle at 20% 10%,rgba(31,111,235,.35),transparent 28%),radial-gradient(circle at 85% 20%,rgba(139,92,246,.28),transparent 30%),#070b14;color:#e6edf3;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;display:flex;align-items:center;justify-content:center;padding:20px;overflow:hidden}.card{width:410px;max-width:100%;background:linear-gradient(180deg,rgba(22,27,34,.94),rgba(13,17,23,.97));border:1px solid rgba(88,166,255,.22);border-radius:28px;padding:30px;box-shadow:0 24px 90px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.05);backdrop-filter:blur(18px)}.logo{width:68px;height:68px;border-radius:22px;background:linear-gradient(135deg,#1f6feb,#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 16px;box-shadow:0 0 30px rgba(31,111,235,.45)}h1{text-align:center;font-size:24px;margin-bottom:6px}.sub{text-align:center;color:#8b949e;font-size:13px;margin-bottom:24px}.field{margin-bottom:14px}label{display:block;color:#8b949e;font-size:12px;font-weight:800;margin:0 0 7px 2px}input{width:100%;padding:14px 15px;background:#0d1117;border:1px solid #30363d;border-radius:14px;color:#e6edf3;font-size:15px;outline:none}input:focus{border-color:#58a6ff;box-shadow:0 0 0 4px rgba(88,166,255,.12)}button{width:100%;padding:14px;border:none;border-radius:14px;background:linear-gradient(135deg,#1f6feb,#8b5cf6);color:#fff;font-size:15px;font-weight:950;cursor:pointer;box-shadow:0 12px 30px rgba(31,111,235,.28)}.hint{margin-top:16px;text-align:center;color:#6e7681;font-size:12px}.err{background:rgba(239,68,68,.13);border:1px solid rgba(239,68,68,.35);color:#fca5a5;padding:11px 13px;border-radius:13px;margin-bottom:14px;font-size:13px;font-weight:750}.admin-footer{margin:26px 0 4px;text-align:center;color:rgba(127,144,170,.48);font-size:11px;font-weight:700;letter-spacing:.02em;opacity:.72;text-shadow:0 0 14px rgba(125,211,252,.14)}.admin-footer:before{content:"";display:block;width:120px;height:1px;background:linear-gradient(90deg,transparent,rgba(125,211,252,.28),transparent);margin:0 auto 12px}</style></head>
        <body><form class="card" method="POST"><div class="logo">⚡</div><h1>HCLOU SERVER</h1><div class="sub">Admin Control Center · Secure Login</div>'.$err.'<input type="hidden" name="csrf" value="'.$csrf.'"><div class="field"><label>Mật khẩu quản trị</label><input type="password" name="pw" placeholder="Nhập mật khẩu admin" autocomplete="current-password" autofocus></div><button>Đăng nhập an toàn</button><div class="hint">Session tự hết hạn sau '.(int)(ADMIN_SESSION_TTL/60).' phút không hoạt động</div></form></body></html>';
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ?logged_out=1'); exit;
}

$loggedIn = !empty($_SESSION['admin_auth']) && !empty($_SESSION['admin_last_seen']) && (time() - $_SESSION['admin_last_seen'] <= ADMIN_SESSION_TTL);
if (!$loggedIn) {
    unset($_SESSION['admin_auth'], $_SESSION['admin_last_seen']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfOk = hash_equals($_SESSION['admin_csrf'] ?? '', $_POST['csrf'] ?? '');
        if (!$csrfOk) { admin_login_page('Phiên đăng nhập không hợp lệ, thử lại.'); exit; }
        if (password_verify($_POST['pw'] ?? '', ADMIN_PASSWORD_HASH)) {
            session_regenerate_id(true);
            $_SESSION['admin_auth'] = true;
            $_SESSION['admin_last_seen'] = time();
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
            header('Location: ?tab=dashboard'); exit;
        }
        admin_login_page('Sai mật khẩu admin.'); exit;
    }
    admin_login_page(); exit;
}
$_SESSION['admin_last_seen'] = time();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));

$db = getDB();
$tab = $_GET['tab'] ?? 'dashboard';

function hclouMaskSecret($value, $left = 8, $right = 4) {
    $value = (string)$value;
    $len = strlen($value);
    if ($value === '') return '';
    if ($len <= $left + $right + 3) return str_repeat('•', min($len, 12));
    return substr($value, 0, $left) . '…' . substr($value, -$right);
}
function hclouCronRunToken() {
    $file = __DIR__ . '/../cron_run.php';
    if (!is_file($file)) return '';
    $src = file_get_contents($file);
    return preg_match("/const\s+CRON_RUN_TOKEN\s*=\s*'([^']+)'/", $src, $m) ? $m[1] : '';
}
function hclouAutomationRunToken() {
    $file = __DIR__ . '/../automation_run.php';
    if (!is_file($file)) return '';
    $src = file_get_contents($file);
    return preg_match("/const\s+AUTOMATION_RUN_TOKEN\s*=\s*'([^']+)'/", $src, $m) ? $m[1] : '';
}
function hclouCronRunUrl($job, $masked = true) {
    $token = hclouCronRunToken();
    $show = $masked ? hclouMaskSecret($token) : $token;
    return rtrim(SITE_URL, '/') . '/cron_run.php?token=' . $show . '&job=' . rawurlencode($job);
}
function hclouAutomationRunUrl($masked = true) {
    $token = hclouAutomationRunToken();
    $show = $masked ? hclouMaskSecret($token) : $token;
    return rtrim(SITE_URL, '/') . '/automation_run.php?token=' . $show;
}

// Xử lý action POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'] ?? '', $_POST['csrf'] ?? '')) { header('Location: ?err=' . urlencode('CSRF token không hợp lệ')); exit; }
    $act = $_POST['act'] ?? '';
    

    if ($act === 'save_config') {
        try {
            $changes = hclouWriteConfigValues($_POST['cfg'] ?? [], $_SESSION['admin_name'] ?? 'web_admin');
            header("Location: ?tab=sysconfig&ok=1&changed=" . count($changes)); exit;
        } catch (Exception $e) { header("Location: ?tab=sysconfig&err=" . urlencode($e->getMessage())); exit; }
    }
    if ($act === 'run_maintenance') {
        try {
            require_once __DIR__ . '/../maintenance.php';
            $r = runMaintenance($db);
            header("Location: ?tab=sysconfig&ok=1&maint=" . urlencode(json_encode($r, JSON_UNESCAPED_UNICODE))); exit;
        } catch (Exception $e) { header("Location: ?tab=sysconfig&err=" . urlencode($e->getMessage())); exit; }
    }

    if ($act === 'add_free_key') {
        $game_id=(int)$_POST['game_id']; $package_id=(int)$_POST['package_id']; $key_code=trim($_POST['key_code']);
        $pkg=$db->prepare("SELECT * FROM packages WHERE id=? AND game_id=?"); $pkg->execute([$package_id,$game_id]); $p=$pkg->fetch();
        if (!$p || !$key_code) { header("Location: ?tab=freekeys&err=missing"); exit; }
        $token=bin2hex(random_bytes(24)); $start=date('Y-m-d H:i:s'); $exp=date('Y-m-d H:i:s', strtotime("+{$p['days']} days"));
        $claim=SITE_URL.'/claim.php?t='.$token;
        try { $short=buildFreeShortlink($claim); } catch (Exception $e) { header("Location: ?tab=freekeys&err=" . urlencode($e->getMessage())); exit; }
        $db->prepare("INSERT INTO free_keys (key_code,game_id,package_id,days,key_type,is_active,start_at,expire_at,claim_token,short_url) VALUES (?,?,?,?,?,1,?,?,?,?)")
           ->execute([$key_code,$game_id,$package_id,$p['days'],$p['key_type'],$start,$exp,$token,$short]);
        header("Location: ?tab=freekeys&ok=1"); exit;
    }
    if ($act === 'toggle_free_key') {
        $db->prepare("UPDATE free_keys SET is_active=1-is_active WHERE id=?")->execute([$_POST['id']]);
        header("Location: ?tab=freekeys&ok=1"); exit;
    }
    if ($act === 'regen_free_link') {
        $stmt=$db->prepare("SELECT * FROM free_keys WHERE id=?"); $stmt->execute([$_POST['id']]); $fk=$stmt->fetch();
        if ($fk) { try { $short=buildFreeShortlink(SITE_URL.'/claim.php?t='.$fk['claim_token']); $db->prepare("UPDATE free_keys SET short_url=? WHERE id=?")->execute([$short,$fk['id']]); } catch (Exception $e) { header("Location: ?tab=freekeys&err=" . urlencode($e->getMessage())); exit; } }
        header("Location: ?tab=freekeys&ok=1"); exit;
    }

    if ($act === 'add_game') {
        $db->prepare("INSERT INTO games (name,package_name,type,root_type,sort_order) VALUES (?,?,?,?,?)")
           ->execute([$_POST['name'],$_POST['pkg'],$_POST['type'],$_POST['root'],$_POST['sort']??0]);
        header("Location: ?tab=games&ok=1"); exit;
    }
    if ($act === 'toggle_game') {
        $db->prepare("UPDATE games SET is_active=1-is_active WHERE id=?")->execute([$_POST['id']]);
        header("Location: ?tab=games&ok=1"); exit;
    }
    if ($act === 'edit_game') {
        $db->prepare("UPDATE games SET name=?, package_name=?, type=?, root_type=?, sort_order=?, is_active=? WHERE id=?")
           ->execute([$_POST['name'],$_POST['pkg'],$_POST['type'],$_POST['root'],$_POST['sort']??0,$_POST['is_active']??1,$_POST['id']]);
        header("Location: ?tab=games&ok=1"); exit;
    }
    if ($act === 'del_game') {
        $db->prepare("DELETE FROM games WHERE id=?")->execute([$_POST['id']]);
        header("Location: ?tab=games"); exit;
    }
    if ($act === 'add_pkg') {
        $duration_hours = $_POST['duration_hours'] ?? null;
        $price_per_device = $_POST['price_per_device'] ?? null;
        $db->prepare("INSERT INTO packages (game_id,name,days,duration_hours,price,price_per_device,key_type) VALUES (?,?,?,?,?,?,?)")
           ->execute([$_POST['game_id'],'Gói '.$_POST['days'].' ngày',$_POST['days'],$duration_hours,$_POST['price'],$price_per_device,$_POST['key_type']]);
        header("Location: ?tab=packages&ok=1"); exit;
    }
    if ($act === 'toggle_pkg') {
        $db->prepare("UPDATE packages SET is_active=1-is_active WHERE id=?")->execute([$_POST['id']]);
        header("Location: ?tab=packages&ok=1"); exit;
    }
    if ($act === 'edit_pkg') {
        $name = trim($_POST['name'] ?? '') ?: ('Gói '.($_POST['days'] ?? '').' ngày');
        $duration_hours = $_POST['duration_hours'] ?? null;
        $price_per_device = $_POST['price_per_device'] ?? null;
        $db->prepare("UPDATE packages SET game_id=?, name=?, days=?, duration_hours=?, price=?, price_per_device=?, key_type=?, is_active=? WHERE id=?")
           ->execute([$_POST['game_id'],$name,$_POST['days'],$duration_hours,$_POST['price'],$price_per_device,$_POST['key_type'],$_POST['is_active']??1,$_POST['id']]);
        header("Location: ?tab=packages&ok=1"); exit;
    }
    if ($act === 'del_pkg') {
        $db->prepare("DELETE FROM packages WHERE id=?")->execute([$_POST['id']]);
        header("Location: ?tab=packages"); exit;
    }
    if ($act === 'approve_order') {
        $order_code = $_POST['order_code'] ?? '';
        $stmt = $db->prepare("SELECT o.*, u.telegram_id FROM orders o JOIN users u ON o.user_id=u.id WHERE o.order_code=? AND o.status='pending'");
        $stmt->execute([$order_code]);
        $order = $stmt->fetch();
        if (!$order) {
            header("Location: ?tab=orders&err=" . urlencode('Đơn không tồn tại hoặc đã được xử lý'));
            exit;
        }
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE orders SET status='approved',approved_by='web_admin',approved_at=NOW() WHERE order_code=?")->execute([$order_code]);
            // Kích hoạt key(s) theo order
            $stmt2 = $db->prepare("SELECT * FROM `keys` WHERE order_id=? AND status='pending'");
            $stmt2->execute([$order['id']]);
            $pendingKeys = $stmt2->fetchAll();
            foreach ($pendingKeys as $pk) {
                $duration = $pk['duration_hours'] ?? ($pk['days'] * 24);
                $startAt = date('Y-m-d H:i:s');
                $expireAt = date('Y-m-d H:i:s', strtotime("+{$duration} hours"));
                $db->prepare("UPDATE `keys` SET status='active',start_at=?,expire_at=? WHERE id=?")->execute([$startAt, $expireAt, $pk['id']]);
            }
            $db->commit();
            // Notify admin
            $adminMsg = "✅ <b>ADMIN ĐÃ DUYỆT THỦ CÔNG #{$order_code}</b>\n\n"
                . "👤 User: @{$order['telegram_username']} (ID: {$order['telegram_id']})\n"
                . "💰 Số tiền: " . number_format($order['amount'], 0, ',', '.') . "đ\n"
                . "🕐 " . date('d/m/Y H:i:s');
            sendTelegram(ADMIN_CHAT_ID, $adminMsg);
            // Notify user
            $userMsg = "✅ <b>Đơn #{$order_code} đã được duyệt!</b>\n\nKey của bạn đã được kích hoạt và sẵn sàng sử dụng.\nVào web để xem chi tiết key.";
            if ($order['telegram_id']) sendTelegram($order['telegram_id'], $userMsg);
            header("Location: ?tab=orders&ok=approved_{$order_code}");
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            header("Location: ?tab=orders&err=" . urlencode($e->getMessage()));
            exit;
        }
    }


    if ($act === 'reject_order') {
        $order_code = $_POST['order_code'];
        $stmt = $db->prepare("SELECT o.*, u.telegram_id FROM orders o JOIN users u ON o.user_id=u.id WHERE o.order_code=? AND o.status='pending'");
        $stmt->execute([$order_code]);
        $order = $stmt->fetch();
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE orders SET status='rejected',approved_by='web_admin' WHERE order_code=?")->execute([$order_code]);
            $db->prepare("UPDATE `keys` k JOIN orders o ON k.order_id=o.id SET k.status='locked' WHERE o.order_code=? AND k.status='pending'")->execute([$order_code]);
            $db->commit();
            if ($order) sendTelegram($order['telegram_id'], "❌ <b>Đơn #{$order_code} bị từ chối.</b>
Vui lòng liên hệ admin để được hỗ trợ.");
        } catch (Exception $e) { $db->rollBack(); header("Location: ?tab=orders&err=".urlencode($e->getMessage())); exit; }
        header("Location: ?tab=orders"); exit;
    }
    if ($act === 'lock_key') {
        $db->prepare("UPDATE `keys` SET status='locked' WHERE id=?")->execute([$_POST['key_id']]);
        header("Location: ?tab=keys"); exit;
    }
    if ($act === 'unlock_key') {
        $db->prepare("UPDATE `keys` SET status='active' WHERE id=? AND start_at IS NOT NULL AND expire_at IS NOT NULL")->execute([$_POST['key_id']]);
        header("Location: ?tab=keys"); exit;
    }
    if ($act === 'delete_key') {
        $db->prepare("DELETE FROM `keys` WHERE id=?")->execute([$_POST['key_id']]);
        header("Location: ?tab=keys&ok=1"); exit;
    }
}

// Lấy data cho dashboard
$stats = [
    'users' => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'orders_pending' => $db->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn(),
    'orders_approved' => $db->query("SELECT COUNT(*) FROM orders WHERE status='approved'")->fetchColumn(),
    'revenue' => $db->query("SELECT SUM(amount) FROM orders WHERE status='approved'")->fetchColumn() ?? 0,
    'keys_active' => $db->query("SELECT COUNT(*) FROM `keys` WHERE status='active'")->fetchColumn(),
    'keys_total' => $db->query("SELECT COUNT(*) FROM `keys`")->fetchColumn(),
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Panel - <?= SITE_NAME ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0b1020;--side:#0f172a;--side2:#111c33;--panel:#111827;--card:#182235;--card2:#151f31;--line:#26354f;--line2:#334765;--text:#edf4ff;--muted:#91a4c3;--blue:#3b82f6;--cyan:#06b6d4;--green:#22c55e;--red:#ef4444;--orange:#f59e0b;--purple:#8b5cf6;--shadow:0 18px 46px rgba(0,0,0,.28)}
html{scroll-behavior:smooth}body{background:linear-gradient(180deg,#08111f 0%,#0b1020 45%,#090d18 100%);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;min-height:100vh;font-size:14px}.layout{display:flex;min-height:100vh}.sidebar{width:270px;background:linear-gradient(180deg,var(--side),#0a1222);border-right:1px solid var(--line);padding:18px 14px;flex-shrink:0;position:fixed;height:100vh;overflow-y:auto;box-shadow:12px 0 34px rgba(0,0,0,.22);z-index:10}.sidebar::-webkit-scrollbar{width:4px}.sidebar::-webkit-scrollbar-thumb{background:#26354f;border-radius:99px}.sidebar-logo{padding:18px 16px;margin:0 2px 18px;border:1px solid rgba(59,130,246,.24);border-radius:22px;background:linear-gradient(135deg,rgba(59,130,246,.22),rgba(6,182,212,.10));font-size:18px;font-weight:950;letter-spacing:-.02em;box-shadow:0 14px 34px rgba(37,99,235,.12)}.sidebar-logo small{display:block!important;margin-top:7px;color:#9fb2cf!important;font-size:11px!important;font-weight:700!important}.version-pill{display:inline-flex;align-items:center;background:rgba(34,197,94,.14);border:1px solid rgba(34,197,94,.28);color:#86efac;border-radius:999px;padding:3px 8px;font-size:10px;font-weight:900;margin-left:6px}.nav-item{display:flex;align-items:center;gap:10px;padding:12px 14px;color:#aab8d0;text-decoration:none;font-size:14px;font-weight:800;border-radius:14px;margin:5px 0;transition:.16s;position:relative}.nav-item:hover{color:#fff;background:rgba(59,130,246,.10);transform:translateX(2px)}.nav-item.active{color:#fff;background:linear-gradient(135deg,rgba(59,130,246,.25),rgba(6,182,212,.12));box-shadow:inset 3px 0 0 var(--cyan),0 10px 24px rgba(6,182,212,.08)}.main{margin-left:270px;padding:26px;flex:1;min-width:0}.main:before{content:"HCLOU SERVER / ADMIN";display:block;color:#7dd3fc;font-size:11px;font-weight:900;letter-spacing:.16em;margin-bottom:8px}.main>h1:first-of-type{font-size:30px;font-weight:950;letter-spacing:-.035em;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:12px}.main>h1:first-of-type:after{content:"Control Center";font-size:12px;letter-spacing:0;color:#bfdbfe;background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.25);padding:8px 12px;border-radius:999px}h2{font-size:17px;margin-bottom:13px;color:#dbeafe}.alert{padding:13px 16px;border-radius:14px;font-size:13px;font-weight:750;margin-bottom:16px;border:1px solid var(--line)}.alert-green{background:rgba(34,197,94,.13);border-color:rgba(34,197,94,.30);color:#86efac}.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px;margin-bottom:28px}.stat-card{background:linear-gradient(180deg,var(--card),var(--card2));border:1px solid var(--line);border-radius:22px;padding:19px;box-shadow:var(--shadow);position:relative;overflow:hidden}.stat-card:after{content:"";position:absolute;right:-24px;top:-24px;width:84px;height:84px;border-radius:50%;background:rgba(59,130,246,.12)}.stat-card:hover{border-color:var(--line2);transform:translateY(-2px);transition:.16s}.stat-val{font-size:34px;font-weight:950;margin-bottom:5px;letter-spacing:-.04em;position:relative}.stat-label{font-size:12px;color:var(--muted);font-weight:800;position:relative}.stat-val.blue{color:#60a5fa}.stat-val.green{color:#4ade80}.stat-val.orange{color:#fbbf24}.stat-val.red{color:#f87171}
table{width:100%;border-collapse:separate;border-spacing:0;background:var(--panel);border:1px solid var(--line);border-radius:18px;overflow:hidden;font-size:13px;box-shadow:var(--shadow)}th{padding:14px 15px;text-align:left;font-size:11px;font-weight:900;color:#9fb7d7;text-transform:uppercase;border-bottom:1px solid var(--line);background:#0f172a;letter-spacing:.04em}td{padding:13px 15px;border-bottom:1px solid rgba(148,163,184,.10);vertical-align:middle;color:#e5edf8}tr:last-child td{border-bottom:none}tr:hover td{background:rgba(59,130,246,.045)}td small{color:var(--muted)!important}.badge{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;font-size:11px;font-weight:900}.badge.green{background:rgba(34,197,94,.14);color:#86efac;border:1px solid rgba(34,197,94,.30)}.badge.orange{background:rgba(245,158,11,.14);color:#fbbf24;border:1px solid rgba(245,158,11,.30)}.badge.red{background:rgba(239,68,68,.14);color:#fca5a5;border:1px solid rgba(239,68,68,.30)}.badge.blue{background:rgba(59,130,246,.14);color:#93c5fd;border:1px solid rgba(59,130,246,.30)}.badge.gray{background:rgba(148,163,184,.12);color:#cbd5e1;border:1px solid rgba(148,163,184,.20)}.btn{padding:8px 13px;border-radius:11px;border:none;font-size:12px;font-weight:900;cursor:pointer;transition:.14s;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:5px;white-space:nowrap}.btn-green{background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff}.btn-red{background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff}.btn-blue{background:linear-gradient(135deg,#2563eb,#06b6d4);color:#fff}.btn-gray{background:#243044;color:#e6edf3;border:1px solid var(--line2)}.btn:hover{transform:translateY(-1px);filter:brightness(1.08)}.btn:active{transform:scale(.97)}.form-card{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:20px;margin-bottom:20px;box-shadow:var(--shadow)}.form-card h3{font-size:16px;font-weight:900;margin-bottom:15px;color:#dbeafe}.form-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}input,select{padding:10px 12px;background:#0f172a;border:1px solid var(--line);border-radius:11px;color:#e6edf3;font-size:13px;outline:none;max-width:100%}input:focus,select:focus{border-color:var(--cyan);box-shadow:0 0 0 3px rgba(6,182,212,.12)}select option{background:#0f172a;color:#e6edf3}label{font-size:12px;color:#93c5fd;display:block;margin-bottom:6px;font-weight:850}.main a:not(.btn):not(.nav-item){color:#67e8f9;text-decoration:none}.main a:not(.btn):not(.nav-item):hover{text-decoration:underline}p{color:var(--muted)}
.guide-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:16px;margin-bottom:18px}.guide-card{background:linear-gradient(180deg,var(--card),var(--card2));border:1px solid var(--line);border-radius:20px;padding:18px;box-shadow:var(--shadow)}.guide-card h3{font-size:16px;margin-bottom:10px;color:#dbeafe}.guide-card ul{margin-left:18px;color:#cbd5e1;line-height:1.65}.guide-card li{margin:4px 0}.guide-card .where{display:inline-flex;background:rgba(6,182,212,.12);border:1px solid rgba(6,182,212,.26);color:#67e8f9;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:900;margin-bottom:10px}.guide-card code,.codebox{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}.codebox{white-space:pre-wrap;background:#07101f;border:1px solid #26354f;border-radius:14px;padding:12px;margin-top:10px;color:#bfdbfe;font-size:12px;line-height:1.55;overflow:auto}.warnbox{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.30);color:#fde68a;border-radius:16px;padding:13px 15px;margin-bottom:16px;font-weight:750}.okbox{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.30);color:#bbf7d0;border-radius:16px;padding:13px 15px;margin-bottom:16px;font-weight:750}.desc-cell{max-width:420px;white-space:normal;line-height:1.45;color:#cbd5e1}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px}.filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}.filters input,.filters select{width:auto;min-width:180px}.nav-section{margin:14px 6px 7px;color:#6b7f9e;font-size:10px;font-weight:950;letter-spacing:.13em;text-transform:uppercase}.nav-sub{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin:0 0 8px}.nav-sub .nav-item{font-size:12px;padding:9px 10px;margin:0;border-radius:12px}.nav-item .count{background:#f85149;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;margin-left:auto}.nav-bottom{margin-top:16px;padding-top:12px;border-top:1px solid var(--line)}
@media(max-width:960px){.sidebar{position:sticky;top:0;width:100%;height:auto;display:flex;gap:8px;overflow-x:auto;border-right:none;border-bottom:1px solid var(--line);padding:12px}.sidebar-logo{min-width:190px;margin:0}.nav-section,.nav-bottom{display:none}.nav-sub{display:flex;gap:8px;margin:0}.nav-item{min-width:max-content;margin:0}.layout{display:block}.main{margin-left:0;padding:18px}.main>h1:first-of-type{font-size:25px}.stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}table{display:block;overflow-x:auto;white-space:nowrap}.form-row{display:grid;grid-template-columns:1fr}.btn,input,select{width:100%}}
@media(max-width:560px){.stats-grid{grid-template-columns:1fr}.main{padding:14px}.main>h1:first-of-type{display:block}.main>h1:first-of-type:after{display:inline-flex;margin-top:8px}}
.admin-footer{margin:26px 0 4px;text-align:center;color:rgba(127,144,170,.48);font-size:11px;font-weight:700;letter-spacing:.02em;opacity:.72;text-shadow:0 0 14px rgba(125,211,252,.14)}.admin-footer:before{content:"";display:block;width:120px;height:1px;background:linear-gradient(90deg,transparent,rgba(125,211,252,.28),transparent);margin:0 auto 12px}</style>
</head>
<body>
<div class="layout">
<div class="sidebar">
  <div class="sidebar-logo">⚡ <?= SITE_NAME ?><br><small style="color:#8b949e;font-size:11px;font-weight:500">Admin Suite <span class="version-pill">CLEAN</span></small></div>
  <a class="nav-item <?=$tab==='dashboard'?'active':''?>" href="?tab=dashboard">📊 Tổng quan</a>

  <div class="nav-section">Bán hàng</div>
  <a class="nav-item <?=$tab==='orders'?'active':''?>" href="?tab=orders">🛒 Đơn hàng <?php if($stats['orders_pending']>0):?><span class="count"><?=$stats['orders_pending']?></span><?php endif?></a>
  <div class="nav-sub">
    <a class="nav-item <?=$tab==='banktx'?'active':''?>" href="?tab=banktx">🏦 Bank</a>
    <a class="nav-item <?=$tab==='keys'?'active':''?>" href="?tab=keys">🔑 Keys</a>
  </div>

  <div class="nav-section">Sản phẩm</div>
  <div class="nav-sub">
    <a class="nav-item <?=$tab==='games'?'active':''?>" href="?tab=games">🎮 Games</a>
    <a class="nav-item <?=$tab==='packages'?'active':''?>" href="?tab=packages">📦 Gói</a>
  </div>
  <a class="nav-item <?=$tab==='freekeys'?'active':''?>" href="?tab=freekeys">🎁 GetKey Free</a>

  <div class="nav-section">Hệ thống</div>
  <div class="nav-sub">
    <a class="nav-item <?=$tab==='sysconfig'?'active':''?>" href="?tab=sysconfig">⚙️ Config</a>
    <a class="nav-item <?=$tab==='setup'?'active':''?>" href="?tab=setup">🧭 Setup</a>
  </div>
  <a class="nav-item <?=$tab==='users'?'active':''?>" href="?tab=users">👥 Users</a>

  <div class="nav-bottom">
    <div class="nav-sub">
      <a class="nav-item" href="../" target="_blank">🌐 Web</a>
      <a class="nav-item" href="?logout=1">🚪 Thoát</a>
    </div>
  </div>
</div>

<div class="main">
<?php if(isset($_GET['ok'])): ?>
<div class="alert alert-green">✅ Thao tác thành công!</div>
<?php endif ?>
<?php if(isset($_GET['err'])): ?>
<div class="alert" style="background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.35);color:#fca5a5">⚠️ <?=htmlspecialchars($_GET['err'])?></div>
<?php endif ?>

<?php if($tab==='dashboard'): ?>
<h1>📊 Dashboard</h1>
<div class="stats-grid">
  <div class="stat-card"><div class="stat-val blue"><?=$stats['users']?></div><div class="stat-label">👥 Người dùng</div></div>
  <div class="stat-card"><div class="stat-val orange"><?=$stats['orders_pending']?></div><div class="stat-label">🛒 Chờ thanh toán</div></div>
  <div class="stat-card"><div class="stat-val green"><?=$stats['orders_approved']?></div><div class="stat-label">✅ Đơn thành công</div></div>
  <div class="stat-card"><div class="stat-val green"><?=number_format($stats['revenue'],0,',','.')?> đ</div><div class="stat-label">💰 Doanh thu</div></div>
  <div class="stat-card"><div class="stat-val blue"><?=$stats['keys_active']?></div><div class="stat-label">🔑 Key đang active</div></div>
  <div class="stat-card"><div class="stat-val"><?=$stats['keys_total']?></div><div class="stat-label">🔑 Tổng keys</div></div>
</div>

<h2 style="font-size:16px;margin-bottom:12px">🛒 Đơn chờ thanh toán</h2>
<?php
$pending = $db->query("SELECT o.*,u.telegram_username,u.full_name,g.name as game_name,p.name as pkg_name,p.days,p.duration_hours,k.key_code,k.max_devices,k.id as key_id FROM orders o JOIN users u ON o.user_id=u.id JOIN games g ON o.game_id=g.id JOIN packages p ON o.package_id=p.id LEFT JOIN `keys` k ON k.order_id=o.id WHERE o.status='pending' ORDER BY o.created_at DESC LIMIT 20")->fetchAll();
if($pending): ?>
<table>
<tr><th>Mã đơn</th><th>User</th><th>Game / Gói</th><th>Key đã tạo</th><th>Thiết bị</th><th>Tiền</th><th>Thời gian</th><th>Thao tác</th></tr>
<?php foreach($pending as $o): ?>
<tr>
  <td><b><?=$o['order_code']?></b></td>
  <td>@<?=$o['telegram_username']?><br><small style="color:#8b949e"><?=$o['full_name']?></small></td>
  <td><?=$o['game_name']?><br><small style="color:#8b949e"><?=$o['pkg_name']?> (<?=$o['duration_hours'] ? ($o['duration_hours'].'h') : ($o['days'].' ngày') ?>)</small></td>
  <td style="font-family:monospace;font-size:12px"><?=htmlspecialchars($o['key_code'] ?? 'Chưa có')?></td>
  <td><?=$o['max_devices'] ? $o['max_devices'].' device'.($o['max_devices']>1?'s':'') : '--' ?></td>
  <td><b><?=number_format($o['amount'],0,',','.')?> đ</b></td>
  <td style="font-size:12px;color:#8b949e"><?=date('d/m H:i',strtotime($o['created_at']))?></td>
  <td>
    <form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="approve_order"><input type="hidden" name="order_code" value="<?=$o['order_code']?>"><button class="btn btn-green" onclick="return confirm('Duyệt đơn này?')">✅</button></form>
    <form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="reject_order"><input type="hidden" name="order_code" value="<?=$o['order_code']?>"><button class="btn btn-red" onclick="return confirm('Từ chối?')">❌</button></form>
  </td>
</tr>
<?php endforeach ?>
</table>
<?php else: ?><p style="color:#8b949e">Không có đơn nào chờ thanh toán ✅</p><?php endif ?>

<?php elseif($tab==='orders'): ?>
<h1>🛒 Quản lý đơn hàng</h1>
<?php
$filter_status = $_GET['s'] ?? 'pending';
$orders = $db->prepare("SELECT o.*,u.telegram_username,u.full_name,g.name as game_name,p.name as pkg_name,p.days,p.duration_hours,k.key_code,k.max_devices FROM orders o JOIN users u ON o.user_id=u.id JOIN games g ON o.game_id=g.id JOIN packages p ON o.package_id=p.id LEFT JOIN `keys` k ON k.order_id=o.id AND k.status='pending' WHERE o.status=? ORDER BY o.created_at DESC LIMIT 100");
$orders->execute([$filter_status]); $orders = $orders->fetchAll();
?>
<div style="margin-bottom:14px;display:flex;gap:8px">
  <?php foreach(['pending'=>'⏳ Chờ TT','approved'=>'✅ Auto duyệt','rejected'=>'❌ Từ chối','cancelled'=>'🚫 Huỷ'] as $s=>$l): ?>
  <a href="?tab=orders&s=<?=$s?>" class="btn <?=$filter_status===$s?'btn-blue':'btn-gray'?>"><?=$l?></a>
  <?php endforeach ?>
</div>
<table>
<tr><th>Mã đơn</th><th>User</th><th>Game / Gói</th><th>Key đã tạo</th><th>Thiết bị</th><th>Tiền</th><th>Trạng thái</th><th>Thời gian</th><?php if($filter_status==='pending'):?><th>Thao tác</th><?php endif?></tr>
<?php foreach($orders as $o): $cls=['pending'=>'orange','approved'=>'green','rejected'=>'red','cancelled'=>'gray'][$o['status']]??'gray'; ?>
<tr>
  <td><b><?=$o['order_code']?></b></td>
  <td>@<?=$o['telegram_username']?></td>
  <td><?=$o['game_name']?><br><small style="color:#8b949e"><?=$o['pkg_name']?><br><?=$o['duration_hours'] ? ($o['duration_hours'].'h') : ($o['days'].' ngày')?></small></td>
  <td style="font-family:monospace;font-size:12px"><?=htmlspecialchars($o['key_code'] ?? '--')?></td>
  <td><?=$o['max_devices'] ? $o['max_devices'].' device'.($o['max_devices']>1?'s':'') : '--' ?></td>
  <td><b><?=number_format($o['amount'],0,',','.')?> đ</b></td>
  <td><span class="badge <?=$cls?>"><?=$o['status']?></span></td>
  <td style="font-size:12px;color:#8b949e"><?=date('d/m/Y H:i',strtotime($o['created_at']))?></td>
  <?php if($filter_status==='pending'):?>
  <td>
    <form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="approve_order"><input type="hidden" name="order_code" value="<?=$o['order_code']?>"><button class="btn btn-green" onclick="return confirm('Duyệt đơn này?')">✅ Duyệt</button></form>
    <form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="reject_order"><input type="hidden" name="order_code" value="<?=$o['order_code']?>"><button class="btn btn-red" onclick="return confirm('Từ chối?')">❌</button></form>
  </td>
  <?php endif?>
</tr>
<?php endforeach ?>
</table>

<?php elseif($tab==='banktx'): ?>
<h1>🏦 Giao dịch MBBANK</h1>
<?php
$tx_status = $_GET['s'] ?? '';
$tx_q = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if ($tx_status !== '') { $where[] = 'status=?'; $params[] = $tx_status; }
if ($tx_q !== '') { $where[] = '(order_code LIKE ? OR description LIKE ? OR tx_hash LIKE ?)'; $params[] = '%'.$tx_q.'%'; $params[] = '%'.$tx_q.'%'; $params[] = '%'.$tx_q.'%'; }
$sqlWhere = $where ? ('WHERE '.implode(' AND ', $where)) : '';
$txStmt = $db->prepare("SELECT * FROM bank_transactions $sqlWhere ORDER BY id DESC LIMIT 150");
$txStmt->execute($params);
$txs = $txStmt->fetchAll();
$txStats = $db->query("SELECT status, COUNT(*) c FROM bank_transactions GROUP BY status")->fetchAll();
$txStatMap=[]; foreach($txStats as $r){ $txStatMap[$r['status']] = (int)$r['c']; }
?>
<div class="stats-grid">
  <div class="stat-card"><div class="stat-val blue"><?=array_sum($txStatMap)?></div><div class="stat-label">Tổng giao dịch đã đọc</div></div>
  <div class="stat-card"><div class="stat-val green"><?=$txStatMap['approved']??0?></div><div class="stat-label">Đã auto duyệt</div></div>
  <div class="stat-card"><div class="stat-val orange"><?=$txStatMap['ignored']??0?></div><div class="stat-label">Bị bỏ qua</div></div>
  <div class="stat-card"><div class="stat-val red"><?=$txStatMap['error']??0?></div><div class="stat-label">Lỗi xử lý</div></div>
</div>
<div class="form-card">
  <form method="GET" class="filters">
    <input type="hidden" name="tab" value="banktx">
    <select name="s">
      <option value="">Tất cả trạng thái</option>
      <?php foreach(['seen'=>'Seen','matched'=>'Matched','approved'=>'Approved','ignored'=>'Ignored','error'=>'Error'] as $v=>$l): ?>
      <option value="<?=$v?>" <?=$tx_status===$v?'selected':''?>><?=$l?></option>
      <?php endforeach; ?>
    </select>
    <input name="q" value="<?=htmlspecialchars($tx_q)?>" placeholder="Tìm ORD / nội dung / tx hash">
    <button type="submit">🔍 Lọc</button>
    <a class="btn" href="?tab=banktx">Reset</a>
  </form>
  <div class="alert" style="background:rgba(59,130,246,.10);border-color:rgba(59,130,246,.25);color:#bfdbfe">Cron hiện tại nên chạy mỗi phút: <span class="mono">/usr/bin/php /www/wwwroot/hclou.com/mbbank_poll.php</span>. Nếu giao dịch không auto duyệt, kiểm tra cột <b>Ghi chú</b> và <b>Nội dung</b> bên dưới.</div>
</div>
<div class="table-wrap"><table>
<tr><th>ID</th><th>Thời gian bank</th><th>Mã đơn</th><th>Số tiền</th><th>Trạng thái</th><th>Ghi chú</th><th>Nội dung CK</th><th>Đọc lúc</th><th>Xử lý lúc</th></tr>
<?php foreach($txs as $tx): $cls=['seen'=>'blue','matched'=>'orange','approved'=>'green','ignored'=>'gray','error'=>'red'][$tx['status']]??'gray'; ?>
<tr>
<td><?=$tx['id']?></td>
<td class="mono"><?=htmlspecialchars($tx['tx_date'])?></td>
<td><b><?=htmlspecialchars($tx['order_code'] ?: '-')?></b></td>
<td><b><?=number_format((float)$tx['amount'])?>đ</b></td>
<td><span class="badge <?=$cls?>"><?=htmlspecialchars($tx['status'])?></span></td>
<td><?=htmlspecialchars($tx['note'] ?: '-')?></td>
<td class="desc-cell"><?=htmlspecialchars($tx['description'])?></td>
<td class="mono"><?=htmlspecialchars($tx['created_at'])?></td>
<td class="mono"><?=htmlspecialchars($tx['processed_at'] ?: '-')?></td>
</tr>
<?php endforeach; if(!$txs): ?><tr><td colspan="9"><p>Chưa có giao dịch phù hợp.</p></td></tr><?php endif; ?>
</table></div>

<?php elseif($tab==='keys'): ?>
<h1>🔑 Quản lý Keys</h1>
<?php
$keys = $db->query("SELECT k.*,u.telegram_username,g.name as game_name,p.name as pkg_name,p.key_type,o.order_code FROM `keys` k JOIN users u ON k.user_id=u.id JOIN games g ON k.game_id=g.id JOIN packages p ON k.package_id=p.id LEFT JOIN orders o ON k.order_id=o.id ORDER BY k.created_at DESC LIMIT 100")->fetchAll();
?>
<table>
<tr><th>Key</th><th>User</th><th>Game / Gói</th><th>Duration</th><th>Devices</th><th>Trạng thái</th><th>Hết hạn</th><th>Thao tác</th></tr>
<?php foreach($keys as $k): $cls=['active'=>'green','expired'=>'orange','locked'=>'red','pending'=>'blue'][$k['status']]??'gray'; ?>
<tr>
  <td style="font-family:monospace;font-size:12px"><?=$k['key_code']?></td>
  <td>@<?=$k['telegram_username']?></td>
  <td style="font-size:12px"><b><?=$k['game_name']?></b><br><small style="color:#8b949e"><?=$k['pkg_name']?> · <?=$k['key_type']?><?php if($k['order_code']): ?> · <?=$k['order_code']?><?php endif ?></small></td>
  <td><?=$k['duration_hours']?($k['duration_hours'].'h'):($k['days'].' ngày')?></td>
  <td><?php if($k['max_devices']): $dc=$k['devices']?count(explode(',',$k['devices'])):0; echo $dc.'/'.$k['max_devices']; else: echo '--'; endif ?></td>
  <td><span class="badge <?=$cls?>"><?=$k['status']?></span><?php if($k['status']==='expired' && !empty($k['expire_at'])):?><br><small style="color:#fbbf24">Tự xoá sau 3 ngày nếu không gia hạn</small><?php endif?></td>
  <td style="font-size:12px;color:#8b949e"><?=$k['expire_at']?date('d/m/Y H:i',strtotime($k['expire_at'])):'--'?></td>
  <td>
    <?php if($k['status']==='active'): ?>
    <form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="lock_key"><input type="hidden" name="key_id" value="<?=$k['id']?>"><button class="btn btn-red" onclick="return confirm('Khoá key?')">🔒</button></form>
    <?php elseif($k['status']==='locked'): ?>
    <form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="unlock_key"><input type="hidden" name="key_id" value="<?=$k['id']?>"><button class="btn btn-green">🔓</button></form>
    <?php endif ?>
    <form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="delete_key"><input type="hidden" name="key_id" value="<?=$k['id']?>"><button class="btn btn-red" onclick="return confirm('Xoá vĩnh viễn key này?')">🗑</button></form>
  </td>
</tr>
<?php endforeach ?>
</table>

<?php elseif($tab==='games'): ?>
<h1>🎮 Quản lý Games</h1>
<div class="form-card">
<h3>➕ Thêm game mới</h3>
<form method="POST"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>">
<input type="hidden" name="act" value="add_game">
<div class="form-row">
  <div><label>Tên game</label><input name="name" required placeholder="Free Fire"></div>
  <div><label>Package name</label><input name="pkg" required placeholder="com.dts.freefireth" style="width:220px"></div>
  <div><label>Loại</label><select name="type"><option>NORMAL</option><option>VIP</option></select></div>
  <div><label>Root type</label><select name="root"><option>Only Root</option><option>Root & NoRoot</option><option>NoRoot</option></select></div>
  <div><label>Thứ tự</label><input name="sort" type="number" value="0" style="width:70px"></div>
  <div style="padding-top:20px"><button class="btn btn-blue" type="submit">➕ Thêm</button></div>
</div>
</form>
</div>
<?php $games = $db->query("SELECT * FROM games ORDER BY sort_order")->fetchAll(); ?>
<table>
<tr><th>#</th><th>Tên game</th><th>Package</th><th>Loại</th><th>Root</th><th>Thứ tự</th><th>Active</th><th>Thao tác</th></tr>
<?php foreach($games as $g): ?>
<tr>
<form method="POST"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>">
  <input type="hidden" name="act" value="edit_game"><input type="hidden" name="id" value="<?=$g['id']?>">
  <td><?=$g['id']?></td>
  <td><input name="name" value="<?=htmlspecialchars($g['name'])?>" required style="width:150px"></td>
  <td><input name="pkg" value="<?=htmlspecialchars($g['package_name'])?>" required style="width:220px"></td>
  <td><select name="type"><option <?=$g['type']==='NORMAL'?'selected':''?>>NORMAL</option><option <?=$g['type']==='VIP'?'selected':''?>>VIP</option></select></td>
  <td><select name="root"><option <?=$g['root_type']==='Only Root'?'selected':''?>>Only Root</option><option <?=$g['root_type']==='Root & NoRoot'?'selected':''?>>Root & NoRoot</option><option <?=$g['root_type']==='NoRoot'?'selected':''?>>NoRoot</option></select></td>
  <td><input name="sort" type="number" value="<?=$g['sort_order']?>" style="width:70px"></td>
  <td><select name="is_active"><option value="1" <?=$g['is_active']?'selected':''?>>Bật</option><option value="0" <?=!$g['is_active']?'selected':''?>>Tắt</option></select></td>
  <td><button class="btn btn-blue" type="submit">💾 Lưu</button>
</form>
<form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="toggle_game"><input type="hidden" name="id" value="<?=$g['id']?>"><button class="btn btn-gray" type="submit"><?=$g['is_active']?'Tắt':'Bật'?></button></form>
<form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="del_game"><input type="hidden" name="id" value="<?=$g['id']?>"><button class="btn btn-red" onclick="return confirm('Xoá game này? Các gói/order/key liên quan có thể bị ảnh hưởng.')">🗑 Xoá</button></form></td>
</tr>
<?php endforeach ?>
</table>

<?php elseif($tab==='packages'): ?>
<h1>📦 Quản lý Gói cước</h1>
<?php $games = $db->query("SELECT * FROM games ORDER BY is_active DESC, sort_order")->fetchAll(); ?>
<div class="form-card">
<h3>➕ Thêm gói mới</h3>
<form method="POST"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>">
<input type="hidden" name="act" value="add_pkg">
<div class="form-row">
  <div><label>Game</label><select name="game_id"><?php foreach($games as $g):?><option value="<?=$g['id']?>"><?=$g['name']?></option><?php endforeach?></select></div>
  <div><label>Số ngày</label><input name="days" type="number" required placeholder="7" style="width:80px"></div>
  <div><label>Duration (hours)</label><input name="duration_hours" type="number" placeholder="168" style="width:100px"></div>
  <div><label>Giá (đ)</label><input name="price" type="number" required placeholder="75000"></div>
  <div><label>Giá/device (đ)</label><input name="price_per_device" type="number" placeholder="75000"></div>
  <div><label>Loại key</label><select name="key_type"><option>Normal</option><option>VIP</option></select></div>
  <div style="padding-top:20px"><button class="btn btn-blue" type="submit">➕ Thêm</button></div>
</div>
</form>
</div>
<?php $pkgs = $db->query("SELECT p.*,g.name as game_name FROM packages p JOIN games g ON p.game_id=g.id ORDER BY g.sort_order,p.days")->fetchAll(); ?>
<table>
<tr><th>Game</th><th>Tên gói</th><th>Ngày</th><th>Hours</th><th>Giá</th><th>Giá/Dev</th><th>Loại</th><th>Active</th><th>Thao tác</th></tr>
<?php foreach($pkgs as $p): ?>
<tr>
<form method="POST"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>">
  <input type="hidden" name="act" value="edit_pkg"><input type="hidden" name="id" value="<?=$p['id']?>">
  <td><select name="game_id"><?php foreach($games as $g):?><option value="<?=$g['id']?>" <?=$p['game_id']==$g['id']?'selected':''?>><?=$g['name']?></option><?php endforeach?></select></td>
  <td><input name="name" value="<?=htmlspecialchars($p['name'])?>" required style="width:120px"></td>
  <td><input name="days" type="number" value="<?=$p['days']?>" required style="width:70px"></td>
  <td><input name="duration_hours" type="number" value="<?=$p['duration_hours']?>" style="width:70px"></td>
  <td><input name="price" type="number" value="<?=$p['price']?>" required style="width:100px"></td>
  <td><input name="price_per_device" type="number" value="<?=$p['price_per_device']?>" style="width:100px"></td>
  <td><select name="key_type"><option <?=$p['key_type']==='Normal'?'selected':''?>>Normal</option><option <?=$p['key_type']==='VIP'?'selected':''?>>VIP</option></select></td>
  <td><select name="is_active"><option value="1" <?=$p['is_active']?'selected':''?>>Bật</option><option value="0" <?=!$p['is_active']?'selected':''?>>Tắt</option></select></td>
  <td><button class="btn btn-blue" type="submit">💾 Lưu</button>
</form>
<form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="toggle_pkg"><input type="hidden" name="id" value="<?=$p['id']?>"><button class="btn btn-gray" type="submit"><?=$p['is_active']?'Tắt':'Bật'?></button></form>
<form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="del_pkg"><input type="hidden" name="id" value="<?=$p['id']?>"><button class="btn btn-red" onclick="return confirm('Xoá gói này?')">🗑</button></form></td>
</tr>
<?php endforeach ?>
</table>


<?php elseif($tab==='freekeys'): ?>
<h1>🎁 GetKey Free</h1>
<?php $gamesAll=$db->query("SELECT * FROM games ORDER BY is_active DESC, sort_order")->fetchAll(); $packagesAll=$db->query("SELECT p.*,g.name game_name FROM packages p JOIN games g ON p.game_id=g.id ORDER BY g.sort_order,p.days")->fetchAll(); ?>
<div class="form-card"><h3>➕ Thêm key free mới</h3>
<form method="POST"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="add_free_key">
<div class="form-row">
<div><label>Key code</label><input name="key_code" required placeholder="abcd..." style="width:220px"></div>
<div><label>Game</label><select name="game_id"><?php foreach($gamesAll as $g): ?><option value="<?=$g['id']?>"><?=$g['name']?> <?=$g['is_active']?'':'(Tắt)'?></option><?php endforeach ?></select></div>
<div><label>Gói</label><select name="package_id"><?php foreach($packagesAll as $p): ?><option value="<?=$p['id']?>"><?=$p['game_name']?> · <?=$p['name']?> · <?=$p['key_type']?> <?=$p['is_active']?'':'(Tắt)'?></option><?php endforeach ?></select></div>
<div style="padding-top:20px"><button class="btn btn-blue">Tạo link 2 lớp</button></div>
</div></form></div>
<?php $fks=$db->query("SELECT fk.*,g.name game_name,p.name pkg_name,(SELECT COUNT(*) FROM free_key_claims c WHERE c.free_key_id=fk.id) claims FROM free_keys fk JOIN games g ON fk.game_id=g.id JOIN packages p ON fk.package_id=p.id ORDER BY fk.created_at DESC LIMIT 100")->fetchAll(); ?>
<table><tr><th>Key</th><th>Game/Gói</th><th>Thời gian</th><th>Link</th><th>Claim</th><th>TT</th><th>Action</th></tr>
<?php foreach($fks as $fk): ?><tr>
<td style="font-family:monospace"><?=htmlspecialchars($fk['key_code'])?></td><td><?=$fk['game_name']?><br><small style="color:#8b949e"><?=$fk['pkg_name']?> · <?=$fk['key_type']?></small></td>
<td><small><?=date('d/m H:i',strtotime($fk['start_at']))?> → <?=date('d/m H:i',strtotime($fk['expire_at']))?></small></td>
<td style="max-width:240px;overflow:hidden;text-overflow:ellipsis"><a href="<?=htmlspecialchars($fk['short_url']?:SITE_URL.'/claim.php?t='.$fk['claim_token'])?>" target="_blank">Mở link</a><br><small style="color:#8b949e"><?=htmlspecialchars($fk['short_url']?:'Chưa có link')?></small></td>
<td><?=$fk['claims']?></td><td><span class="badge <?=$fk['is_active']?'green':'gray'?>"><?=$fk['is_active']?'Bật':'Tắt'?></span></td>
<td><form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="toggle_free_key"><input type="hidden" name="id" value="<?=$fk['id']?>"><button class="btn btn-gray"><?=$fk['is_active']?'Tắt':'Bật'?></button></form>
<form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="regen_free_link"><input type="hidden" name="id" value="<?=$fk['id']?>"><button class="btn btn-blue">Tạo lại link</button></form></td>
</tr><?php endforeach ?>
<?php if(!$fks): ?><tr><td colspan="7" style="text-align:center;color:#8b949e;padding:24px">Chưa có key free nào</td></tr><?php endif ?>
</table>

<?php elseif($tab==='sysconfig'): ?>
<h1>⚙️ Cấu hình hệ thống</h1>
<?php ensureAdminConfigLogTable($db); $cfgKeys = hclouConfigEditableKeys(); $logs = $db->query("SELECT * FROM admin_config_logs ORDER BY id DESC LIMIT 30")->fetchAll(); ?>
<div class="warnbox">⚠️ Chỉ sửa các mục thật sự cần. Token/API key không được public. Khi lưu, hệ thống tự tạo backup <span class="mono">config.php.bk_admincfg_*</span> rồi ghi log vào SQL.</div>
<form method="POST" class="form-card">
<input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="save_config">
<h3>Thông tin site/bot</h3><div class="form-row">
<?php foreach(['SITE_URL'=>'Site URL','SITE_NAME'=>'Site name','ADMIN_CHAT_ID'=>'Admin chat ID','BOT_USERNAME'=>'Bot username'] as $k=>$label): ?>
<div><label><?=$label?></label><input name="cfg[<?=$k?>]" value="<?=htmlspecialchars((string)hclouConfigValue($k))?>"></div>
<?php endforeach; ?></div>
<h3 style="margin-top:20px">Bank / VietQR</h3><div class="form-row">
<?php foreach(['BANK_NAME'=>'Ngân hàng','BANK_ACCOUNT'=>'Số tài khoản','BANK_OWNER'=>'Chủ tài khoản','VIETQR_BANK_ID'=>'VietQR bank BIN'] as $k=>$label): ?>
<div><label><?=$label?></label><input name="cfg[<?=$k?>]" value="<?=htmlspecialchars((string)hclouConfigValue($k))?>"></div>
<?php endforeach; ?></div>
<h3 style="margin-top:20px">API / Auto-bank / GetKey Free</h3><div class="form-row">
<div style="flex:1;min-width:300px"><label>MBBANK API</label><input style="width:100%" value="Direct local service: http://127.0.0.1:3120/history" readonly><small>Hiện đã dùng API gốc MBBank qua service nội bộ <code>mbbank-direct-service</code>. Không cần token queenvps ở đây nữa. Cấu hình user/pass/STK nằm trong <code>mbbank-direct-service/.env</code>.</small></div>
<div><label>Auto-bank</label><select name="cfg[MBBANK_AUTO_APPROVE_ENABLED]"><option value="1" <?=MBBANK_AUTO_APPROVE_ENABLED?'selected':''?>>Bật</option><option value="0" <?=!MBBANK_AUTO_APPROVE_ENABLED?'selected':''?>>Tắt</option></select></div>
<div><label>GetKey Free</label><select name="cfg[FREE_GETKEY_ENABLED]"><option value="1" <?=FREE_GETKEY_ENABLED?'selected':''?>>Bật</option><option value="0" <?=!FREE_GETKEY_ENABLED?'selected':''?>>Tắt</option></select></div>
<div style="flex:1;min-width:260px"><label>Link4M token</label><input style="width:100%" name="cfg[LINK4M_API_TOKEN]" value="<?=htmlspecialchars((string)hclouConfigValue('LINK4M_API_TOKEN'))?>"></div>
<div style="flex:1;min-width:260px"><label>YeuMoney token</label><input style="width:100%" name="cfg[YEUMONEY_API_TOKEN]" value="<?=htmlspecialchars((string)hclouConfigValue('YEUMONEY_API_TOKEN'))?>"></div>
</div>
<div style="margin-top:18px"><button class="btn btn-green" type="submit">💾 Lưu cấu hình</button></div>
</form>
<div class="form-card"><h3>🧹 Bảo trì nhanh</h3><p>Tự chuyển key hết hạn sang expired, xoá key expired quá 3 ngày không gia hạn, và huỷ đơn pending quá 30 phút.</p><form method="POST" style="margin-top:12px"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="act" value="run_maintenance"><button class="btn btn-blue" type="submit">Chạy maintenance ngay</button></form><?php if(isset($_GET['maint'])):?><div class="codebox"><?=htmlspecialchars($_GET['maint'])?></div><?php endif; ?></div>
<div class="form-card"><h3>🧾 Log thay đổi cấu hình</h3><table><tr><th>ID</th><th>Admin</th><th>Key</th><th>Old</th><th>New</th><th>Time</th></tr><?php foreach($logs as $l): ?><tr><td><?=$l['id']?></td><td><?=htmlspecialchars($l['admin'])?></td><td class="mono"><?=htmlspecialchars($l['config_key'])?></td><td><?=htmlspecialchars($l['old_value'] ?? '')?></td><td><?=htmlspecialchars($l['new_value'] ?? '')?></td><td class="mono"><?=htmlspecialchars($l['created_at'])?></td></tr><?php endforeach; if(!$logs): ?><tr><td colspan="6"><p>Chưa có log.</p></td></tr><?php endif; ?></table></div>

<?php elseif($tab==='setup'): ?>
<h1>🧭 Setup/API & Cấu hình hệ thống</h1>
<div class="warnbox">⚠️ Trang này là chức năng hướng dẫn trong admin: chỉ chỉ rõ từng API/token lấy ở đâu, file nào liên quan và lệnh kiểm tra. Không hiển thị token thật để tránh lộ bảo mật.</div>
<div class="okbox">✅ Flow hiện tại: Paid key dùng VietQR + MBBANK Direct tự duyệt. Admin không duyệt tay paid order nữa; chỉ còn từ chối đơn lỗi/spam.</div>
<div class="warnbox">📦 Nếu sau này chuyển qua cPanel/server mới, đọc file <code>/www/wwwroot/hclou.com/CPANEL_DEPLOY_GUIDE.md</code>. File này hướng dẫn từng bước: upload code, import DB, sửa config, chạy Node MBBank Direct, chặn public service, setup webhook, setup cron và checklist verify 100%.</div>

<div class="guide-grid">
  <div class="guide-card"><span class="where">config.php</span><h3>🗄 Database</h3><ul><li>Sửa: <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code>.</li><li>Lấy ở panel MySQL/phpMyAdmin/hosting.</li><li>Nên dùng <code>127.0.0.1</code> nếu PHP-FPM lỗi socket với localhost.</li></ul><div class="codebox">php -r "require '/www/wwwroot/hclou.com/config.php'; var_dump((bool)getDB());"</div></div>

  <div class="guide-card"><span class="where">config.php + webhook.php</span><h3>🤖 Telegram Bot</h3><ul><li>Lấy token tại <b>@BotFather</b> → tạo bot hoặc xem token.</li><li>Sửa <code>BOT_TOKEN</code>, <code>BOT_USERNAME</code>, <code>ADMIN_CHAT_ID</code>.</li><li>Webhook URL: <code><?=htmlspecialchars(SITE_URL)?>/webhook.php</code>.</li></ul><div class="codebox">https://api.telegram.org/bot&lt;BOT_TOKEN&gt;/getWebhookInfo
https://api.telegram.org/bot&lt;BOT_TOKEN&gt;/setWebhook?url=<?=htmlspecialchars(SITE_URL)?>/webhook.php
php -l webhook.php</div></div>

  <div class="guide-card"><span class="where">@BotFather + index.php</span><h3>📱 Telegram Mini App</h3><ul><li>Trong @BotFather đặt Web App/Menu Button URL về <code><?=htmlspecialchars(SITE_URL)?>/</code>.</li><li>Frontend chính nằm ở <code>index.php</code>.</li><li>API Mini App nằm ở <code>api/index.php</code>.</li></ul><div class="codebox">curl '<?=htmlspecialchars(SITE_URL)?>/api/?action=games'
curl '<?=htmlspecialchars(SITE_URL)?>/api/?action=packages&amp;game_id=4'</div></div>

  <div class="guide-card"><span class="where">config.php + index.php</span><h3>🏦 Bank/VietQR</h3><ul><li>Sửa <code>BANK_NAME</code>, <code>BANK_ACCOUNT</code>, <code>BANK_OWNER</code>, <code>VIETQR_BANK_ID</code>.</li><li>MBBank BIN hiện tại: <code>970422</code>.</li><li>VietQR tự điền số tiền + mã đơn ORD.</li></ul><div class="codebox">php -r "require '/www/wwwroot/hclou.com/config.php'; echo buildVietQrUrl(25000,'ORDTEST'), PHP_EOL;"</div></div>

  <div class="guide-card"><span class="where">mbbank-direct-service + cron_run.php + mbbank_poll.php</span><h3>✅ MBBANK Auto-bank Direct</h3><ul><li>Hiện dùng API gốc MBBank qua service nội bộ, không còn phụ thuộc queenvps để lấy lịch sử giao dịch.</li><li>Service local: <code>http://127.0.0.1:3120/history</code>, source tại <code>/www/wwwroot/hclou.com/mbbank-direct-service</code>.</li><li>Thông tin MBBank nằm trong <code>mbbank-direct-service/.env</code>: <code>MBB_USER</code>, <code>MBB_PASS</code>, <code>MBB_ACCOUNT_NUMBER</code>.</li><li>Service dùng Puppeteer login MBBank, giải CAPTCHA, giữ session, tự login lại khi session hết hạn.</li><li>Endpoint ngoài vẫn gọi <code>cron_run.php?job=mbbank</code>; script thật vẫn là <code>mbbank_poll.php</code>.</li><li>Tuyệt đối không public thư mục <code>mbbank-direct-service</code>; nginx đã chặn public 404.</li></ul><div class="codebox">Job cron-job.org: HCLOU MBBANK
Lịch: mỗi phút
URL: <?=htmlspecialchars(hclouCronRunUrl('mbbank'))?>

Service nội bộ:
systemctl status hclou-mbbank-direct.service --no-pager -l
curl http://127.0.0.1:3120/health
curl -m 80 http://127.0.0.1:3120/history

Script thật: /www/wwwroot/hclou.com/mbbank_poll.php
Test VPS: php /www/wwwroot/hclou.com/mbbank_poll.php
Test HTTP: curl '<?=htmlspecialchars(hclouCronRunUrl('mbbank'))?>'

Docs chi tiết: /www/wwwroot/hclou.com/README_MBBANK_DIRECT.md</div></div>

  <div class="guide-card"><span class="where">cron-job.org + cron_run.php</span><h3>🤖 Cron ngoài đang dùng</h3><ul><li>Web quản lý/tạo job: <code>https://console.cron-job.org/</code>.</li><li>API key cron-job.org lấy tại Console → Settings → API keys.</li><li><code>CRON_RUN_TOKEN</code> nằm trong file <code>cron_run.php</code>; dùng chung cho các job wrapper.</li><li>Token chỉ hiển thị dạng rút gọn để tránh lộ secret.</li></ul><div class="codebox">HCLOU MBBANK     | mỗi phút   | <?=htmlspecialchars(hclouCronRunUrl('mbbank'))?>
HCLOU Maintenance| mỗi 5 phút | <?=htmlspecialchars(hclouCronRunUrl('maintenance'))?>
HCLOU Automation | mỗi 2 phút | <?=htmlspecialchars(hclouCronRunUrl('automation'))?>
HCLOU Health    | 08:00 VN  | <?=htmlspecialchars(hclouCronRunUrl('health'))?>
Tuỳ chọn Backup  | hằng ngày  | <?=htmlspecialchars(hclouCronRunUrl('backup'))?>

Cron-job.org API docs: https://docs.cron-job.org/rest-api.html
Verify history: Console → job → History → phải thấy 200 OK</div></div>

  <div class="guide-card"><span class="where">automation_run.php + automation_daily.php</span><h3>🔔 Automation/Reminder trực tiếp</h3><ul><li>Endpoint cũ/trực tiếp: <code>automation_run.php</code>; hiện nên ưu tiên wrapper <code>cron_run.php?job=automation</code>.</li><li><code>AUTOMATION_RUN_TOKEN</code> nằm trong file <code>automation_run.php</code>.</li><li>Chức năng: nhắc thanh toán gần hết hạn, báo đơn bị huỷ, cảnh báo bank ignored/error, báo cáo ngày nếu cron chạy đúng khung.</li></ul><div class="codebox">Direct URL: <?=htmlspecialchars(hclouAutomationRunUrl())?>
Khuyến nghị dùng: <?=htmlspecialchars(hclouCronRunUrl('automation'))?>
Test VPS: php /www/wwwroot/hclou.com/automation_daily.php
Test HTTP: curl '<?=htmlspecialchars(hclouCronRunUrl('automation'))?>'</div></div>

  <div class="guide-card"><span class="where">cron-job.org + health_check_daily.php</span><h3>🩺 Daily Health Check</h3><ul><li>Job ngoài chạy hằng ngày khoảng 08:00 giờ Việt Nam.</li><li>Kiểm tra web home, Mini App API, DB, MBBANK auto approve, maintenance, disk/RAM, đơn/key/bank lỗi.</li><li>Sau khi chạy sẽ gửi báo cáo về Telegram admin.</li><li>Endpoint wrapper: <code>cron_run.php?job=health</code>; script thật: <code>health_check_daily.php</code>.</li></ul><div class="codebox">Cron-job.org: HCLOU Daily Health Check
Lịch: 08:00 Asia/Ho_Chi_Minh mỗi ngày
URL: <?=htmlspecialchars(hclouCronRunUrl('health'))?>
Test VPS: php /www/wwwroot/hclou.com/health_check_daily.php
Test HTTP: curl '<?=htmlspecialchars(hclouCronRunUrl('health'))?>'</div></div>

  <div class="guide-card"><span class="where">config.php + admin GetKey Free</span><h3>🎁 Link4M/YeuMoney</h3><ul><li>Lấy token trong dashboard Link4M/YeuMoney mục API/Developer.</li><li>Sửa <code>LINK4M_API_TOKEN</code>, <code>YEUMONEY_API_TOKEN</code>.</li><li>Flow: Link4M → YeuMoney → HCLOU claim.</li></ul><div class="codebox">Admin → GetKey Free → nhập key → chọn game/gói → Tạo link 2 lớp</div></div>

  <div class="guide-card"><span class="where">admin/index.php</span><h3>🛠 Quản lý trong Admin</h3><ul><li>Games: thêm/sửa/tắt game.</li><li>Gói cước: sửa ngày/giá/key type.</li><li>Keys: khoá/mở/xoá key.</li><li>Đơn hàng: xem trạng thái, từ chối đơn pending lỗi/spam.</li></ul><div class="codebox">Paid order: pending → MBBANK API xác nhận → approved → key active</div></div>

  <div class="guide-card"><span class="where">/www/backup/hclou_db</span><h3>💾 Backup DB</h3><ul><li>Script backup: <code>/www/backup/hclou_db/backup.sh</code>.</li><li>Giữ 7 bản backup mới nhất.</li><li>Có thể chạy qua <code>cron_run.php?job=backup</code> nếu muốn dùng cron ngoài.</li><li>Không xoá backup DB nếu chưa chắc.</li></ul><div class="codebox">Cron ngoài: <?=htmlspecialchars(hclouCronRunUrl('backup'))?>
Hoặc cron VPS: 17 3 * * * /www/backup/hclou_db/backup.sh &gt;/dev/null 2&gt;&amp;1
Test VPS: /www/backup/hclou_db/backup.sh</div></div>
</div>

<div class="form-card">
<h3>🔍 Checklist verify sau khi sửa code</h3>
<div class="codebox">cd /www/wwwroot/hclou.com
php -l config.php
php -l index.php
php -l api/index.php
php -l admin/index.php
php -l webhook.php
php -l claim.php
php -l setup_webhook.php
php -l mbbank_poll.php
php -l maintenance.php
php -l automation_daily.php
php -l cron_run.php
curl -I <?=htmlspecialchars(SITE_URL)?>/
curl '<?=htmlspecialchars(SITE_URL)?>/api/?action=games'
php mbbank_poll.php
php maintenance.php
curl '<?=htmlspecialchars(hclouCronRunUrl('mbbank'))?>'
curl '<?=htmlspecialchars(hclouCronRunUrl('maintenance'))?>'
curl '<?=htmlspecialchars(hclouCronRunUrl('automation'))?>'
curl '<?=htmlspecialchars(hclouCronRunUrl('health'))?>'</div>
</div>

<div class="form-card">
<h3>🧯 Lỗi thường gặp</h3>
<table><tr><th>Lỗi</th><th>Kiểm tra</th><th>File liên quan</th></tr>
<tr><td>API games lỗi DB</td><td>DB_HOST/DB_USER/DB_PASS, dùng 127.0.0.1</td><td>config.php</td></tr>
<tr><td>Bot không trả lời</td><td>BOT_TOKEN, webhook, php -l webhook.php</td><td>config.php, webhook.php</td></tr>
<tr><td>Thanh toán không auto active</td><td>Kiểm tra cron ngoài, <code>hclou-mbbank-direct.service</code>, <code>curl 127.0.0.1:3120/health</code>, description có ORD, amount đủ tiền</td><td>mbbank_poll.php, mbbank-direct-service/server.mjs</td></tr>
<tr><td>VietQR không hiện</td><td>buildVietQrUrl, bank id, img.vietqr.io</td><td>config.php, index.php</td></tr>
<tr><td>GetKey Free lỗi link</td><td>Token Link4M/YeuMoney, endpoint, curl internet</td><td>config.php, admin/index.php</td></tr>
</table>
</div>

<?php elseif($tab==='users'): ?>
<h1>👥 Danh sách Users</h1>
<?php $users = $db->query("SELECT u.*, (SELECT COUNT(*) FROM `keys` WHERE user_id=u.id) as total_keys, (SELECT COUNT(*) FROM orders WHERE user_id=u.id AND status='approved') as total_orders FROM users u ORDER BY u.created_at DESC")->fetchAll(); ?>
<table>
<tr><th>Telegram ID</th><th>Username</th><th>Tên</th><th>Keys</th><th>Đơn</th><th>Ngày tạo</th></tr>
<?php foreach($users as $u): ?>
<tr>
  <td style="font-size:12px;font-family:monospace"><?=$u['telegram_id']?></td>
  <td>@<?=$u['telegram_username']?></td>
  <td><?=$u['full_name']?></td>
  <td><?=$u['total_keys']?></td>
  <td><?=$u['total_orders']?></td>
  <td style="font-size:12px;color:#8b949e"><?=date('d/m/Y',strtotime($u['created_at']))?></td>
</tr>
<?php endforeach ?>
</table>
<?php endif ?>
</div>
</div>

<footer class="admin-footer">Copyright by HCLOU Server · Telegram @hcloucom · Địa chỉ: Thành phố Quảng Ngãi</footer>
</body>
</html>
