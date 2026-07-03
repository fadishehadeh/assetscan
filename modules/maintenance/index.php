<?php
$pageTitle = 'Maintenance';
require_once __DIR__ . '/../../includes/header.php';

$settings = getSettings($pdo);
$records  = $pdo->query(
    "SELECT m.*, a.name AS asset_name, a.asset_tag, u.name AS logged_by_name
     FROM maintenance m
     LEFT JOIN assets a ON m.asset_id = a.id
     LEFT JOIN users u ON m.logged_by = u.id
     ORDER BY m.date DESC LIMIT 50"
)->fetchAll();
?>

<div class="page-header d-flex justify-content-between align-items-center">
  <div><h2><i class="bi bi-wrench me-2 text-primary"></i>Maintenance Log</h2><p>All maintenance and repair records.</p></div>
  <?php if (isAdmin() || isIT()): ?>
  <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Log Maintenance</a>
  <?php endif; ?>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Asset</th><th>Type</th><th>Date</th><th>Cost</th><th>Vendor</th><th>Notes</th><th>Logged By</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($records as $m): ?>
        <tr>
          <td>
            <a href="/asset-manager/modules/assets/view.php?id=<?= $m['asset_id'] ?>" class="text-dark fw-semibold text-decoration-none"><?= e($m['asset_name']) ?></a>
            <br><code class="small text-muted"><?= e($m['asset_tag']) ?></code>
          </td>
          <td><span class="badge bg-secondary"><?= ucfirst($m['type']) ?></span></td>
          <td><?= date('d M Y', strtotime($m['date'])) ?></td>
          <td><?= formatMoney((float)$m['cost'], $settings['currency'] ?? 'USD') ?></td>
          <td><?= e($m['vendor'] ?? '—') ?></td>
          <td><small><?= e($m['notes'] ?? '—') ?></small></td>
          <td><?= e($m['logged_by_name'] ?? '—') ?></td>
          <td>
            <div class="btn-group btn-group-sm">
              <a href="edit.php?id=<?= $m['id'] ?>" class="btn btn-outline-primary py-0 px-2"><i class="bi bi-pencil"></i></a>
              <?php if (isAdmin()): ?>
              <a href="delete.php?id=<?= $m['id'] ?>" class="btn btn-outline-danger py-0 px-2"
                 onclick="return confirm('Delete this record?')"><i class="bi bi-trash"></i></a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($records)): ?>
        <tr><td colspan="7" class="text-center text-muted py-5">No maintenance records.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
