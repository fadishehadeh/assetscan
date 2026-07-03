<?php
$pageTitle = 'Edit Maintenance';
require_once __DIR__ . '/../../includes/header.php';
if (!isAdmin() && !isIT()) { header('Location: index.php'); exit; }

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM maintenance WHERE id=?");
$st->execute([$id]);
$m = $st->fetch();
if (!$m) { setFlash('danger','Record not found.'); header('Location: index.php'); exit; }

$assets = $pdo->query("SELECT id, name, asset_tag FROM assets ORDER BY name")->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assetId = (int)($_POST['asset_id'] ?? 0);
    $type    = $_POST['type'] ?? 'repair';
    $date    = $_POST['date'] ?? '';
    $cost    = (float)($_POST['cost'] ?? 0);
    $vendor  = trim($_POST['vendor'] ?? '');
    $notes   = trim($_POST['notes'] ?? '');
    if (!$assetId) $errors[] = 'Asset required.';
    if (!$date)    $errors[] = 'Date required.';
    if (empty($errors)) {
        $pdo->prepare("UPDATE maintenance SET asset_id=?,type=?,date=?,cost=?,vendor=?,notes=? WHERE id=?")
            ->execute([$assetId,$type,$date,$cost,$vendor?:null,$notes?:null,$id]);
        setFlash('success','Maintenance record updated.');
        header('Location: index.php'); exit;
    }
    $m = array_merge($m, $_POST);
}
?>
<div class="page-header"><h2><i class="bi bi-pencil me-2"></i>Edit Maintenance Record</h2></div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo "<li>{$e}</li>"; ?></ul></div><?php endif; ?>
<div class="row"><div class="col-md-6">
<div class="card"><div class="card-body">
<form method="POST" class="row g-3">
  <div class="col-12">
    <label class="form-label fw-semibold small">Asset *</label>
    <select name="asset_id" class="form-select" required>
      <?php foreach ($assets as $a): ?>
      <option value="<?= $a['id'] ?>" <?= $m['asset_id']==$a['id']?'selected':'' ?>><?= e($a['name']) ?> (<?= e($a['asset_tag']) ?>)</option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-6">
    <label class="form-label fw-semibold small">Type</label>
    <select name="type" class="form-select">
      <?php foreach (['repair','service','inspection','upgrade'] as $t): ?>
      <option value="<?= $t ?>" <?= $m['type']===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-6">
    <label class="form-label fw-semibold small">Date *</label>
    <input type="date" name="date" class="form-control" value="<?= e($m['date']) ?>" required>
  </div>
  <div class="col-md-6">
    <label class="form-label fw-semibold small">Cost</label>
    <input type="number" name="cost" step="0.01" min="0" class="form-control" value="<?= e($m['cost']) ?>">
  </div>
  <div class="col-md-6">
    <label class="form-label fw-semibold small">Vendor</label>
    <input type="text" name="vendor" class="form-control" value="<?= e($m['vendor'] ?? '') ?>">
  </div>
  <div class="col-12">
    <label class="form-label fw-semibold small">Notes</label>
    <textarea name="notes" class="form-control" rows="3"><?= e($m['notes'] ?? '') ?></textarea>
  </div>
  <div class="col-12 d-flex gap-2">
    <button type="submit" class="btn btn-primary">Save Changes</button>
    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>
</div></div>
</div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
