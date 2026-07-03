<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$uid = (int)$_SESSION['user_id'];

// Role-based WHERE
// Super Admin / Admin: see all. IT: only their own requests.
$where  = '';
$params = [];
if (!isAdmin() && !isSuperAdmin()) {
    // IT (or any non-admin) sees only their own
    $where  = 'WHERE at.requested_by = ?';
    $params = [$uid];
}

$stmt = $pdo->prepare(
    "SELECT at.id, at.status, at.reason, at.rejection_note, at.requested_at, at.resolved_at,
            a.asset_tag, a.name AS asset_name,
            fd.name AS from_dept, td.name AS to_dept,
            fl.building AS from_building, fl.floor AS from_floor, fl.room AS from_room,
            tl.building AS to_building, tl.floor AS to_floor, tl.room AS to_room,
            ru.name AS requested_by_name,
            au.name AS approved_by_name
     FROM asset_transfers at
     JOIN assets a         ON at.asset_id       = a.id
     LEFT JOIN departments fd ON at.from_dept_id = fd.id
     LEFT JOIN departments td ON at.to_dept_id   = td.id
     LEFT JOIN locations fl   ON at.from_location_id = fl.id
     LEFT JOIN locations tl   ON at.to_location_id   = tl.id
     LEFT JOIN users ru       ON at.requested_by     = ru.id
     LEFT JOIN users au       ON at.approved_by      = au.id
     $where
     ORDER BY at.requested_at DESC"
);
$stmt->execute($params);
$transfers = $stmt->fetchAll();

$pageTitle = 'Asset Transfer Requests';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-arrow-left-right me-2"></i>Asset Transfer Requests</h1>
    <a href="request.php" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>New Transfer Request
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($transfers)): ?>
            <div class="text-center text-muted py-5">No transfer requests found.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Asset</th>
                        <th>From Department</th>
                        <th>To Department</th>
                        <th>Requested By</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transfers as $t): ?>
                    <?php
                        $statusBadge = match($t['status']) {
                            'approved' => '<span class="badge bg-success">Approved</span>',
                            'rejected' => '<span class="badge bg-danger">Rejected</span>',
                            default    => '<span class="badge bg-warning text-dark">Pending</span>',
                        };
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($t['asset_tag']) ?></strong><br>
                            <span class="text-muted small"><?= e($t['asset_name']) ?></span>
                        </td>
                        <td><?= e($t['from_dept'] ?? '—') ?></td>
                        <td><?= e($t['to_dept'] ?? '—') ?></td>
                        <td><?= e($t['requested_by_name'] ?? '—') ?></td>
                        <td><?= date('d M Y', strtotime($t['requested_at'])) ?></td>
                        <td>
                            <?= $statusBadge ?>
                            <?php if ($t['status'] === 'rejected' && $t['rejection_note']): ?>
                                <i class="bi bi-info-circle text-danger ms-1"
                                   title="<?= e($t['rejection_note']) ?>"
                                   data-bs-toggle="tooltip"></i>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($t['status'] === 'pending' && isAdmin()): ?>
                                <a href="approve.php?id=<?= $t['id'] ?>&action=approve"
                                   class="btn btn-sm btn-success me-1"
                                   onclick="return confirm('Approve this transfer?')">
                                    <i class="bi bi-check-lg"></i> Approve
                                </a>
                                <a href="approve.php?id=<?= $t['id'] ?>&action=reject"
                                   class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-x-lg"></i> Reject
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">
                                    <?= $t['resolved_at'] ? date('d M Y', strtotime($t['resolved_at'])) : '—' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Bootstrap tooltips
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        new bootstrap.Tooltip(el);
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
