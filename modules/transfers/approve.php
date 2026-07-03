<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!isAdmin()) {
    setFlash('danger', 'Access denied. Admins only.');
    header('Location: index.php');
    exit;
}

$id     = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

if (!$id || !in_array($action, ['approve', 'reject'])) {
    setFlash('danger', 'Invalid request.');
    header('Location: index.php');
    exit;
}

// Load transfer
$stmt = $pdo->prepare(
    "SELECT at.*, a.name AS asset_name, a.asset_tag
     FROM asset_transfers at
     JOIN assets a ON at.asset_id = a.id
     WHERE at.id = ? AND at.status = 'pending'"
);
$stmt->execute([$id]);
$transfer = $stmt->fetch();

if (!$transfer) {
    setFlash('danger', 'Transfer request not found or already resolved.');
    header('Location: index.php');
    exit;
}

$errors = [];

// Handle reject via POST (needs rejection note)
if ($action === 'reject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rejectionNote = trim($_POST['rejection_note'] ?? '');
    if (!$rejectionNote) {
        $errors[] = 'A rejection note is required.';
    }

    if (!$errors) {
        $upd = $pdo->prepare(
            "UPDATE asset_transfers
             SET status='rejected', approved_by=?, rejection_note=?, resolved_at=NOW()
             WHERE id=?"
        );
        $upd->execute([$_SESSION['user_id'], $rejectionNote, $id]);

        auditLog($pdo, 'transfer_rejected', 'asset_transfers', $id,
            ['status' => 'pending'],
            ['status' => 'rejected', 'rejection_note' => $rejectionNote]
        );

        setFlash('warning', 'Transfer request rejected.');
        header('Location: index.php');
        exit;
    }
}

// Handle approve via GET
if ($action === 'approve' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare(
            "UPDATE asset_transfers
             SET status='approved', approved_by=?, resolved_at=NOW()
             WHERE id=?"
        );
        $upd->execute([$_SESSION['user_id'], $id]);

        // Apply dept/location change to asset
        $updA = $pdo->prepare(
            "UPDATE assets SET department_id=?, location_id=? WHERE id=?"
        );
        $updA->execute([
            $transfer['to_dept_id'],
            $transfer['to_location_id'] ?: null,
            $transfer['asset_id'],
        ]);

        auditLog($pdo, 'transfer_approved', 'asset_transfers', $id,
            ['status' => 'pending'],
            ['status' => 'approved', 'to_dept_id' => $transfer['to_dept_id']]
        );

        $pdo->commit();
        setFlash('success', 'Transfer approved and asset location updated.');
        header('Location: index.php');
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        setFlash('danger', 'Error: ' . $e->getMessage());
        header('Location: index.php');
        exit;
    }
}

// Show rejection form if action=reject via GET
$pageTitle = 'Reject Transfer Request';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-x-circle me-2 text-danger"></i>Reject Transfer Request</h1>
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

<div class="card mb-4">
    <div class="card-header"><strong>Transfer Details</strong></div>
    <div class="card-body">
        <div class="row g-2">
            <div class="col-sm-4">
                <div class="text-muted small">Asset</div>
                <div><?= e($transfer['asset_tag']) ?> — <?= e($transfer['asset_name']) ?></div>
            </div>
            <div class="col-sm-4">
                <div class="text-muted small">Reason</div>
                <div><?= e($transfer['reason']) ?></div>
            </div>
            <div class="col-sm-4">
                <div class="text-muted small">Requested</div>
                <div><?= date('d M Y', strtotime($transfer['requested_at'])) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Rejection Note</strong></div>
    <div class="card-body">
        <form method="POST" action="approve.php?id=<?= $id ?>&action=reject">
            <div class="mb-3">
                <label class="form-label fw-semibold">Reason for Rejection <span class="text-danger">*</span></label>
                <textarea name="rejection_note" class="form-control" rows="4" required
                          placeholder="Explain why this transfer is being rejected..."><?= e($_POST['rejection_note'] ?? '') ?></textarea>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-x-lg me-1"></i>Reject Transfer
                </button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
