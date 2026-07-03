<?php
/**
 * Backblaze B2 — pure cURL, no SDK needed.
 *
 * Flow:
 *   1. b2_authorize_account  → get apiUrl + authToken
 *   2. b2_get_upload_url     → get uploadUrl + uploadAuthToken
 *   3. b2_upload_file        → upload bytes
 */

class B2Client {

    private string $keyId;
    private string $appKey;
    private string $bucketId;
    private string $bucketName;

    private ?string $authToken  = null;
    private ?string $apiUrl     = null;
    private ?string $downloadUrl = null;

    public function __construct(string $keyId, string $appKey, string $bucketId, string $bucketName) {
        $this->keyId      = $keyId;
        $this->appKey     = $appKey;
        $this->bucketId   = $bucketId;
        $this->bucketName = $bucketName;
    }

    // ── 1. Authorize ─────────────────────────────────────────────────
    public function authorize(): bool {
        $response = $this->curl(
            'https://api.backblazeb2.com/b2api/v3/b2_authorize_account',
            null,
            ['Authorization: Basic ' . base64_encode("{$this->keyId}:{$this->appKey}")]
        );
        if (!$response || !isset($response['authorizationToken'])) return false;

        $this->authToken   = $response['authorizationToken'];
        $this->apiUrl      = $response['apiInfo']['storageApi']['apiUrl'] ?? $response['apiUrl'] ?? null;
        $this->downloadUrl = $response['apiInfo']['storageApi']['downloadUrl'] ?? $response['downloadUrl'] ?? null;
        return true;
    }

    // ── 2. Upload file ───────────────────────────────────────────────
    public function upload(string $localPath, string $remoteName): array|false {
        if (!$this->authToken && !$this->authorize()) {
            return false;
        }

        // Get upload URL
        $uploadInfo = $this->curl(
            $this->apiUrl . '/b2api/v3/b2_get_upload_url',
            ['bucketId' => $this->bucketId],
            ['Authorization: ' . $this->authToken]
        );
        if (!$uploadInfo || !isset($uploadInfo['uploadUrl'])) return false;

        $uploadUrl       = $uploadInfo['uploadUrl'];
        $uploadAuthToken = $uploadInfo['authorizationToken'];

        $content = file_get_contents($localPath);
        $sha1    = sha1($content);
        $size    = strlen($content);

        $result = $this->curlRaw($uploadUrl, $content, [
            'Authorization: '       . $uploadAuthToken,
            'X-Bz-File-Name: '      . rawurlencode($remoteName),
            'Content-Type: application/octet-stream',
            'Content-Length: '      . $size,
            'X-Bz-Content-Sha1: '   . $sha1,
        ]);

        return $result ?: false;
    }

    // ── 3. List files ────────────────────────────────────────────────
    public function listFiles(string $prefix = 'backups/', int $maxCount = 100): array {
        if (!$this->authToken && !$this->authorize()) return [];

        $result = $this->curl(
            $this->apiUrl . '/b2api/v3/b2_list_file_names',
            [
                'bucketId'     => $this->bucketId,
                'prefix'       => $prefix,
                'maxFileCount' => $maxCount,
            ],
            ['Authorization: ' . $this->authToken]
        );
        return $result['files'] ?? [];
    }

    // ── 4. Delete file ───────────────────────────────────────────────
    public function deleteFile(string $fileId, string $fileName): bool {
        if (!$this->authToken && !$this->authorize()) return false;

        $result = $this->curl(
            $this->apiUrl . '/b2api/v3/b2_delete_file_version',
            ['fileId' => $fileId, 'fileName' => $fileName],
            ['Authorization: ' . $this->authToken]
        );
        return isset($result['fileId']);
    }

    // ── 5. Get download URL ──────────────────────────────────────────
    public function getDownloadUrl(string $fileName): string {
        return $this->downloadUrl . '/file/' . rawurlencode($this->bucketName) . '/' . rawurlencode($fileName);
    }

    // ── 6. Get authorized download URL (private buckets) ────────────
    public function getAuthorizedDownloadUrl(string $fileName, int $validSeconds = 3600): string|false {
        if (!$this->authToken && !$this->authorize()) return false;

        $result = $this->curl(
            $this->apiUrl . '/b2api/v3/b2_get_download_authorization',
            [
                'bucketId'               => $this->bucketId,
                'fileNamePrefix'         => $fileName,
                'validDurationInSeconds' => $validSeconds,
            ],
            ['Authorization: ' . $this->authToken]
        );
        if (!$result || !isset($result['authorizationToken'])) return false;

        return $this->downloadUrl . '/file/' . rawurlencode($this->bucketName) . '/' . rawurlencode($fileName)
             . '?Authorization=' . $result['authorizationToken'];
    }

    // ── cURL helpers ─────────────────────────────────────────────────
    private function curl(string $url, ?array $body, array $headers): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $raw  = curl_exec($ch);
        curl_close($ch);
        return $raw ? json_decode($raw, true) : null;
    }

    private function curlRaw(string $url, string $body, array $headers): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($raw && $code === 200) ? json_decode($raw, true) : null;
    }
}

// ── Factory from DB settings ─────────────────────────────────────────
function b2FromSettings(array $s): ?B2Client {
    if (empty($s['b2_key_id']) || empty($s['b2_app_key']) ||
        empty($s['b2_bucket_id']) || empty($s['b2_bucket_name'])) {
        return null;
    }
    return new B2Client($s['b2_key_id'], $s['b2_app_key'], $s['b2_bucket_id'], $s['b2_bucket_name']);
}

function b2IsConfigured(array $s): bool {
    return !empty($s['b2_key_id']) && !empty($s['b2_app_key'])
        && !empty($s['b2_bucket_id']) && !empty($s['b2_bucket_name']);
}
