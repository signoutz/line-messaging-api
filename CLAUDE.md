# CLAUDE.md — LINE Bot แจ้งเตือน (bots)

## ภาพรวม
PHP bot สำหรับส่งการแจ้งเตือนผ่าน LINE Messaging API
ใช้ใน context งานวิจัย/ติดตามสิ่งแวดล้อม (น้ำท่วม, PM2.5)

## โครงสร้างไฟล์

| ไฟล์ | หน้าที่ |
|------|---------|
| `webhook.php` | รับ event จาก LINE, log raw JSON, ดึง Group ID |
| `config.php` | constants: `LINE_CHANNEL_ACCESS_TOKEN`, `LINE_GROUP_ID`, `LINE_ฺID` |
| `database.php` | PDO connection + auto-create tables (MySQL) |
| `line_push.php` | ฟังก์ชัน `linePushMessage()`, `linePushFloodAlert()`, `linePushFloodSummary()` |
| `admin_flood.php` | หน้า Admin UI (card layout) จัดการสถานี, กลุ่ม LINE, ประวัติ, คู่มือ |
| `cron_flood.php` | Cron แจ้งเตือนตาม % (รันทุก 10 นาที) |
| `cron_flood_scheduled.php` | Cron แจ้งเตือนตามเวลาที่ตั้งไว้ (รันทุกชั่วโมง) |
| `cron_flood_summary.php` | Cron รายงานสรุปประจำวัน (08:00, 16:00) |
| `test_push.php` | สคริปต์ทดสอบรันจาก CLI |

## ระบบ Cron Jobs

| Cron | รอบ | หน้าที่ |
|------|-----|---------|
| `cron_flood.php` | `*/10 * * * *` | เช็ค % น้ำ → ถ้าเกิน threshold + cooldown ผ่าน → ส่งแจ้งเตือน |
| `cron_flood_scheduled.php` | `0 * * * *` | เช็คชั่วโมงปัจจุบัน → ถ้าตรงกับ scheduled_hours → ส่งข้อมูลสถานีนั้นทุกระดับ |
| `cron_flood_summary.php` | `0 8,16 * * *` | ส่งตารางสรุปทุกสถานีไปยังกลุ่มที่ตั้งค่าไว้ |

## Database Tables

| ตาราง | หน้าที่ |
|-------|---------|
| `station_config` | ข้อมูลสถานี (id, name, uri, enabled, group_ids JSON, threshold) |
| `alert_rules` | เงื่อนไขแจ้งเตือนแบบ dynamic หลายเงื่อนไขต่อสถานี (threshold %, interval นาที) |
| `scheduled_hours` | ชั่วโมงที่ตั้งแจ้งเตือนตามเวลา (station_id, hour 0-23) |
| `alert_log` | ประวัติการแจ้งเตือน (station_id, percent, alerted_at) |
| `line_groups` | กลุ่ม LINE ที่ Bot เข้าร่วม |
| `summary_config` | กลุ่มที่รับรายงานสรุป |

## ฟังก์ชันหลักใน line_push.php

### `linePushMessage(string $message, string $groupId)`
ส่งข้อความ text ธรรมดาไปยัง group

### `linePushFloodAlert(station, lat, lon, waterLevel, bankLevel, severity, groupId, uri, logDatetime)`
ส่ง Flex Message การ์ดแจ้งเตือนน้ำท่วม พร้อม progress bar และปุ่มดูแผนที่/ข้อมูล

ระดับ severity:
- `normal`   → สีเขียว 🟢
- `watch`    → สีเหลือง 🟡
- `critical` → สีแดง 🔴

### `linePushFloodSummary(array $stations, string $groupId)`
ส่งตารางสรุปทุกสถานี (แบ่ง bubble ละ 25 สถานี)

## Admin UI (admin_flood.php)

หน้า Admin แบ่ง 4 แท็บ:
- **จุดตรวจวัด** — แต่ละสถานีเป็น card layout แสดงข้อมูล live + ตั้งค่า 3 ส่วน (เงื่อนไข %, แจ้งเตือนตามเวลา, LINE Groups)
- **กลุ่ม LINE** — จัดการกลุ่มที่ Bot เข้าร่วม, scan จาก log, ดึงชื่อจาก API
- **ประวัติแจ้งเตือน** — แสดงรายการแจ้งเตือนล่าสุด 50 รายการ
- **คู่มือ** — เอกสารระบบ, flow diagram, วิธีตั้งค่า

## การตั้งค่า
- Webhook URL ใน LINE Developers Console ชี้ไปที่ `webhook.php`
- `config.php` ไม่รวมใน git (อยู่ใน .gitignore)
- ข้อมูล API มาจาก CMU CCDC: `https://www.cmuccdc.org/api/floodboy/realtime`

## ข้อควรระวัง
- `config.php` มี token จริง — ห้าม commit
- log ไม่มี rotation → ต้องจัดการเองหากใช้งานนาน

## Station Types จาก API

ปัจจุบันระบบรองรับเฉพาะ `type = "waterway"` (สถานีตรวจวัดระดับน้ำในลำน้ำ)
โดย filter จาก `$item['db_model_option']['type']` ใน cron_flood*.php และ admin_flood.php

API ยังมี type อื่นที่ยังไม่ได้ทำ:
- **`road`** — สถานีตรวจวัดระดับน้ำบนถนน (config/การแจ้งเตือนจะต่างจาก waterway เช่น ค่า threshold, severity, Flex Message format)

## TODO
- [ ] เพิ่มระบบแจ้งเตือนสำหรับ type `road` — ต้องออกแบบ config แยก เพราะเงื่อนไขและการแสดงผลต่างจาก waterway

## แนวทางต่อยอด
- เพิ่ม event handler ใน `webhook.php` (ยังว่างอยู่ ปัจจุบัน log อย่างเดียว)
- เชื่อม API ข้อมูลจริง (กรมชลประทาน, HII) แล้วรัน cron
- เพิ่ม `linePushFloodAlert` variants สำหรับ PM2.5 หรือ alert ประเภทอื่น
