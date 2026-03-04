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
 * ส่ง Flex Message การ์ดแจ้งเตือนน้ำท่วม (Mega bubble + Progress Bar)
 *
 * @param string $station     ชื่อสถานี
 * @param float  $lat         ละติจูด
 * @param float  $lon         ลองจิจูด
 * @param float  $waterLevel  ความสูงของน้ำ (เมตร)
 * @param float  $bankLevel   ความสูงขอบตลิ่ง (เมตร)
 * @param string $severity    ระดับความรุนแรง: normal | watch | critical
 * @param string $groupId     Group ID
 * @param string $uri         URI สำหรับลิงก์ CMUCCDC (e.g. "fb001")
 */
function linePushFloodAlert(
    string $station,
    float  $lat,
    float  $lon,
    float  $waterLevel,
    float  $bankLevel,
    string $severity  = 'watch',
    string $groupId   = LINE_GROUP_ID,
    string $uri       = ''
): array {
    $severityConfig = [
        'normal'   => [
            'color'    => '#1a7f37',
            'bgColor'  => '#dafbe1',
            'barColor' => '#1a7f37',
            'label'    => 'ปกติ',
            'icon'     => '🟢',
        ],
        'watch'    => [
            'color'    => '#9a6700',
            'bgColor'  => '#fff8c5',
            'barColor' => '#bf8700',
            'label'    => 'เฝ้าระวัง',
            'icon'     => '🟡',
        ],
        'critical' => [
            'color'    => '#cf222e',
            'bgColor'  => '#ffebe9',
            'barColor' => '#cf222e',
            'label'    => 'วิกฤต',
            'icon'     => '🔴',
        ],
    ];

    $cfg     = $severityConfig[$severity] ?? $severityConfig['watch'];
    $now     = date('d/m/Y H:i');
    $percent = $bankLevel > 0 ? round(($waterLevel / $bankLevel) * 100, 1) : 0;
    $percentCapped = min($percent, 100);
    $mapsUrl = "https://www.google.com/maps?q={$lat},{$lon}";

    // Progress bar: filled portion (max 100%)
    $barWidth = max(2, (int)$percentCapped);

    $flex = [
        'type'   => 'bubble',
        'size'   => 'mega',
        'header' => [
            'type'       => 'box',
            'layout'     => 'vertical',
            'paddingAll' => '20px',
            'background' => [
                'type'       => 'linearGradient',
                'angle'      => '135deg',
                'startColor' => $cfg['color'],
                'endColor'   => $cfg['color'] . 'cc',
            ],
            'contents' => [
                [
                    'type'     => 'box',
                    'layout'   => 'horizontal',
                    'contents' => [
                        [
                            'type'   => 'text',
                            'text'   => '🌊 แจ้งเตือนระดับน้ำ',
                            'color'  => '#ffffff',
                            'size'   => 'lg',
                            'weight' => 'bold',
                            'flex'   => 1,
                        ],
                    ],
                ],
                [
                    'type'     => 'box',
                    'layout'   => 'horizontal',
                    'margin'   => 'md',
                    'contents' => [
                        [
                            'type'            => 'text',
                            'text'            => $cfg['icon'] . '  ' . $cfg['label'],
                            'color'           => '#ffffff',
                            'size'            => 'xxl',
                            'weight'          => 'bold',
                        ],
                    ],
                ],
            ],
        ],
        'body' => [
            'type'       => 'box',
            'layout'     => 'vertical',
            'spacing'    => 'md',
            'paddingAll' => '20px',
            'contents'   => [
                // Station name
                [
                    'type'   => 'text',
                    'text'   => $station,
                    'size'   => 'xl',
                    'weight' => 'bold',
                    'color'  => '#1f2328',
                    'wrap'   => true,
                ],
                ['type' => 'separator', 'color' => '#e5e7eb'],
                // Data rows
                [
                    'type'     => 'box',
                    'layout'   => 'vertical',
                    'spacing'  => 'sm',
                    'margin'   => 'md',
                    'contents' => [
                        [
                            'type'     => 'box',
                            'layout'   => 'horizontal',
                            'contents' => [
                                ['type' => 'text', 'text' => '💧 ระดับน้ำ', 'color' => '#656d76', 'size' => 'md', 'flex' => 5],
                                ['type' => 'text', 'text' => number_format($waterLevel, 2) . ' ม.', 'color' => '#1f2328', 'size' => 'md', 'weight' => 'bold', 'align' => 'end', 'flex' => 4],
                            ],
                        ],
                        [
                            'type'     => 'box',
                            'layout'   => 'horizontal',
                            'contents' => [
                                ['type' => 'text', 'text' => '🏔️ ขอบตลิ่ง', 'color' => '#656d76', 'size' => 'md', 'flex' => 5],
                                ['type' => 'text', 'text' => number_format($bankLevel, 2) . ' ม.', 'color' => '#1f2328', 'size' => 'md', 'weight' => 'bold', 'align' => 'end', 'flex' => 4],
                            ],
                        ],
                        [
                            'type'     => 'box',
                            'layout'   => 'horizontal',
                            'contents' => [
                                ['type' => 'text', 'text' => '📊 ระดับ', 'color' => '#656d76', 'size' => 'md', 'flex' => 5],
                                ['type' => 'text', 'text' => $percent . '%', 'color' => $cfg['color'], 'size' => 'lg', 'weight' => 'bold', 'align' => 'end', 'flex' => 4],
                            ],
                        ],
                    ],
                ],
                // Progress bar
                [
                    'type'       => 'box',
                    'layout'     => 'vertical',
                    'margin'     => 'lg',
                    'contents'   => [
                        // Bar background
                        [
                            'type'            => 'box',
                            'layout'          => 'vertical',
                            'contents'        => [
                                // Bar fill
                                [
                                    'type'            => 'box',
                                    'layout'          => 'vertical',
                                    'contents'        => [['type' => 'filler']],
                                    'backgroundColor' => $cfg['barColor'],
                                    'height'          => '12px',
                                    'width'           => $barWidth . '%',
                                    'cornerRadius'    => '6px',
                                ],
                            ],
                            'backgroundColor' => '#e5e7eb',
                            'height'          => '12px',
                            'cornerRadius'    => '6px',
                        ],
                        // Bar label
                        [
                            'type'     => 'box',
                            'layout'   => 'horizontal',
                            'margin'   => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '0%', 'size' => 'xxs', 'color' => '#b0b8c1', 'flex' => 1],
                                ['type' => 'text', 'text' => $percent . '%', 'size' => 'sm', 'color' => $cfg['color'], 'weight' => 'bold', 'align' => 'center', 'flex' => 2],
                                ['type' => 'text', 'text' => '100%', 'size' => 'xxs', 'color' => '#b0b8c1', 'align' => 'end', 'flex' => 1],
                            ],
                        ],
                    ],
                ],
                ['type' => 'separator', 'color' => '#e5e7eb'],
                // Timestamp
                [
                    'type'   => 'text',
                    'text'   => '🕐 ' . $now,
                    'color'  => '#b0b8c1',
                    'size'   => 'sm',
                    'margin' => 'sm',
                ],
            ],
        ],
        'footer' => [
            'type'       => 'box',
            'layout'     => 'horizontal',
            'spacing'    => 'md',
            'paddingAll' => '16px',
            'contents'   => [
                [
                    'type'    => 'button',
                    'style'   => 'secondary',
                    'height'  => 'sm',
                    'action'  => [
                        'type'  => 'uri',
                        'label' => '📍 ดูแผนที่',
                        'uri'   => $mapsUrl,
                    ],
                    'flex' => 1,
                ],
                [
                    'type'    => 'button',
                    'style'   => 'primary',
                    'color'   => $cfg['color'],
                    'height'  => 'sm',
                    'action'  => [
                        'type'  => 'uri',
                        'label' => '🔗 ดูข้อมูล',
                        'uri'   => $uri ? "https://www.cmuccdc.org/floodboy/{$uri}" : $mapsUrl,
                    ],
                    'flex' => 1,
                ],
            ],
        ],
    ];

    $payload = json_encode([
        'to' => $groupId,
        'messages' => [
            [
                'type'     => 'flex',
                'altText'  => "🌊 แจ้งเตือนระดับน้ำ: {$station} {$percent}% ({$cfg['label']})",
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
