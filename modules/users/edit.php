<?php
$pageTitle = 'Edit User';
require_once __DIR__ . '/../../includes/header.php';
requireRole('super_admin', 'admin');

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM users WHERE id=?");
$st->execute([$id]);
$u = $st->fetch();
if (!$u) { setFlash('danger','User not found.'); header('Location: index.php'); exit; }

$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name   = trim($_POST['name'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $role   = $_POST['role'] ?? $u['role'];
    $deptId = (int)($_POST['department_id'] ?? 0);
    $pass   = $_POST['password'] ?? '';

    if (!$name)  $errors[] = 'Name required.';
    if (!$email) $errors[] = 'Email required.';

    if (empty($errors)) {
        if ($pass) {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET name=?,email=?,role=?,department_id=?,password_hash=? WHERE id=?")
                ->execute([$name,$email,$role,$deptId?:null,$hash,$id]);
        } else {
            $pdo->prepare("UPDATE users SET name=?,email=?,role=?,department_id=? WHERE id=?")
                ->execute([$name,$email,$role,$deptId?:null,$id]);
        }
        setFlash('success','User updated.');
        header('Location: index.php');
        exit;
    }
    $u = array_merge($u, $_POST);
}
?>
<div class="page-header"><h2><i class="bi bi-pencil me-2"></i>Edit User</h2></div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo "<li>{$e}</li>"; ?></ul></div><?php endif; ?>

<div class="row"><div class="col-md-6">
<div class="card"><div class="card-body">
  <form method="POST" class="row g-3">
    <div class="col-12">
      <label class="form-label fw-semibold small">Full Name</label>
      <input type="text" name="name" class="form-control" value="<?= e($u['name']) ?>" required>
    </div>
    <div class="col-12">
      <label class="form-label fw-semibold small">Email</label>
      <input type="email" name="email" class="form-control" value="<?= e($u['email']) ?>" required>
    </div>
    <div class="col-12">
      <label class="form-label fw-semibold small">New Password <small class="text-muted">(leave blank to keep current)</small></label>
      <input type="password" name="password" class="form-control" placeholder="Min 6 characters">
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold small">Role</label>
      <select name="role" class="form-select" <?= (!isSuperAdmin() && $u['role']==='super_admin') ? 'disabled' : '' ?>>
        <?php if (isSuperAdmin()): ?>
        <option value="super_admin" <?= $u['role']==='super_admin'?'selected':'' ?>>Super Admin</option>
        <?php endif; ?>
        <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>Admin</option>
        <option value="it" <?= $u['role']==='it'?'selected':'' ?>>IT</option>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold small">Department</label>
      <select name="department_id" class="form-select">
        <option value="">— None —</option>
        <?php foreach ($departments as $d): ?>
        <option value="<?= $d['id'] ?>" <?= $u['department_id']==$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 d-flex gap-2">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div></div>
</div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
