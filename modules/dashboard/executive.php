<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$settings = getSettings($pdo);
$currency = $settings['currency'] ?? 'USD';
$primary  = $settings['primary_color'] ?? '#E84B37';

// ── KPI Queries ────────────────────────────────────────────────────────────
$totalAssets           = (int)$pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
$totalActiveAssets     = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='active'")->fetchColumn();
$totalCost             = (float)$pdo->query("SELECT COALESCE(SUM(purchase_cost),0) FROM assets")->fetchColumn();
$maintenanceCostYTD    = (float)$pdo->query("SELECT COALESCE(SUM(cost),0) FROM maintenance WHERE YEAR(date)=YEAR(CURDATE()) AND cost > 0")->fetchColumn();
$assetsUnderMaintenance= (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='under_maintenance'")->fetchColumn();
$assetsDisposed        = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='disposed'")->fetchColumn();
$pendingTransfers      = (int)$pdo->query("SELECT COUNT(*) FROM asset_transfers WHERE status='pending'")->fetchColumn();
$pendingDisposals      = (int)$pdo->query("SELECT COUNT(*) FROM disposal_requests WHERE status='pending'")->fetchColumn();
$totalUsers            = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
$newAssetsThisYear     = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
$pendingActions        = $pendingTransfers + $pendingDisposals;

// ── Total Book Value (loop, cap at 500) ────────────────────────────────────
$bvAssets = $pdo->query(
    "SELECT purchase_cost, salvage_value, useful_life_years, depreciation_method, purchase_date
     FROM assets WHERE purchase_date IS NOT NULL AND purchase_cost > 0 LIMIT 500"
)->fetchAll();
$totalBookValue = 0.0;
foreach ($bvAssets as $bvA) {
    $totalBookValue += currentBookValue(
        (float)$bvA['purchase_cost'],
        (float)($bvA['salvage_value'] ?? 0),
        (int)($bvA['useful_life_years'] ?? 5),
        $bvA['depreciation_method'] ?? 'straight_line',
        $bvA['purchase_date']
    );
}

// ── Trend data: last 12 months asset additions ─────────────────────────────
$trendData = $pdo->query(
    "SELECT DATE_FORMAT(created_at,'%Y-%m') as month, COUNT(*) as cnt
     FROM assets WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
     GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY month"
)->fetchAll();
$trendLabels = array_column($trendData, 'month');
$trendCounts = array_map('intval', array_column($trendData, 'cnt'));

// ── Department breakdown ───────────────────────────────────────────────────
$deptBreakdown = $pdo->query(
    "SELECT d.name, COUNT(a.id) as asset_count, COALESCE(SUM(a.purchase_cost),0) as total_cost
     FROM departments d LEFT JOIN assets a ON a.department_id=d.id
     GROUP BY d.id,d.name ORDER BY asset_count DESC LIMIT 10"
)->fetchAll();

// ── Category breakdown ─────────────────────────────────────────────────────
$catBreakdown = $pdo->query(
    "SELECT c.name, c.type, COUNT(a.id) as cnt, COALESCE(SUM(a.purchase_cost),0) as cost
     FROM categories c LEFT JOIN assets a ON a.category_id=c.id
     GROUP BY c.id,c.name,c.type HAVING cnt>0 ORDER BY cnt DESC LIMIT 8"
)->fetchAll();

// ── Maintenance cost by month (last 6 months) ──────────────────────────────
$maintMonths = $pdo->query(
    "SELECT DATE_FORMAT(date,'%Y-%m') as month, COALESCE(SUM(cost),0) as total
     FROM maintenance WHERE date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND cost > 0
     GROUP BY DATE_FORMAT(date,'%Y-%m') ORDER BY month"
)->fetchAll();
$maintLabels = array_column($maintMonths, 'month');
$maintTotals = array_map('floatval', array_column($maintMonths, 'total'));

// ── Status breakdown ───────────────────────────────────────────────────────
$statusBreakdown = $pdo->query("SELECT status, COUNT(*) as cnt FROM assets GROUP BY status")->fetchAll();

// ── Top 5 departments by asset value ──────────────────────────────────────
$topDeptsByValue = $pdo->query(
    "SELECT d.name, COALESCE(SUM(a.purchase_cost),0) as value, COUNT(a.id) as cnt
     FROM departments d LEFT JOIN assets a ON a.department_id=d.id
     GROUP BY d.id,d.name ORDER BY value DESC LIMIT 5"
)->fetchAll();

// JS-ready data
$catLabels  = array_column($catBreakdown, 'name');
$catCounts  = array_map('intval', array_column($catBreakdown, 'cnt'));

$statusColors = ['active'=>'#10b981','under_maintenance'=>'#f59e0b','disposed'=>'#6b7280','lost'=>'#ef4444'];
$statusChartLabels = [];
$statusChartData   = [];
$statusChartColors = [];
foreach ($statusBreakdown as $s) {
    $statusChartLabels[] = ucwords(str_replace('_',' ', $s['status']));
    $statusChartData[]   = (int)$s['cnt'];
    $statusChartColors[] = $statusColors[$s['status']] ?? '#94a3b8';
}

$topDeptNames  = array_column($topDeptsByValue, 'name');
$topDeptValues = array_map('floatval', array_column($topDeptsByValue, 'value'));

$pageTitle = 'Executive Dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── Executive dashboard premium styles ─────────────────────────────── */
.exec-hero {
  background: linear-gradient(135deg, <?= e($primary) ?> 0%, #1a1a2e 100%);
  color: #fff;
  border-radius: 14px;
  padding: 28px 32px;
  margin-bottom: 28px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  box-shadow: 0 8px 32px rgba(0,0,0,.18);
}
.exec-hero h1 { font-size: 1.75rem; font-weight: 800; margin: 0 0 4px; letter-spacing: -.5px; }
.exec-hero .subtitle { opacity: .8; font-size: .9rem; margin: 0; }
.exec-hero .actions { display: flex; gap: 10px; flex-wrap: wrap; }
.exec-hero .btn-ghost {
  background: rgba(255,255,255,.15);
  border: 1.5px solid rgba(255,255,255,.35);
  color: #fff;
  font-weight: 600;
  font-size: .85rem;
  padding: 8px 18px;
  border-radius: 8px;
  text-decoration: none;
  transition: background .15s;
  display: inline-flex; align-items: center; gap: 6px;
}
.exec-hero .btn-ghost:hover { background: rgba(255,255,255,.28); color: #fff; }

/* KPI cards row 1 */
.kpi-primary {
  border-radius: 12px;
  color: #fff;
  padding: 22px 20px 18px;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 18px rgba(0,0,0,.12);
  height: 100%;
}
.kpi-primary .kpi-number { font-size: 1.9rem; font-weight: 800; line-height: 1.1; margin-bottom: 4px; }
.kpi-primary .kpi-label { font-size: .8rem; opacity: .88; font-weight: 500; letter-spacing: .3px; display: flex; align-items: center; gap: 5px; }
.kpi-primary .kpi-icon {
  position: absolute; right: 16px; top: 16px;
  font-size: 2.4rem; opacity: .18;
}
.kpi-primary .kpi-sub { font-size: .78rem; opacity: .75; margin-top: 6px; }

/* KPI cards row 2 */
.kpi-secondary {
  border-radius: 12px;
  background: #fff;
  border: 1px solid #e8ecf2;
  padding: 18px 20px;
  height: 100%;
  box-shadow: 0 2px 8px rgba(0,0,0,.05);
  transition: box-shadow .15s;
}
.kpi-secondary:hover { box-shadow: 0 4px 18px rgba(0,0,0,.1); }
.kpi-secondary .kpi-number { font-size: 1.65rem; font-weight: 800; color: #1e293b; line-height: 1.1; margin-bottom: 3px; }
.kpi-secondary .kpi-label { font-size: .78rem; color: #64748b; font-weight: 500; letter-spacing: .3px; display: flex; align-items: center; gap: 5px; }
.kpi-secondary .kpi-icon-box {
  width: 42px; height: 42px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.2rem; margin-bottom: 12px;
}

/* Chart cards */
.chart-card {
  border-radius: 12px;
  background: #fff;
  border: 1px solid #e8ecf2;
  box-shadow: 0 2px 8px rgba(0,0,0,.05);
  height: 100%;
}
.chart-card .chart-card-header {
  padding: 16px 20px 0;
  display: flex; justify-content: space-between; align-items: center;
  border-bottom: 1px solid #f1f5f9;
  padding-bottom: 12px;
}
.chart-card .chart-card-header h6 {
  margin: 0; font-size: .875rem; font-weight: 700; color: #1e293b;
}
.chart-card .chart-body { padding: 16px 20px; }

/* Department table */
.dept-table { width: 100%; }
.dept-table th { font-size: .75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px; padding: 10px 12px; border-bottom: 2px solid #f1f5f9; }
.dept-table td { padding: 11px 12px; border-bottom: 1px solid #f8fafc; font-size: .875rem; vertical-align: middle; }
.dept-table tr:last-child td { border-bottom: none; }
.dept-table tr:hover td { background: #fafbfd; }
.progress-bar-mini { height: 6px; border-radius: 3px; background: #f1f5f9; overflow: hidden; margin-top: 4px; }
.progress-bar-mini .fill { height: 100%; border-radius: 3px; background: <?= e($primary) ?>; }

/* Ranked value list */
.ranked-item {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 0; border-bottom: 1px solid #f1f5f9;
}
.ranked-item:last-child { border-bottom: none; }
.ranked-num { width: 24px; height: 24px; border-radius: 50%; background: <?= e($primary) ?>22; color: <?= e($primary) ?>; font-size: .75rem; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ranked-name { flex: 1; font-size: .875rem; font-weight: 600; color: #1e293b; }
.ranked-badge { font-size: .78rem; font-weight: 700; color: #fff; background: <?= e($primary) ?>; padding: 3px 10px; border-radius: 20px; white-space: nowrap; }

/* Report footer note */
.exec-footer-note { font-size: .8rem; color: #94a3b8; margin-top: 32px; padding-top: 16px; border-top: 1px solid #f1f5f9; }

@media (max-width: 767px) {
  .exec-hero { padding: 20px; }
  .exec-hero h1 { font-size: 1.3rem; }
}
</style>

<!-- ── HERO HEADER ────────────────────────────────────────────────────── -->
<div class="exec-hero">
  <div>
    <h1><i class="bi bi-graph-up-arrow me-2" style="opacity:.7"></i>Executive Dashboard</h1>
    <p class="subtitle">Management overview &nbsp;·&nbsp; <?= date('d F Y') ?></p>
  </div>
  <div class="actions">
    <a href="/asset-manager/modules/dashboard/executive_pdf.php" class="btn-ghost" target="_blank">
      <i class="bi bi-printer"></i> Export PDF
    </a>
    <a href="/asset-manager/modules/dashboard/index.php" class="btn-ghost">
      <i class="bi bi-arrow-left"></i> Dashboard
    </a>
  </div>
</div>

<!-- ── ROW 1: PRIMARY KPI CARDS ──────────────────────────────────────── -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="kpi-primary" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8)">
      <i class="bi bi-boxes kpi-icon"></i>
      <div class="kpi-number"><?= number_format($totalAssets) ?></div>
      <div class="kpi-label"><i class="bi bi-boxes"></i> Total Assets</div>
      <div class="kpi-sub"><?= number_format($newAssetsThisYear) ?> added this year</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-primary" style="background:linear-gradient(135deg,#10b981,#059669)">
      <i class="bi bi-check-circle kpi-icon"></i>
      <div class="kpi-number"><?= number_format($totalActiveAssets) ?></div>
      <div class="kpi-label"><i class="bi bi-check-circle"></i> Active Assets</div>
      <div class="kpi-sub"><?= $totalAssets > 0 ? round($totalActiveAssets/$totalAssets*100) : 0 ?>% of total fleet</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-primary" style="background:linear-gradient(135deg,#0ea5e9,#0284c7)">
      <i class="bi bi-cash-stack kpi-icon"></i>
      <div class="kpi-number" style="font-size:1.45rem"><?= $currency ?> <?= number_format($totalCost, 0) ?></div>
      <div class="kpi-label"><i class="bi bi-cash-stack"></i> Total Asset Value</div>
      <div class="kpi-sub">Purchase cost basis</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-primary" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9)">
      <i class="bi bi-graph-down kpi-icon"></i>
      <div class="kpi-number" style="font-size:1.45rem"><?= $currency ?> <?= number_format($totalBookValue, 0) ?></div>
      <div class="kpi-label"><i class="bi bi-graph-down"></i> Book Value Today</div>
      <div class="kpi-sub"><?= $totalCost > 0 ? round($totalBookValue/$totalCost*100) : 0 ?>% of cost basis</div>
    </div>
  </div>
</div>

<!-- ── ROW 2: SECONDARY KPI CARDS ────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="kpi-secondary">
      <div class="kpi-icon-box" style="background:#fef3c7;color:#d97706"><i class="bi bi-tools"></i></div>
      <div class="kpi-number"><?= $currency ?> <?= number_format($maintenanceCostYTD, 0) ?></div>
      <div class="kpi-label"><i class="bi bi-tools"></i> Maintenance Cost YTD</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-secondary">
      <div class="kpi-icon-box" style="background:#dcfce7;color:#16a34a"><i class="bi bi-plus-circle"></i></div>
      <div class="kpi-number"><?= number_format($newAssetsThisYear) ?></div>
      <div class="kpi-label"><i class="bi bi-plus-circle"></i> New Assets This Year</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-secondary">
      <div class="kpi-icon-box" style="background:#fee2e2;color:#dc2626"><i class="bi bi-hourglass-split"></i></div>
      <div class="kpi-number"><?= number_format($pendingActions) ?></div>
      <div class="kpi-label"><i class="bi bi-hourglass-split"></i> Pending Actions</div>
      <?php if ($pendingActions > 0): ?>
      <div style="font-size:.75rem;color:#64748b;margin-top:4px"><?= $pendingTransfers ?> transfers · <?= $pendingDisposals ?> disposals</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-secondary">
      <div class="kpi-icon-box" style="background:#ede9fe;color:#7c3aed"><i class="bi bi-people"></i></div>
      <div class="kpi-number"><?= number_format($totalUsers) ?></div>
      <div class="kpi-label"><i class="bi bi-people"></i> Active Users</div>
    </div>
  </div>
</div>

<!-- ── ROW 3: TREND + STATUS CHARTS ──────────────────────────────────── -->
<div class="row g-3 mb-3">
  <div class="col-md-7">
    <div class="chart-card">
      <div class="chart-card-header">
        <h6><i class="bi bi-graph-up me-2 text-primary"></i>Asset Additions — Last 12 Months</h6>
        <span class="badge bg-light text-secondary" style="font-size:.72rem"><?= array_sum($trendCounts) ?> total</span>
      </div>
      <div class="chart-body">
        <div style="position:relative;height:260px">
          <canvas id="trendChart"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-5">
    <div class="chart-card">
      <div class="chart-card-header">
        <h6><i class="bi bi-pie-chart me-2 text-primary"></i>Assets by Status</h6>
        <span class="badge bg-light text-secondary" style="font-size:.72rem"><?= $totalAssets ?> total</span>
      </div>
      <div class="chart-body">
        <div style="position:relative;height:260px">
          <canvas id="statusChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── ROW 4: DEPARTMENT TABLE ────────────────────────────────────────── -->
<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="chart-card">
      <div class="chart-card-header">
        <h6><i class="bi bi-diagram-3 me-2 text-primary"></i>Assets by Department</h6>
        <a href="/asset-manager/modules/departments/index.php" class="btn btn-sm btn-outline-primary" style="font-size:.78rem;padding:4px 12px">View All</a>
      </div>
      <div class="chart-body p-0">
        <div class="table-responsive">
          <table class="dept-table">
            <thead>
              <tr>
                <th style="padding-left:20px">Department</th>
                <th>Asset Count</th>
                <th>Total Value</th>
                <th>% of Portfolio</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $deptTotalAssets = array_sum(array_column($deptBreakdown, 'asset_count')) ?: 1;
              $deptTotalCost   = array_sum(array_column($deptBreakdown, 'total_cost'))   ?: 1;
              foreach ($deptBreakdown as $dept):
                $pctCount = round($dept['asset_count'] / $deptTotalAssets * 100, 1);
                $pctValue = round($dept['total_cost']  / $deptTotalCost  * 100, 1);
              ?>
              <tr>
                <td style="padding-left:20px;font-weight:600;color:#1e293b"><?= e($dept['name']) ?></td>
                <td>
                  <span class="fw-semibold"><?= number_format($dept['asset_count']) ?></span>
                  <div class="progress-bar-mini" style="width:120px"><div class="fill" style="width:<?= $pctCount ?>%"></div></div>
                </td>
                <td><?= $currency ?> <?= number_format($dept['total_cost'], 0) ?></td>
                <td>
                  <span class="badge" style="background:<?= e($primary) ?>22;color:<?= e($primary) ?>;font-weight:700;font-size:.78rem"><?= $pctValue ?>%</span>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($deptBreakdown)): ?>
              <tr><td colspan="4" class="text-center text-muted py-4" style="padding-left:20px">No department data available.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── ROW 5: CATEGORY + MAINTENANCE CHARTS ───────────────────────────── -->
<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="chart-card">
      <div class="chart-card-header">
        <h6><i class="bi bi-tags me-2 text-primary"></i>Top Categories by Asset Count</h6>
      </div>
      <div class="chart-body">
        <div style="position:relative;height:280px">
          <canvas id="catChart"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="chart-card">
      <div class="chart-card-header">
        <h6><i class="bi bi-wrench me-2 text-primary"></i>Maintenance Spend — Last 6 Months</h6>
        <span class="badge bg-light text-secondary" style="font-size:.72rem"><?= $currency ?> <?= number_format(array_sum($maintTotals), 0) ?></span>
      </div>
      <div class="chart-body">
        <div style="position:relative;height:280px">
          <canvas id="maintChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── ROW 6: TOP DEPARTMENTS BY VALUE ───────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="chart-card">
      <div class="chart-card-header">
        <h6><i class="bi bi-trophy me-2 text-primary"></i>Top Departments by Asset Value</h6>
      </div>
      <div class="chart-body">
        <div class="row g-4 align-items-center">
          <div class="col-md-5">
            <?php foreach ($topDeptsByValue as $i => $td): ?>
            <div class="ranked-item">
              <div class="ranked-num"><?= $i+1 ?></div>
              <div class="ranked-name">
                <?= e($td['name']) ?>
                <div style="font-size:.75rem;color:#94a3b8;font-weight:400"><?= number_format($td['cnt']) ?> assets</div>
              </div>
              <div class="ranked-badge"><?= $currency ?> <?= number_format($td['value'], 0) ?></div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($topDeptsByValue)): ?>
            <p class="text-muted small">No data available.</p>
            <?php endif; ?>
          </div>
          <div class="col-md-7">
            <div style="position:relative;height:220px">
              <canvas id="topDeptChart"></canvas>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── REPORT FOOTER ──────────────────────────────────────────────────── -->
<div class="exec-footer-note">
  <i class="bi bi-info-circle me-1"></i>
  Report generated: <?= date('d M Y H:i') ?> &nbsp;·&nbsp; by <?= e(currentUser()['name']) ?> &nbsp;·&nbsp; Confidential — Management Use Only
</div>

<!-- ── Chart.js ───────────────────────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color = '#64748b';
Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";

const brandColor  = '<?= e($primary) ?>';
const brandAlpha  = brandColor + '33';
const currency    = '<?= e($currency) ?>';

// ── Trend line chart ─────────────────────────────────────────────────
const trendLabels = <?= json_encode($trendLabels) ?>;
const trendCounts = <?= json_encode($trendCounts) ?>;
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: trendLabels,
    datasets: [{
      label: 'Assets Added',
      data: trendCounts,
      borderColor: brandColor,
      backgroundColor: brandAlpha,
      fill: true,
      tension: 0.4,
      pointRadius: 4,
      pointBackgroundColor: brandColor,
      borderWidth: 2.5
    }]
  },
  options: {
    animation: false,
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } }, grid: { color: '#f1f5f9' } },
      x: { ticks: { font: { size: 10 } }, grid: { display: false } }
    }
  }
});

// ── Status doughnut ──────────────────────────────────────────────────
const statusLabels = <?= json_encode($statusChartLabels) ?>;
const statusData   = <?= json_encode($statusChartData) ?>;
const statusColors = <?= json_encode($statusChartColors) ?>;
new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: {
    labels: statusLabels,
    datasets: [{ data: statusData, backgroundColor: statusColors, borderWidth: 3, borderColor: '#fff' }]
  },
  options: {
    animation: false,
    responsive: true,
    maintainAspectRatio: false,
    cutout: '68%',
    plugins: {
      legend: { position: 'bottom', labels: { font: { size: 12 }, padding: 14, usePointStyle: true } }
    }
  }
});

// ── Category horizontal bar ──────────────────────────────────────────
const catLabels = <?= json_encode($catLabels) ?>;
const catCounts = <?= json_encode($catCounts) ?>;
new Chart(document.getElementById('catChart'), {
  type: 'bar',
  data: {
    labels: catLabels,
    datasets: [{
      label: 'Assets',
      data: catCounts,
      backgroundColor: brandColor + 'cc',
      borderColor: brandColor,
      borderWidth: 1,
      borderRadius: 5
    }]
  },
  options: {
    animation: false,
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: 'y',
    plugins: { legend: { display: false } },
    scales: {
      x: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 } }, grid: { color: '#f1f5f9' } },
      y: { ticks: { font: { size: 11 } }, grid: { display: false } }
    }
  }
});

// ── Maintenance spend bar ────────────────────────────────────────────
const maintLabels = <?= json_encode($maintLabels) ?>;
const maintTotals = <?= json_encode($maintTotals) ?>;
new Chart(document.getElementById('maintChart'), {
  type: 'bar',
  data: {
    labels: maintLabels,
    datasets: [{
      label: 'Cost (' + currency + ')',
      data: maintTotals,
      backgroundColor: '#f59e0bcc',
      borderColor: '#d97706',
      borderWidth: 1,
      borderRadius: 5
    }]
  },
  options: {
    animation: false,
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { font: { size: 10 }, callback: v => currency + ' ' + v.toLocaleString() }, grid: { color: '#f1f5f9' } },
      x: { ticks: { font: { size: 10 } }, grid: { display: false } }
    }
  }
});

// ── Top departments horizontal bar ───────────────────────────────────
const topDeptNames  = <?= json_encode($topDeptNames) ?>;
const topDeptValues = <?= json_encode($topDeptValues) ?>;
const deptColors = ['#3b82f6','#10b981','#8b5cf6','#f59e0b','#ef4444'];
new Chart(document.getElementById('topDeptChart'), {
  type: 'bar',
  data: {
    labels: topDeptNames,
    datasets: [{
      label: 'Asset Value',
      data: topDeptValues,
      backgroundColor: deptColors.map(c => c + 'cc'),
      borderColor: deptColors,
      borderWidth: 1.5,
      borderRadius: 6
    }]
  },
  options: {
    animation: false,
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: 'y',
    plugins: { legend: { display: false } },
    scales: {
      x: { beginAtZero: true, ticks: { font: { size: 10 }, callback: v => currency + ' ' + v.toLocaleString() }, grid: { color: '#f1f5f9' } },
      y: { ticks: { font: { size: 11 } }, grid: { display: false } }
    }
  }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
