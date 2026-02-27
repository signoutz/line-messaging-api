<?php

require_once __DIR__ . '/config.php';

/**
 * ส่งข้อความ text ไปยัง LINE group
 */
function linePushMessage(string $message, string $groupId = LINE_GROUP_ID): array
{
    $payload = json_encode([
        'to' => $groupId,
        'messages' => [
            ['type' => 'text', 'text' => $message]
        ]
    ]);

    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN,
        ],
        CURLOPT_POSTFIELDS     => $payload,
    ]);

    $body       = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'success' => $statusCode === 200,
        'status'  => $statusCode,
        'body'    => $body,
    ];
}

/**
 * ส่ง Flex Message การ์ดแจ้งเตือนน้ำท่วม
 *
 * @param string $station     ชื่อสถานี
 * @param float  $lat         ละติจูด
 * @param float  $lon         ลองจิจูด
 * @param float  $waterLevel  ความสูงของน้ำ (เมตร)
 * @param float  $bankLevel   ความสูงขอบตลิ่ง (เมตร)
 * @param string $severity    ระดับความรุนแรง: normal | watch | warning | critical
 * @param string $groupId     Group ID
 */
function linePushFloodAlert(
    string $station,
    float  $lat,
    float  $lon,
    float  $waterLevel,
    float  $bankLevel,
    string $severity  = 'warning',
    string $groupId   = LINE_GROUP_ID
): array {
    $severityConfig = [
        'normal'   => ['color' => '#27ACB2', 'label' => 'ปกติ',      'icon' => '🟢'],
        'watch'    => ['color' => '#F0C040', 'label' => 'เฝ้าระวัง', 'icon' => '🟡'],
        'warning'  => ['color' => '#FF7D00', 'label' => 'เตือนภัย',  'icon' => '🟠'],
        'critical' => ['color' => '#D9534F', 'label' => 'วิกฤต',     'icon' => '🔴'],
    ];

    $cfg     = $severityConfig[$severity] ?? $severityConfig['warning'];
    $now     = date('d/m/Y H:i');
    $percent = $bankLevel > 0 ? min(round(($waterLevel / $bankLevel) * 100, 1), 100) : 0;
    $mapsUrl = "https://www.google.com/maps?q={$lat},{$lon}";

    $row = fn(string $label, string $value) => [
        'type'    => 'box',
        'layout'  => 'horizontal',
        'contents' => [
            ['type' => 'text', 'text' => $label, 'color' => '#888888', 'flex' => 4, 'size' => 'sm'],
            ['type' => 'text', 'text' => $value,  'color' => '#1a1a1a', 'flex' => 5, 'size' => 'sm', 'weight' => 'bold'],
        ],
    ];

    $flex = [
        'type'   => 'bubble',
        'size'   => 'kilo',
        'styles' => [
            'header' => ['backgroundColor' => $cfg['color']],
        ],
        'header' => [
            'type'     => 'box',
            'layout'   => 'horizontal',
            'contents' => [
                [
                    'type'    => 'text',
                    'text'    => '🌊 แจ้งเตือนน้ำท่วม',
                    'color'   => '#ffffff',
                    'size'    => 'md',
                    'weight'  => 'bold',
                    'flex'    => 1,
                ],
                [
                    'type'   => 'text',
                    'text'   => $cfg['icon'] . ' ' . $cfg['label'],
                    'color'  => '#ffffff',
                    'size'   => 'md',
                    'weight' => 'bold',
                    'align'  => 'end',
                ],
            ],
        ],
        'body' => [
            'type'      => 'box',
            'layout'    => 'vertical',
            'spacing'   => 'sm',
            'paddingAll' => '16px',
            'contents'  => [
                [
                    'type'   => 'text',
                    'text'   => $station,
                    'size'   => 'lg',
                    'weight' => 'bold',
                    'color'  => '#1a1a1a',
                    'wrap'   => true,
                ],
                ['type' => 'separator', 'color' => '#eeeeee', 'margin' => 'sm'],
                $row('📍 พิกัด',         "{$lat}, {$lon}"),
                $row('💧 ระดับน้ำ',      number_format($waterLevel, 2) . ' ม.'),
                $row('🏔️ ขอบตลิ่ง',     number_format($bankLevel, 2) . ' ม.'),
                $row('📊 เต็ม',          $percent . '%'),
                ['type' => 'separator', 'color' => '#eeeeee', 'margin' => 'sm'],
                [
                    'type'  => 'text',
                    'text'  => '🕐 ' . $now,
                    'color' => '#aaaaaa',
                    'size'  => 'xs',
                ],
            ],
        ],
        'footer' => [
            'type'     => 'box',
            'layout'   => 'vertical',
            'contents' => [
                [
                    'type'   => 'button',
                    'style'  => 'link',
                    'height' => 'sm',
                    'action' => [
                        'type'  => 'uri',
                        'label' => 'ดูตำแหน่งบนแผนที่',
                        'uri'   => $mapsUrl,
                    ],
                ],
            ],
        ],
    ];

    $payload = json_encode([
        'to' => $groupId,
        'messages' => [
            [
                'type'     => 'flex',
                'altText'  => "แจ้งเตือนน้ำท่วม: {$station} {$percent}% ({$cfg['label']})",
                'contents' => $flex,
            ]
        ]
    ]);

    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN,
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $body       = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'success' => $statusCode === 200,
        'status'  => $statusCode,
        'body'    => $body,
    ];
}
