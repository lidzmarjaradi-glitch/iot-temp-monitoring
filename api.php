<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db.php';

$pdo = getDB();
initTables($pdo);
runCleanupIfNeeded($pdo);

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {

    // ── Combined dashboard endpoint (single call for all live data) ──
    case 'dashboard':
        $result = ['server_time' => date('Y-m-d H:i:s')];

        // Latest reading
        $row = $pdo->query("SELECT * FROM temperature_reading ORDER BY id DESC LIMIT 1")->fetch();
        if ($row && isset($row['created_at']) && strlen($row['created_at']) > 19) {
            $row['created_at'] = substr($row['created_at'], 0, 19);
        }
        $result['latest'] = $row ?: null;

        // Stats — use cached aggregate, refreshed every 30s
        $statsCacheFile = sys_get_temp_dir() . '/iot_stats_cache.json';
        $statsCache = null;
        if (file_exists($statsCacheFile)) {
            $cached = json_decode(file_get_contents($statsCacheFile), true);
            if ($cached && isset($cached['_ts']) && (time() - $cached['_ts']) < 30) {
                $statsCache = $cached;
                unset($statsCache['_ts']);
            }
        }
        if ($statsCache) {
            $result['stats'] = $statsCache;
        } else {
            if (isPostgres()) {
                // Use subquery with id-based range for speed (avoids full table scan)
                $maxIdRow = $pdo->query("SELECT MAX(id) as mid FROM temperature_reading")->fetch();
                $maxId = $maxIdRow ? (int)$maxIdRow['mid'] : 0;
                $countRow = $pdo->query("SELECT COUNT(*) as total_records FROM temperature_reading")->fetch();
                // Stats from recent ~8640 rows (approx 1 day at 10s intervals) for fast aggregation
                $recentLimit = max(1, $maxId - 8640);
                $statsRow = $pdo->query("SELECT 
                    ROUND(AVG(temperature)::numeric, 1) as avg_temp,
                    ROUND(MIN(temperature)::numeric, 1) as min_temp,
                    ROUND(MAX(temperature)::numeric, 1) as max_temp,
                    ROUND(AVG(humidity)::numeric, 1) as avg_hum,
                    ROUND(MIN(humidity)::numeric, 1) as min_hum,
                    ROUND(MAX(humidity)::numeric, 1) as max_hum
                    FROM temperature_reading WHERE id > {$recentLimit}")->fetch();
                $result['stats'] = array_merge(['total_records' => $countRow['total_records']], $statsRow ?: []);
            } else {
                $maxIdRow = $pdo->query("SELECT MAX(id) as mid FROM temperature_reading")->fetch();
                $maxId = $maxIdRow ? (int)$maxIdRow['mid'] : 0;
                $countRow = $pdo->query("SELECT COUNT(*) as total_records FROM temperature_reading")->fetch();
                $recentLimit = max(1, $maxId - 8640);
                $statsRow = $pdo->query("SELECT 
                    ROUND(AVG(temperature), 1) as avg_temp,
                    ROUND(MIN(temperature), 1) as min_temp,
                    ROUND(MAX(temperature), 1) as max_temp,
                    ROUND(AVG(humidity), 1) as avg_hum,
                    ROUND(MIN(humidity), 1) as min_hum,
                    ROUND(MAX(humidity), 1) as max_hum
                    FROM temperature_reading WHERE id > {$recentLimit}")->fetch();
                $result['stats'] = array_merge(['total_records' => $countRow['total_records']], $statsRow ?: []);
            }
            $toCache = $result['stats'];
            $toCache['_ts'] = time();
            file_put_contents($statsCacheFile, json_encode($toCache));
        }

        // Device status
        try {
            $ds = $pdo->query("SELECT status, last_seen FROM device_status WHERE id = 1")->fetch();
            if ($ds) {
                $lastSeen = strtotime($ds['last_seen']);
                $result['device_online'] = ($ds['status'] === 'online' && (time() - $lastSeen) <= 30);
                $result['device_last_seen'] = substr($ds['last_seen'], 0, 19);
            } else {
                $result['device_online'] = false;
            }
        } catch (Exception $e) {
            $result['device_online'] = false;
        }

        echo json_encode($result);
        break;

    // Get latest reading
    case 'latest':
        $row = $pdo->query("SELECT * FROM temperature_reading ORDER BY id DESC LIMIT 1")->fetch();
        if ($row) {
            $row['server_time'] = date('Y-m-d H:i:s');
            // Normalize PostgreSQL timestamp (strips microseconds)
            if (isset($row['created_at']) && strlen($row['created_at']) > 19) {
                $row['created_at'] = substr($row['created_at'], 0, 19);
            }
        }
        // Attach device status from heartbeat table
        $result = $row ?: ["temperature" => null, "humidity" => null];
        try {
            $ds = $pdo->query("SELECT status, last_seen FROM device_status WHERE id = 1")->fetch();
            if ($ds) {
                $lastSeen = strtotime($ds['last_seen']);
                $now = time();
                $result['device_online'] = ($ds['status'] === 'online' && ($now - $lastSeen) <= 30);
                $result['device_last_seen'] = substr($ds['last_seen'], 0, 19);
            } else {
                $result['device_online'] = false;
                $result['device_last_seen'] = null;
            }
        } catch (Exception $e) {
            $result['device_online'] = false;
            $result['device_last_seen'] = null;
        }
        $result['server_time'] = date('Y-m-d H:i:s');
        echo json_encode($result);
        break;

    // Get device status (heartbeat-based)
    case 'device_status':
        try {
            $ds = $pdo->query("SELECT status, last_seen FROM device_status WHERE id = 1")->fetch();
            if ($ds) {
                $lastSeen = strtotime($ds['last_seen']);
                $now = time();
                $online = ($ds['status'] === 'online' && ($now - $lastSeen) <= 30);
                echo json_encode([
                    'device_online' => $online,
                    'last_seen' => substr($ds['last_seen'], 0, 19),
                    'server_time' => date('Y-m-d H:i:s')
                ]);
            } else {
                echo json_encode([
                    'device_online' => false,
                    'last_seen' => null,
                    'server_time' => date('Y-m-d H:i:s')
                ]);
            }
        } catch (Exception $e) {
            echo json_encode([
                'device_online' => false,
                'last_seen' => null,
                'server_time' => date('Y-m-d H:i:s')
            ]);
        }
        break;

    // Get all records (with optional limit)
    case 'all':
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
        $stmt = $pdo->query("SELECT * FROM temperature_reading ORDER BY id DESC LIMIT {$limit}");
        echo json_encode($stmt->fetchAll());
        break;

    // ── Chart data endpoints ──
    case 'chart':
        $view = isset($_GET['view']) ? $_GET['view'] : 'realtime';
        $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

        if ($view === 'realtime') {
            $data = $pdo->query("SELECT temperature, humidity, created_at
                FROM temperature_reading ORDER BY id DESC LIMIT 100")->fetchAll();
            echo json_encode(array_reverse($data));

        } elseif ($view === 'day') {
            // Aggregate to 5-minute averages (~288 points per day)
            if (isPostgres()) {
                $stmt = $pdo->prepare("SELECT 
                    ROUND(AVG(temperature)::numeric, 2) as temperature,
                    ROUND(AVG(humidity)::numeric, 2) as humidity,
                    date_trunc('hour', created_at) + 
                        (EXTRACT(minute FROM created_at)::int / 5) * INTERVAL '5 minutes' as created_at
                    FROM temperature_reading 
                    WHERE DATE(created_at) = ?
                    GROUP BY date_trunc('hour', created_at) + 
                        (EXTRACT(minute FROM created_at)::int / 5) * INTERVAL '5 minutes'
                    ORDER BY created_at ASC");
            } else {
                $stmt = $pdo->prepare("SELECT 
                    ROUND(AVG(temperature), 2) as temperature,
                    ROUND(AVG(humidity), 2) as humidity,
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:') as h,
                    LPAD(FLOOR(MINUTE(created_at)/5)*5, 2, '0') as m,
                    CONCAT(DATE_FORMAT(created_at, '%Y-%m-%d %H:'), LPAD(FLOOR(MINUTE(created_at)/5)*5, 2, '0'), ':00') as created_at
                    FROM temperature_reading 
                    WHERE DATE(created_at) = ?
                    GROUP BY h, m
                    ORDER BY created_at ASC");
            }
            $stmt->execute([$date]);
            $readings = $stmt->fetchAll();

            $stmtStats = $pdo->prepare("SELECT 
                ROUND(AVG(temperature)::numeric, 1) as avg_temp,
                ROUND(MIN(temperature)::numeric, 1) as min_temp,
                ROUND(MAX(temperature)::numeric, 1) as max_temp,
                ROUND(AVG(humidity)::numeric, 1) as avg_hum,
                ROUND(MIN(humidity)::numeric, 1) as min_hum,
                ROUND(MAX(humidity)::numeric, 1) as max_hum,
                COUNT(*) as total_readings
                FROM temperature_reading WHERE DATE(created_at) = ?");
            if (!isPostgres()) {
                $stmtStats = $pdo->prepare("SELECT 
                    ROUND(AVG(temperature), 1) as avg_temp,
                    ROUND(MIN(temperature), 1) as min_temp,
                    ROUND(MAX(temperature), 1) as max_temp,
                    ROUND(AVG(humidity), 1) as avg_hum,
                    ROUND(MIN(humidity), 1) as min_hum,
                    ROUND(MAX(humidity), 1) as max_hum,
                    COUNT(*) as total_readings
                    FROM temperature_reading WHERE DATE(created_at) = ?");
            }
            $stmtStats->execute([$date]);
            echo json_encode(['readings' => $readings, 'summary' => $stmtStats->fetch()]);

        } elseif ($view === 'week') {
            // Aggregate to hourly averages (~168 points for 7 days)
            if (isPostgres()) {
                $data = $pdo->query("SELECT 
                    ROUND(AVG(temperature)::numeric, 2) as temperature,
                    ROUND(AVG(humidity)::numeric, 2) as humidity,
                    date_trunc('hour', created_at) as created_at
                    FROM temperature_reading 
                    WHERE created_at >= CURRENT_DATE - INTERVAL '6 days'
                    GROUP BY date_trunc('hour', created_at)
                    ORDER BY created_at ASC")->fetchAll();
            } else {
                $data = $pdo->query("SELECT 
                    ROUND(AVG(temperature), 2) as temperature,
                    ROUND(AVG(humidity), 2) as humidity,
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as created_at
                    FROM temperature_reading 
                    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                    GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')
                    ORDER BY created_at ASC")->fetchAll();
            }
            echo json_encode($data);

        } elseif ($view === 'month') {
            $comparePrev = isset($_GET['compare']) && $_GET['compare'] === '1';

            // Aggregate to hourly averages (~720 points for 30 days)
            if (isPostgres()) {
                $current = $pdo->query("SELECT 
                    ROUND(AVG(temperature)::numeric, 2) as temperature,
                    ROUND(AVG(humidity)::numeric, 2) as humidity,
                    date_trunc('hour', created_at) as created_at
                    FROM temperature_reading 
                    WHERE created_at >= CURRENT_DATE - INTERVAL '29 days'
                    GROUP BY date_trunc('hour', created_at)
                    ORDER BY created_at ASC")->fetchAll();
            } else {
                $current = $pdo->query("SELECT 
                    ROUND(AVG(temperature), 2) as temperature,
                    ROUND(AVG(humidity), 2) as humidity,
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as created_at
                    FROM temperature_reading 
                    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                    GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')
                    ORDER BY created_at ASC")->fetchAll();
            }
            $response = ['current' => $current];

            if ($comparePrev) {
                if (isPostgres()) {
                    $prev = $pdo->query("SELECT 
                        ROUND(AVG(temperature)::numeric, 2) as temperature,
                        ROUND(AVG(humidity)::numeric, 2) as humidity,
                        date_trunc('hour', created_at) as created_at
                        FROM temperature_reading 
                        WHERE created_at >= CURRENT_DATE - INTERVAL '59 days'
                          AND created_at < CURRENT_DATE - INTERVAL '29 days'
                        GROUP BY date_trunc('hour', created_at)
                        ORDER BY created_at ASC")->fetchAll();
                } else {
                    $prev = $pdo->query("SELECT 
                        ROUND(AVG(temperature), 2) as temperature,
                        ROUND(AVG(humidity), 2) as humidity,
                        DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as created_at
                        FROM temperature_reading 
                        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 59 DAY)
                          AND created_at < DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                        GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')
                        ORDER BY created_at ASC")->fetchAll();
                }
                $response['previous'] = $prev;
            }
            echo json_encode($response);
        }
        break;

    // Get summary stats
    case 'stats':
        if (isPostgres()) {
            $row = $pdo->query("SELECT 
                COUNT(*) as total_records,
                ROUND(AVG(temperature)::numeric, 1) as avg_temp,
                ROUND(MIN(temperature)::numeric, 1) as min_temp,
                ROUND(MAX(temperature)::numeric, 1) as max_temp,
                ROUND(AVG(humidity)::numeric, 1) as avg_hum,
                ROUND(MIN(humidity)::numeric, 1) as min_hum,
                ROUND(MAX(humidity)::numeric, 1) as max_hum
                FROM temperature_reading")->fetch();
        } else {
            $row = $pdo->query("SELECT 
                COUNT(*) as total_records,
                ROUND(AVG(temperature), 1) as avg_temp,
                ROUND(MIN(temperature), 1) as min_temp,
                ROUND(MAX(temperature), 1) as max_temp,
                ROUND(AVG(humidity), 1) as avg_hum,
                ROUND(MIN(humidity), 1) as min_hum,
                ROUND(MAX(humidity), 1) as max_hum
                FROM temperature_reading")->fetch();
        }
        echo json_encode($row);
        break;

    // Delete single record
    case 'delete':
        if (isset($_GET['id'])) {
            $pdo->prepare("DELETE FROM temperature_reading WHERE id = ?")->execute([intval($_GET['id'])]);
            echo json_encode(["success" => true, "message" => "Record deleted"]);
        } else {
            echo json_encode(["error" => "No ID provided"]);
        }
        break;

    // Delete all records
    case 'truncate':
        if (isPostgres()) {
            $pdo->exec("TRUNCATE TABLE temperature_reading RESTART IDENTITY");
        } else {
            $pdo->exec("TRUNCATE TABLE temperature_reading");
        }
        echo json_encode(["success" => true, "message" => "All records deleted"]);
        break;

    // ── Stable Reading Endpoints ──
    case 'stable':
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
        $stmt = $pdo->query("SELECT * FROM stable_reading ORDER BY id DESC LIMIT {$limit}");
        echo json_encode($stmt->fetchAll());
        break;

    case 'stable_stats':
        echo json_encode($pdo->query("SELECT COUNT(*) as total FROM stable_reading")->fetch());
        break;

    case 'delete_stable':
        if (isset($_GET['id'])) {
            $pdo->prepare("DELETE FROM stable_reading WHERE id = ?")->execute([intval($_GET['id'])]);
            echo json_encode(["success" => true, "message" => "Record deleted"]);
        } else {
            echo json_encode(["error" => "No ID provided"]);
        }
        break;

    case 'truncate_stable':
        if (isPostgres()) {
            $pdo->exec("TRUNCATE TABLE stable_reading RESTART IDENTITY");
        } else {
            $pdo->exec("TRUNCATE TABLE stable_reading");
        }
        echo json_encode(["success" => true, "message" => "All stable readings deleted"]);
        break;

    // ── Warning Reading Endpoints ──
    case 'warning':
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
        $stmt = $pdo->query("SELECT * FROM warning_reading ORDER BY id DESC LIMIT {$limit}");
        echo json_encode($stmt->fetchAll());
        break;

    case 'warning_stats':
        echo json_encode($pdo->query("SELECT COUNT(*) as total FROM warning_reading")->fetch());
        break;

    case 'delete_warning':
        if (isset($_GET['id'])) {
            $pdo->prepare("DELETE FROM warning_reading WHERE id = ?")->execute([intval($_GET['id'])]);
            echo json_encode(["success" => true, "message" => "Record deleted"]);
        } else {
            echo json_encode(["error" => "No ID provided"]);
        }
        break;

    case 'truncate_warning':
        if (isPostgres()) {
            $pdo->exec("TRUNCATE TABLE warning_reading RESTART IDENTITY");
        } else {
            $pdo->exec("TRUNCATE TABLE warning_reading");
        }
        echo json_encode(["success" => true, "message" => "All warning readings deleted"]);
        break;

    // ── Critical Reading Endpoints ──
    case 'critical':
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
        $stmt = $pdo->query("SELECT * FROM critical_reading ORDER BY id DESC LIMIT {$limit}");
        echo json_encode($stmt->fetchAll());
        break;

    case 'critical_stats':
        echo json_encode($pdo->query("SELECT COUNT(*) as total FROM critical_reading")->fetch());
        break;

    case 'delete_critical':
        if (isset($_GET['id'])) {
            $pdo->prepare("DELETE FROM critical_reading WHERE id = ?")->execute([intval($_GET['id'])]);
            echo json_encode(["success" => true, "message" => "Record deleted"]);
        } else {
            echo json_encode(["error" => "No ID provided"]);
        }
        break;

    case 'truncate_critical':
        if (isPostgres()) {
            $pdo->exec("TRUNCATE TABLE critical_reading RESTART IDENTITY");
        } else {
            $pdo->exec("TRUNCATE TABLE critical_reading");
        }
        echo json_encode(["success" => true, "message" => "All critical readings deleted"]);
        break;

    // ── Low Reading Endpoints ──
    case 'low':
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
        $stmt = $pdo->query("SELECT * FROM low_reading ORDER BY id DESC LIMIT {$limit}");
        echo json_encode($stmt->fetchAll());
        break;

    case 'low_stats':
        echo json_encode($pdo->query("SELECT COUNT(*) as total FROM low_reading")->fetch());
        break;

    case 'delete_low':
        if (isset($_GET['id'])) {
            $pdo->prepare("DELETE FROM low_reading WHERE id = ?")->execute([intval($_GET['id'])]);
            echo json_encode(["success" => true, "message" => "Record deleted"]);
        } else {
            echo json_encode(["error" => "No ID provided"]);
        }
        break;

    case 'truncate_low':
        if (isPostgres()) {
            $pdo->exec("TRUNCATE TABLE low_reading RESTART IDENTITY");
        } else {
            $pdo->exec("TRUNCATE TABLE low_reading");
        }
        echo json_encode(["success" => true, "message" => "All low readings deleted"]);
        break;

    // Export temperature readings as CSV
    case 'export':
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="temperature_reading_' . date('Y-m-d_H-i-s') . '.csv"');
        $result = $pdo->query("SELECT * FROM temperature_reading ORDER BY id ASC");
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Temperature (°C)', 'Humidity (%)', 'Timestamp']);
        while ($row = $result->fetch()) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;

    default:
        echo json_encode(["error" => "Invalid action"]);
        break;
}
?>
