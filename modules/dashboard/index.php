<?php
// ── Widget preference loading ────────────────────────────────────────────────
$_defaultWidgets = [
    'stat_total', 'stat_active', 'stat_maintenance', 'stat_value',
    'alert_warranty', 'alert_eol',
    'recent_assets', 'by_category',
    'chart_status', 'chart_category', 'chart_monthly',
];
$_allWidgetLabels = [
    'stat_total'        => 'Total Assets (stat card)',
    'stat_active'       => 'Active Assets (stat card)',
    'stat_maintenance'  => 'In Maintenance (stat card)',
    'stat_value'        => 'Total Asset Value (stat card)',
    'alert_warranty'    => 'Warranty Expiry Alert Banner',
    'alert_eol'         => 'End-of-Life Alert Banner',
    'recent_assets'     => 'Recent Assets Table',
    'by_category'       => 'Assets by Category Panel',
    'chart_status'      => 'Assets by Status Chart (Doughnut)',
    'chart_category'    => 'Assets by Category Chart (Bar)',
    'chart_monthly'     => 'Monthly Additions Chart (Line)',
];
$_userId = (int)($user['id'] ?? 0);
$_prefRow = false;
if ($_userId) {
    $_prefStmt = $pdo->prepare("SELECT widgets FROM user_dashboard_prefs WHERE user_id = ?");
    $_prefStmt->execute([$_userId]);
    $_prefRow = $_prefStmt->fetchColumn();
}
$activeWidgets = $_prefRow ? (json_decode($_prefRow, true) ?: $_defaultWidgets) : $_defaultWidgets;

$pageTitle = 'Dashboard';
require_once __DIR__ . '/../../includes/header.php';

// Stats
$totalAssets    = $pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
$activeAssets   = $pdo->query("SELECT COUNT(*) FROM assets WHERE status='active'")->fetchColumn();
$inMaintenance  = $pdo->query("SELECT COUNT(*) FROM assets WHERE status='under_maintenance'")->fetchColumn();
$disposed       = $pdo->query("SELECT COUNT(*) FROM assets WHERE status='disposed'")->fetchColumn();
$totalCost      = $pdo->query("SELECT COALESCE(SUM(purchase_cost),0) FROM assets")->fetchColumn();
$totalUsers     = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();

// Warranty expiring in 30 days
$warrantyAlert = $pdo->query(
    "SELECT COUNT(*) FROM assets WHERE warranty_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
)->fetchColumn();

// End-of-life alerts (useful life exceeded)
$eolAlert = $pdo->query(
    "SELECT COUNT(*) FROM assets WHERE status='active' AND purchase_date IS NOT NULL
     AND TIMESTAMPDIFF(YEAR, purchase_date, CURDATE()) >= useful_life_years"
)->fetchColumn();

// Recent assets
$recentAssets = $pdo->query(
    "SELECT a.*, c.name AS cat_name, u.name AS assigned_name
     FROM assets a
     LEFT JOIN categories c ON a.category_id = c.id
     LEFT JOIN users u ON a.assigned_to = u.id
     ORDER BY a.created_at DESC LIMIT 8"
)->fetchAll();

// Assets by category
$byCategory = $pdo->query(
    "SELECT c.name, COUNT(a.id) AS cnt
     FROM categories c
     LEFT JOIN assets a ON a.category_id = c.id
     GROUP BY c.id, c.name
     HAVING cnt > 0
     ORDER BY cnt DESC LIMIT 8"
)->fetchAll();

// Assets by status for doughnut chart
$byStatus = $pdo->query(
    "SELECT status, COUNT(*) AS cnt FROM assets GROUP BY status"
)->fetchAll();

// Monthly additions (last 6 months)
$monthly = $pdo->query(
    "SELECT DATE_FORMAT(created_at,'%b %Y') AS month, COUNT(*) AS cnt
     FROM assets
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY DATE_FORMAT(created_at,'%Y-%m')
     ORDER BY MIN(created_at)"
)->fetchAll();

$settings = getSettings($pdo);
$currency = $settings['currency'] ?? 'USD';
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
  <div>
    <h2><i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard</h2>
    <p>Welcome back, <?= e($user['name']) ?>. Here's your asset overview.</p>
  </div>
  <div class="d-flex align-items-center gap-2">
    <span class="text-muted small"><?= date('l, d F Y') ?></span>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="offcanvas" data-bs-target="#dashboardCustomizer" title="Customize Dashboard">
      <i class="bi bi-gear me-1"></i>Customize
    </button>
  </div>
</div>

<!-- STAT CARDS -->
<div class="row g-3 mb-4">
  <?php if (in_array('stat_total', $activeWidgets)): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8)">
      <div class="stat-number"><?= number_format($totalAssets) ?></div>
      <div class="stat-label"><i class="bi bi-boxes me-1"></i>Total Assets</div>
    </div>
  </div>
  <?php endif; ?>
  <?php if (in_array('stat_active', $activeWidgets)): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card" style="background:linear-gradient(135deg,#10b981,#059669)">
      <div class="stat-number"><?= number_format($activeAssets) ?></div>
      <div class="stat-label"><i class="bi bi-check-circle me-1"></i>Active</div>
    </div>
  </div>
  <?php endif; ?>
  <?php if (in_array('stat_maintenance', $activeWidgets)): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card" style="background:linear-gradient(135deg,#f59e0b,#d97706)">
      <div class="stat-number"><?= number_format($inMaintenance) ?></div>
      <div class="stat-label"><i class="bi bi-wrench me-1"></i>In Maintenance</div>
    </div>
  </div>
  <?php endif; ?>
  <?php if (in_array('stat_value', $activeWidgets)): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9)">
      <div class="stat-number"><?= $currency ?> <?= number_format($totalCost, 0) ?></div>
      <div class="stat-label"><i class="bi bi-cash-stack me-1"></i>Total Asset Value</div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ALERTS ROW -->
<?php
$_showWarranty = in_array('alert_warranty', $activeWidgets) && $warrantyAlert > 0;
$_showEol      = in_array('alert_eol', $activeWidgets) && $eolAlert > 0;
?>
<?php if ($_showWarranty || $_showEol): ?>
<div class="row g-3 mb-4">
  <?php if ($_showWarranty): ?>
  <div class="col-md-6">
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-0">
      <i class="bi bi-shield-exclamation fs-5"></i>
      <div><strong><?= $warrantyAlert ?></strong> asset<?= $warrantyAlert > 1 ? 's' : '' ?> with warranty expiring within 30 days.
        <a href="/asset-manager/modules/reports/warranty.php" class="alert-link">View</a>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($_showEol): ?>
  <div class="col-md-6">
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-0">
      <i class="bi bi-exclamation-triangle fs-5"></i>
      <div><strong><?= $eolAlert ?></strong> asset<?= $eolAlert > 1 ? 's' : '' ?> past end-of-life.
        <a href="/asset-manager/modules/reports/eol.php" class="alert-link">View</a>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- CONTENT ROW -->
<div class="row g-4">
  <!-- Recent Assets -->
  <?php if (in_array('recent_assets', $activeWidgets)): ?>
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center py-3">
        <span class="fw-semibold"><i class="bi bi-clock-history me-2"></i>Recent Assets</span>
        <a href="/asset-manager/modules/assets/index.php" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Tag</th>
              <th>Name</th>
              <th>Category</th>
              <th>Status</th>
              <th>Assigned To</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentAssets as $a): ?>
            <tr>
              <td><code class="text-primary small"><?= e($a['asset_tag']) ?></code></td>
              <td><?= e($a['name']) ?></td>
              <td><span class="badge bg-light text-dark"><?= e($a['cat_name'] ?? '—') ?></span></td>
              <td><?= statusBadge($a['status']) ?></td>
              <td><?= e($a['assigned_name'] ?? '—') ?></td>
              <td>
                <a href="/asset-manager/modules/assets/view.php?id=<?= $a['id'] ?>" class="btn btn-xs btn-outline-secondary btn-sm py-0 px-2">
                  <i class="bi bi-eye"></i>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentAssets)): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No assets added yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Side panel -->
  <div class="col-lg-4">
    <!-- By Category -->
    <?php if (in_array('by_category', $activeWidgets)): ?>
    <div class="card mb-4">
      <div class="card-header py-3">
        <span class="fw-semibold"><i class="bi bi-tags me-2"></i>Assets by Category</span>
      </div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          <?php foreach ($byCategory as $row): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center py-2">
            <span class="small"><?= e($row['name']) ?></span>
            <span class="badge bg-primary rounded-pill"><?= $row['cnt'] ?></span>
          </li>
          <?php endforeach; ?>
          <?php if (empty($byCategory)): ?>
          <li class="list-group-item text-muted small py-3 text-center">No data yet.</li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>

    <!-- Quick Links -->
    <div class="card">
      <div class="card-header py-3">
        <span class="fw-semibold"><i class="bi bi-lightning me-2"></i>Quick Actions</span>
      </div>
      <div class="card-body d-grid gap-2">
        <?php if (isAdmin() || isIT()): ?>
        <a href="/asset-manager/modules/assets/add.php" class="btn btn-primary btn-sm">
          <i class="bi bi-plus-circle me-1"></i> Add New Asset
        </a>
        <?php endif; ?>
        <a href="/asset-manager/modules/reports/index.php" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-file-earmark-bar-graph me-1"></i> Generate Report
        </a>
        <a href="/asset-manager/modules/assets/index.php?status=under_maintenance" class="btn btn-outline-warning btn-sm">
          <i class="bi bi-wrench me-1"></i> View Maintenance Queue
        </a>
        <?php if (isAdmin()): ?>
        <a href="/asset-manager/modules/depreciation/run.php" class="btn btn-outline-info btn-sm">
          <i class="bi bi-graph-down me-1"></i> Run Depreciation Calc
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- CHARTS ROW -->
<?php
$_showChartStatus   = in_array('chart_status',   $activeWidgets);
$_showChartCategory = in_array('chart_category', $activeWidgets);
$_showChartMonthly  = in_array('chart_monthly',  $activeWidgets);
?>
<?php if ($_showChartStatus || $_showChartCategory || $_showChartMonthly): ?>
<div class="row g-4 mt-2">
  <?php if ($_showChartStatus): ?>
  <!-- Doughnut: by status -->
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-header fw-semibold"><i class="bi bi-pie-chart me-2"></i>Assets by Status</div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <canvas id="statusChart" style="max-height:220px;"></canvas>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($_showChartCategory): ?>
  <!-- Bar: by category -->
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header fw-semibold"><i class="bi bi-bar-chart me-2"></i>Assets by Category</div>
      <div class="card-body">
        <canvas id="categoryChart" style="max-height:220px;"></canvas>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($_showChartMonthly): ?>
  <!-- Line: monthly additions -->
  <div class="col-md-3">
    <div class="card h-100">
      <div class="card-header fw-semibold"><i class="bi bi-graph-up me-2"></i>Monthly Additions</div>
      <div class="card-body">
        <canvas id="monthlyChart" style="max-height:220px;"></canvas>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Dashboard Customizer Offcanvas ───────────────────────────────────── -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="dashboardCustomizer" style="width:320px;">
  <div class="offcanvas-header border-bottom">
    <h5 class="offcanvas-title"><i class="bi bi-gear me-2"></i>Customize Dashboard</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body">
    <p class="text-muted small mb-3">Select which widgets to show on your dashboard.</p>
    <div class="d-grid gap-2">
      <?php foreach ($_allWidgetLabels as $key => $label): ?>
      <div class="form-check">
        <input class="form-check-input widget-toggle" type="checkbox"
               id="wt_<?= $key ?>" value="<?= $key ?>"
               <?= in_array($key, $activeWidgets) ? 'checked' : '' ?>>
        <label class="form-check-label small" for="wt_<?= $key ?>">
          <?= htmlspecialchars($label) ?>
        </label>
      </div>
      <?php endforeach; ?>
    </div>
    <hr>
    <div class="d-grid gap-2">
      <button class="btn btn-primary btn-sm" onclick="saveDashboardWidgets()">
        <i class="bi bi-floppy me-1"></i>Save Layout
      </button>
      <button class="btn btn-outline-secondary btn-sm" onclick="resetDashboardWidgets()">
        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset to Default
      </button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
const _defaultWidgetKeys = <?= json_encode(array_keys($_allWidgetLabels)) ?>;

function saveDashboardWidgets() {
  const checked = [...document.querySelectorAll('.widget-toggle:checked')].map(el => el.value);
  fetch('/asset-manager/modules/dashboard/widgets.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=save&widgets=' + encodeURIComponent(JSON.stringify(checked))
  }).then(() => location.reload());
}

function resetDashboardWidgets() {
  document.querySelectorAll('.widget-toggle').forEach(cb => {
    cb.checked = _defaultWidgetKeys.includes(cb.value);
  });
  saveDashboardWidgets();
}
</script>
<script>
const brandColor = getComputedStyle(document.documentElement).getPropertyValue('--brand-primary').trim() || '#E84B37';

// ── Status doughnut ──────────────────────────────────────────────────
if (document.getElementById('statusChart')) {
  const statusData = <?= json_encode(array_column($byStatus, 'cnt')) ?>;
  const statusLabels = <?= json_encode(array_map(fn($r) => ucwords(str_replace('_',' ',$r['status'])), $byStatus)) ?>;
  new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
      labels: statusLabels,
      datasets: [{ data: statusData, backgroundColor: [brandColor,'#f59e0b','#ef4444','#6b7280'], borderWidth:2 }]
    },
    options: { plugins:{ legend:{ position:'bottom', labels:{ font:{size:11} } } }, cutout:'65%' }
  });
}

// ── Category bar ─────────────────────────────────────────────────────
if (document.getElementById('categoryChart')) {
  const catLabels = <?= json_encode(array_column($byCategory, 'name')) ?>;
  const catData   = <?= json_encode(array_column($byCategory, 'cnt')) ?>;
  new Chart(document.getElementById('categoryChart'), {
    type: 'bar',
    data: {
      labels: catLabels,
      datasets: [{ label:'Assets', data: catData,
        backgroundColor: brandColor + 'cc', borderColor: brandColor, borderWidth:1, borderRadius:5 }]
    },
    options: {
      plugins:{ legend:{ display:false } },
      scales:{ y:{ beginAtZero:true, ticks:{ precision:0, font:{size:10} } }, x:{ ticks:{ font:{size:10} } } }
    }
  });
}

// ── Monthly line ─────────────────────────────────────────────────────
if (document.getElementById('monthlyChart')) {
  const monthLabels = <?= json_encode(array_column($monthly, 'month')) ?>;
  const monthData   = <?= json_encode(array_column($monthly, 'cnt')) ?>;
  new Chart(document.getElementById('monthlyChart'), {
    type: 'line',
    data: {
      labels: monthLabels,
      datasets: [{ label:'Added', data: monthData, borderColor: brandColor,
        backgroundColor: brandColor+'22', fill:true, tension:.4, pointRadius:4 }]
    },
    options: {
      plugins:{ legend:{ display:false } },
      scales:{ y:{ beginAtZero:true, ticks:{ precision:0, font:{size:10} } }, x:{ ticks:{ font:{size:10} } } }
    }
  });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
