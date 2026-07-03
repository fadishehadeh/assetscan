<?php
$pageTitle = 'Branding';
require_once __DIR__ . '/../../includes/header.php';
requireRole('super_admin');

$s = getSettings($pdo);

// Handle logo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appName    = trim($_POST['app_name']      ?? $s['app_name']);
    $company    = trim($_POST['company_name']  ?? $s['company_name']);
    $primary    = trim($_POST['primary_color'] ?? $s['primary_color']);
    $sidebar    = trim($_POST['sidebar_color'] ?? $s['sidebar_color']);
    $logoPath   = $s['logo_path'];

    // Validate hex colors
    $validPrimary = preg_match('/^#[0-9A-Fa-f]{6}$/', $primary) ? $primary : $s['primary_color'];
    $validSidebar = preg_match('/^#[0-9A-Fa-f]{6}$/', $sidebar) ? $sidebar : $s['sidebar_color'];

    // Handle logo upload
    if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $ext     = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $allowed = ['png','jpg','jpeg','gif','svg','webp'];

        if (!in_array($ext, $allowed, true)) {
            setFlash('danger', 'Invalid file type. Use PNG, JPG, SVG, or WEBP.');
            header('Location: branding.php'); exit;
        }
        if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
            setFlash('danger', 'Logo file must be under 2MB.');
            header('Location: branding.php'); exit;
        }

        $uploadDir = __DIR__ . '/../../uploads/branding/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $filename  = 'logo_' . time() . '.' . $ext;
        $destPath  = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['logo']['tmp_name'], $destPath)) {
            // Remove old custom logo (not the default)
            if ($s['logo_path'] && $s['logo_path'] !== 'assets/img/logo.svg'
                && file_exists(__DIR__ . '/../../' . $s['logo_path'])) {
                @unlink(__DIR__ . '/../../' . $s['logo_path']);
            }
            $logoPath = 'uploads/branding/' . $filename;
        }
    }

    // Handle reset to default logo
    if (isset($_POST['reset_logo'])) {
        if ($s['logo_path'] && $s['logo_path'] !== 'assets/img/logo.svg'
            && file_exists(__DIR__ . '/../../' . $s['logo_path'])) {
            @unlink(__DIR__ . '/../../' . $s['logo_path']);
        }
        $logoPath = 'assets/img/logo.svg';
    }

    $pdo->prepare("UPDATE settings SET app_name=?, company_name=?, primary_color=?, sidebar_color=?, logo_path=? WHERE id=1")
        ->execute([$appName, $company, $validPrimary, $validSidebar, $logoPath]);

    auditLog($pdo, 'branding_update', 'settings', 1, $s);
    setFlash('success', 'Branding updated. Refresh to see the new look.');
    header('Location: branding.php'); exit;
}

$s = getSettings($pdo); // Reload after save
?>

<div class="page-header">
  <h2><i class="bi bi-palette me-2" style="color:var(--brand-primary)"></i>Branding</h2>
  <p>Customize the app's logo, name, and colors. Changes take effect immediately system-wide.</p>
</div>

<form method="POST" enctype="multipart/form-data">
<div class="row g-4">

  <!-- LEFT: Controls -->
  <div class="col-lg-7">

    <!-- Identity -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-type me-2"></i>Identity</div>
      <div class="card-body row g-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold small">App Name <small class="text-muted">(shown in browser tab & topbar)</small></label>
          <input type="text" name="app_name" class="form-control" value="<?= e($s['app_name'] ?? 'Asset Manager') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold small">Company Name <small class="text-muted">(shown in footer & reports)</small></label>
          <input type="text" name="company_name" class="form-control" value="<?= e($s['company_name'] ?? '') ?>">
        </div>
      </div>
    </div>

    <!-- Logo -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-image me-2"></i>Logo</div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-4 mb-4">
          <div style="background:#1a1a2e;padding:12px 20px;border-radius:10px;">
            <img id="logoPreview"
                 src="/asset-manager/<?= e($s['logo_path'] ?? 'assets/img/logo.svg') ?>"
                 alt="Current Logo"
                 style="height:40px;width:auto;max-width:200px;object-fit:contain;">
          </div>
          <div>
            <div class="fw-semibold small mb-1">Current Logo</div>
            <div class="text-muted" style="font-size:12px;"><?= e(basename($s['logo_path'] ?? 'logo.svg')) ?></div>
          </div>
        </div>

        <label class="form-label fw-semibold small">Upload New Logo</label>
        <input type="file" name="logo" id="logoInput" class="form-control mb-1" accept=".png,.jpg,.jpeg,.gif,.svg,.webp"
               onchange="previewLogo(this)">
        <div class="text-muted" style="font-size:12px;">PNG, SVG, or JPG. Max 2MB. Recommended height: 40–60px.</div>

        <?php if (($s['logo_path'] ?? '') !== 'assets/img/logo.svg'): ?>
        <div class="mt-3">
          <button type="submit" name="reset_logo" value="1" class="btn btn-outline-secondary btn-sm"
                  onclick="return confirm('Reset to default G2 logo?')">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset to Default Logo
          </button>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Colors -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-palette2 me-2"></i>Colors</div>
      <div class="card-body row g-4">

        <div class="col-md-6">
          <label class="form-label fw-semibold small">Primary / Accent Color</label>
          <p class="text-muted mb-2" style="font-size:12px;">Used for buttons, active menu items, links, and highlights.</p>
          <div class="d-flex align-items-center gap-3">
            <input type="color" name="primary_color" id="primaryPicker"
                   value="<?= e($s['primary_color'] ?? '#E84B37') ?>"
                   class="form-control form-control-color"
                   style="width:52px;height:42px;padding:2px;border-radius:8px;cursor:pointer;"
                   oninput="syncColor(this,'primaryHex')">
            <input type="text" id="primaryHex" value="<?= e($s['primary_color'] ?? '#E84B37') ?>"
                   class="form-control" style="max-width:110px;font-family:monospace;font-size:14px;"
                   oninput="syncHex(this,'primaryPicker','primary_color')"
                   maxlength="7" name="primary_color_text">
          </div>
          <!-- Presets -->
          <div class="mt-3">
            <div class="small text-muted mb-2 fw-semibold">Quick Presets</div>
            <div class="d-flex flex-wrap gap-2" id="primaryPresets">
              <?php
              $presets = [
                '#E84B37'=>'G2 Red','#1d4ed8'=>'Blue','#059669'=>'Green',
                '#7c3aed'=>'Purple','#d97706'=>'Amber','#0891b2'=>'Cyan',
                '#e11d48'=>'Rose','#374151'=>'Slate'
              ];
              foreach ($presets as $hex => $label):
              ?>
              <button type="button" class="btn btn-sm preset-btn"
                      style="background:<?= $hex ?>;border:2px solid <?= ($s['primary_color'] ?? '#E84B37') === $hex ? '#000' : 'transparent' ?>;width:32px;height:32px;border-radius:8px;padding:0;"
                      title="<?= $label ?>"
                      onclick="setColor('<?= $hex ?>','primaryPicker','primaryHex','primary_color')"></button>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="col-md-6">
          <label class="form-label fw-semibold small">Sidebar / Topbar Color</label>
          <p class="text-muted mb-2" style="font-size:12px;">Background color of the left sidebar and top navigation bar.</p>
          <div class="d-flex align-items-center gap-3">
            <input type="color" name="sidebar_color" id="sidebarPicker"
                   value="<?= e($s['sidebar_color'] ?? '#1a1a2e') ?>"
                   class="form-control form-control-color"
                   style="width:52px;height:42px;padding:2px;border-radius:8px;cursor:pointer;"
                   oninput="syncColor(this,'sidebarHex')">
            <input type="text" id="sidebarHex" value="<?= e($s['sidebar_color'] ?? '#1a1a2e') ?>"
                   class="form-control" style="max-width:110px;font-family:monospace;font-size:14px;"
                   oninput="syncHex(this,'sidebarPicker','sidebar_color')"
                   maxlength="7" name="sidebar_color_text">
          </div>
          <div class="mt-3">
            <div class="small text-muted mb-2 fw-semibold">Quick Presets</div>
            <div class="d-flex flex-wrap gap-2">
              <?php
              $sidebarPresets = [
                '#1a1a2e'=>'Navy Dark','#111827'=>'Gray 900','#0f172a'=>'Slate 950',
                '#1c1917'=>'Stone','#14532d'=>'Forest','#1e1b4b'=>'Indigo Dark',
                '#fff'=>'White','#f1f5f9'=>'Light'
              ];
              foreach ($sidebarPresets as $hex => $label):
              ?>
              <button type="button" class="btn btn-sm preset-btn"
                      style="background:<?= $hex ?>;border:2px solid <?= ($s['sidebar_color'] ?? '#1a1a2e') === $hex ? '#000' : '#e2e8f0' ?>;width:32px;height:32px;border-radius:8px;padding:0;"
                      title="<?= $label ?>"
                      onclick="setColor('<?= $hex ?>','sidebarPicker','sidebarHex','sidebar_color')"></button>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>

  <!-- RIGHT: Live preview -->
  <div class="col-lg-5">
    <div class="card sticky-top" style="top:76px;">
      <div class="card-header"><i class="bi bi-eye me-2"></i>Live Preview</div>
      <div class="card-body p-0 overflow-hidden" style="border-radius:0 0 12px 12px;">

        <!-- Mock topbar -->
        <div id="previewTopbar" style="background:<?= e($s['sidebar_color'] ?? '#1a1a2e') ?>;padding:10px 14px;display:flex;align-items:center;gap:10px;border-bottom:3px solid <?= e($s['primary_color'] ?? '#E84B37') ?>;">
          <img id="previewLogo" src="/asset-manager/<?= e($s['logo_path'] ?? 'assets/img/logo.svg') ?>"
               style="height:28px;width:auto;object-fit:contain;max-width:140px;">
          <span style="color:rgba(255,255,255,.5);font-size:11px;margin-left:6px;" id="previewAppName"><?= e($s['app_name'] ?? 'Asset Manager') ?></span>
          <span style="flex:1"></span>
          <span id="previewBadge" style="background:<?= e($s['primary_color'] ?? '#E84B37') ?>;color:#fff;font-size:10px;padding:3px 8px;border-radius:5px;font-weight:700;">Super Admin</span>
        </div>

        <!-- Mock sidebar + content -->
        <div style="display:flex;min-height:300px;">
          <!-- sidebar -->
          <div id="previewSidebar" style="background:<?= e($s['sidebar_color'] ?? '#1a1a2e') ?>;width:130px;padding:10px 8px;flex-shrink:0;">
            <?php
            $navItems = ['Dashboard','All Assets','Add Asset','Maintenance','Reports','Settings'];
            $icons = ['bi-speedometer2','bi-boxes','bi-plus-circle','bi-wrench','bi-file-earmark-bar-graph','bi-gear'];
            foreach ($navItems as $i => $item):
              $isActive = $item === 'All Assets';
            ?>
            <div class="preview-nav-item <?= $isActive ? 'preview-nav-active' : '' ?>"
                 style="display:flex;align-items:center;gap:6px;padding:6px 8px;border-radius:6px;margin-bottom:2px;
                        <?= $isActive ? 'background:rgba(232,75,55,.22);border-left:3px solid #E84B37;padding-left:5px;' : '' ?>">
              <i class="bi <?= $icons[$i] ?>" style="color:<?= $isActive ? '#fff' : 'rgba(255,255,255,.55)' ?>;font-size:11px;"></i>
              <span style="color:<?= $isActive ? '#fff' : 'rgba(255,255,255,.6)' ?>;font-size:10.5px;"><?= $item ?></span>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- content area -->
          <div style="flex:1;background:#f0f2f5;padding:14px;">
            <div style="background:#fff;border-radius:8px;padding:12px;margin-bottom:10px;box-shadow:0 1px 4px rgba(0,0,0,.07);">
              <div style="font-size:11px;font-weight:700;color:#0f172a;margin-bottom:8px;">Asset Overview</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                <div id="previewCard1" style="background:<?= e($s['primary_color'] ?? '#E84B37') ?>;border-radius:7px;padding:8px 10px;color:#fff;">
                  <div style="font-size:15px;font-weight:800;">142</div>
                  <div style="font-size:9px;opacity:.85;">Total Assets</div>
                </div>
                <div style="background:#10b981;border-radius:7px;padding:8px 10px;color:#fff;">
                  <div style="font-size:15px;font-weight:800;">118</div>
                  <div style="font-size:9px;opacity:.85;">Active</div>
                </div>
              </div>
            </div>
            <div style="background:#fff;border-radius:8px;padding:10px 12px;box-shadow:0 1px 4px rgba(0,0,0,.07);">
              <div style="font-size:10px;color:#64748b;margin-bottom:6px;">Recent Assets</div>
              <?php foreach (['MacBook Pro 14"','Dell Monitor','Office Chair'] as $item): ?>
              <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #f1f5f9;font-size:10px;color:#374151;">
                <span><?= $item ?></span>
                <span id="previewStatusBadge" style="background:<?= e($s['primary_color'] ?? '#E84B37') ?>;color:#fff;padding:1px 6px;border-radius:4px;font-size:9px;font-weight:600;">Active</span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Login preview -->
        <div style="border-top:1px solid #f1f5f9;padding:10px 14px;background:#f8fafc;">
          <div style="font-size:10px;color:#94a3b8;text-align:center;margin-bottom:8px;">Login Screen Preview</div>
          <div style="border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);max-width:200px;margin:0 auto;">
            <div id="previewLoginTop" style="background:<?= e($s['sidebar_color'] ?? '#1a1a2e') ?>;padding:10px;text-align:center;border-bottom:2px solid <?= e($s['primary_color'] ?? '#E84B37') ?>;">
              <img id="previewLoginLogo" src="/asset-manager/<?= e($s['logo_path'] ?? 'assets/img/logo.svg') ?>"
                   style="height:22px;width:auto;object-fit:contain;max-width:130px;">
            </div>
            <div style="background:#fff;padding:10px;">
              <div style="height:6px;background:#f1f5f9;border-radius:3px;margin-bottom:6px;"></div>
              <div style="height:6px;background:#f1f5f9;border-radius:3px;margin-bottom:8px;"></div>
              <div id="previewLoginBtn" style="background:<?= e($s['primary_color'] ?? '#E84B37') ?>;color:#fff;text-align:center;padding:5px;border-radius:5px;font-size:10px;font-weight:700;">Sign In</div>
            </div>
          </div>
        </div>

      </div><!-- card-body -->
    </div><!-- card -->
  </div><!-- col -->

</div><!-- row -->

<!-- Save bar -->
<div class="d-flex align-items-center gap-3 mt-4 pt-3 border-top">
  <button type="submit" class="btn btn-primary px-4">
    <i class="bi bi-save me-2"></i>Save Branding
  </button>
  <a href="index.php" class="btn btn-outline-secondary">Other Settings</a>
  <span class="text-muted small">Changes apply to all users immediately.</span>
</div>

</form>

<script>
// ── Sync color picker ↔ hex input ───────────────────────────────────────
function syncColor(picker, hexId) {
  document.getElementById(hexId).value = picker.value;
  // also sync hidden input
  if (picker.name === 'primary_color') picker.form['primary_color_text'].value = picker.value;
  if (picker.name === 'sidebar_color') picker.form['sidebar_color_text'].value = picker.value;
  updatePreview();
}

function syncHex(input, pickerId, hiddenName) {
  const v = input.value.trim();
  if (/^#[0-9A-Fa-f]{6}$/.test(v)) {
    document.getElementById(pickerId).value = v;
    updatePreview();
  }
}

function setColor(hex, pickerId, hexId, hiddenName) {
  document.getElementById(pickerId).value = hex;
  document.getElementById(hexId).value = hex;
  // update hidden input with same name
  const picker = document.getElementById(pickerId);
  if (picker.name) picker.value = hex;
  updatePreview();
}

// ── Logo preview ─────────────────────────────────────────────────────────
function previewLogo(input) {
  if (!input.files[0]) return;
  const url = URL.createObjectURL(input.files[0]);
  document.getElementById('logoPreview').src = url;
  document.getElementById('previewLogo').src = url;
  document.getElementById('previewLoginLogo').src = url;
}

// ── Live preview updater ──────────────────────────────────────────────────
function updatePreview() {
  const primary = document.getElementById('primaryPicker').value;
  const sidebar = document.getElementById('sidebarPicker').value;
  const appName = document.querySelector('[name="app_name"]').value;

  // topbar
  const tb = document.getElementById('previewTopbar');
  tb.style.background = sidebar;
  tb.style.borderBottomColor = primary;

  // sidebar
  document.getElementById('previewSidebar').style.background = sidebar;

  // badge
  document.getElementById('previewBadge').style.background = primary;

  // stat card
  document.getElementById('previewCard1').style.background = primary;

  // status badges
  document.querySelectorAll('#previewStatusBadge').forEach(el => el.style.background = primary);

  // login
  document.getElementById('previewLoginTop').style.background = sidebar;
  document.getElementById('previewLoginTop').style.borderBottomColor = primary;
  document.getElementById('previewLoginBtn').style.background = primary;

  // nav active item border
  document.querySelectorAll('.preview-nav-active').forEach(el => {
    el.style.borderLeftColor = primary;
    el.style.background = hexToRgba(primary, 0.22);
  });

  // app name
  document.getElementById('previewAppName').textContent = appName || 'Asset Manager';

  // live-update the page CSS vars
  document.documentElement.style.setProperty('--brand-primary', primary);
  document.documentElement.style.setProperty('--sidebar-bg', sidebar);
  document.documentElement.style.setProperty('--topbar-bg', sidebar);
}

function hexToRgba(hex, alpha) {
  const r = parseInt(hex.slice(1,3),16);
  const g = parseInt(hex.slice(3,5),16);
  const b = parseInt(hex.slice(5,7),16);
  return `rgba(${r},${g},${b},${alpha})`;
}

// Fix: ensure hidden inputs sync before submit
document.querySelector('form').addEventListener('submit', function() {
  this['primary_color'].value = document.getElementById('primaryPicker').value;
  this['sidebar_color'].value = document.getElementById('sidebarPicker').value;
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
