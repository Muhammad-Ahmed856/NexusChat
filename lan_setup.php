<?php
/**
 * lan_setup.php — NexusChat LAN Connection Helper
 *
 * Open this page from the host machine to get the shareable LAN URL
 * and verify that Apache and the WebSocket server are reachable.
 *
 * Access: http://localhost/nexuschat/lan_setup.php
 */
require_once __DIR__ . '/config.php';
requireAuth();   // Must be logged in to view server network info

$lanIp    = detectLanIp();
$wsPort   = WS_PORT;
$appPath  = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$appUrl   = 'http://' . $lanIp . $appPath;
$wsUrl    = 'ws://'   . $lanIp . ':' . $wsPort;
$authUrl  = $appUrl . '/auth.php';
$wsTestOk = null;   // filled by JS

// Try a server-side TCP check to the WS port
$wsOpen = @fsockopen($lanIp, $wsPort, $errno, $errstr, 1);
if ($wsOpen) { fclose($wsOpen); $wsServerOk = true; }
else          { $wsServerOk = false; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>⚡ NexusChat — LAN Setup</title>
<link rel="stylesheet" href="style.css">
<style>
body { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:2rem; }
.lan-card { background:var(--surface); border:1px solid var(--border); border-radius:18px;
            padding:2.4rem; max-width:680px; width:100%; }
.lan-title { font-size:1.6rem; font-weight:800; color:var(--teal); margin-bottom:.3rem; }
.lan-sub   { color:var(--text2); margin-bottom:2rem; }
.url-box   { background:var(--surface2); border:2px solid var(--teal); border-radius:10px;
             padding:1rem 1.2rem; font-family:'Fira Code',monospace; font-size:1.05rem;
             color:var(--text); word-break:break-all; margin:.6rem 0 1.4rem; cursor:pointer;
             transition:background .15s; }
.url-box:hover { background:rgba(0,212,170,.08); }
.url-label { font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px;
             color:var(--text3); margin-bottom:.2rem; }
.status-row { display:flex; align-items:center; gap:.7rem; margin:.5rem 0; font-size:.9rem; }
.dot-ok  { color:#43e97b; font-size:1rem; }
.dot-err { color:#f7706a; font-size:1rem; }
.dot-spin{ animation:spin 1s linear infinite; display:inline-block; }
@keyframes spin { to { transform:rotate(360deg); } }
.steps   { background:var(--surface2); border-radius:10px; padding:1.2rem 1.4rem; margin:1.4rem 0; }
.steps h3{ color:var(--teal); margin:0 0 .8rem; font-size:1rem; }
.steps ol{ margin:0; padding-left:1.4rem; color:var(--text2); line-height:2; }
.steps code{ background:var(--bg); padding:.1rem .4rem; border-radius:4px;
             font-family:'Fira Code',monospace; font-size:.85rem; color:var(--text); }
.btn-go  { display:inline-block; background:var(--teal); color:#0a0a14; font-weight:700;
           border-radius:10px; padding:.75rem 2rem; text-decoration:none; font-size:1rem;
           margin-top:1rem; transition:transform .12s; }
.btn-go:hover { transform:translateY(-2px); }
.warn-box{ background:rgba(245,166,35,.1); border:1px solid rgba(245,166,35,.35);
           border-radius:8px; padding:.8rem 1rem; color:#f5a623; font-size:.85rem;
           margin-bottom:1rem; }
.copy-hint{ font-size:.72rem; color:var(--text3); margin-top:.3rem; }
hr { border:none; border-top:1px solid var(--border); margin:1.6rem 0; }
</style>
</head>
<body>
<div class="lan-card">

  <div class="lan-title">📡 NexusChat — LAN Setup</div>
  <div class="lan-sub">Share the link below with anyone on the same Wi-Fi or Ethernet network.</div>

  <?php if ($lanIp === 'localhost'): ?>
  <div class="warn-box">
    ⚠ Could not detect a LAN IP — showing <strong>localhost</strong>. This only works on <em>this machine</em>.
    See the fix below.
  </div>
  <?php endif; ?>

  <!-- App URL -->
  <div class="url-label">🌐 Chat URL (share this link)</div>
  <div class="url-box" onclick="copyUrl(this,'<?= escHtml($authUrl) ?>')" title="Click to copy">
    <?= escHtml($authUrl) ?>
  </div>
  <div class="copy-hint">Click the box to copy · Other devices: open this URL in any browser</div>

  <!-- WS URL (info only) -->
  <div class="url-label" style="margin-top:1rem">🔌 WebSocket URL (auto-used by the app)</div>
  <div class="url-box" onclick="copyUrl(this,'<?= escHtml($wsUrl) ?>')" title="Click to copy">
    <?= escHtml($wsUrl) ?>
  </div>

  <hr>

  <!-- Status checks -->
  <strong style="color:var(--text)">Connection Status</strong>
  <div class="status-row">
    <?php if ($wsServerOk): ?>
      <span class="dot-ok">●</span> WebSocket server is <strong>running</strong> on port <?= $wsPort ?>
    <?php else: ?>
      <span class="dot-err">●</span> WebSocket server is <strong>NOT running</strong> on port <?= $wsPort ?> — start it first (see below)
    <?php endif; ?>
  </div>
  <div class="status-row" id="ws-client-check">
    <span class="dot-spin">↻</span> Testing WebSocket from your browser…
  </div>
  <div class="status-row" id="http-check">
    <span class="dot-spin">↻</span> Testing HTTP reachability…
  </div>

  <hr>

  <!-- Steps -->
  <div class="steps">
    <h3>📋 Quick-Start Checklist</h3>
    <ol>
      <li>On the <strong>host machine</strong>, start XAMPP — both <strong>Apache</strong> and <strong>MySQL</strong> must be green.</li>
      <li>Open a terminal on the host and run:<br>
          <code>php <?= escHtml(str_replace('/', DIRECTORY_SEPARATOR, $_SERVER['DOCUMENT_ROOT'] . $appPath)) ?>\socket_server.php</code><br>
          Keep this window open the whole time.</li>
      <li>Make sure Windows Firewall (or your OS firewall) allows <strong>TCP port 80</strong> (Apache) and <strong>TCP port <?= $wsPort ?></strong> (WebSocket).</li>
      <li>All other devices must be on the <strong>same Wi-Fi or LAN switch</strong> — no internet required.</li>
      <li>On each other device, open a browser and go to:<br>
          <code><?= escHtml($authUrl) ?></code></li>
    </ol>
  </div>

  <?php if ($lanIp === 'localhost'): ?>
  <div class="steps" style="border:1px solid rgba(245,166,35,.3)">
    <h3>🔧 Fix: LAN IP Not Detected</h3>
    <ol>
      <li>Find your LAN IP: on Windows run <code>ipconfig</code>, look for <em>IPv4 Address</em> (e.g. <code>192.168.1.10</code>). On Mac/Linux run <code>ip addr</code> or <code>ifconfig</code>.</li>
      <li>Open <code>config.php</code> and set:<br>
          <code>define('LAN_HOST_OVERRIDE', '192.168.1.10');</code></li>
      <li>Reload this page — the correct URL will appear above.</li>
    </ol>
  </div>
  <?php endif; ?>

  <a href="<?= escHtml($authUrl) ?>" class="btn-go">→ Open NexusChat</a>
</div>

<script>
function copyUrl(el, url) {
    navigator.clipboard.writeText(url).then(() => {
        const orig = el.style.border;
        el.style.border = '2px solid #43e97b';
        setTimeout(() => el.style.border = orig, 1200);
    }).catch(() => {
        const sel = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(el);
        sel.removeAllRanges(); sel.addRange(range);
    });
}

// Browser-side WebSocket test
(function() {
    const el = document.getElementById('ws-client-check');
    const ws = new WebSocket('<?= escHtml($wsUrl) ?>');
    const t  = setTimeout(() => {
        el.innerHTML = '<span class="dot-err">●</span> WebSocket: <strong>no response</strong> in 3 s — check firewall / socket_server.php';
        try { ws.close(); } catch(e) {}
    }, 3000);
    ws.onopen = () => {
        clearTimeout(t);
        el.innerHTML = '<span class="dot-ok">●</span> WebSocket connection from this browser: <strong>OK</strong>';
        ws.close();
    };
    ws.onerror = () => {
        clearTimeout(t);
        el.innerHTML = '<span class="dot-err">●</span> WebSocket: <strong>connection refused</strong> — is socket_server.php running?';
    };
})();

// HTTP test
(function() {
    const el = document.getElementById('http-check');
    fetch('ajax_server.php?action=auth_status')
        .then(r => r.json())
        .then(d => {
            el.innerHTML = '<span class="dot-ok">●</span> HTTP API reachable — Apache is serving NexusChat correctly';
        }).catch(() => {
            el.innerHTML = '<span class="dot-err">●</span> HTTP API not reachable — is Apache running?';
        });
})();
</script>
<?php include __DIR__ . '/_admin_bar.php'; ?>
</body>
</html>
