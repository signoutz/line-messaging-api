<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// รับ raw body จาก LINE
$body = file_get_contents('php://input');
$data = json_decode($body, true);

// สร้างโฟลเดอร์ logs/ ถ้ายังไม่มี
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0750, true);
}

$date    = date('Y-m-d');
$logFile = LOG_DIR . '/webhook_' . $date . '.jsonl';

// แปลง event เป็น compact JSONL แยกตามวัน
foreach ($data['events'] ?? [] as $event) {
    $source  = $event['source']  ?? [];
    $message = $event['message'] ?? [];
    $type    = $event['type']    ?? 'unknown';

    $entry = [
        'ts'     => date('Y-m-d H:i:s'),
        'type'   => $type,
        'source' => $source['type'] ?? 'unknown',
    ];

    if (!empty($source['groupId']))  $entry['groupId'] = $source['groupId'];
    if (!empty($source['userId']))   $entry['userId']  = $source['userId'];

    if ($type === 'message') {
        $entry['msgType'] = $message['type'] ?? 'unknown';

        switch ($message['type'] ?? '') {
            case 'text':
                $entry['text'] = $message['text'] ?? '';
                break;
            case 'image':
                $localFile = downloadLineContent($message['id'] ?? '', $date);
                if ($localFile !== null) $entry['localFile'] = $localFile;
                break;
            case 'file':
                $entry['fileName'] = $message['fileName'] ?? '';
                $entry['fileSize'] = $message['fileSize'] ?? 0;
                break;
            case 'sticker':
                $entry['stickerId'] = $message['stickerId'] ?? '';
                $entry['packageId'] = $message['packageId'] ?? '';
                break;
        }
    }

    file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

    // บันทึก Group ID ลง DB + ไฟล์
    if (($source['type'] ?? '') === 'group' && !empty($source['groupId'])) {
        file_put_contents(__DIR__ . '/group_id.txt', $source['groupId']);

        try {
            $db = getDB();
            $groupName = '';
            // ถ้าเป็น join event → ดึงชื่อกลุ่มจาก LINE API
            if ($type === 'join') {
                $groupName = getLineGroupName($source['groupId']);
            }
            $stmt = $db->prepare('INSERT INTO line_groups (group_id, group_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE group_name = IF(? != "", ?, group_name)');
            $stmt->execute([$source['groupId'], $groupName, $groupName, $groupName]);
        } catch (Exception $e) {
            // ไม่ให้ DB error กระทบ webhook response
        }
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);

// ดึงชื่อกลุ่ม LINE จาก API
function getLineGroupName(string $groupId): string
{
    $ch = curl_init("https://api.line.me/v2/bot/group/{$groupId}/summary");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status === 200) {
        $data = json_decode($body, true);
        return $data['groupName'] ?? '';
    }
    return '';
}

// ดาวน์โหลดรูปภาพจาก LINE Content API แล้วบันทึกไว้ใน logs/images/
function downloadLineContent(string $messageId, string $date): ?string
{
    if ($messageId === '') return null;

    $dir = LOG_DIR . '/images/' . $date;
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    $dest = "{$dir}/{$messageId}.jpg";
    if (file_exists($dest)) {
        return "images/{$date}/{$messageId}.jpg"; // มีอยู่แล้ว
    }

    $ch = curl_init("https://api-data.line.me/v2/bot/message/{$messageId}/content");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN],
    ]);
    $content    = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($statusCode === 200 && $content) {
        file_put_contents($dest, $content);
        return "images/{$date}/{$messageId}.jpg";
    }

    return null;
}
