<?php
require_once __DIR__ . '/../config/db.php';

// ── Sanitize output ──────────────────────────────────────────
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Flash messages ───────────────────────────────────────────
function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// ── Generate unique asset tag ─────────────────────────────────
function generateAssetTag(PDO $pdo): string {
    do {
        $tag = 'AST-' . strtoupper(substr(uniqid(), -6));
        $st  = $pdo->prepare("SELECT id FROM assets WHERE asset_tag = ?");
        $st->execute([$tag]);
    } while ($st->fetch());
    return $tag;
}

// ── QR Code generation ────────────────────────────────────────
function generateQRCode(int $assetId, string $assetTag, bool $force = false): string {
    $qrDir = __DIR__ . '/../uploads/qr/';
    if (!is_dir($qrDir)) { mkdir($qrDir, 0755, true); }
    $file = $qrDir . 'asset_' . $assetId . '.png';
    $host = $_SERVER['HTTP_HOST'] ?? 'assetscan.online';
    $url  = 'https://' . $host . '/modules/assets/view.php?id=' . $assetId;

    if ($force || !file_exists($file)) {
        require_once __DIR__ . '/../libs/phpqrcode/qrlib.php';
        QRcode::png($url, $file, QR_ECLEVEL_M, 6, 2);
    }
    return 'uploads/qr/asset_' . $assetId . '.png';
}

// ── Depreciation calculation ──────────────────────────────────
function calcDepreciation(
    float  $cost,
    float  $salvage,
    int    $usefulLife,
    string $method,
    int    $year        // 1-based year number
): array {
    $depreciableBase = $cost - $salvage;

    if ($method === 'straight_line') {
        $annual   = $usefulLife > 0 ? $depreciableBase / $usefulLife : 0;
        $bookStart = $cost - ($annual * ($year - 1));
        $bookEnd   = max($salvage, $bookStart - $annual);
        $dep       = $bookStart - $bookEnd;
    } else {
        // Double declining balance
        $rate      = $usefulLife > 0 ? (2 / $usefulLife) : 0;
        $bookStart = $cost;
        for ($i = 1; $i < $year; $i++) {
            $bookStart = max($salvage, $bookStart * (1 - $rate));
        }
        $dep     = max(0, $bookStart * $rate);
        $bookEnd = max($salvage, $bookStart - $dep);
        $dep     = $bookStart - $bookEnd;
    }

    return [
        'dep_amount'       => round($dep, 2),
        'book_value_start' => round($bookStart, 2),
        'book_value_end'   => round($bookEnd, 2),
    ];
}

function currentBookValue(float $cost, float $salvage, int $usefulLife, string $method, ?string $purchaseDate): float {
    if (!$purchaseDate) return $cost;
    $years = (int) ((time() - strtotime($purchaseDate)) / (365.25 * 86400));
    $years = min($years, $usefulLife);
    if ($years <= 0) return $cost;
    $d = calcDepreciation($cost, $salvage, $usefulLife, $method, $years);
    return $d['book_value_end'];
}

// ── Audit logging ─────────────────────────────────────────────
function auditLog(PDO $pdo, string $action, string $table, int $recordId, ?array $old = null, ?array $new = null): void {
    $userId = $_SESSION['user_id'] ?? null;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
    $st = $pdo->prepare("INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address)
                         VALUES (?, ?, ?, ?, ?, ?, ?)");
    $st->execute([
        $userId, $action, $table, $recordId,
        $old ? json_encode($old) : null,
        $new  ? json_encode($new) : null,
        $ip
    ]);
}

// ── Asset history ─────────────────────────────────────────────
function logAssetHistory(PDO $pdo, int $assetId, string $action, ?string $from = null, ?string $to = null, ?string $notes = null): void {
    $userId = $_SESSION['user_id'] ?? null;
    $st = $pdo->prepare("INSERT INTO asset_history (asset_id, performed_by, action, from_value, to_value, notes)
                         VALUES (?, ?, ?, ?, ?, ?)");
    $st->execute([$assetId, $userId, $action, $from, $to, $notes]);
}

// ── Format currency ───────────────────────────────────────────
function formatMoney(float $amount, string $currency = 'USD'): string {
    return $currency . ' ' . number_format($amount, 2);
}

// ── Status badge ──────────────────────────────────────────────
function statusBadge(string $status): string {
    $map = [
        'active'            => 'success',
        'under_maintenance' => 'warning',
        'disposed'          => 'danger',
        'lost'              => 'dark',
    ];
    $cls   = $map[$status] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', $status));
    return "<span class=\"badge bg-{$cls}\">{$label}</span>";
}

// ── Pagination helper ─────────────────────────────────────────
function paginate(int $total, int $perPage, int $current): array {
    $pages = (int) ceil($total / $perPage);
    return [
        'total'    => $total,
        'per_page' => $perPage,
        'current'  => $current,
        'pages'    => $pages,
        'offset'   => ($current - 1) * $perPage,
    ];
}

// ── Get settings ──────────────────────────────────────────────
function getSettings(PDO $pdo): array {
    return $pdo->query("SELECT * FROM settings LIMIT 1")->fetch() ?: [];
}
