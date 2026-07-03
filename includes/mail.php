<?php
/**
 * Mailjet mailer — pure cURL, no composer needed.
 */
function sendMail(PDO $pdo, string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    $s = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();

    $apiKey    = $s['mailjet_api_key']    ?? '';
    $secret    = $s['mailjet_secret']     ?? '';
    $fromEmail = $s['mailjet_from_email'] ?? '';
    $fromName  = $s['mailjet_from_name']  ?? 'Asset Manager';

    if (!$apiKey || !$secret || !$fromEmail) return false;

    $payload = json_encode([
        'Messages' => [[
            'From'     => ['Email' => $fromEmail, 'Name' => $fromName],
            'To'       => [['Email' => $toEmail,  'Name' => $toName]],
            'Subject'  => $subject,
            'HTMLPart' => $htmlBody,
        ]]
    ]);

    $ch = curl_init('https://api.mailjet.com/v3.1/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_USERPWD        => "{$apiKey}:{$secret}",
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

function sendOtpEmail(PDO $pdo, string $toEmail, string $toName, string $otp): bool {
    $s       = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch();
    $appName = $s['app_name'] ?? 'Asset Manager';
    $primary = $s['primary_color'] ?? '#E84B37';

    $html = "
    <div style='font-family:Segoe UI,sans-serif;max-width:480px;margin:0 auto;'>
      <div style='background:{$primary};padding:28px 32px;text-align:center;border-radius:12px 12px 0 0;'>
        <h2 style='color:#fff;margin:0;font-size:20px;letter-spacing:1px;'>Verification Code</h2>
      </div>
      <div style='background:#fff;padding:32px;border:1px solid #e2e8f0;border-radius:0 0 12px 12px;'>
        <p style='color:#374151;margin:0 0 8px;'>Hi <strong>{$toName}</strong>,</p>
        <p style='color:#6b7280;font-size:14px;margin:0 0 24px;'>
          Use the code below to complete your sign-in to <strong>{$appName}</strong>.<br>
          This code expires in <strong>10 minutes</strong>.
        </p>
        <div style='background:#f8fafc;border:2px dashed {$primary};border-radius:10px;text-align:center;padding:22px;margin-bottom:24px;'>
          <span style='font-size:38px;font-weight:900;letter-spacing:10px;color:{$primary};font-family:monospace;'>{$otp}</span>
        </div>
        <p style='color:#9ca3af;font-size:12px;margin:0;'>
          If you didn't request this, you can safely ignore this email.
        </p>
      </div>
    </div>";

    return sendMail($pdo, $toEmail, $toName, "Your {$appName} Login Code: {$otp}", $html);
}
