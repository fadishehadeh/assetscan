<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assetId  = (int)($_POST['asset_id'] ?? 0);
    $method   = trim($_POST['disposal_method'] ?? '');
    $reason   = trim($_POST['reason'] ?? '');
    $requestedBy = (int)$_SESSION['user_id'];

    $validMethods = ['donate','scrap','sell','recycle','other'];

    if (!$assetId) $errors[] = 'Please select an asset.';
    if (!in_array($method, $validMethods)) $errors[] = 'Please select a valid disposal method.';
    if (mb_strlen($reason) < 20) $errors[] = 'Reason must be at least 20 characters.';

    // Check no pending/approved disposal for same asset
    if (!$errors) {
        $chk = $pdo->prepare(
            "SELECT id FROM disposal_requests WHERE asset_id = ? AND status IN ('pending','approved')"
        );
        $chk->execute([$assetId]);
        if ($chk->fetch()) $errors[] = 'This asset already has an active or approved disposal request.';
    }

    if (!$errors) {
        $ins = $pdo->prepare(
            "INSERT INTO disposal_requests (asset_id, requested_by, status, reason, disposal_method, requested_at)
             VALUES (?, ?, 'pending', ?, ?, NOW())"
        );
        $ins->execute([$assetId, $requestedBy, $reason, $method]);
        $newId = (int)$pdo->lastInsertId();

        auditLog($pdo, 'disposal_request', 'disposal_requests', $newId, null, [
            'asset_id' => $assetId,
            'method'   => $method,
            'reason'   => $reason,
        ]);

        setFlash('success', 'Disposal request submitted successfully.');
        header('Location: index.php');
        exit;
    }
}

$preAssetId = (int)($_GET['asset_id'] ?? 0);

// Assets eligible for disposal (not already disposed, no pending/approved disposal request)
$assetsStmt = $pdo->query(
    "SELECT a.id, a.asset_tag, a.name
     FROM assets a
     WHERE a.status != 'disposed'
       AND a.id NOT IN (
           SELECT asset_id FROM disposal_requests WHERE status IN ('pending','approved')
       )
     ORDER BY a.asset_tag"
);
$assets = $assetsStmt->fetchAll();

$pageTitle = 'Request Asset Disposal';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-trash3 me-2"></i>Request Asset Disposal</h1>
    <a href="index.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">

                <!-- Asset -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Asset <span class="text-danger">*</span></label>
                    <?php if (empty($assets)): ?>
                        <div class="alert alert-warning mb-0">No eligible assets found for disposal.</div>
                    <?php else: ?>
                    <select name="asset_id" class="form-select" required>
                        <option value="">— Select Asset —</option>
                        <?php foreach ($assets as $a): ?>
                            <option value="<?= $a['id'] ?>"
                                <?= ((int)($_POST['asset_id'] ?? $preAssetId) === (int)$a['id']) ? 'selected' : '' ?>>
                                <?= e($a['asset_tag']) ?> — <?= e($a['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>

                <!-- Disposal Method -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Disposal Method <span class="text-danger">*</span></label>
                    <select name="disposal_method" class="form-select" required>
                        <option value="">— Select Method —</option>
                        <option value="donate"  <?= ($_POST['disposal_method'] ?? '') === 'donate'  ? 'selected' : '' ?>>Donate</option>
                        <option value="scrap"   <?= ($_POST['disposal_method'] ?? '') === 'scrap'   ? 'selected' : '' ?>>Scrap</option>
                        <option value="sell"    <?= ($_POST['disposal_method'] ?? '') === 'sell'    ? 'selected' : '' ?>>Sell</option>
                        <option value="recycle" <?= ($_POST['disposal_method'] ?? '') === 'recycle' ? 'selected' : '' ?>>Recycle</option>
                        <option value="other"   <?= ($_POST['disposal_method'] ?? '') === 'other'   ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>

                <!-- Reason -->
                <div class="col-12">
                    <label class="form-label fw-semibold">
                        Reason <span class="text-danger">*</span>
                        <span class="text-muted fw-normal small">(minimum 20 characters)</span>
                    </label>
                    <textarea name="reason" id="reasonField" class="form-control" rows="5" required
                              minlength="20"
                              placeholder="Describe why this asset should be disposed (e.g. end of life, beyond repair, replaced by new equipment)..."><?= e($_POST['reason'] ?? '') ?></textarea>
                    <div class="form-text">
                        <span id="charCount">0</span> characters entered (minimum 20 required).
                    </div>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-danger" <?= empty($assets) ? 'disabled' : '' ?>>
                        <i class="bi bi-send me-1"></i>Submit Disposal Request
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const reasonField = document.getElementById('reasonField');
const charCount   = document.getElementById('charCount');
function updateCount() {
    charCount.textContent = reasonField.value.length;
    charCount.style.color = reasonField.value.length < 20 ? 'var(--bs-danger)' : 'inherit';
}
reasonField.addEventListener('input', updateCount);
updateCount();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
