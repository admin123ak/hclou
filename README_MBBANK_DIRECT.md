# HCLOU MBBank Direct Service

Mục đích: thay API trung gian `queenvps.com/api/historymb/...` bằng API gốc MBBank, chạy nội bộ trên VPS.

## Kiến trúc

```text
cron-job.org / cron ngoài
  -> https://hclou.com/cron_run.php?token=...&job=mbbank
  -> /www/wwwroot/hclou.com/mbbank_poll.php
  -> http://127.0.0.1:3120/history
  -> MBBank web login + API lịch sử giao dịch gốc
```

Service local:

```text
/www/wwwroot/hclou.com/mbbank-direct-service
```

Systemd service:

```text
hclou-mbbank-direct.service
```

Endpoint nội bộ:

```text
http://127.0.0.1:3120/health
http://127.0.0.1:3120/history
```

Không expose service này ra public. Nginx đã chặn:

```text
/mbbank-direct-service/
```

## File quan trọng

```text
/www/wwwroot/hclou.com/config.php
/www/wwwroot/hclou.com/mbbank_poll.php
/www/wwwroot/hclou.com/cron_run.php
/www/wwwroot/hclou.com/mbbank-direct-service/server.mjs
/www/wwwroot/hclou.com/mbbank-direct-service/.env
/etc/systemd/system/hclou-mbbank-direct.service
```

Backups đã tạo trước khi đổi:

```text
/www/wwwroot/hclou.com/config.php.bk
/www/wwwroot/hclou.com/mbbank_poll.php.bk
/www/server/panel/vhost/nginx/hclou.com.conf.bk_mbbank_direct
```

## Cấu hình trong `.env`

File:

```text
/www/wwwroot/hclou.com/mbbank-direct-service/.env
```

Nội dung chính:

```env
PORT=3120
HOST=127.0.0.1
MBB_USER=<username đăng nhập MBBank>
MBB_PASS=<mật khẩu MBBank>
MBB_ACCOUNT_NUMBER=<số tài khoản nhận tiền>
```

Hiện `MBB_ACCOUNT_NUMBER` đang là STK nhận tiền của HCLOU.

## Web đang gọi API nào?

Trong `config.php`:

```php
define('MBBANK_HISTORY_API_URL', 'http://127.0.0.1:3120/history');
```

Nếu muốn rollback về API cũ, đổi lại dòng này về dạng cũ hoặc restore:

```bash
cp -a /www/wwwroot/hclou.com/config.php.bk /www/wwwroot/hclou.com/config.php
```

Sau đó test:

```bash
cd /www/wwwroot/hclou.com
php -l config.php
php mbbank_poll.php
```

## Lệnh vận hành

Kiểm tra service:

```bash
systemctl status hclou-mbbank-direct.service --no-pager -l
curl -sS http://127.0.0.1:3120/health
```

Restart service:

```bash
systemctl restart hclou-mbbank-direct.service
```

Xem log:

```bash
journalctl -u hclou-mbbank-direct.service -n 100 --no-pager
journalctl -u hclou-mbbank-direct.service -f
```

Test lấy lịch sử MBBank:

```bash
curl -sS -m 80 http://127.0.0.1:3120/history | python3 -m json.tool | head -80
```

Test auto-bank HCLOU:

```bash
cd /www/wwwroot/hclou.com
php mbbank_poll.php
```

Kết quả OK dạng:

```json
{"success":true,"seen_new":0,"matched":0,"approved":0}
```

## Cron ngoài

Cron MBBANK vẫn gọi URL cũ của web:

```text
https://hclou.com/cron_run.php?token=<CRON_RUN_TOKEN>&job=mbbank
```

Không gọi trực tiếp `127.0.0.1:3120` từ ngoài được và cũng không nên expose.

## Cách hoạt động

- Lần đầu service cần login MBBank bằng browser headless qua Puppeteer.
- Service giải CAPTCHA qua API trong `server.mjs`.
- Sau login thành công, service giữ `sessionId`/`deviceId` trong RAM.
- Khi session hết hạn, service login lại.
- `mbbank_poll.php` chỉ nhận danh sách giao dịch chuẩn hoá, rồi match mã `ORD...` như logic cũ.

## Khi chuyển qua cPanel/server mới

Checklist bắt buộc:

1. Copy toàn bộ code web HCLOU.
2. Cài Node.js 22+.
3. Cài thư viện chạy Chrome headless, tối thiểu các lib kiểu:
   - `libatk1.0-0t64`
   - `libatk-bridge2.0-0t64`
   - `libgbm1`
   - `libcairo2`
   - `libpango-1.0-0`
   - `libxcomposite1`
   - `libxdamage1`
   - `libxfixes3`
   - `libxrandr2`
   - `libatspi2.0-0t64`
4. Trong thư mục service chạy:

```bash
cd /www/wwwroot/hclou.com/mbbank-direct-service
npm install --omit=optional
```

5. Tạo lại systemd service hoặc dùng process manager của cPanel/PM2.
6. Đảm bảo service chỉ bind local:

```env
HOST=127.0.0.1
PORT=3120
```

7. Chặn public thư mục `mbbank-direct-service` trên web server.
8. Test:

```bash
curl http://127.0.0.1:3120/health
curl -m 80 http://127.0.0.1:3120/history
php /www/wwwroot/hclou.com/mbbank_poll.php
```

## Lỗi thường gặp

### `libatk-1.0.so.0 not found` hoặc Chrome không launch

Thiếu dependency Chrome headless. Cài các lib ở checklist chuyển server.

### `No doLogin response captured`

MBBank đổi UI/API, selector login/captcha có thể cần cập nhật trong `server.mjs`.

### `Captcha API did not return captcha`

API giải CAPTCHA ngoài lỗi. Cần đổi `CAPTCHA_API_URL` hoặc xử lý CAPTCHA khác.

### `session expired` / lỗi session

Service sẽ tự login lại. Nếu lặp liên tục, kiểm tra user/pass MBBank, CAPTCHA API, hoặc MBBank yêu cầu xác minh thêm.

## Bảo mật

- Không commit/publish `.env`.
- Không expose `/mbbank-direct-service` public.
- Không gửi credential MBBank ra ngoài.
- Nếu nghi lộ credential, đổi mật khẩu MBBank ngay.
