<?php
require_once __DIR__ . '/db.php';

$runId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$run = null;
$error = null;

if ($runId <= 0) {
    $error = 'Invalid analysis run ID.';
} else {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare('SELECT id, uploaded_filename, best_model, best_f1, best_precision, best_recall, best_accuracy, false_positive_rate, created_at, analysis_payload_json FROM analysis_runs WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $runId);
            $stmt->execute();
            $result = $stmt->get_result();
            $run = $result->fetch_assoc() ?: null;
            $stmt->close();
        }
        $db->close();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function pct($value): string
{
    return number_format(((float)$value) * 100, 2) . '%';
}

if (is_array($run) && isset($run['analysis_payload_json']) && is_string($run['analysis_payload_json']) && $run['analysis_payload_json'] !== '') {
    $decoded = json_decode($run['analysis_payload_json'], true);
    if (is_array($decoded) && isset($decoded['results']) && isset($decoded['best_model'])) {
        $data = $decoded;
        $backHref = 'index.php';
        $backText = 'Back to Upload';
        require __DIR__ . '/results_view.php';
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Analysis Run</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="wrapper">
    <section class="card">
      <h1>Analysis Run Details</h1>
      <?php if ($error): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
      <?php elseif (!$run): ?>
        <div class="alert error">Analysis run not found.</div>
      <?php else: ?>
        <p class="small">
          Run #<?= htmlspecialchars((string)$run['id']) ?> |
          File: <?= htmlspecialchars($run['uploaded_filename']) ?> |
          Created: <?= htmlspecialchars($run['created_at']) ?>
        </p>
        <div class="alert ok">Best model: <strong><?= htmlspecialchars($run['best_model']) ?></strong></div>
        <div class="grid" style="margin-top: 1rem;">
          <div class="metric"><div class="k">Accuracy</div><div class="v"><?= pct($run['best_accuracy']) ?></div></div>
          <div class="metric"><div class="k">Precision</div><div class="v"><?= pct($run['best_precision']) ?></div></div>
          <div class="metric"><div class="k">Recall</div><div class="v"><?= pct($run['best_recall']) ?></div></div>
          <div class="metric"><div class="k">F1-score</div><div class="v"><?= pct($run['best_f1']) ?></div></div>
          <div class="metric"><div class="k">False Positive Rate</div><div class="v"><?= pct($run['false_positive_rate']) ?></div></div>
        </div>
      <?php endif; ?>
      <p style="margin-top: 1rem;"><a class="btn" href="index.php">Back to Upload</a></p>
    </section>
  </div>
</body>
</html>
