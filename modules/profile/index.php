<?php
$pageTitle = 'My Profile';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/mail.php';

$userId = (int)$_SESSION['user_id'];
$me     = $pdo->prepare("SELECT * FROM users WHERE id=?");
$me->execute([$userId]);
$me = $me->fetch();

$errors  = [];
$success = '';

// ── Change password ───────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $me['password_hash'])) $errors[] = 'Current password is incorrect.';
    if (strlen($new) < 6)    $errors[] = 'New password must be at least 6 characters.';
    if ($new !== $confirm)   $errors[] = 'New passwords do not match.';

    if (empty($errors)) {
        $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_BCRYPT), $userId]);
        auditLog($pdo, 'password_change', 'users', $userId);
        setFlash('success', 'Password changed successfully.');
        header('Location: index.php'); exit;
    }
}

// ── Toggle OTP ────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'toggle_otp') {
    $newState = $me['otp_enabled'] ? 0 : 1;
    $pdo->prepare("UPDATE users SET otp_enabled=? WHERE id=?")->execute([$newState, $userId]);
    setFlash('success', $newState ? 'Two-factor authentication enabled.' : 'Two-factor authentication disabled.');
    header('Location: index.php'); exit;
}

$settings = getSettings($pdo);
$mailjetOk = !empty($settings['mailjet_api_key']) && !empty($settings['mailjet_from_email']);
?>

<div class="page-header">
  <h2><i class="bi bi-person-circle me-2" style="color:var(--brand-primary)"></i>My Profile</h2>
</div>

<div class="row g-4">

  <!-- Profile info -->
  <div class="col-md-4">
    <div class="card text-center">
      <div class="card-body py-4">
        <div style="width:72px;height:72px;border-radius:50%;background:var(--brand-primary);color:#fff;font-size:28px;font-weight:800;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
          <?= strtoupper(substr($me['name'],0,1)) ?>
        </div>
        <h5 class="fw-bold mb-1"><?= e($me['name']) ?></h5>
        <p class="text-muted small mb-2"><?= e($me['email']) ?></p>
        <span class="badge <?= roleBadgeClass($me['role']) ?>"><?= roleLabel($me['role']) ?></span>
        <hr>
        <div class="text-start small">
          <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Last Login</span><span><?= $me['last_login'] ? date('d M Y H:i', strtotime($me['last_login'])) : 'Never' ?></span></div>
          <div class="d-flex justify-content-between py-1"><span class="text-muted">Account Since</span><span><?= date('d M Y', strtotime($me['created_at'])) ?></span></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-md-8">

    <!-- Change password -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-lock me-2"></i>Change Password</div>
      <div class="card-body">
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo "<li>{$e}</li>"; ?></ul></div>
        <?php endif; ?>
        <form method="POST" class="row g-3">
          <input type="hidden" name="action" value="password">
          <div class="col-md-4">
            <label class="form-label fw-semibold small">Current Password</label>
            <input type="password" name="current_password" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">New Password</label>
            <input type="password" name="new_password" class="form-control" placeholder="Min 6 chars" required>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">Confirm New</label>
            <input type="password" name="confirm_password" class="form-control" required>
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-primary btn-sm">Update Password</button>
          </div>
        </form>
      </div>
    </div>

    <!-- 2FA / OTP -->
    <div class="card">
      <div class="card-header"><i class="bi bi-shield-lock me-2"></i>Two-Factor Authentication (OTP)</div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <?php if ($me['otp_enabled']): ?>
          <div class="p-2 rounded" style="background:#d1fae5;"><i class="bi bi-shield-check text-success fs-4"></i></div>
          <div>
            <div class="fw-semibold">OTP is <span class="text-success">Enabled</span></div>
            <div class="small text-muted">A 6-digit code is sent to your email at each login.</div>
          </div>
          <?php else: ?>
          <div class="p-2 rounded" style="background:#fee2e2;"><i class="bi bi-shield-x text-danger fs-4"></i></div>
          <div>
            <div class="fw-semibold">OTP is <span class="text-danger">Disabled</span></div>
            <div class="small text-muted">Enable to receive a verification code by email on each login.</div>
          </div>
          <?php endif; ?>
        </div>

        <?php if (!$mailjetOk): ?>
        <div class="alert alert-warning py-2 small">
          <i class="bi bi-exclamation-triangle me-1"></i>
          Mailjet is not configured. OTP requires email. Ask your Super Admin to configure Mailjet in Settings.
        </div>
        <?php endif; ?>

        <form method="POST">
          <input type="hidden" name="action" value="toggle_otp">
          <button type="submit" class="btn btn-sm <?= $me['otp_enabled'] ? 'btn-outline-danger' : 'btn-primary' ?>"
                  <?= !$mailjetOk ? 'disabled' : '' ?>>
            <i class="bi bi-<?= $me['otp_enabled'] ? 'shield-x' : 'shield-check' ?> me-1"></i>
            <?= $me['otp_enabled'] ? 'Disable OTP' : 'Enable OTP' ?>
          </button>
        </form>
      </div>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
