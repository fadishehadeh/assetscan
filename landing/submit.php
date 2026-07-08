<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://assetscan.online');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$first    = trim($_POST['first_name']  ?? '');
$last     = trim($_POST['last_name']   ?? '');
$email    = trim($_POST['email']       ?? '');
$phone    = trim($_POST['phone']       ?? '');
$company  = trim($_POST['company']     ?? '');
$industry = trim($_POST['industry']    ?? '');
$assets   = trim($_POST['asset_count'] ?? '');
$source   = trim($_POST['source']      ?? '');
$message  = trim($_POST['message']     ?? '');

if (!$first || !$last || !$email || !$company) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Required fields missing']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid email address']);
    exit;
}

$name = htmlspecialchars($first . ' ' . $last, ENT_QUOTES, 'UTF-8');
$esc  = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$rows = '';
foreach ([
    'Name'          => $name,
    'Email'         => $esc($email),
    'Phone'         => $esc($phone)    ?: '—',
    'Company'       => $esc($company),
    'Industry'      => $esc($industry) ?: '—',
    'Asset Count'   => $esc($assets)   ?: '—',
    'Source'        => $esc($source)   ?: '—',
    'Message'       => nl2br($esc($message)) ?: '—',
] as $label => $value) {
    $rows .= "<tr>
      <td style='padding:10px 14px;font-weight:600;color:#374151;background:#f8fafc;border:1px solid #e2e8f0;white-space:nowrap;width:140px;'>{$label}</td>
      <td style='padding:10px 14px;color:#1e293b;border:1px solid #e2e8f0;'>{$value}</td>
    </tr>";
}

$html = "
<div style='font-family:Inter,Segoe UI,sans-serif;max-width:600px;margin:0 auto;'>
  <div style='background:#E84B37;padding:28px 32px;border-radius:12px 12px 0 0;'>
    <h2 style='color:#fff;margin:0;font-size:20px;font-weight:800;'>New Demo Request — AssetScan</h2>
    <p style='color:rgba(255,255,255,.8);margin:6px 0 0;font-size:14px;'>Submitted " . date('d M Y, H:i') . " UTC</p>
  </div>
  <div style='background:#fff;padding:0;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;overflow:hidden;'>
    <table style='width:100%;border-collapse:collapse;'>{$rows}</table>
    <div style='padding:20px 24px;background:#fff8f7;border-top:1px solid #fde8e4;'>
      <p style='margin:0;font-size:13px;color:#9ca3af;'>Reply directly to this email to contact the requester.</p>
    </div>
  </div>
</div>";

// ── Mailjet API ──────────────────────────────────────────────────────────────
$apiKey  = '52a50c2c536f896054b8a36ef928817c';
$secret  = 'da2bd1c30dbd521c47129c8cfddb1d6e';
$from    = 'hello@assetscan.online';
$fromName = 'AssetScan';
$to      = 'fshehadeh@gmail.com';
$toName  = 'Fadi';

$payload = json_encode([
    'Messages' => [[
        'From'     => ['Email' => $from,  'Name' => $fromName],
        'To'       => [['Email' => $to,   'Name' => $toName]],
        'ReplyTo'  => ['Email' => $email, 'Name' => $name],
        'Subject'  => "Demo Request from {$name} — {$esc($company)}",
        'HTMLPart' => $html,
    ]]
]);

$ch = curl_init('https://api.mailjet.com/v3.1/send');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_USERPWD        => "{$apiKey}:{$secret}",
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Mail delivery failed', 'detail' => $response]);
}
