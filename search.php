<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$q = trim($_GET['q'] ?? '');
$format = $_GET['format'] ?? 'html';

if ($format === 'json') {
    header('Content-Type: application/json');
    if (strlen($q) < 2) { echo json_encode([]); exit; }

    $like = '%' . $q . '%';
    $results = [];

    // Assets
    $rows = $pdo->prepare("SELECT id, asset_tag, name, status FROM assets WHERE asset_tag LIKE ? OR name LIKE ? OR serial_number LIKE ? OR brand LIKE ? OR model LIKE ? LIMIT 5");
    $rows->execute([$like,$like,$like,$like,$like]);
    foreach ($rows->fetchAll() as $r) {
        $results[] = ['type'=>'asset','id'=>$r['id'],'label'=>$r['asset_tag'].' — '.$r['name'],'sub'=>ucfirst($r['status']),'url'=>'/modules/assets/view.php?id='.$r['id']];
    }

    // Users
    $rows = $pdo->prepare("SELECT id, name, email, role FROM users WHERE name LIKE ? OR email LIKE ? LIMIT 3");
    $rows->execute([$like,$like]);
    foreach ($rows->fetchAll() as $r) {
        $results[] = ['type'=>'user','id'=>$r['id'],'label'=>$r['name'],'sub'=>$r['email'],'url'=>'/modules/users/index.php'];
    }

    // Maintenance
    $rows = $pdo->prepare("SELECT m.id, m.description, m.type, a.name as asset_name FROM maintenance m LEFT JOIN assets a ON m.asset_id=a.id WHERE m.description LIKE ? OR m.technician LIKE ? OR a.name LIKE ? LIMIT 3");
    $rows->execute([$like,$like,$like]);
    foreach ($rows->fetchAll() as $r) {
        $results[] = ['type'=>'maintenance','id'=>$r['id'],'label'=>substr($r['description'],0,60),'sub'=>$r['asset_name'].' · '.ucfirst($r['type']),'url'=>'/modules/maintenance/index.php'];
    }

    echo json_encode($results);
    exit;
}

// Full results page
$pageTitle = 'Search: ' . ($q ?: 'All');
require_once __DIR__ . '/includes/header.php';

$assets = $users = $maintenance = [];
if ($q !== '') {
    $like = '%' . $q . '%';

    $stmt = $pdo->prepare(
        "SELECT a.id, a.asset_tag, a.name, a.status, a.brand, a.model, a.serial_number,
                c.name as cat_name, d.name as dept_name
         FROM assets a
         LEFT JOIN categories c ON a.category_id=c.id
         LEFT JOIN departments d ON a.department_id=d.id
         WHERE a.asset_tag LIKE ? OR a.name LIKE ? OR a.serial_number LIKE ?
            OR a.brand LIKE ? OR a.model LIKE ? OR a.vendor LIKE ? OR a.notes LIKE ?
         ORDER BY a.name LIMIT 50"
    );
    $stmt->execute([$like,$like,$like,$like,$like,$like,$like]);
    $assets = $stmt->fetchAll();

    if (isAdmin()) {
        $stmt = $pdo->prepare("SELECT id, name, email, role, is_active FROM users WHERE name LIKE ? OR email LIKE ? ORDER BY name LIMIT 20");
        $stmt->execute([$like,$like]);
        $users = $stmt->fetchAll();
    }

    $stmt = $pdo->prepare(
        "SELECT m.*, a.name as asset_name, a.asset_tag, u.name as logged_by_name
         FROM maintenance m
         LEFT JOIN assets a ON m.asset_id=a.id
         LEFT JOIN users u ON m.logged_by=u.id
         WHERE m.description LIKE ? OR m.technician LIKE ? OR a.name LIKE ? OR a.asset_tag LIKE ?
         ORDER BY m.date DESC LIMIT 20"
    );
    $stmt->execute([$like,$like,$like,$like]);
    $maintenance = $stmt->fetchAll();
}

$total = count($assets) + count($users) + count($maintenance);
?>

<div class="page-header d-flex justify-content-between align-items-center">
  <div>
    <h2><i class="bi bi-search me-2"></i>Search Results</h2>
    <?php if ($q): ?>
    <p><?= $total ?> result<?= $total !== 1 ? 's' : '' ?> for <strong>"<?= e($q) ?>"</strong></p>
    <?php else: ?>
    <p class="text-muted">Enter a search term above.</p>
    <?php endif; ?>
  </div>
</div>

<?php if ($q && $total === 0): ?>
<div class="card text-center py-5">
  <div class="card-body">
    <i class="bi bi-search" style="font-size:48px;opacity:.2;"></i>
    <p class="mt-3 text-muted">No results found for "<?= e($q) ?>".<br>Try a different asset tag, name, or serial number.</p>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($assets)): ?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-boxes"></i> Assets <span class="badge bg-secondary ms-1"><?= count($assets) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Tag</th><th>Name</th><th>Brand / Model</th><th>Category</th><th>Department</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($assets as $a): ?>
      <tr onclick="location.href='/modules/assets/view.php?id=<?= $a['id'] ?>'" style="cursor:pointer">
        <td><code class="text-primary"><?= e($a['asset_tag']) ?></code></td>
        <td><strong><?= e($a['name']) ?></strong><?php if($a['serial_number']): ?><br><span class="text-muted small"><?= e($a['serial_number']) ?></span><?php endif; ?></td>
        <td><?= e(trim(($a['brand']??'').' '.($a['model']??''))) ?: '—' ?></td>
        <td><?= e($a['cat_name'] ?? '—') ?></td>
        <td><?= e($a['dept_name'] ?? '—') ?></td>
        <td><?= statusBadge($a['status']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($users)): ?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-people"></i> Users <span class="badge bg-secondary ms-1"><?= count($users) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
      <tr onclick="location.href='/modules/users/index.php'" style="cursor:pointer">
        <td><strong><?= e($u['name']) ?></strong></td>
        <td><?= e($u['email']) ?></td>
        <td><span class="badge <?= roleBadgeClass($u['role']) ?>"><?= roleLabel($u['role']) ?></span></td>
        <td><?= $u['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($maintenance)): ?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-wrench"></i> Maintenance Records <span class="badge bg-secondary ms-1"><?= count($maintenance) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Asset</th><th>Type</th><th>Description</th><th>Date</th><th>Technician</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($maintenance as $m): ?>
      <tr onclick="location.href='/modules/maintenance/index.php'" style="cursor:pointer">
        <td><code class="text-primary small"><?= e($m['asset_tag']) ?></code><br><span class="small"><?= e($m['asset_name']) ?></span></td>
        <td><span class="badge bg-secondary"><?= ucfirst($m['type']) ?></span></td>
        <td><?= e(mb_substr($m['description'],0,80)) ?><?= strlen($m['description'])>80?'…':'' ?></td>
        <td><?= $m['date'] ? date('d M Y', strtotime($m['date'])) : '—' ?></td>
        <td><?= e($m['technician'] ?? '—') ?></td>
        <td><?= statusBadge($m['status'] ?? 'scheduled') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
