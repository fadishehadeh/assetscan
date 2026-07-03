<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$tag = trim($_GET['tag'] ?? '');

if ($tag === '') {
    echo json_encode(['found' => false, 'error' => 'No tag provided']);
    exit;
}

// Sanitise: keep only printable ASCII, strip null bytes
$tag = preg_replace('/[^\x20-\x7E]/', '', $tag);
$tag = substr($tag, 0, 200);

if ($tag === '') {
    echo json_encode(['found' => false, 'error' => 'Invalid tag']);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT a.id, a.asset_tag, a.name, a.status,
            d.name AS dept_name,
            u.name AS assigned_name,
            a.serial_number,
            c.name AS cat_name
     FROM assets a
     LEFT JOIN departments d ON a.department_id = d.id
     LEFT JOIN users u       ON a.assigned_to   = u.id
     LEFT JOIN categories c  ON a.category_id   = c.id
     WHERE a.asset_tag = ? OR a.serial_number = ?
     LIMIT 1"
);
$stmt->execute([$tag, $tag]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['found' => false]);
    exit;
}

echo json_encode([
    'found'        => true,
    'id'           => (int)$row['id'],
    'asset_tag'    => $row['asset_tag'],
    'name'         => $row['name'],
    'status'       => $row['status'],
    'dept'         => $row['dept_name']     ?? '',
    'assigned'     => $row['assigned_name'] ?? '',
    'cat'          => $row['cat_name']      ?? '',
    'serial'       => $row['serial_number'] ?? '',
    'view_url'     => '/modules/assets/view.php?id=' . (int)$row['id'],
]);
