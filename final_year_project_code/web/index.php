<?php
require_once __DIR__ . '/db.php';

$recentRuns = [];
$dbError = null;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 5;
$totalRuns = 0;
$totalPages = 1;

try {
    $db = get_db_connection();
  
    $countResult = $db->query('SELECT COUNT(*) AS total FROM analysis_runs');
    if ($countResult) {
        $countRow = $countResult->fetch_assoc();
        $totalRuns = (int)($countRow['total'] ?? 0);
        $totalPages = max(1, (int)ceil($totalRuns / $perPage));
        $countResult->close();
    }

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * $perPage;

    // Build a safe query even when some metric columns do not exist yet.
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

    $sql = "SELECT id, uploaded_filename, {$bestModelExpr} AS best_model, {$bestF1Expr} AS best_f1, created_at FROM analysis_runs ORDER BY id DESC LIMIT ? OFFSET ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        $dbError = 'Prepare failed: ' . $db->error;
    } else {
        $stmt->bind_param('ii', $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $recentRuns[] = $row;
        }
        $stmt->close();
    }
    $db->close();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>IDS ML Analyser</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="wrapper">
    <section class="card">
      <h1>Machine Learning IDS Analyser</h1>
      <p class="small">
        Upload a labeled CSV (for example CICIDS2017-style data with a <code>Label</code> column) to train and compare
        Logistic Regression, Random Forest, and Support Vector Machine models for binary intrusion detection.
      </p>
    </section>

    <section class="card" style="margin-top: 1rem;">
      <h2>Upload Dataset</h2>
      <form id="upload-form" action="analyze.php" method="post" enctype="multipart/form-data">
        <label for="dataset">CSV file</label>
        <input type="file" id="dataset" name="dataset" accept=".csv" required>

        <label for="target_column">Target column name</label>
        <input type="text" id="target_column" name="target_column" value="Label" required>

        <div id="submit-row">
          <button id="analyze-btn" type="submit">Analyze Traffic Data</button>
        </div>
        <div id="upload-progress-box" class="upload-progress" style="display:none;">
          <div class="progress-head">
            <strong>Uploading and analyzing dataset...</strong>
          </div>
          <div class="progress-meta">
            <span id="progress-text">Starting...</span>
            <button id="cancel-btn" type="button" class="btn-secondary">Cancel</button>
          </div>
          <div class="analysis-progress-head">
            Analysis progress: <strong id="analysis-progress-pct">0%</strong>
          </div>
          <div class="progress-track progress-track-lg" aria-hidden="true">
            <span id="analysis-progress-fill"></span>
          </div>
        </div>
      </form>
      <p class="small">Binary mapping is applied automatically: values like "BENIGN" or "normal" -> normal (0), everything else -> malicious (1).</p>
    </section>

    <section class="card" style="margin-top: 1rem;">
      <h2>Batch Upload Datasets</h2>
      <form id="batch-upload-form" action="analyze_batch.php" method="post" enctype="multipart/form-data">
        <label for="datasets">CSV files (multiple)</label>
        <input type="file" id="datasets" name="datasets[]" accept=".csv" multiple required>

        <label for="target_column_batch">Target column name</label>
        <input type="text" id="target_column_batch" name="target_column" value="Label" required>

        <div id="submit-row-batch">
          <button id="analyze-btn-batch" type="submit">Analyze Batch</button>
        </div>
        <div id="upload-progress-box-batch" class="upload-progress" style="display:none;">
          <div class="progress-head">
            <strong>Uploading and analyzing datasets...</strong>
          </div>
          <div class="progress-meta">
            <span id="progress-text-batch">Starting...</span>
            <button id="cancel-btn-batch" type="button" class="btn-secondary">Cancel</button>
          </div>
          <div class="analysis-progress-head">
            Analysis progress: <strong id="analysis-progress-pct-batch">0%</strong>
          </div>
          <div class="progress-track progress-track-lg" aria-hidden="true">
            <span id="analysis-progress-fill-batch"></span>
          </div>
        </div>
      </form>
      <p class="small">Each file is analyzed independently and saved as its own run, with individual result pages.</p>
    </section>

    <section id="recent-analysis-runs" class="card" style="margin-top: 1rem;">
      <h2>Recent Analysis Runs</h2>
      <?php if ($dbError): ?>
        <div class="alert error">Database issue: <?= htmlspecialchars($dbError) ?></div>
      <?php elseif (count($recentRuns) === 0): ?>
        <p class="small">No runs saved yet. Upload your first dataset to begin evaluation.</p>
      <?php else: ?>
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
            <?php foreach ($recentRuns as $run): ?>
              <tr>
                <td><?= htmlspecialchars($run['id']) ?></td>
                <td><?= htmlspecialchars($run['uploaded_filename']) ?></td>
                <td><?= htmlspecialchars($run['best_model']) ?></td>
                <td><?= htmlspecialchars(number_format((float)$run['best_f1'], 4)) ?></td>
                <td><?= htmlspecialchars($run['created_at']) ?></td>
                <td><a class="text-link" href="run.php?id=<?= urlencode((string)$run['id']) ?>">View</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($totalPages > 1): ?>
          <div class="pager">
            <?php if ($page > 1): ?>
              <a class="btn-secondary" href="index.php?page=<?= $page - 1 ?>#recent-analysis-runs">Previous</a>
            <?php endif; ?>
            <span>Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
              <a class="btn-secondary" href="index.php?page=<?= $page + 1 ?>#recent-analysis-runs">Next</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <section class="card" style="margin-top: 1rem;">
      <h2>Overall Analysis Report</h2>
      <p class="small">Open the aggregate report with summary KPIs, visual charts, and recommendations across all saved runs.</p>
      <p style="margin-top: 1.25rem;">
        <a class="btn" href="report.php">View Overall Report</a>
      </p>
    </section>
  </div>
  <script>
    (function () {
      function initSingleForm(config) {
        const form = document.getElementById(config.formId);
        const submitRow = document.getElementById(config.submitRowId);
        const progressBox = document.getElementById(config.progressBoxId);
        const analysisProgressFill = document.getElementById(config.progressFillId);
        const analysisProgressPct = document.getElementById(config.progressPctId);
        const progressText = document.getElementById(config.progressTextId);
        const cancelBtn = document.getElementById(config.cancelBtnId);
        const analyzeBtn = document.getElementById(config.analyzeBtnId);
        const datasetInput = document.getElementById(config.fileInputId);
        if (!form || !submitRow || !progressBox || !analysisProgressFill || !analysisProgressPct || !progressText || !cancelBtn || !analyzeBtn || !datasetInput) {
          return;
        }

        let request = null;
        let analysisProgressTimer = null;
        let analysisStarted = false;
        let analysisFallbackTimer = null;

        function setAnalysisProgress(percent, message) {
          const safe = Math.max(0, Math.min(100, percent));
          analysisProgressFill.style.width = safe + '%';
          analysisProgressPct.textContent = (safe >= 100 ? '100' : safe.toFixed(1)) + '%';
          if (message) {
            progressText.textContent = message;
          }
        }

        function startAnalysisProgress() {
          if (analysisStarted) {
            return;
          }
          analysisStarted = true;
          let p = 8;
          analysisProgressTimer = window.setInterval(function () {
            if (p < 85) {
              p += 2.0;
            } else if (p < 97) {
              p += 0.7;
            } else if (p < 99.5) {
              p += 0.2;
            } else {
              p = 99.5;
            }
            setAnalysisProgress(p);
          }, 450);
        }

        function stopAnalysisProgress() {
          if (analysisProgressTimer) {
            clearInterval(analysisProgressTimer);
            analysisProgressTimer = null;
          }
          if (analysisFallbackTimer) {
            clearTimeout(analysisFallbackTimer);
            analysisFallbackTimer = null;
          }
        }

        form.addEventListener('submit', function (event) {
          event.preventDefault();
          if (!datasetInput.files || datasetInput.files.length === 0) {
            form.submit();
            return;
          }

          const formData = new FormData(form);
          request = new XMLHttpRequest();

          submitRow.style.display = 'none';
          progressBox.style.display = 'block';
          analyzeBtn.disabled = true;
          analysisStarted = false;
          progressText.textContent = 'Starting upload...';
          setAnalysisProgress(0);

          request.open('POST', form.action, true);
          request.responseType = 'text';

          request.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable) {
              progressText.textContent = config.uploadLabel + ': ' + Math.round((e.loaded / 1024 / 1024) * 10) / 10 + ' MB';
            } else {
              progressText.textContent = config.uploadLabel + '...';
            }
          });

          request.upload.addEventListener('loadend', function () {
            progressText.textContent = 'Upload complete. Running analysis...';
            setAnalysisProgress(5);
            startAnalysisProgress();
          });

          request.addEventListener('loadstart', function () {
            progressText.textContent = config.uploadLabel + '...';
          });

          request.addEventListener('load', function () {
            stopAnalysisProgress();
            if (request.status >= 200 && request.status < 300) {
              setAnalysisProgress(100, 'Done. Loading results...');
              document.open();
              document.write(request.responseText);
              document.close();
              return;
            }

            if (request.status === 413) {
              progressText.textContent = 'Upload too large for current server/request limits. Try fewer files per batch.';
            } else if (request.status > 0) {
              progressText.textContent = 'Request failed (HTTP ' + request.status + '). Please try again.';
            } else {
              progressText.textContent = 'Request failed. Please try again.';
            }
            cancelBtn.textContent = 'Back';
            cancelBtn.onclick = function () { window.location.reload(); };
          });

          request.addEventListener('error', function () {
            stopAnalysisProgress();
            if (config.isBatch) {
              progressText.textContent = 'Network error during upload. If many files are selected, try smaller batches.';
            } else {
              progressText.textContent = 'Network error during upload.';
            }
            cancelBtn.textContent = 'Back';
            cancelBtn.onclick = function () { window.location.reload(); };
          });

          request.addEventListener('abort', function () {
            stopAnalysisProgress();
            progressText.textContent = 'Upload canceled.';
            cancelBtn.textContent = 'Back';
            cancelBtn.onclick = function () { window.location.reload(); };
          });

          request.addEventListener('loadend', function () {
            if (request && request.readyState === 4 && request.status >= 200 && request.status < 500) {
              return;
            }
            analyzeBtn.disabled = false;
          });

          cancelBtn.onclick = function () {
            if (request && request.readyState !== 4) {
              request.abort();
            } else {
              window.location.reload();
            }
          };

          request.send(formData);
          analysisFallbackTimer = window.setTimeout(function () {
            if (request && request.readyState !== 4 && !analysisStarted) {
              setAnalysisProgress(5, 'Running analysis...');
              startAnalysisProgress();
            }
          }, 1500);
        });
      }

      initSingleForm({
        formId: 'upload-form',
        submitRowId: 'submit-row',
        progressBoxId: 'upload-progress-box',
        progressFillId: 'analysis-progress-fill',
        progressPctId: 'analysis-progress-pct',
        progressTextId: 'progress-text',
        cancelBtnId: 'cancel-btn',
        analyzeBtnId: 'analyze-btn',
        fileInputId: 'dataset',
        uploadLabel: 'Uploading dataset',
        isBatch: false
      });

      (function initBatchForm() {
        const form = document.getElementById('batch-upload-form');
        const submitRow = document.getElementById('submit-row-batch');
        const progressBox = document.getElementById('upload-progress-box-batch');
        const analysisProgressFill = document.getElementById('analysis-progress-fill-batch');
        const analysisProgressPct = document.getElementById('analysis-progress-pct-batch');
        const progressText = document.getElementById('progress-text-batch');
        const cancelBtn = document.getElementById('cancel-btn-batch');
        const analyzeBtn = document.getElementById('analyze-btn-batch');
        const fileInput = document.getElementById('datasets');
        const targetInput = document.getElementById('target_column_batch');
        if (!form || !submitRow || !progressBox || !analysisProgressFill || !analysisProgressPct || !progressText || !cancelBtn || !analyzeBtn || !fileInput || !targetInput) {
          return;
        }

        let activeRequest = null;
        let canceled = false;

        function setProgress(percent, message) {
          const safe = Math.max(0, Math.min(100, percent));
          analysisProgressFill.style.width = safe + '%';
          analysisProgressPct.textContent = (safe >= 100 ? '100' : safe.toFixed(1)) + '%';
          if (message) {
            progressText.textContent = message;
          }
        }

        function postOneFile(file, targetColumn, onUploadProgress) {
          return new Promise(function (resolve) {
            const req = new XMLHttpRequest();
            activeRequest = req;
            const data = new FormData();
            data.append('dataset', file);
            data.append('target_column', targetColumn);
            req.open('POST', 'analyze_api.php', true);
            req.responseType = 'json';

            req.upload.addEventListener('progress', function (e) {
              if (typeof onUploadProgress === 'function') {
                onUploadProgress(e);
              }
            });

            req.addEventListener('load', function () {
              const body = req.response && typeof req.response === 'object' ? req.response : null;
              if (req.status >= 200 && req.status < 300 && body && body.ok) {
                resolve({ ok: true, data: body });
                return;
              }
              const err = body && body.error ? body.error : ('HTTP ' + req.status);
              resolve({ ok: false, error: err, status: req.status });
            });

            req.addEventListener('error', function () {
              resolve({ ok: false, error: 'Network error', status: 0 });
            });

            req.addEventListener('abort', function () {
              resolve({ ok: false, error: 'Canceled', aborted: true, status: 0 });
            });

            req.send(data);
          });
        }

        form.addEventListener('submit', async function (event) {
          event.preventDefault();
          const files = fileInput.files ? Array.from(fileInput.files) : [];
          if (files.length === 0) {
            return;
          }

          canceled = false;
          submitRow.style.display = 'none';
          progressBox.style.display = 'block';
          analyzeBtn.disabled = true;
          cancelBtn.textContent = 'Cancel';
          setProgress(0, 'Starting batch upload...');

          const runIds = [];
          const failures = [];
          const n = files.length;
          const targetColumn = targetInput.value || 'Label';

          cancelBtn.onclick = function () {
            canceled = true;
            if (activeRequest && activeRequest.readyState !== 4) {
              activeRequest.abort();
            } else {
              window.location.reload();
            }
          };

          for (let i = 0; i < n; i++) {
            if (canceled) {
              break;
            }
            const file = files[i];
            const start = (i / n) * 100;
            const end = ((i + 1) / n) * 100;
            setProgress(start, 'Uploading file ' + (i + 1) + '/' + n + ': ' + file.name);

            const result = await postOneFile(file, targetColumn, function (e) {
              if (!e.lengthComputable) {
                return;
              }
              const frac = e.total > 0 ? (e.loaded / e.total) : 0;
              const p = start + (end - start) * Math.max(0, Math.min(1, frac * 0.65));
              setProgress(p, 'Uploading file ' + (i + 1) + '/' + n + ': ' + file.name);
            });

            if (canceled || (result && result.aborted)) {
              break;
            }

            if (result.ok && result.data && result.data.run_id) {
              runIds.push(result.data.run_id);
              setProgress(end, 'Finished file ' + (i + 1) + '/' + n + ': ' + file.name);
            } else {
              const message = result && result.error ? result.error : 'Unknown error';
              failures.push(file.name + ': ' + message);
              setProgress(end, 'Processed file ' + (i + 1) + '/' + n + ' (with error)');
            }
          }

          analyzeBtn.disabled = false;
          if (canceled) {
            progressText.textContent = 'Batch upload canceled.';
            cancelBtn.textContent = 'Back';
            cancelBtn.onclick = function () { window.location.reload(); };
            return;
          }

          if (runIds.length > 0) {
            setProgress(100, 'Batch complete. Loading results...');
            window.location.href = 'batch_results.php?ids=' + encodeURIComponent(runIds.join(','));
            return;
          }

          const preview = failures.length > 0 ? failures[0] : 'No files were processed.';
          progressText.textContent = 'Batch failed. ' + preview;
          cancelBtn.textContent = 'Back';
          cancelBtn.onclick = function () { window.location.reload(); };
        });
      })();
    })();
  </script>
</body>
</html>