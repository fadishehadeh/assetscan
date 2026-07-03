<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$settings = getSettings($pdo);
$company  = $settings['company_name'] ?? 'Company';
$primary  = $settings['primary_color'] ?? '#E84B37';

// Build filter
$catId  = (int)($_GET['category'] ?? 0);
$deptId = (int)($_GET['department'] ?? 0);
$ids    = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));

$where  = "WHERE a.status = 'active'";
$params = [];
if ($catId)  { $where .= " AND a.category_id = ?"; $params[] = $catId; }
if ($deptId) { $where .= " AND a.department_id = ?"; $params[] = $deptId; }
if ($ids)    { $in = implode(',', $ids); $where .= " AND a.id IN ({$in})"; }

$st = $pdo->prepare("SELECT a.* FROM assets a $where ORDER BY a.name");
$st->execute($params);
$assets = $st->fetchAll();

// Generate any missing QR codes
require_once __DIR__ . '/../../libs/phpqrcode/qrlib.php';
foreach ($assets as &$a) {
    if (!$a['qr_code_path'] || !file_exists(__DIR__ . '/../../' . $a['qr_code_path'])) {
        $qrPath = generateQRCode($a['id'], $a['asset_tag']);
        $pdo->prepare("UPDATE assets SET qr_code_path=? WHERE id=?")->execute([$qrPath, $a['id']]);
        $a['qr_code_path'] = $qrPath;
    }
}
unset($a);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Bulk QR Print — <?= e($company) ?></title>
<style>
  body { font-family: 'Segoe UI', Arial, sans-serif; background:#f0f2f5; margin:0; }
  .no-print { background:#fff; border-bottom:1px solid #e2e8f0; padding:12px 20px; display:flex; align-items:center; gap:12px; }
  .no-print button { background:<?= e($primary) ?>; color:#fff; border:none; padding:8px 22px; border-radius:7px; font-size:13px; font-weight:700; cursor:pointer; }
  .no-print a { color:<?= e($primary) ?>; text-decoration:none; font-size:13px; }
  .no-print .count { color:#64748b; font-size:13px; margin-left:auto; }
  .labels-grid { display:flex; flex-wrap:wrap; gap:14px; padding:20px; justify-content:flex-start; }
  .qr-label { background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; padding:14px; width:160px; text-align:center; box-shadow:0 1px 4px rgba(0,0,0,.07); page-break-inside:avoid; }
  .qr-label .company { font-size:8px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px; }
  .qr-label .tag { font-size:11px; font-weight:900; font-family:monospace; color:<?= e($primary) ?>; margin-bottom:4px; }
  .qr-label .asset-name { font-size:9.5px; color:#374151; margin-bottom:8px; line-height:1.3; min-height:26px; }
  .qr-label img { width:110px; height:110px; display:block; margin:0 auto 6px; }
  .qr-label .serial { font-size:8.5px; color:#94a3b8; }
  .empty-state { text-align:center; padding:60px; color:#64748b; }
  @media print {
    .no-print { display:none !important; }
    body { background:#fff; }
    .labels-grid { padding:0; gap:8px; }
    .qr-label { border:1px solid #ccc; box-shadow:none; }
  }
</style>
</head>
<body>

<div class="no-print">
  <strong style="font-size:14px;">🖨 Bulk QR Print</strong>
  <button onclick="window.print()">Print All Labels</button>
  <a href="/modules/assets/index.php">← Back to Assets</a>
  <span class="count"><?= count($assets) ?> labels</span>
</div>

<?php if (empty($assets)): ?>
<div class="empty-state">
  <div style="font-size:48px;">📭</div>
  <p>No assets found with the selected filters.</p>
</div>
<?php else: ?>
<div class="labels-grid">
  <?php foreach ($assets as $a): ?>
  <div class="qr-label">
    <div class="company"><?= e($company) ?></div>
    <div class="tag"><?= e($a['asset_tag']) ?></div>
    <div class="asset-name"><?= e(mb_strimwidth($a['name'], 0, 40, '…')) ?></div>
    <?php if ($a['qr_code_path'] && file_exists(__DIR__ . '/../../' . $a['qr_code_path'])): ?>
    <img src="/<?= e($a['qr_code_path']) ?>" alt="QR">
    <?php else: ?>
    <div style="width:110px;height:110px;background:#f1f5f9;margin:0 auto 6px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:10px;color:#94a3b8;">No QR</div>
    <?php endif; ?>
    <?php if ($a['serial_number']): ?>
    <div class="serial">S/N: <?= e($a['serial_number']) ?></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

</body>
</html>
