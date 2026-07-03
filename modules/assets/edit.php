<?php
$pageTitle = 'Edit Asset';
require_once __DIR__ . '/../../includes/header.php';
if (!isAdmin() && !isIT()) { header('Location: index.php'); exit; }

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$assetSt = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
$assetSt->execute([$id]);
$a = $assetSt->fetch();
if (!$a) { setFlash('danger', 'Asset not found.'); header('Location: index.php'); exit; }

$categories  = $pdo->query("SELECT * FROM categories ORDER BY type, name")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$locations   = $pdo->query("SELECT * FROM locations ORDER BY building, floor, room")->fetchAll();
$users       = $pdo->query("SELECT id, name FROM users WHERE is_active=1 ORDER BY name")->fetchAll();
$settings    = getSettings($pdo);
$errors      = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name'] ?? '');
    $catId      = (int)($_POST['category_id'] ?? 0);
    $serial     = trim($_POST['serial_number'] ?? '');
    $model      = trim($_POST['model'] ?? '');
    $brand      = trim($_POST['brand'] ?? '');
    $purchDate  = $_POST['purchase_date'] ?? '';
    $purchCost  = (float)($_POST['purchase_cost'] ?? 0);
    $vendor     = trim($_POST['vendor'] ?? '');
    $warranty   = $_POST['warranty_expiry'] ?? '';
    $lifeYears  = max(1, (int)($_POST['useful_life_years'] ?? 5));
    $salvage    = (float)($_POST['salvage_value'] ?? 0);
    $depMethod  = $_POST['depreciation_method'] ?? 'straight_line';
    $status     = $_POST['status'] ?? 'active';
    $locationId = (int)($_POST['location_id'] ?? 0);
    $deptId     = (int)($_POST['department_id'] ?? 0);
    $assignedTo = (int)($_POST['assigned_to'] ?? 0);
    $notes      = trim($_POST['notes'] ?? '');

    if (!$name) $errors[] = 'Asset name is required.';

    if (empty($errors)) {
        $old = $a;
        $pdo->prepare("UPDATE assets SET
            name=?, category_id=?, serial_number=?, model=?, brand=?, purchase_date=?,
            purchase_cost=?, vendor=?, warranty_expiry=?, useful_life_years=?, salvage_value=?,
            depreciation_method=?, status=?, location_id=?, department_id=?, assigned_to=?, notes=?
            WHERE id=?")->execute([
            $name, $catId ?: null, $serial ?: null, $model ?: null, $brand ?: null,
            $purchDate ?: null, $purchCost, $vendor ?: null, $warranty ?: null,
            $lifeYears, $salvage, $depMethod, $status,
            $locationId ?: null, $deptId ?: null, $assignedTo ?: null, $notes ?: null,
            $id
        ]);

        if ($old['status'] !== $status) {
            logAssetHistory($pdo, $id, 'status_changed', $old['status'], $status);
        }
        if ($old['assigned_to'] !== ($assignedTo ?: null)) {
            logAssetHistory($pdo, $id, 'assignment_changed', (string)$old['assigned_to'], (string)($assignedTo ?: ''));
        }
        auditLog($pdo, 'update', 'assets', $id, $old);

        setFlash('success', 'Asset updated successfully.');
        header('Location: view.php?id=' . $id);
        exit;
    }

    // Re-populate from POST on error
    $a = array_merge($a, $_POST);
}
?>

<div class="page-header d-flex justify-content-between align-items-center">
  <div>
    <h2><i class="bi bi-pencil me-2 text-primary"></i>Edit Asset</h2>
    <p><code><?= e($a['asset_tag']) ?></code></p>
  </div>
  <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo "<li>{$e}</li>"; ?></ul></div>
<?php endif; ?>

<form method="POST" class="row g-4">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">Basic Information</div>
      <div class="card-body row g-3">
        <div class="col-md-8">
          <label class="form-label fw-semibold small">Asset Name *</label>
          <input type="text" name="name" class="form-control" value="<?= e($a['name']) ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Category</label>
          <select name="category_id" class="form-select">
            <option value="">— Select —</option>
            <?php
            $lastType = '';
            foreach ($categories as $cat):
                if ($cat['type'] !== $lastType) {
                    if ($lastType) echo '</optgroup>';
                    echo '<optgroup label="' . e($cat['type']) . '">';
                    $lastType = $cat['type'];
                }
            ?>
            <option value="<?= $cat['id'] ?>" <?= $a['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
            <?php endforeach; if ($lastType) echo '</optgroup>'; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Brand</label>
          <input type="text" name="brand" class="form-control" value="<?= e($a['brand'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Model</label>
          <input type="text" name="model" class="form-control" value="<?= e($a['model'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Serial Number</label>
          <input type="text" name="serial_number" class="form-control" value="<?= e($a['serial_number'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Purchase Date</label>
          <input type="date" name="purchase_date" class="form-control" value="<?= e($a['purchase_date'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Purchase Cost</label>
          <input type="number" name="purchase_cost" step="0.01" min="0" class="form-control" value="<?= e($a['purchase_cost'] ?? '0') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Vendor</label>
          <input type="text" name="vendor" class="form-control" value="<?= e($a['vendor'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Warranty Expiry</label>
          <input type="date" name="warranty_expiry" class="form-control" value="<?= e($a['warranty_expiry'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Status</label>
          <select name="status" class="form-select">
            <?php foreach (['active','under_maintenance','disposed','lost'] as $s): ?>
            <option value="<?= $s ?>" <?= $a['status'] === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold small">Notes</label>
          <textarea name="notes" class="form-control" rows="2"><?= e($a['notes'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <div class="card mt-4">
      <div class="card-header">Depreciation Settings</div>
      <div class="card-body row g-3">
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Useful Life (Years)</label>
          <input type="number" name="useful_life_years" min="1" max="50" class="form-control" value="<?= e($a['useful_life_years']) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Salvage Value</label>
          <input type="number" name="salvage_value" step="0.01" min="0" class="form-control" value="<?= e($a['salvage_value']) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Depreciation Method</label>
          <select name="depreciation_method" class="form-select">
            <option value="straight_line" <?= $a['depreciation_method']==='straight_line' ? 'selected':'' ?>>Straight-Line</option>
            <option value="declining_balance" <?= $a['depreciation_method']==='declining_balance' ? 'selected':'' ?>>Declining Balance</option>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">Location & Assignment</div>
      <div class="card-body row g-3">
        <div class="col-12">
          <label class="form-label fw-semibold small">Department</label>
          <select name="department_id" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($departments as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $a['department_id'] == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold small">Location</label>
          <select name="location_id" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($locations as $l): ?>
            <option value="<?= $l['id'] ?>" <?= $a['location_id'] == $l['id'] ? 'selected' : '' ?>>
              <?= e($l['building'] . ' › ' . $l['floor'] . 'F › ' . $l['room']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold small">Assigned To</label>
          <select name="assigned_to" class="form-select">
            <option value="">— Unassigned —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>" <?= $a['assigned_to'] == $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
    <div class="card mt-3">
      <div class="card-body d-grid gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </div>
  </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
