<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mail.php';

// Must have pending_user_id in session
if (empty($_SESSION['pending_user_id'])) {
    header('Location: /index.php'); exit;
}

$userId   = (int)$_SESSION['pending_user_id'];
$settings = getSettings($pdo);
$primary  = $settings['primary_color'] ?? '#E84B37';
$logoPath = $settings['logo_path']     ?? 'assets/img/logo.svg';
$appName  = $settings['app_name']      ?? 'Asset Manager';

$user = $pdo->prepare("SELECT * FROM users WHERE id=?");
$user->execute([$userId]);
$user = $user->fetch();
if (!$user) { session_destroy(); header('Location: /index.php'); exit; }

$error = '';

// ── Send OTP if not yet sent ──────────────────────────────────────────
if (empty($_SESSION['otp_sent'])) {
    $otp     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + 600); // 10 min

    $pdo->prepare("UPDATE users SET otp_code=?, otp_expires_at=? WHERE id=?")
        ->execute([$otp, $expires, $userId]);

    $sent = sendOtpEmail($pdo, $user['email'], $user['name'], $otp);
    $_SESSION['otp_sent'] = true;
    if (!$sent) $error = 'Could not send OTP email. Check Mailjet settings.';
}

// ── Resend ────────────────────────────────────────────────────────────
if (isset($_GET['resend'])) {
    unset($_SESSION['otp_sent']);
    header('Location: /otp.php'); exit;
}

// ── Verify OTP ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered = trim(implode('', $_POST['digit'] ?? []));
    if (strlen($entered) !== 6) $error = 'Enter the full 6-digit code.';

    if (!$error) {
        $fresh = $pdo->prepare("SELECT otp_code, otp_expires_at FROM users WHERE id=?");
        $fresh->execute([$userId]);
        $row = $fresh->fetch();

        if ($row['otp_code'] === $entered && strtotime($row['otp_expires_at']) >= time()) {
            // OTP valid — complete login
            $pdo->prepare("UPDATE users SET otp_code=NULL, otp_expires_at=NULL, last_login=NOW() WHERE id=?")
                ->execute([$userId]);

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            unset($_SESSION['pending_user_id'], $_SESSION['otp_sent']);

            header('Location: /modules/dashboard/index.php'); exit;
        } else {
            $error = 'Invalid or expired code. Please try again or resend.';
        }
    }
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify OTP — <?= e($appName) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
  :root { --brand-primary: <?= e($primary) ?>; }
  body { background: linear-gradient(135deg, #0f172a 0%, #1e293b 55%, #111827 100%); }
  .otp-wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
  .otp-card { width:420px; max-width:100%; background:#fff; border-radius:18px; overflow:hidden; box-shadow:0 30px 70px rgba(0,0,0,.5); }
  .otp-top { background:#111827; padding:28px 32px 22px; text-align:center; border-bottom:3px solid <?= e($primary) ?>; }
  .otp-top img { height:42px; margin-bottom:10px; }
  .otp-body { padding:28px 32px 32px; }
  .digit-inputs { display:flex; gap:10px; justify-content:center; margin:20px 0; }
  .digit-inputs input {
    width:46px; height:56px; text-align:center; font-size:22px; font-weight:800;
    border:2px solid #e2e8f0; border-radius:10px; font-family:monospace;
    transition:border-color .15s;
  }
  .digit-inputs input:focus { border-color:<?= e($primary) ?>; box-shadow:0 0 0 3px rgba(232,75,55,.12); outline:none; }
  .btn-brand { background:<?= e($primary) ?>; color:#fff; border:none; font-weight:700; border-radius:9px; padding:11px; width:100%; font-size:14px; }
  .btn-brand:hover { opacity:.9; color:#fff; }
</style>
</head>
<body>
<div class="otp-wrap">
  <div class="otp-card">
    <div class="otp-top">
      <img src="/<?= e($logoPath) ?>" alt="<?= e($appName) ?>">
      <div style="color:rgba(255,255,255,.45);font-size:11px;letter-spacing:.8px;text-transform:uppercase;">Two-Factor Verification</div>
    </div>
    <div class="otp-body">
      <p class="fw-semibold text-dark mb-1" style="font-size:16px;">Check your email</p>
      <p class="text-muted small mb-0">
        We sent a 6-digit code to <strong><?= e(substr($user['email'], 0, 3) . str_repeat('*', strpos($user['email'],'@')-3) . substr($user['email'], strpos($user['email'],'@'))) ?></strong>.
        It expires in 10 minutes.
      </p>

      <?php if ($error): ?>
      <div class="alert alert-danger d-flex gap-2 align-items-center mt-3 py-2" style="font-size:13px;">
        <i class="bi bi-exclamation-circle-fill"></i> <?= e($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST" id="otpForm">
        <div class="digit-inputs">
          <?php for ($i=0;$i<6;$i++): ?>
          <input type="text" name="digit[]" maxlength="1" inputmode="numeric" pattern="[0-9]"
                 id="d<?= $i ?>" autocomplete="one-time-code" required>
          <?php endfor; ?>
        </div>
        <button type="submit" class="btn btn-brand">
          <i class="bi bi-shield-check me-2"></i>Verify Code
        </button>
      </form>

      <div class="mt-3 text-center">
        <small class="text-muted">Didn't receive it? &nbsp;
          <a href="?resend=1" style="color:<?= e($primary) ?>;font-weight:600;">Resend Code</a>
        </small>
      </div>
      <div class="mt-2 text-center">
        <a href="/index.php" class="small text-muted">← Back to Login</a>
      </div>
    </div>
  </div>
</div>

<script>
// Auto-advance OTP digit inputs
const inputs = document.querySelectorAll('.digit-inputs input');
inputs.forEach((inp, i) => {
  inp.addEventListener('input', () => {
    inp.value = inp.value.replace(/\D/,'');
    if (inp.value && i < inputs.length - 1) inputs[i+1].focus();
    if ([...inputs].every(x => x.value)) document.getElementById('otpForm').submit();
  });
  inp.addEventListener('keydown', e => {
    if (e.key === 'Backspace' && !inp.value && i > 0) inputs[i-1].focus();
  });
  inp.addEventListener('paste', e => {
    const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'');
    if (text.length === 6) {
      inputs.forEach((x,j) => x.value = text[j] || '');
      document.getElementById('otpForm').submit();
    }
    e.preventDefault();
  });
});
inputs[0].focus();
</script>
</body>
</html>
