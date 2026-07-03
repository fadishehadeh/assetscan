<?php
// ── Bootstrap ─────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!isIT()) {
    setFlash('danger', 'Access denied. Admin or IT role required.');
    header('Location: /asset-manager/modules/dashboard/index.php');
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('danger', 'Invalid request method.');
    header('Location: /asset-manager/modules/assets/index.php');
    exit;
}

$assetId  = (int)($_POST['asset_id'] ?? 0);
$docName  = trim($_POST['name']      ?? '');
$docType  = trim($_POST['doc_type']  ?? 'other');
$notes    = trim($_POST['notes']     ?? '');

// Validate asset exists
$stAsset = $pdo->prepare("SELECT id, name FROM assets WHERE id = ?");
$stAsset->execute([$assetId]);
$asset = $stAsset->fetch();
if (!$asset) {
    setFlash('danger', 'Asset not found.');
    header('Location: /asset-manager/modules/assets/index.php');
    exit;
}

$redirectBase = "/asset-manager/modules/assets/view.php?id={$assetId}#documents";

$errors = [];

if ($docName === '') $errors[] = 'Document name is required.';

$validDocTypes = ['invoice','warranty','manual','purchase_order','insurance','other'];
if (!in_array($docType, $validDocTypes, true)) $docType = 'other';

// File upload validation
$allowedMimes = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/png',
    'image/jpeg',
];
$allowedExts = ['pdf','doc','docx','xls','xlsx','png','jpg','jpeg'];
$maxSize     = 10 * 1024 * 1024; // 10 MB

if (!isset($_FILES['document']) || $_FILES['document']['error'] === UPLOAD_ERR_NO_FILE) {
    $errors[] = 'Please select a file to upload.';
} elseif ($_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    $errors[] = 'File upload error (code ' . $_FILES['document']['error'] . ').';
} else {
    $file    = $_FILES['document'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mime    = mime_content_type($file['tmp_name']);

    if (!in_array($ext, $allowedExts, true)) {
        $errors[] = 'File type not allowed. Accepted: PDF, DOC, DOCX, XLS, XLSX, PNG, JPG.';
    } elseif (!in_array($mime, $allowedMimes, true)) {
        $errors[] = 'File MIME type not permitted.';
    } elseif ($file['size'] > $maxSize) {
        $errors[] = 'File exceeds 10 MB limit.';
    }
}

if (!empty($errors)) {
    setFlash('danger', implode(' ', $errors));
    header('Location: ' . $redirectBase);
    exit;
}

// Save file
$uploadDir = __DIR__ . '/../../uploads/documents/asset_' . $assetId . '/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$safeBase  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
$filename  = date('Ymd_His') . '_' . $safeBase . '.' . $ext;
$destPath  = $uploadDir . $filename;
$dbPath    = 'uploads/documents/asset_' . $assetId . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    setFlash('danger', 'Failed to save uploaded file. Check directory permissions.');
    header('Location: ' . $redirectBase);
    exit;
}

// Insert DB record
$st = $pdo->prepare("INSERT INTO asset_documents
    (asset_id, name, file_path, file_size, mime_type, doc_type, uploaded_by, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$st->execute([
    $assetId,
    $docName,
    $dbPath,
    $file['size'],
    $mime,
    $docType,
    $_SESSION['user_id'],
    $notes ?: null,
]);
$docId = (int)$pdo->lastInsertId();

auditLog($pdo, 'upload', 'asset_documents', $docId, null, [
    'asset_id' => $assetId,
    'name'     => $docName,
    'doc_type' => $docType,
    'file'     => $filename,
]);

setFlash('success', "Document <strong>" . e($docName) . "</strong> uploaded successfully.");
header('Location: ' . $redirectBase);
exit;
