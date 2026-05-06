<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Plans - <?= SITE_NAME ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0a0e1a;--card:#111827;--border:#1f2937;--text:#e5e7eb;--text2:#9ca3af;--primary:#3b82f6;--success:#10b981;--cyan:#06b6d4}
body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:20px;min-height:100vh}
.container{max-width:1200px;margin:0 auto}
h1{font-size:32px;font-weight:900;text-align:center;margin-bottom:12px;background:linear-gradient(135deg,#3b82f6,#06b6d4);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.subtitle{text-align:center;color:var(--text2);margin-bottom:40px;font-size:16px}
.plans-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:24px;margin-bottom:40px}
.plan-card{background:var(--card);border:2px solid var(--border);border-radius:20px;padding:28px;position:relative;transition:.2s}
.plan-card:hover{border-color:var(--primary);transform:translateY(-4px)}
.plan-card.featured{border-color:var(--primary);box-shadow:0 0 40px rgba(59,130,246,.3)}
.plan-badge{position:absolute;top:-12px;right:20px;background:linear-gradient(135deg,#3b82f6,#06b6d4);color:#fff;padding:6px 16px;border-radius:999px;font-size:12px;font-weight:900}
.plan-name{font-size:24px;font-weight:900;margin-bottom:8px}
.plan-price{font-size:36px;font-weight:900;color:var(--primary);margin-bottom:4px}
.plan-price small{font-size:16px;color:var(--text2);font-weight:600}
.plan-duration{color:var(--text2);font-size:14px;margin-bottom:20px}
.plan-features{list-style:none;margin-bottom:24px}
.plan-features li{padding:10px 0;border-bottom:1px solid var(--border);font-size:14px;display:flex;align-items:center;gap:8px}
.plan-features li:last-child{border:none}
.plan-features li:before{content:"✓";color:var(--success);font-weight:900;font-size:18px}
.plan-btn{width:100%;padding:14px;border:none;border-radius:12px;font-size:16px;font-weight:900;cursor:pointer;transition:.2s;background:linear-gradient(135deg,#3b82f6,#06b6d4);color:#fff}
.plan-btn:hover{transform:scale(1.02);box-shadow:0 8px 24px rgba(59,130,246,.4)}
.plan-btn.free{background:#374151;color:var(--text)}
.back-btn{display:inline-flex;align-items:center;gap:8px;color:var(--text2);text-decoration:none;margin-bottom:24px;font-weight:600;transition:.2s}
.back-btn:hover{color:var(--primary)}
</style>
</head>
<body>
<div class="container">
  <a href="./" class="back-btn">← Quay lại</a>

  <h1>🚀 Chọn gói phù hợp</h1>
  <p class="subtitle">Tạo keys và packages không giới hạn trong quota của bạn</p>

  <div class="plans-grid">
    <?php
    require_once 'config.php';
    $db = getDB();
    $plans = $db->query("SELECT * FROM plans WHERE is_active=1 ORDER BY sort_order")->fetchAll();

    foreach($plans as $i => $p):
      $featured = $p['name'] === 'Pro';
    ?>
    <div class="plan-card <?=$featured?'featured':''?>">
      <?php if($featured): ?><div class="plan-badge">POPULAR</div><?php endif ?>

      <div class="plan-name"><?=$p['name']?></div>
      <div class="plan-price">
        <?php if($p['price'] > 0): ?>
          <?=number_format($p['price'],0,',','.')?>đ
          <small>/<?=$p['duration_days']?> ngày</small>
        <?php else: ?>
          Miễn phí
        <?php endif ?>
      </div>
      <div class="plan-duration"><?=$p['duration_days']?> ngày sử dụng</div>

      <ul class="plan-features">
        <li><?=number_format($p['max_keys'])?> keys</li>
        <li><?=$p['max_packages']?> packages</li>
        <li><?=$p['max_devices_per_key']?> devices/key</li>
        <li>Panel Kuro API</li>
        <li>24/7 Support</li>
      </ul>

      <button class="plan-btn <?=$p['price']==0?'free':''?>" onclick="selectPlan(<?=$p['id']?>,'<?=$p['name']?>',<?=$p['price']?>)">
        <?=$p['price']==0?'Bắt đầu miễn phí':'Mua ngay'?>
      </button>
    </div>
    <?php endforeach ?>
  </div>
</div>

<script>
function selectPlan(id, name, price) {
  if(price === 0) {
    alert('Gói Free đã được kích hoạt tự động khi đăng ký!');
    return;
  }

  if(confirm('Mua gói ' + name + ' với giá ' + price.toLocaleString() + 'đ?')) {
    // TODO: Redirect to payment or credit purchase
    window.location.href = './?buy_plan=' + id;
  }
}
</script>
</body>
</html>
<?php
