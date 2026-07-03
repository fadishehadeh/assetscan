<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$settings = getSettings($pdo);
$currency = $settings['currency'] ?? 'USD';
$primary  = $settings['primary_color'] ?? '#E84B37';
$company  = $settings['company_name']  ?? 'Asset Manager';
$appName  = $settings['app_name']      ?? 'Asset Manager';
$logoPath = $settings['logo_path']     ?? 'assets/img/logo.svg';

// ── KPI Queries ────────────────────────────────────────────────────────────
$totalAssets            = (int)$pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
$totalActiveAssets      = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='active'")->fetchColumn();
$totalCost              = (float)$pdo->query("SELECT COALESCE(SUM(purchase_cost),0) FROM assets")->fetchColumn();
$maintenanceCostYTD     = (float)$pdo->query("SELECT COALESCE(SUM(cost),0) FROM maintenance WHERE YEAR(date)=YEAR(CURDATE()) AND cost > 0")->fetchColumn();
$assetsUnderMaintenance = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='under_maintenance'")->fetchColumn();
$assetsDisposed         = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='disposed'")->fetchColumn();
$pendingTransfers       = (int)$pdo->query("SELECT COUNT(*) FROM asset_transfers WHERE status='pending'")->fetchColumn();
$pendingDisposals       = (int)$pdo->query("SELECT COUNT(*) FROM disposal_requests WHERE status='pending'")->fetchColumn();
$totalUsers             = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
$newAssetsThisYear      = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
$pendingActions         = $pendingTransfers + $pendingDisposals;

// ── Total Book Value ───────────────────────────────────────────────────────
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
$depreciation = $totalCost - $totalBookValue;

// ── Status breakdown ───────────────────────────────────────────────────────
$statusBreakdown = $pdo->query("SELECT status, COUNT(*) as cnt FROM assets GROUP BY status")->fetchAll();

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
    "SELECT DATE_FORMAT(date,'%b %Y') as month, COALESCE(SUM(cost),0) as total
     FROM maintenance WHERE date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND cost > 0
     GROUP BY DATE_FORMAT(date,'%Y-%m') ORDER BY MIN(date)"
)->fetchAll();

$currentUser = currentUser();
$generatedAt = date('d M Y, H:i');
$reportDate  = date('d F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Executive Summary — <?= e($company) ?></title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, Helvetica, sans-serif; font-size: 11.5px; color: #1e293b; background: #fff; }

/* ── Print bar ── */
.no-print { background: #f8fafc; border-bottom: 2px solid #e2e8f0; padding: 12px 24px; display: flex; align-items: center; gap: 12px; }
.no-print button { background: <?= e($primary) ?>; color: #fff; border: none; padding: 9px 22px; border-radius: 7px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.no-print button:hover { opacity: .9; }
.no-print a { color: <?= e($primary) ?>; font-size: 12px; text-decoration: none; font-weight: 600; }

/* ── Report header ── */
.report-header {
  background: linear-gradient(135deg, <?= e($primary) ?> 0%, #1a1a2e 100%);
  color: #fff;
  padding: 22px 28px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.report-header h1 { font-size: 18px; font-weight: 800; margin-bottom: 4px; letter-spacing: -.3px; }
.report-header .meta { font-size: 10.5px; opacity: .8; line-height: 1.6; }
.report-header .logo { height: 38px; width: auto; filter: brightness(10); object-fit: contain; }
.report-header .header-left { display: flex; align-items: center; gap: 16px; }
.report-header .logo-wrap { width: 44px; height: 44px; border-radius: 10px; background: rgba(255,255,255,.15); display: flex; align-items: center; justify-content: center; overflow: hidden; }

/* ── Divider label ── */
.section-label {
  background: <?= e($primary) ?>;
  color: #fff;
  font-size: 9px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 1px;
  padding: 5px 28px;
}

/* ── KPI summary grid ── */
.kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; border-left: 1px solid #e8ecf2; border-top: 1px solid #e8ecf2; }
.kpi-box {
  padding: 16px 18px;
  border-right: 1px solid #e8ecf2;
  border-bottom: 1px solid #e8ecf2;
  text-align: center;
}
.kpi-box .val { font-size: 20px; font-weight: 800; color: <?= e($primary) ?>; line-height: 1.1; margin-bottom: 4px; }
.kpi-box .lbl { font-size: 9.5px; color: #64748b; text-transform: uppercase; letter-spacing: .5px; font-weight: 600; }
.kpi-box .sub { font-size: 9px; color: #94a3b8; margin-top: 3px; }

/* ── Section heading ── */
.section-heading {
  padding: 14px 28px 6px;
  font-size: 12px;
  font-weight: 800;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 6px;
  border-bottom: 1px solid #f1f5f9;
}
.section-heading::before {
  content: '';
  display: inline-block;
  width: 4px; height: 16px;
  background: <?= e($primary) ?>;
  border-radius: 2px;
}

/* ── Tables ── */
.pdf-table { width: 100%; border-collapse: collapse; margin: 0; }
.pdf-table th {
  background: #f8fafc;
  color: #475569;
  padding: 8px 12px 8px 28px;
  text-align: left;
  font-size: 9.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .5px;
  border-bottom: 2px solid #e2e8f0;
}
.pdf-table th:first-child { padding-left: 28px; }
.pdf-table td { padding: 9px 12px 9px 28px; border-bottom: 1px solid #f1f5f9; font-size: 11px; vertical-align: middle; }
.pdf-table td:first-child { padding-left: 28px; font-weight: 600; }
.pdf-table tr:last-child td { border-bottom: none; }
.pdf-table tr:nth-child(even) td { background: #fafbfc; }

/* ── Progress bar ── */
.bar-wrap { height: 5px; background: #e8ecf2; border-radius: 3px; overflow: hidden; min-width: 80px; margin-top: 3px; }
.bar-fill { height: 100%; background: <?= e($primary) ?>; border-radius: 3px; }

/* ── Status badge ── */
.status-pill { display: inline-block; padding: 2px 9px; border-radius: 12px; font-size: 9.5px; font-weight: 700; text-transform: capitalize; }
.status-active { background: #dcfce7; color: #15803d; }
.status-under_maintenance { background: #fef3c7; color: #b45309; }
.status-disposed { background: #f1f5f9; color: #475569; }
.status-lost { background: #fee2e2; color: #b91c1c; }

/* ── Value badge ── */
.value-badge { display: inline-block; background: <?= e($primary) ?>18; color: <?= e($primary) ?>; font-weight: 700; padding: 2px 9px; border-radius: 12px; font-size: 10px; }

/* ── Two column layout ── */
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
.two-col > div { border-right: 1px solid #e8ecf2; }
.two-col > div:last-child { border-right: none; }

/* ── Footer ── */
.report-footer {
  margin-top: 24px;
  padding: 12px 28px;
  border-top: 2px solid <?= e($primary) ?>;
  display: flex;
  justify-content: space-between;
  align-items: center;
  color: #94a3b8;
  font-size: 9.5px;
}

/* ── Financial highlight ── */
.fin-row { display: flex; justify-content: space-between; padding: 8px 28px; border-bottom: 1px solid #f1f5f9; }
.fin-row:last-child { border-bottom: none; }
.fin-label { color: #64748b; font-size: 10.5px; }
.fin-value { font-weight: 700; font-size: 11px; color: #1e293b; }
.fin-value.primary { color: <?= e($primary) ?>; }

@media print {
  .no-print { display: none !important; }
  body { font-size: 10.5px; }
  @page { margin: 12mm 10mm; size: A4; }
}
</style>
</head>
<body>

<!-- ── PRINT BAR ──────────────────────────────────────────────────────── -->
<div class="no-print">
  <button onclick="window.print()">&#128438; Print / Save as PDF</button>
  <a href="/asset-manager/modules/dashboard/executive.php">&#8592; Back to Executive Dashboard</a>
</div>

<!-- ── REPORT HEADER ──────────────────────────────────────────────────── -->
<div class="report-header">
  <div class="header-left">
    <div class="logo-wrap">
      <img src="/asset-manager/<?= e($logoPath) ?>" class="logo" alt="<?= e($appName) ?>">
    </div>
    <div>
      <div style="font-size:10px;opacity:.7;text-transform:uppercase;letter-spacing:1px;margin-bottom:2px"><?= e($company) ?></div>
      <h1>Executive Summary Report</h1>
      <div class="meta">
        Generated: <?= $generatedAt ?> &nbsp;·&nbsp;
        By: <?= e($currentUser['name']) ?> &nbsp;·&nbsp;
        <?= $reportDate ?>
      </div>
    </div>
  </div>
  <div style="text-align:right;opacity:.7;font-size:10px;line-height:1.8">
    Asset Management System<br>
    Confidential — Management Use Only<br>
    <strong style="font-size:14px;opacity:1;color:#fff"><?= $currency ?></strong>
  </div>
</div>

<!-- ── KPI SUMMARY GRID ───────────────────────────────────────────────── -->
<div class="section-label">Key Performance Indicators</div>
<div class="kpi-grid">
  <div class="kpi-box">
    <div class="val"><?= number_format($totalAssets) ?></div>
    <div class="lbl">Total Assets</div>
    <div class="sub"><?= number_format($newAssetsThisYear) ?> added this year</div>
  </div>
  <div class="kpi-box">
    <div class="val"><?= number_format($totalActiveAssets) ?></div>
    <div class="lbl">Active Assets</div>
    <div class="sub"><?= $totalAssets > 0 ? round($totalActiveAssets/$totalAssets*100) : 0 ?>% of fleet</div>
  </div>
  <div class="kpi-box">
    <div class="val"><?= $currency ?> <?= number_format($totalCost, 0) ?></div>
    <div class="lbl">Total Purchase Value</div>
    <div class="sub">Cost basis</div>
  </div>
  <div class="kpi-box">
    <div class="val"><?= $currency ?> <?= number_format($totalBookValue, 0) ?></div>
    <div class="lbl">Current Book Value</div>
    <div class="sub"><?= $totalCost > 0 ? round($totalBookValue/$totalCost*100) : 0 ?>% of cost</div>
  </div>
  <div class="kpi-box">
    <div class="val"><?= number_format($assetsUnderMaintenance) ?></div>
    <div class="lbl">Under Maintenance</div>
    <div class="sub">&nbsp;</div>
  </div>
  <div class="kpi-box">
    <div class="val"><?= number_format($assetsDisposed) ?></div>
    <div class="lbl">Disposed</div>
    <div class="sub">&nbsp;</div>
  </div>
  <div class="kpi-box">
    <div class="val"><?= $currency ?> <?= number_format($maintenanceCostYTD, 0) ?></div>
    <div class="lbl">Maintenance Cost YTD</div>
    <div class="sub">Completed work orders</div>
  </div>
  <div class="kpi-box">
    <div class="val"><?= number_format($pendingActions) ?></div>
    <div class="lbl">Pending Actions</div>
    <div class="sub"><?= $pendingTransfers ?> transfers · <?= $pendingDisposals ?> disposals</div>
  </div>
</div>

<!-- ── FINANCIAL SUMMARY ──────────────────────────────────────────────── -->
<div class="section-label" style="margin-top:4px">Financial Overview</div>
<div style="padding:4px 0 2px">
  <div class="fin-row">
    <span class="fin-label">Total Portfolio (Purchase Cost)</span>
    <span class="fin-value primary"><?= $currency ?> <?= number_format($totalCost, 2) ?></span>
  </div>
  <div class="fin-row">
    <span class="fin-label">Accumulated Depreciation</span>
    <span class="fin-value" style="color:#ef4444">– <?= $currency ?> <?= number_format(max(0, $depreciation), 2) ?></span>
  </div>
  <div class="fin-row" style="background:#fafbfc">
    <span class="fin-label" style="font-weight:700;color:#1e293b">Net Book Value</span>
    <span class="fin-value primary" style="font-size:13px"><?= $currency ?> <?= number_format($totalBookValue, 2) ?></span>
  </div>
  <div class="fin-row">
    <span class="fin-label">Maintenance Cost Year-to-Date</span>
    <span class="fin-value"><?= $currency ?> <?= number_format($maintenanceCostYTD, 2) ?></span>
  </div>
</div>

<!-- ── STATUS BREAKDOWN + MAINTENANCE MONTHS ──────────────────────────── -->
<div class="section-label" style="margin-top:4px">Asset Status &amp; Maintenance</div>
<div class="two-col">
  <div>
    <div style="padding:10px 0 4px">
      <table class="pdf-table">
        <thead>
          <tr>
            <th>Status</th>
            <th>Count</th>
            <th>% of Fleet</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($statusBreakdown as $s):
            $pct = $totalAssets > 0 ? round($s['cnt']/$totalAssets*100, 1) : 0;
            $cls = 'status-' . $s['status'];
          ?>
          <tr>
            <td><span class="status-pill <?= $cls ?>"><?= ucwords(str_replace('_',' ',$s['status'])) ?></span></td>
            <td><?= number_format($s['cnt']) ?></td>
            <td>
              <?= $pct ?>%
              <div class="bar-wrap"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($statusBreakdown)): ?>
          <tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:14px 28px">No data.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div>
    <div style="padding:10px 0 4px">
      <table class="pdf-table">
        <thead>
          <tr>
            <th>Month</th>
            <th>Maintenance Cost</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($maintMonths as $m): ?>
          <tr>
            <td><?= e($m['month']) ?></td>
            <td class="fin-value"><?= $currency ?> <?= number_format($m['total'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($maintMonths)): ?>
          <tr><td colspan="2" style="text-align:center;color:#94a3b8;padding:14px 28px">No maintenance records in period.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── DEPARTMENT BREAKDOWN ───────────────────────────────────────────── -->
<div class="section-label" style="margin-top:4px">Assets by Department</div>
<?php
$deptTotalAssets = array_sum(array_column($deptBreakdown, 'asset_count')) ?: 1;
$deptTotalCost   = array_sum(array_column($deptBreakdown, 'total_cost'))   ?: 1;
?>
<table class="pdf-table">
  <thead>
    <tr>
      <th>Department</th>
      <th>Asset Count</th>
      <th>% of Fleet</th>
      <th>Total Value (<?= e($currency) ?>)</th>
      <th>% of Portfolio</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($deptBreakdown as $dept):
      $pctCount = round($dept['asset_count'] / $deptTotalAssets * 100, 1);
      $pctValue = round($dept['total_cost']  / $deptTotalCost  * 100, 1);
    ?>
    <tr>
      <td><?= e($dept['name']) ?></td>
      <td>
        <?= number_format($dept['asset_count']) ?>
        <div class="bar-wrap"><div class="bar-fill" style="width:<?= $pctCount ?>%"></div></div>
      </td>
      <td><?= $pctCount ?>%</td>
      <td><?= number_format($dept['total_cost'], 0) ?></td>
      <td><span class="value-badge"><?= $pctValue ?>%</span></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($deptBreakdown)): ?>
    <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:16px 28px">No department data available.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<!-- ── CATEGORY BREAKDOWN ────────────────────────────────────────────── -->
<div class="section-label" style="margin-top:4px">Assets by Category</div>
<table class="pdf-table">
  <thead>
    <tr>
      <th>Category</th>
      <th>Type</th>
      <th>Asset Count</th>
      <th>Total Value (<?= e($currency) ?>)</th>
      <th>Avg. Value</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($catBreakdown as $cat):
      $avg = $cat['cnt'] > 0 ? $cat['cost'] / $cat['cnt'] : 0;
    ?>
    <tr>
      <td><?= e($cat['name']) ?></td>
      <td><span class="status-pill" style="background:#ede9fe;color:#6d28d9"><?= e(ucfirst($cat['type'] ?? '—')) ?></span></td>
      <td><?= number_format($cat['cnt']) ?></td>
      <td><?= number_format($cat['cost'], 0) ?></td>
      <td><?= $currency ?> <?= number_format($avg, 0) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($catBreakdown)): ?>
    <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:16px 28px">No category data available.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<!-- ── REPORT FOOTER ──────────────────────────────────────────────────── -->
<div class="report-footer">
  <span><?= e($company) ?> — Asset Management System &nbsp;·&nbsp; Confidential</span>
  <span>Generated: <?= $generatedAt ?> &nbsp;·&nbsp; By: <?= e($currentUser['name']) ?></span>
</div>

</body>
</html>
