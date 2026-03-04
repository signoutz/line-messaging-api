# CLAUDE.md — LINE Bot แจ้งเตือน (bots)

## ภาพรวม
PHP bot สำหรับส่งการแจ้งเตือนผ่าน LINE Messaging API
ใช้ใน context งานวิจัย/ติดตามสิ่งแวดล้อม (น้ำท่วม, PM2.5)

## โครงสร้างไฟล์

| ไฟล์ | หน้าที่ |
|------|---------|
| `webhook.php` | รับ event จาก LINE, log raw JSON, ดึง Group ID |
| `config.php` | constants: `LINE_CHANNEL_ACCESS_TOKEN`, `LINE_GROUP_ID`, `LINE_ฺID` |
| `line_push.php` | ฟังก์ชัน `linePushMessage()` และ `linePushFloodAlert()` |
| `test_push.php` | สคริปต์ทดสอบรันจาก CLI |
| `group_id.txt` | Group ID ที่ webhook จับได้ล่าสุด |
| `webhook_log.txt` | raw JSON log ของทุก event — **อย่า expose ทาง web** |

## ฟังก์ชันหลักใน line_push.php

### `linePushMessage(string $message, string $groupId)`
ส่งข้อความ text ธรรมดาไปยัง group

### `linePushFloodAlert(station, lat, lon, waterLevel, bankLevel, severity, groupId)`
ส่ง Flex Message การ์ดแจ้งเตือนน้ำท่วม

ระดับ severity:
- `normal`   → สีเขียว 🟢
- `watch`    → สีเหลือง 🟡
- `warning`  → สีส้ม 🟠
- `critical` → สีแดง 🔴

## การตั้งค่า
- Webhook URL ใน LINE Developers Console ชี้ไปที่ `webhook.php`
- `config.php` ไม่รวมใน git (อยู่ใน .gitignore)

## ข้อควรระวัง
- `webhook_log.txt` อยู่ใน public_html → ควรป้องกันด้วย `.htaccess` หรือย้ายออกนอก webroot
- `config.php` มี token จริง — ห้าม commit
- log ไม่มี rotation → ต้องจัดการเองหากใช้งานนาน

## แนวทางต่อยอด
- เพิ่ม event handler ใน `webhook.php` (ยังว่างอยู่ ปัจจุบัน log อย่างเดียว)
- เชื่อม API ข้อมูลจริง (กรมชลประทาน, HII) แล้วรัน cron
- เพิ่ม `linePushFloodAlert` variants สำหรับ PM2.5 หรือ alert ประเภทอื่น
