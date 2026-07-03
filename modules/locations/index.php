<?php
$pageTitle = 'Locations';
require_once __DIR__ . '/../../includes/header.php';
requireRole('super_admin', 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $building = trim($_POST['building'] ?? '');
    $floor    = trim($_POST['floor'] ?? '');
    $room     = trim($_POST['room'] ?? '');
    $deptId   = (int)($_POST['department_id'] ?? 0);
    if ($building) {
        $pdo->prepare("INSERT INTO locations (building, floor, room, department_id) VALUES (?,?,?,?)")
            ->execute([$building, $floor ?: null, $room ?: null, $deptId ?: null]);
        setFlash('success', 'Location added.');
    }
    header('Location: index.php'); exit;
}

$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$locations   = $pdo->query(
    "SELECT l.*, d.name AS dept_name, COUNT(a.id) AS asset_count
     FROM locations l LEFT JOIN departments d ON l.department_id=d.id
     LEFT JOIN assets a ON a.location_id=l.id
     GROUP BY l.id ORDER BY l.building, l.floor, l.room"
)->fetchAll();
?>
<div class="page-header"><h2><i class="bi bi-geo-alt me-2 text-primary"></i>Locations</h2></div>

<div class="row g-4">
  <div class="col-md-4">
    <div class="card"><div class="card-header fw-semibold">Add Location</div>
    <div class="card-body">
      <form method="POST" class="row g-2">
        <div class="col-12"><label class="form-label small fw-semibold">Building *</label><input type="text" name="building" class="form-control" required></div>
        <div class="col-6"><label class="form-label small fw-semibold">Floor</label><input type="text" name="floor" class="form-control"></div>
        <div class="col-6"><label class="form-label small fw-semibold">Room</label><input type="text" name="room" class="form-control"></div>
        <div class="col-12">
          <label class="form-label small fw-semibold">Department</label>
          <select name="department_id" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12"><button type="submit" class="btn btn-primary w-100">Add</button></div>
      </form>
    </div></div>
  </div>
  <div class="col">
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Building</th><th>Floor</th><th>Room</th><th>Department</th><th>Assets</th></tr></thead>
          <tbody>
            <?php foreach ($locations as $l): ?>
            <tr>
              <td><?= e($l['building']) ?></td>
              <td><?= e($l['floor'] ?? '—') ?></td>
              <td><?= e($l['room'] ?? '—') ?></td>
              <td><?= e($l['dept_name'] ?? '—') ?></td>
              <td><span class="badge bg-primary"><?= $l['asset_count'] ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
