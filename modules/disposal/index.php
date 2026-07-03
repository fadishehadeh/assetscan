<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$stmt = $pdo->query(
    "SELECT dr.id, dr.status, dr.reason, dr.disposal_method, dr.rejection_note,
            dr.certificate_no, dr.requested_at, dr.resolved_at,
            a.asset_tag, a.name AS asset_name,
            ru.name AS requested_by_name,
            au.name AS approved_by_name
     FROM disposal_requests dr
     JOIN assets a        ON dr.asset_id    = a.id
     LEFT JOIN users ru   ON dr.requested_by = ru.id
     LEFT JOIN users au   ON dr.approved_by  = au.id
     ORDER BY dr.requested_at DESC"
);
$disposals = $stmt->fetchAll();

$pageTitle = 'Disposal Requests';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-trash3 me-2"></i>Disposal Requests</h1>
    <a href="request.php" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Request Disposal
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($disposals)): ?>
            <div class="text-center text-muted py-5">No disposal requests found.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Asset Tag</th>
                        <th>Name</th>
                        <th>Requested By</th>
                        <th>Method</th>
                        <th>Reason</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($disposals as $d): ?>
                    <?php
                        $statusBadge = match($d['status']) {
                            'approved' => '<span class="badge bg-success">Approved</span>',
                            'rejected' => '<span class="badge bg-danger">Rejected</span>',
                            default    => '<span class="badge bg-warning text-dark">Pending</span>',
                        };
                        $methodLabel = ucfirst($d['disposal_method'] ?? '—');
                        $reasonShort = mb_strlen($d['reason']) > 60 ? mb_substr($d['reason'], 0, 57) . '...' : $d['reason'];
                    ?>
                    <tr>
                        <td><?= e($d['asset_tag']) ?></td>
                        <td><?= e($d['asset_name']) ?></td>
                        <td><?= e($d['requested_by_name'] ?? '—') ?></td>
                        <td><span class="badge bg-secondary"><?= e($methodLabel) ?></span></td>
                        <td title="<?= e($d['reason']) ?>"><?= e($reasonShort) ?></td>
                        <td><?= date('d M Y', strtotime($d['requested_at'])) ?></td>
                        <td>
                            <?= $statusBadge ?>
                            <?php if ($d['status'] === 'rejected' && $d['rejection_note']): ?>
                                <i class="bi bi-info-circle text-danger ms-1"
                                   title="<?= e($d['rejection_note']) ?>"
                                   data-bs-toggle="tooltip"></i>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($d['status'] === 'pending' && isAdmin()): ?>
                                <a href="approve.php?id=<?= $d['id'] ?>&action=approve"
                                   class="btn btn-sm btn-success me-1"
                                   onclick="return confirm('Approve this disposal request?')">
                                    <i class="bi bi-check-lg"></i>
                                </a>
                                <a href="approve.php?id=<?= $d['id'] ?>&action=reject"
                                   class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                            <?php elseif ($d['status'] === 'approved' && $d['certificate_no']): ?>
                                <a href="certificate.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                    <i class="bi bi-file-earmark-text me-1"></i>Certificate
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
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
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        new bootstrap.Tooltip(el);
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
