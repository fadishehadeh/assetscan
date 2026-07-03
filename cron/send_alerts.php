<?php
/**
 * Cron alert sender — run via: php send_alerts.php
 * No HTML output, no session, no auth.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/functions.php';

$s = getSettings($pdo);

$alertEmail = $s['alert_email'] ?? '';
$warrantySend  = ($s['notify_warranty']    ?? 0) == 1;
$eolSend       = ($s['notify_eol']         ?? 0) == 1;
$maintSend     = ($s['notify_maintenance'] ?? 0) == 1;
$warningDays   = (int)($s['warranty_alert_days'] ?? 30);

$sentWarranty = 0;
$sentEol      = 0;
$sentMaint    = 0;

// ─────────────────────────────────────────────────────────
// A) WARRANTY ALERTS
// ─────────────────────────────────────────────────────────
if ($warrantySend && $alertEmail) {
    $stmt = $pdo->prepare("
        SELECT a.id, a.asset_tag, a.name, a.warranty_expiry,
               DATEDIFF(a.warranty_expiry, CURDATE()) AS days_remaining
        FROM assets a
        WHERE a.warranty_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
          AND a.status != 'disposed'
          AND NOT EXISTS (
              SELECT 1 FROM notification_log nl
              WHERE nl.type = 'warranty_alert'
                AND nl.asset_id = a.id
                AND DATE(nl.sent_at) = CURDATE()
          )
    ");
    $stmt->execute([':days' => $warningDays]);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($assets as $asset) {
        $subject = "⚠ Warranty Expiring Soon: {$asset['asset_tag']}";
        $expiry  = htmlspecialchars($asset['warranty_expiry']);
        $tag     = htmlspecialchars($asset['asset_tag']);
        $name    = htmlspecialchars($asset['name']);
        $days    = (int)$asset['days_remaining'];

        $html = <<<HTML
        <h2 style="color:#d97706;">⚠ Warranty Expiring Soon</h2>
        <table style="border-collapse:collapse;font-family:sans-serif;font-size:14px;">
          <tr><td style="padding:6px 12px;font-weight:bold;color:#555;">Asset Name</td><td style="padding:6px 12px;">{$name}</td></tr>
          <tr><td style="padding:6px 12px;font-weight:bold;color:#555;">Asset Tag</td><td style="padding:6px 12px;font-family:monospace;">{$tag}</td></tr>
          <tr><td style="padding:6px 12px;font-weight:bold;color:#555;">Warranty Expiry</td><td style="padding:6px 12px;">{$expiry}</td></tr>
          <tr><td style="padding:6px 12px;font-weight:bold;color:#555;">Days Remaining</td><td style="padding:6px 12px;color:#d97706;font-weight:bold;">{$days} day(s)</td></tr>
        </table>
        <p style="font-size:12px;color:#888;margin-top:20px;">This is an automated alert from your Asset Management System.</p>
        HTML;

        $sent = sendMail($pdo, $alertEmail, 'Asset Manager Admin', $subject, $html);
        if ($sent) {
            $pdo->prepare("
                INSERT INTO notification_log (type, asset_id, sent_to, sent_at, details)
                VALUES ('warranty_alert', :asset_id, :sent_to, NOW(), :details)
            ")->execute([
                ':asset_id' => $asset['id'],
                ':sent_to'  => $alertEmail,
                ':details'  => json_encode(['asset_tag' => $asset['asset_tag'], 'warranty_expiry' => $asset['warranty_expiry'], 'days_remaining' => $days]),
            ]);
            $sentWarranty++;
        }
    }
}

// ─────────────────────────────────────────────────────────
// B) EOL ALERTS
// ─────────────────────────────────────────────────────────
if ($eolSend && $alertEmail) {
    $eolAssets = $pdo->query("
        SELECT a.id, a.asset_tag, a.name, a.purchase_date, a.useful_life_years,
               TIMESTAMPDIFF(YEAR, a.purchase_date, CURDATE()) AS age_years
        FROM assets a
        WHERE a.status = 'active'
          AND a.purchase_date IS NOT NULL
          AND TIMESTAMPDIFF(YEAR, a.purchase_date, CURDATE()) >= a.useful_life_years
          AND NOT EXISTS (
              SELECT 1 FROM notification_log nl
              WHERE nl.type = 'eol_alert'
                AND nl.asset_id = a.id
                AND nl.sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
          )
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($eolAssets as $asset) {
        $subject = "Asset End of Life: {$asset['asset_tag']}";
        $tag     = htmlspecialchars($asset['asset_tag']);
        $name    = htmlspecialchars($asset['name']);
        $purchaseDate = htmlspecialchars($asset['purchase_date']);
        $lifeYears    = (int)$asset['useful_life_years'];
        $ageYears     = (int)$asset['age_years'];

        $html = <<<HTML
        <h2 style="color:#dc2626;">⚠ Asset End of Life</h2>
        <p>The following asset has exceeded its useful life and may require replacement or retirement.</p>
        <table style="border-collapse:collapse;font-family:sans-serif;font-size:14px;">
          <tr><td style="padding:6px 12px;font-weight:bold;color:#555;">Asset Name</td><td style="padding:6px 12px;">{$name}</td></tr>
          <tr><td style="padding:6px 12px;font-weight:bold;color:#555;">Asset Tag</td><td style="padding:6px 12px;font-family:monospace;">{$tag}</td></tr>
          <tr><td style="padding:6px 12px;font-weight:bold;color:#555;">Purchase Date</td><td style="padding:6px 12px;">{$purchaseDate}</td></tr>
          <tr><td style="padding:6px 12px;font-weight:bold;color:#555;">Useful Life</td><td style="padding:6px 12px;">{$lifeYears} year(s)</td></tr>
          <tr><td style="padding:6px 12px;font-weight:bold;color:#555;">Current Age</td><td style="padding:6px 12px;color:#dc2626;font-weight:bold;">{$ageYears} year(s)</td></tr>
        </table>
        <p style="font-size:12px;color:#888;margin-top:20px;">This is an automated alert from your Asset Management System.</p>
        HTML;

        $sent = sendMail($pdo, $alertEmail, 'Asset Manager Admin', $subject, $html);
        if ($sent) {
            $pdo->prepare("
                INSERT INTO notification_log (type, asset_id, sent_to, sent_at, details)
                VALUES ('eol_alert', :asset_id, :sent_to, NOW(), :details)
            ")->execute([
                ':asset_id' => $asset['id'],
                ':sent_to'  => $alertEmail,
                ':details'  => json_encode(['asset_tag' => $asset['asset_tag'], 'age_years' => $ageYears, 'useful_life_years' => $lifeYears]),
            ]);
            $sentEol++;
        }
    }
}

// ─────────────────────────────────────────────────────────
// C) MAINTENANCE DUE ALERTS
// ─────────────────────────────────────────────────────────
if ($maintSend && $alertEmail) {
    $maintRecords = $pdo->query("
        SELECT m.id, m.asset_id, m.type, m.technician, m.next_due_date,
               a.asset_tag, a.name AS asset_name,
               DATEDIFF(m.next_due_date, CURDATE()) AS days_until_due
        FROM maintenance m
        JOIN assets a ON a.id = m.asset_id
        WHERE m.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND m.next_due_date >= CURDATE()
          AND m.status = 'scheduled'
          AND NOT EXISTS (
              SELECT 1 FROM notification_log nl
              WHERE nl.type = 'maintenance_alert'
                AND nl.asset_id = m.asset_id
                AND DATE(nl.sent_at) = CURDATE()
                AND JSON_EXTRACT(nl.details, '$.maintenance_id') = m.id
          )
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($maintRecords as $rec) {
        $subject  = "Maintenance Due: {$rec['asset_tag']}";
        $tag      = htmlspecialchars($rec['asset_tag']);
        $name     = htmlspecialchars($rec['asset_name']);
        $type     = htmlspecialchars(ucwords(str_replace('_', ' ', $rec['type'])));
        $tech     = htmlspecialchars($rec['technician'] ?? 'Not assigned');
        $dueDate  = htmlspecialchars($rec['next_due_date']);
        $daysLeft = (int)$rec['days_until_due'];

        $html = <<<HTML
        <h2 style="color:#2563eb;">🔧 Maintenance Due Soon</h2>
        <p>A scheduled maintenance task is due within 7 days.</p>
        <table style="border-collapse:collapse;font-family:sans-serif;font-size:14px;">
          <tr><td style="padding:6px 12px;font-weight:bold;color:#555;">Asset Name</td><td style="padding:6px 12px;">{$name}</td></tr>
          <tr><td style="padding:6px 12px;font-weight:bold;color:#555;">Asset Tag</td><td style="padding:6px 12px;font-family:monospace;">{$tag}</td></tr>
          <tr><td style="padding:6px 12px;font-weight:bold;color:#555;">Maintenance Type</td><td style="padding:6px 12px;">{$type}</td></tr>
          <tr><td style="padding:6px 12px;font-weight:bold;color:#555;">Technician</td><td style="padding:6px 12px;">{$tech}</td></tr>
          <tr><td style="padding:6px 12px;font-weight:bold;color:#555;">Due Date</td><td style="padding:6px 12px;">{$dueDate}</td></tr>
          <tr><td style="padding:6px 12px;font-weight:bold;color:#555;">Days Until Due</td><td style="padding:6px 12px;color:#2563eb;font-weight:bold;">{$daysLeft} day(s)</td></tr>
        </table>
        <p style="font-size:12px;color:#888;margin-top:20px;">This is an automated alert from your Asset Management System.</p>
        HTML;

        $sent = sendMail($pdo, $alertEmail, 'Asset Manager Admin', $subject, $html);
        if ($sent) {
            $pdo->prepare("
                INSERT INTO notification_log (type, asset_id, sent_to, sent_at, details)
                VALUES ('maintenance_alert', :asset_id, :sent_to, NOW(), :details)
            ")->execute([
                ':asset_id' => $rec['asset_id'],
                ':sent_to'  => $alertEmail,
                ':details'  => json_encode(['maintenance_id' => $rec['id'], 'asset_tag' => $rec['asset_tag'], 'type' => $rec['type'], 'next_due_date' => $rec['next_due_date']]),
            ]);
            $sentMaint++;
        }
    }
}

echo "Sent {$sentWarranty} warranty alerts, {$sentEol} EOL alerts, {$sentMaint} maintenance alerts." . PHP_EOL;
