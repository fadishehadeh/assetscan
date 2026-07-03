<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireRole('super_admin', 'admin');

$id = (int)($_GET['id'] ?? 0);
if ($id && $id !== (int)$_SESSION['user_id']) {
    $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id=?")->execute([$id]);
    setFlash('success', 'User status updated.');
}
header('Location: index.php');
exit;
