<?php
$pageTitle = 'Mailjet Settings';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/mail.php';
requireRole('super_admin');

$s = getSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['test_email'])) {
        // Send test email
        $sent = sendMail($pdo, $_POST['test_to'] ?? $s['mailjet_from_email'], 'Test Recipient',
            'Mailjet Test — ' . ($s['app_name'] ?? 'Asset Manager'),
            '<p>This is a test email from your <strong>'.e($s['app_name'] ?? 'Asset Manager').'</strong> asset management system.</p><p>Mailjet is configured correctly ✅</p>');
        setFlash($sent ? 'success' : 'danger', $sent ? 'Test email sent successfully!' : 'Failed to send. Check your API key and sender email.');
        header('Location: mailjet.php'); exit;
    }

    $pdo->prepare("UPDATE settings SET mailjet_api_key=?, mailjet_secret=?, mailjet_from_email=?, mailjet_from_name=? WHERE id=1")
        ->execute([
            trim($_POST['mailjet_api_key']    ?? ''),
            trim($_POST['mailjet_secret']     ?? ''),
            trim($_POST['mailjet_from_email'] ?? ''),
            trim($_POST['mailjet_from_name']  ?? 'Asset Manager'),
        ]);
    setFlash('success', 'Mailjet settings saved.');
    header('Location: mailjet.php'); exit;
}
$s = getSettings($pdo);
$configured = !empty($s['mailjet_api_key']) && !empty($s['mailjet_from_email']);
?>

<div class="page-header">
  <h2><i class="bi bi-envelope-at me-2" style="color:var(--brand-primary)"></i>Mailjet Email Settings</h2>
  <p>Configure Mailjet API for OTP codes and system notifications.</p>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card mb-4">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-key"></i> API Credentials
        <?php if ($configured): ?>
        <span class="badge bg-success ms-auto">Configured</span>
        <?php else: ?>
        <span class="badge bg-secondary ms-auto">Not Configured</span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <div class="alert alert-info py-2 small mb-4">
          <i class="bi bi-info-circle me-1"></i>
          Get your API key from <strong>Mailjet → Account Settings → API Key Management</strong>.
          You need the <strong>API Key</strong> (username) and <strong>Secret Key</strong> (password).
        </div>
        <form method="POST" class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold small">API Key (Public)</label>
            <input type="text" name="mailjet_api_key" class="form-control font-monospace"
                   value="<?= e($s['mailjet_api_key'] ?? '') ?>" placeholder="abc123...">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold small">Secret Key</label>
            <input type="password" name="mailjet_secret" class="form-control font-monospace"
                   value="<?= e($s['mailjet_secret'] ?? '') ?>" placeholder="••••••••••••">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold small">From Email <small class="text-muted">(must be verified in Mailjet)</small></label>
            <input type="email" name="mailjet_from_email" class="form-control"
                   value="<?= e($s['mailjet_from_email'] ?? '') ?>" placeholder="noreply@yourcompany.com">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold small">From Name</label>
            <input type="text" name="mailjet_from_name" class="form-control"
                   value="<?= e($s['mailjet_from_name'] ?? 'Asset Manager') ?>">
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-primary">Save Mailjet Settings</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Test email -->
    <?php if ($configured): ?>
    <div class="card">
      <div class="card-header"><i class="bi bi-send me-2"></i>Send Test Email</div>
      <div class="card-body">
        <form method="POST" class="d-flex gap-2">
          <input type="hidden" name="test_email" value="1">
          <input type="email" name="test_to" class="form-control" placeholder="recipient@example.com"
                 value="<?= e($s['mailjet_from_email'] ?? '') ?>">
          <button type="submit" class="btn btn-outline-primary text-nowrap">
            <i class="bi bi-send me-1"></i>Send Test
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- OTP global settings -->
  <div class="col-lg-5">
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-shield-lock me-2"></i>OTP / 2FA Status</div>
      <div class="card-body">
        <?php
        $otpUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE otp_enabled=1 AND is_active=1")->fetchColumn();
        $totalUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
        ?>
        <div class="d-flex align-items-center gap-3 mb-3">
          <div style="font-size:36px;font-weight:800;color:var(--brand-primary);"><?= $otpUsers ?></div>
          <div>
            <div class="fw-semibold">of <?= $totalUsers ?> active users have OTP enabled</div>
            <div class="small text-muted">Users can enable OTP from their profile page.</div>
          </div>
        </div>
        <div class="progress" style="height:8px;">
          <div class="progress-bar" style="width:<?= $totalUsers > 0 ? round(($otpUsers/$totalUsers)*100) : 0 ?>%;background:var(--brand-primary);"></div>
        </div>
        <?php if (!$configured): ?>
        <div class="alert alert-warning mt-3 py-2 small">
          <i class="bi bi-exclamation-triangle me-1"></i>Configure Mailjet first to enable OTP sending.
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-envelope-check me-2"></i>What OTP Does</div>
      <ul class="list-group list-group-flush">
        <li class="list-group-item small py-2"><i class="bi bi-check-circle text-success me-2"></i>Sends 6-digit code to user's email</li>
        <li class="list-group-item small py-2"><i class="bi bi-check-circle text-success me-2"></i>Code expires in 10 minutes</li>
        <li class="list-group-item small py-2"><i class="bi bi-check-circle text-success me-2"></i>Paste support — auto-submits when code is pasted</li>
        <li class="list-group-item small py-2"><i class="bi bi-check-circle text-success me-2"></i>Each user controls their own 2FA from profile</li>
        <li class="list-group-item small py-2"><i class="bi bi-check-circle text-success me-2"></i>Styled email template with brand colors</li>
      </ul>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
