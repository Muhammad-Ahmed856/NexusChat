<?php
require_once __DIR__ . '/config.php';
requireAuth();
$user = currentUser();
$avatarSrc = resolveAvatarSrc($user['avatar_url'] ?? null);
$avatarSrc = resolveAvatarSrc($user['avatar_url'] ?? null);

// Server-side ban gate — catches users who navigate via browser back/forward
// after being banned while already logged in.
// Uses getDB() (defined in config.php) and inlines IP detection since
// clientIp() is only defined in ajax_server.php.
try {
    $db  = getDB();
    $ip  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
    $ban = getActiveBan($db, $user['id'], $ip);
    if ($ban) {
        session_unset();
        session_destroy();
        $q = 'banned=1';
        if ($ban['reason'])     $q .= '&reason='    . urlencode($ban['reason']);
        if ($ban['expires_at']) $q .= '&expiry='    . urlencode($ban['expires_at']);
        else                    $q .= '&permanent=1';
        header('Location: auth.php?' . $q);
        exit;
    }
} catch (Exception $e) { /* DB not yet set up — skip silently */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>NexusChat — Rooms</title>
<link rel="stylesheet" href="style.css">
</head>
<body style="overflow:auto">

<div class="rooms-page">
    <nav class="rooms-topbar">
        <a href="rooms.php" class="brand-link">
            <div class="brand-icon">⚡</div>
            NexusChat
        </a>
        <div style="display:flex;align-items:center;gap:.65rem">
            <div id="rooms-user-avatar" style="position:relative;display:inline-flex">
                <div id="rooms-avatar-display">
                    <?php if ($avatarSrc): ?>
                    <img src="<?= escHtml($avatarSrc) ?>" class="avatar avatar-img sb-avatar" id="rooms-avatar-img" alt="<?= escHtml($user['username']) ?>" style="width:32px;height:32px;border-radius:9px;font-size:.75rem;cursor:pointer" onclick='openRoomAvatarViewer(<?= json_encode($avatarSrc) ?>, <?= json_encode($user["username"]) ?>)'>
                    <?php else: ?>
                    <div class="avatar" style="background:<?= escHtml($user['avatar_color']) ?>;cursor:default;width:32px;height:32px;border-radius:9px;font-size:.75rem" title="<?= escHtml($user['username']) ?>">
                        <?= escHtml(strtoupper(substr($user['username'],0,2))) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <span class="online-pip"></span>
                <div id="rooms-avatar-viewer" class="rooms-avatar-popover" onclick="event.stopPropagation()">
                    <button class="avatar-viewer-close" type="button" onclick="closeRoomAvatarViewer()" aria-label="Close preview">×</button>
                    <div id="rooms-avatar-viewer-img" class="rooms-avatar-viewer-img" role="img" aria-label="Profile picture"></div>
                </div>
            </div>
            <button class="btn btn-ghost" onclick="logout()" style="font-size:.81rem;padding:.42rem .85rem">⏻ Sign Out</button>
        </div>
    </nav>

    <div class="rooms-body">
        <div class="rooms-head">
            <div>
                <h2>Chat Rooms</h2>
                <p>Join a room or start a new conversation</p>
            </div>
            <div class="rooms-head-actions">
                <input type="text" class="search-input" id="search" placeholder="Search rooms…" oninput="filterRooms()">
                <button class="btn btn-primary rooms-create-btn" onclick="openCreate()">+ New Room</button>
            </div>
        </div>

        <div class="rooms-grid" id="rooms-grid">
            <div class="no-rooms">
                <div class="no-rooms-icon">⏳</div>
                <h3>Loading rooms…</h3>
            </div>
        </div>
    </div>
</div>

<!-- Create room modal -->
<div class="overlay" id="create-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal">
        <div class="modal-title">✨ Create a Room</div>
        <div id="create-err" class="alert alert-error" style="display:none"></div>
        <div class="form-group">
            <input type="text" id="room-name" class="form-input" placeholder=" " maxlength="100">
            <label class="form-label">Room Name</label>
        </div>
        <div style="margin-bottom:1rem">
            <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text2);display:block;margin-bottom:.5rem">Visibility</label>
            <div class="type-sel">
                <div class="type-opt on" data-type="public" onclick="pickType(this)">🌐 Public</div>
                <div class="type-opt" data-type="private" onclick="pickType(this)">🔒 Private</div>
            </div>
        </div>
        <div id="pass-group" style="display:none">
            <div class="form-group">
                <input type="password" id="room-pass" class="form-input" placeholder=" ">
                <label class="form-label">Password</label>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeCreate()">Cancel</button>
            <button class="btn btn-primary" onclick="createRoom()">Create Room</button>
        </div>
    </div>
</div>

<!-- Password modal -->
<div class="overlay" id="pass-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal">
        <div class="modal-title">🔒 Private Room</div>
        <p style="color:var(--text2);font-size:.86rem;margin-bottom:1.1rem">Enter the room password to join.</p>
        <div id="pass-err" class="alert alert-error" style="display:none"></div>
        <div class="form-group" style="margin-bottom:1.1rem">
            <input type="password" id="join-pass" class="form-input" placeholder=" ">
            <label class="form-label">Room Password</label>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="document.getElementById('pass-modal').classList.remove('open')">Cancel</button>
            <button class="btn btn-primary" onclick="submitJoin()">Join →</button>
        </div>
    </div>
</div>

<script>
const ME = { username:'<?= escHtml($user['username']) ?>', color:'<?= escHtml($user['avatar_color']) ?>' };
const CSRF_TOKEN = '<?= getCsrfToken() ?>';
let allRooms = [], pendingId = null;

const ICONS = ['💬','🚀','🎮','🎵','⚡','🔥','🌊','🎯','💡','🌍','🎨','🏆'];

function postData(fd) {
    if (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) fd.append('_csrf', CSRF_TOKEN);
    return { method: 'POST', body: fd };
}

async function loadRooms() {
    try {
        const d = await fetch('ajax_server.php?action=list_rooms').then(r=>r.json());
        if (d.success) { allRooms = d.rooms; renderRooms(d.rooms); }
    } catch(e) {}
}

function esc(s) { const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
function escA(s) { return String(s).replace(/'/g,'&#39;').replace(/"/g,'&quot;'); }

function openRoomAvatarViewer(avatarUrl, username) {
    const overlay = document.getElementById('rooms-avatar-viewer');
    const img = document.getElementById('rooms-avatar-viewer-img');
    if (!overlay || !img || !avatarUrl) return;
    img.style.backgroundImage = `url("${avatarUrl}?t=${Date.now()}")`;
    overlay.classList.add('open');
}

function closeRoomAvatarViewer() {
    const overlay = document.getElementById('rooms-avatar-viewer');
    if (overlay) overlay.classList.remove('open');
}

function applyRoomsAvatarSync(payload) {
    if (!payload || payload.username !== ME.username) return;
    const avatarUrl = payload.avatar_url || null;
    const avatarDisplay = document.getElementById('rooms-avatar-display');
    if (!avatarDisplay) return;
    avatarDisplay.innerHTML = avatarUrl
        ? `<img src="${avatarUrl}?t=${Date.now()}" class="avatar avatar-img sb-avatar" id="rooms-avatar-img" alt="${esc(payload.username)}" style="width:32px;height:32px;border-radius:9px;font-size:.75rem;cursor:pointer" onclick='openRoomAvatarViewer(${JSON.stringify(avatarUrl)}, ${JSON.stringify(payload.username)})'>`
        : `<div class="avatar" style="background:${payload.avatar_color || ME.color};cursor:default;width:32px;height:32px;border-radius:9px;font-size:.75rem" title="${esc(payload.username)}">${esc(payload.username.slice(0,2).toUpperCase())}</div>`;
}

function initRoomsAvatarSync() {
    const syncKey = 'nexuschat_avatar_sync';
    const channelName = 'nexuschat-avatar-sync';
    if (typeof window !== 'undefined') {
        window.addEventListener('storage', e => {
            if (e.key !== syncKey || !e.newValue) return;
            try { applyRoomsAvatarSync(JSON.parse(e.newValue)); } catch (err) {}
        });
        if (typeof BroadcastChannel !== 'undefined') {
            try {
                const bc = new BroadcastChannel(channelName);
                bc.onmessage = e => applyRoomsAvatarSync(e.data);
            } catch (err) {}
        }
    }
}

initRoomsAvatarSync();

function renderRooms(rooms) {
    const g = document.getElementById('rooms-grid');
    if (!rooms.length) {
        g.innerHTML = `<div class="no-rooms">
            <div class="no-rooms-icon">🏜️</div>
            <h3>No rooms yet</h3>
            <p style="margin-top:.35rem;font-size:.84rem">Create the first room to get started!</p>
        </div>`;
        return;
    }
    g.innerHTML = rooms.map((r,i) => {
        const priv = !!+r.has_password;
        const icon = ICONS[i % ICONS.length];
        return `<a class="room-card${r.is_member?' joined':''}" href="javascript:void(0)"
                   onclick="enterRoom(${r.id},'${escA(r.name)}',${priv})">
            <div class="rc-top">
                <div class="rc-icon">${icon}</div>
                <div class="rc-badges">
                    ${r.is_member ? '<span class="badge badge-join">✓ joined</span>' : ''}
                    <span class="badge ${r.type==='public'?'badge-pub':'badge-priv'}">${r.type}${priv?' 🔒':''}</span>
                </div>
            </div>
            <div class="rc-name">${esc(r.name)}</div>
            <div class="rc-stats">
                <span>👥 ${r.member_count}</span>
                <span>💬 ${r.message_count}</span>
                ${r.is_member && +r.unread_count > 0 ? `<span>📨 ${r.unread_count} unread</span>` : ''}
            </div>
            <div class="rc-foot">
                <span class="rc-creator">by ${esc(r.creator)}</span>
                <span class="rc-cta">${r.is_member?'Open':'Join'} →</span>
            </div>
        </a>`;
    }).join('');
}

function filterRooms() {
    const q = document.getElementById('search').value.toLowerCase();
    renderRooms(allRooms.filter(r => r.name.toLowerCase().includes(q)));
}

function enterRoom(id, name, priv) {
    const r = allRooms.find(r => +r.id===+id);
    if (r && r.is_member) { window.location=`index.php?room=${id}`; return; }
    if (priv) {
        pendingId = id;
        document.getElementById('join-pass').value='';
        document.getElementById('pass-err').style.display='none';
        document.getElementById('pass-modal').classList.add('open');
    } else { joinRoom(id,''); }
}

async function joinRoom(id, pass) {
    const fd = new FormData();
    fd.append('action','join_room'); fd.append('room_id',id); fd.append('password',pass);
    const d = await fetch('ajax_server.php',postData(fd)).then(r=>r.json());
    if (d.success) { window.location=`index.php?room=${id}`; }
    else {
        const el = document.getElementById('pass-err');
        el.textContent='⚠ '+(d.error||'Wrong password'); el.style.display='flex';
    }
}

function submitJoin() { joinRoom(pendingId, document.getElementById('join-pass').value); }

let selType = 'public';
function pickType(el) {
    document.querySelectorAll('.type-opt').forEach(b=>b.classList.remove('on'));
    el.classList.add('on'); selType = el.dataset.type;
    document.getElementById('pass-group').style.display = selType==='private'?'block':'none';
}

function openCreate() { document.getElementById('create-modal').classList.add('open'); }
function closeCreate() { document.getElementById('create-modal').classList.remove('open'); }

async function createRoom() {
    const name = document.getElementById('room-name').value.trim();
    const pass = document.getElementById('room-pass').value;
    const err = document.getElementById('create-err');
    err.style.display='none';
    const fd = new FormData();
    fd.append('action','create_room'); fd.append('name',name);
    fd.append('type',selType); fd.append('password',pass);
    const d = await fetch('ajax_server.php',postData(fd)).then(r=>r.json());
    if (d.success) { closeCreate(); window.location=`index.php?room=${d.room_id}`; }
    else { err.textContent='⚠ '+(d.error||'Failed'); err.style.display='flex'; }
}

async function logout() {
    await fetch('ajax_server.php', (() => { const fd = new FormData(); fd.append('action','auth_logout'); return postData(fd); })());
    window.location='auth.php';
}

document.addEventListener('keydown', e => {
    if (e.key==='Enter' && document.getElementById('pass-modal').classList.contains('open')) submitJoin();
    if (e.key==='Escape') document.querySelectorAll('.overlay').forEach(m=>m.classList.remove('open'));
});

loadRooms();
setInterval(loadRooms, 15000);
</script>
<?php include __DIR__ . '/_admin_bar.php'; ?>
</body>
</html>