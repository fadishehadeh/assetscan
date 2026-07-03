<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!isSuperAdmin()) {
    setFlash('danger', 'Access denied. Super Admins only.');
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

// Load disposal request
$stmt = $pdo->prepare(
    "SELECT dr.*, a.name AS asset_name, a.asset_tag
     FROM disposal_requests dr
     JOIN assets a ON dr.asset_id = a.id
     WHERE dr.id = ? AND dr.status = 'pending'"
);
$stmt->execute([$id]);
$disposal = $stmt->fetch();

if (!$disposal) {
    setFlash('danger', 'Disposal request not found or already resolved.');
    header('Location: index.php');
    exit;
}

$errors = [];

// POST: reject
if ($action === 'reject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rejectionNote = trim($_POST['rejection_note'] ?? '');
    if (!$rejectionNote) {
        $errors[] = 'A rejection note is required.';
    }

    if (!$errors) {
        $upd = $pdo->prepare(
            "UPDATE disposal_requests
             SET status='rejected', approved_by=?, rejection_note=?, resolved_at=NOW()
             WHERE id=?"
        );
        $upd->execute([$_SESSION['user_id'], $rejectionNote, $id]);

        auditLog($pdo, 'disposal_rejected', 'disposal_requests', $id,
            ['status' => 'pending'],
            ['status' => 'rejected', 'rejection_note' => $rejectionNote]
        );

        setFlash('warning', 'Disposal request rejected.');
        header('Location: index.php');
        exit;
    }
}

// GET: approve
if ($action === 'approve' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $pdo->beginTransaction();
    try {
        // Generate certificate number: DISP-YYYY-XXXXXX
        $certNo = 'DISP-' . date('Y') . '-' . str_pad($id, 6, '0', STR_PAD_LEFT);

        $upd = $pdo->prepare(
            "UPDATE disposal_requests
             SET status='approved', approved_by=?, certificate_no=?, resolved_at=NOW()
             WHERE id=?"
        );
        $upd->execute([$_SESSION['user_id'], $certNo, $id]);

        // Mark asset as disposed
        $updA = $pdo->prepare("UPDATE assets SET status='disposed', assigned_to=NULL WHERE id=?");
        $updA->execute([$disposal['asset_id']]);

        auditLog($pdo, 'disposal_approved', 'disposal_requests', $id,
            ['status' => 'pending'],
            ['status' => 'approved', 'certificate_no' => $certNo]
        );

        $pdo->commit();
        setFlash('success', "Disposal approved. Certificate: {$certNo}");
        header('Location: index.php');
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        setFlash('danger', 'Error: ' . $e->getMessage());
        header('Location: index.php');
        exit;
    }
}

// Show rejection form
$pageTitle = 'Reject Disposal Request';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-x-circle me-2 text-danger"></i>Reject Disposal Request</h1>
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
    <div class="card-header"><strong>Disposal Request Details</strong></div>
    <div class="card-body">
        <div class="row g-2">
            <div class="col-sm-4">
                <div class="text-muted small">Asset</div>
                <div><?= e($disposal['asset_tag']) ?> — <?= e($disposal['asset_name']) ?></div>
            </div>
            <div class="col-sm-4">
                <div class="text-muted small">Disposal Method</div>
                <div><?= ucfirst(e($disposal['disposal_method'])) ?></div>
            </div>
            <div class="col-sm-4">
                <div class="text-muted small">Requested</div>
                <div><?= date('d M Y', strtotime($disposal['requested_at'])) ?></div>
            </div>
            <div class="col-12">
                <div class="text-muted small">Reason</div>
                <div><?= e($disposal['reason']) ?></div>
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
                          placeholder="Explain why this disposal request is being rejected..."><?= e($_POST['rejection_note'] ?? '') ?></textarea>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-x-lg me-1"></i>Reject Request
                </button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
