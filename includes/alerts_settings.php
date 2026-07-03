<?php
/**
 * renderAlertsSettings(PDO $pdo, array $s): void
 *
 * Outputs the Alert Settings card HTML.
 * Expects $s = getSettings($pdo) (already called by caller).
 * Form POSTs to settings/index.php?tab=email with hidden _action=save_alerts.
 *
 * The caller (settings/index.php) must handle:
 *   if ($_POST['_action'] === 'save_alerts') { ... save fields ... }
 */

function renderAlertsSettings(PDO $pdo, array $s): void
{
    // Last 10 notification log entries
    $logs = $pdo->query("
        SELECT nl.id, nl.type, nl.sent_to, nl.sent_at, nl.details,
               a.asset_tag, a.name AS asset_name
        FROM notification_log nl
        LEFT JOIN assets a ON a.id = nl.asset_id
        ORDER BY nl.sent_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    $alertEmail      = htmlspecialchars($s['alert_email']      ?? '');
    $notifyWarranty  = ($s['notify_warranty']    ?? 0) ? 'checked' : '';
    $notifyEol       = ($s['notify_eol']         ?? 0) ? 'checked' : '';
    $notifyMaint     = ($s['notify_maintenance'] ?? 0) ? 'checked' : '';
    $warrantyDays    = (int)($s['warranty_alert_days'] ?? 30);
    ?>

<div class="card mb-4">
  <div class="card-header py-3">
    <span class="fw-semibold"><i class="bi bi-bell me-2 text-warning"></i>Alert Settings</span>
  </div>
  <div class="card-body">
    <form method="post" action="/modules/settings/index.php?tab=email">
      <input type="hidden" name="_action" value="save_alerts">

      <div class="mb-3">
        <label for="alert_email" class="form-label fw-semibold">Alert Recipient Email</label>
        <input type="email" class="form-control" id="alert_email" name="alert_email"
               value="<?= $alertEmail ?>"
               placeholder="admin@example.com">
        <div class="form-text">All automated alerts will be sent to this address.</div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold">Notification Types</label>
        <div class="d-flex flex-column gap-2">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="notify_warranty"
                   name="notify_warranty" value="1" <?= $notifyWarranty ?>>
            <label class="form-check-label" for="notify_warranty">
              <i class="bi bi-shield-exclamation text-warning me-1"></i>
              Notify on warranty expiry
            </label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="notify_eol"
                   name="notify_eol" value="1" <?= $notifyEol ?>>
            <label class="form-check-label" for="notify_eol">
              <i class="bi bi-exclamation-triangle text-danger me-1"></i>
              Notify when asset reaches end-of-life
            </label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="notify_maintenance"
                   name="notify_maintenance" value="1" <?= $notifyMaint ?>>
            <label class="form-check-label" for="notify_maintenance">
              <i class="bi bi-wrench text-primary me-1"></i>
              Notify on upcoming maintenance due dates
            </label>
          </div>
        </div>
      </div>

      <div class="mb-4" style="max-width:280px;">
        <label for="warranty_alert_days" class="form-label fw-semibold">Warranty Alert Lead Time (days)</label>
        <input type="number" class="form-control" id="warranty_alert_days"
               name="warranty_alert_days" value="<?= $warrantyDays ?>"
               min="1" max="365">
        <div class="form-text">Send warranty alert this many days before expiry. Default: 30.</div>
      </div>

      <button type="submit" class="btn btn-primary btn-sm">
        <i class="bi bi-floppy me-1"></i>Save Alert Settings
      </button>
    </form>
  </div>
</div>

<!-- Recent Notification Log -->
<div class="card">
  <div class="card-header py-3 d-flex justify-content-between align-items-center">
    <span class="fw-semibold"><i class="bi bi-journal-text me-2"></i>Recent Notifications Sent</span>
    <span class="badge bg-secondary"><?= count($logs) ?> entries</span>
  </div>
  <?php if (empty($logs)): ?>
  <div class="card-body text-center text-muted py-4">
    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
    No notifications have been sent yet.
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Type</th>
          <th>Asset</th>
          <th>Sent To</th>
          <th>When</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $log):
          $typeLabel = match($log['type']) {
              'warranty_alert'    => '<span class="badge bg-warning text-dark"><i class="bi bi-shield-exclamation me-1"></i>Warranty</span>',
              'eol_alert'         => '<span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>EOL</span>',
              'maintenance_alert' => '<span class="badge bg-primary"><i class="bi bi-wrench me-1"></i>Maintenance</span>',
              default             => '<span class="badge bg-secondary">' . htmlspecialchars($log['type']) . '</span>',
          };
          $assetDisplay = $log['asset_tag']
              ? '<code class="text-primary small">' . htmlspecialchars($log['asset_tag']) . '</code>'
                . '<br><small class="text-muted">' . htmlspecialchars($log['asset_name'] ?? '') . '</small>'
              : '<span class="text-muted">—</span>';
          $sentAt = $log['sent_at'] ? date('d M Y H:i', strtotime($log['sent_at'])) : '—';
        ?>
        <tr>
          <td><?= $typeLabel ?></td>
          <td><?= $assetDisplay ?></td>
          <td><small><?= htmlspecialchars($log['sent_to']) ?></small></td>
          <td><small class="text-muted"><?= $sentAt ?></small></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php
}
