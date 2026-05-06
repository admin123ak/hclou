HCLOU SERVER - QUICK README
===========================

Web bán key chạy trong Telegram Mini App.
Admin panel: https://hclou.com/admin/
Tab hướng dẫn cấu hình đầy đủ trong admin: Setup/API

FILE CHÍNH
----------
- config.php: cấu hình DB, Telegram bot, bank/VietQR, Link4M, YeuMoney, MBBANK direct URL.
- index.php: giao diện Telegram Mini App.
- api/index.php: API backend cho app.
- admin/index.php: admin panel + tab Setup/API.
- webhook.php: Telegram bot webhook.
- mbbank_poll.php: auto-bank MBBANK polling API; hiện lấy lịch sử từ service local mbbank-direct-service.
- maintenance.php: expire key, xoá key hết hạn quá 3 ngày, huỷ pending order quá 15 phút.
- automation_daily.php: nhắc thanh toán gần hết hạn, báo đơn huỷ, cảnh báo bank ignored/error, báo cáo ngày.
- cron_run.php: endpoint bảo vệ token để cron-job.org gọi các job mbbank/maintenance/automation/backup từ bên ngoài.
- claim.php: claim GetKey Free.
- setup_webhook.php: set/check/delete Telegram webhook, có token bảo vệ.
- database.sql: schema import khi cài mới.

FLOW HIỆN TẠI
-------------
Paid key:
1. User mở Mini App từ Telegram bot.
2. Chọn game/gói và xác nhận mua.
3. Hệ thống tạo order + key pending.
4. Popup hiển thị VietQR đúng số tiền + mã ORD.
5. Cron MBBANK gọi cron_run.php mỗi phút; mbbank_poll.php lấy lịch sử từ API gốc MBBank qua service local 127.0.0.1:3120.
6. Nếu đủ tiền + đúng mã đơn: tự active key, gửi bot, app tự hiện key.

Free key:
1. User chọn Get Key Free.
2. Đi qua Link4M -> YeuMoney -> HCLOU claim.
3. App gọi claim_free_key và active key free.

CRON ĐANG DÙNG
--------------
Hiện ưu tiên chạy bằng cron-job.org bên ngoài để giảm tải cron nội bộ VPS.
Các URL đều đi qua cron_run.php và cần CRON_RUN_TOKEN trong file này.

HCLOU MBBANK:
https://hclou.com/cron_run.php?token=<CRON_RUN_TOKEN>&job=mbbank
- Script thật: /usr/bin/php /www/wwwroot/hclou.com/mbbank_poll.php
- Nhiệm vụ: kiểm tra lịch sử MBBANK, tự approve order pending nếu đúng mã ORD + đủ tiền.

HCLOU Maintenance:
https://hclou.com/cron_run.php?token=<CRON_RUN_TOKEN>&job=maintenance
- Script thật: /usr/bin/php /www/wwwroot/hclou.com/maintenance.php
- Nhiệm vụ: expire key, xoá key expired quá 3 ngày, huỷ đơn pending quá 15 phút và lock key pending.

HCLOU Automation:
https://hclou.com/cron_run.php?token=<CRON_RUN_TOKEN>&job=automation
- Script thật: /usr/bin/php /www/wwwroot/hclou.com/automation_daily.php
- Nhiệm vụ: nhắc user đơn còn <=5 phút, báo đơn tự huỷ, cảnh báo admin giao dịch bank ignored/error, gửi báo cáo ngày trong khung 23:55-23:59.

Backup DB nếu dùng cron ngoài:
https://hclou.com/cron_run.php?token=<CRON_RUN_TOKEN>&job=backup
- Script thật: /www/backup/hclou_db/backup.sh

Cron VPS tương đương nếu cần chạy nội bộ:
* * * * * /usr/bin/php /www/wwwroot/hclou.com/mbbank_poll.php >/dev/null 2>&1
*/5 * * * * /usr/bin/php /www/wwwroot/hclou.com/maintenance.php >/dev/null 2>&1
17 3 * * * /www/backup/hclou_db/backup.sh >/dev/null 2>&1

VERIFY NHANH
------------
cd /www/wwwroot/hclou.com
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
curl -I https://hclou.com/
curl 'https://hclou.com/api/?action=games'
php mbbank_poll.php
php maintenance.php
curl 'https://hclou.com/cron_run.php?token=<CRON_RUN_TOKEN>&job=mbbank'

GHI CHÚ BẢO MẬT
---------------
- Không public BOT_TOKEN, MBBANK credential trong mbbank-direct-service/.env, Link4M/YeuMoney token.
- Không bật lại duyệt tay paid order nếu không thật sự cần.
- Paid order chuẩn là auto duyệt bằng MBBANK Direct local service.
- Không xoá /www/backup nếu chưa chắc.

Muốn xem hướng dẫn setup/API chi tiết: đăng nhập admin -> tab Setup/API.


## MBBANK Direct Service

Hệ thống hiện dùng API gốc MBBank qua service nội bộ thay cho queenvps. Xem đầy đủ: README_MBBANK_DIRECT.md

Lệnh nhanh:

```bash
systemctl status hclou-mbbank-direct.service --no-pager -l
curl http://127.0.0.1:3120/health
php /www/wwwroot/hclou.com/mbbank_poll.php
```

Khi chuyển server/cPanel, phải copy thư mục `mbbank-direct-service`, cài Node.js + Chrome headless dependencies, chạy `npm install --omit=optional`, tạo process/systemd/PM2 và chặn public thư mục này.
