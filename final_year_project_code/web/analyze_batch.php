<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if (!isset($_FILES['datasets']) || !is_array($_FILES['datasets']['name'] ?? null)) {
    echo '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Batch Analysis Error</title><link rel="stylesheet" href="styles.css"></head><body><div class="wrapper"><section class="card"><h1>Batch Analysis Failed</h1><div class="alert error">Please upload one or more CSV files.</div><p><a class="btn" href="index.php">Back to Upload</a></p></section></div></body></html>';
    exit;
}

$targetColumn = trim($_POST['target_column'] ?? 'Label');
$names = $_FILES['datasets']['name'];
$tmpNames = $_FILES['datasets']['tmp_name'];
$errors = $_FILES['datasets']['error'];

if (!function_exists('curl_init') || !class_exists('CURLFile')) {
    echo '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Batch Analysis Error</title><link rel="stylesheet" href="styles.css"></head><body><div class="wrapper"><section class="card"><h1>Batch Analysis Failed</h1><div class="alert error">Server configuration error: PHP cURL extension is not available.</div><p><a class="btn" href="index.php">Back to Upload</a></p></section></div></body></html>';
    exit;
}

$items = [];
$db = null;
$insertStmt = null;

try {
    $db = get_db_connection();
    $insertStmt = $db->prepare('INSERT INTO analysis_runs (uploaded_filename, best_model, best_f1, best_precision, best_recall, best_accuracy, false_positive_rate, analysis_payload_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
} catch (Throwable $e) {
    // Continue without DB persistence if unavailable.
}

for ($i = 0; $i < count($names); $i++) {
    $filename = (string)($names[$i] ?? '');
    if ($filename === '') {
        continue;
    }

    $item = [
        'filename' => $filename,
        'status' => 'Failed',
        'message' => 'Unknown error.',
        'run_id' => null,
        'best_model' => '-',
        'best_f1' => null,
    ];

    $uploadErr = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadErr !== UPLOAD_ERR_OK) {
        if ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE) {
            $item['message'] = 'File exceeds server upload limits.';
        } elseif ($uploadErr === UPLOAD_ERR_NO_FILE) {
            $item['message'] = 'No file uploaded.';
        } else {
            $item['message'] = 'Upload error code: ' . $uploadErr;
        }
        $items[] = $item;
        continue;
    }

    $tmpPath = (string)($tmpNames[$i] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        $item['message'] = 'Upload verification failed for this file.';
        $items[] = $item;
        continue;
    }

    $ch = curl_init();
    if ($ch === false) {
        $item['message'] = 'Failed to initialize ML request.';
        $items[] = $item;
        continue;
    }

    $postFields = [
        'file' => new CURLFile($tmpPath, 'text/csv', $filename),
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
        $item['message'] = 'ML request failed: ' . $curlError;
        $items[] = $item;
        continue;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        $snippet = trim(substr($response, 0, 180));
        $item['message'] = 'ML returned invalid response.' . ($snippet !== '' ? (' Snippet: ' . $snippet) : '');
        $items[] = $item;
        continue;
    }

    if ($httpCode >= 400) {
        $item['message'] = (string)($data['error'] ?? ('ML error (HTTP ' . $httpCode . ').'));
        $items[] = $item;
        continue;
    }

    $bestName = (string)($data['best_model']['name'] ?? 'Unknown');
    $bestMetrics = is_array($data['best_model']['metrics'] ?? null) ? $data['best_model']['metrics'] : [];
    $bestF1 = (float)($bestMetrics['f1'] ?? 0);
    $payloadJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $runId = null;
    if ($db && $insertStmt) {
        try {
            $bestPrecision = (float)($bestMetrics['precision'] ?? 0);
            $bestRecall = (float)($bestMetrics['recall'] ?? 0);
            $bestAccuracy = (float)($bestMetrics['accuracy'] ?? 0);
            $bestFpr = (float)($bestMetrics['false_positive_rate'] ?? 0);
            $insertStmt->bind_param('ssddddds', $filename, $bestName, $bestF1, $bestPrecision, $bestRecall, $bestAccuracy, $bestFpr, $payloadJson);
            $insertStmt->execute();
            $runId = (int)$db->insert_id;
        } catch (Throwable $e) {
            $runId = null;
        }
    }

    $item['status'] = 'Success';
    $item['message'] = 'Analyzed successfully.';
    $item['run_id'] = $runId;
    $item['best_model'] = $bestName;
    $item['best_f1'] = $bestF1;
    $items[] = $item;
}

if ($insertStmt) {
    $insertStmt->close();
}
if ($db) {
    $db->close();
}

$successCount = 0;
foreach ($items as $item) {
    if (($item['status'] ?? '') === 'Success') {
        $successCount++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Batch Analysis Results</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="wrapper">
    <section class="card">
      <h1>Batch Analysis Results</h1>
      <p class="small">Processed <?= h((string)count($items)) ?> file(s). Successful: <?= h((string)$successCount) ?>.</p>
      <?php if ($successCount === 0): ?>
        <div class="alert error">No file completed successfully. See per-file details below.</div>
      <?php else: ?>
        <div class="alert ok">Batch processing completed. Open each result using the View link.</div>
      <?php endif; ?>
    </section>

    <section class="card" style="margin-top:1rem;">
      <h2>Per-file Outcomes</h2>
      <table class="table">
        <thead>
          <tr>
            <th>File</th>
            <th>Status</th>
            <th>Best Model</th>
            <th>Best F1</th>
            <th>Details</th>
            <th>Message</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td><?= h((string)$item['filename']) ?></td>
              <td><?= h((string)$item['status']) ?></td>
              <td><?= h((string)$item['best_model']) ?></td>
              <td><?= $item['best_f1'] === null ? '-' : h(number_format((float)$item['best_f1'], 4)) ?></td>
              <td>
                <?php if (!empty($item['run_id'])): ?>
                  <a class="text-link" href="run.php?id=<?= urlencode((string)$item['run_id']) ?>">View</a>
                <?php else: ?>
                  -
                <?php endif; ?>
              </td>
              <td><?= h((string)$item['message']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <p style="margin-top:1rem;"><a class="btn" href="index.php">Back to Upload</a></p>
  </div>
</body>
</html>
