<?php
$pageTitle = 'Depreciation';
require_once __DIR__ . '/../../includes/header.php';

$settings = getSettings($pdo);
$currency = $settings['currency'] ?? 'USD';

$assets = $pdo->query(
    "SELECT a.*, c.name AS cat_name FROM assets a
     LEFT JOIN categories c ON a.category_id = c.id
     WHERE a.purchase_date IS NOT NULL AND a.purchase_cost > 0
     ORDER BY a.name"
)->fetchAll();
?>

<div class="page-header d-flex justify-content-between align-items-center">
  <div><h2><i class="bi bi-graph-down me-2 text-primary"></i>Depreciation Schedule</h2>
  <p>Current book values and depreciation status for all assets.</p></div>
  <?php if (isAdmin()): ?>
  <a href="run.php" class="btn btn-primary" onclick="return confirm('Run depreciation calculation for current year?')">
    <i class="bi bi-calculator me-1"></i>Run Depreciation
  </a>
  <?php endif; ?>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Asset</th>
          <th>Category</th>
          <th>Purchase Cost</th>
          <th>Purchase Date</th>
          <th>Method</th>
          <th>Useful Life</th>
          <th>Salvage Value</th>
          <th>Current Book Value</th>
          <th>Depreciated %</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($assets as $a):
          $bv   = currentBookValue((float)$a['purchase_cost'], (float)$a['salvage_value'], (int)$a['useful_life_years'], $a['depreciation_method'], $a['purchase_date']);
          $cost = (float)$a['purchase_cost'];
          $pct  = $cost > 0 ? round((($cost - $bv) / $cost) * 100, 1) : 0;
          $barClass = $pct >= 90 ? 'danger' : ($pct >= 60 ? 'warning' : 'success');
        ?>
        <tr>
          <td>
            <a href="/modules/assets/view.php?id=<?= $a['id'] ?>" class="text-dark fw-semibold text-decoration-none">
              <?= e($a['name']) ?>
            </a><br>
            <code class="small text-muted"><?= e($a['asset_tag']) ?></code>
          </td>
          <td><span class="badge bg-light text-dark"><?= e($a['cat_name'] ?? '—') ?></span></td>
          <td><?= formatMoney($cost, $currency) ?></td>
          <td><?= $a['purchase_date'] ? date('d M Y', strtotime($a['purchase_date'])) : '—' ?></td>
          <td><?= $a['depreciation_method'] === 'straight_line' ? 'SL' : 'DB' ?></td>
          <td><?= $a['useful_life_years'] ?> yrs</td>
          <td><?= formatMoney((float)$a['salvage_value'], $currency) ?></td>
          <td class="fw-bold text-<?= $bv <= (float)$a['salvage_value'] ? 'danger' : 'success' ?>"><?= formatMoney($bv, $currency) ?></td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="progress flex-grow-1" style="height:8px;">
                <div class="progress-bar bg-<?= $barClass ?>" style="width:<?= min(100,$pct) ?>%"></div>
              </div>
              <span class="small"><?= $pct ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($assets)): ?>
        <tr><td colspan="9" class="text-center text-muted py-5">No assets with purchase data found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
