<?php
require_once __DIR__ . '/db.php';

$runs = [];
$dbError = null;

try {
    $db = get_db_connection();

    $columns = [];
    $colResult = $db->query("SHOW COLUMNS FROM analysis_runs");
    if ($colResult) {
        while ($c = $colResult->fetch_assoc()) {
            $name = (string)($c['Field'] ?? '');
            if ($name !== '') {
                $columns[$name] = true;
            }
        }
        $colResult->close();
    }

    $bestModelExpr = isset($columns['best_model']) ? "COALESCE(best_model, 'Unknown')" : "'Unknown'";
    $bestF1Expr = isset($columns['best_f1']) ? "COALESCE(best_f1, 0)" : "0";
    $bestPrecisionExpr = isset($columns['best_precision']) ? "COALESCE(best_precision, 0)" : "0";
    $bestRecallExpr = isset($columns['best_recall']) ? "COALESCE(best_recall, 0)" : "0";
    $bestAccuracyExpr = isset($columns['best_accuracy']) ? "COALESCE(best_accuracy, 0)" : "0";
    $fprExpr = isset($columns['false_positive_rate']) ? "COALESCE(false_positive_rate, 0)" : "0";

    $sql = "SELECT
                id,
                uploaded_filename,
                {$bestModelExpr} AS best_model,
                {$bestF1Expr} AS best_f1,
                {$bestPrecisionExpr} AS best_precision,
                {$bestRecallExpr} AS best_recall,
                {$bestAccuracyExpr} AS best_accuracy,
                {$fprExpr} AS false_positive_rate,
                created_at
            FROM analysis_runs
            ORDER BY id ASC";

    $result = $db->query($sql);
    if (!$result) {
        $dbError = 'Report query failed: ' . $db->error;
    } else {
        while ($row = $result->fetch_assoc()) {
            $runs[] = $row;
        }
        $result->close();
    }

    $db->close();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

function avg(array $values): float
{
    if (count($values) === 0) {
        return 0.0;
    }
    return array_sum($values) / count($values);
}

function stddev(array $values): float
{
    if (count($values) < 2) {
        return 0.0;
    }
    $mean = avg($values);
    $sum = 0.0;
    foreach ($values as $v) {
        $sum += ($v - $mean) * ($v - $mean);
    }
    return sqrt($sum / count($values));
}

function pct(float $v): string
{
    return number_format($v * 100, 2) . '%';
}

$f1s = [];
$precisions = [];
$recalls = [];
$accuracies = [];
$fprs = [];
$modelCounts = [];
$timeline = [];

foreach ($runs as $run) {
    $f1 = (float)($run['best_f1'] ?? 0);
    $precision = (float)($run['best_precision'] ?? 0);
    $recall = (float)($run['best_recall'] ?? 0);
    $accuracy = (float)($run['best_accuracy'] ?? 0);
    $fpr = (float)($run['false_positive_rate'] ?? 0);
    $model = (string)($run['best_model'] ?? 'Unknown');

    $f1s[] = $f1;
    $precisions[] = $precision;
    $recalls[] = $recall;
    $accuracies[] = $accuracy;
    $fprs[] = $fpr;
    $modelCounts[$model] = (int)($modelCounts[$model] ?? 0) + 1;
    $timeline[] = [
        'label' => (string)$run['created_at'],
        'f1' => $f1,
    ];
}

$totalRuns = count($runs);
$avgF1 = avg($f1s);
$avgPrecision = avg($precisions);
$avgRecall = avg($recalls);
$avgAccuracy = avg($accuracies);
$avgFpr = avg($fprs);
$f1Volatility = stddev($f1s);
$bestModelOverall = '-';

if (count($modelCounts) > 0) {
    arsort($modelCounts);
    $bestModelOverall = (string)array_key_first($modelCounts);
}

$recommendations = [];
if ($totalRuns < 5) {
    $recommendations[] = 'Increase coverage: run at least 5-10 diverse datasets for a stable baseline.';
}
if ($avgF1 < 0.90) {
    $recommendations[] = 'Improve detection quality: review label quality, class balance, and feature engineering to raise average F1.';
}
if ($avgRecall < 0.90) {
    $recommendations[] = 'Reduce missed attacks: prioritize recall tuning (class weighting / threshold strategy) for high-risk environments.';
}
if ($avgFpr > 0.05) {
    $recommendations[] = 'Lower false alarms: current average false-positive rate is high; tune features/model selection and validate per-traffic profile.';
}
if ($f1Volatility > 0.08) {
    $recommendations[] = 'Stability risk detected: high run-to-run F1 volatility suggests dataset shift; add drift checks and scenario-specific validation.';
}
if (count($modelCounts) > 0) {
    $topCount = (int)($modelCounts[$bestModelOverall] ?? 0);
    if ($totalRuns > 0 && ($topCount / $totalRuns) >= 0.8) {
        $recommendations[] = 'Model selection is consistent (' . $bestModelOverall . ' dominates); consider standardizing it as default with periodic re-evaluation.';
    }
}
if (count($recommendations) === 0 && $totalRuns > 0) {
    $recommendations[] = 'Overall performance is strong and stable; continue periodic batch evaluations to monitor drift over time.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Overall Analysis Report</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="wrapper">
    <section class="card">
      <h1>Overall Analysis Report</h1>
      <p class="small">Aggregate view across all recorded analysis runs.</p>
      <p style="margin-top: 0.8rem;"><a class="btn" href="index.php">Back to Upload</a></p>
    </section>

    <section class="card" style="margin-top: 1rem;">
      <h2>Summary KPIs</h2>
      <?php if ($dbError): ?>
        <div class="alert error">Database issue: <?= htmlspecialchars($dbError) ?></div>
      <?php elseif ($totalRuns === 0): ?>
        <div class="alert error">No analysis runs available yet. Run at least one dataset to generate this report.</div>
      <?php else: ?>
        <div class="grid">
          <div class="metric"><div class="k">Total Runs</div><div class="v"><?= htmlspecialchars((string)$totalRuns) ?></div></div>
          <div class="metric"><div class="k">Avg Accuracy</div><div class="v"><?= htmlspecialchars(pct($avgAccuracy)) ?></div></div>
          <div class="metric"><div class="k">Avg Precision</div><div class="v"><?= htmlspecialchars(pct($avgPrecision)) ?></div></div>
          <div class="metric"><div class="k">Avg Recall</div><div class="v"><?= htmlspecialchars(pct($avgRecall)) ?></div></div>
          <div class="metric"><div class="k">Avg F1</div><div class="v"><?= htmlspecialchars(pct($avgF1)) ?></div></div>
          <div class="metric"><div class="k">Avg False Positive Rate</div><div class="v"><?= htmlspecialchars(pct($avgFpr)) ?></div></div>
          <div class="metric"><div class="k">F1 Volatility (Std)</div><div class="v"><?= htmlspecialchars(number_format($f1Volatility, 4)) ?></div></div>
          <div class="metric"><div class="k">Most Frequent Best Model</div><div class="v"><?= htmlspecialchars($bestModelOverall) ?></div></div>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($totalRuns > 0): ?>
      <section class="card" style="margin-top: 1rem;">
        <h2>Overall Charts</h2>
        <div class="chart-grid">
          <div>
            <h3>F1 Trend Across Runs</h3>
            <canvas id="trendChart" width="520" height="280"></canvas>
          </div>
          <div>
            <h3>Best Model Distribution</h3>
            <canvas id="modelDonut" width="520" height="280"></canvas>
          </div>
          <div>
            <h3>Average Metric Comparison</h3>
            <canvas id="avgBar" width="520" height="280"></canvas>
          </div>
        </div>
      </section>

      <section class="card" style="margin-top: 1rem;">
        <h2>Recommendations</h2>
        <ul class="recommend-list">
          <?php foreach ($recommendations as $item): ?>
            <li><?= htmlspecialchars($item) ?></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
  </div>

  <?php if ($totalRuns > 0): ?>
  <script>
    (function () {
      const timeline = <?= json_encode($timeline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const modelCounts = <?= json_encode($modelCounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const avgMetrics = {
        accuracy: <?= json_encode($avgAccuracy) ?>,
        precision: <?= json_encode($avgPrecision) ?>,
        recall: <?= json_encode($avgRecall) ?>,
        f1: <?= json_encode($avgF1) ?>,
        oneMinusFpr: <?= json_encode(max(0, 1 - $avgFpr)) ?>
      };

      function clearCanvas(ctx) {
        ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);
        ctx.font = '12px Segoe UI, Tahoma, sans-serif';
        ctx.fillStyle = '#0f172a';
      }

      function drawTrend(progress) {
        const canvas = document.getElementById('trendChart');
        const ctx = canvas.getContext('2d');
        clearCanvas(ctx);
        const w = canvas.width, h = canvas.height;
        const m = {top: 20, right: 12, bottom: 40, left: 40};
        const iw = w - m.left - m.right, ih = h - m.top - m.bottom;
        ctx.strokeStyle = '#94a3b8';
        ctx.beginPath();
        ctx.moveTo(m.left, m.top);
        ctx.lineTo(m.left, m.top + ih);
        ctx.lineTo(m.left + iw, m.top + ih);
        ctx.stroke();
        if (timeline.length < 1) return;
        ctx.strokeStyle = '#2563eb';
        ctx.lineWidth = 2;
        ctx.beginPath();
        timeline.forEach((pt, i) => {
          const x = m.left + (timeline.length === 1 ? 0 : (i / (timeline.length - 1)) * iw);
          const y = m.top + ih - (Math.max(0, Math.min(1, pt.f1 || 0)) * ih * progress);
          if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        });
        ctx.stroke();
        ctx.fillStyle = '#2563eb';
        timeline.forEach((pt, i) => {
          const x = m.left + (timeline.length === 1 ? 0 : (i / (timeline.length - 1)) * iw);
          const y = m.top + ih - (Math.max(0, Math.min(1, pt.f1 || 0)) * ih * progress);
          ctx.beginPath();
          ctx.arc(x, y, 3.5, 0, Math.PI * 2);
          ctx.fill();
        });
      }

      function drawDonut(progress) {
        const canvas = document.getElementById('modelDonut');
        const ctx = canvas.getContext('2d');
        clearCanvas(ctx);
        const entries = Object.entries(modelCounts);
        if (entries.length === 0) return;
        const total = entries.reduce((s, e) => s + Number(e[1]), 0);
        const colors = ['#2563eb', '#16a34a', '#ea580c', '#7c3aed', '#0891b2', '#dc2626'];
        const cx = canvas.width / 2, cy = canvas.height / 2 + 6, r = 88, lw = 34;
        let start = -Math.PI / 2;
        ctx.lineWidth = lw;
        const maxSweep = Math.PI * 2 * progress;
        let usedSweep = 0;
        entries.forEach((e, i) => {
          if (usedSweep >= maxSweep) {
            return;
          }
          const frac = Number(e[1]) / total;
          const sliceSweep = frac * Math.PI * 2;
          const drawableSweep = Math.min(sliceSweep, maxSweep - usedSweep);
          const end = start + drawableSweep;
          ctx.strokeStyle = colors[i % colors.length];
          ctx.beginPath();
          ctx.arc(cx, cy, r, start, end);
          ctx.stroke();
          start += sliceSweep;
          usedSweep += drawableSweep;
        });
        let y = 20;
        entries.forEach((e, i) => {
          ctx.fillStyle = colors[i % colors.length];
          ctx.fillRect(18, y - 10, 10, 10);
          ctx.fillStyle = '#0f172a';
          const pct = ((Number(e[1]) / total) * 100).toFixed(1);
          ctx.fillText(e[0] + ' (' + pct + '%)', 34, y);
          y += 16;
        });
      }

      function drawAvgBar(progress) {
        const canvas = document.getElementById('avgBar');
        const ctx = canvas.getContext('2d');
        clearCanvas(ctx);
        const pairs = [
          ['Accuracy', avgMetrics.accuracy],
          ['Precision', avgMetrics.precision],
          ['Recall', avgMetrics.recall],
          ['F1', avgMetrics.f1],
          ['1 - FPR', avgMetrics.oneMinusFpr]
        ];
        const w = canvas.width, h = canvas.height;
        const m = {top: 20, right: 14, bottom: 54, left: 40};
        const iw = w - m.left - m.right, ih = h - m.top - m.bottom;
        ctx.strokeStyle = '#94a3b8';
        ctx.beginPath();
        ctx.moveTo(m.left, m.top);
        ctx.lineTo(m.left, m.top + ih);
        ctx.lineTo(m.left + iw, m.top + ih);
        ctx.stroke();
        const bw = (iw / pairs.length) * 0.62;
        pairs.forEach((p, i) => {
          const val = Math.max(0, Math.min(1, Number(p[1] || 0)));
          const x = m.left + (i + 0.19) * (iw / pairs.length);
          const bh = val * ih * progress;
          const y = m.top + ih - bh;
          ctx.fillStyle = '#16a34a';
          ctx.fillRect(x, y, bw, bh);
          ctx.fillStyle = '#0f172a';
          ctx.fillText((val * 100 * progress).toFixed(1) + '%', x, y - 5);
          ctx.save();
          ctx.translate(x + bw / 2, m.top + ih + 16);
          ctx.rotate(-0.3);
          ctx.textAlign = 'center';
          ctx.fillText(p[0], 0, 0);
          ctx.restore();
        });
      }

      const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduceMotion) {
        drawTrend(1);
        drawDonut(1);
        drawAvgBar(1);
        return;
      }

      const duration = 900;
      const startAt = performance.now();
      function tick(now) {
        const p = Math.min(1, (now - startAt) / duration);
        drawTrend(p);
        drawDonut(p);
        drawAvgBar(p);
        if (p < 1) {
          requestAnimationFrame(tick);
        }
      }
      requestAnimationFrame(tick);
    })();
  </script>
  <?php endif; ?>
</body>
</html>