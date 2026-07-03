<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$report   = $_GET['report'] ?? 'assets';
$settings = getSettings($pdo);
$currency = $settings['currency'] ?? 'USD';
$company  = $settings['company_name'] ?? 'Company';
$primary  = $settings['primary_color'] ?? '#E84B37';

// Load data depending on report type
switch ($report) {
    case 'eol':
        $title = 'End-of-Life Assets Report';
        $rows  = $pdo->query(
            "SELECT a.asset_tag, a.name, c.name AS category, d.name AS department,
                    a.purchase_date, a.useful_life_years,
                    TIMESTAMPDIFF(YEAR, a.purchase_date, CURDATE()) AS age_years,
                    a.purchase_cost, u.name AS assigned_to
             FROM assets a
             LEFT JOIN categories c ON a.category_id=c.id
             LEFT JOIN departments d ON a.department_id=d.id
             LEFT JOIN users u ON a.assigned_to=u.id
             WHERE a.status='active' AND a.purchase_date IS NOT NULL
               AND TIMESTAMPDIFF(YEAR, a.purchase_date, CURDATE()) >= a.useful_life_years
             ORDER BY age_years DESC"
        )->fetchAll();
        $headers = ['Tag','Name','Category','Dept','Purchase Date','Life','Age','Cost','Assigned'];
        $rowFn = fn($r) => [
            e($r['asset_tag']), e($r['name']), e($r['category'] ?? '—'), e($r['department'] ?? '—'),
            $r['purchase_date'] ? date('d M Y', strtotime($r['purchase_date'])) : '—',
            $r['useful_life_years'].' yrs', $r['age_years'].' yrs',
            formatMoney((float)$r['purchase_cost'], $currency), e($r['assigned_to'] ?? '—')
        ];
        break;

    case 'warranty':
        $title = 'Warranty Expiry Report';
        $rows  = $pdo->query(
            "SELECT a.asset_tag, a.name, c.name AS category, a.warranty_expiry,
                    DATEDIFF(a.warranty_expiry, CURDATE()) AS days_left, u.name AS assigned_to
             FROM assets a
             LEFT JOIN categories c ON a.category_id=c.id
             LEFT JOIN users u ON a.assigned_to=u.id
             WHERE a.warranty_expiry IS NOT NULL
             ORDER BY a.warranty_expiry ASC"
        )->fetchAll();
        $headers = ['Tag','Name','Category','Warranty Expiry','Days Left','Assigned'];
        $rowFn = fn($r) => [
            e($r['asset_tag']), e($r['name']), e($r['category'] ?? '—'),
            $r['warranty_expiry'] ? date('d M Y', strtotime($r['warranty_expiry'])) : '—',
            $r['days_left'] >= 0 ? $r['days_left'].' days' : abs($r['days_left']).' days ago',
            e($r['assigned_to'] ?? '—')
        ];
        break;

    case 'depreciation':
        $title = 'Depreciation Schedule Report';
        $rows  = $pdo->query(
            "SELECT a.asset_tag, a.name, c.name AS category, a.purchase_date,
                    a.purchase_cost, a.useful_life_years, a.salvage_value, a.depreciation_method
             FROM assets a
             LEFT JOIN categories c ON a.category_id=c.id
             WHERE a.purchase_cost > 0 AND a.purchase_date IS NOT NULL
             ORDER BY a.name"
        )->fetchAll();
        $headers = ['Tag','Name','Category','Purchase Date','Cost','Life','Salvage','Method','Book Value'];
        $rowFn = function($r) use ($currency) {
            $bv = currentBookValue((float)$r['purchase_cost'],(float)$r['salvage_value'],(int)$r['useful_life_years'],$r['depreciation_method'],$r['purchase_date']);
            return [
                e($r['asset_tag']), e($r['name']), e($r['category'] ?? '—'),
                $r['purchase_date'] ? date('d M Y', strtotime($r['purchase_date'])) : '—',
                formatMoney((float)$r['purchase_cost'],$currency),
                $r['useful_life_years'].' yrs',
                formatMoney((float)$r['salvage_value'],$currency),
                $r['depreciation_method'] === 'straight_line' ? 'SL' : 'DB',
                formatMoney($bv, $currency)
            ];
        };
        break;

    default: // full asset register
        $title = 'Asset Register';
        $rows  = $pdo->query(
            "SELECT a.asset_tag, a.name, c.name AS category, a.brand, a.model,
                    a.serial_number, a.purchase_date, a.purchase_cost,
                    a.status, d.name AS department, u.name AS assigned_to
             FROM assets a
             LEFT JOIN categories c ON a.category_id=c.id
             LEFT JOIN departments d ON a.department_id=d.id
             LEFT JOIN users u ON a.assigned_to=u.id
             ORDER BY a.name"
        )->fetchAll();
        $headers = ['Tag','Name','Category','Brand','Model','Serial','Purchase Date','Cost','Status','Dept','Assigned'];
        $rowFn = fn($r) => [
            e($r['asset_tag']), e($r['name']), e($r['category'] ?? '—'),
            e($r['brand'] ?? '—'), e($r['model'] ?? '—'), e($r['serial_number'] ?? '—'),
            $r['purchase_date'] ? date('d M Y', strtotime($r['purchase_date'])) : '—',
            formatMoney((float)$r['purchase_cost'],$currency),
            ucwords(str_replace('_',' ',$r['status'])),
            e($r['department'] ?? '—'), e($r['assigned_to'] ?? '—')
        ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($title) ?></title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size:11px; color:#1e293b; background:#fff; }
  .report-header { background:<?= e($primary) ?>; color:#fff; padding:20px 24px; display:flex; justify-content:space-between; align-items:center; }
  .report-header h1 { font-size:16px; font-weight:700; margin-bottom:2px; }
  .report-header .meta { font-size:10px; opacity:.8; }
  .report-header .logo { height:36px; width:auto; filter:brightness(10); }
  .summary { background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:10px 24px; display:flex; gap:24px; }
  .summary-item { text-align:center; }
  .summary-item .val { font-size:18px; font-weight:800; color:<?= e($primary) ?>; }
  .summary-item .lbl { font-size:9px; color:#64748b; text-transform:uppercase; letter-spacing:.5px; }
  .content { padding:16px 24px; }
  table { width:100%; border-collapse:collapse; margin-top:10px; }
  th { background:<?= e($primary) ?>; color:#fff; padding:7px 8px; text-align:left; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; }
  td { padding:6px 8px; border-bottom:1px solid #f1f5f9; font-size:10.5px; }
  tr:nth-child(even) td { background:#fafafa; }
  .footer { margin-top:20px; padding:12px 24px; border-top:2px solid <?= e($primary) ?>; display:flex; justify-content:space-between; color:#94a3b8; font-size:10px; }
  .no-print { text-align:center; padding:16px; background:#f0f2f5; border-bottom:1px solid #e2e8f0; }
  .no-print button { background:<?= e($primary) ?>; color:#fff; border:none; padding:8px 24px; border-radius:6px; font-size:13px; cursor:pointer; font-weight:600; margin-right:8px; }
  .no-print a { color:<?= e($primary) ?>; font-size:12px; text-decoration:none; }
  @media print { .no-print { display:none !important; } }
</style>
</head>
<body>

<div class="no-print">
  <button onclick="window.print()"><i>🖨</i> Print / Save as PDF</button>
  <a href="javascript:history.back()">← Back</a>
</div>

<div class="report-header">
  <div>
    <h1><?= e($title) ?></h1>
    <div class="meta"><?= e($company) ?> &nbsp;·&nbsp; Generated: <?= date('d M Y, H:i') ?> &nbsp;·&nbsp; By: <?= e($_SESSION['user_name'] ?? '') ?></div>
  </div>
  <img src="/asset-manager/<?= e($settings['logo_path'] ?? 'assets/img/logo.png') ?>" class="logo" alt="">
</div>

<div class="summary">
  <div class="summary-item"><div class="val"><?= count($rows) ?></div><div class="lbl">Total Records</div></div>
  <?php if ($report === 'assets' || $report === 'depreciation'): ?>
  <?php $totalCost = array_sum(array_column($rows, 'purchase_cost')); ?>
  <div class="summary-item"><div class="val"><?= formatMoney($totalCost, $currency) ?></div><div class="lbl">Total Value</div></div>
  <?php endif; ?>
  <div class="summary-item"><div class="val"><?= date('d M Y') ?></div><div class="lbl">Report Date</div></div>
</div>

<div class="content">
  <table>
    <thead>
      <tr><?php foreach ($headers as $h) echo "<th>{$h}</th>"; ?></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row):
        $cells = $rowFn($row);
      ?>
      <tr><?php foreach ($cells as $cell) echo "<td>{$cell}</td>"; ?></tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
      <tr><td colspan="<?= count($headers) ?>" style="text-align:center;padding:20px;color:#94a3b8;">No records found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="footer">
  <span><?= e($company) ?> — Asset Management System</span>
  <span>Page 1 of 1 &nbsp;·&nbsp; Confidential</span>
</div>

</body>
</html>
