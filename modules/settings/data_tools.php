<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!isSuperAdmin()) { http_response_code(403); exit('Forbidden'); }

$pageTitle = 'Data Tools';
$result = '';
$resultType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    // ── DELETE ALL DATA ───────────────────────────────────────────────
    if ($action === 'delete_all') {
        $tables = [
            'audit_log','asset_photos','asset_documents','custom_field_values',
            'asset_transfers','disposal_requests','checkout_log','maintenance',
            'depreciation_log','notification_log','assets'
        ];
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        foreach ($tables as $t) {
            $pdo->exec("TRUNCATE TABLE `$t`");
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        $result = 'All asset data deleted successfully. Categories, departments, locations and users are preserved.';
        $resultType = 'success';
    }

    // ── SEED DEMO DATA ────────────────────────────────────────────────
    if ($action === 'seed_data') {
        $cats  = $pdo->query("SELECT id, name FROM categories")->fetchAll();
        $depts = $pdo->query("SELECT id FROM departments")->fetchAll(PDO::FETCH_COLUMN);
        $locs  = $pdo->query("SELECT id FROM locations")->fetchAll(PDO::FETCH_COLUMN);
        $users = $pdo->query("SELECT id FROM users WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN);

        if (empty($cats) || empty($depts)) {
            $result = 'Please create at least one category and one department before seeding data.';
            $resultType = 'danger';
        } else {
            $catIds  = array_column($cats, 'id');
            $demoAssets = [
                ['MacBook Pro 16" M3','Apple','MBP16M3','SN-MAC-001','active',2499,200,5,'straight_line'],
                ['Dell XPS 15','Dell','XPS15-9530','SN-DEL-001','active',1899,150,4,'straight_line'],
                ['HP LaserJet Pro M404','HP','M404DN','SN-HP-001','active',450,50,5,'straight_line'],
                ['Cisco Catalyst 2960','Cisco','WS-C2960','SN-CIS-001','active',3200,300,7,'straight_line'],
                ['iPhone 14 Pro','Apple','IP14PRO','SN-IPH-001','active',999,100,3,'straight_line'],
                ['Samsung 27" Monitor','Samsung','S27A800','SN-SAM-001','active',699,50,5,'straight_line'],
                ['Logitech MX Keys','Logitech','MXK-BLK','SN-LOG-001','active',99,10,3,'straight_line'],
                ['Canon EOS R5','Canon','EOSR5','SN-CAN-001','active',3899,400,6,'straight_line'],
                ['Sony WH-1000XM5','Sony','WH1000XM5','SN-SON-001','active',349,30,4,'straight_line'],
                ['Epson WorkForce Pro','Epson','WF4830','SN-EPS-001','active',299,25,5,'straight_line'],
                ['iPad Pro 12.9"','Apple','IPADPRO129','SN-IPD-001','active',1099,100,4,'straight_line'],
                ['LG 34" UltraWide','LG','34WN80C','SN-LG-001','active',799,60,5,'straight_line'],
                ['Ubiquiti UniFi AP','Ubiquiti','UAP-AC-PRO','SN-UBI-001','active',149,15,5,'straight_line'],
                ['Synology NAS DS923+','Synology','DS923PLUS','SN-SYN-001','active',599,50,6,'straight_line'],
                ['APC UPS 1500VA','APC','SMT1500','SN-APC-001','active',299,25,7,'straight_line'],
                ['Microsoft Surface Pro 9','Microsoft','SURFPRO9','SN-MSF-001','active',1299,120,4,'straight_line'],
                ['Lenovo ThinkPad X1','Lenovo','X1CARBON','SN-LEN-001','active',1599,140,5,'straight_line'],
                ['HP EliteDesk 800','HP','ED800G9','SN-HPE-001','active',899,80,5,'straight_line'],
                ['Jabra Evolve2 85','Jabra','EV285','SN-JAB-001','active',449,40,4,'straight_line'],
                ['Cisco IP Phone 8845','Cisco','CP8845','SN-CIP-001','active',399,35,6,'straight_line'],
                ['Dell PowerEdge R740','Dell','PE-R740','SN-SRV-001','under_maintenance',4999,500,7,'straight_line'],
                ['HP ProLiant DL380','HP','DL380G10','SN-SRV-002','active',5499,550,7,'straight_line'],
                ['Yamaha Conference Cam','Yamaha','CS-700AV','SN-YAM-001','active',899,80,5,'straight_line'],
                ['Ergotron Sit-Stand Desk','Ergotron','WKST-SSD','SN-ERG-001','active',1200,100,10,'straight_line'],
                ['Brother HL-L8360CDW','Brother','HLL8360','SN-BRO-001','active',499,45,5,'straight_line'],
                ['Wacom Intuos Pro','Wacom','PTH860','SN-WAC-001','active',379,35,5,'straight_line'],
                ['Anker PowerConf C200','Anker','PC200','SN-ANK-001','active',79,8,3,'straight_line'],
                ['Google Pixel 7 Pro','Google','PIX7PRO','SN-GOO-001','active',899,80,3,'straight_line'],
                ['Bose SoundLink Flex','Bose','SLFLX','SN-BOS-001','active',149,15,4,'straight_line'],
                ['Samsung Galaxy Tab S8','Samsung','TABS8','SN-SGT-001','active',699,60,4,'straight_line'],
                ['Alienware 34" Monitor','Dell','AW3423DW','SN-ALW-001','active',1299,120,5,'straight_line'],
                ['Shure MV7 Microphone','Shure','MV7BLK','SN-SHU-001','active',249,25,5,'straight_line'],
                ['CalDigit TS4 Dock','CalDigit','TS4','SN-CAL-001','active',349,30,4,'straight_line'],
                ['Elgato Stream Deck','Elgato','SD15','SN-ELG-001','active',149,15,4,'straight_line'],
                ['Ring Central Desk Phone','RingCentral','DESK-RC','SN-RNG-001','active',199,18,5,'straight_line'],
                ['Zebra ZD621 Printer','Zebra','ZD621','SN-ZEB-001','active',599,55,6,'straight_line'],
                ['Poly Studio X30','Poly','SX30','SN-POL-001','active',999,90,5,'straight_line'],
                ['Netgear 48-Port Switch','Netgear','GS348','SN-NET-001','active',599,55,7,'straight_line'],
                ['Raspberry Pi 4B','Raspberry Pi','RPI4B','SN-RPI-001','active',75,5,3,'straight_line'],
                ['AirPods Pro 2nd Gen','Apple','APP2','SN-APP-001','active',249,20,3,'straight_line'],
                ['DJI Mini 3 Pro','DJI','MINI3PRO','SN-DJI-001','active',759,70,4,'straight_line'],
                ['Garmin GPS Fleet 790','Garmin','FL790','SN-GAR-001','active',499,45,5,'straight_line'],
                ['Panasonic Toughbook 55','Panasonic','CF55','SN-PAN-001','active',2499,220,6,'straight_line'],
                ['3M Projector MP8749','3M','MP8749','SN-3MP-001','disposed',899,80,6,'straight_line'],
                ['Fujitsu ScanSnap iX1600','Fujitsu','IX1600','SN-FUJ-001','active',419,40,5,'straight_line'],
                ['Kensington Lock Pro','Kensington','LD9750','SN-KEN-001','active',49,5,5,'straight_line'],
                ['Belkin 12-Outlet Surge','Belkin','BV112230','SN-BEL-001','active',89,8,5,'straight_line'],
                ['Targus Laptop Bag','Targus','TSB195','SN-TAR-001','active',79,8,5,'straight_line'],
                ['iStorage datAshur','iStorage','DAUSB','SN-IST-001','lost',149,10,3,'straight_line'],
                ['Seagate 4TB External','Seagate','STDR4000','SN-SEA-001','active',99,10,4,'straight_line'],
            ];

            $stmt = $pdo->prepare("INSERT INTO assets
                (name,brand,model,serial_number,status,purchase_cost,salvage_value,useful_life_years,
                 depreciation_method,category_id,department_id,location_id,assigned_to,
                 purchase_date,warranty_expiry,asset_tag,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");

            $count = 0;
            foreach ($demoAssets as $i => $a) {
                $tag = generateAssetTag($pdo);
                $catId  = $catIds[$i % count($catIds)];
                $deptId = $depts[$i % count($depts)];
                $locId  = !empty($locs) ? $locs[$i % count($locs)] : null;
                $userId = !empty($users) ? $users[$i % count($users)] : null;
                $pDate  = date('Y-m-d', strtotime('-' . rand(6,36) . ' months'));
                $wDate  = date('Y-m-d', strtotime($pDate . ' +' . rand(1,3) . ' years'));
                $stmt->execute([
                    $a[0],$a[1],$a[2],$a[3],$a[4],$a[5],$a[6],$a[7],$a[8],
                    $catId,$deptId,$locId,$userId,$pDate,$wDate,$tag
                ]);
                $newId = $pdo->lastInsertId();
                generateQRCode($newId, $tag);
                $count++;
            }
            $result = "Successfully seeded $count demo assets.";
            $resultType = 'success';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
  <div>
    <h2><i class="bi bi-database-gear me-2 text-primary"></i>Data Tools</h2>
    <p class="text-muted mb-0">Seed demo data or reset asset records for testing.</p>
  </div>
  <a href="/modules/settings/index.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Back to Settings
  </a>
</div>

<?php if ($result): ?>
<div class="alert alert-<?= $resultType ?> alert-dismissible fade show mt-3">
  <i class="bi bi-<?= $resultType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
  <?= htmlspecialchars($result) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4 mt-1">

  <!-- Seed Data -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-body p-4">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div style="width:52px;height:52px;background:#f0fdf4;border-radius:14px;display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-database-add fs-4 text-success"></i>
          </div>
          <div>
            <h5 class="mb-0">Seed Demo Data</h5>
            <p class="text-muted mb-0" style="font-size:.85rem;">Insert 50 realistic sample assets</p>
          </div>
        </div>
        <p class="text-muted" style="font-size:.88rem;">Populates the system with 50 demo assets across different categories, departments, and statuses. Useful for testing the UI and reports without real data.</p>
        <div class="alert alert-info py-2 px-3 mb-3" style="font-size:.82rem;">
          <i class="bi bi-info-circle me-1"></i> Requires at least one category and department to exist.
        </div>
        <form method="POST" onsubmit="return confirm('Seed 50 demo assets into the system?')">
          <input type="hidden" name="_action" value="seed_data">
          <button type="submit" class="btn btn-success w-100">
            <i class="bi bi-database-add me-2"></i>Seed Demo Data
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Delete All Data -->
  <div class="col-md-6">
    <div class="card h-100 border-danger">
      <div class="card-body p-4">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div style="width:52px;height:52px;background:#fef2f2;border-radius:14px;display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-trash3 fs-4 text-danger"></i>
          </div>
          <div>
            <h5 class="mb-0 text-danger">Delete All Asset Data</h5>
            <p class="text-muted mb-0" style="font-size:.85rem;">Wipe all assets and related records</p>
          </div>
        </div>
        <p class="text-muted" style="font-size:.88rem;">Permanently deletes all assets, maintenance logs, checkouts, transfers, disposals, photos, and documents. Categories, departments, locations, and users are kept.</p>
        <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:.82rem;">
          <i class="bi bi-exclamation-triangle me-1"></i> This action is <strong>irreversible</strong>. All asset records will be lost.
        </div>
        <form method="POST" onsubmit="return confirm('DELETE ALL asset data? This cannot be undone.')">
          <input type="hidden" name="_action" value="delete_all">
          <button type="submit" class="btn btn-danger w-100">
            <i class="bi bi-trash3 me-2"></i>Delete All Asset Data
          </button>
        </form>
      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
