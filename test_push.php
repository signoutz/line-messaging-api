<?php

require_once __DIR__ . '/line_push.php';

echo '=== ทดสอบส่งการแจ้งเตือนน้ำท่วม ===' . PHP_EOL;

$result = linePushFloodAlert(
    station:    'แม่น้ำปิง สถานี P.1',
    lat:        18.7883,
    lon:        98.9853,
    waterLevel: 3.52,
    bankLevel:  4.00,
    severity:   'critical'
);

if ($result['success']) {
    echo "ส่งสำเร็จ!\n";
} else {
    echo "Error {$result['status']}: {$result['body']}\n";
}