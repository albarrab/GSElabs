<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function fail_with(string $message): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Analysis Error</title><link rel="stylesheet" href="styles.css"></head><body><div class="wrapper"><section class="card"><h1>Analysis Failed</h1><div class="alert error">' . htmlspecialchars($message) . '</div><p><a class="btn" href="index.php">Back to Upload</a></p></section></div></body></html>';
    exit;
}

if (!isset($_FILES['dataset'])) {
    fail_with('Please upload a CSV file.');
}

if ($_FILES['dataset']['error'] !== UPLOAD_ERR_OK) {
    if ($_FILES['dataset']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['dataset']['error'] === UPLOAD_ERR_FORM_SIZE) {
        fail_with('Uploaded file is too large for current server limits. Please upload a smaller file or increase upload_max_filesize/post_max_size.');
    }
    fail_with('Upload failed. Please try again with a valid CSV file.');
}

$tmpPath = $_FILES['dataset']['tmp_name'];
$originalName = $_FILES['dataset']['name'];
$targetColumn = trim($_POST['target_column'] ?? 'Label');

if (!function_exists('curl_init') || !class_exists('CURLFile')) {
    fail_with('Server configuration error: PHP cURL extension is not available.');
}

if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    fail_with('Upload verification failed. Please retry the upload.');
}

$ch = curl_init();
if ($ch === false) {
    fail_with('Unable to initialize ML request.');
}

$postFields = [
    'file' => new CURLFile($tmpPath, 'text/csv', $originalName),
    'target_column' => $targetColumn,
];

curl_setopt_array($ch, [
    CURLOPT_URL => rtrim($ML_API_URL, '/') . '/analyze',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_TIMEOUT => 1200,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    fail_with('ML service request failed: ' . $curlError);
}

$data = json_decode($response, true);
if (!is_array($data)) {
    $snippet = trim(substr($response, 0, 300));
    $detail = $snippet !== '' ? (' Response snippet: ' . $snippet) : '';
    fail_with('ML service returned invalid response.' . $detail);
}

if ($httpCode >= 400) {
    $message = $data['error'] ?? ('ML service error (HTTP ' . $httpCode . ').');
    fail_with($message);
}

$results = $data['results'] ?? [];
$bestName = $data['best_model']['name'] ?? 'Unknown';
$bestMetrics = $data['best_model']['metrics'] ?? [];
$payloadJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
        $stmt->close();
    }
    $db->close();
} catch (Throwable $e) {
    // Keep analysis usable even if DB save fails.
}
$backHref = 'index.php';
$backText = 'Analyze Another Dataset';
try {
    require __DIR__ . '/results_view.php';
} catch (Throwable $e) {
    fail_with('Unable to render results page: ' . $e->getMessage());
}
