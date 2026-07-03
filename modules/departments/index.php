<?php
$pageTitle = 'Departments';
require_once __DIR__ . '/../../includes/header.php';
requireRole('super_admin', 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $loc  = trim($_POST['location'] ?? '');
    if ($name) {
        $pdo->prepare("INSERT INTO departments (name, location) VALUES (?,?)")->execute([$name, $loc ?: null]);
        setFlash('success', "Department '{$name}' added.");
    }
    header('Location: index.php'); exit;
}

if (isset($_GET['delete'])) {
    $did = (int)$_GET['delete'];
    $inUse = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE department_id=?");
    $inUse->execute([$did]);
    if ($inUse->fetchColumn() > 0) {
        setFlash('danger', 'Cannot delete: assets are assigned to this department.');
    } else {
        $pdo->prepare("DELETE FROM departments WHERE id=?")->execute([$did]);
        setFlash('success', 'Department deleted.');
    }
    header('Location: index.php'); exit;
}

$departments = $pdo->query(
    "SELECT d.*, COUNT(a.id) AS asset_count FROM departments d LEFT JOIN assets a ON a.department_id=d.id GROUP BY d.id ORDER BY d.name"
)->fetchAll();
?>

<div class="page-header"><h2><i class="bi bi-diagram-3 me-2 text-primary"></i>Departments</h2></div>

<div class="row g-4">
  <div class="col-md-4">
    <div class="card"><div class="card-header fw-semibold">Add Department</div>
    <div class="card-body">
      <form method="POST" class="row g-2">
        <div class="col-12"><label class="form-label small fw-semibold">Department Name</label><input type="text" name="name" class="form-control" required></div>
        <div class="col-12"><label class="form-label small fw-semibold">Location</label><input type="text" name="location" class="form-control"></div>
        <div class="col-12"><button type="submit" class="btn btn-primary w-100">Add</button></div>
      </form>
    </div></div>
  </div>
  <div class="col">
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Name</th><th>Location</th><th>Assets</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($departments as $d): ?>
            <tr>
              <td><?= e($d['name']) ?></td>
              <td><?= e($d['location'] ?? '—') ?></td>
              <td><span class="badge bg-primary"><?= $d['asset_count'] ?></span></td>
              <td><?php if ($d['asset_count'] == 0): ?>
                <a href="?delete=<?= $d['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete?')"><i class="bi bi-trash"></i></a>
              <?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
