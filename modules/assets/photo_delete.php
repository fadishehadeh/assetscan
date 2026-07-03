<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

if (!isAdmin()) {
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

$assetId   = (int)$photo['asset_id'];
$wasPrimary = (int)$photo['is_primary'] === 1;

// Delete the file from disk
$filePath = __DIR__ . '/../../' . $photo['file_path'];
if (file_exists($filePath)) {
    unlink($filePath);
}

// Delete the DB record
$del = $pdo->prepare("DELETE FROM asset_photos WHERE id = ?");
$del->execute([$photoId]);

// If deleted photo was primary, promote the oldest remaining photo
if ($wasPrimary) {
    $promote = $pdo->prepare(
        "UPDATE asset_photos SET is_primary = 1
         WHERE asset_id = ?
         ORDER BY uploaded_at ASC
         LIMIT 1"
    );
    $promote->execute([$assetId]);
}

auditLog($pdo, 'delete_photo', 'asset_photos', $photoId, $photo, null);

setFlash('success', 'Photo deleted.');
header("Location: view.php?id={$assetId}#photos");
exit;
