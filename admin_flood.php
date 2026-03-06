<?php

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(60);

require_once __DIR__ . '/database.php';

session_start();

// --- Auth ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === LOG_VIEWER_PASSWORD) {
        $_SESSION['log_auth'] = true;
    } else {
        $loginError = 'รหัสผ่านไม่ถูกต้อง';
    }
}
if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (empty($_SESSION['log_auth'])) {
    loginPage($loginError ?? null);
    exit;
}

try {
    $db = getDB();
} catch (Exception $e) {
    die('<pre>Database error: ' . htmlspecialchars($e->getMessage()) . '</pre>');
}
$message = '';

// --- Current tab ---
$validTabs = ['stations', 'groups', 'history', 'guide'];
$currentTab = $_GET['tab'] ?? 'stations';
if (!in_array($currentTab, $validTabs)) {
    $currentTab = 'stations';
}

// --- Alert Rules Helper ---
function getAlertRules(PDO $db, string $stationId): array
{
    $stmt = $db->prepare('SELECT * FROM alert_rules WHERE station_id = ? ORDER BY threshold DESC');
    $stmt->execute([$stationId]);
    return $stmt->fetchAll();
}

function createDefaultRules(PDO $db, string $stationId): array
{
    $stmt = $db->prepare('INSERT IGNORE INTO alert_rules (station_id, threshold, alert_interval) VALUES (?, 80, 60)');
    $stmt->execute([$stationId]);
    $stmt = $db->prepare('INSERT IGNORE INTO alert_rules (station_id, threshold, alert_interval) VALUES (?, 100, 5)');
    $stmt->execute([$stationId]);
    return [
        ['threshold' => 80, 'alert_interval' => 60],
        ['threshold' => 100, 'alert_interval' => 5],
    ];
}

// --- Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['password']) && !isset($_POST['logout'])) {
    $action = $_POST['action'] ?? '';

    // Determine which tab to redirect back to
    $redirectTab = $currentTab;
    if (in_array($action, ['sync', 'toggle', 'threshold', 'interval', 'add_group', 'remove_group', 'toggle_summary', 'add_summary_group', 'add_rule', 'edit_rule', 'delete_rule'])) {
        $redirectTab = 'stations';
    } elseif (in_array($action, ['rename_group', 'refresh_groups', 'scan_logs'])) {
        $redirectTab = 'groups';
    }

    if ($action === 'sync') {
        $message = syncStations($db);
    } elseif ($action === 'toggle') {
        $stationId = $_POST['station_id'] ?? '';
        $enabled = (int)($_POST['enabled'] ?? 0);
        $stmt = $db->prepare('UPDATE station_config SET enabled = ?, updated_at = NOW() WHERE station_id = ?');
        $stmt->execute([$enabled, $stationId]);
        $message = 'อัปเดตสถานะเรียบร้อย';
    } elseif ($action === 'threshold') {
        $stationId = $_POST['station_id'] ?? '';
        $threshold = max(1, min(100, (float)($_POST['threshold'] ?? 80)));
        $stmt = $db->prepare('UPDATE station_config SET threshold = ?, updated_at = NOW() WHERE station_id = ?');
        $stmt->execute([$threshold, $stationId]);
        $message = 'อัปเดต threshold เรียบร้อย';
    } elseif ($action === 'interval') {
        $stationId = $_POST['station_id'] ?? '';
        $interval = max(10, (int)($_POST['alert_interval'] ?? 60));
        $stmt = $db->prepare('UPDATE station_config SET alert_interval = ?, updated_at = NOW() WHERE station_id = ?');
        $stmt->execute([$interval, $stationId]);
        $message = 'อัปเดตความถี่แจ้งเตือนเรียบร้อย';
    } elseif ($action === 'add_rule') {
        $stationId = $_POST['station_id'] ?? '';
        $threshold = max(1, min(100, (float)($_POST['threshold'] ?? 50)));
        $interval = max(5, (int)($_POST['alert_interval'] ?? 30));
        $stmt = $db->prepare('INSERT INTO alert_rules (station_id, threshold, alert_interval) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE alert_interval = VALUES(alert_interval)');
        $stmt->execute([$stationId, $threshold, $interval]);
        $message = 'เพิ่มเงื่อนไขแจ้งเตือนเรียบร้อย';
    } elseif ($action === 'edit_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $threshold = max(1, min(100, (float)($_POST['threshold'] ?? 50)));
        $interval = max(5, (int)($_POST['alert_interval'] ?? 30));
        $stmt = $db->prepare('UPDATE alert_rules SET threshold = ?, alert_interval = ? WHERE id = ?');
        $stmt->execute([$threshold, $interval, $ruleId]);
        $message = 'อัปเดตเงื่อนไขแจ้งเตือนเรียบร้อย';
    } elseif ($action === 'delete_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $stmt = $db->prepare('DELETE FROM alert_rules WHERE id = ?');
        $stmt->execute([$ruleId]);
        $message = 'ลบเงื่อนไขแจ้งเตือนเรียบร้อย';
    } elseif ($action === 'toggle_summary') {
        $groupId = trim($_POST['group_id'] ?? '');
        $enabled = (int)($_POST['enabled'] ?? 0);
        if ($groupId !== '') {
            $stmt = $db->prepare('INSERT INTO summary_config (group_id, enabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)');
            $stmt->execute([$groupId, $enabled]);
            $message = $enabled ? 'เปิดรายงานสรุปสำหรับกลุ่มนี้' : 'ปิดรายงานสรุปสำหรับกลุ่มนี้';
        }
    } elseif ($action === 'add_summary_group') {
        $groupId = trim($_POST['group_id'] ?? '');
        if ($groupId !== '') {
            $stmt = $db->prepare('INSERT INTO summary_config (group_id, enabled) VALUES (?, 1) ON DUPLICATE KEY UPDATE enabled = 1');
            $stmt->execute([$groupId]);
            $message = 'เพิ่มกลุ่มรับรายงานสรุปเรียบร้อย';
        }
    } elseif ($action === 'add_group') {
        $stationId = $_POST['station_id'] ?? '';
        $newGroup = trim($_POST['new_group'] ?? '');
        if ($newGroup !== '') {
            $stmt = $db->prepare('SELECT group_ids FROM station_config WHERE station_id = ?');
            $stmt->execute([$stationId]);
            $row = $stmt->fetch();
            $groups = json_decode($row['group_ids'] ?? '[]', true) ?: [];
            if (!in_array($newGroup, $groups)) {
                $groups[] = $newGroup;
                $stmt = $db->prepare('UPDATE station_config SET group_ids = ?, updated_at = NOW() WHERE station_id = ?');
                $stmt->execute([json_encode($groups), $stationId]);
                $message = 'เพิ่ม Group ID เรียบร้อย';
            } else {
                $message = 'Group ID นี้มีอยู่แล้ว';
            }
        }
    } elseif ($action === 'rename_group') {
        $groupId = trim($_POST['group_id'] ?? '');
        $groupName = trim($_POST['group_name'] ?? '');
        if ($groupId !== '') {
            $stmt = $db->prepare('UPDATE line_groups SET group_name = ? WHERE group_id = ?');
            $stmt->execute([$groupName, $groupId]);
            $message = 'อัปเดตชื่อกลุ่มเรียบร้อย';
        }
    } elseif ($action === 'refresh_groups') {
        $message = refreshLineGroups($db);
    } elseif ($action === 'scan_logs') {
        $message = scanLogsForGroups($db);
    } elseif ($action === 'remove_group') {
        $stationId = $_POST['station_id'] ?? '';
        $removeGroup = $_POST['remove_group'] ?? '';
        $stmt = $db->prepare('SELECT group_ids FROM station_config WHERE station_id = ?');
        $stmt->execute([$stationId]);
        $row = $stmt->fetch();
        $groups = json_decode($row['group_ids'] ?? '[]', true) ?: [];
        $groups = array_values(array_filter($groups, fn($g) => $g !== $removeGroup));
        $stmt = $db->prepare('UPDATE station_config SET group_ids = ?, updated_at = NOW() WHERE station_id = ?');
        $stmt->execute([json_encode($groups), $stationId]);
        $message = 'ลบ Group ID เรียบร้อย';
    }

    // POST-Redirect-GET
    if ($message) {
        $_SESSION['flash_message'] = $message;
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=' . $redirectTab);
        exit;
    }
}

// Flash message
if (!empty($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

// --- Load LINE groups ---
$lineGroups = $db->query('SELECT * FROM line_groups ORDER BY group_name, group_id')->fetchAll();
// สร้าง map สำหรับ lookup ชื่อ
$groupNameMap = [];
foreach ($lineGroups as $lg) {
    $groupNameMap[$lg['group_id']] = $lg['group_name'] ?: substr($lg['group_id'], 0, 10) . '...';
}

// --- Load stations ---
$stations = $db->query('SELECT * FROM station_config ORDER BY station_name')->fetchAll();

// --- Load alert rules ---
$stationRules = [];
$allRules = $db->query('SELECT * FROM alert_rules ORDER BY station_id, threshold DESC')->fetchAll();
foreach ($allRules as $r) {
    $stationRules[$r['station_id']][] = $r;
}

// Migrate: สร้าง default rules สำหรับ station ที่ยังไม่มี rules
foreach ($stations as $s) {
    $sid = $s['station_id'];
    if (!isset($stationRules[$sid]) || empty($stationRules[$sid])) {
        createDefaultRules($db, $sid);
        // ดึง rules ที่สร้างใหม่
        $stationRules[$sid] = getAlertRules($db, $sid);
    }
}

// --- Load summary config ---
$summaryConfigs = $db->query('SELECT * FROM summary_config')->fetchAll();
$summaryMap = [];
foreach ($summaryConfigs as $sc) {
    $summaryMap[$sc['group_id']] = (int)$sc['enabled'];
}

// --- ดึงค่าล่าสุดจาก API ---
$liveData = [];
$ctx = stream_context_create(['http' => ['timeout' => 10]]);
$json = @file_get_contents('https://www.cmuccdc.org/api/floodboy/lasted', false, $ctx);
if ($json !== false) {
    $items = json_decode($json, true);
    if (is_array($items)) {
        foreach ($items as $item) {
            if (($item['db_model_option']['type'] ?? '') !== 'waterway') continue;
            $sid = (string)($item['id'] ?? '');
            $bankLevel = (float)($item['db_model_option']['back'] ?? 0);
            $waterLevel = (float)($item['water_level'] ?? 0);
            $percent = $bankLevel > 0 ? round(($waterLevel / $bankLevel) * 100, 1) : 0;
            $liveData[$sid] = [
                'water_level' => $waterLevel,
                'bank_level'  => $bankLevel,
                'percent'     => $percent,
            ];
        }
    }
}

// --- Recent alerts ---
$recentAlerts = $db->query('SELECT al.*, sc.station_name FROM alert_log al LEFT JOIN station_config sc ON al.station_id = sc.station_id ORDER BY al.alerted_at DESC LIMIT 50')->fetchAll();

function syncStations(PDO $db): string
{
    $apiUrl = 'https://www.cmuccdc.org/api/floodboy/lasted';
    $ctx = stream_context_create(['http' => ['timeout' => 30]]);
    $json = @file_get_contents($apiUrl, false, $ctx);
    if ($json === false) {
        return 'ไม่สามารถเชื่อมต่อ API ได้';
    }

    $items = json_decode($json, true);
    if (!is_array($items)) {
        return 'API ส่งข้อมูลผิดรูปแบบ';
    }

    $count = 0;
    foreach ($items as $item) {
        if (($item['db_model_option']['type'] ?? '') !== 'waterway') {
            continue;
        }

        $stationId = (string)($item['id'] ?? '');
        $stationName = $item['name'] ?? '';
        $uri = $item['uri'] ?? '';

        $stmt = $db->prepare('SELECT id FROM station_config WHERE station_id = ?');
        $stmt->execute([$stationId]);

        if ($stmt->fetch()) {
            // Update ชื่อและ uri
            $stmt = $db->prepare('UPDATE station_config SET station_name = ?, uri = ?, updated_at = NOW() WHERE station_id = ?');
            $stmt->execute([$stationName, $uri, $stationId]);
        } else {
            $defaultGroups = json_encode([LINE_GROUP_ID]);
            $stmt = $db->prepare('INSERT INTO station_config (station_id, station_name, uri, enabled, group_ids, threshold) VALUES (?, ?, ?, 1, ?, 80)');
            $stmt->execute([$stationId, $stationName, $uri, $defaultGroups]);
            // สร้าง default alert rules
            createDefaultRules($db, $stationId);
            $count++;
        }
    }

    return "Sync สำเร็จ เพิ่มสถานีใหม่ {$count} รายการ";
}

function refreshLineGroups(PDO $db): string
{
    $groups = $db->query('SELECT group_id FROM line_groups')->fetchAll(PDO::FETCH_COLUMN);
    $updated = 0;
    foreach ($groups as $gid) {
        $ch = curl_init("https://api.line.me/v2/bot/group/{$gid}/summary");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 200) {
            $data = json_decode($body, true);
            $name = $data['groupName'] ?? '';
            if ($name !== '') {
                $stmt = $db->prepare('UPDATE line_groups SET group_name = ? WHERE group_id = ?');
                $stmt->execute([$name, $gid]);
                $updated++;
            }
        }
    }
    return "อัปเดตชื่อกลุ่มสำเร็จ {$updated} กลุ่ม";
}

function scanLogsForGroups(PDO $db): string
{
    $logFiles = glob(LOG_DIR . '/webhook_*.jsonl') ?: [];
    $found = [];
    foreach ($logFiles as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $e = json_decode($line, true);
            if (!empty($e['groupId'])) {
                $found[$e['groupId']] = true;
            }
        }
    }

    $count = 0;
    $stmt = $db->prepare('INSERT IGNORE INTO line_groups (group_id, group_name) VALUES (?, "")');
    foreach (array_keys($found) as $gid) {
        $stmt->execute([$gid]);
        if ($stmt->rowCount() > 0) $count++;
    }

    return "Scan log สำเร็จ พบกลุ่มใหม่ {$count} กลุ่ม (จาก " . count($found) . " กลุ่มทั้งหมด)";
}

function loginPage(?string $error): void { ?>
<!doctype html>
<html lang="th">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign in - Flood Alert</title>
    <style>
    *,
    *::before,
    *::after {
        box-sizing: border-box;
    }

    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans", Helvetica, Arial, sans-serif;
        font-size: 14px;
        color: #1f2328;
        background: #f6f8fa;
        margin: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
    }

    .login-logo {
        text-align: center;
        margin-bottom: 16px;
        font-size: 48px;
    }

    .login-box {
        background: #fff;
        border: 1px solid #d0d7de;
        border-radius: 6px;
        padding: 20px;
        width: 308px;
    }

    .login-box h1 {
        font-size: 24px;
        font-weight: 300;
        text-align: center;
        margin: 0 0 16px;
        letter-spacing: -.5px;
    }

    .login-box label {
        display: block;
        font-weight: 600;
        margin-bottom: 6px;
    }

    .login-box input[type=password] {
        width: 100%;
        padding: 5px 12px;
        font-size: 14px;
        line-height: 20px;
        border: 1px solid #d0d7de;
        border-radius: 6px;
        background: #f6f8fa;
        outline: none;
    }

    .login-box input[type=password]:focus {
        border-color: #0969da;
        box-shadow: 0 0 0 3px rgba(9, 105, 218, .3);
        background: #fff;
    }

    .login-btn {
        display: block;
        width: 100%;
        padding: 5px 16px;
        font-size: 14px;
        font-weight: 600;
        line-height: 20px;
        color: #fff;
        background: #1a7f37;
        border: 1px solid rgba(27, 31, 36, .15);
        border-radius: 6px;
        cursor: pointer;
        margin-top: 16px;
    }

    .login-btn:hover {
        background: #116329;
    }

    .login-error {
        padding: 8px 12px;
        margin-bottom: 12px;
        background: #ffebe9;
        border: 1px solid rgba(255, 129, 130, .4);
        border-radius: 6px;
        color: #82071e;
        font-size: 13px;
    }
    </style>
</head>

<body>
    <div>
        <div class="login-logo">🌊</div>
        <div class="login-box">
            <h1>Sign in to Flood Alert</h1>
            <?php if ($error): ?>
            <div class="login-error"><?= htmlspecialchars($error) ?></div>
            <?php endif ?>
            <form method="post">
                <label for="pw">Password</label>
                <input type="password" id="pw" name="password" autofocus required>
                <button type="submit" class="login-btn">Sign in</button>
            </form>
        </div>
    </div>
</body>

</html>
<?php }

// Tab helper
function tabUrl(string $tab): string {
    return $_SERVER['PHP_SELF'] . '?tab=' . $tab;
}
function isTab(string $tab): bool {
    global $currentTab;
    return $currentTab === $tab;
}
?>
<!doctype html>
<html lang="th">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Flood Alert Admin</title>
    <style>
    *,
    *::before,
    *::after {
        box-sizing: border-box;
    }

    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans", Helvetica, Arial, sans-serif;
        font-size: 14px;
        line-height: 1.5;
        color: #1f2328;
        background: #f6f8fa;
        margin: 0;
    }

    a {
        color: #0969da;
        text-decoration: none;
    }

    a:hover {
        text-decoration: underline;
    }

    /* Header */
    .gh-header {
        background: #24292f;
        padding: 12px 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .gh-header-title {
        color: #f0f6fc;
        font-size: 16px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .gh-header-title span {
        font-size: 20px;
    }

    .gh-header .btn-logout {
        color: #d0d7de;
        background: transparent;
        border: 1px solid #6e7681;
        border-radius: 6px;
        padding: 3px 12px;
        font-size: 12px;
        cursor: pointer;
        font-family: inherit;
    }

    .gh-header .btn-logout:hover {
        color: #f0f6fc;
        border-color: #8b949e;
    }

    /* Container */
    .container {
        max-width: 1280px;
        margin: 0 auto;
        padding: 24px;
    }

    /* Flash */
    .flash {
        padding: 12px 16px;
        margin-bottom: 16px;
        border: 1px solid #d0d7de;
        border-radius: 6px;
        background: #ddf4ff;
        color: #0969da;
        font-size: 14px;
        position: relative;
    }

    .flash-close {
        position: absolute;
        top: 10px;
        right: 12px;
        background: none;
        border: none;
        cursor: pointer;
        color: #656d76;
        font-size: 16px;
        line-height: 1;
        padding: 0;
    }

    /* Underline Tabs */
    .UnderlineNav {
        display: flex;
        border-bottom: 1px solid #d0d7de;
        margin-bottom: 16px;
        gap: 0;
        overflow-x: auto;
    }

    .UnderlineNav-item {
        padding: 8px 16px;
        font-size: 14px;
        color: #656d76;
        font-weight: 500;
        border-bottom: 2px solid transparent;
        white-space: nowrap;
        text-decoration: none;
    }

    .UnderlineNav-item:hover {
        color: #1f2328;
        text-decoration: none;
        border-bottom-color: #d0d7de;
    }

    .UnderlineNav-item.selected {
        color: #1f2328;
        font-weight: 600;
        border-bottom-color: #fd8c73;
    }

    .UnderlineNav-item .Counter {
        display: inline-block;
        min-width: 20px;
        padding: 0 6px;
        font-size: 12px;
        font-weight: 600;
        line-height: 18px;
        text-align: center;
        background: rgba(175, 184, 193, .2);
        border-radius: 10px;
        margin-left: 4px;
    }

    .UnderlineNav-item.selected .Counter {
        background: rgba(234, 74, 40, .1);
        color: #bc4c00;
    }

    /* Box (like GitHub repo box) */
    .Box {
        border: 1px solid #d0d7de;
        border-radius: 6px;
        background: #fff;
    }

    .Box-header {
        padding: 12px 16px;
        background: #f6f8fa;
        border-bottom: 1px solid #d0d7de;
        border-radius: 6px 6px 0 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .Box-header h3 {
        margin: 0;
        font-size: 14px;
        font-weight: 600;
    }

    .Box-body {
        padding: 16px;
    }

    .Box-row {
        padding: 12px 16px;
        border-top: 1px solid #d0d7de;
    }

    .Box-row:first-child {
        border-top: none;
    }

    /* Tables */
    .gh-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .gh-table th {
        padding: 8px 16px;
        background: #f6f8fa;
        border-bottom: 1px solid #d0d7de;
        text-align: left;
        font-weight: 600;
        color: #1f2328;
        white-space: nowrap;
        font-size: 12px;
    }

    .gh-table td {
        padding: 8px 16px;
        border-bottom: 1px solid #d0d7de;
        vertical-align: middle;
    }

    .gh-table tbody tr:last-child td {
        border-bottom: none;
    }

    .gh-table tbody tr:hover {
        background: #f6f8fa;
    }

    /* Buttons */
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 12px;
        font-size: 12px;
        font-weight: 600;
        font-family: inherit;
        line-height: 20px;
        border-radius: 6px;
        cursor: pointer;
        border: 1px solid rgba(27, 31, 36, .15);
        white-space: nowrap;
    }

    .btn-primary {
        color: #fff;
        background: #1a7f37;
    }

    .btn-primary:hover {
        background: #116329;
    }

    .btn-default {
        color: #24292f;
        background: #f6f8fa;
    }

    .btn-default:hover {
        background: #eaeef2;
    }

    .btn-danger-outline {
        color: #cf222e;
        background: transparent;
        border-color: rgba(27, 31, 36, .15);
    }

    .btn-danger-outline:hover {
        color: #fff;
        background: #cf222e;
        border-color: #cf222e;
    }

    .btn-sm {
        padding: 1px 8px;
        font-size: 11px;
    }

    .btn-toggle-on {
        color: #fff;
        background: #1a7f37;
        border-color: rgba(27, 31, 36, .15);
    }

    .btn-toggle-off {
        color: #656d76;
        background: #f6f8fa;
        border-color: #d0d7de;
    }

    /* Form controls */
    .form-control {
        padding: 3px 12px;
        font-size: 14px;
        line-height: 20px;
        border: 1px solid #d0d7de;
        border-radius: 6px;
        background: #fff;
        font-family: inherit;
        outline: none;
    }

    .form-control:focus {
        border-color: #0969da;
        box-shadow: 0 0 0 3px rgba(9, 105, 218, .3);
    }

    .form-control-sm {
        padding: 1px 8px;
        font-size: 12px;
    }

    .form-select {
        padding: 3px 28px 3px 8px;
        font-size: 12px;
        line-height: 20px;
        border: 1px solid #d0d7de;
        border-radius: 6px;
        background: #f6f8fa url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M2 4l4 4 4-4' fill='none' stroke='%23656d76' stroke-width='1.5'/%3E%3C/svg%3E") right 8px center/12px no-repeat;
        -webkit-appearance: none;
        font-family: inherit;
        cursor: pointer;
        outline: none;
    }

    .form-select:focus {
        border-color: #0969da;
        box-shadow: 0 0 0 3px rgba(9, 105, 218, .3);
    }

    /* Monospace */
    code,
    .mono {
        font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
        font-size: 12px;
    }

    /* Util */
    .text-muted {
        color: #656d76;
    }

    .text-bold {
        font-weight: 600;
    }

    .text-small {
        font-size: 12px;
    }

    .text-right {
        text-align: right;
    }

    .text-center {
        text-align: center;
    }

    .text-danger {
        color: #cf222e;
        font-weight: 600;
    }

    .text-warning {
        color: #bf8700;
        font-weight: 600;
    }

    .text-success {
        color: #1a7f37;
    }

    .d-flex {
        display: flex;
    }

    .align-center {
        align-items: center;
    }

    .gap-4 {
        gap: 4px;
    }

    .gap-8 {
        gap: 8px;
    }

    .gap-16 {
        gap: 16px;
    }

    .mb-0 {
        margin-bottom: 0;
    }

    .mb-8 {
        margin-bottom: 8px;
    }

    .mb-16 {
        margin-bottom: 16px;
    }

    .mt-4 {
        margin-top: 4px;
    }

    .mt-8 {
        margin-top: 8px;
    }

    .inline {
        display: inline;
    }

    .block {
        display: block;
    }

    .overflow-auto {
        overflow-x: auto;
    }

    .group-id {
        font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
        font-size: 12px;
        word-break: break-all;
        color: #656d76;
    }

    /* Guide */
    .guide-section {
        max-width: 860px;
    }

    .guide-section h3 {
        font-size: 16px;
        font-weight: 600;
        padding-bottom: 8px;
        border-bottom: 1px solid #d0d7de;
        margin-bottom: 12px;
        color: #1f2328;
    }

    pre.flow-diagram {
        background: #24292f;
        color: #e6edf3;
        border-radius: 6px;
        padding: 16px;
        font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
        font-size: 13px;
        line-height: 1.6;
        overflow-x: auto;
        border: 1px solid #d0d7de;
    }

    .severity-row {
        display: flex;
        align-items: center;
        padding: 16px 0;
        border-bottom: 1px solid #d0d7de;
        gap: 24px;
    }

    .severity-row:last-child {
        border-bottom: none;
    }

    .severity-icon {
        font-size: 36px;
        line-height: 1;
        min-width: 50px;
        text-align: center;
    }

    .severity-name {
        font-weight: 700;
        font-size: 15px;
        min-width: 80px;
    }

    .severity-pct {
        min-width: 160px;
        color: #656d76;
        font-size: 14px;
    }

    .severity-desc {
        color: #1f2328;
        font-size: 14px;
    }

    .guide-card {
        border: 1px solid #d0d7de;
        border-radius: 6px;
        background: #fff;
        padding: 20px;
        margin-bottom: 16px;
    }

    .guide-card ol,
    .guide-card ul {
        padding-left: 20px;
        margin: 8px 0 0;
    }

    .guide-card li {
        margin-bottom: 6px;
    }

    .guide-tip {
        background: #ddf4ff;
        border: 1px solid #54aeff66;
        border-radius: 6px;
        padding: 12px 16px;
        font-size: 13px;
        margin-top: 12px;
    }
    </style>
</head>

<body>

    <!-- GitHub-style Header -->
    <div class="gh-header">
        <div class="gh-header-title">
            <span>🌊</span> Flood Alert System
        </div>
        <form method="post" class="inline">
            <button name="logout" class="btn-logout">Sign out</button>
        </form>
    </div>

    <div class="container">

        <?php if ($message): ?>
        <div class="flash">
            <?= htmlspecialchars($message) ?>
            <button class="flash-close" onclick="this.parentElement.remove()">&times;</button>
        </div>
        <?php endif ?>

        <!-- Underline Tab Navigation -->
        <nav class="UnderlineNav">
            <a class="UnderlineNav-item <?= isTab('stations') ? 'selected' : '' ?>" href="<?= tabUrl('stations') ?>">
                📡 จุดตรวจวัด <span class="Counter"><?= count($stations) ?></span>
            </a>
            <a class="UnderlineNav-item <?= isTab('groups') ? 'selected' : '' ?>" href="<?= tabUrl('groups') ?>">
                💬 กลุ่ม LINE <span class="Counter"><?= count($lineGroups) ?></span>
            </a>
            <a class="UnderlineNav-item <?= isTab('history') ? 'selected' : '' ?>" href="<?= tabUrl('history') ?>">
                🔔 ประวัติแจ้งเตือน <span class="Counter"><?= count($recentAlerts) ?></span>
            </a>
            <a class="UnderlineNav-item <?= isTab('guide') ? 'selected' : '' ?>" href="<?= tabUrl('guide') ?>">
                📖 คู่มือ
            </a>
        </nav>

        <!-- ============================================================ -->
        <!-- TAB: STATIONS -->
        <!-- ============================================================ -->
        <?php if (isTab('stations')): ?>
        <div class="Box">
            <div class="Box-header">
                <h3>📋 รายการสถานี</h3>
                <form method="post" action="<?= tabUrl('stations') ?>" class="inline">
                    <button name="action" value="sync" class="btn btn-primary"
                        onclick="return confirm('Sync สถานีจาก API?')">🔄 Sync สถานี</button>
                </form>
            </div>
            <?php if ($stations): ?>
            <div class="overflow-auto">
                <table class="gh-table">
                    <thead>
                        <tr>
                            <th>สถานี</th>
                            <th>URI</th>
                            <th style="width:90px">ระดับน้ำ (ม.)</th>
                            <th style="width:90px">ขอบตลิ่ง (ม.)</th>
                            <th style="width:60px">%</th>
                            <th style="width:80px">สถานะ</th>
                            <th style="width:280px">เงื่อนไขแจ้งเตือน</th>
                            <th>LINE Groups</th>
                            <th style="width:140px">อัปเดตล่าสุด</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stations as $s): ?>
                        <tr>
                            <td>
                                <span class="text-bold"><?= htmlspecialchars($s['station_name']) ?></span>
                                <br><span class="text-small text-muted">ID:
                                    <?= htmlspecialchars($s['station_id']) ?></span>
                            </td>
                            <td>
                                <?php if ($s['uri']): ?>
                                <a href="https://www.cmuccdc.org/floodboy/<?= htmlspecialchars($s['uri']) ?>"
                                    target="_blank">
                                    <?= htmlspecialchars($s['uri']) ?>
                                </a>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif ?>
                            </td>
                            <?php
              $live = $liveData[$s['station_id']] ?? null;
              if ($live):
                  $pct = $live['percent'];
                  $pctClass = $pct >= 100 ? 'text-danger' : ($pct > $s['threshold'] ? 'text-warning' : 'text-success');
            ?>
                            <td class="text-right mono"><?= number_format($live['water_level'], 2) ?></td>
                            <td class="text-right mono"><?= number_format($live['bank_level'], 2) ?></td>
                            <td class="text-right"><span class="<?= $pctClass ?>"><?= $pct ?>%</span></td>
                            <?php else: ?>
                            <td class="text-center text-muted">-</td>
                            <td class="text-center text-muted">-</td>
                            <td class="text-center text-muted">-</td>
                            <?php endif ?>
                            <td>
                                <form method="post" action="<?= tabUrl('stations') ?>" class="inline">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="station_id"
                                        value="<?= htmlspecialchars($s['station_id']) ?>">
                                    <input type="hidden" name="enabled" value="<?= $s['enabled'] ? 0 : 1 ?>">
                                    <button
                                        class="btn btn-sm <?= $s['enabled'] ? 'btn-toggle-on' : 'btn-toggle-off' ?>">
                                        <?= $s['enabled'] ? '🔔 เปิด' : '🔕 ปิด' ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <?php
              $rules = $stationRules[$s['station_id']] ?? [];
              ?>
                                <div class="alert-rules-list">
                                    <?php foreach ($rules as $rule): ?>
                                    <form method="post" action="<?= tabUrl('stations') ?>"
                                        class="d-flex align-center gap-4 mb-4" style="margin-bottom:4px">
                                        <input type="hidden" name="action" value="edit_rule">
                                        <input type="hidden" name="rule_id" value="<?= $rule['id'] ?>">
                                        <span class="text-small text-muted">&gt;</span>
                                        <input type="number" name="threshold" value="<?= $rule['threshold'] ?>" min="1"
                                            max="100" step="1" class="form-control form-control-sm" style="width:60px">
                                        <span class="text-small text-muted">%</span>
                                        <span class="text-small text-muted">→</span>
                                        <select name="alert_interval" class="form-select" style="width:90px">
                                            <?php foreach ([5, 10, 15, 30, 60, 90, 120, 180] as $v): ?>
                                            <option value="<?= $v ?>"
                                                <?= (int)$rule['alert_interval'] === $v ? 'selected' : '' ?>><?= $v ?>
                                                นาที</option>
                                            <?php endforeach ?>
                                        </select>
                                        <input type="hidden" name="station_id"
                                            value="<?= htmlspecialchars($s['station_id']) ?>">
                                        <button type="submit" class="btn btn-sm btn-primary-outline"
                                            style="padding:0 4px;font-size:10px;line-height:16px"
                                            title="บันทึก">✓</button>
                                        <?php if (count($rules) > 1): ?>
                                        <button name="action" value="delete_rule" class="btn btn-sm btn-danger-outline"
                                            style="padding:0 4px;font-size:10px;line-height:16px" title="ลบ"
                                            onclick="return confirm('ลบเงื่อนไขนี้?')">✕</button>
                                        <?php endif ?>
                                    </form>
                                    <?php endforeach ?>
                                    <form method="post" action="<?= tabUrl('stations') ?>"
                                        class="d-flex align-center gap-4 mt-4" style="margin-top:4px">
                                        <input type="hidden" name="action" value="add_rule">
                                        <input type="hidden" name="station_id"
                                            value="<?= htmlspecialchars($s['station_id']) ?>">
                                        <input type="number" name="threshold" value="" min="1" max="100" step="1"
                                            class="form-control form-control-sm" style="width:60px" placeholder=">%"
                                            required>
                                        <span class="text-small text-muted">%</span>
                                        <span class="text-small text-muted">→</span>
                                        <select name="alert_interval" class="form-select" style="width:90px">
                                            <?php foreach ([5, 10, 15, 30, 60, 90, 120, 180] as $v): ?>
                                            <option value="<?= $v ?>"><?= $v ?> นาที</option>
                                            <?php endforeach ?>
                                        </select>
                                        <button class="btn btn-sm btn-default">+</button>
                                    </form>
                                </div>
                            </td>
                            <td>
                                <?php
              $groups = json_decode($s['group_ids'] ?? '[]', true) ?: [];
              foreach ($groups as $g):
                  $gLabel = $groupNameMap[$g] ?? substr($g, 0, 10) . '...';
              ?>
                                <div class="d-flex align-center gap-4 mb-0" style="margin-bottom:4px">
                                    <span class="group-id"
                                        title="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($gLabel) ?></span>
                                    <form method="post" action="<?= tabUrl('stations') ?>" class="inline">
                                        <input type="hidden" name="action" value="remove_group">
                                        <input type="hidden" name="station_id"
                                            value="<?= htmlspecialchars($s['station_id']) ?>">
                                        <input type="hidden" name="remove_group" value="<?= htmlspecialchars($g) ?>">
                                        <button class="btn btn-sm btn-danger-outline"
                                            style="padding:0 4px;font-size:10px;line-height:16px" title="ลบ"
                                            onclick="return confirm('ลบกลุ่มนี้?')">✕</button>
                                    </form>
                                </div>
                                <?php endforeach ?>
                                <?php if ($lineGroups): ?>
                                <form method="post" action="<?= tabUrl('stations') ?>"
                                    class="d-flex align-center gap-4 mt-4">
                                    <input type="hidden" name="action" value="add_group">
                                    <input type="hidden" name="station_id"
                                        value="<?= htmlspecialchars($s['station_id']) ?>">
                                    <select name="new_group" class="form-select" style="width:150px">
                                        <option value="">เพิ่มกลุ่ม...</option>
                                        <?php foreach ($lineGroups as $lg):
                      $alreadyAdded = in_array($lg['group_id'], $groups);
                      $label = $lg['group_name'] ?: substr($lg['group_id'], 0, 15) . '...';
                  ?>
                                        <option value="<?= htmlspecialchars($lg['group_id']) ?>"
                                            <?= $alreadyAdded ? 'disabled' : '' ?>>
                                            <?= $alreadyAdded ? '✓ ' : '' ?><?= htmlspecialchars($label) ?>
                                        </option>
                                        <?php endforeach ?>
                                    </select>
                                    <button class="btn btn-sm btn-default">+</button>
                                </form>
                                <?php endif ?>
                            </td>
                            <td><span
                                    class="text-small text-muted"><?= htmlspecialchars($s['updated_at'] ?? '') ?></span>
                            </td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="Box-body">
                <p class="text-muted mb-0">ยังไม่มีสถานี — กดปุ่ม "Sync สถานี" เพื่อดึงข้อมูลจาก API</p>
            </div>
            <?php endif ?>
        </div>

        <!-- Summary Config Section -->
        <div class="Box mt-16" style="margin-top:16px">
            <div class="Box-header">
                <h3>📊 รายงานสรุป (Daily Summary)</h3>
                <form method="post" action="<?= tabUrl('stations') ?>" class="d-flex align-center gap-4">
                    <input type="hidden" name="action" value="add_summary_group">
                    <select name="group_id" class="form-select">
                        <option value="">เพิ่มกลุ่มรับสรุป...</option>
                        <?php foreach ($lineGroups as $lg):
              $label = $lg['group_name'] ?: substr($lg['group_id'], 0, 15) . '...';
              $alreadyIn = isset($summaryMap[$lg['group_id']]);
          ?>
                        <option value="<?= htmlspecialchars($lg['group_id']) ?>" <?= $alreadyIn ? 'disabled' : '' ?>>
                            <?= $alreadyIn ? '✓ ' : '' ?><?= htmlspecialchars($label) ?>
                        </option>
                        <?php endforeach ?>
                    </select>
                    <button class="btn btn-sm btn-primary">+ เพิ่ม</button>
                </form>
            </div>
            <?php if ($summaryConfigs): ?>
            <div class="overflow-auto">
                <table class="gh-table">
                    <thead>
                        <tr>
                            <th>กลุ่ม LINE</th>
                            <th>Group ID</th>
                            <th style="width:100px">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summaryConfigs as $sc):
              $scName = $groupNameMap[$sc['group_id']] ?? substr($sc['group_id'], 0, 10) . '...';
          ?>
                        <tr>
                            <td class="text-bold"><?= htmlspecialchars($scName) ?></td>
                            <td><code class="text-small"><?= htmlspecialchars($sc['group_id']) ?></code></td>
                            <td>
                                <form method="post" action="<?= tabUrl('stations') ?>" class="inline">
                                    <input type="hidden" name="action" value="toggle_summary">
                                    <input type="hidden" name="group_id"
                                        value="<?= htmlspecialchars($sc['group_id']) ?>">
                                    <input type="hidden" name="enabled" value="<?= $sc['enabled'] ? 0 : 1 ?>">
                                    <button
                                        class="btn btn-sm <?= $sc['enabled'] ? 'btn-toggle-on' : 'btn-toggle-off' ?>">
                                        <?= $sc['enabled'] ? '📊 เปิด' : '🔕 ปิด' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="Box-body">
                <p class="text-muted mb-0">ยังไม่มีกลุ่มรับรายงานสรุป — เลือกกลุ่มจาก dropdown ด้านบนเพื่อเพิ่ม</p>
            </div>
            <?php endif ?>
            <div class="Box-body" style="border-top:1px solid #d0d7de">
                <p class="text-small text-muted mb-0">💡 รายงานสรุปจะถูกส่งอัตโนมัติเวลา 08:00 และ 16:00
                    ไปยังกลุ่มที่เปิดอยู่ (ต้องตั้ง crontab: <code>0 8,16 * * * php cron_flood_summary.php</code>)</p>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- TAB: LINE GROUPS -->
        <!-- ============================================================ -->
        <?php elseif (isTab('groups')): ?>
        <div class="Box">
            <div class="Box-header">
                <h3>💬 กลุ่ม LINE ที่ Bot อยู่</h3>
                <div class="d-flex gap-8">
                    <form method="post" action="<?= tabUrl('groups') ?>" class="inline">
                        <button name="action" value="scan_logs" class="btn btn-default">📂 Scan จาก Log</button>
                    </form>
                    <form method="post" action="<?= tabUrl('groups') ?>" class="inline">
                        <button name="action" value="refresh_groups" class="btn btn-default">🔄 ดึงชื่อกลุ่ม</button>
                    </form>
                </div>
            </div>
            <?php if ($lineGroups): ?>
            <div class="overflow-auto">
                <table class="gh-table">
                    <thead>
                        <tr>
                            <th>Group ID</th>
                            <th>ชื่อกลุ่ม</th>
                            <th>เข้าร่วมเมื่อ</th>
                            <th style="width:240px">แก้ไขชื่อ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lineGroups as $lg): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($lg['group_id']) ?></code></td>
                            <td><?= htmlspecialchars($lg['group_name'] ?: '-') ?></td>
                            <td><span class="text-small text-muted"><?= htmlspecialchars($lg['joined_at']) ?></span>
                            </td>
                            <td>
                                <form method="post" action="<?= tabUrl('groups') ?>" class="d-flex align-center gap-4">
                                    <input type="hidden" name="action" value="rename_group">
                                    <input type="hidden" name="group_id"
                                        value="<?= htmlspecialchars($lg['group_id']) ?>">
                                    <input type="text" name="group_name"
                                        value="<?= htmlspecialchars($lg['group_name']) ?>" placeholder="ตั้งชื่อ"
                                        class="form-control form-control-sm" style="flex:1">
                                    <button class="btn btn-sm btn-default">Save</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="Box-body">
                <p class="text-muted mb-0">ยังไม่มีกลุ่ม — เพิ่ม Bot เข้ากลุ่ม LINE แล้วส่งข้อความเพื่อให้ระบบบันทึก
                    Group ID อัตโนมัติ</p>
            </div>
            <?php endif ?>
        </div>

        <!-- ============================================================ -->
        <!-- TAB: HISTORY -->
        <!-- ============================================================ -->
        <?php elseif (isTab('history')): ?>
        <div class="Box">
            <div class="Box-header">
                <h3>🔔 ประวัติการแจ้งเตือน (ล่าสุด 50 รายการ)</h3>
            </div>
            <?php if ($recentAlerts): ?>
            <div class="overflow-auto">
                <table class="gh-table">
                    <thead>
                        <tr>
                            <th>เวลา</th>
                            <th>สถานี</th>
                            <th>ระดับน้ำ %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAlerts as $a): ?>
                        <tr>
                            <td><span class="text-small text-muted"><?= htmlspecialchars($a['alerted_at']) ?></span>
                            </td>
                            <td><?= htmlspecialchars($a['station_name'] ?? $a['station_id']) ?></td>
                            <td>
                                <?php
              $pct = $a['percent'];
              $cls = $pct >= 100 ? 'text-danger' : 'text-warning';
              ?>
                                <span class="<?= $cls ?>"><?= $pct ?>%</span>
                            </td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="Box-body">
                <p class="text-muted mb-0">ยังไม่มีการแจ้งเตือน</p>
            </div>
            <?php endif ?>
        </div>

        <!-- ============================================================ -->
        <!-- TAB: GUIDE -->
        <!-- ============================================================ -->
        <?php elseif (isTab('guide')): ?>
        <div class="guide-section">

            <!-- ภาพรวม -->
            <div class="guide-card">
                <h3>📌 ภาพรวมระบบ</h3>
                <p>ระบบ Flood Alert เป็นระบบแจ้งเตือนระดับน้ำผ่าน LINE Messaging API โดยดึงข้อมูลจากสถานีตรวจวัดของ CMU
                    CCDC แล้วส่งการแจ้งเตือนไปยังกลุ่ม LINE ที่กำหนด เมื่อระดับน้ำเกินค่า threshold ที่ตั้งไว้</p>
                <ul>
                    <li><strong>แหล่งข้อมูล:</strong> CMU CCDC Floodboy API (ข้อมูลระดับน้ำ realtime)</li>
                    <li><strong>การแจ้งเตือน:</strong> LINE Messaging API (Flex Message)</li>
                    <li><strong>การทำงาน:</strong> Cron job ทำงานตามรอบที่ตั้งไว้</li>
                </ul>
            </div>

            <!-- Flow Diagram: cron_flood -->
            <div class="guide-card">
                <h3>⚙️ Flow การทำงาน: cron_flood.php (แจ้งเตือนรายสถานี)</h3>
                <pre class="flow-diagram">
┌───────────────────┐
│  Cron Job         │  ทุก 10 นาที (*/10 * * * *)
│  cron_flood.php   │
└────────┬──────────┘
         │
         ▼
┌───────────────────────┐
│  ดึงข้อมูล API        │  GET /api/floodboy/lasted
│  CMU CCDC             │
└────────┬──────────────┘
         │
         ▼
┌───────────────────────────────────────┐
│  วนลูปแต่ละสถานี (type = waterway)    │
└────────┬──────────────────────────────┘
         │
         ▼
┌───────────────────────────────────────┐
│  ดึง/สร้าง station_config จาก DB      │
│  (auto-insert สถานีใหม่)              │
└────────┬──────────────────────────────┘
         │
         ▼
    ┌────┴────┐
    │ enabled │──── ปิด ──→ ข้าม (SKIP)
    │    ?    │
    └────┬────┘
         │ เปิด
         ▼
┌───────────────────────────────────────┐
│  คำนวณ % = (water_level / bank) × 100│
└────────┬──────────────────────────────┘
         │
         ▼
┌───────────────────────────────────────┐
│  ดึง alert_rules ของสถานี            │
│  เรียง threshold DESC (สูง→ต่ำ)      │
└────────┬──────────────────────────────┘
         │
         ▼
    ┌────┴──────────┐
    │ % >= threshold│──── ไม่ ──→ ข้าม (NORMAL)
    │ (เงื่อนไขแรก) │
    └────┴──────────┘
         │ ใช่
         ▼
┌───────────────────────────────────────┐
│  ใช้ alert_interval จาก rule ที่ match │
│  (เงื่อนไข strict ที่สุด)            │
└────────┬──────────────────────────────┘
         │
         ▼
┌───────────────────────────────────────┐
│  กำหนด severity                       │
│  ├─ % >= 100  → 🔴 critical           │
│  └─ % > threshold → 🟡 watch          │
└────────┬──────────────────────────────┘
         │
         ▼
┌───────────────────────────────────────┐
│  ตรวจ cooldown จาก alert_log          │
│  (ใช้ interval จาก rule ที่ match    │
│   เช่น 5, 10, 30, 60 นาที)          │
└────────┬──────────────────────────────┘
         │
         ▼
┌───────────────────────────────────────┐
│  ตรวจ cooldown จาก alert_log          │
│  (ตาม alert_interval ของแต่ละสถานี    │
│   เช่น 10 นาที, 1 ชม., 6 ชม., 1 วัน) │
└────────┬──────────────────────────────┘
         │
         ▼
    ┌────┴────────────┐
    │ เคยแจ้งเตือนแล้ว │──── ใช่ ──→ ข้าม (ALREADY ALERTED)
    │   ภายใน interval │
    └────┬────────────┘
         │ ยังไม่เคย
         ▼
┌───────────────────────────────────────┐
│  ส่ง Flex Message แจ้งเตือน           │
│  ไปยังทุก group ที่ตั้งค่าไว้          │
│  + บันทึก alert_log                   │
└───────────────────────────────────────┘
</pre>
            </div>

            <!-- Flow Diagram: cron_flood_summary -->
            <div class="guide-card">
                <h3>📊 Flow การทำงาน: cron_flood_summary.php (รายงานสรุป)</h3>
                <pre class="flow-diagram">
┌─────────────────────────┐
│  Cron Job               │  08:00 และ 16:00 (0 8,16 * * *)
│  cron_flood_summary.php │
└────────┬────────────────┘
         │
         ▼
┌───────────────────────┐
│  ดึงข้อมูล API        │  GET /api/floodboy/lasted
│  CMU CCDC             │
└────────┬──────────────┘
         │
         ▼
┌───────────────────────────────────────┐
│  ดึงสถานีที่ enabled จาก DB           │
│  SELECT * FROM station_config         │
│  WHERE enabled = 1                    │
└────────┬──────────────────────────────┘
         │
         ▼
┌───────────────────────────────────────┐
│  รวบรวมข้อมูลทุกสถานี                 │
│  ├─ คำนวณ % และ severity              │
│  └─ เรียงลำดับ: critical → watch →     │
│     normal (มาก → น้อย)               │
└────────┬──────────────────────────────┘
         │
         ▼
┌───────────────────────────────────────┐
│  ดึงกลุ่มรับสรุปจาก summary_config    │
│  WHERE enabled = 1                    │
└────────┬──────────────────────────────┘
         │
         ▼
┌───────────────────────────────────────┐
│  สร้าง Flex Message ตารางสรุป          │
│  ┌─────────────────────────────────┐  │
│  │ 📊 รายงานสรุประดับน้ำ           │  │
│  │ 🕐 06/03/2026 08:00            │  │
│  │─────────────────────────────────│  │
│  │    สถานี          %    ระดับ(ม.)│  │
│  │ 🔴 สถานี A     105%     3.20   │  │
│  │ 🟡 สถานี B      85%     2.10   │  │
│  │ 🟢 สถานี C      45%     1.05   │  │
│  │─────────────────────────────────│  │
│  │ ทั้งหมด 3 สถานี   🟡 1  🔴 1   │  │
│  └─────────────────────────────────┘  │
│  (แบ่ง bubble ละ 25 สถานี)            │
└────────┬──────────────────────────────┘
         │
         ▼
┌───────────────────────────────────────┐
│  ส่ง Flex Message ไปยังทุกกลุ่ม       │
│  ที่เปิดใน summary_config             │
└───────────────────────────────────────┘
</pre>
            </div>

            <!-- ระดับการแจ้งเตือนภัยน้ำท่วม -->
            <div class="guide-card">
                <h3>🚩 ระดับการแจ้งเตือนภัยน้ำท่วม</h3>
                <div style="border:1px solid #d0d7de;border-radius:6px;overflow:hidden">
                    <div style="display:flex;padding:10px 24px;background:#f6f8fa;border-bottom:1px solid #d0d7de">
                        <span class="text-small text-bold" style="min-width:120px">ระดับสี</span>
                        <span class="text-small text-bold" style="min-width:180px">ความจุลำน้ำ</span>
                        <span class="text-small text-bold">คำแนะนำ</span>
                    </div>
                    <div class="severity-row" style="padding:16px 24px;margin:0">
                        <div>
                            <div class="severity-icon">🟩</div>
                            <div class="severity-name" style="color:#1a7f37">ปกติ</div>
                        </div>
                        <div class="severity-pct">&le;80%</div>
                        <div class="severity-desc">สภาวะน้ำปกติ</div>
                    </div>
                    <div class="severity-row" style="padding:16px 24px;margin:0">
                        <div>
                            <div class="severity-icon">🟨</div>
                            <div class="severity-name" style="color:#bf8700">เฝ้าระวัง</div>
                        </div>
                        <div class="severity-pct">&gt;80% ถึง &lt;100%</div>
                        <div class="severity-desc">ติดตามข่าวสารอย่างใกล้ชิด เตรียมพร้อมอพยพ/แจ้งเตือนเมื่อจำเป็น</div>
                    </div>
                    <div class="severity-row" style="padding:16px 24px;margin:0;border-bottom:none">
                        <div>
                            <div class="severity-icon">🟥</div>
                            <div class="severity-name" style="color:#cf222e">วิกฤต</div>
                        </div>
                        <div class="severity-pct">&ge;100%</div>
                        <div class="severity-desc">ดำเนินการแจ้งเตือนภัยและอพยพประชาชนทันที</div>
                    </div>
                </div>
                <p class="text-small text-muted" style="margin-top:12px">ที่มา: ศูนย์อุทกวิทยาและการประยุกต์
                    มหาวิทยาลัยเชียงใหม่</p>
            </div>

            <!-- Dynamic Threshold -->
            <div class="guide-card">
                <h3>⚙️ ระบบเงื่อนไขแจ้งเตือนแบบ Dynamic (หลายเงื่อนไข)</h3>
                <p>แต่ละสถานีสามารถกำหนด <strong>หลายเงื่อนไข</strong> ได้ โดยแต่ละเงื่อนไขประกอบด้วย:</p>
                <ul>
                    <li><strong>Threshold (%)</strong> — ค่าระดับน้ำ (%) ที่ต้องการแจ้งเตือน</li>
                    <li><strong>ความถี่</strong> — ระยะเวลาขั้นต่ำระหว่างการแจ้งเตือน (5-180 นาที)</li>
                </ul>

                <h4 style="margin-top:16px;font-size:14px;font-weight:600">ตัวอย่างการตั้งค่า</h4>
                <table class="gh-table" style="margin-top:8px">
                    <thead>
                        <tr>
                            <th>Threshold</th>
                            <th>ความถี่</th>
                            <th>ความหมาย</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>> 50%</td>
                            <td>30 นาที</td>
                            <td>ถ้าระดับน้ำเกิน 50% ให้แจ้งเตือนทุก 30 นาที</td>
                        </tr>
                        <tr>
                            <td>> 80%</td>
                            <td>10 นาที</td>
                            <td>ถ้าระดับน้ำเกิน 80% ให้แจ้งเตือนทุก 10 นาที</td>
                        </tr>
                        <tr>
                            <td>> 100%</td>
                            <td>5 นาที</td>
                            <td>ถ้าระดับน้ำเกิน 100% ให้แจ้งเตือนทุก 5 นาที</td>
                        </tr>
                    </tbody>
                </table>

                <h4 style="margin-top:16px;font-size:14px;font-weight:600">หลักการทำงาน</h4>
                <ol style="margin-top:8px">
                    <li>ดึงเงื่อนไขทั้งหมดของสถานี เรียงตาม threshold จากสูงไปต่ำ</li>
                    <li>หาเงื่อนไขแรกที่ <code>ระดับน้ำ % >= threshold</code></li>
                    <li>ใช้ความถี่ (alert_interval) จากเงื่อนไขที่ match</li>
                    <li>ถ้าระดับน้ำ 85% จะ match กับเงื่อนไข ">80%" (ไม่ใช่ ">50%")</li>
                </ol>

                <div class="guide-tip">
                    <strong>💡 ค่าเริ่มต้น:</strong> สถานีใหม่จะถูกสร้างเงื่อนไขอัตโนมัติ 2 ข้อ:<br>
                    • threshold 80% → แจ้งเตือนทุก 60 นาที<br>
                    • threshold 100% → แจ้งเตือนทุก 5 นาที
                </div>
            </div>

            <!-- วิธีเพิ่ม Bot เข้ากลุ่ม -->
            <div class="guide-card">
                <h3>🤖 วิธีเพิ่ม Bot เข้ากลุ่ม LINE</h3>
                <div
                    style="background:#f6f8fa;border:1px solid #d0d7de;border-radius:6px;padding:12px 16px;margin-bottom:12px;display:flex;align-items:center;gap:12px">
                    <span style="font-size:24px">🤖</span>
                    <div>
                        <span class="text-small text-muted">LINE ID ของ Bot</span><br>
                        <code
                            style="font-size:16px;font-weight:600;color:#0969da"><?= htmlspecialchars(LINE_ฺID) ?></code>
                    </div>
                </div>
                <ol>
                    <li>เปิดแอป LINE บนมือถือ → ค้นหา ID <code><?= htmlspecialchars(LINE_ฺID) ?></code>
                        แล้วเพิ่มเป็นเพื่อน</li>
                    <li>เข้ากลุ่มที่ต้องการ → กดชื่อกลุ่มด้านบน → <strong>เชิญ</strong></li>
                    <li>ค้นหาชื่อ Bot หรือเลือกจากรายชื่อเพื่อน แล้วเพิ่มเข้ากลุ่ม</li>
                    <li>ส่งข้อความอะไรก็ได้ในกลุ่ม (เพื่อให้ webhook จับ Group ID)</li>
                    <li>กลับมาหน้า Admin → แท็บ <strong>💬 กลุ่ม LINE</strong> → กด <strong>"📂 Scan จาก Log"</strong>
                    </li>
                    <li>กลุ่มใหม่จะปรากฏในรายการ → สามารถเลือกใช้กับสถานีได้</li>
                </ol>
            </div>

            <!-- วิธีจัดการสถานี -->
            <div class="guide-card">
                <h3>🔧 วิธีจัดการสถานี / Threshold</h3>
                <ol>
                    <li><strong>Sync สถานี</strong> — กดปุ่ม "🔄 Sync สถานี" ในแท็บ 📡 จุดตรวจวัด
                        เพื่อดึงรายการสถานีล่าสุดจาก API</li>
                    <li><strong>เปิด/ปิดสถานี</strong> — กดปุ่ม 🔔/🔕 เพื่อเปิดหรือปิดการแจ้งเตือนของแต่ละสถานี</li>
                    <li><strong>ตั้งค่า Threshold</strong> — กรอกค่า % ที่ต้องการ (1–100) แล้วกดบันทึก
                        เมื่อระดับน้ำเกินค่านี้ระบบจะส่งแจ้งเตือน</li>
                    <li><strong>ตั้งความถี่แจ้งเตือน</strong> — เลือกระยะเวลา cooldown ของแต่ละสถานี (10 นาที ถึง 1 วัน)
                        หลังจากส่งแจ้งเตือนแล้ว ระบบจะไม่ส่งซ้ำจนกว่าจะครบเวลาที่กำหนด</li>
                    <li><strong>เพิ่ม/ลบกลุ่ม LINE</strong> — เลือกกลุ่มจาก dropdown เพื่อเพิ่ม หรือกด ✕
                        เพื่อลบกลุ่มออกจากสถานี</li>
                </ol>
                <div class="guide-tip">
                    <strong>💡 Tips:</strong> ค่า threshold ที่แนะนำคือ 80% สำหรับการเตือนล่วงหน้า หรือ 70%
                    หากต้องการเตือนเร็วขึ้น
                </div>
            </div>

            <!-- รายงานสรุป -->
            <div class="guide-card">
                <h3>📊 รายงานสรุป (Daily Summary)</h3>
                <p>ระบบสามารถส่งรายงานสรุประดับน้ำทุกสถานีที่เปิดใช้งาน เป็น Flex Message ตารางไปยังกลุ่ม LINE ที่กำหนด
                </p>
                <ol>
                    <li>ไปที่แท็บ <strong>📡 จุดตรวจวัด</strong> → ส่วน "📊 รายงานสรุป" ด้านล่าง</li>
                    <li>เลือกกลุ่ม LINE ที่จะรับรายงาน แล้วกด "+ เพิ่ม"</li>
                    <li>เปิด/ปิดรายงานสำหรับแต่ละกลุ่มได้</li>
                </ol>
                <div class="guide-tip">
                    <strong>💡 Crontab:</strong> ตั้ง cron สำหรับส่งรายงานสรุปเวลา 08:00 และ 16:00:<br>
                    <code>0 8,16 * * * php /path/to/cron_flood_summary.php</code>
                </div>
            </div>

            <!-- Crontab -->
            <div class="guide-card">
                <h3>⏰ การตั้ง Crontab</h3>
                <pre class="flow-diagram">
# เช็คแจ้งเตือนทุก 10 นาที
*/10 * * * * php /path/to/cron_flood.php >> /path/to/logs/cron.log 2>&1

# รายงานสรุป 08:00 และ 16:00
0 8,16 * * * php /path/to/cron_flood_summary.php >> /path/to/logs/cron_summary.log 2>&1
</pre>
            </div>

        </div>
        <?php endif ?>

    </div>
</body>

</html>