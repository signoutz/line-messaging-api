<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/line_push.php';

$apiUrl = 'https://www.cmuccdc.org/api/floodboy/realtime';

echo "[" . date('Y-m-d H:i:s') . "] cron_flood started<br>";

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

$db = getDB();
$alertCount = 0;
$skipCount = 0;
$normalCount = 0;

foreach ($items as $item) {
    $type = $item['db_model_option']['type'] ?? '';
    if ($type !== 'waterway') {
        continue;
    }

    $stationId   = (string)($item['id'] ?? '');
    $stationName = $item['name'] ?? '';
    $uri         = $item['uri'] ?? '';
    $logDatetime = $item['log_datetime'] ?? '';
    $lat         = (float)($item['latitude'] ?? 0);
    $lon         = (float)($item['longitude'] ?? 0);
    $waterLevel  = (float)($item['water_level'] ?? 0);
    $bankLevel   = (float)($item['db_model_option']['back'] ?? 0);

    if ($bankLevel <= 0) {
        echo "  SKIP [{$stationName}] bankLevel=0<br>";
        continue;
    }

    $percent = round(($waterLevel / $bankLevel) * 100, 1);

    // เช็ค/สร้าง station config
    $stmt = $db->prepare('SELECT * FROM station_config WHERE station_id = ?');
    $stmt->execute([$stationId]);
    $config = $stmt->fetch();

    if (!$config) {
        // Auto-insert สถานีใหม่
        $defaultGroups = json_encode([LINE_GROUP_ID]);
        $stmt = $db->prepare('INSERT INTO station_config (station_id, station_name, uri, enabled, group_ids, threshold) VALUES (?, ?, ?, 1, ?, 80)');
        $stmt->execute([$stationId, $stationName, $uri, $defaultGroups]);
        // สร้าง default alert rules
        $stmt = $db->prepare('INSERT IGNORE INTO alert_rules (station_id, threshold, alert_interval) VALUES (?, 80, 60)');
        $stmt->execute([$stationId]);
        $stmt = $db->prepare('INSERT IGNORE INTO alert_rules (station_id, threshold, alert_interval) VALUES (?, 100, 5)');
        $stmt->execute([$stationId]);
        $config = [
            'station_id' => $stationId,
            'enabled'    => 1,
            'group_ids'  => $defaultGroups,
            'threshold'  => 80,
        ];
        echo "  NEW station: {$stationName} (id={$stationId})<br>";
    }

    if (!$config['enabled']) {
        $skipCount++;
        echo "  DISABLED [{$stationName}] {$percent}%<br>";
        continue;
    }

    // ดึง alert rules เรียงจาก threshold สูง -> ต่ำ
    $stmt = $db->prepare('SELECT * FROM alert_rules WHERE station_id = ? ORDER BY threshold DESC');
    $stmt->execute([$stationId]);
    $rules = $stmt->fetchAll();

    // ถ้าไม่มี rules ให้สร้าง default
    if (empty($rules)) {
        $stmt = $db->prepare('INSERT IGNORE INTO alert_rules (station_id, threshold, alert_interval) VALUES (?, 80, 60)');
        $stmt->execute([$stationId]);
        $stmt = $db->prepare('INSERT IGNORE INTO alert_rules (station_id, threshold, alert_interval) VALUES (?, 100, 5)');
        $stmt->execute([$stationId]);
        $stmt = $db->prepare('SELECT * FROM alert_rules WHERE station_id = ? ORDER BY threshold DESC');
        $stmt->execute([$stationId]);
        $rules = $stmt->fetchAll();
    }

    // หา rule แรกที่ percent >= threshold (ใช้เงื่อนไขที่ strict ที่สุด)
    $matchedRule = null;
    foreach ($rules as $rule) {
        if ($percent >= $rule['threshold']) {
            $matchedRule = $rule;
            break;
        }
    }

    // กำหนด severity
    if ($percent >= 100) {
        $severity = 'critical';
    } elseif ($matchedRule) {
        $severity = 'watch';
    } else {
        $severity = 'normal';
        $normalCount++;
        echo "  OK [{$stationName}] {$percent}%<br>";
        continue; // ไม่แจ้งเตือน
    }

    // ใช้ interval จาก rule ที่ match หรือ default
    $interval = $matchedRule ? (int)$matchedRule['alert_interval'] : 60;
    $stmt = $db->prepare('SELECT COUNT(*) FROM alert_log WHERE station_id = ? AND alerted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)');
    $stmt->execute([$stationId, $interval]);
    $recentAlerts = (int)$stmt->fetchColumn();

    if ($recentAlerts > 0) {
        $skipCount++;
        echo "  ALREADY ALERTED [{$stationName}] {$percent}% ({$severity})<br>";
        continue;
    }

    // ส่งแจ้งเตือนไปทุก group
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
        echo "  ALERT [{$stationName}] {$percent}% ({$severity}) -> group " . substr($groupId, 0, 8) . "... {$status}<br>";
    }

    // บันทึก alert_log
    $stmt = $db->prepare('INSERT INTO alert_log (station_id, percent, alerted_at) VALUES (?, ?, NOW())');
    $stmt->execute([$stationId, $percent]);
    $alertCount++;
}

echo "---<br>";
echo "Summary: alerts={$alertCount}, skipped={$skipCount}, normal={$normalCount}<br>";
echo "[" . date('Y-m-d H:i:s') . "] cron_flood finished<br>";
