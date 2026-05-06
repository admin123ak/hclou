<?php
require_once 'config.php';
$db=getDB();
$t=$_GET['t']??'';
$fk=null;
if ($t) { $stmt=$db->prepare("SELECT fk.*,g.name game_name,p.name pkg_name FROM free_keys fk JOIN games g ON fk.game_id=g.id JOIN packages p ON fk.package_id=p.id WHERE fk.claim_token=?"); $stmt->execute([$t]); $fk=$stmt->fetch(); }
function page($title,$msg,$ok=false){ echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>HCLOU Claim</title><style>body{margin:0;min-height:100vh;background:radial-gradient(circle at 20% 10%,#1f6feb55,transparent 30%),#070b14;color:#e6edf3;font-family:-apple-system,Segoe UI,sans-serif;display:flex;align-items:center;justify-content:center;padding:20px}.card{max-width:420px;background:#111827dd;border:1px solid #38bdf833;border-radius:28px;padding:28px;text-align:center;box-shadow:0 24px 80px #0008}.ico{font-size:42px}.btn{display:block;margin-top:18px;padding:14px;border-radius:999px;background:linear-gradient(135deg,#06b6d4,#3b82f6);color:#fff;text-decoration:none;font-weight:900}</style></head><body><div class="card"><div class="ico">'.($ok?'✅':'⚠️').'</div><h2>'.$title.'</h2><p>'.$msg.'</p><a class="btn" href="'.SITE_URL.'">Mở Mini App</a></div></body></html>'; exit; }
if(!$fk) page('Link không hợp lệ','Token claim không tồn tại.');
if(!$fk['is_active'] || strtotime($fk['expire_at'])<time()) page('Key free đã hết hạn','Key này không còn khả dụng.');
$tg=$_GET['telegram_id']??0;
if(!$tg) { header('Location: '.SITE_URL.'/?claim='.urlencode($t).'&v=claim20260428'); exit; }
$stmt=$db->prepare("SELECT * FROM users WHERE telegram_id=?"); $stmt->execute([$tg]); $user=$stmt->fetch();
if(!$user) page('Chưa đăng nhập','Không tìm thấy tài khoản Telegram trên hệ thống.');
$chk=$db->prepare("SELECT user_id FROM free_key_claims WHERE free_key_id=? LIMIT 1"); $chk->execute([$fk['id']]); $old=$chk->fetch(); if($old && (int)$old['user_id']===(int)$user['id']) page('Bạn đã nhận rồi','Key free này đã nằm trong tài khoản của bạn.',true); if($old) page('Key đã có người nhận','Key free này đã được claim trước đó.');
$db->beginTransaction();
try{
 $db->prepare("INSERT INTO `keys` (key_code,user_id,game_id,package_id,status,days,start_at,expire_at) VALUES (?,?,?,?, 'active', ?, ?, ?)")->execute([$fk['key_code'],$user['id'],$fk['game_id'],$fk['package_id'],$fk['days'],$fk['start_at'],$fk['expire_at']]);
 $kid=$db->lastInsertId();
 $db->prepare("INSERT INTO free_key_claims (free_key_id,user_id,key_id) VALUES (?,?,?)")->execute([$fk['id'],$user['id'],$kid]);
 $db->prepare("UPDATE free_keys SET is_active=0 WHERE id=?")->execute([$fk['id']]);
 $db->commit();
 page('Nhận key thành công','Key đã được thêm vào mục Key của bạn.',true);
}catch(Exception $e){$db->rollBack(); page('Không nhận được key','Có thể bạn đã nhận key này hoặc key bị trùng.');}
