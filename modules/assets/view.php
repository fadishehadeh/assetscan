<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/custom_fields_helper.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$asset = $pdo->prepare(
    "SELECT a.*, c.name AS cat_name, c.type AS cat_type,
            u.name AS assigned_name, d.name AS dept_name,
            l.building, l.floor, l.room,
            cb.name AS created_by_name
     FROM assets a
     LEFT JOIN categories c ON a.category_id = c.id
     LEFT JOIN users u ON a.assigned_to = u.id
     LEFT JOIN departments d ON a.department_id = d.id
     LEFT JOIN locations l ON a.location_id = l.id
     LEFT JOIN users cb ON a.created_by = cb.id
     WHERE a.id = ?"
);
$asset->execute([$id]);
$a = $asset->fetch();
if (!$a) { setFlash('danger', 'Asset not found.'); header('Location: index.php'); exit; }

$settings   = getSettings($pdo);
$currency   = $settings['currency'] ?? 'USD';
$bookValue  = currentBookValue((float)$a['purchase_cost'], (float)$a['salvage_value'], (int)$a['useful_life_years'], $a['depreciation_method'], $a['purchase_date']);

$maintenance = $pdo->prepare("SELECT m.*, u.name AS logged_by_name FROM maintenance m LEFT JOIN users u ON m.logged_by=u.id WHERE m.asset_id=? ORDER BY m.date DESC LIMIT 10");
$maintenance->execute([$id]);
$maintLogs = $maintenance->fetchAll();

$history = $pdo->prepare("SELECT h.*, u.name AS user_name FROM asset_history h LEFT JOIN users u ON h.performed_by=u.id WHERE h.asset_id=? ORDER BY h.created_at DESC LIMIT 15");
$history->execute([$id]);
$histLogs = $history->fetchAll();

$depLogs = $pdo->prepare("SELECT * FROM depreciation_log WHERE asset_id=? ORDER BY fiscal_year");
$depLogs->execute([$id]);
$depRows = $depLogs->fetchAll();

// Documents
$docs = $pdo->prepare("SELECT d.*, u.name AS uploader FROM asset_documents d LEFT JOIN users u ON d.uploaded_by=u.id WHERE d.asset_id=? ORDER BY d.uploaded_at DESC");
$docs->execute([$id]);
$docRows = $docs->fetchAll();

// Checkouts
$checkouts = $pdo->prepare("SELECT co.*, u.name AS holder_name, ob.name AS out_by, ib.name AS in_by FROM asset_checkouts co LEFT JOIN users u ON co.user_id=u.id LEFT JOIN users ob ON co.checked_out_by=ob.id LEFT JOIN users ib ON co.checked_in_by=ib.id WHERE co.asset_id=? ORDER BY co.checkout_at DESC LIMIT 20");
$checkouts->execute([$id]);
$checkoutRows = $checkouts->fetchAll();

// Photos
$photos = $pdo->prepare("SELECT * FROM asset_photos WHERE asset_id=? ORDER BY is_primary DESC, uploaded_at ASC");
$photos->execute([$id]);
$photoRows = $photos->fetchAll();
$primaryPhoto = null;
foreach ($photoRows as $p) { if ($p['is_primary']) { $primaryPhoto = $p; break; } }

// Custom fields
$customFields = getCustomFieldsForAsset($pdo, (int)($a['category_id'] ?? 0));
$customValues = getCustomFieldValues($pdo, $id);

$pageTitle = 'Asset: ' . $a['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-start">
  <div>
    <h2><i class="bi bi-box me-2 text-primary"></i><?= e($a['name']) ?></h2>
    <p>
      <code class="text-primary me-2"><?= e($a['asset_tag']) ?></code>
      <?= statusBadge($a['status']) ?>
    </p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (isAdmin() || isIT()): ?>
    <a href="edit.php?id=<?= $id ?>" class="btn btn-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
    <a href="clone.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Clone this asset? A new asset tag will be assigned.')"><i class="bi bi-copy me-1"></i>Clone</a>
    <a href="qr.php?id=<?= $id ?>" class="btn btn-success btn-sm" target="_blank"><i class="bi bi-qr-code me-1"></i>Print QR</a>
    <a href="/asset-manager/modules/checkout/checkout.php?asset_id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left-right me-1"></i>Check Out</a>
    <a href="/asset-manager/modules/disposal/request.php?asset_id=<?= $id ?>" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3 me-1"></i>Dispose</a>
    <?php endif; ?>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>
</div>

<div class="row g-4">

  <!-- Left: Details -->
  <div class="col-lg-8">

    <!-- Info Card -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-info-circle me-2"></i>Asset Details</div>
      <div class="card-body">
        <div class="row g-3">
          <?php
          $fields = [
            'Category'        => e($a['cat_name'] ?? '—') . ' <span class="badge bg-secondary ms-1">' . e($a['cat_type'] ?? '') . '</span>',
            'Brand / Model'   => e(($a['brand'] ?? '') . ' ' . ($a['model'] ?? '')),
            'Serial Number'   => e($a['serial_number'] ?? '—'),
            'Purchase Date'   => $a['purchase_date'] ? date('d M Y', strtotime($a['purchase_date'])) : '—',
            'Purchase Cost'   => formatMoney((float)$a['purchase_cost'], $currency),
            'Current Value'   => '<strong>' . formatMoney($bookValue, $currency) . '</strong>',
            'Vendor'          => e($a['vendor'] ?? '—'),
            'Warranty Expiry' => $a['warranty_expiry'] ? date('d M Y', strtotime($a['warranty_expiry'])) : '—',
            'Department'      => e($a['dept_name'] ?? '—'),
            'Location'        => $a['building'] ? e($a['building'] . ' › Fl.' . $a['floor'] . ' › ' . $a['room']) : '—',
            'Assigned To'     => e($a['assigned_name'] ?? 'Unassigned'),
            'Added By'        => e($a['created_by_name'] ?? '—'),
          ];
          foreach ($fields as $label => $val): ?>
          <div class="col-sm-6">
            <div class="small text-muted"><?= $label ?></div>
            <div><?= $val ?></div>
          </div>
          <?php endforeach; ?>
          <?php if ($a['notes']): ?>
          <div class="col-12">
            <div class="small text-muted">Notes</div>
            <div><?= e($a['notes']) ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Depreciation Schedule -->
    <?php if (!empty($depRows)): ?>
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-graph-down me-2"></i>Depreciation Schedule</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Year</th><th>Depreciation</th><th>Book Value Start</th><th>Book Value End</th></tr></thead>
          <tbody>
            <?php foreach ($depRows as $dr): ?>
            <tr>
              <td><?= $dr['fiscal_year'] ?></td>
              <td><?= formatMoney((float)$dr['dep_amount'], $currency) ?></td>
              <td><?= formatMoney((float)$dr['book_value_start'], $currency) ?></td>
              <td><?= formatMoney((float)$dr['book_value_end'], $currency) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Maintenance Log -->
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-wrench me-2"></i>Maintenance History</span>
        <?php if (isAdmin() || isIT()): ?>
        <a href="/asset-manager/modules/maintenance/add.php?asset_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">+ Log</a>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Date</th><th>Type</th><th>Cost</th><th>Vendor</th><th>Logged By</th></tr></thead>
          <tbody>
            <?php foreach ($maintLogs as $m): ?>
            <tr>
              <td><?= date('d M Y', strtotime($m['date'])) ?></td>
              <td><span class="badge bg-secondary"><?= ucfirst($m['type']) ?></span></td>
              <td><?= formatMoney((float)$m['cost'], $currency) ?></td>
              <td><?= e($m['vendor'] ?? '—') ?></td>
              <td><?= e($m['logged_by_name'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($maintLogs)): ?>
            <tr><td colspan="5" class="text-muted text-center py-3">No maintenance records.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Photos -->
    <div class="card mb-4" id="photos">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-images me-2"></i>Photos <span class="badge bg-secondary ms-1"><?= count($photoRows) ?></span></span>
        <?php if (isAdmin() || isIT()): ?>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadPhotoModal"><i class="bi bi-cloud-upload me-1"></i>Upload</button>
        <?php endif; ?>
      </div>
      <?php if ($photoRows): ?>
      <div class="card-body pb-2">
        <div class="row g-2">
          <?php foreach ($photoRows as $p): ?>
          <div class="col-4">
            <div class="position-relative">
              <div class="ratio ratio-1x1">
                <img src="/asset-manager/<?= e($p['file_path']) ?>"
                     class="img-fluid rounded object-fit-cover w-100 h-100"
                     alt="<?= e($p['caption'] ?? 'Asset photo') ?>"
                     style="cursor:pointer; object-fit:cover;"
                     data-bs-toggle="tooltip"
                     title="<?= e($p['caption'] ?? '') ?>">
              </div>
              <?php if ($p['is_primary']): ?>
              <span class="position-absolute top-0 start-0 badge bg-primary m-1" style="font-size:.65rem;">Primary</span>
              <?php endif; ?>
            </div>
            <?php if (isAdmin() || isIT()): ?>
            <div class="d-flex gap-1 mt-1 justify-content-center flex-wrap">
              <?php if (!$p['is_primary']): ?>
              <a href="photo_primary.php?id=<?= $p['id'] ?>"
                 class="btn btn-xs btn-outline-secondary"
                 style="font-size:.7rem;padding:1px 5px;"
                 title="Set as primary">
                <i class="bi bi-star"></i>
              </a>
              <?php endif; ?>
              <?php if (isAdmin()): ?>
              <a href="photo_delete.php?id=<?= $p['id'] ?>"
                 class="btn btn-xs btn-outline-danger"
                 style="font-size:.7rem;padding:1px 5px;"
                 onclick="return confirm('Delete this photo?')"
                 title="Delete">
                <i class="bi bi-trash3"></i>
              </a>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php else: ?>
      <div class="card-body">
        <div class="border border-2 border-dashed rounded text-center py-4 text-muted"
             style="border-style:dashed!important;cursor:pointer;"
             <?php if (isAdmin() || isIT()): ?>data-bs-toggle="modal" data-bs-target="#uploadPhotoModal"<?php endif; ?>>
          <i class="bi bi-camera" style="font-size:2rem;opacity:.4;"></i>
          <div class="mt-2 small">No photos yet<?php if (isAdmin() || isIT()): ?> — click to upload<?php endif; ?></div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Documents -->
    <div class="card mb-4" id="documents">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-paperclip me-2"></i>Documents <span class="badge bg-secondary ms-1"><?= count($docRows) ?></span></span>
        <?php if (isAdmin() || isIT()): ?>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadDocModal">+ Upload</button>
        <?php endif; ?>
      </div>
      <?php if ($docRows): ?>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Name</th><th>Type</th><th>Size</th><th>Uploaded By</th><th>Date</th><?php if(isAdmin()):?><th></th><?php endif;?></tr></thead>
          <tbody>
          <?php foreach ($docRows as $doc):
            $icons = ['invoice'=>'bi-receipt','warranty'=>'bi-shield-check','manual'=>'bi-book','purchase_order'=>'bi-file-text','insurance'=>'bi-umbrella','other'=>'bi-paperclip'];
            $icon = $icons[$doc['doc_type']] ?? 'bi-paperclip';
          ?>
          <tr>
            <td><a href="/asset-manager/<?= e($doc['file_path']) ?>" target="_blank"><i class="bi <?= $icon ?> me-1"></i><?= e($doc['name']) ?></a></td>
            <td><span class="badge bg-light text-dark"><?= ucfirst(str_replace('_',' ',$doc['doc_type'])) ?></span></td>
            <td><?= $doc['file_size'] > 0 ? round($doc['file_size']/1024,1).' KB' : '—' ?></td>
            <td><?= e($doc['uploader'] ?? '—') ?></td>
            <td><?= date('d M Y', strtotime($doc['uploaded_at'])) ?></td>
            <?php if(isAdmin()):?><td><a href="/asset-manager/modules/documents/delete.php?id=<?= $doc['id'] ?>" class="text-danger small" onclick="return confirm('Delete this document?')"><i class="bi bi-trash3"></i></a></td><?php endif;?>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="card-body text-muted text-center py-3"><i class="bi bi-folder2-open me-2"></i>No documents attached.</div>
      <?php endif; ?>
    </div>

    <!-- Checkout History -->
    <?php if (!empty($checkoutRows)): ?>
    <div class="card mb-4" id="checkouts">
      <div class="card-header"><i class="bi bi-arrow-left-right me-2"></i>Checkout History</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Checked Out To</th><th>By</th><th>Out Date</th><th>Expected Return</th><th>Returned</th><th>Condition</th></tr></thead>
          <tbody>
          <?php foreach ($checkoutRows as $co): ?>
          <tr>
            <td><?= e($co['holder_name'] ?? '—') ?></td>
            <td><?= e($co['out_by'] ?? '—') ?></td>
            <td><?= date('d M Y', strtotime($co['checkout_at'])) ?></td>
            <td><?= $co['expected_return'] ? date('d M Y', strtotime($co['expected_return'])) : '—' ?></td>
            <td><?= $co['actual_return'] ? date('d M Y', strtotime($co['actual_return'])) : '<span class="badge bg-warning text-dark">Active</span>' ?></td>
            <td>
              <?php if ($co['actual_return']): ?>
              <span class="badge bg-<?= $co['condition_in']==='good'?'success':($co['condition_in']==='fair'?'warning':'danger') ?>"><?= ucfirst($co['condition_in']??'—') ?></span>
              <?php else: ?>
              <span class="badge bg-<?= $co['condition_out']==='good'?'success':($co['condition_out']==='fair'?'warning':'danger') ?>"><?= ucfirst($co['condition_out']) ?> (out)</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Custom Fields -->
    <?php if (!empty($customFields)): ?>
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-sliders2 me-2"></i>Custom Fields</div>
      <div class="card-body">
        <div class="row g-3">
        <?php foreach ($customFields as $cf): ?>
        <div class="col-sm-6">
          <div class="small text-muted"><?= e($cf['field_label']) ?></div>
          <div><?= e($customValues[$cf['id']] ?? '—') ?></div>
        </div>
        <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Asset History -->
    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history me-2"></i>Activity History</div>
      <ul class="list-group list-group-flush">
        <?php foreach ($histLogs as $h): ?>
        <li class="list-group-item py-2">
          <div class="d-flex justify-content-between">
            <span class="small"><strong><?= e($h['user_name'] ?? 'System') ?></strong> — <?= e($h['action']) ?></span>
            <span class="text-muted small"><?= date('d M Y H:i', strtotime($h['created_at'])) ?></span>
          </div>
          <?php if ($h['notes']): ?><div class="small text-muted"><?= e($h['notes']) ?></div><?php endif; ?>
        </li>
        <?php endforeach; ?>
        <?php if (empty($histLogs)): ?>
        <li class="list-group-item text-muted text-center py-3">No history yet.</li>
        <?php endif; ?>
      </ul>
    </div>

  </div>

<!-- Upload Photo Modal -->
<div class="modal fade" id="uploadPhotoModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="photo_upload.php" enctype="multipart/form-data">
        <input type="hidden" name="asset_id" value="<?= $id ?>">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-images me-2"></i>Upload Photos</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Photos <small class="text-muted">(up to 5 total per asset · JPG, PNG, WebP, GIF · max 5 MB each)</small></label>
            <input type="file" name="photos[]" class="form-control" multiple accept="image/*" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Caption <small class="text-muted">(optional, applies to all uploaded photos)</small></label>
            <input type="text" name="caption" class="form-control" maxlength="200" placeholder="e.g. Front view">
          </div>
          <?php if (count($photoRows) >= 5): ?>
          <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Maximum 5 photos reached. Delete a photo before uploading more.</div>
          <?php else: ?>
          <div class="text-muted small"><i class="bi bi-info-circle me-1"></i><?= 5 - count($photoRows) ?> photo slot(s) remaining.</div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" <?= count($photoRows) >= 5 ? 'disabled' : '' ?>>
            <i class="bi bi-cloud-upload me-1"></i>Upload
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Upload Document Modal -->
<div class="modal fade" id="uploadDocModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="/asset-manager/modules/documents/upload.php" enctype="multipart/form-data">
        <input type="hidden" name="asset_id" value="<?= $id ?>">
        <div class="modal-header"><h5 class="modal-title">Upload Document</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Document Name</label><input type="text" name="name" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Type</label>
            <select name="doc_type" class="form-select">
              <option value="invoice">Invoice</option><option value="warranty">Warranty</option><option value="manual">Manual</option>
              <option value="purchase_order">Purchase Order</option><option value="insurance">Insurance</option><option value="other">Other</option>
            </select></div>
          <div class="mb-3"><label class="form-label">File <small class="text-muted">(PDF, DOC, XLS, PNG, JPG — max 10MB)</small></label><input type="file" name="file" class="form-control" required accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg"></div>
          <div class="mb-3"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Upload</button></div>
      </form>
    </div>
  </div>
</div>

  <!-- Right: Primary Photo + QR + Depreciation info -->
  <div class="col-lg-4">

    <?php if ($primaryPhoto): ?>
    <!-- Primary Photo -->
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-image me-2"></i>Primary Photo</span>
        <span class="badge bg-primary">Primary</span>
      </div>
      <div class="card-body p-2">
        <img src="/asset-manager/<?= e($primaryPhoto['file_path']) ?>"
             alt="<?= e($primaryPhoto['caption'] ?? $a['name']) ?>"
             class="img-fluid rounded w-100"
             style="object-fit:cover;max-height:240px;">
        <?php if ($primaryPhoto['caption']): ?>
        <div class="text-muted small text-center mt-1"><?= e($primaryPhoto['caption']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card mb-4 text-center">
      <div class="card-header"><i class="bi bi-qr-code me-2"></i>QR Code</div>
      <div class="card-body">
        <?php if ($a['qr_code_path'] && file_exists(__DIR__ . '/../../' . $a['qr_code_path'])): ?>
        <img src="/asset-manager/<?= e($a['qr_code_path']) ?>" alt="QR Code" class="img-fluid" style="max-width:180px;">
        <?php else: ?>
        <div class="text-muted py-3"><i class="bi bi-qr-code" style="font-size:60px;opacity:.3;"></i><br>QR not generated</div>
        <?php endif; ?>
        <div class="mt-3">
          <a href="qr.php?id=<?= $id ?>" class="btn btn-success btn-sm w-100" target="_blank">
            <i class="bi bi-printer me-1"></i> Print QR Label
          </a>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-graph-down me-2"></i>Depreciation Summary</div>
      <ul class="list-group list-group-flush">
        <li class="list-group-item d-flex justify-content-between small">
          <span>Method</span>
          <strong><?= $a['depreciation_method'] === 'straight_line' ? 'Straight-Line' : 'Declining Balance' ?></strong>
        </li>
        <li class="list-group-item d-flex justify-content-between small">
          <span>Useful Life</span>
          <strong><?= $a['useful_life_years'] ?> years</strong>
        </li>
        <li class="list-group-item d-flex justify-content-between small">
          <span>Salvage Value</span>
          <strong><?= formatMoney((float)$a['salvage_value'], $currency) ?></strong>
        </li>
        <li class="list-group-item d-flex justify-content-between small">
          <span>Original Cost</span>
          <strong><?= formatMoney((float)$a['purchase_cost'], $currency) ?></strong>
        </li>
        <li class="list-group-item d-flex justify-content-between">
          <span class="small">Current Book Value</span>
          <strong class="text-success"><?= formatMoney($bookValue, $currency) ?></strong>
        </li>
      </ul>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
