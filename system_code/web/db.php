<?php
require_once __DIR__ . '/config.php';

function ensure_analysis_runs_schema(mysqli $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    // Backward-compatible schema upgrade for replaying full historical result pages.
    // Some MySQL variants do not support "ADD COLUMN IF NOT EXISTS", so check first.
    $dbName = '';
    $dbNameResult = $db->query('SELECT DATABASE() AS db');
    if ($dbNameResult) {
        $dbNameRow = $dbNameResult->fetch_assoc();
        $dbName = (string)($dbNameRow['db'] ?? '');
        $dbNameResult->close();
    }
    $dbNameEscaped = $db->real_escape_string($dbName);
    $columnNameEscaped = $db->real_escape_string('analysis_payload_json');
    $existsSql = "SELECT COUNT(*) AS cnt
                  FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = '{$dbNameEscaped}'
                    AND TABLE_NAME = 'analysis_runs'
                    AND COLUMN_NAME = '{$columnNameEscaped}'";
    $existsResult = $db->query($existsSql);
    if ($existsResult) {
        $row = $existsResult->fetch_assoc();
        $existsResult->close();
        $exists = (int)($row['cnt'] ?? 0) > 0;
        if (!$exists) {
            $db->query('ALTER TABLE analysis_runs ADD COLUMN analysis_payload_json LONGTEXT NULL');
        }
    }
    $checked = true;
}

function get_db_connection(): mysqli
{
    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;

    $attempts = 10;
    $lastError = 'Unknown database connection error';
    for ($i = 0; $i < $attempts; $i++) {
        $mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, (int)$DB_PORT);
        if (!$mysqli->connect_error) {
            ensure_analysis_runs_schema($mysqli);
            return $mysqli;
        }

        $lastError = $mysqli->connect_error;
        usleep(500000);
    }

    throw new RuntimeException('Database connection failed: ' . $lastError);
}
