<?php
$pageTitle = 'Categories';
require_once __DIR__ . '/../../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAdmin()) {
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? 'Other';
    if ($name) {
        $pdo->prepare("INSERT INTO categories (name, type) VALUES (?,?)")->execute([$name, $type]);
        setFlash('success', "Category '{$name}' added.");
    }
    header('Location: index.php'); exit;
}

if (isset($_GET['delete']) && isAdmin()) {
    $cid = (int)$_GET['delete'];
    $inUse = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE category_id=?");
    $inUse->execute([$cid]);
    if ($inUse->fetchColumn() > 0) {
        setFlash('danger', 'Cannot delete: category is in use by assets.');
    } else {
        $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$cid]);
        setFlash('success', 'Category deleted.');
    }
    header('Location: index.php'); exit;
}

$categories = $pdo->query(
    "SELECT c.*, COUNT(a.id) AS asset_count FROM categories c LEFT JOIN assets a ON a.category_id=c.id GROUP BY c.id ORDER BY c.type, c.name"
)->fetchAll();
$types = ['IT','Furniture','Office Equipment','Vehicle','Networking','Software','Other'];
?>

<div class="page-header d-flex justify-content-between align-items-center">
  <div><h2><i class="bi bi-tags me-2 text-primary"></i>Categories</h2></div>
</div>

<div class="row g-4">
  <?php if (isAdmin()): ?>
  <div class="col-md-4">
    <div class="card">
      <div class="card-header fw-semibold">Add Category</div>
      <div class="card-body">
        <form method="POST" class="row g-2">
          <div class="col-12">
            <label class="form-label small fw-semibold">Name</label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold">Type</label>
            <select name="type" class="form-select">
              <?php foreach ($types as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12"><button type="submit" class="btn btn-primary w-100">Add Category</button></div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="col">
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Name</th><th>Type</th><th>Assets</th><?php if(isAdmin()): ?><th>Actions</th><?php endif; ?></tr></thead>
          <tbody>
            <?php foreach ($categories as $c): ?>
            <tr>
              <td><?= e($c['name']) ?></td>
              <td><span class="badge bg-secondary"><?= e($c['type']) ?></span></td>
              <td><span class="badge bg-primary"><?= $c['asset_count'] ?></span></td>
              <?php if (isAdmin()): ?>
              <td>
                <?php if ($c['asset_count'] == 0): ?>
                <a href="?delete=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger"
                   onclick="return confirm('Delete this category?')"><i class="bi bi-trash"></i></a>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
