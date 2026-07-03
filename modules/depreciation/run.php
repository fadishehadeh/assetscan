<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireRole('super_admin', 'admin');

$year   = (int)date('Y');
$assets = $pdo->query(
    "SELECT * FROM assets WHERE purchase_date IS NOT NULL AND purchase_cost > 0 AND status = 'active'"
)->fetchAll();

$count = 0;
foreach ($assets as $a) {
    $purchYear   = (int)date('Y', strtotime($a['purchase_date']));
    $assetYear   = $year - $purchYear + 1;
    if ($assetYear < 1 || $assetYear > $a['useful_life_years']) continue;

    $dep = calcDepreciation(
        (float)$a['purchase_cost'],
        (float)$a['salvage_value'],
        (int)$a['useful_life_years'],
        $a['depreciation_method'],
        $assetYear
    );

    $ins = $pdo->prepare(
        "INSERT INTO depreciation_log (asset_id, fiscal_year, dep_amount, book_value_start, book_value_end)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           dep_amount=VALUES(dep_amount),
           book_value_start=VALUES(book_value_start),
           book_value_end=VALUES(book_value_end),
           calculated_at=NOW()"
    );
    $ins->execute([$a['id'], $year, $dep['dep_amount'], $dep['book_value_start'], $dep['book_value_end']]);
    $count++;
}

auditLog($pdo, 'depreciation_run', 'depreciation_log', 0, null, ['year'=>$year,'count'=>$count]);
setFlash('success', "Depreciation calculated for {$count} assets (FY {$year}).");
header('Location: index.php');
exit;
