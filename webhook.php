<?php

require_once __DIR__ . '/config.php';

// รับ raw body จาก LINE
$body = file_get_contents('php://input');
$data = json_decode($body, true);

// Log ไว้ดู Group ID
file_put_contents(__DIR__ . '/webhook_log.txt', date('Y-m-d H:i:s') . "\n" . $body . "\n\n", FILE_APPEND);

// ดึง Group ID จาก event แรก
foreach ($data['events'] ?? [] as $event) {
    $source = $event['source'] ?? [];

    if (($source['type'] ?? '') === 'group') {
        $groupId = $source['groupId'];

        // บันทึก Group ID ลงไฟล์
        file_put_contents(__DIR__ . '/group_id.txt', $groupId);
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
