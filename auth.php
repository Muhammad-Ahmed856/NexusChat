<?php
require_once __DIR__ . '/config.php';
initSession();
if (isLoggedIn()) { header('Location: rooms.php'); exit; }

// ── Ban redirect params ────────────────────────────────────────────────────────
// When a banned user is signed out by the heartbeat, script.js redirects here
// with ?banned=1 and optional reason / expiry params.
$wasBanned = !empty($_GET['banned']);
$banReason = $wasBanned && !empty($_GET['reason']) ? trim($_GET['reason']) : null;
$banExpiry = $wasBanned && !empty($_GET['expiry']) ? trim($_GET['expiry']) : null;
$banPerm   = $wasBanned && !empty($_GET['permanent']);

// LAN info — only show when accessed from the host machine (localhost/127.x)
$remoteIp    = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocalHost = in_array($remoteIp, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
$lanIp       = detectLanIp();
$appPath     = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$lanUrl      = 'http://' . $lanIp . $appPath . '/auth.php';
$setupUrl    = 'http://' . $lanIp . $appPath . '/lan_setup.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>NexusChat — Sign In</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<?php if ($isLocalHost && $lanIp !== 'localhost'): ?>
<div style="background:rgba(0,212,170,.1);border-bottom:1px solid rgba(0,212,170,.3);
            padding:.7rem 1.4rem;font-size:.82rem;color:var(--text2);text-align:center;
            display:flex;align-items:center;justify-content:center;gap:.8rem;flex-wrap:wrap">
    <span>📡 <strong style="color:var(--teal)">LAN Mode active</strong> — share this link with others on your network:</span>
    <code style="background:var(--surface2);padding:.2rem .7rem;border-radius:6px;
                 color:var(--text);cursor:pointer;font-size:.8rem"
          onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= escHtml($lanUrl) ?>').then(()=>{this.textContent='✓ Copied!';setTimeout(()=>{this.textContent='<?= escHtml($lanUrl) ?>';},1500)})"
          title="Click to copy"><?= escHtml($lanUrl) ?></code>
    <a href="<?= escHtml($setupUrl) ?>" style="color:var(--teal);font-size:.8rem">Setup guide →</a>
</div>
<?php endif; ?>

<div class="auth-page">
    <div class="auth-card">
        <div class="auth-brand">
            <div class="auth-brand-icon">⚡</div>
            <h1>NexusChat</h1>
            <p>Real-time messaging, reimagined</p>
        </div>

        <?php if ($wasBanned): ?>
        <div style="
            background:rgba(255,80,80,.08);
            border:1.5px solid rgba(255,80,80,.3);
            border-radius:14px;
            padding:1.2rem 1.4rem;
            margin-bottom:1.2rem;
            display:flex;
            flex-direction:column;
            gap:.6rem;
        ">
            <div style="display:flex;align-items:center;gap:.6rem">
                <span style="font-size:1.3rem">🚫</span>
                <span style="font-weight:800;color:#ff6b6b;font-size:1rem;">
                    Your account has been banned
                </span>
            </div>
            <?php if ($banReason): ?>
            <div style="font-size:.85rem;color:#ccd0d8;">
                <span style="color:rgba(255,107,107,.7);font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;">Reason</span><br>
                <?= escHtml($banReason) ?>
            </div>
            <?php endif; ?>
            <div style="font-size:.82rem;color:#8892a4;">
                <?php if ($banExpiry): ?>
                    ⏳ This ban expires on <strong style="color:#dde2f0"><?= escHtml($banExpiry) ?></strong>.
                <?php elseif ($banPerm): ?>
                    ⛔ This ban is <strong style="color:#ff6b6b">permanent</strong>.
                <?php else: ?>
                    Contact the server administrator for more information.
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="auth-tabs">
            <button class="auth-tab on" onclick="switchTab('login')">Sign In</button>
            <button class="auth-tab" onclick="switchTab('register')">Register</button>
        </div>

        <!-- Login -->
        <div id="tab-login" class="auth-panel on">
            <div id="login-err" class="alert alert-error" style="display:none"></div>
            <div class="form-group">
                <input type="text" id="login-user" class="form-input" placeholder=" " autocomplete="username">
                <label class="form-label">Username</label>
            </div>
            <div class="form-group password-group" style="margin-bottom:1.3rem">
                <input type="password" id="login-pass" class="form-input" placeholder=" " autocomplete="current-password">
                <button type="button" class="pw-toggle" onclick="togglePassword('login-pass', this)" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                <label class="form-label">Password</label>
            </div>
            <button class="btn btn-primary btn-full" onclick="doLogin()">Sign In →</button>
        </div>

        <!-- Register -->
        <div id="tab-register" class="auth-panel">
            <div id="reg-err" class="alert alert-error" style="display:none"></div>
            <div class="form-group">
                <input type="text" id="reg-user" class="form-input" placeholder=" " autocomplete="username">
                <label class="form-label">Username</label>
            </div>
            <div class="form-group">
                <input type="email" id="reg-email" class="form-input" placeholder=" " autocomplete="email">
                <label class="form-label">Email (optional)</label>
            </div>
            <div class="form-group password-group">
                <input type="password" id="reg-pass" class="form-input" placeholder=" " autocomplete="new-password">
                <button type="button" class="pw-toggle" onclick="togglePassword('reg-pass', this)" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                <label class="form-label">Password</label>
            </div>
            <div style="margin-bottom:1.3rem">
                <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text2);display:block;margin-bottom:.5rem">Avatar Color</label>
                <div class="swatches" id="color-swatches">
                <?php
                $colors = ['#00d4aa','#4f8ef7','#f7706a','#f5a623','#9b7ff5','#3dd68c','#f093fb','#00b4d8','#ff6584','#43e97b'];
                foreach ($colors as $i => $c): ?>
                    <div class="swatch <?= $i===0?'on':'' ?>" style="background:<?= $c ?>" data-color="<?= $c ?>" onclick="pickColor(this)"></div>
                <?php endforeach; ?>
                </div>
                <input type="hidden" id="avatar-color" value="<?= $colors[0] ?>">
            </div>
            <button class="btn btn-primary btn-full" onclick="doRegister()">Create Account →</button>
        </div>
    </div>
</div>

<script>
function switchTab(t) {
    document.querySelectorAll('.auth-tab').forEach((b,i) => b.classList.toggle('on', (i===0&&t==='login')||(i===1&&t==='register')));
    document.querySelectorAll('.auth-panel').forEach(p => p.classList.remove('on'));
    document.getElementById('tab-'+t).classList.add('on');
}

function pickColor(el) {
    document.querySelectorAll('.swatch').forEach(s => s.classList.remove('on'));
    el.classList.add('on');
    document.getElementById('avatar-color').value = el.dataset.color;
}

function passwordToggleIcon(showing) {
    if (showing) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19C5 19 1 12 1 12a21.76 21.76 0 0 1 5.06-6.94"></path><path d="M9.9 4.24A10.94 10.94 0 0 1 12 5c7 0 11 7 11 7a21.8 21.8 0 0 1-3.17 4.24"></path><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
    }
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
}

function togglePassword(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input || !btn) return;
    input.type = input.type === 'password' ? 'text' : 'password';
    const showing = input.type === 'text';
    btn.innerHTML = passwordToggleIcon(showing);
    btn.classList.toggle('is-on', showing);
    btn.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
}

function showErr(id, msg) {
    const el = document.getElementById(id);
    el.textContent = '⚠ ' + msg;
    el.style.display = 'flex';
    el.style.animation = 'none';
    el.offsetHeight;
    el.style.animation = '';
}

async function doLogin() {
    const err = 'login-err';
    document.getElementById(err).style.display = 'none';
    const u = document.getElementById('login-user').value.trim();
    const p = document.getElementById('login-pass').value;
    if (!u || !p) { showErr(err, 'Please fill in all fields'); return; }
    const fd = new FormData();
    fd.append('action','auth_login'); fd.append('username',u); fd.append('password',p);
    try {
        const d = await fetch('ajax_server.php',{method:'POST',body:fd}).then(r=>r.json());
        if (d.success) {
            if (d.csrf_token) sessionStorage.setItem('csrf_token', d.csrf_token);
            window.location='rooms.php';
        } else showErr(err, d.error||'Login failed');
    } catch(e) { showErr(err,'Network error'); }
}

async function doRegister() {
    const err = 'reg-err';
    document.getElementById(err).style.display = 'none';
    const u = document.getElementById('reg-user').value.trim();
    const e = document.getElementById('reg-email').value.trim();
    const p = document.getElementById('reg-pass').value;
    const c = document.getElementById('avatar-color').value;
    const fd = new FormData();
    fd.append('action','auth_register'); fd.append('username',u);
    fd.append('email',e); fd.append('password',p); fd.append('avatar_color',c);
    try {
        const d = await fetch('ajax_server.php',{method:'POST',body:fd}).then(r=>r.json());
        if (d.success) {
            if (d.csrf_token) sessionStorage.setItem('csrf_token', d.csrf_token);
            window.location='rooms.php';
        } else showErr(err, d.error||'Registration failed');
    } catch(e) { showErr(err,'Network error'); }
}

document.addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    if (document.getElementById('tab-login').classList.contains('on')) doLogin();
    else doRegister();
});
</script>
</body>
</html>
