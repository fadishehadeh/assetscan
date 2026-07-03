<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

if (!isAdmin() && !isIT()) {
    setFlash('danger', 'Access denied.');
    header('Location: index.php');
    exit;
}

$photoId = (int)($_GET['id'] ?? 0);
if (!$photoId) {
    setFlash('danger', 'Invalid photo ID.');
    header('Location: index.php');
    exit;
}

// Fetch the photo record
$stmt = $pdo->prepare("SELECT * FROM asset_photos WHERE id = ?");
$stmt->execute([$photoId]);
$photo = $stmt->fetch();

if (!$photo) {
    setFlash('danger', 'Photo not found.');
    header('Location: index.php');
    exit;
}

$assetId = (int)$photo['asset_id'];

// Clear existing primary for this asset, then set the chosen one
$pdo->prepare("UPDATE asset_photos SET is_primary = 0 WHERE asset_id = ?")->execute([$assetId]);
$pdo->prepare("UPDATE asset_photos SET is_primary = 1 WHERE id = ?")->execute([$photoId]);

auditLog($pdo, 'set_primary_photo', 'asset_photos', $photoId, null, ['asset_id' => $assetId]);

setFlash('success', 'Primary photo updated.');
header("Location: view.php?id={$assetId}#photos");
exit;
