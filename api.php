<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
date_default_timezone_set('Asia/Manila');

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "iot_logs";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(["error" => "Connection failed: " . $conn->connect_error]);
    exit;
}

$conn->query("SET time_zone = '+08:00'");

// Create tables if they don't exist
$conn->query("CREATE TABLE IF NOT EXISTS temperature_reading (
    id INT AUTO_INCREMENT PRIMARY KEY,
    temperature FLOAT NOT NULL,
    humidity FLOAT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS stable_reading (
    id INT AUTO_INCREMENT PRIMARY KEY,
    temperature FLOAT NOT NULL,
    humidity FLOAT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'STABLE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS warning_reading (
    id INT AUTO_INCREMENT PRIMARY KEY,
    temperature FLOAT NOT NULL,
    humidity FLOAT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'WARNING',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS critical_reading (
    id INT AUTO_INCREMENT PRIMARY KEY,
    temperature FLOAT NOT NULL,
    humidity FLOAT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'CRITICAL',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS low_reading (
    id INT AUTO_INCREMENT PRIMARY KEY,
    temperature FLOAT NOT NULL,
    humidity FLOAT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'LOW',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

function renumberIds($conn, $table = 'temperature_reading') {
    $conn->query("SET @num = 0");
    $conn->query("UPDATE `$table` SET id = (@num := @num + 1) ORDER BY id");
    $conn->query("ALTER TABLE `$table` AUTO_INCREMENT = 1");
}

// Auto-cleanup
function autoCleanup($conn) {
    // temperature_reading & stable_reading: delete records older than 1 month
    $monthTables = ['temperature_reading', 'stable_reading'];
    foreach ($monthTables as $tbl) {
        $conn->query("DELETE FROM `$tbl` WHERE created_at < NOW() - INTERVAL 1 MONTH");
    }

    // warning_reading, critical_reading & low_reading: cap at 5000 records (oldest deleted first)
    $capTables = ['warning_reading', 'critical_reading', 'low_reading'];
    foreach ($capTables as $tbl) {
        $countResult = $conn->query("SELECT COUNT(*) as cnt FROM `$tbl`");
        $count = $countResult->fetch_assoc()['cnt'];
        if ($count > 5000) {
            $excess = $count - 5000;
            $conn->query("DELETE FROM `$tbl` ORDER BY id ASC LIMIT $excess");
        }
    }
}

// Run cleanup at most once per hour (not every request)
$cleanupFile = sys_get_temp_dir() . '/iot_cleanup_last.txt';
$lastCleanup = file_exists($cleanupFile) ? (int)file_get_contents($cleanupFile) : 0;
if (time() - $lastCleanup > 3600) {
    autoCleanup($conn);
    file_put_contents($cleanupFile, time());
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {

    // Get latest reading
    case 'latest':
        $result = $conn->query("SELECT * FROM temperature_reading ORDER BY id DESC LIMIT 1");
        $row = $result->fetch_assoc();
        if ($row) {
            $row['server_time'] = date('Y-m-d H:i:s');
        }
        echo json_encode($row ?: ["temperature" => null, "humidity" => null]);
        break;

    // Get all records (with optional limit)
    case 'all':
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
        $result = $conn->query("SELECT * FROM temperature_reading ORDER BY id DESC LIMIT $limit");
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        echo json_encode($data);
        break;

    // ── Chart data endpoints ──
    case 'chart':
        $view = isset($_GET['view']) ? $_GET['view'] : 'realtime';
        $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

        if ($view === 'realtime') {
            // Real-time: last 100 individual readings
            $result = $conn->query("SELECT temperature, humidity, created_at
                FROM temperature_reading ORDER BY id DESC LIMIT 100");
            $data = [];
            while ($row = $result->fetch_assoc()) $data[] = $row;
            echo json_encode(array_reverse($data));

        } elseif ($view === 'day') {
            // Per Day: all individual readings for a specific date + summary
            $stmt = $conn->prepare("SELECT temperature, humidity, created_at
                FROM temperature_reading 
                WHERE DATE(created_at) = ?
                ORDER BY id ASC");
            $stmt->bind_param("s", $date);
            $stmt->execute();
            $result = $stmt->get_result();
            $readings = [];
            while ($row = $result->fetch_assoc()) $readings[] = $row;
            $stmt->close();

            // Day summary stats
            $stmtStats = $conn->prepare("SELECT 
                ROUND(AVG(temperature),1) as avg_temp,
                ROUND(MIN(temperature),1) as min_temp,
                ROUND(MAX(temperature),1) as max_temp,
                ROUND(AVG(humidity),1) as avg_hum,
                ROUND(MIN(humidity),1) as min_hum,
                ROUND(MAX(humidity),1) as max_hum,
                COUNT(*) as total_readings
                FROM temperature_reading WHERE DATE(created_at) = ?");
            $stmtStats->bind_param("s", $date);
            $stmtStats->execute();
            $daySummary = $stmtStats->get_result()->fetch_assoc();
            $stmtStats->close();

            echo json_encode(['readings' => $readings, 'summary' => $daySummary]);

        } elseif ($view === 'week') {
            // Per Week: all individual readings from last 7 days
            $result = $conn->query("SELECT temperature, humidity, created_at
                FROM temperature_reading 
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                ORDER BY id ASC");
            $data = [];
            while ($row = $result->fetch_assoc()) $data[] = $row;
            echo json_encode($data);

        } elseif ($view === 'month') {
            // Per Month: all individual readings from last 30 days + optional previous
            $comparePrev = isset($_GET['compare']) && $_GET['compare'] === '1';

            $result = $conn->query("SELECT temperature, humidity, created_at
                FROM temperature_reading 
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                ORDER BY id ASC");
            $current = [];
            while ($row = $result->fetch_assoc()) $current[] = $row;

            $response = ['current' => $current];

            if ($comparePrev) {
                $prevResult = $conn->query("SELECT temperature, humidity, created_at
                    FROM temperature_reading 
                    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 59 DAY)
                      AND created_at < DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                    ORDER BY id ASC");
                $prev = [];
                while ($row = $prevResult->fetch_assoc()) $prev[] = $row;
                $response['previous'] = $prev;
            }

            echo json_encode($response);
        }
        break;

    // Get summary stats
    case 'stats':
        $result = $conn->query("SELECT 
            COUNT(*) as total_records,
            ROUND(AVG(temperature), 1) as avg_temp,
            ROUND(MIN(temperature), 1) as min_temp,
            ROUND(MAX(temperature), 1) as max_temp,
            ROUND(AVG(humidity), 1) as avg_hum,
            ROUND(MIN(humidity), 1) as min_hum,
            ROUND(MAX(humidity), 1) as max_hum
            FROM temperature_reading");
        echo json_encode($result->fetch_assoc());
        break;

    // Delete single record
    case 'delete':
        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $conn->query("DELETE FROM temperature_reading WHERE id = $id");
            echo json_encode(["success" => true, "message" => "Record deleted"]);
        } else {
            echo json_encode(["error" => "No ID provided"]);
        }
        break;

    // Delete all records
    case 'truncate':
        $conn->query("TRUNCATE TABLE temperature_reading");
        echo json_encode(["success" => true, "message" => "All records deleted"]);
        break;

    // ── Stable Reading Endpoints ──
    case 'stable':
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
        $result = $conn->query("SELECT * FROM stable_reading ORDER BY id DESC LIMIT $limit");
        $data = [];
        while ($row = $result->fetch_assoc()) { $data[] = $row; }
        echo json_encode($data);
        break;

    case 'stable_stats':
        $result = $conn->query("SELECT COUNT(*) as total FROM stable_reading");
        echo json_encode($result->fetch_assoc());
        break;

    case 'delete_stable':
        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $conn->query("DELETE FROM stable_reading WHERE id = $id");
            echo json_encode(["success" => true, "message" => "Record deleted"]);
        } else {
            echo json_encode(["error" => "No ID provided"]);
        }
        break;

    case 'truncate_stable':
        $conn->query("TRUNCATE TABLE stable_reading");
        echo json_encode(["success" => true, "message" => "All stable readings deleted"]);
        break;

    // ── Warning Reading Endpoints ──
    case 'warning':
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
        $result = $conn->query("SELECT * FROM warning_reading ORDER BY id DESC LIMIT $limit");
        $data = [];
        while ($row = $result->fetch_assoc()) { $data[] = $row; }
        echo json_encode($data);
        break;

    case 'warning_stats':
        $result = $conn->query("SELECT COUNT(*) as total FROM warning_reading");
        echo json_encode($result->fetch_assoc());
        break;

    case 'delete_warning':
        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $conn->query("DELETE FROM warning_reading WHERE id = $id");
            echo json_encode(["success" => true, "message" => "Record deleted"]);
        } else {
            echo json_encode(["error" => "No ID provided"]);
        }
        break;

    case 'truncate_warning':
        $conn->query("TRUNCATE TABLE warning_reading");
        echo json_encode(["success" => true, "message" => "All warning readings deleted"]);
        break;

    // ── Critical Reading Endpoints ──
    case 'critical':
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
        $result = $conn->query("SELECT * FROM critical_reading ORDER BY id DESC LIMIT $limit");
        $data = [];
        while ($row = $result->fetch_assoc()) { $data[] = $row; }
        echo json_encode($data);
        break;

    case 'critical_stats':
        $result = $conn->query("SELECT COUNT(*) as total FROM critical_reading");
        echo json_encode($result->fetch_assoc());
        break;

    case 'delete_critical':
        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $conn->query("DELETE FROM critical_reading WHERE id = $id");
            echo json_encode(["success" => true, "message" => "Record deleted"]);
        } else {
            echo json_encode(["error" => "No ID provided"]);
        }
        break;

    case 'truncate_critical':
        $conn->query("TRUNCATE TABLE critical_reading");
        echo json_encode(["success" => true, "message" => "All critical readings deleted"]);
        break;

    // ── Low Reading Endpoints ──
    case 'low':
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
        $result = $conn->query("SELECT * FROM low_reading ORDER BY id DESC LIMIT $limit");
        $data = [];
        while ($row = $result->fetch_assoc()) { $data[] = $row; }
        echo json_encode($data);
        break;

    case 'low_stats':
        $result = $conn->query("SELECT COUNT(*) as total FROM low_reading");
        echo json_encode($result->fetch_assoc());
        break;

    case 'delete_low':
        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $conn->query("DELETE FROM low_reading WHERE id = $id");
            echo json_encode(["success" => true, "message" => "Record deleted"]);
        } else {
            echo json_encode(["error" => "No ID provided"]);
        }
        break;

    case 'truncate_low':
        $conn->query("TRUNCATE TABLE low_reading");
        echo json_encode(["success" => true, "message" => "All low readings deleted"]);
        break;

    // Export temperature readings as CSV
    case 'export':
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="temperature_reading_' . date('Y-m-d_H-i-s') . '.csv"');
        $result = $conn->query("SELECT * FROM temperature_reading ORDER BY id ASC");
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Temperature (°C)', 'Humidity (%)', 'Timestamp']);
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, $row);
        }
        fclose($output);
        $conn->close();
        exit;

    default:
        echo json_encode(["error" => "Invalid action"]);
        break;
}

$conn->close();
?>
