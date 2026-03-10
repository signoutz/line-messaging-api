<?php

/**
 * cron_flood_scheduled.php — แจ้งเตือนตามเวลาที่กำหนด (ไม่เช็ค %)
 * รันทุกชั่วโมง: 0 * * * * php /path/to/cron_flood_scheduled.php
 *
 * ดึงข้อมูลจาก API แล้วส่งแจ้งเตือนเฉพาะสถานีที่ตั้งเวลาตรงกับชั่วโมงปัจจุบัน
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/line_push.php';

$currentHour = (int)date('G'); // 0-23
$apiUrl = 'https://www.cmuccdc.org/api/floodboy/realtime';

echo "[" . date('Y-m-d H:i:s') . "] cron_flood_scheduled started (hour={$currentHour})<br>";

// ดึงสถานีที่ตั้งเวลาแจ้งเตือนในชั่วโมงนี้
$db = getDB();
$stmt = $db->prepare('SELECT sh.station_id FROM scheduled_hours sh
    INNER JOIN station_config sc ON sh.station_id = sc.station_id
    WHERE sh.hour = ? AND sc.enabled = 1');
$stmt->execute([$currentHour]);
$scheduledStations = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($scheduledStations)) {
    echo "No stations scheduled for hour {$currentHour}<br>";
    echo "[" . date('Y-m-d H:i:s') . "] cron_flood_scheduled finished<br>";
    exit(0);
}

echo "Stations scheduled: " . count($scheduledStations) . "<br>";

// ดึงข้อมูลจาก API
$ctx = stream_context_create(['http' => ['timeout' => 30]]);
$json = @file_get_contents($apiUrl, false, $ctx);
if ($json === false) {
    echo "ERROR: Cannot fetch API<br>";
    exit(1);
}

$items = json_decode($json, true);
if (!is_array($items)) {
    echo "ERROR: Invalid JSON response<br>";
    exit(1);
}

// สร้าง map ข้อมูล API โดย station id
$apiMap = [];
foreach ($items as $item) {
    $type = $item['db_model_option']['type'] ?? '';
    if ($type !== 'waterway') continue;
    $sid = (string)($item['id'] ?? '');
    $apiMap[$sid] = $item;
}

$sentCount = 0;
$skipCount = 0;

foreach ($scheduledStations as $stationId) {
    // ดึง config สถานี
    $stmt = $db->prepare('SELECT * FROM station_config WHERE station_id = ?');
    $stmt->execute([$stationId]);
    $config = $stmt->fetch();

    if (!$config) {
        echo "  SKIP [{$stationId}] no config<br>";
        $skipCount++;
        continue;
    }

    // ดึงข้อมูล API ของสถานี
    $item = $apiMap[$stationId] ?? null;
    if (!$item) {
        echo "  SKIP [{$config['station_name']}] no API data<br>";
        $skipCount++;
        continue;
    }

    $stationName = $item['name'] ?? $config['station_name'];
    $uri         = $item['uri'] ?? $config['uri'] ?? '';
    $logDatetime = $item['log_datetime'] ?? '';
    $lat         = (float)($item['latitude'] ?? 0);
    $lon         = (float)($item['longitude'] ?? 0);
    $waterLevel  = (float)($item['water_level'] ?? 0);
    $bankLevel   = (float)($item['db_model_option']['back'] ?? 0);

    if ($bankLevel <= 0) {
        echo "  SKIP [{$stationName}] bankLevel=0<br>";
        $skipCount++;
        continue;
    }

    $percent = round(($waterLevel / $bankLevel) * 100, 1);

    // กำหนด severity ตาม % (แจ้งทุกระดับ ไม่ว่า % จะเท่าไหร่)
    if ($percent >= 100) {
        $severity = 'critical';
    } elseif ($percent >= ($config['threshold'] ?? 80)) {
        $severity = 'watch';
    } else {
        $severity = 'normal';
    }

    // ส่งแจ้งเตือนไปทุก group ของสถานีนี้
    $groupIds = json_decode($config['group_ids'], true) ?: [LINE_GROUP_ID];

    foreach ($groupIds as $groupId) {
        $result = linePushFloodAlert(
            $stationName,
            $lat,
            $lon,
            $waterLevel,
            $bankLevel,
            $severity,
            $groupId,
            $uri,
            $logDatetime
        );

        $status = $result['success'] ? 'OK' : 'FAIL(' . $result['status'] . ')';
        echo "  SENT [{$stationName}] {$percent}% ({$severity}) -> group " . substr($groupId, 0, 8) . "... {$status}<br>";
    }

    $sentCount++;
}

echo "---<br>";
echo "Summary: sent={$sentCount}, skipped={$skipCount}<br>";
echo "[" . date('Y-m-d H:i:s') . "] cron_flood_scheduled finished<br>";
