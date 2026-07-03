<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!isAdmin() && !isIT()) {
    setFlash('danger', 'Access denied.');
    header('Location: /asset-manager/index.php');
    exit;
}

// IT role filter: only IT-category assets
$roleJoin  = '';
$roleWhere = '';
if (!isAdmin() && isIT()) {
    $roleJoin  = " LEFT JOIN categories c2 ON a.category_id = c2.id ";
    $roleWhere = " AND c2.type IN ('IT','Networking','Software') ";
}

// Active checkouts
$activeStmt = $pdo->query(
    "SELECT ac.id, ac.checkout_at, ac.expected_return, ac.condition_out, ac.notes_out,
            a.id AS asset_id, a.asset_tag, a.name AS asset_name,
            u.name AS checked_out_to,
            cb.name AS checked_out_by
     FROM asset_checkouts ac
     JOIN assets a ON ac.asset_id = a.id
     $roleJoin
     JOIN users u  ON ac.user_id = u.id
     JOIN users cb ON ac.checked_out_by = cb.id
     WHERE ac.actual_return IS NULL
     $roleWhere
     ORDER BY ac.checkout_at DESC"
);
$activeCheckouts = $activeStmt->fetchAll();

// Recent history — last 30 returned
$historyStmt = $pdo->query(
    "SELECT ac.id, ac.checkout_at, ac.actual_return, ac.condition_out, ac.condition_in,
            a.asset_tag, a.name AS asset_name,
            u.name AS checked_out_to,
            ci.name AS checked_in_by_name
     FROM asset_checkouts ac
     JOIN assets a  ON ac.asset_id = a.id
     $roleJoin
     JOIN users u   ON ac.user_id = u.id
     LEFT JOIN users ci ON ac.checked_in_by = ci.id
     WHERE ac.actual_return IS NOT NULL
     $roleWhere
     ORDER BY ac.actual_return DESC
     LIMIT 30"
);
$history = $historyStmt->fetchAll();

$pageTitle = 'Asset Checkouts';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-box-arrow-right me-2"></i>Asset Checkouts</h1>
    <?php if (isAdmin() || isIT()): ?>
        <a href="checkout.php" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>New Checkout
        </a>
    <?php endif; ?>
</div>

<!-- Active Checkouts -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-clock-history text-warning"></i>
        <strong>Active Checkouts</strong>
        <span class="badge bg-warning text-dark ms-auto"><?= count($activeCheckouts) ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($activeCheckouts)): ?>
            <div class="text-center text-muted py-4">No active checkouts.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Asset Tag</th>
                        <th>Asset Name</th>
                        <th>Checked Out To</th>
                        <th>Checked Out By</th>
                        <th>Since</th>
                        <th>Expected Return</th>
                        <th>Condition</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeCheckouts as $co): ?>
                    <?php
                        $overdue = $co['expected_return'] && strtotime($co['expected_return']) < time();
                    ?>
                    <tr class="<?= $overdue ? 'table-danger' : '' ?>">
                        <td><a href="/asset-manager/modules/assets/view.php?id=<?= $co['asset_id'] ?>"><?= e($co['asset_tag']) ?></a></td>
                        <td><?= e($co['asset_name']) ?></td>
                        <td><?= e($co['checked_out_to']) ?></td>
                        <td><?= e($co['checked_out_by']) ?></td>
                        <td><?= date('d M Y', strtotime($co['checkout_at'])) ?></td>
                        <td>
                            <?php if ($co['expected_return']): ?>
                                <?= date('d M Y', strtotime($co['expected_return'])) ?>
                                <?php if ($overdue): ?>
                                    <span class="badge bg-danger ms-1">Overdue</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $co['condition_out'] === 'good' ? 'success' : ($co['condition_out'] === 'fair' ? 'warning text-dark' : 'danger') ?>">
                                <?= ucfirst(e($co['condition_out'])) ?>
                            </span>
                        </td>
                        <td>
                            <a href="checkin.php?id=<?= $co['id'] ?>" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-box-arrow-in-left me-1"></i>Check In
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent History -->
<div class="card">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-clock text-secondary"></i>
        <strong>Recent Return History</strong>
        <span class="badge bg-secondary ms-auto">Last 30</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($history)): ?>
            <div class="text-center text-muted py-4">No return history yet.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Asset Tag</th>
                        <th>Asset Name</th>
                        <th>Checked Out To</th>
                        <th>Checked In By</th>
                        <th>Checked Out</th>
                        <th>Returned</th>
                        <th>Condition Out</th>
                        <th>Condition In</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?= e($h['asset_tag']) ?></td>
                        <td><?= e($h['asset_name']) ?></td>
                        <td><?= e($h['checked_out_to']) ?></td>
                        <td><?= e($h['checked_in_by_name'] ?? '—') ?></td>
                        <td><?= date('d M Y', strtotime($h['checkout_at'])) ?></td>
                        <td><?= date('d M Y', strtotime($h['actual_return'])) ?></td>
                        <td>
                            <span class="badge bg-<?= $h['condition_out'] === 'good' ? 'success' : ($h['condition_out'] === 'fair' ? 'warning text-dark' : 'danger') ?>">
                                <?= ucfirst(e($h['condition_out'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($h['condition_in']): ?>
                            <span class="badge bg-<?= $h['condition_in'] === 'good' ? 'success' : ($h['condition_in'] === 'fair' ? 'warning text-dark' : 'danger') ?>">
                                <?= ucfirst(e($h['condition_in'])) ?>
                            </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
