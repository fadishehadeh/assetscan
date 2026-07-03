<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
$pageTitle = 'Maintenance Schedule';

// Handle "Run Alerts Now" POST (admin only)
$alertOutput = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_alerts']) && isAdmin()) {
    $scriptPath = realpath(__DIR__ . '/../../cron/send_alerts.php');
    if ($scriptPath && file_exists($scriptPath)) {
        $cmd = 'php ' . escapeshellarg($scriptPath) . ' 2>&1';
        $alertOutput = shell_exec($cmd);
        if ($alertOutput === null) {
            $alertOutput = 'Alert script executed (no output returned).';
        }
    } else {
        $alertOutput = 'Error: send_alerts.php not found.';
    }
}

// Query scheduled maintenance with next_due_date set, joined with assets
$records = $pdo->query("
    SELECT m.id, m.type, m.notes, m.next_due_date,
           m.is_recurring, m.interval_months,
           a.id AS asset_id, a.asset_tag, a.name AS asset_name,
           DATEDIFF(m.next_due_date, CURDATE()) AS days_until_due,
           DATE_FORMAT(m.next_due_date, '%Y-%m') AS month_key,
           DATE_FORMAT(m.next_due_date, '%M %Y') AS month_label
    FROM maintenance m
    JOIN assets a ON a.id = m.asset_id
    WHERE m.next_due_date IS NOT NULL
    ORDER BY m.next_due_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Group by month
$grouped = [];
foreach ($records as $row) {
    $grouped[$row['month_key']]['label']   = $row['month_label'];
    $grouped[$row['month_key']]['records'][] = $row;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
  <div>
    <h2><i class="bi bi-calendar-check me-2 text-primary"></i>Maintenance Schedule</h2>
    <p class="text-muted mb-0">Upcoming scheduled maintenance tasks grouped by month.</p>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <?php if (isAdmin()): ?>
    <form method="post" class="d-inline" onsubmit="this.querySelector('button').disabled=true;">
      <button type="submit" name="run_alerts" value="1" class="btn btn-outline-warning btn-sm">
        <i class="bi bi-bell me-1"></i>Run Alerts Now
      </button>
    </form>
    <?php endif; ?>
    <a href="/asset-manager/modules/maintenance/index.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i>All Maintenance
    </a>
  </div>
</div>

<?php if ($alertOutput !== ''): ?>
<div class="alert alert-info d-flex gap-2 align-items-start mt-2">
  <i class="bi bi-terminal fs-5 mt-1"></i>
  <div>
    <strong>Alert Script Output:</strong><br>
    <code><?= htmlspecialchars($alertOutput) ?></code>
  </div>
</div>
<?php endif; ?>

<?php if (empty($grouped)): ?>
<div class="card">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-calendar-x fs-1 d-block mb-3"></i>
    <p class="mb-0">No upcoming scheduled maintenance found.</p>
  </div>
</div>
<?php else: ?>

<?php foreach ($grouped as $monthKey => $group): ?>
<div class="mb-4">
  <h5 class="text-muted fw-semibold mb-3 d-flex align-items-center gap-2">
    <i class="bi bi-calendar3-range text-primary"></i>
    <?= htmlspecialchars($group['label']) ?>
    <span class="badge bg-primary ms-1"><?= count($group['records']) ?></span>
  </h5>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:130px;">Due Date</th>
            <th>Asset</th>
            <th>Type</th>
            <th>Technician</th>
            <th style="width:140px;">Days Until Due</th>
            <th style="width:80px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($group['records'] as $rec): ?>
          <?php
            $days = (int)$rec['days_until_due'];
            if ($days < 0) {
                $badgeClass = 'bg-danger';
                $daysLabel  = 'Overdue (' . abs($days) . 'd)';
            } elseif ($days <= 7) {
                $badgeClass = 'bg-warning text-dark';
                $daysLabel  = $days . ' day' . ($days == 1 ? '' : 's');
            } else {
                $badgeClass = 'bg-success';
                $daysLabel  = $days . ' days';
            }
            $typeLabel = ucwords(str_replace('_', ' ', $rec['type']));
          ?>
          <tr>
            <td>
              <span class="fw-semibold"><?= htmlspecialchars(date('d M Y', strtotime($rec['next_due_date']))) ?></span>
              <?php if ($rec['is_recurring']): ?>
              <br><span class="badge bg-secondary" style="font-size:.7rem;">
                <i class="bi bi-arrow-repeat me-1"></i>Every <?= (int)$rec['interval_months'] ?>mo
              </span>
              <?php endif; ?>
            </td>
            <td>
              <a href="/asset-manager/modules/assets/view.php?id=<?= (int)$rec['asset_id'] ?>" class="text-decoration-none fw-semibold">
                <?= htmlspecialchars($rec['asset_name']) ?>
              </a>
              <br>
              <code class="text-muted small"><?= htmlspecialchars($rec['asset_tag']) ?></code>
            </td>
            <td>
              <span class="badge bg-light text-dark border"><?= htmlspecialchars($typeLabel) ?></span>
            </td>
            <td>
              <?php if (!empty($rec['notes'])): ?>
                <i class="bi bi-chat-text me-1 text-muted"></i><?= htmlspecialchars(mb_substr($rec['notes'], 0, 60)) ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?= $badgeClass ?> px-3 py-2"><?= htmlspecialchars($daysLabel) ?></span>
            </td>
            <td>
              <a href="/asset-manager/modules/maintenance/view.php?id=<?= (int)$rec['id'] ?>"
                 class="btn btn-sm btn-outline-secondary py-0 px-2">
                <i class="bi bi-eye"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
