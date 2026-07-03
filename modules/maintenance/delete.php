<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('super_admin','admin');
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $pdo->prepare("DELETE FROM maintenance WHERE id=?")->execute([$id]);
    setFlash('success','Maintenance record deleted.');
}
header('Location: index.php'); exit;
