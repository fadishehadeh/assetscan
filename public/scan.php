<?php
/**
 * Public asset scan page — NO login required.
 * Accessed via QR code: /asset-manager/public/scan.php?tag=AST-XXXXXX
 */

require_once __DIR__ . '/../config/db.php';

// ── Load settings from DB ──────────────────────────────────────────────
$settings = [
    'app_name'      => 'Asset Manager',
    'company_name'  => '',
    'logo_path'     => '',
    'primary_color' => '#E84B37',
    'sidebar_color' => '#0f1117',
    'alert_email'   => '',
];

try {
    $stmt = $pdo->query(
        "SELECT app_name, company_name, logo_path, primary_color, sidebar_color, alert_email
         FROM settings WHERE id = 1 LIMIT 1"
    );
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings = array_merge($settings, array_filter($row, fn($v) => $v !== null && $v !== ''));
    }
} catch (Throwable $e) {
    // Use defaults silently
}

$primaryColor = htmlspecialchars($settings['primary_color'], ENT_QUOTES);
$sidebarColor = htmlspecialchars($settings['sidebar_color'], ENT_QUOTES);
$appName      = htmlspecialchars($settings['app_name'],      ENT_QUOTES);
$companyName  = htmlspecialchars($settings['company_name'],  ENT_QUOTES);
$alertEmail   = htmlspecialchars($settings['alert_email'],   ENT_QUOTES);
$logoPath     = htmlspecialchars($settings['logo_path'],     ENT_QUOTES);

// ── Fetch asset ────────────────────────────────────────────────────────
$asset   = null;
$tag     = trim($_GET['tag'] ?? '');
$error   = '';

if ($tag === '') {
    $error = 'No asset tag provided.';
} else {
    try {
        $stmt = $pdo->prepare(
            "SELECT a.*, c.name AS cat_name, d.name AS dept_name,
                    l.building, l.floor, l.room, u.name AS assigned_name
             FROM assets a
             LEFT JOIN categories  c ON a.category_id   = c.id
             LEFT JOIN departments d ON a.department_id  = d.id
             LEFT JOIN locations   l ON a.location_id    = l.id
             LEFT JOIN users       u ON a.assigned_to    = u.id
             WHERE a.asset_tag = ?
             LIMIT 1"
        );
        $stmt->execute([$tag]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$asset) {
            $error = 'Asset not found for tag: ' . htmlspecialchars($tag, ENT_QUOTES);
        }
    } catch (Throwable $e) {
        $error = 'Database error. Please try again later.';
    }
}

// ── Warranty helpers ───────────────────────────────────────────────────
$warrantyHtml = '';
if ($asset && !empty($asset['warranty_expiry'])) {
    $expiry    = new DateTime($asset['warranty_expiry']);
    $today     = new DateTime('today');
    $diffDays  = (int) $today->diff($expiry)->format('%r%a');

    if ($diffDays < 0) {
        $warrantyHtml = '<span class="scan-warranty-expired">Expired</span>';
    } elseif ($diffDays <= 90) {
        $warrantyHtml = '<span class="scan-warranty-soon">Expires in ' . $diffDays . ' day' . ($diffDays === 1 ? '' : 's') . '</span>';
    } else {
        $warrantyHtml = '<span class="scan-warranty-ok">' . htmlspecialchars($asset['warranty_expiry']) . '</span>';
    }
}

// ── Status badge helper ────────────────────────────────────────────────
function statusBadge(string $status): string
{
    $map = [
        'Active'            => '#22c55e',
        'Under Maintenance' => '#f59e0b',
        'Disposed'          => '#6b7280',
        'Lost'              => '#ef4444',
        'Inactive'          => '#94a3b8',
        'Reserved'          => '#3b82f6',
    ];
    $color = $map[$status] ?? '#94a3b8';
    return '<span style="background:' . $color . ';color:#fff;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;letter-spacing:.3px;">'
           . htmlspecialchars($status) . '</span>';
}

// ── Location string ────────────────────────────────────────────────────
function locationStr(array $asset): string
{
    $parts = array_filter([
        $asset['building'] ?? '',
        $asset['floor']    ?? '',
        $asset['room']     ?? '',
    ]);
    return $parts ? htmlspecialchars(implode(' › ', $parts)) : '—';
}

// ── mailto link ────────────────────────────────────────────────────────
$mailtoSubject = rawurlencode('Issue with ' . ($asset['asset_tag'] ?? $tag));
$mailtoLink    = $alertEmail
    ? 'mailto:' . $alertEmail . '?subject=' . $mailtoSubject
    : '#';

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $appName ?> — Asset Info</title>

  <!-- Bootstrap 5.3 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">

  <style>
    :root {
      --brand:    <?= $primaryColor ?>;
      --sidebar:  <?= $sidebarColor ?>;
    }

    *, *::before, *::after { box-sizing: border-box; }

    body {
      background: #f0f2f7;
      font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: flex-start;
      justify-content: center;
      padding: 20px 12px 48px;
      -webkit-font-smoothing: antialiased;
    }

    /* ── Scan card ─────────────────────────────────────────────────── */
    .scan-card {
      width: 100%;
      max-width: 440px;
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 8px 40px rgba(0,0,0,.12), 0 2px 8px rgba(0,0,0,.06);
      overflow: hidden;
      animation: fadeUp .35s cubic-bezier(.22,.68,0,1.2) both;
    }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(14px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* ── Brand header ──────────────────────────────────────────────── */
    .scan-header {
      background: var(--sidebar);
      padding: 22px 24px 18px;
      text-align: center;
      position: relative;
    }

    .scan-header::after {
      content: '';
      position: absolute;
      bottom: 0; left: 24px; right: 24px;
      height: 2px;
      background: linear-gradient(90deg, transparent, var(--brand), transparent);
    }

    .scan-logo {
      height: 44px;
      width: auto;
      object-fit: contain;
      margin-bottom: 8px;
      filter: brightness(1.05);
    }

    .scan-logo-placeholder {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 44px; height: 44px;
      border-radius: 10px;
      background: var(--brand);
      margin-bottom: 8px;
      font-size: 22px;
      color: #fff;
    }

    .scan-company {
      font-size: 11px;
      color: rgba(255,255,255,.4);
      letter-spacing: .8px;
      text-transform: uppercase;
      margin: 0;
    }

    /* ── Asset tag banner ──────────────────────────────────────────── */
    .scan-tag-banner {
      background: linear-gradient(135deg, var(--brand) 0%, color-mix(in srgb, var(--brand) 80%, #000) 100%);
      padding: 14px 24px;
      text-align: center;
    }

    .scan-tag-label {
      font-size: 10px;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      color: rgba(255,255,255,.6);
      display: block;
      margin-bottom: 2px;
    }

    .scan-tag-value {
      font-size: 22px;
      font-weight: 800;
      font-family: 'Courier New', 'Consolas', monospace;
      color: #fff;
      letter-spacing: 2px;
    }

    /* ── Body ──────────────────────────────────────────────────────── */
    .scan-body {
      padding: 20px 24px;
    }

    .scan-asset-name {
      font-size: 1.3rem;
      font-weight: 700;
      color: #0f172a;
      margin: 0 0 6px;
      line-height: 1.25;
    }

    .scan-status-row {
      margin-bottom: 18px;
    }

    /* ── Info rows ─────────────────────────────────────────────────── */
    .scan-info-list {
      border-top: 1px solid #f1f5f9;
      padding-top: 16px;
      display: flex;
      flex-direction: column;
      gap: 0;
    }

    .scan-info-row {
      display: flex;
      align-items: baseline;
      gap: 10px;
      padding: 9px 0;
      border-bottom: 1px solid #f8fafc;
    }

    .scan-info-row:last-child { border-bottom: none; }

    .scan-info-label {
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .6px;
      color: #94a3b8;
      flex-shrink: 0;
      min-width: 110px;
    }

    .scan-info-value {
      font-size: 13.5px;
      color: #1e293b;
      font-weight: 500;
      word-break: break-word;
    }

    .scan-info-icon {
      color: var(--brand);
      font-size: 13px;
      flex-shrink: 0;
      margin-right: 2px;
    }

    /* ── Warranty states ───────────────────────────────────────────── */
    .scan-warranty-expired {
      color: #dc2626;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .scan-warranty-expired::before { content: '⚠ '; }

    .scan-warranty-soon {
      color: #d97706;
      font-weight: 600;
    }

    .scan-warranty-ok {
      color: #16a34a;
      font-weight: 500;
    }

    /* ── Report issue button ───────────────────────────────────────── */
    .scan-footer {
      padding: 0 24px 24px;
    }

    .scan-report-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      width: 100%;
      padding: 12px 20px;
      background: #fff;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      color: #64748b;
      font-size: 13.5px;
      font-weight: 600;
      text-decoration: none;
      transition: border-color .18s, color .18s, box-shadow .18s;
    }

    .scan-report-btn:hover {
      border-color: var(--brand);
      color: var(--brand);
      box-shadow: 0 2px 12px rgba(0,0,0,.08);
    }

    .scan-report-btn i { font-size: 15px; }

    /* ── Error card ────────────────────────────────────────────────── */
    .scan-error-icon {
      font-size: 52px;
      color: #e2e8f0;
      margin-bottom: 12px;
      display: block;
    }

    /* ── Powered-by footer ─────────────────────────────────────────── */
    .scan-poweredby {
      text-align: center;
      margin-top: 18px;
      font-size: 11px;
      color: #cbd5e1;
      letter-spacing: .3px;
    }
  </style>
</head>
<body>

  <?php if ($error): ?>
  <!-- ── Error state ─────────────────────────────────────────────────── -->
  <div class="scan-card">
    <div class="scan-header">
      <?php if ($logoPath): ?>
        <img src="/asset-manager/<?= $logoPath ?>" alt="<?= $appName ?>" class="scan-logo">
      <?php else: ?>
        <span class="scan-logo-placeholder"><i class="bi bi-box-seam"></i></span>
      <?php endif; ?>
      <?php if ($companyName): ?>
        <p class="scan-company"><?= $companyName ?></p>
      <?php endif; ?>
    </div>

    <div class="scan-body text-center" style="padding: 40px 24px;">
      <span class="scan-error-icon"><i class="bi bi-qr-code-scan"></i></span>
      <h2 style="font-size:1.1rem;font-weight:700;color:#0f172a;margin-bottom:8px;">Asset Not Found</h2>
      <p style="color:#64748b;font-size:13.5px;margin:0;"><?= $error ?></p>
    </div>
  </div>

  <?php else: ?>
  <!-- ── Asset card ─────────────────────────────────────────────────── -->
  <div class="scan-card">

    <!-- Brand header -->
    <div class="scan-header">
      <?php if ($logoPath): ?>
        <img src="/asset-manager/<?= $logoPath ?>" alt="<?= $appName ?>" class="scan-logo">
      <?php else: ?>
        <span class="scan-logo-placeholder"><i class="bi bi-box-seam"></i></span>
      <?php endif; ?>
      <?php if ($companyName): ?>
        <p class="scan-company"><?= $companyName ?></p>
      <?php endif; ?>
    </div>

    <!-- Asset tag banner -->
    <div class="scan-tag-banner">
      <span class="scan-tag-label">Asset Tag</span>
      <div class="scan-tag-value"><?= htmlspecialchars($asset['asset_tag']) ?></div>
    </div>

    <!-- Body -->
    <div class="scan-body">

      <h1 class="scan-asset-name"><?= htmlspecialchars($asset['name'] ?? $asset['asset_name'] ?? '') ?></h1>

      <div class="scan-status-row">
        <?= statusBadge($asset['status'] ?? 'Unknown') ?>
      </div>

      <div class="scan-info-list">

        <?php if (!empty($asset['cat_name'])): ?>
        <div class="scan-info-row">
          <i class="bi bi-tag scan-info-icon"></i>
          <span class="scan-info-label">Category</span>
          <span class="scan-info-value"><?= htmlspecialchars($asset['cat_name']) ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($asset['dept_name'])): ?>
        <div class="scan-info-row">
          <i class="bi bi-building scan-info-icon"></i>
          <span class="scan-info-label">Department</span>
          <span class="scan-info-value"><?= htmlspecialchars($asset['dept_name']) ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($asset['building']) || !empty($asset['floor']) || !empty($asset['room'])): ?>
        <div class="scan-info-row">
          <i class="bi bi-geo-alt scan-info-icon"></i>
          <span class="scan-info-label">Location</span>
          <span class="scan-info-value"><?= locationStr($asset) ?></span>
        </div>
        <?php endif; ?>

        <div class="scan-info-row">
          <i class="bi bi-person scan-info-icon"></i>
          <span class="scan-info-label">Assigned To</span>
          <span class="scan-info-value">
            <?= !empty($asset['assigned_name'])
                ? htmlspecialchars($asset['assigned_name'])
                : '<span style="color:#94a3b8;">Unassigned</span>' ?>
          </span>
        </div>

        <?php if (!empty($asset['purchase_date'])): ?>
        <div class="scan-info-row">
          <i class="bi bi-calendar3 scan-info-icon"></i>
          <span class="scan-info-label">Purchased</span>
          <span class="scan-info-value"><?= htmlspecialchars($asset['purchase_date']) ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($asset['warranty_expiry'])): ?>
        <div class="scan-info-row">
          <i class="bi bi-shield-check scan-info-icon"></i>
          <span class="scan-info-label">Warranty</span>
          <span class="scan-info-value"><?= $warrantyHtml ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($asset['serial_number'])): ?>
        <div class="scan-info-row">
          <i class="bi bi-upc scan-info-icon"></i>
          <span class="scan-info-label">Serial No.</span>
          <span class="scan-info-value" style="font-family:monospace;font-size:12.5px;"><?= htmlspecialchars($asset['serial_number']) ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($asset['model'])): ?>
        <div class="scan-info-row">
          <i class="bi bi-cpu scan-info-icon"></i>
          <span class="scan-info-label">Model</span>
          <span class="scan-info-value"><?= htmlspecialchars($asset['model']) ?></span>
        </div>
        <?php endif; ?>

      </div><!-- /.scan-info-list -->
    </div><!-- /.scan-body -->

    <!-- Report issue -->
    <?php if ($alertEmail): ?>
    <div class="scan-footer">
      <a href="<?= $mailtoLink ?>" class="scan-report-btn">
        <i class="bi bi-exclamation-triangle"></i>
        Report Issue
      </a>
    </div>
    <?php endif; ?>

  </div><!-- /.scan-card -->
  <?php endif; ?>

  <p class="scan-poweredby"><?= $appName ?> &bull; Asset Management System</p>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
