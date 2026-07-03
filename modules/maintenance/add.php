<?php
$pageTitle = 'Log Maintenance';
require_once __DIR__ . '/../../includes/header.php';
if (!isAdmin() && !isIT()) { header('Location: index.php'); exit; }

$assetId = (int)($_GET['asset_id'] ?? 0);
$assets  = $pdo->query("SELECT id, name, asset_tag FROM assets ORDER BY name")->fetchAll();
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assetId = (int)($_POST['asset_id'] ?? 0);
    $type    = $_POST['type'] ?? 'repair';
    $date    = $_POST['date'] ?? date('Y-m-d');
    $cost    = (float)($_POST['cost'] ?? 0);
    $vendor  = trim($_POST['vendor'] ?? '');
    $notes   = trim($_POST['notes'] ?? '');

    if (!$assetId) $errors[] = 'Select an asset.';
    if (!$date)    $errors[] = 'Date is required.';

    if (empty($errors)) {
        $pdo->prepare("INSERT INTO maintenance (asset_id, type, date, cost, vendor, notes, logged_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$assetId, $type, $date, $cost, $vendor ?: null, $notes ?: null, $_SESSION['user_id']]);

        if ($type === 'repair') {
            $pdo->prepare("UPDATE assets SET status='under_maintenance' WHERE id=?")->execute([$assetId]);
        }
        logAssetHistory($pdo, $assetId, 'maintenance_logged', null, $type, $notes);

        setFlash('success', 'Maintenance record saved.');
        header('Location: /modules/assets/view.php?id=' . $assetId);
        exit;
    }
}
?>

<div class="page-header"><h2><i class="bi bi-wrench me-2"></i>Log Maintenance</h2></div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo "<li>{$e}</li>"; ?></ul></div><?php endif; ?>

<div class="row"><div class="col-md-6">
<div class="card"><div class="card-body">
<form method="POST" class="row g-3">
  <div class="col-12">
    <label class="form-label fw-semibold small">Asset *</label>
    <select name="asset_id" class="form-select" required>
      <option value="">— Select Asset —</option>
      <?php foreach ($assets as $a): ?>
      <option value="<?= $a['id'] ?>" <?= $assetId==$a['id']?'selected':'' ?>><?= e($a['name']) ?> (<?= e($a['asset_tag']) ?>)</option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-6">
    <label class="form-label fw-semibold small">Type</label>
    <select name="type" class="form-select">
      <option value="repair">Repair</option>
      <option value="service">Service</option>
      <option value="inspection">Inspection</option>
      <option value="upgrade">Upgrade</option>
    </select>
  </div>
  <div class="col-md-6">
    <label class="form-label fw-semibold small">Date *</label>
    <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
  </div>
  <div class="col-md-6">
    <label class="form-label fw-semibold small">Cost</label>
    <input type="number" name="cost" step="0.01" min="0" class="form-control" value="0">
  </div>
  <div class="col-md-6">
    <label class="form-label fw-semibold small">Vendor</label>
    <input type="text" name="vendor" class="form-control">
  </div>
  <div class="col-12">
    <label class="form-label fw-semibold small">Notes</label>
    <textarea name="notes" class="form-control" rows="3"></textarea>
  </div>
  <div class="col-12 d-flex gap-2">
    <button type="submit" class="btn btn-primary">Save Record</button>
    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>
</div></div>
</div></div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
