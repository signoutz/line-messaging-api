<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/line_push.php';

$apiUrl = 'https://www.cmuccdc.org/api/floodboy/realtime';

echo "[" . date('Y-m-d H:i:s') . "] cron_flood_summary started<br>";

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

// ดึงสถานีที่ enabled
$enabledStations = $db->query('SELECT * FROM station_config WHERE enabled = 1')->fetchAll();
$enabledMap = [];
foreach ($enabledStations as $s) {
    $enabledMap[$s['station_id']] = $s;
}

// รวบรวมข้อมูลสถานี
$summaryData = [];
foreach ($items as $item) {
    $type = $item['db_model_option']['type'] ?? '';
    if ($type !== 'waterway') {
        continue;
    }

    $stationId = (string)($item['id'] ?? '');
    if (!isset($enabledMap[$stationId])) {
        continue;
    }

    $config      = $enabledMap[$stationId];
    $stationName = $item['name'] ?? '';
    $waterLevel  = (float)($item['water_level'] ?? 0);
    $bankLevel   = (float)($item['db_model_option']['back'] ?? 0);

    if ($bankLevel <= 0) {
        continue;
    }

    $percent   = round(($waterLevel / $bankLevel) * 100, 1);
    $threshold = (float)$config['threshold'];

    if ($percent >= 100) {
        $severity = 'critical';
    } elseif ($percent > $threshold) {
        $severity = 'watch';
    } else {
        $severity = 'normal';
    }

    $summaryData[] = [
        'name'         => $stationName,
        'water_level'  => $waterLevel,
        'bank_level'   => $bankLevel,
        'percent'      => $percent,
        'severity'     => $severity,
        'log_datetime' => $item['log_datetime'] ?? '',
    ];
}

// เรียงตาม severity (critical > watch > normal) แล้วตาม percent มากไปน้อย
usort($summaryData, function ($a, $b) {
    $order = ['critical' => 0, 'watch' => 1, 'normal' => 2];
    $diff = ($order[$a['severity']] ?? 9) - ($order[$b['severity']] ?? 9);
    if ($diff !== 0) return $diff;
    return $b['percent'] <=> $a['percent'];
});

if (empty($summaryData)) {
    echo "No enabled stations with data<br>";
    exit(0);
}

echo "Stations: " . count($summaryData) . "<br>";

// ดึงกลุ่มที่จะส่งรายงานสรุป
$summaryGroups = $db->query('SELECT * FROM summary_config WHERE enabled = 1')->fetchAll();

if (empty($summaryGroups)) {
    echo "No summary groups configured — skipping<br>";
    exit(0);
}

$sentCount = 0;
foreach ($summaryGroups as $sg) {
    $result = linePushFloodSummary($summaryData, $sg['group_id']);
    $status = $result['success'] ? 'OK' : 'FAIL(' . $result['status'] . ')';
    echo "  SUMMARY -> group " . substr($sg['group_id'], 0, 8) . "... {$status}<br>";
    if (!$result['success']) {
        echo "    Error: " . htmlspecialchars($result['body']) . "<br>";
    }
    if ($result['success']) $sentCount++;
}

echo "---<br>";
echo "Summary sent to {$sentCount}/" . count($summaryGroups) . " groups<br>";
echo "[" . date('Y-m-d H:i:s') . "] cron_flood_summary finished<br>";
