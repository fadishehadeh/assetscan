<?php
// ── Bootstrap ─────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mail.php';
require_once __DIR__ . '/../../includes/b2.php';
requireLogin();
requireRole('super_admin');

$s      = getSettings($pdo);
$tab    = $_GET['tab'] ?? 'general';
$allowed = ['general','branding','email','backup'];
if (!in_array($tab, $allowed, true)) $tab = 'general';

$backupDir = __DIR__ . '/../../uploads/backups/';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

// ── POST handlers ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    // GENERAL
    if ($action === 'save_general') {
        $pdo->prepare("UPDATE settings SET company_name=?, currency=?, default_dep_method=? WHERE id=1")
            ->execute([
                trim($_POST['company_name']      ?? ''),
                trim($_POST['currency']           ?? 'USD'),
                $_POST['default_dep_method']      ?? 'straight_line',
            ]);
        auditLog($pdo, 'settings_updated', 'settings', 1, $s);
        setFlash('success', 'General settings saved.');
        header('Location: index.php?tab=general'); exit;
    }

    // BRANDING
    if ($action === 'save_branding') {
        $appName  = trim($_POST['app_name']      ?? $s['app_name']);
        $company  = trim($_POST['company_name']  ?? $s['company_name']);
        $primary  = trim($_POST['primary_color'] ?? $s['primary_color']);
        $sidebar  = trim($_POST['sidebar_color'] ?? $s['sidebar_color']);
        $logoPath = $s['logo_path'];

        $validPrimary = preg_match('/^#[0-9A-Fa-f]{6}$/', $primary) ? $primary : $s['primary_color'];
        $validSidebar = preg_match('/^#[0-9A-Fa-f]{6}$/', $sidebar) ? $sidebar : $s['sidebar_color'];

        // Logo upload
        if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $ext     = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowed = ['png','jpg','jpeg','gif','svg','webp'];
            if (!in_array($ext, $allowed, true)) {
                setFlash('danger', 'Invalid file type. Use PNG, JPG, SVG, or WEBP.');
                header('Location: index.php?tab=branding'); exit;
            }
            if ($_FILES['logo']['size'] > 2*1024*1024) {
                setFlash('danger', 'Logo file must be under 2MB.');
                header('Location: index.php?tab=branding'); exit;
            }
            $uploadDir = __DIR__ . '/../../uploads/branding/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $filename = 'logo_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $filename)) {
                if ($s['logo_path'] && $s['logo_path'] !== 'assets/img/logo.svg'
                    && file_exists(__DIR__ . '/../../' . $s['logo_path'])) {
                    @unlink(__DIR__ . '/../../' . $s['logo_path']);
                }
                $logoPath = 'uploads/branding/' . $filename;
            }
        }

        if (isset($_POST['reset_logo'])) {
            if ($s['logo_path'] !== 'assets/img/logo.svg'
                && file_exists(__DIR__ . '/../../' . $s['logo_path'])) {
                @unlink(__DIR__ . '/../../' . $s['logo_path']);
            }
            $logoPath = 'assets/img/logo.svg';
        }

        $pdo->prepare("UPDATE settings SET app_name=?, company_name=?, primary_color=?, sidebar_color=?, logo_path=? WHERE id=1")
            ->execute([$appName, $company, $validPrimary, $validSidebar, $logoPath]);
        auditLog($pdo, 'branding_updated', 'settings', 1, $s);
        setFlash('success', 'Branding saved. Reload to see the new look everywhere.');
        header('Location: index.php?tab=branding'); exit;
    }

    // ALERT SETTINGS
    if ($action === 'save_alerts') {
        $pdo->prepare("UPDATE settings SET alert_email=?, notify_warranty=?, notify_eol=?, notify_maintenance=?, warranty_alert_days=? WHERE id=1")
            ->execute([
                trim($_POST['alert_email'] ?? ''),
                isset($_POST['notify_warranty']) ? 1 : 0,
                isset($_POST['notify_eol']) ? 1 : 0,
                isset($_POST['notify_maintenance']) ? 1 : 0,
                max(1, min(365, (int)($_POST['warranty_alert_days'] ?? 30))),
            ]);
        setFlash('success', 'Alert settings saved.');
        header('Location: index.php?tab=email'); exit;
    }

    // EMAIL
    if ($action === 'save_email') {
        $pdo->prepare("UPDATE settings SET mailjet_api_key=?, mailjet_secret=?, mailjet_from_email=?, mailjet_from_name=? WHERE id=1")
            ->execute([
                trim($_POST['mailjet_api_key']    ?? ''),
                trim($_POST['mailjet_secret']     ?? ''),
                trim($_POST['mailjet_from_email'] ?? ''),
                trim($_POST['mailjet_from_name']  ?? 'Asset Manager'),
            ]);
        setFlash('success', 'Email settings saved.');
        header('Location: index.php?tab=email'); exit;
    }
    if ($action === 'test_email') {
        $s = getSettings($pdo);
        $sent = sendMail($pdo, trim($_POST['test_to'] ?? ''), 'Test Recipient',
            'Mailjet Test — ' . ($s['app_name'] ?? 'Asset Manager'),
            '<p>This is a test email from <strong>' . e($s['app_name'] ?? 'Asset Manager') . '</strong>. Mailjet is configured correctly ✅</p>');
        setFlash($sent ? 'success' : 'danger', $sent ? 'Test email sent!' : 'Send failed — check API key and sender email.');
        header('Location: index.php?tab=email'); exit;
    }

    // BACKUP
    if ($action === 'save_b2') {
        $pdo->prepare("UPDATE settings SET b2_key_id=?, b2_app_key=?, b2_bucket_id=?, b2_bucket_name=? WHERE id=1")
            ->execute([
                trim($_POST['b2_key_id']      ?? ''),
                trim($_POST['b2_app_key']     ?? ''),
                trim($_POST['b2_bucket_id']   ?? ''),
                trim($_POST['b2_bucket_name'] ?? ''),
            ]);
        setFlash('success', 'Backblaze B2 settings saved.');
        header('Location: index.php?tab=backup'); exit;
    }
    if ($action === 'test_b2') {
        $testB2 = new B2Client(
            trim($_POST['b2_key_id']      ?? ''),
            trim($_POST['b2_app_key']     ?? ''),
            trim($_POST['b2_bucket_id']   ?? ''),
            trim($_POST['b2_bucket_name'] ?? '')
        );
        $ok = $testB2->authorize();
        setFlash($ok ? 'success' : 'danger', $ok ? '✅ B2 connection successful!' : '❌ B2 connection failed. Check your credentials.');
        header('Location: index.php?tab=backup'); exit;
    }
    if ($action === 'create_backup') {
        $s = getSettings($pdo);
        $b2 = b2IsConfigured($s) ? b2FromSettings($s) : null;
        $filename = 'backup_' . date('Ymd_His') . '.sql';
        $filepath = $backupDir . $filename;
        $sql = generateSqlDump($pdo);
        file_put_contents($filepath, $sql);
        $msg = "Local backup created: <strong>{$filename}</strong> (" . number_format(strlen($sql)/1024, 1) . " KB)";
        if ($b2) {
            $result = $b2->upload($filepath, 'backups/' . $filename);
            $msg .= $result
                ? ' &nbsp;·&nbsp; <i class="bi bi-cloud-check text-success"></i> Uploaded to B2.'
                : ' &nbsp;·&nbsp; <span class="text-warning">⚠ B2 upload failed (saved locally).</span>';
        }
        auditLog($pdo, 'backup_created', 'system', 0, null, ['file' => $filename]);
        setFlash('success', $msg);
        header('Location: index.php?tab=backup'); exit;
    }
}

// BACKUP GET actions
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
if (isset($_GET['delete_local'])) {
    $file = basename($_GET['delete_local']);
    $path = $backupDir . $file;
    if (file_exists($path) && str_ends_with($file, '.sql')) {
        unlink($path);
        setFlash('success', "Backup <strong>{$file}</strong> deleted.");
    }
    header('Location: index.php?tab=backup'); exit;
}
$s = getSettings($pdo);
$b2Ok  = b2IsConfigured($s);
$b2    = $b2Ok ? b2FromSettings($s) : null;
if (isset($_GET['upload_b2']) && $b2) {
    $file = basename($_GET['upload_b2']);
    $path = $backupDir . $file;
    if (file_exists($path)) {
        $result = $b2->upload($path, 'backups/' . $file);
        setFlash($result ? 'success' : 'danger', $result ? "Uploaded <strong>{$file}</strong> to B2." : 'B2 upload failed.');
    }
    header('Location: index.php?tab=backup'); exit;
}
if (isset($_GET['delete_b2']) && $b2) {
    $ok = $b2->deleteFile($_GET['delete_b2'], $_GET['fname'] ?? '');
    setFlash($ok ? 'success' : 'danger', $ok ? 'Deleted from B2.' : 'B2 delete failed.');
    header('Location: index.php?tab=backup'); exit;
}
if (isset($_GET['download_b2']) && $b2) {
    $url = $b2->getAuthorizedDownloadUrl($_GET['download_b2']);
    if ($url) { header('Location: ' . $url); exit; }
    setFlash('danger', 'Could not generate B2 download URL.');
    header('Location: index.php?tab=backup'); exit;
}

// ── Helpers ───────────────────────────────────────────────────────────
function generateSqlDump(PDO $pdo): string {
    $out  = "-- Asset Manager Database Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        $out .= "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n";
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_NUM);
        if ($rows) {
            $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
            $colStr = '`' . implode('`,`', $cols) . '`';
            foreach (array_chunk($rows, 50) as $chunk) {
                $vals = array_map(fn($row) => '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)) . ')', $chunk);
                $out .= "INSERT INTO `{$table}` ({$colStr}) VALUES\n" . implode(",\n", $vals) . ";\n";
            }
            $out .= "\n";
        }
    }
    return $out . "SET FOREIGN_KEY_CHECKS = 1;\n";
}
function fmtSize(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes/1048576, 2) . ' MB';
    return number_format($bytes/1024, 1) . ' KB';
}

// ── Load page data ────────────────────────────────────────────────────
$s = getSettings($pdo);
$b2Ok = b2IsConfigured($s);
$b2   = $b2Ok ? b2FromSettings($s) : null;
$locals  = glob($backupDir . '*.sql') ?: [];
usort($locals, fn($a, $b) => filemtime($b) - filemtime($a));
$b2Files = [];
if ($b2Ok && $b2) { try { $b2Files = $b2->listFiles('backups/'); } catch (\Throwable) {} }
$mailjetConfigured = !empty($s['mailjet_api_key']) && !empty($s['mailjet_from_email']);
$otpUsers   = $pdo->query("SELECT COUNT(*) FROM users WHERE otp_enabled=1 AND is_active=1")->fetchColumn();
$totalUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();

$pageTitle = 'Settings';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- ── PAGE HEADER ────────────────────────────────────────────────── -->
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h2><i class="bi bi-gear me-2" style="color:var(--brand-primary)"></i>System Settings</h2>
    <p>Manage all application settings in one place. Super Admin only.</p>
  </div>
  <?php if ($tab === 'backup'): ?>
  <form method="POST">
    <input type="hidden" name="_action" value="create_backup">
    <button class="btn btn-primary">
      <i class="bi bi-database-add me-2"></i><?= $b2Ok ? 'Backup + Upload to B2' : 'Create Local Backup' ?>
    </button>
  </form>
  <?php endif; ?>
</div>

<!-- ── TABS ──────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-4" style="border-bottom:2px solid #e2e8f0;">
  <?php
  $tabs = [
    'general'  => ['bi-sliders','General'],
    'branding' => ['bi-palette','Branding'],
    'email'    => ['bi-envelope-at','Email & OTP'],
    'backup'   => ['bi-database-down','Backup & B2'],
  ];
  foreach ($tabs as $key => [$icon, $label]):
    $active = $tab === $key;
  ?>
  <li class="nav-item">
    <a class="nav-link <?= $active ? 'active fw-semibold' : '' ?>"
       href="?tab=<?= $key ?>"
       style="<?= $active ? 'color:var(--brand-primary);border-bottom:2px solid var(--brand-primary);margin-bottom:-2px;' : 'color:#64748b;' ?>">
      <i class="bi <?= $icon ?> me-2"></i><?= $label ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- TAB: GENERAL                                                       -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<?php if ($tab === 'general'): ?>
<div class="row g-4">
  <div class="col-lg-6">
    <form method="POST">
      <input type="hidden" name="_action" value="save_general">
      <div class="card">
        <div class="card-header"><i class="bi bi-building me-2"></i>Company & Defaults</div>
        <div class="card-body row g-3">

          <div class="col-12">
            <label class="form-label fw-semibold small">Company Name</label>
            <input type="text" name="company_name" class="form-control" value="<?= e($s['company_name'] ?? '') ?>" placeholder="G2. Doha">
            <div class="form-text">Shown on reports, exports, and the login screen.</div>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold small">Currency</label>
            <select name="currency" class="form-select">
              <?php foreach (['USD','EUR','GBP','AED','SAR','KWD','JOD','EGP','QAR','BHD','OMR'] as $c): ?>
              <option value="<?= $c ?>" <?= ($s['currency'] ?? 'USD') === $c ? 'selected' : '' ?>><?= $c ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold small">Default Depreciation Method</label>
            <select name="default_dep_method" class="form-select">
              <option value="straight_line"    <?= ($s['default_dep_method'] ?? '') === 'straight_line'    ? 'selected' : '' ?>>Straight-Line</option>
              <option value="declining_balance" <?= ($s['default_dep_method'] ?? '') === 'declining_balance' ? 'selected' : '' ?>>Declining Balance</option>
            </select>
          </div>

          <div class="col-12 pt-1">
            <button type="submit" class="btn btn-primary px-4">
              <i class="bi bi-save me-2"></i>Save General Settings
            </button>
          </div>
        </div>
      </div>
    </form>
  </div>

  <!-- Quick overview card -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-info-circle me-2"></i>System Overview</div>
      <div class="card-body">
        <?php
        $totalAssets = $pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
        $totalCost   = $pdo->query("SELECT COALESCE(SUM(purchase_cost),0) FROM assets")->fetchColumn();
        $cats        = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
        ?>
        <dl class="row mb-0" style="font-size:13.5px;">
          <dt class="col-6 text-muted fw-normal">App Name</dt>
          <dd class="col-6 fw-semibold"><?= e($s['app_name'] ?? '—') ?></dd>
          <dt class="col-6 text-muted fw-normal">Company</dt>
          <dd class="col-6 fw-semibold"><?= e($s['company_name'] ?? '—') ?></dd>
          <dt class="col-6 text-muted fw-normal">Currency</dt>
          <dd class="col-6 fw-semibold"><?= e($s['currency'] ?? 'USD') ?></dd>
          <dt class="col-6 text-muted fw-normal">Total Assets</dt>
          <dd class="col-6 fw-semibold"><?= number_format($totalAssets) ?></dd>
          <dt class="col-6 text-muted fw-normal">Total Value</dt>
          <dd class="col-6 fw-semibold"><?= e($s['currency'] ?? 'USD') ?> <?= number_format($totalCost, 0) ?></dd>
          <dt class="col-6 text-muted fw-normal">Categories</dt>
          <dd class="col-6 fw-semibold"><?= $cats ?></dd>
          <dt class="col-6 text-muted fw-normal">Active Users</dt>
          <dd class="col-6 fw-semibold"><?= $totalUsers ?></dd>
          <dt class="col-6 text-muted fw-normal">Mailjet</dt>
          <dd class="col-6">
            <?= $mailjetConfigured
              ? '<span class="badge bg-success">Configured</span>'
              : '<span class="badge bg-secondary">Not set</span>' ?>
          </dd>
          <dt class="col-6 text-muted fw-normal">B2 Backup</dt>
          <dd class="col-6">
            <?= $b2Ok
              ? '<span class="badge bg-success">Connected</span>'
              : '<span class="badge bg-secondary">Not set</span>' ?>
          </dd>
        </dl>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- TAB: BRANDING                                                      -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'branding'): ?>
<form method="POST" enctype="multipart/form-data">
  <input type="hidden" name="_action" value="save_branding">
  <div class="row g-4">

    <!-- LEFT: controls -->
    <div class="col-lg-7">

      <!-- Identity -->
      <div class="card mb-4">
        <div class="card-header"><i class="bi bi-type me-2"></i>Identity</div>
        <div class="card-body row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold small">App Name <span class="text-muted fw-normal">(topbar & browser tab)</span></label>
            <input type="text" name="app_name" class="form-control" value="<?= e($s['app_name'] ?? 'Asset Manager') ?>" oninput="updatePreview()">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold small">Company Name <span class="text-muted fw-normal">(reports & footer)</span></label>
            <input type="text" name="company_name" class="form-control" value="<?= e($s['company_name'] ?? '') ?>">
          </div>
        </div>
      </div>

      <!-- Logo -->
      <div class="card mb-4">
        <div class="card-header"><i class="bi bi-image me-2"></i>Logo</div>
        <div class="card-body">
          <div class="d-flex align-items-center gap-4 mb-4">
            <div style="background:#0f1117;padding:12px 20px;border-radius:10px;flex-shrink:0;">
              <img id="logoPreview" src="/<?= e($s['logo_path'] ?? 'assets/img/logo.svg') ?>"
                   alt="Logo" style="height:38px;width:auto;max-width:180px;object-fit:contain;">
            </div>
            <div>
              <div class="fw-semibold small mb-1">Current logo</div>
              <div class="text-muted" style="font-size:12px;"><?= e(basename($s['logo_path'] ?? 'logo.svg')) ?></div>
              <?php if (($s['logo_path'] ?? '') !== 'assets/img/logo.svg'): ?>
              <button type="submit" name="reset_logo" value="1" class="btn btn-outline-secondary btn-sm mt-2 py-0"
                      onclick="return confirm('Reset to default G2 logo?')">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset to default
              </button>
              <?php endif; ?>
            </div>
          </div>

          <label class="form-label fw-semibold small">Upload New Logo</label>
          <input type="file" name="logo" class="form-control mb-1" accept=".png,.jpg,.jpeg,.svg,.webp"
                 onchange="previewLogo(this)">
          <div class="form-text">PNG, SVG, JPG or WEBP · Max 2MB · Recommended height 40–60px</div>
        </div>
      </div>

      <!-- Colors -->
      <div class="card mb-4">
        <div class="card-header"><i class="bi bi-palette2 me-2"></i>Colors</div>
        <div class="card-body row g-4">

          <div class="col-md-6">
            <label class="form-label fw-semibold small">Primary / Accent Color</label>
            <p class="text-muted mb-2" style="font-size:12px;">Buttons, active states, badges, links.</p>
            <div class="d-flex align-items-center gap-3 mb-3">
              <input type="color" name="primary_color" id="primaryPicker"
                     value="<?= e($s['primary_color'] ?? '#E84B37') ?>"
                     class="form-control form-control-color" style="width:52px;height:42px;padding:2px;cursor:pointer;"
                     oninput="syncColor(this,'primaryHex')">
              <input type="text" id="primaryHex" value="<?= e($s['primary_color'] ?? '#E84B37') ?>"
                     class="form-control font-monospace" style="max-width:110px;"
                     oninput="syncHex(this,'primaryPicker')" maxlength="7">
            </div>
            <div class="small text-muted fw-semibold mb-2">Presets</div>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ([
                '#E84B37'=>'G2 Red','#1d4ed8'=>'Blue','#059669'=>'Green',
                '#7c3aed'=>'Purple','#d97706'=>'Amber','#0891b2'=>'Cyan',
                '#e11d48'=>'Rose','#374151'=>'Slate'
              ] as $hex => $label): ?>
              <button type="button" class="preset-dot" title="<?= $label ?>"
                      style="background:<?= $hex ?>;width:28px;height:28px;border-radius:50%;border:2px solid <?= ($s['primary_color'] ?? '#E84B37') === $hex ? '#000' : 'rgba(0,0,0,.12)' ?>;cursor:pointer;transition:transform .15s;"
                      onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform=''"
                      onclick="setColor('<?= $hex ?>','primaryPicker','primaryHex')"></button>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold small">Sidebar / Topbar Color</label>
            <p class="text-muted mb-2" style="font-size:12px;">Navigation background.</p>
            <div class="d-flex align-items-center gap-3 mb-3">
              <input type="color" name="sidebar_color" id="sidebarPicker"
                     value="<?= e($s['sidebar_color'] ?? '#0f1117') ?>"
                     class="form-control form-control-color" style="width:52px;height:42px;padding:2px;cursor:pointer;"
                     oninput="syncColor(this,'sidebarHex')">
              <input type="text" id="sidebarHex" value="<?= e($s['sidebar_color'] ?? '#0f1117') ?>"
                     class="form-control font-monospace" style="max-width:110px;"
                     oninput="syncHex(this,'sidebarPicker')" maxlength="7">
            </div>
            <div class="small text-muted fw-semibold mb-2">Presets</div>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ([
                '#0f1117'=>'Near Black','#1a1a2e'=>'Navy Dark','#111827'=>'Gray 900',
                '#1c1917'=>'Stone Dark','#14532d'=>'Forest','#1e1b4b'=>'Indigo',
                '#1e3a5f'=>'Ocean','#ffffff'=>'White'
              ] as $hex => $label): ?>
              <button type="button" class="preset-dot" title="<?= $label ?>"
                      style="background:<?= $hex ?>;width:28px;height:28px;border-radius:50%;border:2px solid <?= ($s['sidebar_color'] ?? '#0f1117') === $hex ? '#000' : '#e2e8f0' ?>;cursor:pointer;transition:transform .15s;"
                      onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform=''"
                      onclick="setColor('<?= $hex ?>','sidebarPicker','sidebarHex')"></button>
              <?php endforeach; ?>
            </div>
          </div>

        </div>
      </div>

      <div class="d-flex gap-3 align-items-center">
        <button type="submit" class="btn btn-primary px-4">
          <i class="bi bi-save me-2"></i>Save Branding
        </button>
        <span class="text-muted small">Changes take effect immediately for all users.</span>
      </div>
    </div>

    <!-- RIGHT: live preview -->
    <div class="col-lg-5">
      <div class="card sticky-top" style="top:76px;">
        <div class="card-header"><i class="bi bi-eye me-2"></i>Live Preview</div>
        <div class="card-body p-0 overflow-hidden" style="border-radius:0 0 var(--radius-lg) var(--radius-lg);">

          <!-- Topbar mock -->
          <div id="previewTopbar" style="background:<?= e($s['sidebar_color'] ?? '#0f1117') ?>;padding:10px 14px;display:flex;align-items:center;gap:10px;border-bottom:3px solid <?= e($s['primary_color'] ?? '#E84B37') ?>;">
            <img id="previewLogo" src="/<?= e($s['logo_path'] ?? 'assets/img/logo.svg') ?>"
                 style="height:26px;width:auto;object-fit:contain;max-width:130px;">
            <span id="previewAppName" style="color:rgba(255,255,255,.5);font-size:11px;flex:1;"><?= e($s['app_name'] ?? 'Asset Manager') ?></span>
            <span id="previewBadge" style="background:<?= e($s['primary_color'] ?? '#E84B37') ?>;color:#fff;font-size:10px;padding:3px 8px;border-radius:5px;font-weight:700;">Super Admin</span>
          </div>

          <!-- Body: sidebar + content -->
          <div style="display:flex;min-height:280px;">
            <div id="previewSidebar" style="background:<?= e($s['sidebar_color'] ?? '#0f1117') ?>;width:120px;padding:10px 8px;flex-shrink:0;">
              <?php
              $navItems = [['bi-speedometer2','Dashboard',false],['bi-boxes','All Assets',true],['bi-plus-circle','Add Asset',false],['bi-wrench','Maintenance',false],['bi-gear','Settings',false]];
              foreach ($navItems as [$icon,$label,$active]): ?>
              <div style="display:flex;align-items:center;gap:6px;padding:6px 8px;border-radius:6px;margin-bottom:2px;position:relative;
                          <?= $active ? 'background:rgba(232,75,55,.18);' : '' ?>">
                <?= $active ? '<div style="position:absolute;left:0;top:4px;bottom:4px;width:3px;border-radius:0 2px 2px 0;background:var(--brand-primary-preview,#E84B37)"></div>' : '' ?>
                <i class="bi <?= $icon ?>" style="color:<?= $active ? '#fff' : 'rgba(255,255,255,.5)' ?>;font-size:11px;margin-left:<?= $active ? '2px' : '0' ?>;"></i>
                <span style="color:<?= $active ? '#fff' : 'rgba(255,255,255,.55)' ?>;font-size:10px;"><?= $label ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <div style="flex:1;background:#f0f2f7;padding:12px;">
              <div style="background:#fff;border-radius:8px;padding:10px;margin-bottom:8px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
                <div style="font-size:10.5px;font-weight:700;color:#0f172a;margin-bottom:7px;">Asset Overview</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                  <div id="previewCard1" style="background:<?= e($s['primary_color'] ?? '#E84B37') ?>;border-radius:7px;padding:7px 9px;color:#fff;">
                    <div style="font-size:15px;font-weight:800;"><?= number_format($pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn()) ?></div>
                    <div style="font-size:9px;opacity:.85;">Total Assets</div>
                  </div>
                  <div style="background:#10b981;border-radius:7px;padding:7px 9px;color:#fff;">
                    <div style="font-size:15px;font-weight:800;"><?= number_format($pdo->query("SELECT COUNT(*) FROM assets WHERE status='active'")->fetchColumn()) ?></div>
                    <div style="font-size:9px;opacity:.85;">Active</div>
                  </div>
                </div>
              </div>
              <div style="background:#fff;border-radius:8px;padding:9px 11px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
                <div style="font-size:10px;color:#94a3b8;margin-bottom:5px;">Recent</div>
                <?php
                $recent = $pdo->query("SELECT name FROM assets ORDER BY created_at DESC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($recent ?: ['No assets yet','—','—'] as $item): ?>
                <div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid #f1f5f9;font-size:10px;color:#374151;">
                  <span style="overflow:hidden;white-space:nowrap;text-overflow:ellipsis;max-width:80px;"><?= e($item) ?></span>
                  <span class="preview-status-badge" style="background:<?= e($s['primary_color'] ?? '#E84B37') ?>;color:#fff;padding:1px 5px;border-radius:4px;font-size:8px;font-weight:600;flex-shrink:0;">Active</span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Login preview strip -->
          <div style="background:#f8fafc;padding:10px 14px;border-top:1px solid #f1f5f9;">
            <div style="font-size:10px;color:#94a3b8;text-align:center;margin-bottom:7px;">Login Preview</div>
            <div style="border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.10);max-width:180px;margin:0 auto;">
              <div id="previewLoginTop" style="background:<?= e($s['sidebar_color'] ?? '#0f1117') ?>;padding:10px;text-align:center;border-bottom:2px solid <?= e($s['primary_color'] ?? '#E84B37') ?>;">
                <img id="previewLoginLogo" src="/<?= e($s['logo_path'] ?? 'assets/img/logo.svg') ?>"
                     style="height:20px;width:auto;object-fit:contain;max-width:120px;">
              </div>
              <div style="background:#fff;padding:9px;">
                <div style="height:5px;background:#f1f5f9;border-radius:3px;margin-bottom:5px;"></div>
                <div style="height:5px;background:#f1f5f9;border-radius:3px;margin-bottom:7px;"></div>
                <div id="previewLoginBtn" style="background:<?= e($s['primary_color'] ?? '#E84B37') ?>;color:#fff;text-align:center;padding:5px;border-radius:5px;font-size:10px;font-weight:700;">Sign In</div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

  </div>
</form>

<script>
function syncColor(picker, hexId) {
  document.getElementById(hexId).value = picker.value;
  updatePreview();
}
function syncHex(input, pickerId) {
  const v = input.value.trim();
  if (/^#[0-9A-Fa-f]{6}$/.test(v)) {
    document.getElementById(pickerId).value = v;
    updatePreview();
  }
}
function setColor(hex, pickerId, hexId) {
  document.getElementById(pickerId).value = hex;
  document.getElementById(hexId).value = hex;
  updatePreview();
}
function previewLogo(input) {
  if (!input.files[0]) return;
  const url = URL.createObjectURL(input.files[0]);
  document.getElementById('logoPreview').src = url;
  document.getElementById('previewLogo').src = url;
  document.getElementById('previewLoginLogo').src = url;
}
function updatePreview() {
  const p = document.getElementById('primaryPicker').value;
  const s = document.getElementById('sidebarPicker').value;
  const n = document.querySelector('[name="app_name"]').value;
  document.getElementById('previewTopbar').style.background = s;
  document.getElementById('previewTopbar').style.borderBottomColor = p;
  document.getElementById('previewSidebar').style.background = s;
  document.getElementById('previewBadge').style.background = p;
  document.getElementById('previewCard1').style.background = p;
  document.querySelectorAll('.preview-status-badge').forEach(el => el.style.background = p);
  document.getElementById('previewLoginTop').style.background = s;
  document.getElementById('previewLoginTop').style.borderBottomColor = p;
  document.getElementById('previewLoginBtn').style.background = p;
  document.getElementById('previewAppName').textContent = n || 'Asset Manager';
  // Also update the live page so user sees it immediately
  document.documentElement.style.setProperty('--brand-primary', p);
  document.documentElement.style.setProperty('--sidebar-bg', s);
  document.documentElement.style.setProperty('--topbar-bg', s);
  // Active nav indicator
  document.querySelectorAll('[style*="border-radius:0 2px 2px 0"]').forEach(el => el.style.background = p);
}
</script>

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- TAB: EMAIL & OTP                                                   -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'email'): ?>
<div class="row g-4">
  <div class="col-lg-7">

    <!-- Credentials card -->
    <div class="card mb-4">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-key me-1"></i> Mailjet API Credentials
        <?= $mailjetConfigured
          ? '<span class="badge bg-success ms-auto"><i class="bi bi-check-circle me-1"></i>Configured</span>'
          : '<span class="badge bg-secondary ms-auto">Not Configured</span>' ?>
      </div>
      <div class="card-body">
        <div class="alert alert-info py-2 small mb-4">
          <i class="bi bi-info-circle me-1"></i>
          Get your keys at <strong>Mailjet → Account → API Key Management</strong>.
          Use the <strong>API Key</strong> (username) + <strong>Secret Key</strong> (password).
        </div>
        <form method="POST" class="row g-3">
          <input type="hidden" name="_action" value="save_email">
          <div class="col-md-6">
            <label class="form-label fw-semibold small">API Key (Public)</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-key"></i></span>
              <input type="text" name="mailjet_api_key" class="form-control font-monospace"
                     value="<?= e($s['mailjet_api_key'] ?? '') ?>" placeholder="abc123...">
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold small">Secret Key</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock"></i></span>
              <input type="password" name="mailjet_secret" class="form-control font-monospace"
                     value="<?= e($s['mailjet_secret'] ?? '') ?>" placeholder="••••••••••••" id="secretInput">
              <button class="btn btn-outline-secondary" type="button" onclick="toggleSecret()">
                <i class="bi bi-eye" id="secretEyeIcon"></i>
              </button>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold small">From Email <span class="text-muted fw-normal">(verified in Mailjet)</span></label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-at"></i></span>
              <input type="email" name="mailjet_from_email" class="form-control"
                     value="<?= e($s['mailjet_from_email'] ?? '') ?>" placeholder="noreply@yourcompany.com">
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold small">From Name</label>
            <input type="text" name="mailjet_from_name" class="form-control"
                   value="<?= e($s['mailjet_from_name'] ?? 'Asset Manager') ?>">
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-save me-2"></i>Save Email Settings
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Test email -->
    <?php if ($mailjetConfigured): ?>
    <div class="card">
      <div class="card-header"><i class="bi bi-send me-2"></i>Send Test Email</div>
      <div class="card-body">
        <form method="POST" class="d-flex gap-2">
          <input type="hidden" name="_action" value="test_email">
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" name="test_to" class="form-control" placeholder="recipient@example.com"
                   value="<?= e($s['mailjet_from_email'] ?? '') ?>">
          </div>
          <button type="submit" class="btn btn-outline-primary text-nowrap">
            <i class="bi bi-send me-1"></i>Send Test
          </button>
        </form>
        <div class="form-text mt-1">Sends a branded test email to verify your Mailjet configuration.</div>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- OTP status sidebar -->
  <div class="col-lg-5">
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-shield-lock me-2"></i>OTP / 2FA Status</div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div style="font-size:38px;font-weight:800;color:var(--brand-primary);line-height:1;"><?= $otpUsers ?></div>
          <div>
            <div class="fw-semibold">of <?= $totalUsers ?> active users have OTP enabled</div>
            <div class="small text-muted">Users manage their own 2FA from My Profile.</div>
          </div>
        </div>
        <div class="progress mb-3" style="height:8px;">
          <div class="progress-bar" role="progressbar"
               style="width:<?= $totalUsers > 0 ? round(($otpUsers/$totalUsers)*100) : 0 ?>%;background:var(--brand-primary);"
               aria-valuenow="<?= $otpUsers ?>" aria-valuemax="<?= $totalUsers ?>"></div>
        </div>
        <?php if (!$mailjetConfigured): ?>
        <div class="alert alert-warning py-2 small mb-0">
          <i class="bi bi-exclamation-triangle me-1"></i>Configure Mailjet credentials first to enable OTP sending.
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-envelope-check me-2"></i>How OTP Works</div>
      <ul class="list-group list-group-flush" style="font-size:13px;">
        <li class="list-group-item py-2"><i class="bi bi-check-circle text-success me-2"></i>6-digit code sent to user's email at login</li>
        <li class="list-group-item py-2"><i class="bi bi-check-circle text-success me-2"></i>Code expires after 10 minutes</li>
        <li class="list-group-item py-2"><i class="bi bi-check-circle text-success me-2"></i>Paste support — auto-submits on paste</li>
        <li class="list-group-item py-2"><i class="bi bi-check-circle text-success me-2"></i>Each user enables it from their profile</li>
        <li class="list-group-item py-2"><i class="bi bi-check-circle text-success me-2"></i>Branded HTML email with your colors</li>
      </ul>
    </div>
  </div>
</div>

<script>
function toggleSecret() {
  const inp = document.getElementById('secretInput');
  const icon = document.getElementById('secretEyeIcon');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.className = 'bi bi-eye-slash';
  } else {
    inp.type = 'password';
    icon.className = 'bi bi-eye';
  }
}
</script>

<?php
require_once __DIR__ . '/../../includes/alerts_settings.php';
renderAlertsSettings($pdo, $s);
?>

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- TAB: BACKUP & B2                                                   -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'backup'): ?>

<!-- Stats row -->
<div class="row g-3 mb-4">
  <?php
  $totalSize = array_sum(array_map('filesize', $locals));
  $statCards = [
    [count($locals),  'Local Backups',   '#3b82f6'],
    [count($b2Files), 'B2 Cloud Backups','#7c3aed'],
    [fmtSize($totalSize), 'Local Storage','#f59e0b'],
    [$b2Ok ? '<i class="bi bi-cloud-check"></i>' : '<i class="bi bi-cloud-slash"></i>',
     $b2Ok ? 'B2 Connected' : 'B2 Not Set', $b2Ok ? '#10b981' : '#94a3b8'],
  ];
  foreach ($statCards as [$val,$label,$color]): ?>
  <div class="col-6 col-md-3">
    <div class="card text-center p-3">
      <div class="fw-bold mb-1" style="font-size:1.6rem;color:<?= $color ?>"><?= $val ?></div>
      <div class="text-muted small"><?= $label ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-4">

  <!-- B2 config -->
  <div class="col-lg-4">
    <div class="card mb-4">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-cloud-arrow-up"></i> Backblaze B2
        <?= $b2Ok
          ? '<span class="badge bg-success ms-auto">Connected</span>'
          : '<span class="badge bg-secondary ms-auto">Not Configured</span>' ?>
      </div>
      <div class="card-body">
        <div class="alert alert-info py-2 small mb-3">
          <i class="bi bi-info-circle me-1"></i>
          <strong>Backblaze → App Keys</strong> → create key with Read &amp; Write on your bucket.
        </div>
        <form method="POST" class="row g-3">
          <input type="hidden" name="_action" value="save_b2">
          <div class="col-12">
            <label class="form-label fw-semibold small">Key ID</label>
            <input type="text" name="b2_key_id" class="form-control font-monospace" autocomplete="off"
                   value="<?= e($s['b2_key_id'] ?? '') ?>" placeholder="00abc123...">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold small">Application Key</label>
            <div class="input-group">
              <input type="password" name="b2_app_key" class="form-control font-monospace" autocomplete="new-password"
                     value="<?= e($s['b2_app_key'] ?? '') ?>" placeholder="K000••••••••" id="b2keyInput">
              <button class="btn btn-outline-secondary" type="button" onclick="toggleB2Key()">
                <i class="bi bi-eye" id="b2EyeIcon"></i>
              </button>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold small">Bucket ID</label>
            <input type="text" name="b2_bucket_id" class="form-control font-monospace"
                   value="<?= e($s['b2_bucket_id'] ?? '') ?>" placeholder="4a48fe8875c6...">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold small">Bucket Name</label>
            <input type="text" name="b2_bucket_name" class="form-control"
                   value="<?= e($s['b2_bucket_name'] ?? '') ?>" placeholder="my-company-backups">
          </div>
          <div class="col-12 d-flex gap-2">
            <button name="_action" value="save_b2" class="btn btn-primary btn-sm flex-grow-1">
              <i class="bi bi-save me-1"></i>Save
            </button>
            <button type="submit" name="_action" value="test_b2" class="btn btn-outline-secondary btn-sm flex-grow-1">
              <i class="bi bi-wifi me-1"></i>Test
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Backup tables -->
  <div class="col-lg-8">

    <!-- B2 files -->
    <div class="card mb-4">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-cloud" style="color:var(--brand-primary)"></i> B2 Cloud Backups
        <span class="badge bg-primary ms-auto"><?= count($b2Files) ?></span>
      </div>
      <?php if (!$b2Ok): ?>
      <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-cloud-slash" style="font-size:44px;opacity:.25;"></i>
        <p class="mt-2 mb-0 small">Configure B2 credentials on the left.</p>
      </div>
      <?php elseif (empty($b2Files)): ?>
      <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-cloud" style="font-size:44px;opacity:.25;"></i>
        <p class="mt-2 mb-0 small">No backups in B2 yet.</p>
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Filename</th><th>Size</th><th>Uploaded</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($b2Files as $i => $file):
              $fname    = basename($file['fileName']);
              $uploaded = isset($file['uploadTimestamp']) ? date('d M Y H:i', $file['uploadTimestamp']/1000) : '—';
            ?>
            <tr>
              <td>
                <i class="bi bi-cloud-fill me-2" style="color:var(--brand-primary);font-size:12px;"></i>
                <span class="font-monospace small"><?= e($fname) ?></span>
                <?php if ($i===0): ?><span class="badge bg-success ms-2">Latest</span><?php endif; ?>
              </td>
              <td><?= fmtSize($file['contentLength'] ?? 0) ?></td>
              <td class="text-muted small"><?= $uploaded ?></td>
              <td>
                <div class="btn-group btn-group-sm">
                  <a href="?tab=backup&download_b2=<?= urlencode($file['fileName']) ?>" class="btn btn-outline-success py-0 px-2" title="Download">
                    <i class="bi bi-cloud-download"></i>
                  </a>
                  <a href="?tab=backup&delete_b2=<?= urlencode($file['fileId']) ?>&fname=<?= urlencode($file['fileName']) ?>"
                     class="btn btn-outline-danger py-0 px-2" title="Delete from B2"
                     onclick="return confirm('Delete from B2? This cannot be undone.')">
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

    <!-- Local files -->
    <div class="card">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-hdd"></i> Local Backups
        <span class="badge bg-secondary ms-auto"><?= count($locals) ?></span>
      </div>
      <?php if (empty($locals)): ?>
      <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-database-x" style="font-size:44px;opacity:.25;"></i>
        <p class="mt-2 mb-0 small">No local backups yet.</p>
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Filename</th><th>Size</th><th>Created</th><th>Age</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($locals as $i => $file):
              $fname   = basename($file);
              $size    = filesize($file);
              $mtime   = filemtime($file);
              $ageDays = (int)((time()-$mtime)/86400);
              $ageClr  = $ageDays === 0 ? 'success' : ($ageDays < 7 ? 'primary' : ($ageDays < 30 ? 'warning' : 'danger'));
              $ageLabel= $ageDays === 0 ? 'Today' : "{$ageDays}d ago";
            ?>
            <tr>
              <td>
                <i class="bi bi-file-earmark-code me-2 text-muted"></i>
                <span class="font-monospace small"><?= e($fname) ?></span>
                <?php if ($i===0): ?><span class="badge bg-success ms-2">Latest</span><?php endif; ?>
              </td>
              <td><?= fmtSize($size) ?></td>
              <td class="text-muted small"><?= date('d M Y H:i', $mtime) ?></td>
              <td><span class="badge bg-<?= $ageClr ?>"><?= $ageLabel ?></span></td>
              <td>
                <div class="btn-group btn-group-sm">
                  <a href="?tab=backup&download=<?= urlencode($fname) ?>" class="btn btn-outline-success py-0 px-2" title="Download">
                    <i class="bi bi-download"></i>
                  </a>
                  <?php if ($b2Ok): ?>
                  <a href="?tab=backup&upload_b2=<?= urlencode($fname) ?>" class="btn btn-outline-primary py-0 px-2" title="Upload to B2">
                    <i class="bi bi-cloud-upload"></i>
                  </a>
                  <?php endif; ?>
                  <a href="?tab=backup&delete_local=<?= urlencode($fname) ?>"
                     class="btn btn-outline-danger py-0 px-2" title="Delete local"
                     onclick="return confirm('Delete local backup?')">
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

  </div>
</div>

<script>
function toggleB2Key() {
  const inp = document.getElementById('b2keyInput');
  const icon = document.getElementById('b2EyeIcon');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.className = 'bi bi-eye-slash';
  } else {
    inp.type = 'password';
    icon.className = 'bi bi-eye';
  }
}
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
