<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

// Filters
$search        = trim($_GET['search']    ?? '');
$filterCat     = (int)($_GET['category'] ?? 0);
$filterStatus  = $_GET['status']         ?? '';
$filterDept    = (int)($_GET['dept']     ?? 0);
$filterLoc     = (int)($_GET['location'] ?? 0);
$filterUser    = (int)($_GET['user']     ?? 0);
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo   = trim($_GET['date_to']   ?? '');
$filterDepMethod = $_GET['dep_method']   ?? '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 20;

// IT role: only IT category types
$roleWhere = '';
if (!isAdmin() && isIT()) {
    $roleWhere = " AND c.type IN ('IT','Networking','Software') ";
}

$where  = "WHERE 1=1 {$roleWhere}";
$params = [];

if ($search) {
    $where .= " AND (a.asset_tag LIKE ? OR a.name LIKE ? OR a.serial_number LIKE ? OR a.brand LIKE ? OR a.vendor LIKE ?)";
    $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%","%$search%"]);
}
if ($filterCat)        { $where .= " AND a.category_id = ?";        $params[] = $filterCat; }
if ($filterStatus)     { $where .= " AND a.status = ?";              $params[] = $filterStatus; }
if ($filterDept)       { $where .= " AND a.department_id = ?";       $params[] = $filterDept; }
if ($filterLoc)        { $where .= " AND a.location_id = ?";         $params[] = $filterLoc; }
if ($filterUser === -1) { $where .= " AND a.assigned_to IS NULL"; }
elseif ($filterUser)   { $where .= " AND a.assigned_to = ?";          $params[] = $filterUser; }
if ($filterDateFrom)   { $where .= " AND a.purchase_date >= ?";      $params[] = $filterDateFrom; }
if ($filterDateTo)     { $where .= " AND a.purchase_date <= ?";      $params[] = $filterDateTo; }
if ($filterDepMethod)  { $where .= " AND a.depreciation_method = ?"; $params[] = $filterDepMethod; }

$countSt = $pdo->prepare("SELECT COUNT(*) FROM assets a LEFT JOIN categories c ON a.category_id=c.id $where");
$countSt->execute($params);
$total = (int)$countSt->fetchColumn();
$pg    = paginate($total, $perPage, $page);

$st = $pdo->prepare(
    "SELECT a.*, c.name AS cat_name, u.name AS assigned_name, d.name AS dept_name
     FROM assets a
     LEFT JOIN categories c ON a.category_id = c.id
     LEFT JOIN users u ON a.assigned_to = u.id
     LEFT JOIN departments d ON a.department_id = d.id
     $where
     ORDER BY a.created_at DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}"
);
$st->execute($params);
$assets = $st->fetchAll();

$categories  = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$locations   = $pdo->query("SELECT id, CONCAT(building,' › Fl.',floor,' › ',room) AS label FROM locations ORDER BY building,floor,room")->fetchAll();
$users       = $pdo->query("SELECT id, name FROM users WHERE is_active=1 ORDER BY name")->fetchAll();
$settings    = getSettings($pdo);

// Detect if any advanced filters are active
$advancedActive = $filterLoc || $filterUser || $filterDateFrom || $filterDateTo || $filterDepMethod;

$pageTitle = 'Assets';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-start">
  <div>
    <h2><i class="bi bi-boxes me-2 text-primary"></i>Asset Registry</h2>
    <p>Total: <strong><?= number_format($total) ?></strong> assets found.</p>
  </div>
  <div class="d-flex gap-2">
    <?php if (isAdmin() || isIT()): ?>
    <a href="/asset-manager/modules/assets/add.php" class="btn btn-primary">
      <i class="bi bi-plus-circle me-1"></i> Add Asset
    </a>
    <?php endif; ?>
    <a href="/asset-manager/modules/assets/bulk_qr.php" class="btn btn-outline-success" target="_blank">
      <i class="bi bi-qr-code me-1"></i> Bulk QR
    </a>
    <?php
    $exportQ = http_build_query(array_filter(['search'=>$search,'category'=>$filterCat ?: null,'status'=>$filterStatus,'dept'=>$filterDept ?: null]));
    ?>
    <div class="dropdown">
      <button class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
        <i class="bi bi-download me-1"></i> Export
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="/asset-manager/modules/reports/export_csv.php?report=assets&<?= $exportQ ?>"><i class="bi bi-filetype-csv me-2"></i>CSV (current filter)</a></li>
        <li><a class="dropdown-item" href="/asset-manager/modules/reports/export_csv.php?report=assets&format=excel&<?= $exportQ ?>"><i class="bi bi-file-earmark-excel me-2"></i>Excel (current filter)</a></li>
        <li><a class="dropdown-item" href="/asset-manager/modules/reports/export_pdf.php?report=assets" target="_blank"><i class="bi bi-file-earmark-pdf me-2"></i>PDF (all assets)</a></li>
      </ul>
    </div>
  </div>
</div>

<!-- FILTERS -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" id="filterForm">
      <!-- Quick filter row -->
      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <input type="search" name="search" class="form-control form-control-sm" placeholder="Search tag, name, serial, brand…" value="<?= e($search) ?>">
        </div>
        <div class="col-md-2">
          <select name="category" class="form-select form-select-sm">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= $filterCat == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <select name="dept" class="form-select form-select-sm">
            <option value="">All Departments</option>
            <?php foreach ($departments as $dept): ?>
            <option value="<?= $dept['id'] ?>" <?= $filterDept == $dept['id'] ? 'selected' : '' ?>><?= e($dept['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <select name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            <option value="active" <?= $filterStatus==='active' ? 'selected':'' ?>>Active</option>
            <option value="under_maintenance" <?= $filterStatus==='under_maintenance' ? 'selected':'' ?>>Maintenance</option>
            <option value="disposed" <?= $filterStatus==='disposed' ? 'selected':'' ?>>Disposed</option>
            <option value="lost" <?= $filterStatus==='lost' ? 'selected':'' ?>>Lost</option>
          </select>
        </div>
        <div class="col-auto d-flex gap-1 align-items-center">
          <button type="submit" class="btn btn-sm btn-primary">Filter</button>
          <a href="index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
          <button type="button" class="btn btn-sm btn-outline-secondary <?= $advancedActive ? 'active' : '' ?>"
                  data-bs-toggle="collapse" data-bs-target="#advancedFilters" aria-expanded="<?= $advancedActive ? 'true' : 'false' ?>">
            <i class="bi bi-sliders me-1"></i>Advanced<?= $advancedActive ? ' <span class="badge bg-primary ms-1" style="font-size:9px">ON</span>' : '' ?>
          </button>
        </div>
      </div>

      <!-- Advanced filter panel -->
      <div class="collapse <?= $advancedActive ? 'show' : '' ?> mt-3" id="advancedFilters">
        <div class="border-top pt-3">
          <div class="row g-2">
            <div class="col-md-3">
              <label class="form-label form-label-sm fw-semibold mb-1">Location</label>
              <select name="location" class="form-select form-select-sm">
                <option value="">Any Location</option>
                <?php foreach ($locations as $loc): ?>
                <option value="<?= $loc['id'] ?>" <?= $filterLoc == $loc['id'] ? 'selected' : '' ?>><?= e($loc['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label form-label-sm fw-semibold mb-1">Assigned To</label>
              <select name="user" class="form-select form-select-sm">
                <option value="">Any User</option>
                <option value="-1" <?= $filterUser == -1 ? 'selected' : '' ?>>Unassigned</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label form-label-sm fw-semibold mb-1">Purchase Date From</label>
              <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($filterDateFrom) ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label form-label-sm fw-semibold mb-1">Purchase Date To</label>
              <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($filterDateTo) ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label form-label-sm fw-semibold mb-1">Depreciation Method</label>
              <select name="dep_method" class="form-select form-select-sm">
                <option value="">Any Method</option>
                <option value="straight_line" <?= $filterDepMethod==='straight_line' ? 'selected':'' ?>>Straight Line</option>
                <option value="declining_balance" <?= $filterDepMethod==='declining_balance' ? 'selected':'' ?>>Declining Balance</option>
              </select>
            </div>
          </div>
          <?php if ($advancedActive): ?>
          <div class="mt-2">
            <?php if ($filterLoc): ?><span class="badge bg-primary me-1">Location set</span><?php endif; ?>
            <?php if ($filterUser): ?><span class="badge bg-primary me-1">User filter active</span><?php endif; ?>
            <?php if ($filterDateFrom || $filterDateTo): ?><span class="badge bg-primary me-1">Date range: <?= e($filterDateFrom ?: '…') ?> → <?= e($filterDateTo ?: '…') ?></span><?php endif; ?>
            <?php if ($filterDepMethod): ?><span class="badge bg-primary me-1">Method: <?= $filterDepMethod === 'straight_line' ? 'Straight Line' : 'Declining Balance' ?></span><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- BULK FORM wraps bar + table -->
<form id="bulkForm" method="POST" action="/asset-manager/modules/assets/bulk_action.php">

<!-- BULK ACTION BAR (hidden until ≥1 checkbox checked) -->
<div id="bulkBar" class="d-none mb-2 p-2 bg-white border rounded d-flex align-items-center gap-2 flex-wrap">
  <span class="text-muted small me-2"><span id="selectedCount">0</span> selected</span>
  <!-- Bulk assign department -->
  <select id="bulkDept" name="bulk_dept_id" class="form-select form-select-sm" style="width:auto">
    <option value="">Assign Department…</option>
    <?php foreach ($departments as $dept): ?>
    <option value="<?= $dept['id'] ?>"><?= e($dept['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" name="bulk_action" value="assign_dept" class="btn btn-sm btn-outline-primary">Apply Dept</button>
  <!-- Bulk status -->
  <select id="bulkStatus" name="bulk_status" class="form-select form-select-sm" style="width:auto">
    <option value="">Change Status…</option>
    <option value="active">Active</option>
    <option value="under_maintenance">Under Maintenance</option>
    <option value="lost">Lost</option>
    <option value="disposed">Disposed</option>
  </select>
  <button type="submit" name="bulk_action" value="change_status" class="btn btn-sm btn-outline-warning">Apply Status</button>
  <!-- Export selected -->
  <button type="submit" name="bulk_action" value="export_csv" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV</button>
  <button type="submit" name="bulk_action" value="export_pdf" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i>Export PDF</button>
</div>

<!-- TABLE -->
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th class="text-center" style="width:36px"><input type="checkbox" id="selectAll" class="form-check-input"></th>
          <th>Asset Tag</th>
          <th>Name</th>
          <th>Category</th>
          <th>Status</th>
          <th>Department</th>
          <th>Assigned To</th>
          <th>Purchase Cost</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($assets as $a): ?>
        <tr>
          <td class="text-center"><input type="checkbox" name="ids[]" value="<?= $a['id'] ?>" class="form-check-input row-check"></td>
          <td><code class="text-primary"><?= e($a['asset_tag']) ?></code></td>
          <td>
            <a href="view.php?id=<?= $a['id'] ?>" class="text-dark text-decoration-none fw-semibold">
              <?= e($a['name']) ?>
            </a>
            <?php if ($a['brand']): ?><br><small class="text-muted"><?= e($a['brand']) ?> <?= e($a['model'] ?? '') ?></small><?php endif; ?>
          </td>
          <td><span class="badge bg-light text-dark"><?= e($a['cat_name'] ?? '—') ?></span></td>
          <td><?= statusBadge($a['status']) ?></td>
          <td><?= e($a['dept_name'] ?? '—') ?></td>
          <td><?= e($a['assigned_name'] ?? '—') ?></td>
          <td><?= formatMoney((float)$a['purchase_cost'], $settings['currency'] ?? 'USD') ?></td>
          <td>
            <div class="btn-group btn-group-sm">
              <a href="view.php?id=<?= $a['id'] ?>" class="btn btn-outline-secondary" title="View"><i class="bi bi-eye"></i></a>
              <?php if (isAdmin() || isIT()): ?>
              <a href="edit.php?id=<?= $a['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
              <a href="clone.php?id=<?= $a['id'] ?>" class="btn btn-outline-secondary" title="Clone" onclick="return confirm('Clone this asset?')"><i class="bi bi-copy"></i></a>
              <a href="qr.php?id=<?= $a['id'] ?>" class="btn btn-outline-success" title="QR Code" target="_blank"><i class="bi bi-qr-code"></i></a>
              <?php endif; ?>
              <?php if (isAdmin()): ?>
              <a href="delete.php?id=<?= $a['id'] ?>" class="btn btn-outline-danger" title="Delete"
                 onclick="return confirm('Delete this asset? This cannot be undone.')"><i class="bi bi-trash"></i></a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($assets)): ?>
        <tr><td colspan="9" class="text-center text-muted py-5">No assets found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pg['pages'] > 1): ?>
  <div class="card-footer d-flex justify-content-between align-items-center">
    <small class="text-muted">Showing <?= $pg['offset']+1 ?>–<?= min($pg['offset']+$pg['per_page'], $total) ?> of <?= $total ?></small>
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <?php for ($i = 1; $i <= $pg['pages']; $i++): ?>
        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
          <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page'=>$i])) ?>"><?= $i ?></a>
        </li>
        <?php endfor; ?>
      </ul>
    </nav>
  </div>
  <?php endif; ?>
</div>

</form><!-- end bulkForm -->

<script>
(function () {
  const selectAll   = document.getElementById('selectAll');
  const bulkBar     = document.getElementById('bulkBar');
  const countSpan   = document.getElementById('selectedCount');

  function updateBar() {
    const checked = document.querySelectorAll('.row-check:checked');
    const n = checked.length;
    countSpan.textContent = n;
    if (n > 0) {
      bulkBar.classList.remove('d-none');
      bulkBar.classList.add('d-flex');
    } else {
      bulkBar.classList.add('d-none');
      bulkBar.classList.remove('d-flex');
    }
  }

  selectAll.addEventListener('change', function () {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
    updateBar();
  });

  document.querySelectorAll('.row-check').forEach(cb => {
    cb.addEventListener('change', function () {
      const all  = document.querySelectorAll('.row-check');
      const chkd = document.querySelectorAll('.row-check:checked');
      selectAll.indeterminate = chkd.length > 0 && chkd.length < all.length;
      selectAll.checked = chkd.length === all.length && all.length > 0;
      updateBar();
    });
  });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
