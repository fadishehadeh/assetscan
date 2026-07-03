<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assetId    = (int)($_POST['asset_id'] ?? 0);
    $toDeptId   = (int)($_POST['to_dept_id'] ?? 0);
    $toLocId    = (int)($_POST['to_location_id'] ?? 0);
    $reason     = trim($_POST['reason'] ?? '');
    $requestedBy = (int)$_SESSION['user_id'];

    if (!$assetId)  $errors[] = 'Please select an asset.';
    if (!$toDeptId) $errors[] = 'Please select a destination department.';
    if (strlen($reason) < 5) $errors[] = 'Please provide a reason (at least 5 characters).';

    if (!$errors) {
        // Fetch asset's current dept/location
        $ast = $pdo->prepare("SELECT department_id, location_id FROM assets WHERE id = ?");
        $ast->execute([$assetId]);
        $assetRow = $ast->fetch();

        if (!$assetRow) {
            $errors[] = 'Asset not found.';
        } else {
            $fromDeptId = $assetRow['department_id'];
            $fromLocId  = $assetRow['location_id'];

            if ($fromDeptId == $toDeptId && $fromLocId == $toLocId) {
                $errors[] = 'The destination is the same as the current department/location.';
            }
        }
    }

    if (!$errors) {
        $ins = $pdo->prepare(
            "INSERT INTO asset_transfers
             (asset_id, from_dept_id, to_dept_id, from_location_id, to_location_id,
              requested_by, status, reason, requested_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW())"
        );
        $ins->execute([
            $assetId, $fromDeptId, $toDeptId,
            $fromLocId ?: null, $toLocId ?: null,
            $requestedBy, $reason,
        ]);
        $newId = (int)$pdo->lastInsertId();

        auditLog($pdo, 'transfer_request', 'asset_transfers', $newId, null, [
            'asset_id'  => $assetId,
            'to_dept'   => $toDeptId,
            'reason'    => $reason,
        ]);

        setFlash('success', 'Transfer request submitted successfully.');
        header('Location: index.php');
        exit;
    }
}

$preAssetId = (int)($_GET['asset_id'] ?? 0);

// Assets available for transfer
$assetsStmt = $pdo->query(
    "SELECT a.id, a.asset_tag, a.name, a.department_id, a.location_id,
            d.name AS dept_name,
            l.building, l.floor, l.room
     FROM assets a
     LEFT JOIN departments d ON a.department_id = d.id
     LEFT JOIN locations l   ON a.location_id   = l.id
     WHERE a.status != 'disposed'
     ORDER BY a.asset_tag"
);
$assets = $assetsStmt->fetchAll();

// Departments
$depts = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();

// Locations
$locs = $pdo->query("SELECT id, building, floor, room FROM locations ORDER BY building, floor, room")->fetchAll();

// Build asset data for JS
$assetData = [];
foreach ($assets as $a) {
    $assetData[$a['id']] = [
        'dept_id'     => $a['department_id'],
        'location_id' => $a['location_id'],
        'dept_name'   => $a['dept_name'] ?? '',
        'location'    => trim(($a['building'] ?? '') . ' / ' . ($a['floor'] ?? '') . ' / ' . ($a['room'] ?? ''), ' /'),
    ];
}

$pageTitle = 'New Transfer Request';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-arrow-left-right me-2"></i>New Transfer Request</h1>
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

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">

                <!-- Asset -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Asset <span class="text-danger">*</span></label>
                    <select name="asset_id" id="assetSelect" class="form-select" required>
                        <option value="">— Select Asset —</option>
                        <?php foreach ($assets as $a): ?>
                            <option value="<?= $a['id'] ?>"
                                <?= ((int)($_POST['asset_id'] ?? $preAssetId) === (int)$a['id']) ? 'selected' : '' ?>>
                                <?= e($a['asset_tag']) ?> — <?= e($a['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Current info (read-only, filled by JS) -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Current Department / Location</label>
                    <input type="text" id="currentInfo" class="form-control" readonly
                           placeholder="Select an asset to see current location" style="background:#f8f9fa;">
                </div>

                <!-- To Department -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Destination Department <span class="text-danger">*</span></label>
                    <select name="to_dept_id" class="form-select" required>
                        <option value="">— Select Department —</option>
                        <?php foreach ($depts as $d): ?>
                            <option value="<?= $d['id'] ?>"
                                <?= (int)($_POST['to_dept_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
                                <?= e($d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- To Location -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Destination Location</label>
                    <select name="to_location_id" class="form-select">
                        <option value="">— Select Location (optional) —</option>
                        <?php foreach ($locs as $l): ?>
                            <option value="<?= $l['id'] ?>"
                                <?= (int)($_POST['to_location_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>>
                                <?= e($l['building']) ?><?= $l['floor'] ? ' / ' . e($l['floor']) : '' ?><?= $l['room'] ? ' / ' . e($l['room']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Reason -->
                <div class="col-12">
                    <label class="form-label fw-semibold">Reason <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="4" required
                              placeholder="Explain why this asset needs to be transferred..."><?= e($_POST['reason'] ?? '') ?></textarea>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Submit Request
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const assetData = <?= json_encode($assetData) ?>;

document.getElementById('assetSelect').addEventListener('change', function() {
    const info = assetData[this.value];
    const field = document.getElementById('currentInfo');
    if (info) {
        const parts = [];
        if (info.dept_name) parts.push(info.dept_name);
        if (info.location)  parts.push(info.location);
        field.value = parts.join(' | ') || 'No location set';
    } else {
        field.value = '';
    }
});

// Trigger on load if pre-selected
const sel = document.getElementById('assetSelect');
if (sel.value) sel.dispatchEvent(new Event('change'));
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
