<?php
require_once __DIR__ . '/db.php';

$idsParam = trim($_GET['ids'] ?? '');
$idParts = array_filter(array_map('trim', explode(',', $idsParam)), static function ($v) {
    return $v !== '' && ctype_digit($v);
});
$ids = array_map('intval', $idParts);
$rows = [];
$dbError = null;

if (count($ids) > 0) {
    try {
        $db = get_db_connection();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $db->prepare("SELECT id, uploaded_filename, best_model, best_f1, created_at FROM analysis_runs WHERE id IN ($placeholders) ORDER BY id DESC");
        if ($stmt) {
            $stmt->bind_param($types, ...$ids);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
        $db->close();
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Batch Results</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="wrapper">
    <section class="card">
      <h1>Batch Analysis Results</h1>
      <?php if ($dbError): ?>
        <div class="alert error">Database issue: <?= htmlspecialchars($dbError) ?></div>
      <?php elseif (count($rows) === 0): ?>
        <div class="alert error">No successful batch runs were found.</div>
      <?php else: ?>
        <p class="small">Completed runs: <?= htmlspecialchars((string)count($rows)) ?></p>
        <table class="table">
          <thead>
            <tr>
              <th>ID</th>
              <th>File</th>
              <th>Best Model</th>
              <th>Best F1</th>
              <th>Created</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $run): ?>
              <tr>
                <td><?= htmlspecialchars((string)$run['id']) ?></td>
                <td><?= htmlspecialchars((string)$run['uploaded_filename']) ?></td>
                <td><?= htmlspecialchars((string)$run['best_model']) ?></td>
                <td><?= htmlspecialchars(number_format((float)$run['best_f1'], 4)) ?></td>
                <td><?= htmlspecialchars((string)$run['created_at']) ?></td>
                <td><a class="text-link" href="run.php?id=<?= urlencode((string)$run['id']) ?>">View</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <p style="margin-top: 1rem;"><a class="btn" href="index.php">Back to Upload</a></p>
    </section>
  </div>
</body>
</html>
