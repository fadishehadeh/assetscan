<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /modules/dashboard/index.php');
    exit;
}

// Show landing page for guests
if (!isset($_POST['email'])) {
    header('Location: /landing/index.html');
    exit;
}

$settings   = getSettings($pdo);
$appName    = $settings['app_name']      ?? 'Asset Manager';
$company    = $settings['company_name']  ?? 'My Company';
$logoPath   = $settings['logo_path']     ?? 'assets/img/logo.svg';
$primaryClr = $settings['primary_color'] ?? '#E84B37';
$sidebarClr = $settings['sidebar_color'] ?? '#111827';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $st = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
        $st->execute([$email]);
        $user = $st->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['otp_enabled']) {
                // Trigger OTP flow
                $_SESSION['pending_user_id'] = $user['id'];
                header('Location: /otp.php');
                exit;
            }
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            header('Location: /modules/dashboard/index.php');
            exit;
        }
    }
    $error = 'Invalid email or password.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign In — <?= e($appName) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    :root {
      --brand-primary:    <?= e($primaryClr) ?>;
      --brand-primary-dk: #c73a28;
      --topbar-bg:        <?= e($sidebarClr) ?>;
    }
    body { background: linear-gradient(135deg, #0f172a 0%, #1e293b 55%, #111827 100%); }
    .login-wrap { min-height: 100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
    .login-card { width: 420px; max-width:100%; border-radius: 18px; overflow: hidden; box-shadow: 0 30px 70px rgba(0,0,0,.5); }
    .login-brand-panel {
      background: <?= e($sidebarClr) ?>;
      padding: 36px 32px 26px;
      text-align: center;
      border-bottom: 3px solid <?= e($primaryClr) ?>;
    }
    .login-logo { height: 56px; width: auto; max-width: 240px; object-fit: contain; margin-bottom: 12px; }
    .login-subtitle {
      font-size: 11px;
      color: rgba(255,255,255,.4);
      letter-spacing: 1.2px;
      text-transform: uppercase;
      margin: 0;
    }
    .login-body { background: #fff; padding: 30px 32px 34px; }
    .login-body .form-control {
      border-radius: 8px;
      padding: 10px 14px;
      font-size: 14px;
      border: 1.5px solid #e2e8f0;
    }
    .login-body .form-control:focus {
      border-color: <?= e($primaryClr) ?>;
      box-shadow: 0 0 0 3px rgba(232,75,55,.12);
    }
    .btn-brand {
      background: <?= e($primaryClr) ?>;
      border: none;
      color: #fff;
      font-weight: 700;
      font-size: 14px;
      padding: 11px;
      border-radius: 9px;
      letter-spacing: .3px;
      transition: background .15s;
    }
    .btn-brand:hover { background: #c73a28; color: #fff; }
    .input-group-text { border: 1.5px solid #e2e8f0; background: #f8fafc; color: #94a3b8; }
    .powered-by { text-align:center; color: rgba(255,255,255,.2); font-size:11px; margin-top:24px; }
  </style>
</head>
<body>
<div class="login-wrap">
  <div>
    <div class="login-card">
      <!-- Brand panel -->
      <div class="login-brand-panel">
        <?php if (!empty($logoPath) && file_exists(__DIR__ . '/' . $logoPath)): ?>
          <img src="/<?= e($logoPath) ?>" alt="<?= e($appName) ?>" class="login-logo">
        <?php else: ?>
          <div style="font-family:'Inter',sans-serif; font-size:2rem; font-weight:900; color:#fff; letter-spacing:-1px; margin-bottom:8px;">
            Asset<span style="color:<?= e($primaryClr) ?>;">Scan</span>
          </div>
        <?php endif; ?>
        <p class="login-subtitle">Asset Management System</p>
      </div>

      <!-- Form -->
      <div class="login-body">
        <p class="fw-semibold text-dark mb-4" style="font-size:17px;">Welcome back</p>

        <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-3" style="font-size:13px;">
          <i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST">
          <div class="mb-3">
            <label class="form-label fw-semibold" style="font-size:12.5px;color:#374151;">Email Address</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-envelope" style="font-size:13px;"></i></span>
              <input type="email" name="email" class="form-control"
                     placeholder="you@company.com"
                     value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                     required autofocus>
            </div>
          </div>
          <div class="mb-4">
            <label class="form-label fw-semibold" style="font-size:12.5px;color:#374151;">Password</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock" style="font-size:13px;"></i></span>
              <input type="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>
          </div>
          <button type="submit" class="btn btn-brand w-100">
            <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
          </button>
        </form>
      </div>
    </div>
    <p class="powered-by">© <?= date('Y') ?> <?= e($company) ?> · All rights reserved</p>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
