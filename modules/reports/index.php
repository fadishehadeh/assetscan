<?php
$pageTitle = 'Reports';
require_once __DIR__ . '/../../includes/header.php';
$settings = getSettings($pdo);
$currency = $settings['currency'] ?? 'USD';

// Summary stats
$total      = $pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
$totalCost  = $pdo->query("SELECT COALESCE(SUM(purchase_cost),0) FROM assets")->fetchColumn();
$byStatus   = $pdo->query("SELECT status, COUNT(*) AS cnt FROM assets GROUP BY status")->fetchAll();
$byCategory = $pdo->query("SELECT c.name, COUNT(a.id) AS cnt, COALESCE(SUM(a.purchase_cost),0) AS total FROM categories c LEFT JOIN assets a ON a.category_id=c.id GROUP BY c.id,c.name HAVING cnt>0 ORDER BY cnt DESC")->fetchAll();
$byDept     = $pdo->query("SELECT d.name, COUNT(a.id) AS cnt FROM departments d LEFT JOIN assets a ON a.department_id=d.id GROUP BY d.id,d.name")->fetchAll();
$eolAssets  = $pdo->query("SELECT a.*, c.name AS cat_name FROM assets a LEFT JOIN categories c ON a.category_id=c.id WHERE a.status='active' AND a.purchase_date IS NOT NULL AND TIMESTAMPDIFF(YEAR, a.purchase_date, CURDATE()) >= a.useful_life_years LIMIT 20")->fetchAll();
$warranty30 = $pdo->query("SELECT a.*, c.name AS cat_name FROM assets a LEFT JOIN categories c ON a.category_id=c.id WHERE a.warranty_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY a.warranty_expiry")->fetchAll();
?>

<div class="page-header d-flex justify-content-between align-items-center">
  <div><h2><i class="bi bi-file-earmark-bar-graph me-2 text-primary"></i>Reports</h2><p>Asset reports and exports.</p></div>
  <div class="d-flex gap-2 flex-wrap">
    <div class="dropdown">
      <button class="btn btn-outline-success btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Export</button>
      <ul class="dropdown-menu">
        <li><h6 class="dropdown-header">Asset Register</h6></li>
        <li><a class="dropdown-item" href="export_csv.php?report=assets">CSV — All Assets</a></li>
        <li><a class="dropdown-item" href="export_csv.php?report=assets&format=excel">Excel — All Assets</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><h6 class="dropdown-header">Other Reports</h6></li>
        <li><a class="dropdown-item" href="export_csv.php?report=eol">CSV — End of Life</a></li>
        <li><a class="dropdown-item" href="export_csv.php?report=warranty">CSV — Warranty Expiry</a></li>
        <li><a class="dropdown-item" href="export_csv.php?report=depreciation">CSV — Depreciation</a></li>
        <li><a class="dropdown-item" href="export_csv.php?report=eol&format=excel">Excel — End of Life</a></li>
        <li><a class="dropdown-item" href="export_csv.php?report=warranty&format=excel">Excel — Warranty</a></li>
      </ul>
    </div>
    <div class="dropdown">
      <button class="btn btn-outline-danger btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
      <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="export_pdf.php?report=assets" target="_blank">Asset Register</a></li>
        <li><a class="dropdown-item" href="export_pdf.php?report=eol" target="_blank">End of Life</a></li>
        <li><a class="dropdown-item" href="export_pdf.php?report=warranty" target="_blank">Warranty Expiry</a></li>
        <li><a class="dropdown-item" href="export_pdf.php?report=depreciation" target="_blank">Depreciation</a></li>
      </ul>
    </div>
  </div>
</div>

<!-- SUMMARY CARDS -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card text-center p-3">
      <div class="fs-2 fw-bold text-primary"><?= number_format($total) ?></div>
      <div class="text-muted small">Total Assets</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card text-center p-3">
      <div class="fs-2 fw-bold text-success"><?= formatMoney((float)$totalCost, $currency) ?></div>
      <div class="text-muted small">Total Asset Value</div>
    </div>
  </div>
  <?php foreach ($byStatus as $s): ?>
  <div class="col-md-3">
    <div class="card text-center p-3">
      <div class="fs-2 fw-bold"><?= $s['cnt'] ?></div>
      <div><?= statusBadge($s['status']) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-4">
  <!-- By Category -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header fw-semibold"><i class="bi bi-tags me-2"></i>Assets by Category</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Category</th><th>Count</th><th>Total Value</th></tr></thead>
          <tbody>
            <?php foreach ($byCategory as $row): ?>
            <tr>
              <td><?= e($row['name']) ?></td>
              <td><span class="badge bg-primary"><?= $row['cnt'] ?></span></td>
              <td><?= formatMoney((float)$row['total'], $currency) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- By Department -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header fw-semibold"><i class="bi bi-diagram-3 me-2"></i>Assets by Department</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Department</th><th>Count</th></tr></thead>
          <tbody>
            <?php foreach ($byDept as $row): ?>
            <tr><td><?= e($row['name']) ?></td><td><span class="badge bg-primary"><?= $row['cnt'] ?></span></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- EOL -->
  <?php if (!empty($eolAssets)): ?>
  <div class="col-12">
    <div class="card border-danger">
      <div class="card-header bg-danger text-white fw-semibold"><i class="bi bi-exclamation-triangle me-2"></i>End-of-Life Assets (<?= count($eolAssets) ?>)</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Tag</th><th>Name</th><th>Category</th><th>Purchase Date</th><th>Useful Life</th></tr></thead>
          <tbody>
            <?php foreach ($eolAssets as $a): ?>
            <tr>
              <td><code><?= e($a['asset_tag']) ?></code></td>
              <td><a href="/modules/assets/view.php?id=<?= $a['id'] ?>"><?= e($a['name']) ?></a></td>
              <td><?= e($a['cat_name'] ?? '—') ?></td>
              <td><?= $a['purchase_date'] ? date('d M Y', strtotime($a['purchase_date'])) : '—' ?></td>
              <td><?= $a['useful_life_years'] ?> years</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Warranty -->
  <?php if (!empty($warranty30)): ?>
  <div class="col-12">
    <div class="card border-warning">
      <div class="card-header bg-warning fw-semibold"><i class="bi bi-shield-exclamation me-2"></i>Warranty Expiring in 30 Days (<?= count($warranty30) ?>)</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Tag</th><th>Name</th><th>Category</th><th>Warranty Expiry</th></tr></thead>
          <tbody>
            <?php foreach ($warranty30 as $a): ?>
            <tr>
              <td><code><?= e($a['asset_tag']) ?></code></td>
              <td><a href="/modules/assets/view.php?id=<?= $a['id'] ?>"><?= e($a['name']) ?></a></td>
              <td><?= e($a['cat_name'] ?? '—') ?></td>
              <td class="text-danger fw-bold"><?= date('d M Y', strtotime($a['warranty_expiry'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
