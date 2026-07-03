<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$defaultWidgets = [
    'stat_total', 'stat_active', 'stat_maintenance', 'stat_value',
    'alert_warranty', 'alert_eol',
    'recent_assets', 'by_category',
    'chart_status', 'chart_category', 'chart_monthly',
];

$availableWidgets = $defaultWidgets; // same set

$action = $_POST['action'] ?? '';
$userId = (int)($user['id'] ?? 0);

if ($action === 'save') {
    $raw = $_POST['widgets'] ?? '[]';
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid widgets data']);
        exit;
    }
    // Filter to only known widget keys
    $filtered = array_values(array_filter($decoded, fn($w) => in_array($w, $availableWidgets)));
    $widgetsJson = json_encode($filtered);

    // Upsert
    $exists = $pdo->prepare("SELECT id FROM user_dashboard_prefs WHERE user_id = ?");
    $exists->execute([$userId]);
    if ($exists->fetchColumn()) {
        $pdo->prepare("UPDATE user_dashboard_prefs SET widgets = ?, updated_at = NOW() WHERE user_id = ?")
            ->execute([$widgetsJson, $userId]);
    } else {
        $pdo->prepare("INSERT INTO user_dashboard_prefs (user_id, widgets, updated_at) VALUES (?, ?, NOW())")
            ->execute([$userId, $widgetsJson]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'load') {
    $row = $pdo->prepare("SELECT widgets FROM user_dashboard_prefs WHERE user_id = ?");
    $row->execute([$userId]);
    $data = $row->fetchColumn();
    $widgets = $data ? json_decode($data, true) : $defaultWidgets;
    if (!is_array($widgets)) {
        $widgets = $defaultWidgets;
    }
    echo json_encode(['widgets' => $widgets]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
