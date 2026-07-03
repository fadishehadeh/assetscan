<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('super_admin', 'admin');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$st = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
$st->execute([$id]);
$a = $st->fetch();

if ($a) {
    // Remove QR file
    if ($a['qr_code_path'] && file_exists(__DIR__ . '/../../' . $a['qr_code_path'])) {
        unlink(__DIR__ . '/../../' . $a['qr_code_path']);
    }
    auditLog($pdo, 'delete', 'assets', $id, $a);
    $pdo->prepare("DELETE FROM assets WHERE id = ?")->execute([$id]);
    setFlash('success', 'Asset "' . $a['name'] . '" deleted.');
} else {
    setFlash('danger', 'Asset not found.');
}

header('Location: index.php');
exit;
