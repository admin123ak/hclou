# HCLOU - Hướng dẫn chuyển web sang cPanel/server mới

File này viết để sau này không có người cài hộ vẫn biết cách đưa full code HCLOU lên server mới và chạy đủ: web, bot Telegram, VietQR, auto-bank MBBank, cron, backup.

---

## 0. Hiểu nhanh hệ thống đang chạy cái gì

HCLOU gồm 2 phần:

### A. Web PHP

Nằm ở:

```text
/www/wwwroot/hclou.com
```

Chức năng:

- Web Telegram Mini App.
- Admin panel.
- API tạo đơn/key.
- VietQR thanh toán.
- Auto duyệt đơn qua `mbbank_poll.php`.
- Cron wrapper `cron_run.php`.

### B. MBBank Direct Service Node.js

Nằm ở:

```text
/www/wwwroot/hclou.com/mbbank-direct-service
```

Chức năng:

- Tự login MBBank bằng browser headless.
- Tự giải CAPTCHA.
- Lấy lịch sử giao dịch từ API gốc MBBank.
- Chạy local tại:

```text
http://127.0.0.1:3120/history
```

Web PHP sẽ gọi service này để lấy giao dịch. Không public service này ra ngoài.

---

## 1. Những thứ cần backup trước khi chuyển server

Trên server cũ, tải/copy các thứ sau:

### 1.1. Source code web

Copy toàn bộ thư mục:

```text
/www/wwwroot/hclou.com
```

Phải bao gồm cả:

```text
mbbank-direct-service/
README.txt
README_MBBANK_DIRECT.md
CPANEL_DEPLOY_GUIDE.md
```

### 1.2. Database

Export DB hiện tại. Xem thông tin DB trong:

```text
/www/wwwroot/hclou.com/config.php
```

Các dòng quan trọng:

```php
define('DB_HOST', '...');
define('DB_NAME', '...');
define('DB_USER', '...');
define('DB_PASS', '...');
```

Export bằng phpMyAdmin hoặc lệnh:

```bash
mysqldump -u DB_USER -p DB_NAME > hclou_backup.sql
```

### 1.3. Credential/API cần giữ

Các thông tin nằm trong:

```text
config.php
mbbank-direct-service/.env
cron_run.php
```

Không gửi public các file này.

---

## 2. Upload lên cPanel/server mới

### 2.1. Upload source

Upload toàn bộ code vào document root domain, ví dụ cPanel thường là:

```text
/home/USER/public_html
```

Nếu domain chạy thư mục khác thì dùng đúng document root của domain.

Sau khi upload, các file chính phải tồn tại:

```text
public_html/config.php
public_html/index.php
public_html/api/index.php
public_html/admin/index.php
public_html/cron_run.php
public_html/mbbank_poll.php
public_html/mbbank-direct-service/server.mjs
```

### 2.2. Import database

Vào cPanel → MySQL Databases:

1. Tạo database mới.
2. Tạo user DB mới.
3. Gán user vào DB với toàn quyền.
4. Vào phpMyAdmin import file `.sql` đã backup.

---

## 3. Sửa `config.php` trên server mới

Mở file:

```text
config.php
```

Sửa các dòng sau theo server mới:

```php
define('DB_HOST', 'localhost'); // hoặc 127.0.0.1 nếu localhost lỗi
define('DB_NAME', 'database_moi');
define('DB_USER', 'user_moi');
define('DB_PASS', 'password_moi');
```

Sửa domain:

```php
define('SITE_URL', 'https://domain-moi.com');
```

Bank/VietQR:

```php
define('BANK_NAME', 'MBBANK');
define('BANK_ACCOUNT', 'STK_NHAN_TIEN');
define('BANK_OWNER', 'TEN_CHU_TAI_KHOAN');
define('VIETQR_BANK_ID', '970422');
```

MBBank Direct URL phải giữ local:

```php
define('MBBANK_HISTORY_API_URL', 'http://127.0.0.1:3120/history');
```

Không đổi dòng này thành domain public.

---

## 4. Cài Node.js cho MBBank Direct Service

Service MBBank cần Node.js và Chrome headless.

### 4.1. Nếu cPanel có Node.js App

Vào cPanel → Setup Node.js App:

- Node version: 20 hoặc 22.
- Application root:

```text
public_html/mbbank-direct-service
```

- Application startup file:

```text
server.mjs
```

- Application URL: có thể để internal/không public nếu panel hỗ trợ. Service chỉ cần chạy local port 3120.

Sau đó vào Terminal/cPanel SSH:

```bash
cd ~/public_html/mbbank-direct-service
npm install --omit=optional
```

Nếu cPanel Node App không cho bind `127.0.0.1:3120`, dùng VPS/systemd/PM2 sẽ dễ hơn.

### 4.2. Nếu là VPS/server có SSH

Cài dependency:

```bash
cd /path/to/public_html/mbbank-direct-service
npm install --omit=optional
```

Cài thư viện Chrome headless nếu thiếu:

```bash
apt-get update
apt-get install -y --no-install-recommends \
  libatk1.0-0t64 libatk-bridge2.0-0t64 libgbm1 libcairo2 \
  libpango-1.0-0 libxcomposite1 libxdamage1 libxfixes3 \
  libxrandr2 libatspi2.0-0t64
```

Tạo systemd service:

```bash
cat > /etc/systemd/system/hclou-mbbank-direct.service <<'SERVICE'
[Unit]
Description=HCLOU MBBank Direct Local API
After=network.target

[Service]
Type=simple
WorkingDirectory=/path/to/public_html/mbbank-direct-service
ExecStart=/usr/bin/node /path/to/public_html/mbbank-direct-service/server.mjs
Restart=always
RestartSec=5
Environment=NODE_ENV=production

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable hclou-mbbank-direct.service
systemctl start hclou-mbbank-direct.service
```

Nhớ thay `/path/to/public_html` bằng đường dẫn thật.

---

## 5. Sửa `.env` của MBBank Direct Service

Mở file:

```text
mbbank-direct-service/.env
```

Nội dung mẫu:

```env
PORT=3120
HOST=127.0.0.1
MBB_USER=ten_dang_nhap_mbbank
MBB_PASS=mat_khau_mbbank
MBB_ACCOUNT_NUMBER=so_tai_khoan_nhan_tien
```

Giải thích:

- `MBB_USER`: tên đăng nhập MBBank web/app.
- `MBB_PASS`: mật khẩu MBBank.
- `MBB_ACCOUNT_NUMBER`: số tài khoản nhận tiền khách chuyển khoản.
- `HOST=127.0.0.1`: bắt buộc để service chỉ chạy nội bộ.
- `PORT=3120`: web PHP đang gọi port này.

---

## 6. Chặn public thư mục `mbbank-direct-service`

Thư mục này chứa `.env`, source service, credential. Không được để public đọc.

### 6.1. Apache/cPanel `.htaccess`

Trong thư mục:

```text
mbbank-direct-service/.htaccess
```

Nội dung:

```apache
Require all denied
Deny from all
```

Test public phải bị 403/404:

```text
https://domain-moi.com/mbbank-direct-service/server.mjs
```

Nếu thấy code hiện ra là sai, phải chặn ngay.

### 6.2. Nginx

Thêm vào server block domain:

```nginx
location ^~ /mbbank-direct-service/ {
    deny all;
    return 404;
}
```

Reload nginx.

---

## 7. Setup Telegram Bot/Webhook

Trong `config.php` kiểm tra:

```php
define('BOT_TOKEN', '...');
define('BOT_USERNAME', '...');
define('ADMIN_CHAT_ID', '...');
```

Set webhook:

```bash
curl "https://api.telegram.org/bot<BOT_TOKEN>/setWebhook?url=https://domain-moi.com/webhook.php"
```

Kiểm tra webhook:

```bash
curl "https://api.telegram.org/bot<BOT_TOKEN>/getWebhookInfo"
```

Mini App URL trong BotFather phải trỏ về:

```text
https://domain-moi.com/
```

---

## 8. Setup cron ngoài

Cron ngoài không gọi trực tiếp `mbbank-direct-service`. Cron chỉ gọi `cron_run.php`.

Lấy `CRON_RUN_TOKEN` trong file:

```text
cron_run.php
```

Các URL cron:

```text
MBBANK mỗi phút:
https://domain-moi.com/cron_run.php?token=<CRON_RUN_TOKEN>&job=mbbank

Maintenance mỗi 5 phút:
https://domain-moi.com/cron_run.php?token=<CRON_RUN_TOKEN>&job=maintenance

Automation mỗi 2-5 phút:
https://domain-moi.com/cron_run.php?token=<CRON_RUN_TOKEN>&job=automation

Health mỗi ngày 08:00 VN:
https://domain-moi.com/cron_run.php?token=<CRON_RUN_TOKEN>&job=health

Backup mỗi ngày nếu dùng:
https://domain-moi.com/cron_run.php?token=<CRON_RUN_TOKEN>&job=backup
```

Khuyến nghị:

- MBBANK: mỗi 1 phút trước cho ổn.
- Maintenance: mỗi 5 phút.
- Automation: mỗi 2-5 phút.
- Backup: mỗi ngày, không chạy liên tục.

---

## 9. Checklist test sau khi deploy

Chạy trên server mới:

```bash
cd /path/to/public_html
php -l config.php
php -l index.php
php -l api/index.php
php -l admin/index.php
php -l webhook.php
php -l cron_run.php
php -l mbbank_poll.php
php -l maintenance.php
```

Test web/API:

```bash
curl -I https://domain-moi.com/
curl 'https://domain-moi.com/api/?action=games'
```

Test MBBank service:

```bash
curl http://127.0.0.1:3120/health
curl -m 80 http://127.0.0.1:3120/history
```

Test auto-bank:

```bash
php mbbank_poll.php
```

Kết quả OK thường dạng:

```json
{"success":true,"seen_new":0,"matched":0,"approved":0}
```

Test cron wrapper:

```bash
curl 'https://domain-moi.com/cron_run.php?token=<CRON_RUN_TOKEN>&job=mbbank'
curl 'https://domain-moi.com/cron_run.php?token=<CRON_RUN_TOKEN>&job=maintenance'
```

---

## 10. Cách biết hệ thống chạy đúng 100%

Đủ các dấu hiệu sau là OK:

1. Trang chủ mở được.
2. Mini App load được game/package.
3. Admin đăng nhập được.
4. Tạo order ra VietQR đúng số tiền + mã ORD.
5. `curl 127.0.0.1:3120/history` trả được danh sách giao dịch MBBank.
6. `php mbbank_poll.php` trả success.
7. Cron ngoài history báo HTTP 200.
8. Khi khách chuyển khoản đúng mã ORD + đủ tiền, order tự chuyển `approved`, key tự active.
9. Telegram gửi key cho khách.
10. Public URL `/mbbank-direct-service/server.mjs` không đọc được code.

---

## 11. Rollback nếu MBBank Direct lỗi

Nếu MBBank Direct lỗi và cần quay lại API cũ:

1. Mở `config.php`.
2. Đổi:

```php
define('MBBANK_HISTORY_API_URL', 'http://127.0.0.1:3120/history');
```

về API cũ nếu còn token:

```php
define('MBBANK_HISTORY_API_URL', 'https://queenvps.com/api/historymb/' . MBBANK_HISTORY_API_TOKEN);
```

Hoặc restore backup:

```bash
cp -a config.php.bk config.php
```

Sau đó test:

```bash
php -l config.php
php mbbank_poll.php
```

---

## 12. Lỗi thường gặp

### Service MBBank không chạy

```bash
systemctl status hclou-mbbank-direct.service --no-pager -l
journalctl -u hclou-mbbank-direct.service -n 100 --no-pager
```

Nếu cPanel không có systemd, kiểm tra Node.js App/PM2.

### Chrome báo thiếu thư viện

Cài dependency Chrome headless ở mục 4.2.

### Login MBBank lỗi CAPTCHA/session

Kiểm tra:

```bash
curl http://127.0.0.1:3120/health
journalctl -u hclou-mbbank-direct.service -n 100 --no-pager
```

Có thể MBBank đổi UI hoặc API CAPTCHA lỗi.

### Auto-bank không duyệt đơn

Kiểm tra theo thứ tự:

1. Cron có gọi `job=mbbank` không.
2. `php mbbank_poll.php` có success không.
3. Giao dịch có mã `ORD...` trong nội dung không.
4. Số tiền nhận có đủ order không.
5. Order còn `pending` không.
6. Key của order còn `pending` không.

### Public thấy code service

Sai cấu hình bảo mật. Phải chặn ngay `/mbbank-direct-service/` bằng `.htaccess` hoặc nginx.

---

## 13. Ghi nhớ quan trọng

- Không xoá `mbbank-direct-service` nếu còn dùng auto-bank direct.
- Không xoá cache Chrome/Puppeteer nếu service đang chạy ổn, trừ khi biết cài lại.
- Không public `.env`.
- Không chạy backup mỗi vài giây/phút.
- Cron MBBANK nên để 1 phút trước; muốn nhanh hơn thì giảm sau khi server ổn.
