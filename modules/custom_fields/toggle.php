<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!isSuperAdmin()) {
    setFlash('danger', 'Access denied.');
    header('Location: /modules/dashboard/index.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT id, field_label, is_active FROM custom_fields WHERE id = ?");
$st->execute([$id]);
$field = $st->fetch();

if (!$field) {
    setFlash('danger', 'Custom field not found.');
    header('Location: index.php');
    exit;
}

$newState = $field['is_active'] ? 0 : 1;
$pdo->prepare("UPDATE custom_fields SET is_active = ? WHERE id = ?")->execute([$newState, $id]);
auditLog($pdo, 'toggle', 'custom_fields', $id,
    ['is_active' => $field['is_active']],
    ['is_active' => $newState]
);

$label = $newState ? 'activated' : 'deactivated';
setFlash('success', "Custom field <strong>{$field['field_label']}</strong> {$label}.");
header('Location: index.php');
exit;
