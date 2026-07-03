<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

// Admin only for mutating actions; export allowed for any logged-in user
$action = $_POST['bulk_action'] ?? '';

// Collect and validate IDs
$rawIds = $_POST['ids'] ?? [];
if (!is_array($rawIds) || empty($rawIds)) {
    setFlash('warning', 'No assets selected.');
    header('Location: /asset-manager/modules/assets/index.php');
    exit;
}

// Cast to int and filter out zeros; cap at 500
$ids = array_values(array_filter(array_map('intval', $rawIds)));
if (empty($ids)) {
    setFlash('warning', 'No valid asset IDs selected.');
    header('Location: /asset-manager/modules/assets/index.php');
    exit;
}
if (count($ids) > 500) {
    $ids = array_slice($ids, 0, 500);
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

// ── Mutating actions require admin ────────────────────────────────────────────
if (in_array($action, ['assign_dept', 'change_status'], true) && !isAdmin()) {
    setFlash('danger', 'Only administrators can perform bulk updates.');
    header('Location: /asset-manager/modules/assets/index.php');
    exit;
}

// ── assign_dept ───────────────────────────────────────────────────────────────
if ($action === 'assign_dept') {
    $deptId = (int)($_POST['bulk_dept_id'] ?? 0);
    if ($deptId <= 0) {
        setFlash('danger', 'Please select a valid department.');
        header('Location: /asset-manager/modules/assets/index.php');
        exit;
    }

    // Verify department exists
    $deptCheck = $pdo->prepare("SELECT id FROM departments WHERE id = ?");
    $deptCheck->execute([$deptId]);
    if (!$deptCheck->fetch()) {
        setFlash('danger', 'Selected department does not exist.');
        header('Location: /asset-manager/modules/assets/index.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Fetch old values for audit
        $oldSt = $pdo->prepare("SELECT id, department_id FROM assets WHERE id IN ($placeholders)");
        $oldSt->execute($ids);
        $oldRows = $oldSt->fetchAll(PDO::FETCH_KEY_PAIR); // id => department_id

        $upSt = $pdo->prepare("UPDATE assets SET department_id = ? WHERE id IN ($placeholders)");
        $upSt->execute(array_merge([$deptId], $ids));

        foreach ($ids as $assetId) {
            auditLog($pdo, 'bulk_assign_dept', 'assets', $assetId,
                ['department_id' => $oldRows[$assetId] ?? null],
                ['department_id' => $deptId]
            );
        }

        $pdo->commit();
        setFlash('success', count($ids) . ' asset(s) assigned to new department.');
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('danger', 'Bulk update failed: ' . $e->getMessage());
    }

    header('Location: /asset-manager/modules/assets/index.php');
    exit;
}

// ── change_status ─────────────────────────────────────────────────────────────
if ($action === 'change_status') {
    $validStatuses = ['active', 'under_maintenance', 'lost', 'disposed'];
    $newStatus = $_POST['bulk_status'] ?? '';
    if (!in_array($newStatus, $validStatuses, true)) {
        setFlash('danger', 'Please select a valid status.');
        header('Location: /asset-manager/modules/assets/index.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Fetch old values for audit
        $oldSt = $pdo->prepare("SELECT id, status FROM assets WHERE id IN ($placeholders)");
        $oldSt->execute($ids);
        $oldRows = $oldSt->fetchAll(PDO::FETCH_KEY_PAIR); // id => status

        $upSt = $pdo->prepare("UPDATE assets SET status = ? WHERE id IN ($placeholders)");
        $upSt->execute(array_merge([$newStatus], $ids));

        foreach ($ids as $assetId) {
            auditLog($pdo, 'bulk_change_status', 'assets', $assetId,
                ['status' => $oldRows[$assetId] ?? null],
                ['status' => $newStatus]
            );
        }

        $pdo->commit();
        setFlash('success', count($ids) . ' asset(s) updated to status "' . ucwords(str_replace('_', ' ', $newStatus)) . '".');
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('danger', 'Bulk status update failed: ' . $e->getMessage());
    }

    header('Location: /asset-manager/modules/assets/index.php');
    exit;
}

// ── export_csv ────────────────────────────────────────────────────────────────
if ($action === 'export_csv') {
    $st = $pdo->prepare(
        "SELECT a.asset_tag, a.name, c.name AS category, a.brand, a.model,
                a.serial_number, a.purchase_date, a.purchase_cost, a.status,
                d.name AS department, u.name AS assigned_to
         FROM assets a
         LEFT JOIN categories c ON a.category_id = c.id
         LEFT JOIN departments d ON a.department_id = d.id
         LEFT JOIN users u ON a.assigned_to = u.id
         WHERE a.id IN ($placeholders)
         ORDER BY a.asset_tag"
    );
    $st->execute($ids);
    $rows = $st->fetchAll();

    $filename = 'assets_export_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Asset Tag', 'Name', 'Category', 'Brand', 'Model', 'Serial Number',
                   'Purchase Date', 'Purchase Cost', 'Status', 'Department', 'Assigned To']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['asset_tag'],
            $r['name'],
            $r['category'] ?? '',
            $r['brand'] ?? '',
            $r['model'] ?? '',
            $r['serial_number'] ?? '',
            $r['purchase_date'] ?? '',
            $r['purchase_cost'] ?? '',
            $r['status'],
            $r['department'] ?? '',
            $r['assigned_to'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ── export_pdf ────────────────────────────────────────────────────────────────
if ($action === 'export_pdf') {
    $st = $pdo->prepare(
        "SELECT a.asset_tag, a.name, c.name AS category, a.brand, a.model,
                a.serial_number, a.purchase_date, a.purchase_cost, a.status,
                d.name AS department, u.name AS assigned_to
         FROM assets a
         LEFT JOIN categories c ON a.category_id = c.id
         LEFT JOIN departments d ON a.department_id = d.id
         LEFT JOIN users u ON a.assigned_to = u.id
         WHERE a.id IN ($placeholders)
         ORDER BY a.asset_tag"
    );
    $st->execute($ids);
    $rows = $st->fetchAll();

    $settings = getSettings($pdo);
    $currency = $settings['currency'] ?? 'USD';
    $company  = $settings['company_name'] ?? 'Company';
    $primary  = $settings['primary_color'] ?? '#E84B37';
    $totalCost = array_sum(array_column($rows, 'purchase_cost'));
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Selected Assets Export</title>
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
  <button onclick="window.print()">&#128438; Print / Save as PDF</button>
  <a href="javascript:history.back()">&#8592; Back</a>
</div>

<div class="report-header">
  <div>
    <h1>Selected Assets Export</h1>
    <div class="meta"><?= e($company) ?> &nbsp;&middot;&nbsp; Generated: <?= date('d M Y, H:i') ?> &nbsp;&middot;&nbsp; By: <?= e($_SESSION['user_name'] ?? '') ?></div>
  </div>
  <img src="/asset-manager/<?= e($settings['logo_path'] ?? 'assets/img/logo.png') ?>" class="logo" alt="">
</div>

<div class="summary">
  <div class="summary-item"><div class="val"><?= count($rows) ?></div><div class="lbl">Total Records</div></div>
  <div class="summary-item"><div class="val"><?= formatMoney($totalCost, $currency) ?></div><div class="lbl">Total Value</div></div>
  <div class="summary-item"><div class="val"><?= date('d M Y') ?></div><div class="lbl">Report Date</div></div>
</div>

<div class="content">
  <table>
    <thead>
      <tr>
        <th>Tag</th><th>Name</th><th>Category</th><th>Brand</th><th>Model</th>
        <th>Serial</th><th>Purchase Date</th><th>Cost</th><th>Status</th>
        <th>Department</th><th>Assigned To</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['asset_tag']) ?></td>
        <td><?= e($r['name']) ?></td>
        <td><?= e($r['category'] ?? '—') ?></td>
        <td><?= e($r['brand'] ?? '—') ?></td>
        <td><?= e($r['model'] ?? '—') ?></td>
        <td><?= e($r['serial_number'] ?? '—') ?></td>
        <td><?= $r['purchase_date'] ? date('d M Y', strtotime($r['purchase_date'])) : '—' ?></td>
        <td><?= formatMoney((float)$r['purchase_cost'], $currency) ?></td>
        <td><?= ucwords(str_replace('_', ' ', $r['status'])) ?></td>
        <td><?= e($r['department'] ?? '—') ?></td>
        <td><?= e($r['assigned_to'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
      <tr><td colspan="11" style="text-align:center;padding:20px;color:#94a3b8;">No records found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="footer">
  <span><?= e($company) ?> — Asset Management System</span>
  <span>Page 1 of 1 &nbsp;&middot;&nbsp; Confidential</span>
</div>

</body>
</html>
<?php
    exit;
}

// ── Unknown action ────────────────────────────────────────────────────────────
setFlash('warning', 'Unknown bulk action.');
header('Location: /asset-manager/modules/assets/index.php');
exit;
