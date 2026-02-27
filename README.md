# LINE Messaging API Bot

PHP bot สำหรับส่งข้อความและแจ้งเตือนน้ำท่วมผ่าน LINE Messaging API

## ไฟล์หลัก

| ไฟล์ | หน้าที่ |
|------|---------|
| `webhook.php` | รับ event จาก LINE และดึง Group ID |
| `line_push.php` | ฟังก์ชัน push ข้อความ text และ Flex Message แจ้งเตือนน้ำท่วม |
| `test_push.php` | ทดสอบส่งข้อความ |
| `config.php` | ค่า config (ไม่รวมใน repo) |

## การตั้งค่า

1. คัดลอก `config.php` และใส่ค่า:

```php
define('LINE_CHANNEL_ACCESS_TOKEN', 'your_token');
define('LINE_GROUP_ID', 'your_group_id');
```

2. ตั้ง Webhook URL ใน LINE Developers Console ชี้ไปที่ `webhook.php`

## ระดับความรุนแรง (Flood Alert)

| ระดับ | สี |
|-------|----|
| `normal` | เขียว |
| `watch` | เหลือง |
| `warning` | ส้ม |
| `critical` | แดง |
