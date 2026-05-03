<?php
if (!isset($data) || !is_array($data)) {
    throw new RuntimeException('Result view requires $data array.');
}

$results = is_array($data['results'] ?? null) ? $data['results'] : [];
$bestName = (string)($data['best_model']['name'] ?? 'Unknown');
$bestMetrics = is_array($data['best_model']['metrics'] ?? null) ? $data['best_model']['metrics'] : [];
$backHref = isset($backHref) ? (string)$backHref : 'index.php';
$backText = isset($backText) ? (string)$backText : 'Analyze Another Dataset';

if (!function_exists('pct')) {
    function pct($value): string
    {
        return number_format(((float)$value) * 100, 2) . '%';
    }
}

$chartPayload = [];
foreach ($results as $modelName => $metrics) {
    $chartPayload[] = [
        'name' => (string)$modelName,
        'accuracy' => (float)($metrics['accuracy'] ?? 0),
        'precision' => (float)($metrics['precision'] ?? 0),
        'recall' => (float)($metrics['recall'] ?? 0),
        'f1' => (float)($metrics['f1'] ?? 0),
        'false_positive_rate' => (float)($metrics['false_positive_rate'] ?? 0),
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Analysis Results</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="wrapper">
    <section class="card">
      <h1>Detection Results</h1>
      <div class="alert ok">
        Best model: <strong><?= htmlspecialchars($bestName) ?></strong>
      </div>
      <p class="small">Rows analyzed: <?= htmlspecialchars((string)($data['rows'] ?? 'N/A')) ?> | Features used: <?= htmlspecialchars((string)($data['features_used'] ?? 'N/A')) ?></p>
    </section>

    <section class="card" style="margin-top: 1rem;">
      <h2>Best Model Metrics</h2>
      <div class="grid">
        <div class="metric"><div class="k">Accuracy</div><div class="v"><?= pct($bestMetrics['accuracy'] ?? 0) ?></div></div>
        <div class="metric"><div class="k">Precision</div><div class="v"><?= pct($bestMetrics['precision'] ?? 0) ?></div></div>
        <div class="metric"><div class="k">Recall</div><div class="v"><?= pct($bestMetrics['recall'] ?? 0) ?></div></div>
        <div class="metric"><div class="k">F1-score</div><div class="v"><?= pct($bestMetrics['f1'] ?? 0) ?></div></div>
        <div class="metric"><div class="k">False Positive Rate</div><div class="v"><?= pct($bestMetrics['false_positive_rate'] ?? 0) ?></div></div>
      </div>
    </section>

    <section class="card" style="margin-top: 1rem;">
      <h2>Model Comparison (F1-score)</h2>
      <div class="bar-wrap">
        <?php foreach ($results as $modelName => $metrics): ?>
          <?php $width = max(0, min(100, ((float)($metrics['f1'] ?? 0)) * 100)); ?>
          <div class="bar-row">
            <div><?= htmlspecialchars((string)$modelName) ?></div>
            <div class="bar"><span style="width: <?= htmlspecialchars((string)$width) ?>%"></span></div>
            <div><?= htmlspecialchars(number_format((float)($metrics['f1'] ?? 0), 4)) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card" style="margin-top: 1rem;">
      <h2>Detailed Metrics</h2>
      <table class="table">
        <thead>
          <tr>
            <th>Model</th>
            <th>Accuracy</th>
            <th>Precision</th>
            <th>Recall</th>
            <th>F1-score</th>
            <th>False Positive Rate</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($results as $modelName => $metrics): ?>
            <tr>
              <td><?= htmlspecialchars((string)$modelName) ?></td>
              <td><?= htmlspecialchars(number_format((float)($metrics['accuracy'] ?? 0), 4)) ?></td>
              <td><?= htmlspecialchars(number_format((float)($metrics['precision'] ?? 0), 4)) ?></td>
              <td><?= htmlspecialchars(number_format((float)($metrics['recall'] ?? 0), 4)) ?></td>
              <td><?= htmlspecialchars(number_format((float)($metrics['f1'] ?? 0), 4)) ?></td>
              <td><?= htmlspecialchars(number_format((float)($metrics['false_positive_rate'] ?? 0), 4)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <section class="card" style="margin-top: 1rem;">
      <h2>Visual Charts</h2>
      <div class="chart-grid">
        <div>
          <h3>F1 Score by Model (Bar)</h3>
          <canvas id="f1BarChart" width="520" height="280"></canvas>
        </div>
        <div>
          <h3>Best Model: Accuracy vs Error (Donut)</h3>
          <canvas id="bestDonutChart" width="520" height="280"></canvas>
        </div>
      </div>
    </section>

    <p style="margin-top:1rem;"><a class="btn" href="<?= htmlspecialchars($backHref) ?>"><?= htmlspecialchars($backText) ?></a></p>
  </div>
  <script>
    (function () {
      const models = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const bestAccuracy = <?= json_encode((float)($bestMetrics['accuracy'] ?? 0)) ?>;

      function clearCanvas(ctx) {
        ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);
        ctx.font = '12px Segoe UI, Tahoma, sans-serif';
        ctx.fillStyle = '#0f172a';
      }

      function drawF1BarChart(progress) {
        const canvas = document.getElementById('f1BarChart');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        clearCanvas(ctx);
        const w = canvas.width;
        const h = canvas.height;
        const m = { top: 20, right: 16, bottom: 70, left: 44 };
        const innerW = w - m.left - m.right;
        const innerH = h - m.top - m.bottom;

        ctx.strokeStyle = '#94a3b8';
        ctx.beginPath();
        ctx.moveTo(m.left, m.top);
        ctx.lineTo(m.left, m.top + innerH);
        ctx.lineTo(m.left + innerW, m.top + innerH);
        ctx.stroke();

        const count = Math.max(models.length, 1);
        const barW = innerW / count * 0.62;
        models.forEach((row, i) => {
          const x = m.left + (i + 0.19) * (innerW / count);
          const value = Math.max(0, Math.min(1, row.f1 || 0));
          const bh = value * innerH * progress;
          const y = m.top + innerH - bh;

          ctx.fillStyle = '#16a34a';
          ctx.fillRect(x, y, barW, bh);
          ctx.fillStyle = '#0f172a';
          ctx.fillText((value * 100 * progress).toFixed(1) + '%', x, y - 6);
          ctx.save();
          ctx.translate(x + barW / 2, m.top + innerH + 16);
          ctx.rotate(-0.35);
          ctx.textAlign = 'center';
          ctx.fillText(row.name, 0, 0);
          ctx.restore();
        });
      }

      function drawDonutChart(progress) {
        const canvas = document.getElementById('bestDonutChart');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        clearCanvas(ctx);
        const w = canvas.width;
        const h = canvas.height;
        const cx = w / 2;
        const cy = h / 2 + 8;
        const r = 90;
        const width = 34;
        const acc = Math.max(0, Math.min(1, bestAccuracy));
        const shownAcc = acc * progress;
        const err = 1 - acc;
        const start = -Math.PI / 2;

        ctx.lineWidth = width;
        ctx.strokeStyle = '#16a34a';
        ctx.beginPath();
        ctx.arc(cx, cy, r, start, start + (Math.PI * 2 * shownAcc));
        ctx.stroke();

        ctx.strokeStyle = '#ef4444';
        ctx.beginPath();
        ctx.arc(cx, cy, r, start + (Math.PI * 2 * shownAcc), start + (Math.PI * 2 * progress));
        ctx.stroke();

        ctx.fillStyle = '#0f172a';
        ctx.textAlign = 'center';
        ctx.font = 'bold 20px Segoe UI, Tahoma, sans-serif';
        ctx.fillText((acc * 100 * progress).toFixed(1) + '%', cx, cy + 6);
        ctx.font = '12px Segoe UI, Tahoma, sans-serif';
        ctx.fillText('Accuracy', cx, cy + 24);

        ctx.textAlign = 'left';
        ctx.fillStyle = '#16a34a';
        ctx.fillRect(18, 20, 12, 12);
        ctx.fillStyle = '#0f172a';
        ctx.fillText('Accuracy: ' + (acc * 100).toFixed(2) + '%', 36, 30);

        ctx.fillStyle = '#ef4444';
        ctx.fillRect(18, 40, 12, 12);
        ctx.fillStyle = '#0f172a';
        ctx.fillText('Error: ' + (err * 100).toFixed(2) + '%', 36, 50);
      }

      const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduceMotion) {
        drawF1BarChart(1);
        drawDonutChart(1);
        return;
      }

      const duration = 900;
      const startAt = performance.now();
      function tick(now) {
        const p = Math.min(1, (now - startAt) / duration);
        drawF1BarChart(p);
        drawDonutChart(p);
        if (p < 1) {
          requestAnimationFrame(tick);
        }
      }
      requestAnimationFrame(tick);
    })();
  </script>
</body>
</html>
