<?php
/**
 * Simulates ESP8266 sensor data for local testing.
 * 
 * Usage:
 *   1. Open in browser: http://localhost/iot-temp-monitoring/test_simulate.php
 *      → Inserts one reading with random stable-range values
 *   2. With parameters: ?temp=28&hum=65
 *      → Inserts specific values
 *   3. Auto-mode: ?auto=1&count=50&interval=1
 *      → Inserts 50 readings spread over the last 50 seconds (backfills history)
 *   4. Continuous: ?stream=1
 *      → Streams one reading every 2 seconds (keep tab open)
 */

header('Content-Type: text/plain');

// Reuse log.php's insert logic via HTTP to test the full pipeline
$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . dirname($_SERVER['SCRIPT_NAME']) . '/log.php';

// ── Stream mode: sends one reading every 2 seconds ──
if (isset($_GET['stream'])) {
    set_time_limit(0);
    ob_implicit_flush(true);
    if (ob_get_level()) ob_end_flush();

    echo "Streaming simulated readings every 2 seconds... (close tab to stop)\n\n";

    while (true) {
        $temp = round(rand(180, 260) / 10, 1); // 18.0 - 26.0
        $hum  = round(rand(400, 700) / 10, 1); // 40.0 - 70.0

        $url = $base . '?temp=' . $temp . '&hum=' . $hum;
        $result = @file_get_contents($url);
        $time = date('H:i:s');
        echo "[$time] Sent temp={$temp} hum={$hum} → $result\n";

        sleep(2);
    }
    exit;
}

// ── Auto-backfill mode: insert historical readings ──
if (isset($_GET['auto'])) {
    $count = isset($_GET['count']) ? max(1, min(500, intval($_GET['count']))) : 20;
    $interval = isset($_GET['interval']) ? max(1, intval($_GET['interval'])) : 2;

    // Connect directly to insert with custom timestamps
    $conn = new mysqli('localhost', 'root', '', 'iot_logs');
    if ($conn->connect_error) {
        die("DB connection failed: " . $conn->connect_error);
    }
    $conn->query("SET time_zone = '+08:00'");

    echo "Inserting $count historical readings (every {$interval}s)...\n\n";

    $stmt = $conn->prepare("INSERT INTO temperature_reading (temperature, humidity, created_at) VALUES (?, ?, ?)");
    $stmtStatus = $conn->prepare("INSERT INTO stable_reading (temperature, humidity, status, created_at) VALUES (?, ?, 'STABLE', ?)");

    for ($i = $count; $i >= 1; $i--) {
        $temp = round(rand(180, 260) / 10, 1);
        $hum  = round(rand(400, 700) / 10, 1);
        $ts = date('Y-m-d H:i:s', time() - ($i * $interval));

        $stmt->bind_param("dds", $temp, $hum, $ts);
        $stmt->execute();

        if ($temp >= 18 && $temp <= 24) {
            $stmtStatus->bind_param("dds", $temp, $hum, $ts);
            $stmtStatus->execute();
        }

        echo "[$ts] temp={$temp} hum={$hum}\n";
    }

    $stmt->close();
    $stmtStatus->close();
    $conn->close();
    echo "\nDone! $count readings inserted.";
    exit;
}

// ── Single reading mode ──
$temp = isset($_GET['temp']) ? floatval($_GET['temp']) : round(rand(200, 240) / 10, 1);
$hum  = isset($_GET['hum'])  ? floatval($_GET['hum'])  : round(rand(450, 650) / 10, 1);

$url = $base . '?temp=' . $temp . '&hum=' . $hum;
$result = @file_get_contents($url);

echo "Sent to log.php: temp={$temp} hum={$hum}\n";
echo "Response: $result\n\n";
echo "--- Test URLs ---\n";
echo "Single:    http://localhost/iot-temp-monitoring/test_simulate.php\n";
echo "Custom:    http://localhost/iot-temp-monitoring/test_simulate.php?temp=28&hum=65\n";
echo "Backfill:  http://localhost/iot-temp-monitoring/test_simulate.php?auto=1&count=50\n";
echo "Stream:    http://localhost/iot-temp-monitoring/test_simulate.php?stream=1\n";
echo "Dashboard: http://localhost/iot-temp-monitoring/index.html\n";
