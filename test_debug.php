<?php
include 'db.php';
$pdo = getConnection();

$results = [];

// Basic counts
$results['total_rows'] = $pdo->query("SELECT COUNT(*) FROM temperature_reading")->fetchColumn();
$results['current_date'] = $pdo->query("SELECT CURRENT_DATE::text")->fetchColumn();
$results['current_timestamp'] = $pdo->query("SELECT NOW()::text")->fetchColumn();
$results['min_date'] = $pdo->query("SELECT MIN(created_at)::text FROM temperature_reading")->fetchColumn();
$results['max_date'] = $pdo->query("SELECT MAX(created_at)::text FROM temperature_reading")->fetchColumn();

// Column type
$results['column_type'] = $pdo->query("SELECT data_type FROM information_schema.columns WHERE table_name='temperature_reading' AND column_name='created_at'")->fetchColumn();

// GROUP BY test
$results['hourly_groups'] = $pdo->query("SELECT COUNT(*) FROM (SELECT date_trunc('hour', created_at) as h FROM temperature_reading GROUP BY h) sub")->fetchColumn();

// Week/month filter counts
$results['week_raw'] = $pdo->query("SELECT COUNT(*) FROM temperature_reading WHERE created_at >= CURRENT_DATE - INTERVAL '6 days'")->fetchColumn();
$results['month_raw'] = $pdo->query("SELECT COUNT(*) FROM temperature_reading WHERE created_at >= CURRENT_DATE - INTERVAL '29 days'")->fetchColumn();
$results['week_grouped'] = $pdo->query("SELECT COUNT(*) FROM (SELECT date_trunc('hour', created_at) as h FROM temperature_reading WHERE created_at >= CURRENT_DATE - INTERVAL '6 days' GROUP BY h) sub")->fetchColumn();
$results['month_grouped'] = $pdo->query("SELECT COUNT(*) FROM (SELECT date_trunc('hour', created_at) as h FROM temperature_reading WHERE created_at >= CURRENT_DATE - INTERVAL '29 days' GROUP BY h) sub")->fetchColumn();

// Sample of 5 rows with truncated hours  
$results['sample_hours'] = $pdo->query("SELECT date_trunc('hour', created_at)::text as h, COUNT(*) as cnt FROM temperature_reading GROUP BY h ORDER BY h ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);
