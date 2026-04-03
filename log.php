<?php
require_once __DIR__ . '/db.php';

$pdo = getDB();
initTables($pdo);
runCleanupIfNeeded($pdo);

// INSERT — from ESP8266
if (isset($_GET['temp']) && isset($_GET['hum'])) {
    $temperature = floatval($_GET['temp']);
    $humidity    = floatval($_GET['hum']);

    $stmt = $pdo->prepare("INSERT INTO temperature_reading (temperature, humidity) VALUES (?, ?)");
    if ($stmt->execute([$temperature, $humidity])) {
        echo "Data inserted successfully";
    } else {
        echo "Error inserting data";
    }

    // Route to appropriate status table based on temperature
    if ($temperature >= 18 && $temperature <= 24) {
        $pdo->prepare("INSERT INTO stable_reading (temperature, humidity, status) VALUES (?, ?, ?)")
            ->execute([$temperature, $humidity, 'STABLE']);
        echo " | Status: STABLE";
    } elseif ($temperature >= 25 && $temperature <= 30) {
        $pdo->prepare("INSERT INTO warning_reading (temperature, humidity, status) VALUES (?, ?, ?)")
            ->execute([$temperature, $humidity, 'WARNING']);
        echo " | Status: WARNING";
    } elseif ($temperature > 30) {
        $pdo->prepare("INSERT INTO critical_reading (temperature, humidity, status) VALUES (?, ?, ?)")
            ->execute([$temperature, $humidity, 'CRITICAL']);
        echo " | Status: CRITICAL";
    } elseif ($temperature >= 15 && $temperature <= 17) {
        $pdo->prepare("INSERT INTO low_reading (temperature, humidity, status) VALUES (?, ?, ?)")
            ->execute([$temperature, $humidity, 'LOW']);
        echo " | Status: LOW";
    } elseif ($temperature < 15) {
        $pdo->prepare("INSERT INTO low_reading (temperature, humidity, status) VALUES (?, ?, ?)")
            ->execute([$temperature, $humidity, 'LOW']);
        echo " | Status: EXTREME LOW";
    }
}

// DELETE single row by ID
elseif (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $pdo->prepare("DELETE FROM temperature_reading WHERE id = ?")->execute([$id]);
    echo "Record deleted.";
}

// DELETE ALL and reset
elseif (isset($_GET['action']) && $_GET['action'] === 'truncate') {
    if (isPostgres()) {
        $pdo->exec("TRUNCATE TABLE temperature_reading RESTART IDENTITY");
    } else {
        $pdo->exec("TRUNCATE TABLE temperature_reading");
    }
    echo "All records deleted. ID reset to 1.";
}

// RENUMBER only (without deleting) — MySQL only
elseif (isset($_GET['action']) && $_GET['action'] === 'renumber') {
    if (!isPostgres()) {
        $pdo->exec("SET @num = 0");
        $pdo->exec("UPDATE temperature_reading SET id = (@num := @num + 1) ORDER BY id");
        $pdo->exec("ALTER TABLE temperature_reading AUTO_INCREMENT = 1");
    }
    echo "IDs renumbered successfully.";
}
?>
