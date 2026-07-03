<?php
$pageTitle = 'Database Backup';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/b2.php';
requireRole('super_admin');

$backupDir = __DIR__ . '/../../uploads/backups/';
$s         = getSettings($pdo);
$b2Ok      = b2IsConfigured($s);
$b2        = $b2Ok ? b2FromSettings($s) : null;

// ── Actions ──────────────────────────────────────────────────────────

// Download local backup
if (isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $path = $backupDir . $file;
    if (file_exists($path) && str_ends_with($file, '.sql')) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path); exit;
    }
}

// Delete local backup
if (isset($_GET['delete_local'])) {
    $file = basename($_GET['delete_local']);
    $path = $backupDir . $file;
    if (file_exists($path) && str_ends_with($file, '.sql')) {
        unlink($path);
        auditLog($pdo, 'backup_local_deleted', 'system', 0, ['file' => $file]);
        setFlash('success', "Local backup <strong>{$file}</strong> deleted.");
    }
    header('Location: backup.php'); exit;
}

// Upload existing local file to B2
if (isset($_GET['upload_b2']) && $b2) {
    $file = basename($_GET['upload_b2']);
    $path = $backupDir . $file;
    if (file_exists($path)) {
        $result = $b2->upload($path, 'backups/' . $file);
        if ($result) {
            auditLog($pdo, 'backup_uploaded_b2', 'system', 0, null, ['file' => $file, 'fileId' => $result['fileId'] ?? '']);
            setFlash('success', "Uploaded <strong>{$file}</strong> to Backblaze B2 successfully.");
        } else {
            setFlash('danger', 'B2 upload failed. Check your B2 credentials and bucket settings.');
        }
    }
    header('Location: backup.php'); exit;
}

// Delete from B2
if (isset($_GET['delete_b2']) && $b2) {
    $fileId   = $_GET['delete_b2'];
    $fileName = $_GET['fname'] ?? '';
    $ok = $b2->deleteFile($fileId, $fileName);
    auditLog($pdo, 'backup_b2_deleted', 'system', 0, ['fileId' => $fileId, 'fileName' => $fileName]);
    setFlash($ok ? 'success' : 'danger', $ok ? "Deleted from B2: <strong>" . e(basename($fileName)) . "</strong>" : 'B2 delete failed.');
    header('Location: backup.php'); exit;
}

// Download from B2 (redirect to signed URL)
if (isset($_GET['download_b2']) && $b2) {
    $fileName = $_GET['download_b2'];
    $url = $b2->getAuthorizedDownloadUrl($fileName);
    if ($url) { header('Location: ' . $url); exit; }
    setFlash('danger', 'Could not generate B2 download URL.');
    header('Location: backup.php'); exit;
}

// Save B2 settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_b2'])) {
    $pdo->prepare("UPDATE settings SET b2_key_id=?, b2_app_key=?, b2_bucket_id=?, b2_bucket_name=? WHERE id=1")
        ->execute([
            trim($_POST['b2_key_id']      ?? ''),
            trim($_POST['b2_app_key']     ?? ''),
            trim($_POST['b2_bucket_id']   ?? ''),
            trim($_POST['b2_bucket_name'] ?? ''),
        ]);
    setFlash('success', 'Backblaze B2 settings saved.');
    header('Location: backup.php'); exit;
}

// Test B2 connection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_b2'])) {
    $testB2 = new B2Client(
        trim($_POST['b2_key_id']      ?? ''),
        trim($_POST['b2_app_key']     ?? ''),
        trim($_POST['b2_bucket_id']   ?? ''),
        trim($_POST['b2_bucket_name'] ?? '')
    );
    $ok = $testB2->authorize();
    setFlash($ok ? 'success' : 'danger', $ok
        ? '✅ B2 connection successful! Credentials are valid.'
        : '❌ B2 connection failed. Check your Key ID, App Key, and Bucket ID.');
    header('Location: backup.php'); exit;
}

// Create new backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {
    $filename = 'backup_' . date('Ymd_His') . '.sql';
    $filepath = $backupDir . $filename;
    $sql      = generateSqlDump($pdo);
    file_put_contents($filepath, $sql);

    $msg  = "Local backup created: <strong>{$filename}</strong> (" . number_format(strlen($sql) / 1024, 1) . " KB)";

    // Auto-upload to B2 if configured
    if ($b2) {
        $result = $b2->upload($filepath, 'backups/' . $filename);
        if ($result) {
            $msg .= ' &nbsp;·&nbsp; <i class="bi bi-cloud-check text-success"></i> Uploaded to B2 automatically.';
            auditLog($pdo, 'backup_created_b2', 'system', 0, null, ['file' => $filename]);
        } else {
            $msg .= ' &nbsp;·&nbsp; <span class="text-warning">⚠ B2 upload failed (saved locally only).</span>';
        }
    }
    auditLog($pdo, 'backup_created', 'system', 0, null, ['file' => $filename, 'size' => strlen($sql)]);
    setFlash('success', $msg);
    header('Location: backup.php'); exit;
}

// ── Load data ─────────────────────────────────────────────────────────
$s      = getSettings($pdo);
$b2Ok   = b2IsConfigured($s);
$b2     = $b2Ok ? b2FromSettings($s) : null;
$locals = glob($backupDir . '*.sql') ?: [];
usort($locals, fn($a, $b) => filemtime($b) - filemtime($a));

$b2Files = [];
if ($b2Ok && $b2) {
    try { $b2Files = $b2->listFiles('backups/'); } catch (\Throwable) { $b2Files = []; }
}

// ── Helpers ───────────────────────────────────────────────────────────
function generateSqlDump(PDO $pdo): string {
    $out  = "-- Asset Manager Database Backup\n";
    $out .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $out .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
    foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        $out .= "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n";
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_NUM);
        if ($rows) {
            $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
            $colStr = '`' . implode('`,`', $cols) . '`';
            foreach (array_chunk($rows, 50) as $chunk) {
                $vals = array_map(fn($row) =>
                    '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)) . ')',
                $chunk);
                $out .= "INSERT INTO `{$table}` ({$colStr}) VALUES\n" . implode(",\n", $vals) . ";\n";
            }
            $out .= "\n";
        }
    }
    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $out;
}

function fmtSize(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes/1048576, 2) . ' MB';
    return number_format($bytes/1024, 1) . ' KB';
}
?>

<!-- ── PAGE HEADER ─────────────────────────────────────────────────── -->
<div class="page-header d-flex justify-content-between align-items-center">
  <div>
    <h2><i class="bi bi-database-down me-2" style="color:var(--brand-primary)"></i>Database Backup</h2>
    <p>Create SQL backups and sync them to <strong>Backblaze B2</strong> cloud storage.</p>
  </div>
  <form method="POST">
    <button name="create_backup" class="btn btn-primary">
      <i class="bi bi-database-add me-2"></i>
      <?= $b2Ok ? 'Backup + Upload to B2' : 'Create Backup' ?>
    </button>
  </form>
</div>

<!-- ── STATS ──────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card text-center p-3">
      <div class="fs-2 fw-bold" style="color:var(--brand-primary)"><?= count($locals) ?></div>
      <div class="text-muted small">Local Backups</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center p-3">
      <div class="fs-2 fw-bold" style="color:var(--brand-primary)"><?= count($b2Files) ?></div>
      <div class="text-muted small">B2 Cloud Backups</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center p-3">
      <?php $totalSize = array_sum(array_map('filesize', $locals)); ?>
      <div class="fs-2 fw-bold" style="color:var(--brand-primary)"><?= fmtSize($totalSize) ?></div>
      <div class="text-muted small">Local Storage</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center p-3">
      <div class="fs-2 fw-bold" style="color:<?= $b2Ok ? '#10b981' : '#94a3b8' ?>">
        <?= $b2Ok ? '<i class="bi bi-cloud-check"></i>' : '<i class="bi bi-cloud-slash"></i>' ?>
      </div>
      <div class="text-muted small"><?= $b2Ok ? 'B2 Connected' : 'B2 Not Configured' ?></div>
    </div>
  </div>
</div>

<div class="row g-4">

<!-- ── LEFT: B2 Settings ──────────────────────────────────────────── -->
<div class="col-lg-4">
  <div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
      <i class="bi bi-cloud-arrow-up"></i>
      Backblaze B2 Settings
      <?= $b2Ok
        ? '<span class="badge bg-success ms-auto"><i class="bi bi-check-circle me-1"></i>Connected</span>'
        : '<span class="badge bg-secondary ms-auto">Not Configured</span>' ?>
    </div>
    <div class="card-body">
      <div class="alert alert-info py-2 small mb-3">
        <i class="bi bi-info-circle me-1"></i>
        Get credentials from <strong>Backblaze → App Keys</strong>.<br>
        Create a key with <em>Read &amp; Write</em> access to your bucket.
      </div>
      <form method="POST" class="row g-3">
        <div class="col-12">
          <label class="form-label fw-semibold small">Key ID <span class="text-muted">(applicationKeyId)</span></label>
          <input type="text" name="b2_key_id" class="form-control font-monospace"
                 value="<?= e($s['b2_key_id'] ?? '') ?>" placeholder="00abc123..." autocomplete="off">
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold small">Application Key <span class="text-muted">(secret)</span></label>
          <input type="password" name="b2_app_key" class="form-control font-monospace"
                 value="<?= e($s['b2_app_key'] ?? '') ?>" placeholder="K000••••••••••••" autocomplete="new-password">
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold small">Bucket ID</label>
          <input type="text" name="b2_bucket_id" class="form-control font-monospace"
                 value="<?= e($s['b2_bucket_id'] ?? '') ?>" placeholder="e.g. 4a48fe8875c6214145260818">
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold small">Bucket Name</label>
          <input type="text" name="b2_bucket_name" class="form-control"
                 value="<?= e($s['b2_bucket_name'] ?? '') ?>" placeholder="my-company-backups">
        </div>
        <div class="col-12 d-flex gap-2">
          <button name="save_b2" class="btn btn-primary btn-sm flex-grow-1">
            <i class="bi bi-save me-1"></i>Save
          </button>
          <button name="test_b2" class="btn btn-outline-secondary btn-sm flex-grow-1"
                  formnovalidate title="Test connection without saving">
            <i class="bi bi-wifi me-1"></i>Test
          </button>
        </div>
      </form>

      <hr class="my-3">
      <div class="small text-muted">
        <div class="fw-semibold mb-1">How to set up:</div>
        <ol class="ps-3 mb-0" style="line-height:1.8;">
          <li>Log in to <strong>backblaze.com</strong></li>
          <li>Create a private bucket</li>
          <li>Go to <em>App Keys → Add New App Key</em></li>
          <li>Restrict to your bucket, allow Read &amp; Write</li>
          <li>Copy Key ID + Application Key above</li>
        </ol>
      </div>
    </div>
  </div>
</div>

<!-- ── RIGHT: Backup Tables ───────────────────────────────────────── -->
<div class="col-lg-8">

  <!-- B2 Cloud Backups -->
  <div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2 fw-semibold">
      <i class="bi bi-cloud" style="color:var(--brand-primary)"></i>
      Backblaze B2 — Cloud Backups
      <span class="badge bg-primary ms-auto"><?= count($b2Files) ?> files</span>
    </div>

    <?php if (!$b2Ok): ?>
    <div class="card-body text-center py-4 text-muted">
      <i class="bi bi-cloud-slash" style="font-size:40px;opacity:.3;"></i>
      <p class="mt-2 mb-0 small">Configure B2 credentials on the left to enable cloud backup.</p>
    </div>

    <?php elseif (empty($b2Files)): ?>
    <div class="card-body text-center py-4 text-muted">
      <i class="bi bi-cloud" style="font-size:40px;opacity:.3;"></i>
      <p class="mt-2 mb-0 small">No backups in B2 yet. Click <strong>Backup + Upload to B2</strong> above.</p>
    </div>

    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr>
          <th>Filename</th><th>Size</th><th>Uploaded</th><th>Actions</th>
        </tr></thead>
        <tbody>
          <?php foreach ($b2Files as $i => $file):
            $fname    = basename($file['fileName']);
            $uploaded = isset($file['uploadTimestamp']) ? date('d M Y H:i', $file['uploadTimestamp']/1000) : '—';
            $size     = $file['contentLength'] ?? 0;
          ?>
          <tr>
            <td>
              <i class="bi bi-cloud-fill me-2" style="color:var(--brand-primary);font-size:13px;"></i>
              <span class="font-monospace small"><?= e($fname) ?></span>
              <?php if ($i === 0): ?><span class="badge bg-success ms-2">Latest</span><?php endif; ?>
            </td>
            <td><?= fmtSize($size) ?></td>
            <td class="small text-muted"><?= $uploaded ?></td>
            <td>
              <div class="btn-group btn-group-sm">
                <a href="?download_b2=<?= urlencode($file['fileName']) ?>"
                   class="btn btn-outline-success py-0 px-2" title="Download from B2">
                  <i class="bi bi-cloud-download"></i>
                </a>
                <a href="?delete_b2=<?= urlencode($file['fileId']) ?>&fname=<?= urlencode($file['fileName']) ?>"
                   class="btn btn-outline-danger py-0 px-2" title="Delete from B2"
                   onclick="return confirm('Delete this backup from B2? This cannot be undone.')">
                  <i class="bi bi-trash"></i>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Local Backups -->
  <div class="card">
    <div class="card-header d-flex align-items-center gap-2 fw-semibold">
      <i class="bi bi-hdd"></i>
      Local Backups <small class="text-muted fw-normal ms-1">(server disk)</small>
      <span class="badge bg-secondary ms-auto"><?= count($locals) ?> files</span>
    </div>

    <?php if (empty($locals)): ?>
    <div class="card-body text-center py-4 text-muted">
      <i class="bi bi-database-x" style="font-size:40px;opacity:.3;"></i>
      <p class="mt-2 mb-0 small">No local backups yet.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>Filename</th><th>Size</th><th>Created</th><th>Age</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($locals as $i => $file):
            $fname    = basename($file);
            $size     = filesize($file);
            $mtime    = filemtime($file);
            $ageDays  = (int)((time()-$mtime)/86400);
            $ageClass = $ageDays === 0 ? 'success' : ($ageDays < 7 ? 'primary' : ($ageDays < 30 ? 'warning' : 'danger'));
            $ageLabel = $ageDays === 0 ? 'Today' : "{$ageDays}d ago";
          ?>
          <tr>
            <td>
              <i class="bi bi-file-earmark-code me-2 text-muted"></i>
              <span class="font-monospace small"><?= e($fname) ?></span>
              <?php if ($i===0): ?><span class="badge bg-success ms-2">Latest</span><?php endif; ?>
            </td>
            <td><?= fmtSize($size) ?></td>
            <td class="small text-muted"><?= date('d M Y H:i', $mtime) ?></td>
            <td><span class="badge bg-<?= $ageClass ?>"><?= $ageLabel ?></span></td>
            <td>
              <div class="btn-group btn-group-sm">
                <a href="?download=<?= urlencode($fname) ?>" class="btn btn-outline-success py-0 px-2" title="Download">
                  <i class="bi bi-download"></i>
                </a>
                <?php if ($b2Ok): ?>
                <a href="?upload_b2=<?= urlencode($fname) ?>" class="btn btn-outline-primary py-0 px-2" title="Upload to B2">
                  <i class="bi bi-cloud-upload"></i>
                </a>
                <?php endif; ?>
                <a href="?delete_local=<?= urlencode($fname) ?>" class="btn btn-outline-danger py-0 px-2" title="Delete local"
                   onclick="return confirm('Delete local copy?')">
                  <i class="bi bi-trash"></i>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div><!-- col-lg-8 -->
</div><!-- row -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
