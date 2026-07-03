<?php
$pageTitle = 'Warranty Expiry';
require_once __DIR__ . '/../../includes/header.php';

$settings = getSettings($pdo);
$currency = $settings['currency'] ?? 'USD';
$days     = (int)($_GET['days'] ?? 30);

$assets = $pdo->query(
    "SELECT a.*, c.name AS cat_name, d.name AS dept_name, u.name AS assigned_name,
            DATEDIFF(a.warranty_expiry, CURDATE()) AS days_left
     FROM assets a
     LEFT JOIN categories c ON a.category_id = c.id
     LEFT JOIN departments d ON a.department_id = d.id
     LEFT JOIN users u ON a.assigned_to = u.id
     WHERE a.warranty_expiry IS NOT NULL
       AND a.warranty_expiry >= CURDATE()
       AND a.warranty_expiry <= DATE_ADD(CURDATE(), INTERVAL {$days} DAY)
     ORDER BY a.warranty_expiry ASC"
)->fetchAll();

$expired = $pdo->query(
    "SELECT a.*, c.name AS cat_name, u.name AS assigned_name,
            DATEDIFF(CURDATE(), a.warranty_expiry) AS days_ago
     FROM assets a
     LEFT JOIN categories c ON a.category_id = c.id
     LEFT JOIN users u ON a.assigned_to = u.id
     WHERE a.warranty_expiry < CURDATE() AND a.status = 'active'
     ORDER BY a.warranty_expiry DESC LIMIT 30"
)->fetchAll();
?>
<div class="page-header d-flex justify-content-between align-items-center">
  <div>
    <h2><i class="bi bi-shield-exclamation me-2 text-warning"></i>Warranty Status</h2>
    <p>Track upcoming and expired warranties.</p>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <select onchange="location='?days='+this.value" class="form-select form-select-sm" style="width:auto;">
      <?php foreach ([14,30,60,90] as $d): ?>
      <option value="<?= $d ?>" <?= $days==$d?'selected':'' ?>>Next <?= $d ?> days</option>
      <?php endforeach; ?>
    </select>
    <a href="export_pdf.php?report=warranty" target="_blank" class="btn btn-outline-danger btn-sm"><i class="bi bi-file-pdf me-1"></i>PDF</a>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>
</div>

<!-- Expiring soon -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between">
    <span><i class="bi bi-clock-history me-2 text-warning"></i>Expiring within <?= $days ?> days</span>
    <span class="badge bg-warning text-dark"><?= count($assets) ?></span>
  </div>
  <?php if (empty($assets)): ?>
  <div class="card-body text-center text-muted py-4"><i class="bi bi-check-circle text-success me-2"></i>No warranties expiring in the next <?= $days ?> days.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Tag</th><th>Name</th><th>Category</th><th>Expiry Date</th><th>Days Left</th><th>Assigned To</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($assets as $a):
          $urgency = $a['days_left'] <= 7 ? 'danger' : ($a['days_left'] <= 14 ? 'warning' : 'info');
        ?>
        <tr>
          <td><code><?= e($a['asset_tag']) ?></code></td>
          <td><a href="/modules/assets/view.php?id=<?= $a['id'] ?>" class="fw-semibold text-dark text-decoration-none"><?= e($a['name']) ?></a></td>
          <td><span class="badge bg-light text-dark"><?= e($a['cat_name'] ?? '—') ?></span></td>
          <td><?= date('d M Y', strtotime($a['warranty_expiry'])) ?></td>
          <td><span class="badge bg-<?= $urgency ?>"><?= $a['days_left'] ?> days</span></td>
          <td><?= e($a['assigned_name'] ?? '—') ?></td>
          <td><a href="/modules/assets/edit.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-pencil"></i></a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Already expired -->
<div class="card">
  <div class="card-header d-flex justify-content-between">
    <span><i class="bi bi-shield-x me-2 text-danger"></i>Already Expired (active assets)</span>
    <span class="badge bg-danger"><?= count($expired) ?></span>
  </div>
  <?php if (empty($expired)): ?>
  <div class="card-body text-center text-muted py-4">No expired warranties on active assets.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Tag</th><th>Name</th><th>Category</th><th>Expired On</th><th>Days Ago</th><th>Assigned To</th></tr></thead>
      <tbody>
        <?php foreach ($expired as $a): ?>
        <tr>
          <td><code><?= e($a['asset_tag']) ?></code></td>
          <td><a href="/modules/assets/view.php?id=<?= $a['id'] ?>" class="fw-semibold text-dark text-decoration-none"><?= e($a['name']) ?></a></td>
          <td><span class="badge bg-light text-dark"><?= e($a['cat_name'] ?? '—') ?></span></td>
          <td class="text-danger"><?= date('d M Y', strtotime($a['warranty_expiry'])) ?></td>
          <td><span class="badge bg-secondary"><?= $a['days_ago'] ?> days ago</span></td>
          <td><?= e($a['assigned_name'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
