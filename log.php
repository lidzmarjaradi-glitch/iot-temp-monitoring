<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "iot_logs";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
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

// Function to renumber IDs sequentially
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

// Run cleanup at most once per hour
$cleanupFile = sys_get_temp_dir() . '/iot_log_cleanup_last.txt';
$lastCleanup = file_exists($cleanupFile) ? (int)file_get_contents($cleanupFile) : 0;
if (time() - $lastCleanup > 3600) {
    autoCleanup($conn);
    file_put_contents($cleanupFile, time());
}

// INSERT — from ESP8266
if (isset($_GET['temp']) && isset($_GET['hum'])) {
    $temperature = floatval($_GET['temp']);
    $humidity    = floatval($_GET['hum']);

    // Insert into temperature_reading
    $stmt = $conn->prepare("INSERT INTO temperature_reading (temperature, humidity) VALUES (?, ?)");
    $stmt->bind_param("dd", $temperature, $humidity);

    if ($stmt->execute()) {
        echo "Data inserted successfully";
    } else {
        echo "Error: " . $stmt->error;
    }
    $stmt->close();

    // Route to appropriate status table based on temperature
    if ($temperature >= 18 && $temperature <= 24) {
        // Stable: 18°C – 24°C
        $status = 'STABLE';
        $stmt2 = $conn->prepare("INSERT INTO stable_reading (temperature, humidity, status) VALUES (?, ?, ?)");
        $stmt2->bind_param("dds", $temperature, $humidity, $status);
        $stmt2->execute();
        $stmt2->close();
        echo " | Status: STABLE";
    } elseif ($temperature >= 25 && $temperature <= 30) {
        // Warning: 25°C – 30°C
        $status = 'WARNING';
        $stmt2 = $conn->prepare("INSERT INTO warning_reading (temperature, humidity, status) VALUES (?, ?, ?)");
        $stmt2->bind_param("dds", $temperature, $humidity, $status);
        $stmt2->execute();
        $stmt2->close();
        echo " | Status: WARNING";
    } elseif ($temperature > 30) {
        // Critical: Above 30°C
        $status = 'CRITICAL';
        $stmt2 = $conn->prepare("INSERT INTO critical_reading (temperature, humidity, status) VALUES (?, ?, ?)");
        $stmt2->bind_param("dds", $temperature, $humidity, $status);
        $stmt2->execute();
        $stmt2->close();
        echo " | Status: CRITICAL";
    } elseif ($temperature >= 15 && $temperature <= 17) {
        // Low: 15°C – 17°C
        $status = 'LOW';
        $stmt2 = $conn->prepare("INSERT INTO low_reading (temperature, humidity, status) VALUES (?, ?, ?)");
        $stmt2->bind_param("dds", $temperature, $humidity, $status);
        $stmt2->execute();
        $stmt2->close();
        echo " | Status: LOW";
    } elseif ($temperature < 15) {
        // Extreme Low: Below 15°C
        $status = 'LOW';
        $stmt2 = $conn->prepare("INSERT INTO low_reading (temperature, humidity, status) VALUES (?, ?, ?)");
        $stmt2->bind_param("dds", $temperature, $humidity, $status);
        $stmt2->execute();
        $stmt2->close();
        echo " | Status: EXTREME LOW";
    }
}

// DELETE single row by ID
elseif (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $conn->query("DELETE FROM temperature_reading WHERE id = $id");
    echo "Record deleted.";
}

// DELETE ALL and reset
elseif (isset($_GET['action']) && $_GET['action'] === 'truncate') {
    $conn->query("TRUNCATE TABLE temperature_reading");
    echo "All records deleted. ID reset to 1.";
}

// RENUMBER only (without deleting)
elseif (isset($_GET['action']) && $_GET['action'] === 'renumber') {
    renumberIds($conn);
    echo "IDs renumbered successfully.";
}

$conn->close();
?>
