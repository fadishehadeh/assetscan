<?php
$pageTitle = 'End-of-Life Assets';
require_once __DIR__ . '/../../includes/header.php';

$settings = getSettings($pdo);
$currency = $settings['currency'] ?? 'USD';

$assets = $pdo->query(
    "SELECT a.*, c.name AS cat_name, d.name AS dept_name, u.name AS assigned_name,
            TIMESTAMPDIFF(YEAR, a.purchase_date, CURDATE()) AS age_years
     FROM assets a
     LEFT JOIN categories c ON a.category_id = c.id
     LEFT JOIN departments d ON a.department_id = d.id
     LEFT JOIN users u ON a.assigned_to = u.id
     WHERE a.status = 'active'
       AND a.purchase_date IS NOT NULL
       AND TIMESTAMPDIFF(YEAR, a.purchase_date, CURDATE()) >= a.useful_life_years
     ORDER BY age_years DESC"
)->fetchAll();
?>
<div class="page-header d-flex justify-content-between align-items-center">
  <div>
    <h2><i class="bi bi-exclamation-triangle me-2 text-danger"></i>End-of-Life Assets</h2>
    <p>Active assets that have exceeded their useful life — <strong><?= count($assets) ?></strong> found.</p>
  </div>
  <div class="d-flex gap-2">
    <a href="export_pdf.php?report=eol" target="_blank" class="btn btn-outline-danger btn-sm"><i class="bi bi-file-pdf me-1"></i>PDF</a>
    <a href="../reports/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Reports</a>
  </div>
</div>

<?php if (empty($assets)): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
  <i class="bi bi-check-circle text-success" style="font-size:48px;"></i>
  <p class="mt-3 mb-0">No end-of-life assets found.</p>
</div></div>
<?php else: ?>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr>
        <th>Asset Tag</th><th>Name</th><th>Category</th><th>Dept</th>
        <th>Purchase Date</th><th>Useful Life</th><th>Age</th>
        <th>Cost</th><th>Assigned To</th><th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($assets as $a): ?>
        <tr>
          <td><code class="text-danger"><?= e($a['asset_tag']) ?></code></td>
          <td><a href="/modules/assets/view.php?id=<?= $a['id'] ?>" class="fw-semibold text-dark text-decoration-none"><?= e($a['name']) ?></a></td>
          <td><span class="badge bg-light text-dark"><?= e($a['cat_name'] ?? '—') ?></span></td>
          <td><?= e($a['dept_name'] ?? '—') ?></td>
          <td><?= $a['purchase_date'] ? date('d M Y', strtotime($a['purchase_date'])) : '—' ?></td>
          <td><?= $a['useful_life_years'] ?> yrs</td>
          <td><span class="badge bg-danger"><?= $a['age_years'] ?> yrs old</span></td>
          <td><?= formatMoney((float)$a['purchase_cost'], $currency) ?></td>
          <td><?= e($a['assigned_name'] ?? '—') ?></td>
          <td>
            <a href="/modules/assets/edit.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-warning py-0 px-2">
              <i class="bi bi-pencil"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
