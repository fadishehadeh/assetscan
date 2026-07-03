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

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setFlash('danger', 'Invalid checkout record.');
    header('Location: index.php');
    exit;
}

// Load checkout record
$stmt = $pdo->prepare(
    "SELECT ac.*, a.id AS asset_id, a.asset_tag, a.name AS asset_name,
            u.name AS user_name, cb.name AS checked_out_by_name
     FROM asset_checkouts ac
     JOIN assets a  ON ac.asset_id = a.id
     JOIN users u   ON ac.user_id  = u.id
     JOIN users cb  ON ac.checked_out_by = cb.id
     WHERE ac.id = ? AND ac.actual_return IS NULL"
);
$stmt->execute([$id]);
$co = $stmt->fetch();

if (!$co) {
    setFlash('danger', 'Checkout record not found or already returned.');
    header('Location: index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conditionIn = $_POST['condition_in'] ?? '';
    $notesIn     = trim($_POST['notes_in'] ?? '');
    $checkedInBy = (int)$_SESSION['user_id'];

    if (!in_array($conditionIn, ['good','fair','poor'])) {
        $errors[] = 'Please select a valid return condition.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare(
                "UPDATE asset_checkouts
                 SET actual_return = NOW(), condition_in = ?, notes_in = ?, checked_in_by = ?
                 WHERE id = ?"
            );
            $upd->execute([$conditionIn, $notesIn ?: null, $checkedInBy, $id]);

            // Clear assignment on asset
            $updA = $pdo->prepare("UPDATE assets SET assigned_to = NULL WHERE id = ?");
            $updA->execute([$co['asset_id']]);

            auditLog($pdo, 'checkin', 'asset_checkouts', $id, null, [
                'condition_in' => $conditionIn,
                'checked_in_by' => $checkedInBy,
            ]);

            $pdo->commit();
            setFlash('success', 'Asset "' . $co['asset_name'] . '" checked in successfully.');
            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Check In Asset';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-box-arrow-in-left me-2"></i>Check In Asset</h1>
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

<!-- Checkout summary -->
<div class="card mb-4">
    <div class="card-header"><strong>Checkout Summary</strong></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-sm-4">
                <div class="text-muted small">Asset</div>
                <div class="fw-semibold"><?= e($co['asset_tag']) ?> — <?= e($co['asset_name']) ?></div>
            </div>
            <div class="col-sm-4">
                <div class="text-muted small">Checked Out To</div>
                <div class="fw-semibold"><?= e($co['user_name']) ?></div>
            </div>
            <div class="col-sm-4">
                <div class="text-muted small">Checked Out By</div>
                <div class="fw-semibold"><?= e($co['checked_out_by_name']) ?></div>
            </div>
            <div class="col-sm-4">
                <div class="text-muted small">Checkout Date</div>
                <div><?= date('d M Y H:i', strtotime($co['checkout_at'])) ?></div>
            </div>
            <div class="col-sm-4">
                <div class="text-muted small">Expected Return</div>
                <div><?= $co['expected_return'] ? date('d M Y', strtotime($co['expected_return'])) : '—' ?></div>
            </div>
            <div class="col-sm-4">
                <div class="text-muted small">Condition at Checkout</div>
                <span class="badge bg-<?= $co['condition_out'] === 'good' ? 'success' : ($co['condition_out'] === 'fair' ? 'warning text-dark' : 'danger') ?>">
                    <?= ucfirst(e($co['condition_out'])) ?>
                </span>
            </div>
            <?php if ($co['notes_out']): ?>
            <div class="col-12">
                <div class="text-muted small">Checkout Notes</div>
                <div><?= e($co['notes_out']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Check-in form -->
<div class="card">
    <div class="card-header"><strong>Return Details</strong></div>
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Condition on Return <span class="text-danger">*</span></label>
                    <select name="condition_in" class="form-select" required>
                        <option value="">— Select —</option>
                        <option value="good" <?= ($_POST['condition_in'] ?? '') === 'good' ? 'selected' : '' ?>>Good</option>
                        <option value="fair" <?= ($_POST['condition_in'] ?? '') === 'fair' ? 'selected' : '' ?>>Fair</option>
                        <option value="poor" <?= ($_POST['condition_in'] ?? '') === 'poor' ? 'selected' : '' ?>>Poor</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Return Notes</label>
                    <textarea name="notes_in" class="form-control" rows="3"
                              placeholder="Note any damage, missing parts, or other observations..."><?= e($_POST['notes_in'] ?? '') ?></textarea>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-box-arrow-in-left me-1"></i>Confirm Check In
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
