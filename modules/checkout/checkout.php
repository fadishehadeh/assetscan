<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!isAdmin() && !isIT()) {
    setFlash('danger', 'Access denied.');
    header('Location: /index.php');
    exit;
}

$assetId = (int)($_GET['asset_id'] ?? 0);
$errors  = [];

// POST: process checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assetId    = (int)($_POST['asset_id'] ?? 0);
    $userId     = (int)($_POST['user_id'] ?? 0);
    $expectedRet = trim($_POST['expected_return'] ?? '');
    $condition  = $_POST['condition'] ?? '';
    $notes      = trim($_POST['notes'] ?? '');
    $checkedOutBy = (int)$_SESSION['user_id'];

    if (!$assetId)   $errors[] = 'Please select an asset.';
    if (!$userId)    $errors[] = 'Please select a user to check out to.';
    if (!in_array($condition, ['good','fair','poor'])) $errors[] = 'Please select a valid condition.';

    // Check asset not already checked out
    if (!$errors) {
        $chk = $pdo->prepare("SELECT id FROM asset_checkouts WHERE asset_id = ? AND actual_return IS NULL");
        $chk->execute([$assetId]);
        if ($chk->fetch()) $errors[] = 'This asset is already checked out.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                "INSERT INTO asset_checkouts (asset_id, user_id, checked_out_by, checkout_at, expected_return, condition_out, notes_out)
                 VALUES (?, ?, ?, NOW(), ?, ?, ?)"
            );
            $ins->execute([
                $assetId, $userId, $checkedOutBy,
                $expectedRet ?: null,
                $condition,
                $notes ?: null,
            ]);
            $newId = (int)$pdo->lastInsertId();

            // Update asset assigned_to
            $upd = $pdo->prepare("UPDATE assets SET assigned_to = ?, status = 'active' WHERE id = ?");
            $upd->execute([$userId, $assetId]);

            auditLog($pdo, 'checkout', 'asset_checkouts', $newId, null, [
                'asset_id'  => $assetId,
                'user_id'   => $userId,
                'condition' => $condition,
            ]);

            $pdo->commit();
            setFlash('success', 'Asset checked out successfully.');
            header("Location: /modules/assets/view.php?id={$assetId}");
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Load asset if pre-selected
$asset = null;
if ($assetId) {
    $ast = $pdo->prepare(
        "SELECT a.id, a.asset_tag, a.name, d.name AS dept_name, l.building, l.floor, l.room
         FROM assets a
         LEFT JOIN departments d ON a.department_id = d.id
         LEFT JOIN locations l ON a.location_id = l.id
         WHERE a.id = ? AND a.status != 'disposed'"
    );
    $ast->execute([$assetId]);
    $asset = $ast->fetch();
}

// Available assets (not currently checked out, not disposed)
$assetsStmt = $pdo->query(
    "SELECT a.id, a.asset_tag, a.name
     FROM assets a
     WHERE a.status != 'disposed'
       AND a.id NOT IN (SELECT asset_id FROM asset_checkouts WHERE actual_return IS NULL)
     ORDER BY a.asset_tag"
);
$assets = $assetsStmt->fetchAll();

// Active users
$usersStmt = $pdo->query("SELECT id, name, email FROM users WHERE is_active = 1 ORDER BY name");
$users = $usersStmt->fetchAll();

$pageTitle = 'Check Out Asset';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-box-arrow-right me-2"></i>Check Out Asset</h1>
    <a href="index.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Checkouts
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
        <form method="POST" action="checkout.php">
            <div class="row g-3">

                <!-- Asset selector -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Asset <span class="text-danger">*</span></label>
                    <select name="asset_id" id="assetSelect" class="form-select" required>
                        <option value="">— Select Asset —</option>
                        <?php foreach ($assets as $a): ?>
                            <option value="<?= $a['id'] ?>"
                                <?= ((int)($asset['id'] ?? 0) === (int)$a['id'] || (int)($_POST['asset_id'] ?? 0) === (int)$a['id']) ? 'selected' : '' ?>>
                                <?= e($a['asset_tag']) ?> — <?= e($a['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- User selector -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Check Out To <span class="text-danger">*</span></label>
                    <select name="user_id" class="form-select" required>
                        <option value="">— Select User —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>"
                                <?= (int)($_POST['user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                                <?= e($u['name']) ?> &lt;<?= e($u['email']) ?>&gt;
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Expected Return -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Expected Return Date</label>
                    <input type="date" name="expected_return" class="form-control"
                           value="<?= e($_POST['expected_return'] ?? '') ?>"
                           min="<?= date('Y-m-d') ?>">
                    <div class="form-text">Leave blank if open-ended.</div>
                </div>

                <!-- Condition -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Condition at Checkout <span class="text-danger">*</span></label>
                    <select name="condition" class="form-select" required>
                        <option value="">— Select —</option>
                        <option value="good"  <?= ($_POST['condition'] ?? '') === 'good'  ? 'selected' : '' ?>>Good</option>
                        <option value="fair"  <?= ($_POST['condition'] ?? '') === 'fair'  ? 'selected' : '' ?>>Fair</option>
                        <option value="poor"  <?= ($_POST['condition'] ?? '') === 'poor'  ? 'selected' : '' ?>>Poor</option>
                    </select>
                </div>

                <!-- Notes -->
                <div class="col-12">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes about this checkout..."><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-box-arrow-right me-1"></i>Check Out
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
