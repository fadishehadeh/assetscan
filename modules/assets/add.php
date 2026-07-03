<?php
// ── Bootstrap (no output yet) ─────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!isAdmin() && !isIT()) { setFlash('danger','Access denied.'); header('Location: index.php'); exit; }

// ── Process POST ──────────────────────────────────────────────────────
$errors = [];
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
        $tag = generateAssetTag($pdo);
        $st  = $pdo->prepare("INSERT INTO assets
            (asset_tag, name, category_id, serial_number, model, brand, purchase_date, purchase_cost,
             vendor, warranty_expiry, useful_life_years, salvage_value, depreciation_method, status,
             location_id, department_id, assigned_to, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([
            $tag, $name, $catId ?: null, $serial ?: null, $model ?: null, $brand ?: null,
            $purchDate ?: null, $purchCost, $vendor ?: null, $warranty ?: null,
            $lifeYears, $salvage, $depMethod, $status,
            $locationId ?: null, $deptId ?: null, $assignedTo ?: null, $notes ?: null,
            $_SESSION['user_id']
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Handle image upload
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp'], true) && $_FILES['image']['size'] < 3*1024*1024) {
                $imgDir  = __DIR__ . '/../../uploads/assets/';
                if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
                $imgFile = 'asset_' . $newId . '.' . $ext;
                move_uploaded_file($_FILES['image']['tmp_name'], $imgDir . $imgFile);
                $pdo->prepare("UPDATE assets SET image_path=? WHERE id=?")->execute(['uploads/assets/'.$imgFile, $newId]);
            }
        }

        // Generate QR
        $qrPath = generateQRCode($newId, $tag);
        $pdo->prepare("UPDATE assets SET qr_code_path=? WHERE id=?")->execute([$qrPath, $newId]);

        logAssetHistory($pdo, $newId, 'created', null, $tag);
        auditLog($pdo, 'create', 'assets', $newId, null, ['name'=>$name,'tag'=>$tag]);

        setFlash('success', "Asset <strong>{$tag}</strong> — {$name} created successfully.");
        header('Location: view.php?id=' . $newId);
        exit;
    }
}

// ── Load form data ────────────────────────────────────────────────────
$categories  = $pdo->query("SELECT * FROM categories ORDER BY type, name")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$locations   = $pdo->query("SELECT * FROM locations ORDER BY building, floor, room")->fetchAll();
$users       = $pdo->query("SELECT id, name FROM users WHERE is_active=1 ORDER BY name")->fetchAll();
$settings    = getSettings($pdo);

// ── Output ────────────────────────────────────────────────────────────
$pageTitle = 'Add Asset';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <h2><i class="bi bi-plus-circle me-2" style="color:var(--brand-primary)"></i>Add New Asset</h2>
  <p>Fill in the details below to register a new asset.</p>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo "<li>".e($err)."</li>"; ?></ul></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="row g-4">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><i class="bi bi-info-circle me-2"></i>Basic Information</div>
      <div class="card-body row g-3">
        <div class="col-md-8">
          <label class="form-label fw-semibold small">Asset Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" value="<?= e($_POST['name'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Category</label>
          <select name="category_id" class="form-select">
            <option value="">— Select —</option>
            <?php $lastType=''; foreach ($categories as $cat):
              if ($cat['type'] !== $lastType) { if ($lastType) echo '</optgroup>'; echo '<optgroup label="'.e($cat['type']).'">'; $lastType=$cat['type']; } ?>
            <option value="<?= $cat['id'] ?>" <?= (($_POST['category_id']??0)==$cat['id'])?'selected':'' ?>><?= e($cat['name']) ?></option>
            <?php endforeach; if($lastType) echo '</optgroup>'; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Brand</label>
          <input type="text" name="brand" class="form-control" value="<?= e($_POST['brand'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Model</label>
          <input type="text" name="model" class="form-control" value="<?= e($_POST['model'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Serial Number</label>
          <input type="text" name="serial_number" class="form-control" value="<?= e($_POST['serial_number'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Purchase Date</label>
          <input type="date" name="purchase_date" class="form-control" value="<?= e($_POST['purchase_date'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Purchase Cost (<?= e($settings['currency'] ?? 'USD') ?>)</label>
          <input type="number" name="purchase_cost" step="0.01" min="0" class="form-control" value="<?= e($_POST['purchase_cost'] ?? '0') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Vendor / Supplier</label>
          <input type="text" name="vendor" class="form-control" value="<?= e($_POST['vendor'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Warranty Expiry</label>
          <input type="date" name="warranty_expiry" class="form-control" value="<?= e($_POST['warranty_expiry'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Status</label>
          <select name="status" class="form-select">
            <?php foreach(['active'=>'Active','under_maintenance'=>'Under Maintenance','disposed'=>'Disposed','lost'=>'Lost'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= ($_POST['status']??'active')===$v?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold small">Asset Photo <small class="text-muted">(optional, max 3MB)</small></label>
          <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.webp">
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold small">Notes</label>
          <textarea name="notes" class="form-control" rows="2"><?= e($_POST['notes'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <div class="card mt-4">
      <div class="card-header"><i class="bi bi-graph-down me-2"></i>Depreciation Settings</div>
      <div class="card-body row g-3">
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Useful Life (Years)</label>
          <input type="number" name="useful_life_years" min="1" max="50" class="form-control" value="<?= e($_POST['useful_life_years'] ?? '5') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Salvage Value</label>
          <input type="number" name="salvage_value" step="0.01" min="0" class="form-control" value="<?= e($_POST['salvage_value'] ?? '0') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">Depreciation Method</label>
          <select name="depreciation_method" class="form-select">
            <option value="straight_line" <?= ($_POST['depreciation_method']??'straight_line')==='straight_line'?'selected':'' ?>>Straight-Line</option>
            <option value="declining_balance" <?= ($_POST['depreciation_method']??'')==='declining_balance'?'selected':'' ?>>Declining Balance</option>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="bi bi-geo-alt me-2"></i>Location & Assignment</div>
      <div class="card-body row g-3">
        <div class="col-12">
          <label class="form-label fw-semibold small">Department</label>
          <select name="department_id" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>" <?= (($_POST['department_id']??0)==$d['id'])?'selected':'' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold small">Location</label>
          <select name="location_id" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>" <?= (($_POST['location_id']??0)==$l['id'])?'selected':'' ?>><?= e($l['building'].' › Fl.'.$l['floor'].' › '.$l['room']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold small">Assigned To</label>
          <select name="assigned_to" class="form-select">
            <option value="">— Unassigned —</option>
            <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= (($_POST['assigned_to']??0)==$u['id'])?'selected':'' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
    <div class="card mt-3">
      <div class="card-body">
        <p class="small text-muted mb-3"><i class="bi bi-qr-code me-1"></i>QR code auto-generated after saving.</p>
        <div class="d-grid gap-2">
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Asset</button>
          <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </div>
    </div>
  </div>
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
