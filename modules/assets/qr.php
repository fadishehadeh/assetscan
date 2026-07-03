<?php
$pageTitle = 'QR Code';
// Minimal session bootstrap without full header
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$st = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
$st->execute([$id]);
$a = $st->fetch();
if (!$a) { echo 'Asset not found'; exit; }

// Ensure QR exists
if (!$a['qr_code_path'] || !file_exists(__DIR__ . '/../../' . $a['qr_code_path'])) {
    $qrPath = generateQRCode($a['id'], $a['asset_tag']);
    $pdo->prepare("UPDATE assets SET qr_code_path=? WHERE id=?")->execute([$qrPath, $id]);
    $a['qr_code_path'] = $qrPath;
}

$settings = getSettings($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>QR Label – <?= e($a['asset_tag']) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    body { background: #f8f9fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .label-card { background: white; border: 2px solid #222; border-radius: 12px; padding: 24px; width: 300px; text-align: center; }
    .company-name { font-size: 13px; font-weight: 700; color: #333; text-transform: uppercase; letter-spacing: 1px; }
    .asset-tag { font-size: 18px; font-weight: 900; font-family: monospace; color: #1d4ed8; margin: 8px 0; }
    .asset-name { font-size: 14px; color: #333; margin-bottom: 8px; }
    .qr-img { width: 180px; height: 180px; }
    .meta { font-size: 11px; color: #666; margin-top: 8px; }
    @media print {
      body { background: white; }
      .no-print { display: none; }
      .label-card { border: 1px solid #000; box-shadow: none; }
    }
  </style>
</head>
<body>
  <div>
    <div class="label-card shadow">
      <div class="company-name"><?= e($settings['company_name'] ?? 'Company') ?></div>
      <div class="asset-tag"><?= e($a['asset_tag']) ?></div>
      <div class="asset-name"><?= e($a['name']) ?></div>
      <img src="/asset-manager/<?= e($a['qr_code_path']) ?>" class="qr-img" alt="QR Code">
      <div class="meta">
        <?php if ($a['serial_number']): ?>S/N: <?= e($a['serial_number']) ?><br><?php endif; ?>
        Scan to view full details
      </div>
    </div>
    <div class="text-center mt-3 no-print">
      <button onclick="window.print()" class="btn btn-primary btn-sm me-2">
        <i class="bi bi-printer"></i> Print Label
      </button>
      <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">Back to Asset</a>
    </div>
  </div>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</body>
</html>
