<?php
/**
 * Seeds continuous 24/7 sensor data from March 30 to April 5, 2026.
 * Simulates an always-on device sending readings every 2 minutes (~5,040 records).
 *
 * Usage: https://your-app.onrender.com/seed_data.php
 */

header('Content-Type: text/plain');
set_time_limit(600);

require_once __DIR__ . '/db.php';

$pdo = getDB();
initTables($pdo);

// ── Clear old data first ──
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'seed';

echo "Clearing all tables...\n";
foreach (['temperature_reading','stable_reading','warning_reading','critical_reading','low_reading'] as $tbl) {
    try {
        if (isPostgres()) {
            $pdo->exec("TRUNCATE TABLE {$tbl} RESTART IDENTITY");
        } else {
            $pdo->exec("TRUNCATE TABLE {$tbl}");
        }
    } catch (Exception $e) {}
}
echo "All tables cleared.\n\n";

if ($mode === 'clean') {
    echo "Done! All data deleted.\n";
    exit;
}

$start = strtotime('2026-03-30 00:00:00');
$end   = strtotime('2026-04-05 23:59:59');
$step  = 120; // Every 2 minutes — continuous always-on device

$inserted = 0;

// Prepared statements
$stmtMain     = $pdo->prepare("INSERT INTO temperature_reading (temperature, humidity, created_at) VALUES (?, ?, ?)");
$stmtStable   = $pdo->prepare("INSERT INTO stable_reading   (temperature, humidity, status, created_at) VALUES (?, ?, 'STABLE', ?)");
$stmtWarning  = $pdo->prepare("INSERT INTO warning_reading  (temperature, humidity, status, created_at) VALUES (?, ?, 'WARNING', ?)");
$stmtCritical = $pdo->prepare("INSERT INTO critical_reading (temperature, humidity, status, created_at) VALUES (?, ?, 'CRITICAL', ?)");

// Smoothed random walk state
$prevTemp = 26.0;
$prevHum  = 65.0;

echo "Seeding continuous 24/7 data (every 2 min) from 2026-03-30 to 2026-04-05...\n\n";

for ($t = $start; $t <= $end; $t += $step) {
    $hour    = (int) date('G', $t);
    $minute  = (int) date('i', $t);
    $ts      = date('Y-m-d H:i:s', $t);

    // ── Smooth diurnal cycle using fractional hour ──
    $fracHour = $hour + $minute / 60.0;
    $radians  = (($fracHour - 14) / 24) * 2 * M_PI;
    $diurnal  = cos($radians);

    // Day-to-day variation
    $dayIndex   = (int) (($t - $start) / 86400);
    $dayOffsets = [0.0, 0.5, -0.3, 1.2, 0.8, -0.5, 0.3, 1.0];
    $dayBias    = $dayOffsets[$dayIndex % count($dayOffsets)];

    // Base temp: nighttime low ~24, daytime high ~32
    $baseTemp = 28.0 + $diurnal * 4.0 + $dayBias;

    // Smooth random walk toward target (smaller step for 2-min granularity)
    $jitter   = (mt_rand(-10, 10) / 10.0) * 0.15;
    $prevTemp = $prevTemp + ($baseTemp - $prevTemp) * 0.08 + $jitter;
    $temp     = round(max(20.0, min(36.0, $prevTemp)), 1);

    // Humidity: inversely correlated with temperature
    $baseHum   = 72.0 - $diurnal * 10.0 + $dayBias * -1.5;
    $humJitter = (mt_rand(-10, 10) / 10.0) * 0.8;
    $prevHum   = $prevHum + ($baseHum - $prevHum) * 0.06 + $humJitter;
    $hum       = round(max(45.0, min(90.0, $prevHum)), 1);

    // Insert main reading
    $stmtMain->execute([$temp, $hum, $ts]);

    // Insert into status table
    if ($temp >= 18 && $temp <= 24) {
        $stmtStable->execute([$temp, $hum, $ts]);
    } elseif ($temp >= 25 && $temp <= 30) {
        $stmtWarning->execute([$temp, $hum, $ts]);
    } elseif ($temp > 30) {
        $stmtCritical->execute([$temp, $hum, $ts]);
    }

    $inserted++;

    // Print progress every 6 hours
    if ($t % 21600 === 0) {
        echo "[$ts] temp={$temp}°C  hum={$hum}%  ({$inserted} rows)\n";
    }
}

echo "\nDone! Inserted {$inserted} continuous readings over 7 days.\n";
