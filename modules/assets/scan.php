<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$settings   = getSettings($pdo);
$appName    = $settings['app_name']      ?? 'Asset Manager';
$primaryClr = $settings['primary_color'] ?? '#E84B37';
$sidebarClr = $settings['sidebar_color'] ?? '#1a1a2e';

function darkenHex(string $hex, float $factor = 0.82): string {
    $hex = ltrim($hex, '#');
    [$r,$g,$b] = [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
    return sprintf('#%02x%02x%02x',(int)($r*$factor),(int)($g*$factor),(int)($b*$factor));
}
$primaryDk = darkenHex($primaryClr);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Scan Asset — <?= e($appName) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
  <style>
    :root {
      --brand:    <?= e($primaryClr) ?>;
      --brand-dk: <?= e($primaryDk)  ?>;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    html, body {
      height: 100%;
      overflow: hidden;
      background: #000;
      font-family: system-ui, -apple-system, sans-serif;
    }

    /* ── Camera feed ─────────────────────────────────────────── */
    #cameraView {
      position: fixed;
      top: 0; left: 0;
      width: 100%; height: 100%;
      object-fit: cover;
      z-index: 1;
    }

    #scanCanvas { display: none; }

    /* ── Dark vignette overlay ───────────────────────────────── */
    #overlay {
      position: fixed;
      inset: 0;
      z-index: 2;
      pointer-events: none;
      /* punched-out centre via radial gradient */
      background:
        radial-gradient(ellipse 55% 45% at 50% 48%,
          transparent 0%,
          rgba(0,0,0,0.55) 100%);
    }

    /* ── Top bar ─────────────────────────────────────────────── */
    #topBar {
      position: fixed;
      top: 0; left: 0; right: 0;
      z-index: 3;
      background: linear-gradient(to bottom, rgba(0,0,0,0.75), transparent);
      padding: max(12px, env(safe-area-inset-top)) 16px 28px;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    #topBar .back-btn {
      width: 36px; height: 36px;
      border-radius: 50%;
      background: rgba(255,255,255,0.15);
      border: 1px solid rgba(255,255,255,0.25);
      color: #fff;
      display: flex; align-items: center; justify-content: center;
      text-decoration: none;
      font-size: 1.1rem;
      backdrop-filter: blur(4px);
      transition: background 0.2s;
      flex-shrink: 0;
    }
    #topBar .back-btn:hover { background: rgba(255,255,255,0.28); }

    #topBar h1 {
      color: #fff;
      font-size: 1.05rem;
      font-weight: 600;
      letter-spacing: 0.02em;
      text-shadow: 0 1px 4px rgba(0,0,0,0.5);
    }

    /* ── Reticle ─────────────────────────────────────────────── */
    #reticleWrap {
      position: fixed;
      inset: 0;
      z-index: 2;
      display: flex;
      align-items: center;
      justify-content: center;
      pointer-events: none;
    }

    .reticle {
      position: relative;
      width: 260px; height: 260px;
      transition: filter 0.2s;
    }

    .reticle.found  { filter: drop-shadow(0 0 12px #22c55e); }
    .reticle.miss   { filter: drop-shadow(0 0 12px #ef4444); }

    /* Corner brackets via pseudo + span */
    .reticle::before, .reticle::after,
    .reticle .corner-br, .reticle .corner-bl {
      content: '';
      position: absolute;
      width: 36px; height: 36px;
      border-color: #fff;
      border-style: solid;
    }
    /* top-left */
    .reticle::before {
      top: 0; left: 0;
      border-width: 3px 0 0 3px;
      border-radius: 4px 0 0 0;
      box-shadow: -2px -2px 6px rgba(var(--brand-rgb, 232,75,55),0.6);
    }
    /* top-right */
    .reticle::after {
      top: 0; right: 0;
      border-width: 3px 3px 0 0;
      border-radius: 0 4px 0 0;
    }
    /* bottom-right */
    .reticle .corner-br {
      bottom: 0; right: 0;
      border-width: 0 3px 3px 0;
      border-radius: 0 0 4px 0;
    }
    /* bottom-left */
    .reticle .corner-bl {
      bottom: 0; left: 0;
      border-width: 0 0 3px 3px;
      border-radius: 0 0 0 4px;
    }

    /* Glow on corners using brand colour */
    .reticle::before,
    .reticle::after,
    .reticle .corner-br,
    .reticle .corner-bl {
      filter: drop-shadow(0 0 4px var(--brand));
    }

    /* Scan line */
    .scan-line {
      position: absolute;
      left: 4px; right: 4px;
      height: 2px;
      background: linear-gradient(to right,
        transparent,
        var(--brand) 30%,
        var(--brand) 70%,
        transparent);
      border-radius: 1px;
      box-shadow: 0 0 8px 2px var(--brand);
      animation: scanAnim 1.6s ease-in-out infinite;
    }

    @keyframes scanAnim {
      0%   { top: 8px;  opacity: 0.9; }
      50%  { opacity: 1; }
      100% { top: calc(100% - 10px); opacity: 0.9; }
    }

    /* Label under reticle */
    .reticle-label {
      position: absolute;
      bottom: -32px;
      left: 50%;
      transform: translateX(-50%);
      white-space: nowrap;
      font-size: 0.78rem;
      color: rgba(255,255,255,0.7);
      letter-spacing: 0.04em;
    }

    /* ── Bottom sheet ────────────────────────────────────────── */
    #bottomSheet {
      position: fixed;
      bottom: 0; left: 0; right: 0;
      z-index: 10;
      transform: translateY(100%);
      transition: transform 0.32s cubic-bezier(0.32, 0.72, 0, 1);
      padding-bottom: env(safe-area-inset-bottom, 0px);
    }
    #bottomSheet.open { transform: translateY(0); }

    .sheet-card {
      background: #fff;
      border-radius: 20px 20px 0 0;
      padding: 20px 20px 28px;
      box-shadow: 0 -8px 40px rgba(0,0,0,0.35);
    }

    .sheet-handle {
      width: 40px; height: 4px;
      background: #d1d5db;
      border-radius: 2px;
      margin: 0 auto 18px;
    }

    .sheet-tag {
      font-family: 'SFMono-Regular', Consolas, monospace;
      font-size: 1rem;
      color: #6b7280;
      letter-spacing: 0.08em;
    }

    .sheet-name {
      font-size: 1.35rem;
      font-weight: 700;
      color: #111827;
      line-height: 1.25;
      margin: 4px 0 10px;
    }

    .sheet-meta {
      font-size: 0.84rem;
      color: #6b7280;
      display: flex;
      flex-direction: column;
      gap: 4px;
      margin-bottom: 18px;
    }

    .sheet-meta span { display: flex; align-items: center; gap: 6px; }

    .sheet-actions {
      display: flex;
      gap: 10px;
    }
    .sheet-actions .btn { flex: 1; border-radius: 10px; font-weight: 600; padding: 10px; }

    .btn-brand {
      background: var(--brand);
      border-color: var(--brand);
      color: #fff;
    }
    .btn-brand:hover {
      background: var(--brand-dk);
      border-color: var(--brand-dk);
      color: #fff;
    }

    /* ── Manual entry bar ───────────────────────────────────── */
    #manualBar {
      position: fixed;
      bottom: 0; left: 0; right: 0;
      z-index: 4;
      background: linear-gradient(to top, rgba(0,0,0,0.75), transparent);
      padding: 28px 16px max(16px, env(safe-area-inset-bottom));
      display: flex;
      align-items: center;
      gap: 8px;
    }

    #manualBar input {
      flex: 1;
      background: rgba(255,255,255,0.13);
      border: 1px solid rgba(255,255,255,0.3);
      color: #fff;
      border-radius: 10px;
      padding: 9px 14px;
      font-size: 0.9rem;
      backdrop-filter: blur(6px);
    }
    #manualBar input::placeholder { color: rgba(255,255,255,0.55); }
    #manualBar input:focus {
      outline: none;
      border-color: var(--brand);
      box-shadow: 0 0 0 2px rgba(232,75,55,0.3);
      background: rgba(255,255,255,0.2);
    }

    #manualBar button {
      background: var(--brand);
      border: none;
      color: #fff;
      border-radius: 10px;
      padding: 9px 18px;
      font-weight: 600;
      font-size: 0.9rem;
      cursor: pointer;
      white-space: nowrap;
    }
    #manualBar button:hover { background: var(--brand-dk); }

    /* ── Toast ───────────────────────────────────────────────── */
    #toast {
      position: fixed;
      top: 80px;
      left: 50%;
      transform: translateX(-50%) translateY(-10px);
      background: rgba(239,68,68,0.92);
      color: #fff;
      padding: 10px 20px;
      border-radius: 20px;
      font-size: 0.88rem;
      font-weight: 600;
      z-index: 20;
      opacity: 0;
      transition: opacity 0.2s, transform 0.2s;
      pointer-events: none;
      white-space: nowrap;
      backdrop-filter: blur(6px);
    }
    #toast.show {
      opacity: 1;
      transform: translateX(-50%) translateY(0);
    }
    #toast.success { background: rgba(34,197,94,0.92); }

    /* ── Spinner overlay ─────────────────────────────────────── */
    #spinnerOverlay {
      position: fixed;
      inset: 0;
      z-index: 15;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(0,0,0,0.35);
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.15s;
    }
    #spinnerOverlay.show { opacity: 1; pointer-events: auto; }
    #spinnerOverlay .spinner {
      width: 48px; height: 48px;
      border: 4px solid rgba(255,255,255,0.25);
      border-top-color: var(--brand);
      border-radius: 50%;
      animation: spin 0.7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Camera error card ───────────────────────────────────── */
    #cameraError {
      position: fixed;
      inset: 0;
      z-index: 20;
      background: #111;
      display: none;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 32px 24px;
      text-align: center;
    }
    #cameraError.show { display: flex; }
    #cameraError .error-icon { font-size: 4rem; color: #ef4444; margin-bottom: 16px; }
    #cameraError h2 { color: #fff; font-size: 1.3rem; font-weight: 700; margin-bottom: 8px; }
    #cameraError p  { color: #9ca3af; font-size: 0.9rem; margin-bottom: 28px; line-height: 1.6; }
    #cameraError .manual-fallback { width: 100%; max-width: 360px; }
    #cameraError .manual-fallback input {
      width: 100%;
      padding: 11px 16px;
      border-radius: 10px;
      border: 1px solid #374151;
      background: #1f2937;
      color: #fff;
      font-size: 1rem;
      margin-bottom: 10px;
    }
    #cameraError .manual-fallback button {
      width: 100%;
      padding: 12px;
      background: var(--brand);
      border: none;
      color: #fff;
      border-radius: 10px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
    }
  </style>
</head>
<body>

<!-- Hidden canvas for frame analysis -->
<canvas id="scanCanvas"></canvas>

<!-- Camera feed -->
<video id="cameraView" autoplay playsinline muted></video>

<!-- Dark vignette overlay -->
<div id="overlay"></div>

<!-- Reticle -->
<div id="reticleWrap">
  <div class="reticle" id="reticle">
    <span class="corner-br"></span>
    <span class="corner-bl"></span>
    <div class="scan-line" id="scanLine"></div>
    <span class="reticle-label">Point at a QR code or barcode</span>
  </div>
</div>

<!-- Top bar -->
<div id="topBar">
  <a href="/modules/assets/index.php" class="back-btn" title="Back">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h1>Scan Asset</h1>
</div>

<!-- Spinner overlay -->
<div id="spinnerOverlay"><div class="spinner"></div></div>

<!-- Toast -->
<div id="toast"></div>

<!-- Bottom sheet (asset found result) -->
<div id="bottomSheet">
  <div class="sheet-card">
    <div class="sheet-handle"></div>
    <div class="sheet-tag" id="sheetTag"></div>
    <div class="sheet-name" id="sheetName"></div>
    <div class="sheet-meta" id="sheetMeta"></div>
    <div class="sheet-actions">
      <a id="viewBtn" href="#" class="btn btn-brand">
        <i class="bi bi-box-arrow-up-right me-1"></i> View Details
      </a>
      <button class="btn btn-outline-secondary" onclick="resumeScanner()">
        <i class="bi bi-qr-code-scan me-1"></i> Scan Another
      </button>
    </div>
  </div>
</div>

<!-- Manual entry bar (visible while scanning) -->
<div id="manualBar">
  <input type="text" id="manualInput" placeholder="Type asset tag or serial…" autocomplete="off" autocorrect="off" autocapitalize="characters" spellcheck="false">
  <button onclick="lookupManual()"><i class="bi bi-search"></i> Go</button>
</div>

<!-- Camera not available -->
<div id="cameraError">
  <div class="error-icon"><i class="bi bi-camera-video-off"></i></div>
  <h2>Camera Access Required</h2>
  <p>This scanner needs camera access to read QR codes and barcodes.<br>
     Please allow camera access in your browser settings and reload the page.</p>
  <div class="manual-fallback">
    <input type="text" id="errorManualInput" placeholder="Enter asset tag or serial number" autocomplete="off" autocapitalize="characters">
    <button onclick="lookupFromError()">Look Up Asset</button>
  </div>
</div>

<script>
(function () {
  'use strict';

  /* ── State ────────────────────────────────────────────────── */
  let scanning    = true;
  let lastScanned = '';
  let scanPause   = false;
  let audioCtx    = null;

  const video          = document.getElementById('cameraView');
  const canvas         = document.getElementById('scanCanvas');
  const ctx            = canvas.getContext('2d', { willReadFrequently: true });
  const reticle        = document.getElementById('reticle');
  const bottomSheet    = document.getElementById('bottomSheet');
  const manualBar      = document.getElementById('manualBar');
  const manualInput    = document.getElementById('manualInput');
  const spinnerOverlay = document.getElementById('spinnerOverlay');
  const toast          = document.getElementById('toast');
  const cameraError    = document.getElementById('cameraError');

  /* ── Start camera ─────────────────────────────────────────── */
  async function startCamera() {
    try {
      const constraints = {
        video: {
          facingMode: { ideal: 'environment' },
          width:  { ideal: 1280 },
          height: { ideal: 720 },
        }
      };
      const stream = await navigator.mediaDevices.getUserMedia(constraints);
      video.srcObject = stream;
      video.addEventListener('loadedmetadata', () => {
        canvas.width  = video.videoWidth;
        canvas.height = video.videoHeight;
        requestAnimationFrame(tick);
      });
    } catch (err) {
      showCameraError();
    }
  }

  function showCameraError() {
    cameraError.classList.add('show');
    manualBar.style.display = 'none';
  }

  /* ── Scan loop ────────────────────────────────────────────── */
  function tick() {
    if (video.readyState === video.HAVE_ENOUGH_DATA && scanning && !scanPause) {
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
      const code = jsQR(imageData.data, imageData.width, imageData.height, {
        inversionAttempts: 'dontInvert',
      });
      if (code && code.data && code.data !== lastScanned) {
        lastScanned = code.data;
        onDecode(code.data);
      }
    }
    requestAnimationFrame(tick);
  }

  /* ── On a code decoded ────────────────────────────────────── */
  function onDecode(raw) {
    scanPause = true;
    // Extract meaningful tag — prefer URL query param "tag" or "id" if present
    let tag = raw.trim();
    try {
      const url  = new URL(raw);
      const t    = url.searchParams.get('tag') || url.searchParams.get('id') || url.searchParams.get('asset_tag');
      if (t) tag = t.trim();
    } catch (_) { /* not a URL — use raw */ }

    lookupTag(tag);
  }

  /* ── API lookup ───────────────────────────────────────────── */
  async function lookupTag(tag) {
    if (!tag) { scanPause = false; return; }
    showSpinner(true);
    try {
      const res  = await fetch('/modules/assets/scan_lookup.php?tag=' + encodeURIComponent(tag));
      const data = await res.json();
      showSpinner(false);

      if (data.found) {
        onAssetFound(data);
      } else {
        onAssetMiss(tag);
      }
    } catch (e) {
      showSpinner(false);
      showToast('Network error — try again', false);
      setTimeout(() => { scanPause = false; lastScanned = ''; }, 2500);
    }
  }

  /* ── Asset found ──────────────────────────────────────────── */
  function onAssetFound(data) {
    buzz(880, 80);
    vibrate();
    flashReticle('found');

    // Populate sheet
    document.getElementById('sheetTag').textContent  = data.asset_tag;
    document.getElementById('sheetName').textContent = data.name;

    const meta = document.getElementById('sheetMeta');
    meta.innerHTML = '';
    const badge = statusBadge(data.status);
    meta.innerHTML = badge;
    if (data.dept)     meta.innerHTML += `<span><i class="bi bi-diagram-3"></i> ${esc(data.dept)}</span>`;
    if (data.assigned) meta.innerHTML += `<span><i class="bi bi-person"></i> ${esc(data.assigned)}</span>`;
    if (data.cat)      meta.innerHTML += `<span><i class="bi bi-tag"></i> ${esc(data.cat)}</span>`;

    document.getElementById('viewBtn').href = data.view_url;

    // Slide up sheet, hide manual bar
    manualBar.style.display = 'none';
    bottomSheet.classList.add('open');
  }

  /* ── Asset not found ──────────────────────────────────────── */
  function onAssetMiss(tag) {
    flashReticle('miss');
    showToast('Asset not found: ' + tag, false);
    setTimeout(() => { scanPause = false; lastScanned = ''; }, 2500);
  }

  /* ── Resume after viewing ─────────────────────────────────── */
  window.resumeScanner = function () {
    bottomSheet.classList.remove('open');
    manualBar.style.display = '';
    manualInput.value = '';
    lastScanned = '';
    setTimeout(() => { scanPause = false; }, 300);
  };

  /* ── Manual entry ─────────────────────────────────────────── */
  window.lookupManual = function () {
    const tag = manualInput.value.trim();
    if (!tag) { manualInput.focus(); return; }
    scanPause = true;
    lookupTag(tag);
  };

  window.lookupFromError = function () {
    const tag = document.getElementById('errorManualInput').value.trim();
    if (!tag) return;
    window.location.href = '/modules/assets/index.php?search=' + encodeURIComponent(tag);
  };

  manualInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') window.lookupManual();
  });
  document.getElementById('errorManualInput')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') window.lookupFromError();
  });

  /* ── UI helpers ───────────────────────────────────────────── */
  function showSpinner(on) {
    spinnerOverlay.classList.toggle('show', on);
  }

  let toastTimer = null;
  function showToast(msg, success = false) {
    toast.textContent = msg;
    toast.className   = 'show' + (success ? ' success' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.className = ''; }, 2800);
  }

  function flashReticle(cls) {
    reticle.classList.remove('found', 'miss');
    reticle.classList.add(cls);
    setTimeout(() => reticle.classList.remove(cls), 1200);
  }

  function statusBadge(status) {
    const map = {
      active:      ['success', 'Active'],
      inactive:    ['secondary', 'Inactive'],
      maintenance: ['warning', 'Maintenance'],
      disposed:    ['danger', 'Disposed'],
      lost:        ['dark', 'Lost'],
      stolen:      ['danger', 'Stolen'],
    };
    const [cls, label] = map[status] ?? ['secondary', status ?? ''];
    return `<span><span class="badge bg-${cls}">${label}</span></span>`;
  }

  function esc(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  /* ── Audio feedback ───────────────────────────────────────── */
  function buzz(freq, ms) {
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      const osc  = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.type      = 'sine';
      osc.frequency.value = freq;
      gain.gain.setValueAtTime(0.25, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + ms / 1000);
      osc.start();
      osc.stop(audioCtx.currentTime + ms / 1000 + 0.01);
    } catch (_) {}
  }

  /* ── Haptic feedback ──────────────────────────────────────── */
  function vibrate() {
    try { navigator.vibrate && navigator.vibrate(100); } catch (_) {}
  }

  /* ── Bootstrap ────────────────────────────────────────────── */
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    showCameraError();
  } else {
    startCamera();
  }

})();
</script>
</body>
</html>
