<?php
/**
 * Seeds realistic 24/7 temperature/humidity data from March 30 to April 5, 2026.
 * Readings every 10 minutes with diurnal patterns typical of a Philippine indoor sensor.
 *
 * Usage: https://your-app.onrender.com/seed_data.php
 */

header('Content-Type: text/plain');
set_time_limit(300);

require_once __DIR__ . '/db.php';

$pdo = getDB();
initTables($pdo);

$start = strtotime('2026-03-30 00:00:00');
$end   = strtotime('2026-04-05 23:59:59');
$step  = 600; // 10 minutes

$inserted = 0;

// Prepared statements
$stmtMain     = $pdo->prepare("INSERT INTO temperature_reading (temperature, humidity, created_at) VALUES (?, ?, ?)");
$stmtStable   = $pdo->prepare("INSERT INTO stable_reading   (temperature, humidity, status, created_at) VALUES (?, ?, 'STABLE', ?)");
$stmtWarning  = $pdo->prepare("INSERT INTO warning_reading  (temperature, humidity, status, created_at) VALUES (?, ?, 'WARNING', ?)");
$stmtCritical = $pdo->prepare("INSERT INTO critical_reading (temperature, humidity, status, created_at) VALUES (?, ?, 'CRITICAL', ?)");

// Smoothed random walk state
$prevTemp = 26.0;
$prevHum  = 65.0;

echo "Seeding 24/7 data from 2026-03-30 to 2026-04-05...\n\n";

for ($t = $start; $t <= $end; $t += $step) {
    $hour = (int) date('G', $t);
    $ts   = date('Y-m-d H:i:s', $t);

    // ── Target temperature based on time of day (diurnal cycle) ──
    // Peak ~14:00 (2 PM), trough ~05:00 (5 AM)
    // Using a cosine curve shifted so min is at 5 AM and max at 2 PM
    $radians    = (($hour - 14) / 24) * 2 * M_PI;
    $diurnal    = cos($radians); // 1.0 at 14:00, ~-1.0 at 02:00

    // Day-to-day variation: slight drift per day
    $dayIndex   = (int) (($t - $start) / 86400);
    $dayOffsets = [0.0, 0.5, -0.3, 1.2, 0.8, -0.5, 0.3, 1.0];
    $dayBias    = $dayOffsets[$dayIndex % count($dayOffsets)];

    // Base temp range: nighttime low ~24, daytime high ~32
    $baseTemp = 28.0 + $diurnal * 4.0 + $dayBias;

    // Smooth random walk toward target
    $jitter   = (mt_rand(-10, 10) / 10.0) * 0.3; // ±0.3 °C noise
    $prevTemp = $prevTemp + ($baseTemp - $prevTemp) * 0.3 + $jitter;
    $temp     = round($prevTemp, 1);

    // ── Humidity: inversely correlated with temperature ──
    // Higher at night / cooler temps, lower during hot afternoon
    $baseHum  = 72.0 - $diurnal * 10.0 + $dayBias * -1.5;
    $humJitter = (mt_rand(-10, 10) / 10.0) * 1.5;
    $prevHum   = $prevHum + ($baseHum - $prevHum) * 0.25 + $humJitter;
    $hum       = round(max(45.0, min(90.0, $prevHum)), 1);

    // Clamp temperature to realistic sensor range
    $temp = max(20.0, min(36.0, $temp));

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

    // Print progress every hour
    if ($t % 3600 === 0) {
        echo "[$ts] temp={$temp}°C  hum={$hum}%\n";
    }
}

echo "\nDone! Inserted {$inserted} readings.\n";
