<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

if (!isAdmin() && !isIT()) {
    setFlash('danger', 'Access denied.');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$assetId = (int)($_POST['asset_id'] ?? 0);
if (!$assetId) {
    setFlash('danger', 'Invalid asset.');
    header('Location: index.php');
    exit;
}

// Verify asset exists
$assetCheck = $pdo->prepare("SELECT id FROM assets WHERE id = ?");
$assetCheck->execute([$assetId]);
if (!$assetCheck->fetch()) {
    setFlash('danger', 'Asset not found.');
    header('Location: index.php');
    exit;
}

// Count existing photos
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM asset_photos WHERE asset_id = ?");
$countStmt->execute([$assetId]);
$existingCount = (int)$countStmt->fetchColumn();

$uploadedFiles = $_FILES['photos'] ?? null;
if (!$uploadedFiles || empty($uploadedFiles['name'][0])) {
    setFlash('danger', 'No files selected.');
    header("Location: view.php?id={$assetId}#photos");
    exit;
}

// Normalize $_FILES['photos'] into an array of individual files
$fileCount = count($uploadedFiles['name']);
$newCount  = 0;
foreach ($uploadedFiles['error'] as $err) {
    if ($err === UPLOAD_ERR_OK) $newCount++;
}

if ($existingCount + $newCount > 5) {
    setFlash('danger', 'Maximum 5 photos per asset. Currently have ' . $existingCount . ', trying to add ' . $newCount . '.');
    header("Location: view.php?id={$assetId}#photos");
    exit;
}

$allowedExts  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$maxBytes     = 5 * 1024 * 1024; // 5 MB
$caption      = trim(substr($_POST['caption'] ?? '', 0, 200));

// Check if there is already a primary photo for this asset
$hasPrimary = $pdo->prepare("SELECT COUNT(*) FROM asset_photos WHERE asset_id = ? AND is_primary = 1");
$hasPrimary->execute([$assetId]);
$primaryExists = (int)$hasPrimary->fetchColumn() > 0;

$uploadDir = __DIR__ . '/../../uploads/photos/asset_' . $assetId . '/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$uploaded = 0;
$errors   = [];
$firstUpload = true;

for ($i = 0; $i < $fileCount; $i++) {
    if ($uploadedFiles['error'][$i] !== UPLOAD_ERR_OK) {
        continue;
    }

    $tmpPath  = $uploadedFiles['tmp_name'][$i];
    $origName = $uploadedFiles['name'][$i];
    $size     = $uploadedFiles['size'][$i];

    // Validate extension
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        $errors[] = "'{$origName}': invalid extension.";
        continue;
    }

    // Validate MIME
    $mime = mime_content_type($tmpPath);
    if (!in_array($mime, $allowedMimes, true)) {
        $errors[] = "'{$origName}': invalid file type ({$mime}).";
        continue;
    }

    // Validate size
    if ($size > $maxBytes) {
        $errors[] = "'{$origName}': exceeds 5 MB limit.";
        continue;
    }

    // Build a safe filename
    $safeName = uniqid('photo_', true) . '.' . $ext;
    $destPath = $uploadDir . $safeName;

    // Resize if width > 1600px using GD
    $resized = false;
    if (function_exists('imagecreatefromjpeg')) {
        try {
            $srcImg = null;
            if (in_array($mime, ['image/jpeg'], true)) {
                $srcImg = imagecreatefromjpeg($tmpPath);
            } elseif ($mime === 'image/png') {
                $srcImg = imagecreatefrompng($tmpPath);
            } elseif ($mime === 'image/webp') {
                $srcImg = imagecreatefromwebp($tmpPath);
            } elseif ($mime === 'image/gif') {
                $srcImg = imagecreatefromgif($tmpPath);
            }

            if ($srcImg !== null && $srcImg !== false) {
                $origWidth = imagesx($srcImg);
                if ($origWidth > 1600) {
                    $scaled = imagescale($srcImg, 1600);
                    if ($scaled !== false) {
                        if ($mime === 'image/png') {
                            imagepng($scaled, $destPath);
                        } elseif ($mime === 'image/gif') {
                            imagegif($scaled, $destPath);
                        } elseif ($mime === 'image/webp') {
                            imagewebp($scaled, $destPath, 88);
                        } else {
                            imagejpeg($scaled, $destPath, 88);
                        }
                        imagedestroy($scaled);
                        $resized = true;
                    }
                }
                imagedestroy($srcImg);
            }
        } catch (\Throwable $e) {
            // GD failed — fall back to plain copy
        }
    }

    if (!$resized) {
        if (!move_uploaded_file($tmpPath, $destPath)) {
            $errors[] = "'{$origName}': failed to save.";
            continue;
        }
    }

    $savedSize   = filesize($destPath);
    $relPath     = 'uploads/photos/asset_' . $assetId . '/' . $safeName;
    $isPrimary   = ($firstUpload && !$primaryExists) ? 1 : 0;
    $firstUpload = false;
    if ($isPrimary) $primaryExists = true;

    $ins = $pdo->prepare(
        "INSERT INTO asset_photos (asset_id, file_path, file_size, is_primary, caption, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $ins->execute([$assetId, $relPath, $savedSize, $isPrimary, $caption ?: null, $_SESSION['user_id']]);

    $uploaded++;
}

if ($uploaded > 0) {
    auditLog($pdo, 'upload_photos', 'asset_photos', $assetId, null, ['count' => $uploaded]);
    $msg = $uploaded . ' photo' . ($uploaded > 1 ? 's' : '') . ' uploaded successfully.';
    if ($errors) {
        $msg .= ' Some files were skipped: ' . implode('; ', $errors);
    }
    setFlash('success', $msg);
} else {
    $errMsg = 'No photos were uploaded.';
    if ($errors) $errMsg .= ' ' . implode('; ', $errors);
    setFlash('danger', $errMsg);
}

header("Location: view.php?id={$assetId}#photos");
exit;
