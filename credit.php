<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Nạp Credit - <?= SITE_NAME ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0a0e1a;--card:#111827;--border:#1f2937;--text:#e5e7eb;--text2:#9ca3af;--primary:#3b82f6;--success:#10b981;--cyan:#06b6d4}
body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:20px;min-height:100vh}
.container{max-width:600px;margin:0 auto}
h1{font-size:28px;font-weight:900;margin-bottom:8px}
.subtitle{color:var(--text2);margin-bottom:24px}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:24px;margin-bottom:20px}
.balance{text-align:center;padding:20px;background:linear-gradient(135deg,rgba(59,130,246,.15),rgba(6,182,212,.1));border-radius:16px;margin-bottom:24px}
.balance-label{font-size:13px;color:var(--text2);margin-bottom:6px}
.balance-amount{font-size:36px;font-weight:900;color:var(--primary)}
.amount-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:20px}
.amount-btn{padding:16px;border:2px solid var(--border);border-radius:12px;background:transparent;color:var(--text);font-size:16px;font-weight:700;cursor:pointer;transition:.2s}
.amount-btn:hover{border-color:var(--primary);background:rgba(59,130,246,.1)}
.amount-btn.active{border-color:var(--primary);background:rgba(59,130,246,.2)}
.custom-input{width:100%;padding:14px;border:2px solid var(--border);border-radius:12px;background:var(--card);color:var(--text);font-size:16px;margin-bottom:20px}
.custom-input:focus{outline:none;border-color:var(--primary)}
.btn{width:100%;padding:16px;border:none;border-radius:12px;font-size:16px;font-weight:900;cursor:pointer;background:linear-gradient(135deg,#3b82f6,#06b6d4);color:#fff;transition:.2s}
.btn:hover{transform:scale(1.02)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.back-btn{display:inline-flex;align-items:center;gap:8px;color:var(--text2);text-decoration:none;margin-bottom:20px;font-weight:600}
.back-btn:hover{color:var(--primary)}
.note{font-size:13px;color:var(--text2);line-height:1.6;padding:12px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:12px}
</style>
</head>
<body>
<div class="container">
  <a href="./" class="back-btn">← Quay lại</a>

  <h1>💰 Nạp Credit</h1>
  <p class="subtitle">Nạp tiền để mua plans và sử dụng dịch vụ</p>

  <div class="balance">
    <div class="balance-label">Số dư hiện tại</div>
    <div class="balance-amount" id="currentBalance">0đ</div>
  </div>

  <div class="card">
    <h3 style="margin-bottom:16px;font-size:16px">Chọn số tiền nạp</h3>

    <div class="amount-grid">
      <button class="amount-btn" onclick="selectAmount(100000)">100,000đ</button>
      <button class="amount-btn" onclick="selectAmount(200000)">200,000đ</button>
      <button class="amount-btn" onclick="selectAmount(500000)">500,000đ</button>
      <button class="amount-btn" onclick="selectAmount(1000000)">1,000,000đ</button>
    </div>

    <input type="number" class="custom-input" id="customAmount" placeholder="Hoặc nhập số tiền khác (tối thiểu 50,000đ)" min="50000" step="10000">

    <button class="btn" id="depositBtn" onclick="createDeposit()">Tạo lệnh nạp tiền</button>
  </div>

  <div class="note">
    ⚠️ <strong>Lưu ý:</strong><br>
    • Số tiền tối thiểu: 50,000đ<br>
    • Chuyển khoản đúng nội dung để tự động duyệt<br>
    • Credit không hoàn lại, chỉ dùng để mua plans
  </div>
</div>

<script>
var selectedAmount = 0;

function selectAmount(amount) {
  selectedAmount = amount;
  document.querySelectorAll('.amount-btn').forEach(b => b.classList.remove('active'));
  event.target.classList.add('active');
  document.getElementById('customAmount').value = '';
}

async function createDeposit() {
  var amount = selectedAmount || parseInt(document.getElementById('customAmount').value);
  if (!amount || amount < 50000) {
    alert('Số tiền tối thiểu là 50,000đ');
    return;
  }

  var btn = document.getElementById('depositBtn');
  btn.disabled = true;
  btn.textContent = 'Đang tạo...';

  // TODO: Call API to create deposit order
  alert('Chức năng đang phát triển. Sẽ tạo QR code nạp ' + amount.toLocaleString() + 'đ');

  btn.disabled = false;
  btn.textContent = 'Tạo lệnh nạp tiền';
}

// Load current balance
window.onload = function() {
  // TODO: Fetch from API
  document.getElementById('currentBalance').textContent = '0đ';
};
</script>
</body>
</html>
<?php
