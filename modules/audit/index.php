<?php
$pageTitle = 'Audit Log';
require_once __DIR__ . '/../../includes/header.php';
requireRole('super_admin','admin');

$page    = max(1,(int)($_GET['page'] ?? 1));
$perPage = 50;
$search  = trim($_GET['search'] ?? '');

$where  = "WHERE 1=1";
$params = [];
if ($search) {
    $where .= " AND (u.name LIKE ? OR al.action LIKE ? OR al.table_name LIKE ?)";
    $params = array_merge($params, ["%$search%","%$search%","%$search%"]);
}

$total = $pdo->prepare("SELECT COUNT(*) FROM audit_log al LEFT JOIN users u ON al.user_id=u.id $where");
$total->execute($params);
$total = (int)$total->fetchColumn();
$pg    = paginate($total, $perPage, $page);

$st = $pdo->prepare(
    "SELECT al.*, u.name AS user_name, u.role AS user_role
     FROM audit_log al
     LEFT JOIN users u ON al.user_id = u.id
     $where
     ORDER BY al.created_at DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}"
);
$st->execute($params);
$logs = $st->fetchAll();

$actionColors = [
    'create'           => 'success',
    'update'           => 'primary',
    'delete'           => 'danger',
    'depreciation_run' => 'info',
    'branding_update'  => 'warning',
    'login'            => 'secondary',
];
?>

<div class="page-header d-flex justify-content-between align-items-center">
  <div><h2><i class="bi bi-shield-check me-2" style="color:var(--brand-primary)"></i>Audit Log</h2>
  <p>Full record of all system actions. <strong><?= number_format($total) ?></strong> entries total.</p></div>
</div>

<!-- Filter -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="d-flex gap-2">
      <input type="search" name="search" class="form-control form-control-sm" placeholder="Search user, action, table…" value="<?= e($search) ?>" style="max-width:320px;">
      <button type="submit" class="btn btn-sm btn-primary">Filter</button>
      <?php if ($search): ?><a href="?" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>When</th><th>User</th><th>Action</th><th>Table</th><th>Record</th><th>IP</th><th>Changes</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $log):
          $color  = $actionColors[$log['action']] ?? 'secondary';
          $label  = ucwords(str_replace('_',' ',$log['action']));
        ?>
        <tr>
          <td class="text-muted small" style="white-space:nowrap;"><?= date('d M Y H:i:s', strtotime($log['created_at'])) ?></td>
          <td>
            <?php if ($log['user_name']): ?>
            <span class="fw-semibold"><?= e($log['user_name']) ?></span><br>
            <span class="badge <?= roleBadgeClass($log['user_role'] ?? '') ?>" style="font-size:9px;"><?= roleLabel($log['user_role'] ?? '') ?></span>
            <?php else: ?><span class="text-muted">System</span><?php endif; ?>
          </td>
          <td><span class="badge bg-<?= $color ?>"><?= $label ?></span></td>
          <td><code class="small"><?= e($log['table_name'] ?? '—') ?></code></td>
          <td><?= $log['record_id'] ? '#'.$log['record_id'] : '—' ?></td>
          <td><small class="text-muted"><?= e($log['ip_address'] ?? '—') ?></small></td>
          <td>
            <?php if ($log['old_values'] || $log['new_values']): ?>
            <button class="btn btn-xs btn-outline-secondary btn-sm py-0 px-2" style="font-size:11px;"
                    data-bs-toggle="modal" data-bs-target="#changesModal"
                    onclick="showChanges(<?= e(json_encode($log['old_values'])) ?>, <?= e(json_encode($log['new_values'])) ?>)">
              <i class="bi bi-code-slash"></i> diff
            </button>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?>
        <tr><td colspan="7" class="text-center text-muted py-5">No audit records found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pg['pages'] > 1): ?>
  <div class="card-footer d-flex justify-content-between align-items-center">
    <small class="text-muted">Showing <?= $pg['offset']+1 ?>–<?= min($pg['offset']+$pg['per_page'],$total) ?> of <?= $total ?></small>
    <nav><ul class="pagination pagination-sm mb-0">
      <?php for ($i=1;$i<=$pg['pages'];$i++): ?>
      <li class="page-item <?= $i==$page?'active':'' ?>">
        <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?= $i ?></a>
      </li>
      <?php endfor; ?>
    </ul></nav>
  </div>
  <?php endif; ?>
</div>

<!-- Changes Modal -->
<div class="modal fade" id="changesModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Change Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="fw-semibold small text-danger mb-2">Before</div>
            <pre id="oldValues" class="bg-light p-3 rounded small" style="max-height:300px;overflow:auto;"></pre>
          </div>
          <div class="col-md-6">
            <div class="fw-semibold small text-success mb-2">After</div>
            <pre id="newValues" class="bg-light p-3 rounded small" style="max-height:300px;overflow:auto;"></pre>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function showChanges(old, nw) {
  document.getElementById('oldValues').textContent = old ? JSON.stringify(JSON.parse(old), null, 2) : '(none)';
  document.getElementById('newValues').textContent = nw  ? JSON.stringify(JSON.parse(nw),  null, 2) : '(none)';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
