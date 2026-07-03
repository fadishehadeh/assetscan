<?php
/**
 * One-time seeder: inserts 50 realistic demo assets.
 * Run once via browser: http://localhost/seed_assets.php
 * DELETE this file after running.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Safety: only allow from localhost
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403); exit('Forbidden');
}

// Fetch IDs from DB so foreign keys are valid
$cats  = $pdo->query("SELECT id, name, type FROM categories")->fetchAll(PDO::FETCH_ASSOC);
$depts = $pdo->query("SELECT id FROM departments")->fetchAll(PDO::FETCH_COLUMN);
$locs  = $pdo->query("SELECT id FROM locations")->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query("SELECT id FROM users WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN);

$catByType = [];
foreach ($cats as $c) $catByType[$c['type']][] = $c['id'];

$assets = [
    // IT
    ['Dell Latitude 5540 Laptop',       'IT',          'Dell',       'Latitude 5540',      'SN-DL-001', '2023-03-15', 1850.00, 5, 200.00, 'straight_line',    '2026-03-15', 'Dell Technologies',   'active'],
    ['HP EliteBook 840 G10',            'IT',          'HP',         'EliteBook 840 G10',  'SN-HP-002', '2022-08-20', 1600.00, 5, 150.00, 'straight_line',    '2025-08-20', 'HP Inc.',             'active'],
    ['Apple MacBook Pro 14"',           'IT',          'Apple',      'MacBook Pro 14',     'SN-AP-003', '2023-11-01', 2499.00, 5, 300.00, 'declining_balance', '2026-11-01', 'Apple Store',         'active'],
    ['Lenovo ThinkPad X1 Carbon',       'IT',          'Lenovo',     'ThinkPad X1',        'SN-LN-004', '2021-06-10', 1750.00, 5, 175.00, 'straight_line',    '2024-06-10', 'Lenovo',              'active'],
    ['Dell OptiPlex 7010 Desktop',      'IT',          'Dell',       'OptiPlex 7010',      'SN-DL-005', '2022-01-05', 950.00,  5, 100.00, 'straight_line',    '2025-01-05', 'Dell Technologies',   'active'],
    ['HP Z4 Workstation',               'IT',          'HP',         'Z4 G5',              'SN-HP-006', '2023-07-22', 3200.00, 7, 350.00, 'declining_balance', '2026-07-22', 'HP Inc.',             'active'],
    ['iMac 24" M3',                     'IT',          'Apple',      'iMac 24 M3',         'SN-AP-007', '2024-01-10', 1899.00, 5, 200.00, 'straight_line',    '2027-01-10', 'Apple Store',         'active'],
    ['Microsoft Surface Pro 9',         'IT',          'Microsoft',  'Surface Pro 9',      'SN-MS-008', '2023-05-18', 1399.00, 4, 140.00, 'straight_line',    '2025-05-18', 'Microsoft',           'active'],
    ['Dell PowerEdge R750 Server',      'IT',          'Dell',       'PowerEdge R750',     'SN-DL-009', '2022-09-30', 8500.00, 7, 800.00, 'straight_line',    '2025-09-30', 'Dell Technologies',   'active'],
    ['HP ProLiant DL380 Gen10',         'IT',          'HP',         'ProLiant DL380',     'SN-HP-010', '2021-11-15', 7200.00, 7, 700.00, 'declining_balance', '2024-11-15', 'HP Inc.',             'under_maintenance'],
    // Networking
    ['Cisco Catalyst 9300 Switch',      'Networking',  'Cisco',      'Catalyst 9300',      'SN-CS-011', '2022-04-20', 4500.00, 7, 450.00, 'straight_line',    '2025-04-20', 'Cisco Systems',       'active'],
    ['Ubiquiti UniFi AP AC Pro',        'Networking',  'Ubiquiti',   'UAP-AC-PRO',         'SN-UB-012', '2023-02-14', 180.00,  5, 20.00,  'straight_line',    '2026-02-14', 'Ubiquiti Inc.',       'active'],
    ['Fortinet FortiGate 100F Firewall','Networking',  'Fortinet',   'FortiGate 100F',     'SN-FT-013', '2022-12-01', 3800.00, 5, 380.00, 'declining_balance', '2025-12-01', 'Fortinet',            'active'],
    ['Cisco ASR 1001-X Router',         'Networking',  'Cisco',      'ASR 1001-X',         'SN-CS-014', '2021-08-05', 5200.00, 7, 500.00, 'straight_line',    '2024-08-05', 'Cisco Systems',       'active'],
    ['Netgear M4300 Switch',            'Networking',  'Netgear',    'M4300-52G',          'SN-NG-015', '2023-06-30', 1200.00, 5, 120.00, 'straight_line',    '2026-06-30', 'Netgear',             'active'],
    // Software (licenses — virtual assets)
    ['Microsoft 365 E3 License (50u)',  'Software',    'Microsoft',  'M365 E3',            'LIC-MS-016', '2024-01-01', 7500.00, 3, 0.00,  'straight_line',    null,         'Microsoft',           'active'],
    ['Adobe Creative Cloud Team',       'Software',    'Adobe',      'CC Teams 25u',       'LIC-AD-017', '2023-07-01', 9000.00, 3, 0.00,  'straight_line',    null,         'Adobe Inc.',          'active'],
    ['Autodesk AutoCAD 2024',           'Software',    'Autodesk',   'AutoCAD 2024',       'LIC-AU-018', '2024-03-01', 2400.00, 3, 0.00,  'straight_line',    null,         'Autodesk',            'active'],
    ['Windows Server 2022 Standard',    'Software',    'Microsoft',  'Win Server 2022',    'LIC-MS-019', '2022-09-01', 1200.00, 5, 0.00,  'straight_line',    null,         'Microsoft',           'active'],
    ['Kaspersky Endpoint Security 50u', 'Software',    'Kaspersky',  'KES Cloud 50u',      'LIC-KS-020', '2024-01-15', 1800.00, 3, 0.00,  'straight_line',    null,         'Kaspersky',           'active'],
    // Printers
    ['HP LaserJet Enterprise M507',     'Printers',    'HP',         'LJ Enterprise M507', 'SN-HP-021', '2022-05-10', 650.00,  5, 65.00,  'straight_line',    '2025-05-10', 'HP Inc.',             'active'],
    ['Canon imageRUNNER 2630',          'Printers',    'Canon',      'iR 2630',            'SN-CN-022', '2021-09-20', 2200.00, 5, 220.00, 'declining_balance', '2024-09-20', 'Canon Qatar',         'active'],
    ['Epson EcoTank ET-16650',          'Printers',    'Epson',      'EcoTank ET-16650',   'SN-EP-023', '2023-03-05', 580.00,  5, 58.00,  'straight_line',    '2026-03-05', 'Epson',               'active'],
    // Phones
    ['iPhone 15 Pro',                   'Phones',      'Apple',      'iPhone 15 Pro',      'SN-IP-024', '2023-10-01', 1300.00, 3, 130.00, 'declining_balance', '2025-10-01', 'Apple Store',         'active'],
    ['Samsung Galaxy S24 Ultra',        'Phones',      'Samsung',    'Galaxy S24 Ultra',   'SN-SG-025', '2024-02-20', 1200.00, 3, 120.00, 'declining_balance', '2027-02-20', 'Samsung',             'active'],
    ['iPhone 14',                       'Phones',      'Apple',      'iPhone 14',          'SN-IP-026', '2022-11-01', 999.00,  3, 100.00, 'declining_balance', '2025-11-01', 'Apple Store',         'active'],
    ['Google Pixel 8 Pro',              'Phones',      'Google',     'Pixel 8 Pro',        'SN-GP-027', '2024-01-05', 999.00,  3, 100.00, 'declining_balance', '2027-01-05', 'Google',              'active'],
    // Monitors
    ['Dell UltraSharp U2723D 27"',      'Monitors',    'Dell',       'U2723D',             'SN-DL-028', '2023-04-10', 699.00,  5, 70.00,  'straight_line',    '2026-04-10', 'Dell Technologies',   'active'],
    ['LG 32UN880 UltraFine',            'Monitors',    'LG',         '32UN880',            'SN-LG-029', '2022-08-15', 799.00,  5, 80.00,  'straight_line',    '2025-08-15', 'LG Electronics',      'active'],
    ['Samsung 49" Odyssey G9',          'Monitors',    'Samsung',    'Odyssey G9',         'SN-SG-030', '2023-01-20', 1200.00, 5, 120.00, 'declining_balance', '2026-01-20', 'Samsung',             'active'],
    ['BenQ PD3220U 32" 4K',             'Monitors',    'BenQ',       'PD3220U',            'SN-BQ-031', '2022-06-30', 850.00,  5, 85.00,  'straight_line',    '2025-06-30', 'BenQ',                'active'],
    // Furniture
    ['Executive Desk — CEO Office',     'Furniture',   'Steelcase',  'Series 1 Executive', 'FN-001',    '2020-01-15', 2800.00, 10, 280.00,'straight_line',    null,         'Steelcase Qatar',     'active'],
    ['Meeting Table 12-seater',         'Furniture',   'Herman Miller','Conference Table',  'FN-002',    '2020-03-20', 4500.00, 10, 450.00,'straight_line',    null,         'Herman Miller',       'active'],
    ['Ergonomic Chair — Aeron',         'Furniture',   'Herman Miller','Aeron Chair',       'FN-003',    '2021-05-10', 1500.00, 10, 150.00,'straight_line',    null,         'Herman Miller',       'active'],
    ['Standing Desk Adjustable x10',    'Furniture',   'Flexispot',  'E7 Pro',             'FN-004',    '2022-09-01', 4000.00, 7, 400.00, 'straight_line',    null,         'Flexispot',           'active'],
    ['Sofa Set — Reception',            'Furniture',   'IKEA',       'Kivik 3-piece',      'FN-005',    '2020-07-01', 1800.00, 10, 180.00,'straight_line',    null,         'IKEA Qatar',          'active'],
    ['Filing Cabinet 4-Drawer x5',     'Furniture',   'Bisley',     '4D Filing Cabinet',  'FN-006',    '2019-04-01', 2500.00, 10, 250.00,'straight_line',    null,         'Bisley',              'disposed'],
    ['Whiteboard 180x120cm x4',         'Furniture',   'Legamaster', 'Premium Board',      'FN-007',    '2021-11-15', 600.00,  5, 60.00,  'straight_line',    null,         'Legamaster',          'active'],
    // Office Equipment
    ['Projector Epson EB-L400U',        'Office Equipment','Epson',  'EB-L400U',           'OE-001',    '2022-07-10', 2200.00, 7, 220.00, 'declining_balance', '2025-07-10', 'Epson Qatar',         'active'],
    ['Polycom Studio Video Bar',        'Office Equipment','Polycom', 'Studio X30',         'OE-002',    '2023-03-15', 1800.00, 5, 180.00, 'straight_line',    '2026-03-15', 'Poly',                'active'],
    ['Shredder Fellowes Powershred',    'Office Equipment','Fellowes','Powershred 225Ci',   'OE-003',    '2021-06-01', 450.00,  5, 45.00,  'straight_line',    '2024-06-01', 'Fellowes',            'active'],
    ['UPS APC Smart-UPS 1500VA',        'Office Equipment','APC',    'Smart-UPS 1500',      'OE-004',    '2022-04-20', 800.00,  5, 80.00,  'straight_line',    '2025-04-20', 'APC by Schneider',    'active'],
    ['Label Printer Dymo LabelWriter',  'Office Equipment','Dymo',   'LabelWriter 5XL',    'OE-005',    '2023-09-01', 230.00,  4, 23.00,  'straight_line',    '2026-09-01', 'Dymo',                'active'],
    // Vehicles
    ['Toyota Fortuner 2022',            'Vehicles',    'Toyota',     'Fortuner 2.8 4WD',   'VH-001',    '2022-02-01', 55000.00, 5, 15000.00,'declining_balance',null,        'Al Salama Toyota',    'active'],
    ['Mitsubishi L200 Pickup 2023',     'Vehicles',    'Mitsubishi', 'L200 Double Cab',    'VH-002',    '2023-05-15', 48000.00, 5, 12000.00,'declining_balance',null,        'Behbehani Motors',    'active'],
    ['Nissan Patrol 2021',              'Vehicles',    'Nissan',     'Patrol Y62 V8',      'VH-003',    '2021-08-01', 72000.00, 5, 18000.00,'declining_balance',null,        'Al Babtain Nissan',   'active'],
    // Security
    ['CCTV DVR 16-Channel Hikvision',   'Security',    'Hikvision',  'DS-7616NI-K2',       'SC-001',    '2022-03-10', 1200.00, 7, 120.00, 'straight_line',    '2025-03-10', 'Hikvision Qatar',     'active'],
    ['IP Camera Dahua 4MP x8',          'Security',    'Dahua',      'IPC-HDW2849H',       'SC-002',    '2022-03-10', 960.00,  7, 96.00,  'straight_line',    '2025-03-10', 'Dahua Qatar',         'active'],
    ['Bosch Access Control Panel',      'Security',    'Bosch',      'APC-AMC2-4WCF',      'SC-003',    '2021-11-20', 3500.00, 7, 350.00, 'straight_line',    '2024-11-20', 'Bosch Security',      'active'],
    ['ZKTeco Fingerprint Reader x6',    'Security',    'ZKTeco',     'K40 Pro',            'SC-004',    '2023-01-10', 720.00,  5, 72.00,  'straight_line',    '2026-01-10', 'ZKTeco Qatar',        'active'],
];

$stmt = $pdo->prepare("
    INSERT INTO assets
        (asset_tag, name, category_id, serial_number, model, brand, purchase_date, purchase_cost,
         vendor, warranty_expiry, useful_life_years, salvage_value, depreciation_method,
         status, location_id, department_id, assigned_to, notes, created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");

// Build category name→id map
$catNameToId = [];
foreach ($cats as $c) $catNameToId[$c['name']] = $c['id'];
// Also by type for fallback
$catTypeToId = [];
foreach ($cats as $c) $catTypeToId[$c['type']] = $c['id'];

$inserted = 0;
$skipped  = 0;

foreach ($assets as $i => $a) {
    [$name, $typeName, $brand, $model, $serial, $purchDate, $cost,
     $life, $salvage, $method, $warranty, $vendor, $status] = $a;

    // Find category id by type match
    $catId = null;
    foreach ($cats as $c) {
        if ($c['type'] === $typeName || $c['name'] === $typeName) {
            $catId = $c['id'];
            break;
        }
    }

    // Check if serial already exists
    $check = $pdo->prepare("SELECT id FROM assets WHERE serial_number=? LIMIT 1");
    $check->execute([$serial]);
    if ($check->fetchColumn()) { $skipped++; continue; }

    $tag    = generateAssetTag($pdo);
    $locId  = $locs  ? $locs[array_rand($locs)]  : null;
    $deptId = $depts ? $depts[array_rand($depts)] : null;
    $userId = $users ? $users[array_rand($users)] : null;

    $stmt->execute([
        $tag, $name, $catId, $serial, $model, $brand, $purchDate, $cost,
        $vendor, $warranty, $life, $salvage, $method,
        $status, $locId, $deptId, $userId,
        "Demo data — seeded " . date('Y-m-d'),
        1 // super_admin user id
    ]);
    $newId = (int)$pdo->lastInsertId();

    // QR code
    $qrPath = generateQRCode($newId, $tag);
    if ($qrPath) {
        $pdo->prepare("UPDATE assets SET qr_code_path=? WHERE id=?")->execute([$qrPath, $newId]);
    }

    $inserted++;
}

echo "<h2>Seeder Done</h2>";
echo "<p>Inserted: <strong>{$inserted}</strong> | Skipped (already exists): <strong>{$skipped}</strong></p>";
echo "<p><a href='/modules/assets/index.php'>Go to Assets</a></p>";
echo "<p style='color:red'><strong>Delete this file now:</strong> seed_assets.php</p>";
