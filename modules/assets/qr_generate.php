<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$id = (int)($_POST['id'] ?? 0);
if (!$id) { header('Location: /modules/assets/index.php'); exit; }

$asset = $pdo->prepare("SELECT id, asset_tag FROM assets WHERE id = ?");
$asset->execute([$id]);
$a = $asset->fetch();

if (!$a) { header('Location: /modules/assets/index.php'); exit; }

$path = generateQRCode($a['id'], $a['asset_tag'], true);

$pdo->prepare("UPDATE assets SET qr_code_path = ? WHERE id = ?")
    ->execute([$path, $a['id']]);

auditLog($pdo, 'qr_generated', 'assets', $a['id'], null, ['asset_tag' => $a['asset_tag']]);
setFlash('success', 'QR code generated for ' . $a['asset_tag']);
header('Location: /modules/assets/view.php?id=' . $id);
exit;
