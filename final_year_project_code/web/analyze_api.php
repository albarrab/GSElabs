<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_FILES['dataset'])) {
    respond(400, ['ok' => false, 'error' => 'Please upload a CSV file.']);
}

if ($_FILES['dataset']['error'] !== UPLOAD_ERR_OK) {
    if ($_FILES['dataset']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['dataset']['error'] === UPLOAD_ERR_FORM_SIZE) {
        respond(413, ['ok' => false, 'error' => 'Uploaded file exceeds server limits.']);
    }
    respond(400, ['ok' => false, 'error' => 'Upload failed with error code: ' . (int)$_FILES['dataset']['error']]);
}

$tmpPath = (string)$_FILES['dataset']['tmp_name'];
$originalName = (string)$_FILES['dataset']['name'];
$targetColumn = trim($_POST['target_column'] ?? 'Label');

if (!function_exists('curl_init') || !class_exists('CURLFile')) {
    respond(500, ['ok' => false, 'error' => 'Server configuration error: PHP cURL extension is not available.']);
}

if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    respond(400, ['ok' => false, 'error' => 'Upload verification failed. Please retry the upload.']);
}

$ch = curl_init();
if ($ch === false) {
    respond(500, ['ok' => false, 'error' => 'Unable to initialize ML request.']);
}

$postFields = [
    'file' => new CURLFile($tmpPath, 'text/csv', $originalName),
    'target_column' => $targetColumn,
];

function call_ml_api($ch, string $url, array $postFields): array
{
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 1800,
    ]);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$response, $errno, $error, $httpCode];
}

$mlUrl = rtrim($ML_API_URL, '/') . '/analyze';
[$response, $curlErrno, $curlError, $httpCode] = [false, 0, '', 0];
for ($attempt = 1; $attempt <= 5; $attempt++) {
    [$response, $curlErrno, $curlError, $httpCode] = call_ml_api($ch, $mlUrl, $postFields);
    if ($response !== false) {
        break;
    }
    // Retry transient ML-service outages (container restart / connection drop).
    if (!in_array($curlErrno, [7, 52, 56], true)) {
        break;
    }
    usleep(500000 * $attempt);
}

curl_close($ch);

if ($response === false) {
    if ($curlErrno === 52) {
        respond(502, ['ok' => false, 'error' => 'ML service returned an empty reply (possible restart/resource pressure). Try smaller files or fewer parallel workloads.']);
    }
    if ($curlErrno === 7) {
        respond(502, ['ok' => false, 'error' => 'ML service was temporarily unavailable (connection refused). It may have restarted under load; please retry this file.']);
    }
    respond(502, ['ok' => false, 'error' => 'ML service request failed: ' . $curlError]);
}

$data = json_decode($response, true);
if (!is_array($data)) {
    $snippet = trim(substr($response, 0, 200));
    respond(502, ['ok' => false, 'error' => 'ML service returned invalid response.', 'snippet' => $snippet]);
}

if ($httpCode >= 400) {
    respond($httpCode, ['ok' => false, 'error' => (string)($data['error'] ?? ('ML service error (HTTP ' . $httpCode . ').'))]);
}

$bestName = (string)($data['best_model']['name'] ?? 'Unknown');
$bestMetrics = is_array($data['best_model']['metrics'] ?? null) ? $data['best_model']['metrics'] : [];
$payloadJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$runId = null;

try {
    $db = get_db_connection();
    $stmt = $db->prepare('INSERT INTO analysis_runs (uploaded_filename, best_model, best_f1, best_precision, best_recall, best_accuracy, false_positive_rate, analysis_payload_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if ($stmt) {
        $bestF1 = (float)($bestMetrics['f1'] ?? 0);
        $bestPrecision = (float)($bestMetrics['precision'] ?? 0);
        $bestRecall = (float)($bestMetrics['recall'] ?? 0);
        $bestAccuracy = (float)($bestMetrics['accuracy'] ?? 0);
        $bestFpr = (float)($bestMetrics['false_positive_rate'] ?? 0);
        $stmt->bind_param('ssddddds', $originalName, $bestName, $bestF1, $bestPrecision, $bestRecall, $bestAccuracy, $bestFpr, $payloadJson);
        $stmt->execute();
        $runId = (int)$db->insert_id;
        $stmt->close();
    }
    $db->close();
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'error' => 'Result save failed: ' . $e->getMessage()]);
}

respond(200, [
    'ok' => true,
    'run_id' => $runId,
    'filename' => $originalName,
    'best_model' => $bestName,
    'best_f1' => (float)($bestMetrics['f1'] ?? 0),
]);
