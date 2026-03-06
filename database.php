<?php

require_once __DIR__ . '/config.php';

/**
 * สร้าง/เชื่อมต่อ MySQL database พร้อม auto-create tables
 */
function getDB(): PDO
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $db = new PDO($dsn, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $db->exec('CREATE TABLE IF NOT EXISTS station_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id VARCHAR(50) UNIQUE,
        station_name VARCHAR(255),
        uri VARCHAR(100),
        enabled TINYINT DEFAULT 1,
        group_ids TEXT,
        threshold DOUBLE DEFAULT 80,
        alert_interval INT DEFAULT 60,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Migrate: add alert_interval column if missing
    try {
        $db->exec('ALTER TABLE station_config ADD COLUMN alert_interval INT DEFAULT 60 AFTER threshold');
    } catch (PDOException $e) {
        // Column already exists — ignore
    }

    $db->exec('CREATE TABLE IF NOT EXISTS line_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id VARCHAR(100) UNIQUE,
        group_name VARCHAR(255) DEFAULT "",
        joined_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // seed กลุ่มเริ่มต้นจาก config (ถ้ายังไม่มี)
    $stmt = $db->prepare('INSERT IGNORE INTO line_groups (group_id, group_name) VALUES (?, ?)');
    $stmt->execute([LINE_GROUP_ID, 'กลุ่มหลัก (default)']);

    $db->exec('CREATE TABLE IF NOT EXISTS summary_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id VARCHAR(100),
        enabled TINYINT DEFAULT 1,
        UNIQUE KEY (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS alert_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id VARCHAR(50),
        percent DOUBLE,
        alerted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_station_alerted (station_id, alerted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS alert_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id VARCHAR(50),
        threshold DOUBLE NOT NULL,
        alert_interval INT NOT NULL DEFAULT 60,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_station_threshold (station_id, threshold)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    return $db;
}
if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    try {
        $db = getDB();
        echo "MySQL connected successfully.\n";
        echo "Database: " . DB_NAME . " @ " . DB_HOST . "\n";

        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Tables: " . implode(', ', $tables) . "\n";
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
}
