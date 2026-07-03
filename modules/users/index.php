<?php
$pageTitle = 'Users';
require_once __DIR__ . '/../../includes/header.php';
requireRole('super_admin', 'admin');

$users = $pdo->query(
    "SELECT u.*, d.name AS dept_name FROM users u LEFT JOIN departments d ON u.department_id=d.id ORDER BY u.role, u.name"
)->fetchAll();
?>

<div class="page-header d-flex justify-content-between align-items-center">
  <div><h2><i class="bi bi-people me-2 text-primary"></i>Users</h2><p>Manage system users and roles.</p></div>
  <a href="add.php" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Add User</a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Last Login</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['name']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td><span class="badge <?= roleBadgeClass($u['role']) ?>"><?= roleLabel($u['role']) ?></span></td>
          <td><?= e($u['dept_name'] ?? '—') ?></td>
          <td><?= $u['last_login'] ? date('d M Y H:i', strtotime($u['last_login'])) : 'Never' ?></td>
          <td><?= $u['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
          <td>
            <div class="btn-group btn-group-sm">
              <a href="edit.php?id=<?= $u['id'] ?>" class="btn btn-outline-primary"><i class="bi bi-pencil"></i></a>
              <?php if ($u['id'] != $_SESSION['user_id']): ?>
              <a href="toggle.php?id=<?= $u['id'] ?>" class="btn btn-outline-<?= $u['is_active'] ? 'warning' : 'success' ?>"
                 onclick="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> this user?')">
                <i class="bi bi-<?= $u['is_active'] ? 'pause' : 'play' ?>"></i>
              </a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
