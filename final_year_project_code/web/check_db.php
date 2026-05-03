<?php
require_once __DIR__ . '/db.php';

try {
    $db = get_db_connection();

    $dbName = $db->query("SELECT DATABASE() AS db")->fetch_assoc()['db'] ?? 'unknown';
    $count = (int)($db->query("SELECT COUNT(*) AS c FROM analysis_runs")->fetch_assoc()['c'] ?? 0);
    $last = $db->query("SELECT id, uploaded_filename, created_at FROM analysis_runs ORDER BY id DESC LIMIT 1")->fetch_assoc();

    header('Content-Type: text/plain; charset=utf-8');
    echo "DB: {$dbName}\n";
    echo "analysis_runs count: {$count}\n";
    echo "latest row: " . json_encode($last) . "\n";
} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: " . $e->getMessage() . "\n";
}
