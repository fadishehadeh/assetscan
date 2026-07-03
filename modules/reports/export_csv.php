<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$report      = $_GET['report']   ?? 'assets';
$filterCat   = (int)($_GET['category'] ?? 0);
$filterStatus = $_GET['status']  ?? '';
$filterDept  = (int)($_GET['dept'] ?? 0);
$search      = trim($_GET['search'] ?? '');
$ids         = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
$format      = $_GET['format']   ?? 'csv'; // csv or excel

$settings = getSettings($pdo);
$currency = $settings['currency'] ?? 'USD';

// ── Build query based on report type ─────────────────────────────────
switch ($report) {
    case 'eol':
        $filename = 'eol_report_' . date('Ymd');
        $headers  = ['Asset Tag','Name','Category','Department','Purchase Date','Useful Life','Age (Years)','Purchase Cost','Assigned To'];
        $rows = $pdo->query(
            "SELECT a.asset_tag, a.name, c.name AS category, d.name AS department,
                    a.purchase_date, a.useful_life_years,
                    TIMESTAMPDIFF(YEAR, a.purchase_date, CURDATE()) AS age_years,
                    a.purchase_cost, u.name AS assigned_to
             FROM assets a
             LEFT JOIN categories c ON a.category_id=c.id
             LEFT JOIN departments d ON a.department_id=d.id
             LEFT JOIN users u ON a.assigned_to=u.id
             WHERE a.status='active' AND a.purchase_date IS NOT NULL
               AND TIMESTAMPDIFF(YEAR, a.purchase_date, CURDATE()) >= a.useful_life_years
             ORDER BY age_years DESC"
        )->fetchAll();
        $rowFn = fn($r) => [$r['asset_tag'],$r['name'],$r['category']??'',$r['department']??'',$r['purchase_date'],$r['useful_life_years'].' yrs',$r['age_years'],$r['purchase_cost'],$r['assigned_to']??''];
        break;

    case 'warranty':
        $filename = 'warranty_report_' . date('Ymd');
        $headers  = ['Asset Tag','Name','Category','Department','Warranty Expiry','Days Left','Assigned To'];
        $rows = $pdo->query(
            "SELECT a.asset_tag, a.name, c.name AS category, d.name AS department,
                    a.warranty_expiry, DATEDIFF(a.warranty_expiry, CURDATE()) AS days_left, u.name AS assigned_to
             FROM assets a
             LEFT JOIN categories c ON a.category_id=c.id
             LEFT JOIN departments d ON a.department_id=d.id
             LEFT JOIN users u ON a.assigned_to=u.id
             WHERE a.warranty_expiry IS NOT NULL
             ORDER BY a.warranty_expiry ASC"
        )->fetchAll();
        $rowFn = fn($r) => [$r['asset_tag'],$r['name'],$r['category']??'',$r['department']??'',$r['warranty_expiry'],$r['days_left'],$r['assigned_to']??''];
        break;

    case 'depreciation':
        $filename = 'depreciation_report_' . date('Ymd');
        $headers  = ['Asset Tag','Name','Category','Purchase Date','Purchase Cost','Useful Life','Salvage Value','Method','Current Book Value'];
        $rows = $pdo->query(
            "SELECT a.asset_tag, a.name, c.name AS category, a.purchase_date,
                    a.purchase_cost, a.useful_life_years, a.salvage_value, a.depreciation_method
             FROM assets a LEFT JOIN categories c ON a.category_id=c.id
             WHERE a.purchase_cost > 0 AND a.purchase_date IS NOT NULL ORDER BY a.name"
        )->fetchAll();
        $rowFn = function($r) use ($currency) {
            $bv = currentBookValue((float)$r['purchase_cost'],(float)$r['salvage_value'],(int)$r['useful_life_years'],$r['depreciation_method'],$r['purchase_date']);
            return [$r['asset_tag'],$r['name'],$r['category']??'',$r['purchase_date'],$r['purchase_cost'],$r['useful_life_years'].' yrs',$r['salvage_value'],$r['depreciation_method'],$bv];
        };
        break;

    default: // full asset register, with filters
        $filename = 'assets_' . date('Ymd');
        $headers  = ['Asset Tag','Name','Category','Brand','Model','Serial Number','Purchase Date','Purchase Cost','Vendor','Warranty Expiry','Useful Life (Yrs)','Salvage Value','Dep. Method','Status','Department','Building','Floor','Room','Assigned To'];

        $where  = "WHERE 1=1";
        $params = [];

        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $where .= " AND a.id IN ($ph)";
            $params = array_merge($params, $ids);
            $filename .= '_selected';
        } else {
            if ($search) {
                $where .= " AND (a.asset_tag LIKE ? OR a.name LIKE ? OR a.serial_number LIKE ?)";
                $params = array_merge($params, ["%$search%","%$search%","%$search%"]);
            }
            if ($filterCat)    { $where .= " AND a.category_id=?";   $params[] = $filterCat; }
            if ($filterStatus) { $where .= " AND a.status=?";         $params[] = $filterStatus; }
            if ($filterDept)   { $where .= " AND a.department_id=?";  $params[] = $filterDept; }
        }

        $st = $pdo->prepare(
            "SELECT a.asset_tag, a.name, c.name AS category, a.brand, a.model,
                    a.serial_number, a.purchase_date, a.purchase_cost, a.vendor,
                    a.warranty_expiry, a.useful_life_years, a.salvage_value,
                    a.depreciation_method, a.status, d.name AS department,
                    l.building, l.floor, l.room, u.name AS assigned_to
             FROM assets a
             LEFT JOIN categories c ON a.category_id=c.id
             LEFT JOIN departments d ON a.department_id=d.id
             LEFT JOIN locations l ON a.location_id=l.id
             LEFT JOIN users u ON a.assigned_to=u.id
             $where ORDER BY a.name"
        );
        $st->execute($params);
        $rows = $st->fetchAll();
        $rowFn = fn($r) => [
            $r['asset_tag'],$r['name'],$r['category']??'',$r['brand']??'',$r['model']??'',$r['serial_number']??'',
            $r['purchase_date']??'',$r['purchase_cost'],$r['vendor']??'',$r['warranty_expiry']??'',
            $r['useful_life_years'],$r['salvage_value'],$r['depreciation_method'],$r['status'],
            $r['department']??'',$r['building']??'',$r['floor']??'',$r['room']??'',$r['assigned_to']??''
        ];
}

// ── Output ───────────────────────────────────────────────────────────
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Pragma: no-cache');

    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="utf-8"><style>td,th{mso-number-format:"@";font-family:Arial;font-size:11pt;}th{background:#E84B37;color:white;font-weight:bold;}</style></head><body><table border="1">';
    echo '<tr>' . implode('', array_map(fn($h) => '<th>' . htmlspecialchars($h) . '</th>', $headers)) . '</tr>';
    foreach ($rows as $row) {
        $cells = $rowFn($row);
        echo '<tr>' . implode('', array_map(fn($c) => '<td>' . htmlspecialchars((string)$c) . '</td>', $cells)) . '</tr>';
    }
    echo '</table></body></html>';
} else {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $rowFn($row));
    }
    fclose($out);
}
