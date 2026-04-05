<?php
/**
 * Simulates March 31 00:00 AM → April 5 1:00 PM (2026)
 * Continues from the March 30 simulation.
 *
 * ~6.5 days at 10s intervals = ~56,160 readings
 * Uses batch inserts for speed on Render.
 *
 * Daily pattern:
 *   Night (12-6 AM):    Cool stable ~19-20°C
 *   Morning (6-11 AM):  Warm-up ~20-22°C
 *   Midday (11 AM-3 PM): Peak ~22-25°C
 *   Afternoon (3-6 PM): Sustained ~23-24°C
 *   Evening (6-12 AM):  Cool-down ~22-19°C
 *
 * Special events scattered across days for realism.
 */

set_time_limit(600);
header('Content-Type: text/plain');

require_once __DIR__ . '/db.php';

$pdo = getDB();
initTables($pdo);

$startTime = strtotime('2026-03-31 00:00:00');
$endTime   = strtotime('2026-04-05 13:00:00');
$interval  = 10;

// ── Hourly base profile [temp, tempVar, hum, humVar] ──
$hourProfile = [
    0  => [19.5, 0.3, 59.0, 1.5],
    1  => [19.3, 0.3, 59.5, 1.5],
    2  => [19.2, 0.3, 60.0, 1.5],
    3  => [19.0, 0.2, 60.5, 1.5],
    4  => [19.0, 0.2, 60.5, 1.5],
    5  => [19.2, 0.3, 60.0, 1.5],
    6  => [19.5, 0.3, 59.0, 2.0],
    7  => [20.0, 0.4, 58.0, 2.0],
    8  => [20.5, 0.4, 57.0, 2.0],
    9  => [21.0, 0.5, 55.0, 2.0],
    10 => [21.5, 0.5, 53.0, 2.5],
    11 => [22.2, 0.6, 51.0, 2.5],
    12 => [23.0, 0.8, 49.0, 3.0],
    13 => [23.5, 0.9, 48.0, 3.0],
    14 => [24.0, 1.0, 47.0, 3.0],
    15 => [23.8, 0.8, 47.5, 2.5],
    16 => [23.2, 0.7, 48.5, 2.5],
    17 => [22.5, 0.6, 50.0, 2.5],
    18 => [21.8, 0.5, 52.0, 2.0],
    19 => [21.2, 0.4, 54.0, 2.0],
    20 => [20.8, 0.4, 55.5, 2.0],
    21 => [20.3, 0.3, 57.0, 1.5],
    22 => [20.0, 0.3, 58.0, 1.5],
    23 => [19.8, 0.3, 58.5, 1.5],
];

// Weekend adjustment (Sat Apr 4, Sun Apr 5): less server load → cooler
function isWeekend($ts) {
    $dow = date('N', $ts); // 6=Sat, 7=Sun
    return $dow >= 6;
}

// Interpolate hour profile
function getBaseValues($ts) {
    global $hourProfile;
    $h = (int)date('G', $ts);
    $minFrac = (int)date('i', $ts) / 60.0;
    $nh = ($h + 1) % 24;
    $c = $hourProfile[$h];
    $n = $hourProfile[$nh];
    $temp    = $c[0] + ($n[0] - $c[0]) * $minFrac;
    $tempVar = $c[1] + ($n[1] - $c[1]) * $minFrac;
    $hum     = $c[2] + ($n[2] - $c[2]) * $minFrac;
    $humVar  = $c[3] + ($n[3] - $c[3]) * $minFrac;

    // Weekend: 1°C cooler, slightly more humid
    if (isWeekend($ts)) {
        $temp -= 1.0;
        $hum += 2.0;
    }

    return [$temp, $tempVar, $hum, $humVar];
}

function gaussRand($mean, $std) {
    $u1 = mt_rand() / mt_getrandmax();
    $u2 = mt_rand() / mt_getrandmax();
    $z = sqrt(-2 * log(max($u1, 0.0001))) * cos(2 * M_PI * $u2);
    return $mean + $z * $std;
}

// ── Special events (date => [[startH:M, endH:M, type, peakTemp, peakHum]]) ──
$events = [
    '2026-03-31' => [
        ['14:20', '14:28', 'warning', 27.0, 44.0],   // Brief warning spike
    ],
    '2026-04-01' => [
        ['10:30', '10:40', 'warning', 26.5, 45.0],   // Morning load burst
        ['15:05', '15:15', 'critical', 31.0, 41.0],   // AC compressor lag
    ],
    '2026-04-02' => [
        ['13:40', '13:55', 'warning', 28.0, 43.0],   // Sustained warning
        ['22:15', '22:22', 'low', 16.5, 63.0],        // Night over-cooling
    ],
    '2026-04-03' => [
        ['11:00', '11:08', 'warning', 26.0, 46.0],   // Brief warning
        ['15:30', '15:42', 'critical', 31.5, 40.0],   // Server rack hotspot
        ['23:10', '23:16', 'low', 16.0, 64.0],        // Over-cooling
    ],
    '2026-04-04' => [
        // Saturday — quiet day, one minor event
        ['14:00', '14:06', 'warning', 25.5, 47.0],
    ],
    '2026-04-05' => [
        // Sunday morning — one brief warning
        ['10:15', '10:22', 'warning', 26.0, 46.0],
    ],
];

// Build lookup: timestamp ranges for events
$eventRanges = [];
foreach ($events as $date => $dayEvents) {
    foreach ($dayEvents as $ev) {
        $s = strtotime("{$date} {$ev[0]}:00");
        $e = strtotime("{$date} {$ev[1]}:00");
        $eventRanges[] = ['start' => $s, 'end' => $e, 'type' => $ev[2], 'peakTemp' => $ev[3], 'peakHum' => $ev[4]];
    }
}

function getEvent($ts) {
    global $eventRanges;
    foreach ($eventRanges as $ev) {
        if ($ts >= $ev['start'] && $ts <= $ev['end']) {
            return $ev;
        }
    }
    return null;
}

// Count total
$totalReadings = (int)(($endTime - $startTime) / $interval) + 1;

echo "=== March 31 → April 5 1:00 PM Simulation ===\n";
echo "Period: Mar 31 00:00 — Apr 5 13:00 (~6.5 days)\n";
echo "Interval: {$interval}s | Expected: ~{$totalReadings} readings\n";
echo "==============================================\n\n";

// ── Batch insert for speed ──
$batchSize = 200;
$mainBatch = [];
$stableBatch = [];
$warningBatch = [];
$criticalBatch = [];
$lowBatch = [];

$inserted = 0;
$counts = ['STABLE' => 0, 'WARNING' => 0, 'CRITICAL' => 0, 'LOW' => 0];
$prevTemp = 19.5;

function flushBatch($pdo, &$batch, $table, $cols) {
    if (empty($batch)) return;
    $placeholders = [];
    $values = [];
    $colCount = count($cols);
    foreach ($batch as $row) {
        $placeholders[] = '(' . implode(',', array_fill(0, $colCount, '?')) . ')';
        foreach ($row as $v) $values[] = $v;
    }
    $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES " . implode(',', $placeholders);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    $batch = [];
}

for ($t = $startTime; $t <= $endTime; $t += $interval) {
    $ts = date('Y-m-d H:i:s', $t);
    list($baseTemp, $tempVar, $baseHum, $humVar) = getBaseValues($t);

    $targetTemp = gaussRand($baseTemp, $tempVar);
    $temp = round($prevTemp * 0.7 + $targetTemp * 0.3, 1);
    $hum = round(gaussRand($baseHum, $humVar), 1);

    // Check for special events
    $ev = getEvent($t);
    if ($ev) {
        $progress = ($t - $ev['start']) / max(1, $ev['end'] - $ev['start']);
        $curve = sin($progress * M_PI); // 0→1→0

        if ($ev['type'] === 'critical') {
            $temp = round($ev['peakTemp'] - 6 + $curve * 7 + gaussRand(0, 0.3), 1);
            $hum  = round($ev['peakHum'] + (1 - $curve) * 5 + gaussRand(0, 1.0), 1);
        } elseif ($ev['type'] === 'warning') {
            $temp = round($ev['peakTemp'] - 2 + $curve * 3 + gaussRand(0, 0.4), 1);
            $hum  = round($ev['peakHum'] + (1 - $curve) * 4 + gaussRand(0, 1.5), 1);
        } elseif ($ev['type'] === 'low') {
            $temp = round($ev['peakTemp'] + 3 - $curve * 4 + gaussRand(0, 0.2), 1);
            $hum  = round($ev['peakHum'] - 3 + $curve * 5 + gaussRand(0, 1.0), 1);
        }
    }

    $temp = max(14.0, min(35.0, $temp));
    $hum  = max(30.0, min(80.0, $hum));
    $prevTemp = $temp;

    $mainBatch[] = [$temp, $hum, $ts];

    if ($temp >= 18 && $temp <= 24) {
        $stableBatch[] = [$temp, $hum, 'STABLE', $ts];
        $counts['STABLE']++;
    } elseif ($temp >= 25 && $temp <= 30) {
        $warningBatch[] = [$temp, $hum, 'WARNING', $ts];
        $counts['WARNING']++;
    } elseif ($temp > 30) {
        $criticalBatch[] = [$temp, $hum, 'CRITICAL', $ts];
        $counts['CRITICAL']++;
    } elseif ($temp < 18) {
        $lowBatch[] = [$temp, $hum, 'LOW', $ts];
        $counts['LOW']++;
    }

    $inserted++;

    // Flush batches
    if (count($mainBatch) >= $batchSize) {
        flushBatch($pdo, $mainBatch, 'temperature_reading', ['temperature', 'humidity', 'created_at']);
        flushBatch($pdo, $stableBatch, 'stable_reading', ['temperature', 'humidity', 'status', 'created_at']);
        flushBatch($pdo, $warningBatch, 'warning_reading', ['temperature', 'humidity', 'status', 'created_at']);
        flushBatch($pdo, $criticalBatch, 'critical_reading', ['temperature', 'humidity', 'status', 'created_at']);
        flushBatch($pdo, $lowBatch, 'low_reading', ['temperature', 'humidity', 'status', 'created_at']);
    }

    if ($inserted % 5000 === 0) {
        $pct = round($inserted / $totalReadings * 100);
        echo "[{$ts}] {$inserted}/{$totalReadings} ({$pct}%) — temp={$temp}°C hum={$hum}%\n";
        if (ob_get_level()) ob_flush();
        flush();
    }
}

// Flush remaining
flushBatch($pdo, $mainBatch, 'temperature_reading', ['temperature', 'humidity', 'created_at']);
flushBatch($pdo, $stableBatch, 'stable_reading', ['temperature', 'humidity', 'status', 'created_at']);
flushBatch($pdo, $warningBatch, 'warning_reading', ['temperature', 'humidity', 'status', 'created_at']);
flushBatch($pdo, $criticalBatch, 'critical_reading', ['temperature', 'humidity', 'status', 'created_at']);
flushBatch($pdo, $lowBatch, 'low_reading', ['temperature', 'humidity', 'status', 'created_at']);

// Update device heartbeat to final timestamp (Apr 5 1:00 PM)
$lastTs = date('Y-m-d H:i:s', $endTime);
if (isPostgres()) {
    $pdo->exec("INSERT INTO device_status (id, status, last_seen) VALUES (1, 'online', '{$lastTs}')
        ON CONFLICT (id) DO UPDATE SET status = 'online', last_seen = '{$lastTs}'");
} else {
    $pdo->exec("INSERT INTO device_status (id, status, last_seen) VALUES (1, 'online', '{$lastTs}')
        ON DUPLICATE KEY UPDATE status = 'online', last_seen = '{$lastTs}'");
}

echo "\n==============================================\n";
echo "DONE! Inserted {$inserted} readings.\n\n";
echo "Breakdown:\n";
echo "  STABLE:   {$counts['STABLE']}\n";
echo "  WARNING:  {$counts['WARNING']}\n";
echo "  CRITICAL: {$counts['CRITICAL']}\n";
echo "  LOW:      {$counts['LOW']}\n\n";
echo "Events simulated:\n";
echo "  Mar 31: Warning 2:20-2:28 PM\n";
echo "  Apr 1:  Warning 10:30-10:40 AM, Critical 3:05-3:15 PM\n";
echo "  Apr 2:  Warning 1:40-1:55 PM, Low 10:15-10:22 PM\n";
echo "  Apr 3:  Warning 11:00-11:08 AM, Critical 3:30-3:42 PM, Low 11:10-11:16 PM\n";
echo "  Apr 4:  Warning 2:00-2:06 PM (Saturday — lighter load)\n";
echo "  Apr 5:  Warning 10:15-10:22 AM (Sunday)\n";
echo "\nDashboard: /index.html\n";
