<?php
/**
 * Simulates a full day of ESP8266 sensor data for March 30, 2026
 * from 8:00 AM to 11:59 PM (16 hours, readings every 10 seconds).
 *
 * Realistic data center temperature profile:
 *   - Morning (8-11 AM):    Gradual warm-up ~20-22°C
 *   - Midday (11 AM-2 PM):  Peak load ~22-25°C, occasional warning spikes
 *   - Afternoon (2-5 PM):   Sustained warm ~23-24°C, brief critical spike ~30.5°C around 3 PM
 *   - Evening (5-8 PM):     Cooling ~22-20°C
 *   - Night (8-11:59 PM):   Stable ~19-21°C
 *
 * Usage:
 *   Browser: http://localhost/iot-temp-monitoring/simulate_march30.php
 *   CLI:     php simulate_march30.php
 */

set_time_limit(0);
header('Content-Type: text/plain');

require_once __DIR__ . '/db.php';

$pdo = getDB();
initTables($pdo);

// ── Time range ──
$startTime = strtotime('2026-03-30 08:00:00');
$endTime   = strtotime('2026-03-30 23:59:50');
$interval  = 10; // seconds between readings (matches ESP8266 timer)

// ── Realistic temperature profile (base curve by hour) ──
// hour => [base_temp, temp_variance, base_humidity, hum_variance]
$profile = [
    8  => [20.0, 0.4, 58.0, 2.0],
    9  => [20.5, 0.5, 56.0, 2.0],
    10 => [21.2, 0.5, 54.0, 2.5],
    11 => [22.0, 0.6, 52.0, 2.5],
    12 => [23.0, 0.8, 50.0, 3.0],
    13 => [24.0, 1.0, 48.0, 3.0],
    14 => [24.5, 1.2, 47.0, 3.0],
    15 => [24.0, 1.5, 48.0, 3.5],  // spike window around 3 PM
    16 => [23.5, 0.8, 49.0, 2.5],
    17 => [22.5, 0.6, 51.0, 2.5],
    18 => [21.5, 0.5, 53.0, 2.0],
    19 => [21.0, 0.4, 55.0, 2.0],
    20 => [20.5, 0.3, 56.0, 2.0],
    21 => [20.0, 0.3, 57.0, 1.5],
    22 => [19.8, 0.3, 58.0, 1.5],
    23 => [19.5, 0.3, 59.0, 1.5],
];

// Interpolate between hour profiles for smooth transitions
function getBaseValues($timestamp) {
    global $profile;
    $hour = (int) date('G', $timestamp);
    $minuteFraction = (int) date('i', $timestamp) / 60.0;

    $currentHour = $hour;
    $nextHour = min($hour + 1, 23);

    if (!isset($profile[$currentHour])) $currentHour = 23;
    if (!isset($profile[$nextHour])) $nextHour = 23;

    $c = $profile[$currentHour];
    $n = $profile[$nextHour];

    return [
        $c[0] + ($n[0] - $c[0]) * $minuteFraction, // base temp
        $c[1] + ($n[1] - $c[1]) * $minuteFraction, // temp variance
        $c[2] + ($n[2] - $c[2]) * $minuteFraction, // base humidity
        $c[3] + ($n[3] - $c[3]) * $minuteFraction, // hum variance
    ];
}

// Gaussian-ish random for more natural variation
function gaussRand($mean, $stddev) {
    $u1 = mt_rand() / mt_getrandmax();
    $u2 = mt_rand() / mt_getrandmax();
    $z = sqrt(-2 * log(max($u1, 0.0001))) * cos(2 * M_PI * $u2);
    return $mean + $z * $stddev;
}

// ── Count expected readings ──
$totalReadings = 0;
for ($t = $startTime; $t <= $endTime; $t += $interval) {
    $totalReadings++;
}

echo "=== March 30, 2026 — Data Center Simulation ===\n";
echo "Period: 8:00 AM — 11:59 PM (16 hours)\n";
echo "Interval: {$interval}s | Expected readings: {$totalReadings}\n";
echo "================================================\n\n";

// ── Prepare statements ──
$stmtMain = $pdo->prepare("INSERT INTO temperature_reading (temperature, humidity, created_at) VALUES (?, ?, ?)");
$stmtStable = $pdo->prepare("INSERT INTO stable_reading (temperature, humidity, status, created_at) VALUES (?, ?, 'STABLE', ?)");
$stmtWarning = $pdo->prepare("INSERT INTO warning_reading (temperature, humidity, status, created_at) VALUES (?, ?, 'WARNING', ?)");
$stmtCritical = $pdo->prepare("INSERT INTO critical_reading (temperature, humidity, status, created_at) VALUES (?, ?, 'CRITICAL', ?)");
$stmtLow = $pdo->prepare("INSERT INTO low_reading (temperature, humidity, status, created_at) VALUES (?, ?, 'LOW', ?)");

// ── Inject special events for realism ──
// Brief critical spike at ~3:12 PM (server room AC hiccup)
$spikeStart = strtotime('2026-03-30 15:12:00');
$spikeEnd   = strtotime('2026-03-30 15:18:00');

// Warning period around 1:30-1:45 PM (high compute load)
$warnStart = strtotime('2026-03-30 13:30:00');
$warnEnd   = strtotime('2026-03-30 13:45:00');

// Brief low dip at ~10:05 PM (over-cooling)
$lowStart = strtotime('2026-03-30 22:05:00');
$lowEnd   = strtotime('2026-03-30 22:10:00');

$inserted = 0;
$counts = ['STABLE' => 0, 'WARNING' => 0, 'CRITICAL' => 0, 'LOW' => 0];
$prevTemp = 20.0;

$pdo->beginTransaction();

for ($t = $startTime; $t <= $endTime; $t += $interval) {
    $ts = date('Y-m-d H:i:s', $t);
    list($baseTemp, $tempVar, $baseHum, $humVar) = getBaseValues($t);

    // Smooth random walk (70% previous + 30% new target for natural drift)
    $targetTemp = gaussRand($baseTemp, $tempVar);
    $temp = round($prevTemp * 0.7 + $targetTemp * 0.3, 1);
    $hum = round(gaussRand($baseHum, $humVar), 1);

    // ── Inject special events ──
    if ($t >= $spikeStart && $t <= $spikeEnd) {
        // Critical AC failure spike: ramps up then recovers
        $progress = ($t - $spikeStart) / ($spikeEnd - $spikeStart);
        $spikeCurve = sin($progress * M_PI); // 0→1→0
        $temp = round(25.0 + $spikeCurve * 6.5 + gaussRand(0, 0.3), 1); // peaks ~31.5°C
        $hum = round(42.0 - $spikeCurve * 5 + gaussRand(0, 1.0), 1);
    } elseif ($t >= $warnStart && $t <= $warnEnd) {
        // Warning zone: sustained high load
        $temp = round(gaussRand(26.5, 1.0), 1);
        $hum = round(gaussRand(45.0, 2.0), 1);
    } elseif ($t >= $lowStart && $t <= $lowEnd) {
        // Brief low dip: over-cooling event
        $progress = ($t - $lowStart) / ($lowEnd - $lowStart);
        $dipCurve = sin($progress * M_PI);
        $temp = round(19.0 - $dipCurve * 3.0 + gaussRand(0, 0.2), 1); // dips to ~16°C
        $hum = round(62.0 + $dipCurve * 4 + gaussRand(0, 1.0), 1);
    }

    // Clamp to realistic sensor range
    $temp = max(14.0, min(35.0, $temp));
    $hum  = max(30.0, min(80.0, $hum));

    $prevTemp = $temp;

    // Insert into main table
    $stmtMain->execute([$temp, $hum, $ts]);

    // Route to status table (same logic as log.php)
    if ($temp >= 18 && $temp <= 24) {
        $stmtStable->execute([$temp, $hum, $ts]);
        $counts['STABLE']++;
    } elseif ($temp >= 25 && $temp <= 30) {
        $stmtWarning->execute([$temp, $hum, $ts]);
        $counts['WARNING']++;
    } elseif ($temp > 30) {
        $stmtCritical->execute([$temp, $hum, $ts]);
        $counts['CRITICAL']++;
    } elseif ($temp >= 15 && $temp <= 17) {
        $stmtLow->execute([$temp, $hum, $ts]);
        $counts['LOW']++;
    } elseif ($temp < 15) {
        $stmtLow->execute([$temp, $hum, $ts]);
        $counts['LOW']++;
    }

    $inserted++;

    // Progress output every 500 readings
    if ($inserted % 500 === 0) {
        echo "[{$ts}] {$inserted}/{$totalReadings} inserted... (temp={$temp}°C, hum={$hum}%)\n";
        if (ob_get_level()) ob_flush();
        flush();
    }
}

// Update device heartbeat to last reading time
$lastTs = date('Y-m-d H:i:s', $endTime);
if (isPostgres()) {
    $pdo->exec("INSERT INTO device_status (id, status, last_seen) VALUES (1, 'online', '{$lastTs}')
        ON CONFLICT (id) DO UPDATE SET status = 'online', last_seen = '{$lastTs}'");
} else {
    $pdo->exec("INSERT INTO device_status (id, status, last_seen) VALUES (1, 'online', '{$lastTs}')
        ON DUPLICATE KEY UPDATE status = 'online', last_seen = '{$lastTs}'");
}

$pdo->commit();

echo "\n================================================\n";
echo "DONE! Inserted {$inserted} readings.\n\n";
echo "Breakdown:\n";
echo "  STABLE:   {$counts['STABLE']} readings (18-24°C)\n";
echo "  WARNING:  {$counts['WARNING']} readings (25-30°C)\n";
echo "  CRITICAL: {$counts['CRITICAL']} readings (>30°C)\n";
echo "  LOW:      {$counts['LOW']} readings (<18°C)\n\n";
echo "Special events simulated:\n";
echo "  🔴 AC failure spike at 3:12-3:18 PM (peaks ~31.5°C)\n";
echo "  🟡 High load warning at 1:30-1:45 PM (~26-28°C)\n";
echo "  🔵 Over-cooling dip at 10:05-10:10 PM (~16°C)\n\n";
echo "View dashboard: /index.html\n";
