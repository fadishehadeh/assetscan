<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

if (!isAdmin() && !isIT()) {
    setFlash('danger', 'Access denied.');
    header('Location: index.php'); exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$src = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
$src->execute([$id]);
$a = $src->fetch();
if (!$a) { setFlash('danger', 'Asset not found.'); header('Location: index.php'); exit; }

$newTag = generateAssetTag($pdo);

$pdo->prepare(
    "INSERT INTO assets (asset_tag, name, category_id, brand, model, serial_number,
     purchase_date, purchase_cost, vendor, warranty_expiry, useful_life_years,
     salvage_value, depreciation_method, status, location_id, department_id,
     assigned_to, notes, created_by)
     VALUES (?,?,?,?,?,NULL,?,?,?,?,?,?,?,?,?,?,NULL,?,?)"
)->execute([
    $newTag,
    $a['name'] . ' (Copy)',
    $a['category_id'],
    $a['brand'],
    $a['model'],
    $a['purchase_date'],
    $a['purchase_cost'],
    $a['vendor'],
    $a['warranty_expiry'],
    $a['useful_life_years'],
    $a['salvage_value'],
    $a['depreciation_method'],
    'active',
    $a['location_id'],
    $a['department_id'],
    $a['notes'],
    currentUser()['id'],
]);

$newId = (int)$pdo->lastInsertId();
generateQRCode($newId, $newTag);

// Copy custom field values
$cfVals = $pdo->prepare("SELECT field_id, value FROM custom_field_values WHERE asset_id = ?");
$cfVals->execute([$id]);
foreach ($cfVals->fetchAll() as $cfv) {
    $pdo->prepare("INSERT INTO custom_field_values (asset_id, field_id, value) VALUES (?,?,?)")
        ->execute([$newId, $cfv['field_id'], $cfv['value']]);
}

auditLog($pdo, 'cloned', 'assets', $newId, null, ['cloned_from' => $id, 'asset_tag' => $newTag]);
setFlash('success', "Asset cloned as <strong>{$newTag}</strong>. Review and update the details below.");
header("Location: edit.php?id={$newId}"); exit;
