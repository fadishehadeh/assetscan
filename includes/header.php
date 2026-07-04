<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$settings   = getSettings($pdo);
$company    = $settings['company_name']  ?? 'Asset Manager';
$appName    = $settings['app_name']      ?? 'Asset Manager';
$logoPath   = $settings['logo_path']     ?? 'assets/img/logo.svg';
$primaryClr = $settings['primary_color'] ?? '#E84B37';
$sidebarClr = $settings['sidebar_color'] ?? '#1a1a2e';
$user       = currentUser();
$flash      = getFlash();
$currentUri = $_SERVER['REQUEST_URI'] ?? '';

// Darken primary by ~15% for hover states
function darkenHex(string $hex, float $factor = 0.82): string {
    $hex = ltrim($hex, '#');
    [$r, $g, $b] = [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
    return sprintf('#%02x%02x%02x', (int)($r*$factor), (int)($g*$factor), (int)($b*$factor));
}

function lightenHex(string $hex, float $factor = 1.15): string {
    $hex = ltrim($hex, '#');
    [$r, $g, $b] = [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
    return sprintf('#%02x%02x%02x', min(255,(int)($r*$factor)), min(255,(int)($g*$factor)), min(255,(int)($b*$factor)));
}

// Convert hex to rgba string
function hexRgba(string $hex, float $alpha): string {
    $hex = ltrim($hex, '#');
    [$r, $g, $b] = [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
    return "rgba({$r},{$g},{$b},{$alpha})";
}

$primaryDk  = darkenHex($primaryClr);
$primaryLt  = lightenHex($primaryClr);
$sidebarActive = hexRgba($primaryClr, 0.22);
$focusShadow   = hexRgba($primaryClr, 0.15);

function navActive(string $path): string {
    global $currentUri;
    return str_contains($currentUri, $path) ? 'active' : '';
}
// Buffer all output so header() redirects work even after this file is included
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? 'Dashboard') ?> — <?= e($appName) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
  <link rel="manifest" href="/assets/manifest.json">
  <!-- Dynamic brand variables from DB -->
  <style>
    :root {
      --brand-primary:    <?= e($primaryClr) ?>;
      --brand-primary-dk: <?= e($primaryDk) ?>;
      --brand-primary-lt: <?= e($primaryLt) ?>;
      --sidebar-bg:       <?= e($sidebarClr) ?>;
      --sidebar-active:   <?= $sidebarActive ?>;
      --topbar-bg:        <?= e($sidebarClr) ?>;
    }
    .form-control:focus, .form-select:focus {
      border-color: <?= e($primaryClr) ?>;
      box-shadow: 0 0 0 3px <?= $focusShadow ?>;
    }
  </style>
</head>
<body>

<!-- ── TOP NAV ──────────────────────────────────────────────────── -->
<header class="topbar">
  <button class="sidebar-toggle d-md-none" id="sidebarToggle" aria-label="Toggle sidebar">
    <i class="bi bi-list"></i>
  </button>
  <a class="topbar-brand" href="/modules/dashboard/index.php">
    <img src="/<?= e($logoPath) ?>" alt="<?= e($appName) ?>" class="topbar-logo">
  </a>
  <div class="topbar-divider"></div>
  <span class="topbar-app-name"><?= e($appName) ?></span>
  <div class="topbar-spacer"></div>

  <!-- Global Search -->
  <div class="topbar-search" id="topbarSearch">
    <form action="/search.php" method="GET" autocomplete="off" id="globalSearchForm">
      <div class="search-input-wrap">
        <i class="bi bi-search search-icon"></i>
        <input type="text" name="q" id="globalSearchInput" class="search-input" placeholder="Search assets, users…" value="<?= isset($_GET['q']) && basename($_SERVER['PHP_SELF'])==='search.php' ? e($_GET['q']) : '' ?>">
        <div class="search-dropdown" id="searchDropdown"></div>
      </div>
    </form>
  </div>

  <div class="topbar-user">
    <span class="badge <?= roleBadgeClass($user['role']) ?>"><?= roleLabel($user['role']) ?></span>
    <span class="topbar-username d-none d-sm-inline"><?= e($user['name']) ?></span>
    <a href="/modules/assets/scan.php" class="btn btn-sm btn-outline-secondary d-md-none ms-1 py-1 px-2" title="Scan Asset"><i class="bi bi-qr-code-scan"></i></a>
    <?php
    $curLang = $_SESSION['lang'] ?? ($settings['app_language'] ?? 'en');
    $otherLang = $curLang === 'ar' ? 'en' : 'ar';
    $otherLabel = $curLang === 'ar' ? 'EN' : 'ع';
    ?>
    <a href="/lang_switch.php?lang=<?= $otherLang ?>&back=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn btn-sm btn-outline-secondary ms-1 py-1 px-2" title="Switch language"><?= $otherLabel ?></a>
    <a href="/logout.php" class="btn btn-sm btn-outline-danger ms-1 py-1 px-2" title="Logout">
      <i class="bi bi-box-arrow-right"></i>
    </a>
  </div>
</header>

<!-- ── SIDEBAR OVERLAY (mobile) ─────────────────────────────────── -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── SIDEBAR ──────────────────────────────────────────────────── -->
<nav class="sidebar" id="sidebar">
  <ul class="nav flex-column">

    <li><a class="nav-link <?= navActive('/dashboard/') ?>" href="/modules/dashboard/index.php">
      <i class="bi bi-speedometer2"></i> Dashboard
    </a></li>

    <li><span class="nav-section-label">Assets</span></li>
    <li><a class="nav-link <?= navActive('/assets/index') ?>" href="/modules/assets/index.php">
      <i class="bi bi-boxes"></i> All Assets
    </a></li>
    <?php if (isAdmin() || isIT()): ?>
    <li><a class="nav-link <?= navActive('/assets/add') ?>" href="/modules/assets/add.php">
      <i class="bi bi-plus-circle"></i> Add Asset
    </a></li>
    <?php endif; ?>
    <li><a class="nav-link <?= navActive('/categories/') ?>" href="/modules/categories/index.php">
      <i class="bi bi-tags"></i> Categories
    </a></li>

    <li><span class="nav-section-label">Operations</span></li>
    <li><a class="nav-link <?= navActive('/maintenance/index') ?>" href="/modules/maintenance/index.php">
      <i class="bi bi-wrench"></i> Maintenance
    </a></li>
    <li><a class="nav-link <?= navActive('/maintenance/schedule') ?>" href="/modules/maintenance/schedule.php">
      <i class="bi bi-calendar-check"></i> Schedule
    </a></li>
    <li><a class="nav-link <?= navActive('/depreciation/') ?>" href="/modules/depreciation/index.php">
      <i class="bi bi-graph-down"></i> Depreciation
    </a></li>
    <li><a class="nav-link <?= navActive('/checkout/') ?>" href="/modules/checkout/index.php">
      <i class="bi bi-arrow-left-right"></i> Checkouts
    </a></li>
    <li><a class="nav-link d-md-none <?= navActive('/assets/scan') ?>" href="/modules/assets/scan.php">
      <i class="bi bi-qr-code-scan"></i> Scan Asset
    </a></li>
    <li><a class="nav-link <?= navActive('/transfers/') ?>" href="/modules/transfers/index.php">
      <i class="bi bi-send"></i> Transfers
    </a></li>
    <li><a class="nav-link <?= navActive('/disposal/') ?>" href="/modules/disposal/index.php">
      <i class="bi bi-trash3"></i> Disposal
    </a></li>

    <li><span class="nav-section-label">Reports</span></li>
    <li><a class="nav-link <?= navActive('/reports/index') ?>" href="/modules/reports/index.php">
      <i class="bi bi-file-earmark-bar-graph"></i> Reports
    </a></li>
    <li><a class="nav-link <?= navActive('/dashboard/executive') ?>" href="/modules/dashboard/executive.php">
      <i class="bi bi-graph-up-arrow"></i> Executive View
    </a></li>

    <?php if (isAdmin()): ?>
    <li><span class="nav-section-label">Administration</span></li>
    <li><a class="nav-link <?= navActive('/users/') ?>" href="/modules/users/index.php">
      <i class="bi bi-people"></i> Users
    </a></li>
    <li><a class="nav-link <?= navActive('/departments/') ?>" href="/modules/departments/index.php">
      <i class="bi bi-diagram-3"></i> Departments
    </a></li>
    <li><a class="nav-link <?= navActive('/locations/') ?>" href="/modules/locations/index.php">
      <i class="bi bi-geo-alt"></i> Locations
    </a></li>
    <li><a class="nav-link <?= navActive('/import/') ?>" href="/modules/import/index.php">
      <i class="bi bi-upload"></i> Bulk Import
    </a></li>
    <li><a class="nav-link <?= navActive('/custom_fields/') ?>" href="/modules/custom_fields/index.php">
      <i class="bi bi-sliders2"></i> Custom Fields
    </a></li>
    <?php endif; ?>

    <?php if (isSuperAdmin()): ?>
    <li><span class="nav-section-label">System</span></li>
    <li><a class="nav-link <?= (str_contains($currentUri, '/settings/') && !str_contains($currentUri, 'tab=')) || str_contains($currentUri, 'tab=general') ? 'active' : '' ?>" href="/modules/settings/index.php?tab=general">
      <i class="bi bi-sliders"></i> General
    </a></li>
    <li><a class="nav-link <?= str_contains($currentUri, 'tab=branding') ? 'active' : '' ?>" href="/modules/settings/index.php?tab=branding">
      <i class="bi bi-palette"></i> Branding
    </a></li>
    <li><a class="nav-link <?= str_contains($currentUri, 'tab=email') ? 'active' : '' ?>" href="/modules/settings/index.php?tab=email">
      <i class="bi bi-envelope-at"></i> Email & OTP
    </a></li>
    <li><a class="nav-link <?= str_contains($currentUri, 'tab=backup') ? 'active' : '' ?>" href="/modules/settings/index.php?tab=backup">
      <i class="bi bi-database-down"></i> DB Backup
    </a></li>
    <li><a class="nav-link <?= navActive('/audit/') ?>" href="/modules/audit/index.php">
      <i class="bi bi-shield-check"></i> Audit Log
    </a></li>
    <li><a class="nav-link <?= navActive('/settings/data_tools') ?>" href="/modules/settings/data_tools.php">
      <i class="bi bi-database-gear"></i> Data Tools
    </a></li>
    <?php endif; ?>
    <li><span class="nav-section-label">Account</span></li>
    <li><a class="nav-link <?= navActive('/profile/') ?>" href="/modules/profile/index.php">
      <i class="bi bi-person-circle"></i> My Profile
    </a></li>

  </ul>
</nav>

<!-- ── MAIN ─────────────────────────────────────────────────────── -->
<main class="page-wrapper" id="pageWrapper">

<?php if ($flash): ?>
<div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
  <?= $flash['msg'] ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
