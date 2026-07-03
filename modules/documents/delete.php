<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!isAdmin()) {
    setFlash('danger', 'Access denied. Admin role required.');
    header('Location: /asset-manager/modules/dashboard/index.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM asset_documents WHERE id = ?");
$st->execute([$id]);
$doc = $st->fetch();

if (!$doc) {
    setFlash('danger', 'Document not found.');
    header('Location: /asset-manager/modules/assets/index.php');
    exit;
}

$assetId      = (int)$doc['asset_id'];
$redirectBase = "/asset-manager/modules/assets/view.php?id={$assetId}#documents";

// Delete physical file
$filePath = __DIR__ . '/../../' . ltrim($doc['file_path'], '/');
if (file_exists($filePath)) {
    unlink($filePath);
}

// Delete DB record
$pdo->prepare("DELETE FROM asset_documents WHERE id = ?")->execute([$id]);

auditLog($pdo, 'delete', 'asset_documents', $id,
    ['name' => $doc['name'], 'file_path' => $doc['file_path'], 'asset_id' => $assetId],
    null
);

setFlash('success', "Document <strong>" . e($doc['name']) . "</strong> deleted.");
header('Location: ' . $redirectBase);
exit;
