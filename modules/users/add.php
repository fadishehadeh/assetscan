<?php
$pageTitle = 'Add User';
require_once __DIR__ . '/../../includes/header.php';
requireRole('super_admin', 'admin');

$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name   = trim($_POST['name'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $pass   = $_POST['password'] ?? '';
    $role   = $_POST['role'] ?? 'it';
    $deptId = (int)($_POST['department_id'] ?? 0);

    if (!$name)  $errors[] = 'Name is required.';
    if (!$email) $errors[] = 'Email is required.';
    if (!$pass || strlen($pass) < 6) $errors[] = 'Password must be at least 6 characters.';

    if (empty($errors)) {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $errors[] = 'Email already in use.';
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (name, email, password_hash, role, department_id) VALUES (?,?,?,?,?)")
                ->execute([$name, $email, $hash, $role, $deptId ?: null]);
            setFlash('success', "User {$name} created.");
            header('Location: index.php');
            exit;
        }
    }
}
?>
<div class="page-header"><h2><i class="bi bi-person-plus me-2"></i>Add User</h2></div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo "<li>{$e}</li>"; ?></ul></div><?php endif; ?>

<div class="row"><div class="col-md-6">
<div class="card">
  <div class="card-body">
    <form method="POST" class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold small">Full Name *</label>
        <input type="text" name="name" class="form-control" value="<?= e($_POST['name'] ?? '') ?>" required>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold small">Email *</label>
        <input type="email" name="email" class="form-control" value="<?= e($_POST['email'] ?? '') ?>" required>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold small">Password *</label>
        <input type="password" name="password" class="form-control" placeholder="Min 6 characters" required>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold small">Role</label>
        <select name="role" class="form-select">
          <?php if (isSuperAdmin()): ?>
          <option value="super_admin">Super Admin</option>
          <?php endif; ?>
          <option value="admin">Admin</option>
          <option value="it" selected>IT</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold small">Department</label>
        <select name="department_id" class="form-select">
          <option value="">— None —</option>
          <?php foreach ($departments as $d): ?>
          <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary">Create User</button>
        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>
</div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
