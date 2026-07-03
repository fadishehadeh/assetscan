<?php
// ── Bootstrap ─────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!isAdmin()) {
    setFlash('danger', 'Access denied. Admin role required.');
    header('Location: /asset-manager/modules/dashboard/index.php');
    exit;
}

// ── Template Download ─────────────────────────────────────────────────────
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="asset_import_template.csv"');
    // BOM for Excel UTF-8 compatibility
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'name','category_name','serial_number','model','brand',
        'purchase_date','purchase_cost','vendor','warranty_expiry',
        'useful_life_years','salvage_value','depreciation_method',
        'status','department_name','notes'
    ]);
    fputcsv($out, [
        'Dell Laptop Pro','Computers & Laptops','SN-EXAMPLE-001','Latitude 5540','Dell',
        '2024-01-15','1850.00','Dell Technologies','2027-01-15',
        '5','200.00','straight_line','active','IT Department','Example asset row'
    ]);
    fputcsv($out, [
        'Office Chair Ergonomic','Furniture','','Aeron Chair','Herman Miller',
        '2023-06-01','1500.00','Herman Miller','',
        '10','150.00','straight_line','active','Management','Example furniture'
    ]);
    fclose($out);
    exit;
}

// ── Process CSV Upload ────────────────────────────────────────────────────
$importResults = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    $importErrors  = [];
    $importSuccess = 0;
    $rowErrors     = [];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $importErrors[] = 'File upload failed (error code ' . $file['error'] . ').';
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $importErrors[] = 'Only CSV files are accepted.';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $importErrors[] = 'File exceeds 5 MB limit.';
    } else {
        // Load category/department lookup maps (case-insensitive)
        $catMap  = [];
        $deptMap = [];
        foreach ($pdo->query("SELECT id, name FROM categories")->fetchAll() as $r) {
            $catMap[strtolower(trim($r['name']))] = (int)$r['id'];
        }
        foreach ($pdo->query("SELECT id, name FROM departments")->fetchAll() as $r) {
            $deptMap[strtolower(trim($r['name']))] = (int)$r['id'];
        }

        $validStatuses   = ['active','under_maintenance','disposed','lost'];
        $validDepMethods = ['straight_line','declining_balance'];

        $handle = fopen($file['tmp_name'], 'r');
        // Strip BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // Read header row
        $header = fgetcsv($handle);
        if ($header === false) {
            $importErrors[] = 'CSV file appears to be empty.';
        } else {
            // Normalize header names
            $header = array_map(fn($h) => strtolower(trim($h)), $header);
            $expectedCols = [
                'name','category_name','serial_number','model','brand',
                'purchase_date','purchase_cost','vendor','warranty_expiry',
                'useful_life_years','salvage_value','depreciation_method',
                'status','department_name','notes'
            ];

            // Map column positions
            $colIdx = [];
            foreach ($expectedCols as $col) {
                $pos = array_search($col, $header);
                $colIdx[$col] = ($pos !== false) ? $pos : null;
            }

            $rowNum = 1; // data rows start at 1
            $pdo->beginTransaction();
            try {
                while (($row = fgetcsv($handle)) !== false) {
                    $rowNum++;

                    // Skip entirely blank rows
                    if (count(array_filter(array_map('trim', $row))) === 0) continue;

                    // Helper to get value by column name
                    $get = function(string $col) use ($row, $colIdx): string {
                        $i = $colIdx[$col];
                        return ($i !== null && isset($row[$i])) ? trim($row[$i]) : '';
                    };

                    $name = $get('name');
                    if ($name === '') {
                        $rowErrors[] = "Row {$rowNum}: Skipped — 'name' is required.";
                        continue;
                    }

                    $rowErrs = [];

                    // Category lookup
                    $catName = $get('category_name');
                    $catId   = null;
                    if ($catName !== '') {
                        $catId = $catMap[strtolower($catName)] ?? null;
                        if ($catId === null) {
                            $rowErrs[] = "category '{$catName}' not found";
                        }
                    }

                    // Department lookup
                    $deptName = $get('department_name');
                    $deptId   = null;
                    if ($deptName !== '') {
                        $deptId = $deptMap[strtolower($deptName)] ?? null;
                        if ($deptId === null) {
                            $rowErrs[] = "department '{$deptName}' not found";
                        }
                    }

                    // Date validation
                    $purchDate = $get('purchase_date');
                    if ($purchDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchDate)) {
                        $rowErrs[] = "purchase_date must be YYYY-MM-DD";
                        $purchDate = '';
                    }
                    $warrantyExpiry = $get('warranty_expiry');
                    if ($warrantyExpiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $warrantyExpiry)) {
                        $rowErrs[] = "warranty_expiry must be YYYY-MM-DD";
                        $warrantyExpiry = '';
                    }

                    // Status validation
                    $status = $get('status');
                    if ($status === '') $status = 'active';
                    if (!in_array($status, $validStatuses, true)) {
                        $rowErrs[] = "status '{$status}' invalid (use: " . implode('|', $validStatuses) . ")";
                        $status = 'active';
                    }

                    // Depreciation method
                    $depMethod = $get('depreciation_method');
                    if ($depMethod === '') $depMethod = 'straight_line';
                    if (!in_array($depMethod, $validDepMethods, true)) {
                        $rowErrs[] = "depreciation_method '{$depMethod}' invalid (use: straight_line|declining_balance)";
                        $depMethod = 'straight_line';
                    }

                    // Numeric fields
                    $purchCost  = $get('purchase_cost');
                    $purchCost  = ($purchCost !== '') ? (float)$purchCost : 0.0;
                    $usefulLife = $get('useful_life_years');
                    $usefulLife = ($usefulLife !== '') ? max(1, (int)$usefulLife) : 5;
                    $salvage    = $get('salvage_value');
                    $salvage    = ($salvage !== '') ? (float)$salvage : 0.0;

                    if (!empty($rowErrs)) {
                        $rowErrors[] = "Row {$rowNum} ({$name}): " . implode('; ', $rowErrs) . " — row skipped.";
                        continue;
                    }

                    // Insert
                    $tag = generateAssetTag($pdo);
                    $st  = $pdo->prepare("INSERT INTO assets
                        (asset_tag, name, category_id, serial_number, model, brand,
                         purchase_date, purchase_cost, vendor, warranty_expiry,
                         useful_life_years, salvage_value, depreciation_method, status,
                         department_id, notes, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $st->execute([
                        $tag,
                        $name,
                        $catId,
                        $get('serial_number') ?: null,
                        $get('model')         ?: null,
                        $get('brand')         ?: null,
                        $purchDate            ?: null,
                        $purchCost,
                        $get('vendor')        ?: null,
                        $warrantyExpiry       ?: null,
                        $usefulLife,
                        $salvage,
                        $depMethod,
                        $status,
                        $deptId,
                        $get('notes')         ?: null,
                        $_SESSION['user_id'],
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    auditLog($pdo, 'import', 'assets', $newId, null, ['name' => $name, 'tag' => $tag, 'source' => 'csv_import']);
                    $importSuccess++;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $importErrors[] = 'Database error: ' . $e->getMessage();
            }
        }
        fclose($handle);
    }

    $importResults = [
        'success'    => $importSuccess,
        'row_errors' => $rowErrors,
        'errors'     => $importErrors,
    ];
}

// ── Output ────────────────────────────────────────────────────────────────
$pageTitle = 'Bulk Asset Import';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-start">
  <div>
    <h2><i class="bi bi-cloud-upload me-2" style="color:var(--brand-primary)"></i>Bulk Asset Import (CSV)</h2>
    <p class="text-muted mb-0">Import multiple assets at once using a CSV file.</p>
  </div>
  <a href="?download_template=1" class="btn btn-outline-success">
    <i class="bi bi-download me-1"></i>Download Template
  </a>
</div>

<?php if ($importResults !== null): ?>
  <?php if (!empty($importResults['errors'])): ?>
    <div class="alert alert-danger">
      <strong><i class="bi bi-x-circle me-1"></i>Import Failed</strong>
      <ul class="mb-0 mt-1">
        <?php foreach ($importResults['errors'] as $err): ?>
          <li><?= e($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php else: ?>
    <div class="alert alert-<?= $importResults['success'] > 0 ? 'success' : 'warning' ?>">
      <strong><i class="bi bi-check-circle me-1"></i>Import Complete</strong> —
      <strong><?= $importResults['success'] ?></strong> asset<?= $importResults['success'] !== 1 ? 's' : '' ?> imported successfully<?= !empty($importResults['row_errors']) ? ', with ' . count($importResults['row_errors']) . ' row(s) skipped' : '.' ?>
    </div>
    <?php if (!empty($importResults['row_errors'])): ?>
      <div class="card border-warning mb-4">
        <div class="card-header bg-warning bg-opacity-10 text-warning-emphasis">
          <i class="bi bi-exclamation-triangle me-1"></i>Skipped Rows
        </div>
        <div class="card-body p-0">
          <ul class="list-group list-group-flush">
            <?php foreach ($importResults['row_errors'] as $re): ?>
              <li class="list-group-item list-group-item-warning small"><?= e($re) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<!-- Instructions card -->
<div class="row g-4 mb-4">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-info-circle me-2"></i>How to Import</div>
      <div class="card-body">
        <ol class="mb-0">
          <li class="mb-2">Download the <strong>CSV template</strong> using the button above (top-right).</li>
          <li class="mb-2">Fill in your asset data — one asset per row. The <strong>name</strong> column is required; all others are optional.</li>
          <li class="mb-2">Dates must be in <code>YYYY-MM-DD</code> format (e.g. <code>2024-03-15</code>).</li>
          <li class="mb-2"><strong>category_name</strong> and <strong>department_name</strong> must exactly match existing records (case-insensitive). Rows with unrecognized names will be skipped.</li>
          <li class="mb-2">Save as <strong>CSV UTF-8</strong> from Excel or Google Sheets.</li>
          <li>Upload the file below and click <strong>Import Assets</strong>.</li>
        </ol>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-table me-2"></i>Column Reference</div>
      <div class="card-body p-0">
        <table class="table table-sm table-striped mb-0 small">
          <thead class="table-light">
            <tr><th>Column</th><th>Required</th><th>Notes</th></tr>
          </thead>
          <tbody>
            <tr><td><code>name</code></td><td><span class="badge bg-danger">Yes</span></td><td>Asset display name</td></tr>
            <tr><td><code>category_name</code></td><td><span class="badge bg-secondary">No</span></td><td>Must match DB category</td></tr>
            <tr><td><code>serial_number</code></td><td><span class="badge bg-secondary">No</span></td><td>Free text</td></tr>
            <tr><td><code>model</code></td><td><span class="badge bg-secondary">No</span></td><td>Free text</td></tr>
            <tr><td><code>brand</code></td><td><span class="badge bg-secondary">No</span></td><td>Free text</td></tr>
            <tr><td><code>purchase_date</code></td><td><span class="badge bg-secondary">No</span></td><td>YYYY-MM-DD</td></tr>
            <tr><td><code>purchase_cost</code></td><td><span class="badge bg-secondary">No</span></td><td>Numeric, e.g. 1850.00</td></tr>
            <tr><td><code>vendor</code></td><td><span class="badge bg-secondary">No</span></td><td>Supplier name</td></tr>
            <tr><td><code>warranty_expiry</code></td><td><span class="badge bg-secondary">No</span></td><td>YYYY-MM-DD</td></tr>
            <tr><td><code>useful_life_years</code></td><td><span class="badge bg-secondary">No</span></td><td>Integer, default 5</td></tr>
            <tr><td><code>salvage_value</code></td><td><span class="badge bg-secondary">No</span></td><td>Numeric, default 0</td></tr>
            <tr><td><code>depreciation_method</code></td><td><span class="badge bg-secondary">No</span></td><td><code>straight_line</code> or <code>declining_balance</code></td></tr>
            <tr><td><code>status</code></td><td><span class="badge bg-secondary">No</span></td><td><code>active</code> | <code>under_maintenance</code> | <code>disposed</code> | <code>lost</code></td></tr>
            <tr><td><code>department_name</code></td><td><span class="badge bg-secondary">No</span></td><td>Must match DB department</td></tr>
            <tr><td><code>notes</code></td><td><span class="badge bg-secondary">No</span></td><td>Free text</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Upload form -->
<div class="card">
  <div class="card-header"><i class="bi bi-upload me-2"></i>Upload CSV File</div>
  <div class="card-body">
    <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
      <div class="col-md-8">
        <label class="form-label fw-semibold small">Select CSV File <span class="text-danger">*</span></label>
        <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
        <div class="form-text">Max file size: 5 MB. UTF-8 CSV format.</div>
      </div>
      <div class="col-md-4">
        <button type="submit" class="btn btn-primary w-100">
          <i class="bi bi-cloud-upload me-1"></i>Import Assets
        </button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
