<?php
/**
 * Shared database connection.
 * Uses DATABASE_URL env var on Render (PostgreSQL), falls back to local MySQL.
 */

date_default_timezone_set('Asia/Manila');

function getDB() {
    $databaseUrl = getenv('DATABASE_URL');

    if ($databaseUrl) {
        // Render PostgreSQL: postgres://user:pass@host[:port]/dbname
        $params = parse_url($databaseUrl);
        $host = $params['host'];
        $port = isset($params['port']) ? $params['port'] : 5432;
        $dbname = ltrim($params['path'], '/');
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
        $pdo = new PDO($dsn, $params['user'], $params['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET timezone = 'Asia/Manila'");
    } else {
        // Local MySQL (XAMPP)
        $dsn = "mysql:host=localhost;dbname=iot_logs;charset=utf8mb4";
        $pdo = new PDO($dsn, 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET time_zone = '+08:00'");
    }

    return $pdo;
}

function isPostgres() {
    return (bool) getenv('DATABASE_URL');
}

function initTables($pdo) {
    if (isPostgres()) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS temperature_reading (
            id SERIAL PRIMARY KEY,
            temperature REAL NOT NULL,
            humidity REAL NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS stable_reading (
            id SERIAL PRIMARY KEY,
            temperature REAL NOT NULL,
            humidity REAL NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'STABLE',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS warning_reading (
            id SERIAL PRIMARY KEY,
            temperature REAL NOT NULL,
            humidity REAL NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'WARNING',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS critical_reading (
            id SERIAL PRIMARY KEY,
            temperature REAL NOT NULL,
            humidity REAL NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'CRITICAL',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS low_reading (
            id SERIAL PRIMARY KEY,
            temperature REAL NOT NULL,
            humidity REAL NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'LOW',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS temperature_reading (
            id INT AUTO_INCREMENT PRIMARY KEY,
            temperature FLOAT NOT NULL,
            humidity FLOAT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS stable_reading (
            id INT AUTO_INCREMENT PRIMARY KEY,
            temperature FLOAT NOT NULL,
            humidity FLOAT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'STABLE',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS warning_reading (
            id INT AUTO_INCREMENT PRIMARY KEY,
            temperature FLOAT NOT NULL,
            humidity FLOAT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'WARNING',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS critical_reading (
            id INT AUTO_INCREMENT PRIMARY KEY,
            temperature FLOAT NOT NULL,
            humidity FLOAT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'CRITICAL',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS low_reading (
            id INT AUTO_INCREMENT PRIMARY KEY,
            temperature FLOAT NOT NULL,
            humidity FLOAT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'LOW',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    }
}

function autoCleanup($pdo) {
    if (isPostgres()) {
        $monthTables = ['temperature_reading', 'stable_reading'];
        foreach ($monthTables as $tbl) {
            $pdo->exec("DELETE FROM {$tbl} WHERE created_at < NOW() - INTERVAL '1 month'");
        }
        $capTables = ['warning_reading', 'critical_reading', 'low_reading'];
        foreach ($capTables as $tbl) {
            $row = $pdo->query("SELECT COUNT(*) as cnt FROM {$tbl}")->fetch();
            if ($row['cnt'] > 5000) {
                $excess = $row['cnt'] - 5000;
                $pdo->exec("DELETE FROM {$tbl} WHERE id IN (SELECT id FROM {$tbl} ORDER BY id ASC LIMIT {$excess})");
            }
        }
    } else {
        $monthTables = ['temperature_reading', 'stable_reading'];
        foreach ($monthTables as $tbl) {
            $pdo->exec("DELETE FROM `{$tbl}` WHERE created_at < NOW() - INTERVAL 1 MONTH");
        }
        $capTables = ['warning_reading', 'critical_reading', 'low_reading'];
        foreach ($capTables as $tbl) {
            $row = $pdo->query("SELECT COUNT(*) as cnt FROM `{$tbl}`")->fetch();
            if ($row['cnt'] > 5000) {
                $excess = $row['cnt'] - 5000;
                $pdo->exec("DELETE FROM `{$tbl}` ORDER BY id ASC LIMIT {$excess}");
            }
        }
    }
}

function runCleanupIfNeeded($pdo) {
    $cleanupFile = sys_get_temp_dir() . '/iot_cleanup_last.txt';
    $lastCleanup = file_exists($cleanupFile) ? (int)file_get_contents($cleanupFile) : 0;
    if (time() - $lastCleanup > 3600) {
        autoCleanup($pdo);
        file_put_contents($cleanupFile, time());
    }
}
