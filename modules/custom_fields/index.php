<?php
// ── Bootstrap ─────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!isSuperAdmin()) {
    setFlash('danger', 'Access denied. Super Admin role required.');
    header('Location: /asset-manager/modules/dashboard/index.php');
    exit;
}

$fields = $pdo->query("
    SELECT cf.*, c.name AS category_name
    FROM custom_fields cf
    LEFT JOIN categories c ON cf.category_id = c.id
    ORDER BY cf.sort_order ASC, cf.id ASC
")->fetchAll();

$pageTitle = 'Custom Fields';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
  <div>
    <h2><i class="bi bi-sliders2 me-2" style="color:var(--brand-primary)"></i>Custom Fields</h2>
    <p class="text-muted mb-0">Define extra fields that appear on asset forms.</p>
  </div>
  <a href="add.php" class="btn btn-primary">
    <i class="bi bi-plus-circle me-1"></i>Add Custom Field
  </a>
</div>

<?php if (empty($fields)): ?>
  <div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>No custom fields defined yet.
    <a href="add.php" class="alert-link">Add the first one.</a>
  </div>
<?php else: ?>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:40px">#</th>
          <th>Label</th>
          <th>Field Name</th>
          <th>Type</th>
          <th>Category</th>
          <th class="text-center">Required</th>
          <th class="text-center">Sort</th>
          <th class="text-center">Active</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fields as $f): ?>
        <tr>
          <td class="text-muted small"><?= $f['id'] ?></td>
          <td class="fw-semibold"><?= e($f['field_label']) ?></td>
          <td><code class="small"><?= e($f['field_name']) ?></code></td>
          <td>
            <?php
            $typeIcons = [
                'text'     => 'bi-input-cursor-text',
                'number'   => 'bi-123',
                'date'     => 'bi-calendar',
                'select'   => 'bi-menu-button',
                'textarea' => 'bi-textarea-resize',
                'url'      => 'bi-link-45deg',
                'email'    => 'bi-envelope',
            ];
            $icon = $typeIcons[$f['field_type']] ?? 'bi-question';
            ?>
            <span class="badge bg-secondary"><i class="bi <?= $icon ?> me-1"></i><?= e(ucfirst($f['field_type'])) ?></span>
          </td>
          <td><?= $f['category_name'] ? e($f['category_name']) : '<span class="text-muted small">All Categories</span>' ?></td>
          <td class="text-center">
            <?= $f['is_required'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash text-muted"></i>' ?>
          </td>
          <td class="text-center">
            <span class="badge bg-light text-dark border"><?= (int)$f['sort_order'] ?></span>
          </td>
          <td class="text-center">
            <a href="toggle.php?id=<?= $f['id'] ?>" title="Toggle active status" class="text-decoration-none">
              <?php if ($f['is_active']): ?>
                <span class="badge bg-success"><i class="bi bi-toggle-on me-1"></i>Active</span>
              <?php else: ?>
                <span class="badge bg-secondary"><i class="bi bi-toggle-off me-1"></i>Inactive</span>
              <?php endif; ?>
            </a>
          </td>
          <td class="text-end">
            <a href="edit.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary me-1" title="Edit">
              <i class="bi bi-pencil"></i>
            </a>
            <a href="delete.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete"
               onclick="return confirm('Delete field &quot;<?= e(addslashes($f['field_label'])) ?>&quot; and all its values? This cannot be undone.')">
              <i class="bi bi-trash"></i>
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
