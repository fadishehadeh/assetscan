<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!isAdmin()) {
    setFlash('danger', 'Access denied.');
    header('Location: /index.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setFlash('danger', 'Invalid disposal request.');
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT dr.*,
            a.asset_tag, a.name AS asset_name, a.serial_number, a.brand, a.model,
            a.purchase_cost,
            c.name AS category_name,
            ru.name AS requested_by_name, ru.email AS requested_by_email,
            au.name AS approved_by_name,
            d.name AS dept_name
     FROM disposal_requests dr
     JOIN assets a         ON dr.asset_id     = a.id
     LEFT JOIN categories c  ON a.category_id  = c.id
     LEFT JOIN users ru      ON dr.requested_by = ru.id
     LEFT JOIN users au      ON dr.approved_by  = au.id
     LEFT JOIN departments d ON a.department_id = d.id
     WHERE dr.id = ? AND dr.status = 'approved'"
);
$stmt->execute([$id]);
$disposal = $stmt->fetch();

if (!$disposal) {
    setFlash('danger', 'Disposal certificate not found or request not yet approved.');
    header('Location: index.php');
    exit;
}

// Load app settings for branding
$settings   = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$company    = $settings['company_name'] ?? 'Organization';
$appName    = $settings['app_name']     ?? 'Asset Manager';
$primaryClr = $settings['primary_color'] ?? '#E84B37';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate of Disposal — <?= e($disposal['certificate_no']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --brand-primary: <?= e($primaryClr) ?>;
        }

        body {
            background: #f4f6f9;
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        .cert-wrapper {
            max-width: 820px;
            margin: 40px auto;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .cert-header {
            background: var(--brand-primary);
            color: #fff;
            padding: 36px 48px 28px;
            text-align: center;
        }

        .cert-header h1 {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            margin: 0;
        }

        .cert-header .org-name {
            font-size: 1.05rem;
            opacity: 0.9;
            margin-top: 4px;
        }

        .cert-badge {
            display: inline-block;
            background: rgba(255,255,255,0.18);
            border: 2px solid rgba(255,255,255,0.6);
            border-radius: 50px;
            padding: 6px 24px;
            font-size: 0.95rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            margin-top: 14px;
        }

        .cert-body {
            padding: 40px 48px;
        }

        .cert-no {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--brand-primary);
            border-bottom: 2px solid var(--brand-primary);
            display: inline-block;
            padding-bottom: 4px;
            margin-bottom: 28px;
        }

        .info-section {
            margin-bottom: 28px;
        }

        .info-section h5 {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #6c757d;
            margin-bottom: 12px;
            border-bottom: 1px solid #e9ecef;
            padding-bottom: 6px;
        }

        .info-row {
            display: flex;
            margin-bottom: 8px;
        }

        .info-label {
            min-width: 200px;
            font-weight: 600;
            color: #495057;
            font-size: 0.92rem;
        }

        .info-value {
            color: #212529;
            font-size: 0.92rem;
        }

        .cert-footer {
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            padding: 24px 48px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .sig-block {
            text-align: center;
        }

        .sig-line {
            width: 200px;
            border-bottom: 1.5px solid #212529;
            margin: 0 auto 6px;
            height: 36px;
        }

        .sig-label {
            font-size: 0.8rem;
            color: #6c757d;
        }

        .no-print {
            max-width: 820px;
            margin: 0 auto 32px;
            display: flex;
            gap: 12px;
        }

        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .cert-wrapper {
                box-shadow: none;
                border: 1px solid #ccc;
                margin: 0;
                border-radius: 0;
            }
        }
    </style>
</head>
<body>

<div class="no-print mt-4 px-3">
    <button onclick="window.print()" class="btn btn-primary">
        <i class="bi bi-printer me-1"></i>Print / Save as PDF
    </button>
    <a href="index.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Disposal Requests
    </a>
</div>

<div class="cert-wrapper">

    <!-- Header -->
    <div class="cert-header">
        <div class="org-name"><?= e($company) ?> &mdash; <?= e($appName) ?></div>
        <h1><i class="bi bi-shield-check me-2"></i>Certificate of Disposal</h1>
        <div class="cert-badge">OFFICIAL DISPOSAL CERTIFICATE</div>
    </div>

    <!-- Body -->
    <div class="cert-body">

        <div class="text-center mb-4">
            <span class="cert-no"><i class="bi bi-hash"></i> <?= e($disposal['certificate_no']) ?></span>
        </div>

        <p class="text-muted mb-4" style="font-size:0.95rem;">
            This certificate confirms that the asset described below has been officially authorized
            for disposal in accordance with organizational asset management policies.
        </p>

        <!-- Asset Details -->
        <div class="info-section">
            <h5><i class="bi bi-tag me-1"></i>Asset Details</h5>
            <div class="info-row">
                <span class="info-label">Asset Tag</span>
                <span class="info-value"><?= e($disposal['asset_tag']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Asset Name</span>
                <span class="info-value"><?= e($disposal['asset_name']) ?></span>
            </div>
            <?php if ($disposal['brand'] || $disposal['model']): ?>
            <div class="info-row">
                <span class="info-label">Brand / Model</span>
                <span class="info-value"><?= e(trim(($disposal['brand'] ?? '') . ' ' . ($disposal['model'] ?? ''))) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($disposal['serial_number']): ?>
            <div class="info-row">
                <span class="info-label">Serial Number</span>
                <span class="info-value"><?= e($disposal['serial_number']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($disposal['category_name']): ?>
            <div class="info-row">
                <span class="info-label">Category</span>
                <span class="info-value"><?= e($disposal['category_name']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($disposal['dept_name']): ?>
            <div class="info-row">
                <span class="info-label">Department</span>
                <span class="info-value"><?= e($disposal['dept_name']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($disposal['purchase_cost']): ?>
            <div class="info-row">
                <span class="info-label">Original Purchase Cost</span>
                <span class="info-value"><?= formatMoney((float)$disposal['purchase_cost']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Disposal Details -->
        <div class="info-section">
            <h5><i class="bi bi-trash3 me-1"></i>Disposal Details</h5>
            <div class="info-row">
                <span class="info-label">Disposal Method</span>
                <span class="info-value">
                    <strong><?= ucfirst(e($disposal['disposal_method'])) ?></strong>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Reason for Disposal</span>
                <span class="info-value"><?= e($disposal['reason']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Date Requested</span>
                <span class="info-value"><?= date('d F Y', strtotime($disposal['requested_at'])) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Date Approved</span>
                <span class="info-value"><?= date('d F Y', strtotime($disposal['resolved_at'])) ?></span>
            </div>
        </div>

        <!-- Authorization -->
        <div class="info-section">
            <h5><i class="bi bi-person-check me-1"></i>Authorization</h5>
            <div class="info-row">
                <span class="info-label">Requested By</span>
                <span class="info-value"><?= e($disposal['requested_by_name'] ?? '—') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Approved By</span>
                <span class="info-value"><strong><?= e($disposal['approved_by_name'] ?? '—') ?></strong></span>
            </div>
        </div>

    </div><!-- /cert-body -->

    <!-- Footer / Signatures -->
    <div class="cert-footer">
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Requested By: <?= e($disposal['requested_by_name'] ?? '') ?></div>
        </div>

        <div class="text-center text-muted" style="font-size:0.78rem;">
            <div>Generated by <?= e($appName) ?></div>
            <div><?= date('d F Y, H:i') ?></div>
        </div>

        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Approved By: <?= e($disposal['approved_by_name'] ?? '') ?></div>
        </div>
    </div>

</div><!-- /cert-wrapper -->

</body>
</html>
