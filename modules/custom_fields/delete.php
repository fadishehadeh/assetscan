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
$st = $pdo->prepare("SELECT id, field_label, field_name FROM custom_fields WHERE id = ?");
$st->execute([$id]);
$field = $st->fetch();

if (!$field) {
    setFlash('danger', 'Custom field not found.');
    header('Location: index.php');
    exit;
}

// Delete all values for this field, then the field itself
$pdo->prepare("DELETE FROM custom_field_values WHERE field_id = ?")->execute([$id]);
$pdo->prepare("DELETE FROM custom_fields WHERE id = ?")->execute([$id]);

auditLog($pdo, 'delete', 'custom_fields', $id,
    ['field_name' => $field['field_name'], 'field_label' => $field['field_label']],
    null
);

setFlash('success', "Custom field <strong>{$field['field_label']}</strong> and all its values have been deleted.");
header('Location: index.php');
exit;
