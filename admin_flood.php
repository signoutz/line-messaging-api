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

// --- Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['password']) && !isset($_POST['logout'])) {
    $action = $_POST['action'] ?? '';

    // Determine which tab to redirect back to
    $redirectTab = $currentTab;
    if (in_array($action, ['sync', 'toggle', 'threshold', 'add_group', 'remove_group'])) {
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
  <title>Flood Alert Admin — Login</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
  <div class="card shadow" style="width:340px">
    <div class="card-body p-4">
      <h5 class="mb-3 text-center">🔒 Flood Alert Admin</h5>
      <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
      <?php endif ?>
      <form method="post">
        <div class="mb-3">
          <input type="password" name="password" class="form-control" placeholder="รหัสผ่าน" autofocus required>
        </div>
        <button class="btn btn-primary w-100">เข้าสู่ระบบ</button>
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
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    body { font-size: .95rem; background: #f5f7fa; }
    .table td, .table th { vertical-align: middle; }
    .group-id { font-family: monospace; font-size: .85rem; word-break: break-all; }
    .nav-pills .nav-link { color: #495057; border-radius: .5rem; font-weight: 500; }
    .nav-pills .nav-link.active { background: #0d6efd; color: #fff; }
    .nav-pills .nav-link:not(.active):hover { background: #e9ecef; }
    .card { border: none; border-radius: .75rem; }
    .card-header { border-radius: .75rem .75rem 0 0 !important; }
    .top-bar { background: #fff; border-bottom: 1px solid #dee2e6; }
    .guide-section { max-width: 800px; }
    .guide-section h5 { color: #0d6efd; border-bottom: 2px solid #e9ecef; padding-bottom: .5rem; }
    pre.flow-diagram { background: #1e293b; color: #e2e8f0; border-radius: .5rem; padding: 1.25rem; font-size: .85rem; line-height: 1.6; overflow-x: auto; }
    .severity-flag { font-size: 2.5rem; line-height: 1; }
    .severity-label { font-weight: 700; font-size: 1.1rem; }
    .severity-table td { padding: 1rem 1.25rem; border-bottom: 1px solid #eee; vertical-align: middle; }
    .severity-table th { padding: .75rem 1.25rem; font-weight: 500; color: #6b7280; border-bottom: 2px solid #e5e7eb; }
  </style>
</head>
<body>

<!-- Top Bar -->
<div class="top-bar px-3 py-2 mb-3">
  <div class="container-fluid d-flex align-items-center justify-content-between">
    <h5 class="mb-0 fw-bold">🌊 Flood Alert System</h5>
    <form method="post" class="d-inline">
      <button name="logout" class="btn btn-sm btn-outline-secondary">ออกจากระบบ</button>
    </form>
  </div>
</div>

<div class="container-fluid px-3">

  <?php if ($message): ?>
    <div class="alert alert-info alert-dismissible py-2 mb-3">
      <?= htmlspecialchars($message) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif ?>

  <!-- Tab Navigation -->
  <ul class="nav nav-pills mb-3 gap-1">
    <li class="nav-item">
      <a class="nav-link <?= isTab('stations') ? 'active' : '' ?>" href="<?= tabUrl('stations') ?>">📡 จุดตรวจวัด</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= isTab('groups') ? 'active' : '' ?>" href="<?= tabUrl('groups') ?>">💬 กลุ่ม LINE</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= isTab('history') ? 'active' : '' ?>" href="<?= tabUrl('history') ?>">🔔 ประวัติแจ้งเตือน</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= isTab('guide') ? 'active' : '' ?>" href="<?= tabUrl('guide') ?>">📖 คู่มือ</a>
    </li>
  </ul>

  <!-- ============================================================ -->
  <!-- TAB: STATIONS -->
  <!-- ============================================================ -->
  <?php if (isTab('stations')): ?>
  <div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
      <strong>📋 รายการสถานี (<?= count($stations) ?>)</strong>
      <form method="post" action="<?= tabUrl('stations') ?>" class="d-inline">
        <button name="action" value="sync" class="btn btn-sm btn-success" onclick="return confirm('Sync สถานีจาก API?')">🔄 Sync สถานี</button>
      </form>
    </div>
    <?php if ($stations): ?>
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>สถานี</th>
            <th>URI</th>
            <th style="width:100px">ระดับน้ำ (ม.)</th>
            <th style="width:100px">ขอบตลิ่ง (ม.)</th>
            <th style="width:70px">%</th>
            <th style="width:90px">สถานะ</th>
            <th style="width:120px">Threshold %</th>
            <th>LINE Group IDs</th>
            <th style="width:150px">อัปเดตล่าสุด</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($stations as $s): ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($s['station_name']) ?></strong>
              <br><small class="text-muted">ID: <?= htmlspecialchars($s['station_id']) ?></small>
            </td>
            <td>
              <?php if ($s['uri']): ?>
                <a href="https://www.cmuccdc.org/floodboy/<?= htmlspecialchars($s['uri']) ?>" target="_blank">
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
                  $pctClass = $pct >= 100 ? 'text-danger fw-bold' : ($pct > $s['threshold'] ? 'text-warning fw-bold' : 'text-success');
            ?>
            <td class="text-end"><?= number_format($live['water_level'], 2) ?></td>
            <td class="text-end"><?= number_format($live['bank_level'], 2) ?></td>
            <td class="text-end"><span class="<?= $pctClass ?>"><?= $pct ?>%</span></td>
            <?php else: ?>
            <td class="text-muted text-center">-</td>
            <td class="text-muted text-center">-</td>
            <td class="text-muted text-center">-</td>
            <?php endif ?>
            <td>
              <form method="post" action="<?= tabUrl('stations') ?>" class="d-inline">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="station_id" value="<?= htmlspecialchars($s['station_id']) ?>">
                <input type="hidden" name="enabled" value="<?= $s['enabled'] ? 0 : 1 ?>">
                <button class="btn btn-sm <?= $s['enabled'] ? 'btn-success' : 'btn-outline-secondary' ?>">
                  <?= $s['enabled'] ? '🔔 เปิด' : '🔕 ปิด' ?>
                </button>
              </form>
            </td>
            <td>
              <form method="post" action="<?= tabUrl('stations') ?>" class="d-flex gap-1">
                <input type="hidden" name="action" value="threshold">
                <input type="hidden" name="station_id" value="<?= htmlspecialchars($s['station_id']) ?>">
                <input type="number" name="threshold" value="<?= $s['threshold'] ?>" min="1" max="100" step="1" class="form-control form-control-sm" style="width:70px">
                <button class="btn btn-sm btn-outline-primary">บันทึก</button>
              </form>
            </td>
            <td>
              <?php
              $groups = json_decode($s['group_ids'] ?? '[]', true) ?: [];
              foreach ($groups as $g):
                  $gLabel = $groupNameMap[$g] ?? substr($g, 0, 10) . '...';
              ?>
                <div class="d-flex align-items-center gap-1 mb-1">
                  <span class="group-id" title="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($gLabel) ?></span>
                  <form method="post" action="<?= tabUrl('stations') ?>" class="d-inline">
                    <input type="hidden" name="action" value="remove_group">
                    <input type="hidden" name="station_id" value="<?= htmlspecialchars($s['station_id']) ?>">
                    <input type="hidden" name="remove_group" value="<?= htmlspecialchars($g) ?>">
                    <button class="btn btn-sm btn-outline-danger py-0 px-1" title="ลบ" onclick="return confirm('ลบกลุ่มนี้?')">✕</button>
                  </form>
                </div>
              <?php endforeach ?>
              <?php if ($lineGroups): ?>
              <form method="post" action="<?= tabUrl('stations') ?>" class="d-flex gap-1 mt-1">
                <input type="hidden" name="action" value="add_group">
                <input type="hidden" name="station_id" value="<?= htmlspecialchars($s['station_id']) ?>">
                <select name="new_group" class="form-select form-select-sm" style="width:160px">
                  <option value="">-- เพิ่มกลุ่ม --</option>
                  <?php foreach ($lineGroups as $lg):
                      $alreadyAdded = in_array($lg['group_id'], $groups);
                      $label = $lg['group_name'] ?: substr($lg['group_id'], 0, 15) . '...';
                  ?>
                    <option value="<?= htmlspecialchars($lg['group_id']) ?>" <?= $alreadyAdded ? 'disabled' : '' ?>>
                      <?= $alreadyAdded ? '✓ ' : '' ?><?= htmlspecialchars($label) ?>
                    </option>
                  <?php endforeach ?>
                </select>
                <button class="btn btn-sm btn-outline-success py-0">+</button>
              </form>
              <?php endif ?>
            </td>
            <td><small class="text-muted"><?= htmlspecialchars($s['updated_at'] ?? '') ?></small></td>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="card-body">
      <p class="text-muted mb-0">ยังไม่มีสถานี — กดปุ่ม "Sync สถานี" เพื่อดึงข้อมูลจาก API</p>
    </div>
    <?php endif ?>
  </div>

  <!-- ============================================================ -->
  <!-- TAB: LINE GROUPS -->
  <!-- ============================================================ -->
  <?php elseif (isTab('groups')): ?>
  <div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
      <strong>💬 กลุ่ม LINE ที่ Bot อยู่ (<?= count($lineGroups) ?>)</strong>
      <div class="d-flex gap-1">
        <form method="post" action="<?= tabUrl('groups') ?>" class="d-inline">
          <button name="action" value="scan_logs" class="btn btn-sm btn-outline-primary">📂 Scan จาก Log</button>
        </form>
        <form method="post" action="<?= tabUrl('groups') ?>" class="d-inline">
          <button name="action" value="refresh_groups" class="btn btn-sm btn-outline-primary">🔄 ดึงชื่อกลุ่ม</button>
        </form>
      </div>
    </div>
    <?php if ($lineGroups): ?>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>Group ID</th>
            <th>ชื่อกลุ่ม</th>
            <th>เข้าร่วมเมื่อ</th>
            <th style="width:200px">แก้ไขชื่อ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lineGroups as $lg): ?>
          <tr>
            <td><code class="small"><?= htmlspecialchars($lg['group_id']) ?></code></td>
            <td><?= htmlspecialchars($lg['group_name'] ?: '-') ?></td>
            <td><small class="text-muted"><?= htmlspecialchars($lg['joined_at']) ?></small></td>
            <td>
              <form method="post" action="<?= tabUrl('groups') ?>" class="d-flex gap-1">
                <input type="hidden" name="action" value="rename_group">
                <input type="hidden" name="group_id" value="<?= htmlspecialchars($lg['group_id']) ?>">
                <input type="text" name="group_name" value="<?= htmlspecialchars($lg['group_name']) ?>" placeholder="ตั้งชื่อ" class="form-control form-control-sm">
                <button class="btn btn-sm btn-outline-primary">บันทึก</button>
              </form>
            </td>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="card-body">
      <p class="text-muted mb-0">ยังไม่มีกลุ่ม — เพิ่ม Bot เข้ากลุ่ม LINE แล้วส่งข้อความเพื่อให้ระบบบันทึก Group ID อัตโนมัติ</p>
    </div>
    <?php endif ?>
  </div>

  <!-- ============================================================ -->
  <!-- TAB: HISTORY -->
  <!-- ============================================================ -->
  <?php elseif (isTab('history')): ?>
  <div class="card shadow-sm">
    <div class="card-header bg-white py-2">
      <strong>🔔 ประวัติการแจ้งเตือน (ล่าสุด 50 รายการ)</strong>
    </div>
    <?php if ($recentAlerts): ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>เวลา</th>
            <th>สถานี</th>
            <th>ระดับน้ำ %</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentAlerts as $a): ?>
          <tr>
            <td><small><?= htmlspecialchars($a['alerted_at']) ?></small></td>
            <td><?= htmlspecialchars($a['station_name'] ?? $a['station_id']) ?></td>
            <td>
              <?php
              $pct = $a['percent'];
              $cls = $pct >= 100 ? 'text-danger fw-bold' : 'text-warning';
              ?>
              <span class="<?= $cls ?>"><?= $pct ?>%</span>
            </td>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="card-body">
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
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h5>📌 ภาพรวมระบบ</h5>
        <p>ระบบ Flood Alert เป็นระบบแจ้งเตือนระดับน้ำผ่าน LINE Messaging API โดยดึงข้อมูลจากสถานีตรวจวัดของ CMU CCDC แล้วส่งการแจ้งเตือนไปยังกลุ่ม LINE ที่กำหนด เมื่อระดับน้ำเกินค่า threshold ที่ตั้งไว้</p>
        <ul class="mb-0">
          <li><strong>แหล่งข้อมูล:</strong> CMU CCDC Floodboy API (ข้อมูลระดับน้ำ realtime)</li>
          <li><strong>การแจ้งเตือน:</strong> LINE Messaging API (Flex Message)</li>
          <li><strong>การทำงาน:</strong> Cron job ทำงานตามรอบที่ตั้งไว้</li>
        </ul>
      </div>
    </div>

    <!-- Flow Diagram -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h5>⚙️ Flow การทำงานของ Cron</h5>
<pre class="flow-diagram">
┌──────────────┐
│  Cron Job    │  (ทุก 15 นาที หรือตามที่ตั้งไว้)
│  cron_flood  │
└──────┬───────┘
       │
       ▼
┌──────────────────┐
│  ดึงข้อมูล API   │  GET https://www.cmuccdc.org/api/floodboy/lasted
│  CMU CCDC        │
└──────┬───────────┘
       │
       ▼
┌──────────────────┐
│  ดึง station     │  SELECT * FROM station_config WHERE enabled = 1
│  config จาก DB   │
└──────┬───────────┘
       │
       ▼
┌──────────────────────────────┐
│  วนลูปแต่ละสถานี             │
│                              │
│  คำนวณ % = น้ำ / ตลิ่ง × 100 │
│                              │
│  ถ้า % >= threshold:         │
│    ├─ ตรวจ cooldown (1 ชม.)  │
│    └─ ส่ง Flex Message       │
│       ไปยังกลุ่ม LINE        │
│       + บันทึก alert_log     │
└──────────────────────────────┘
</pre>
      </div>
    </div>

    <!-- ระดับการแจ้งเตือนภัยน้ำท่วม -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h5>🚩 ระดับการแจ้งเตือนภัยน้ำท่วม</h5>
        <table class="severity-table w-100" style="border-collapse:collapse">
          <thead>
            <tr>
              <th style="width:140px">ระดับสี</th>
              <th style="width:200px">ความจุลำน้ำ</th>
              <th>คำแนะนำ</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>
                <div class="severity-flag">🟩</div>
                <div class="severity-label" style="color:#0d9488">ปกติ</div>
              </td>
              <td>&le;80%</td>
              <td>สภาวะน้ำปกติ</td>
            </tr>
            <tr>
              <td>
                <div class="severity-flag">🟨</div>
                <div class="severity-label" style="color:#d97706">เฝ้าระวัง</div>
              </td>
              <td>&gt;80% ถึง &lt;100%</td>
              <td>ติดตามข่าวสารอย่างใกล้ชิด เตรียมพร้อมอพยพ/แจ้งเตือนเมื่อจำเป็น</td>
            </tr>
            <tr>
              <td>
                <div class="severity-flag">🟥</div>
                <div class="severity-label" style="color:#dc2626">วิกฤต</div>
              </td>
              <td>&ge;100%</td>
              <td>ดำเนินการแจ้งเตือนภัยและอพยพประชาชนทันที</td>
            </tr>
          </tbody>
        </table>
        <p class="text-muted small mt-3 mb-0">ที่มา: ศูนย์อุทกวิทยาและการประยุกต์ มหาวิทยาลัยเชียงใหม่</p>
      </div>
    </div>

    <!-- วิธีเพิ่ม Bot เข้ากลุ่ม -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h5>🤖 วิธีเพิ่ม Bot เข้ากลุ่ม LINE</h5>
        <ol>
          <li>เปิดแอป LINE บนมือถือ</li>
          <li>เข้ากลุ่มที่ต้องการ → กดชื่อกลุ่มด้านบน → <strong>เชิญ</strong></li>
          <li>ค้นหาชื่อ Bot แล้วเพิ่มเข้ากลุ่ม</li>
          <li>ส่งข้อความอะไรก็ได้ในกลุ่ม (เพื่อให้ webhook จับ Group ID)</li>
          <li>กลับมาหน้า Admin → แท็บ <strong>💬 กลุ่ม LINE</strong> → กด <strong>"📂 Scan จาก Log"</strong></li>
          <li>กลุ่มใหม่จะปรากฏในรายการ → สามารถเลือกใช้กับสถานีได้</li>
        </ol>
      </div>
    </div>

    <!-- วิธีจัดการสถานี -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h5>🔧 วิธีจัดการสถานี / Threshold</h5>
        <ol>
          <li><strong>Sync สถานี</strong> — กดปุ่ม "🔄 Sync สถานี" ในแท็บ 📡 จุดตรวจวัด เพื่อดึงรายการสถานีล่าสุดจาก API</li>
          <li><strong>เปิด/ปิดสถานี</strong> — กดปุ่ม 🔔/🔕 เพื่อเปิดหรือปิดการแจ้งเตือนของแต่ละสถานี</li>
          <li><strong>ตั้งค่า Threshold</strong> — กรอกค่า % ที่ต้องการ (1–100) แล้วกดบันทึก เมื่อระดับน้ำเกินค่านี้ระบบจะส่งแจ้งเตือน</li>
          <li><strong>เพิ่ม/ลบกลุ่ม LINE</strong> — เลือกกลุ่มจาก dropdown เพื่อเพิ่ม หรือกด ✕ เพื่อลบกลุ่มออกจากสถานี</li>
        </ol>
        <div class="alert alert-light border small mb-0">
          <strong>💡 Tips:</strong> ค่า threshold ที่แนะนำคือ 80% สำหรับการเตือนล่วงหน้า หรือ 70% หากต้องการเตือนเร็วขึ้น
        </div>
      </div>
    </div>

  </div>
  <?php endif ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
