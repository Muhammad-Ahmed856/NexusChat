<?php
require_once __DIR__ . '/config.php';
requireAuth();
$user   = currentUser();
$roomId = (int)($_GET['room'] ?? 0);

if ($roomId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT r.*, u.username AS creator FROM rooms r LEFT JOIN users u ON u.id = r.created_by WHERE r.id = ?");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room) { header('Location: rooms.php'); exit; }
    $stmt = $db->prepare("SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$roomId, $user['id']]);
    if (!$stmt->fetch()) { header('Location: rooms.php'); exit; }
} else {
    header('Location: rooms.php'); exit;
}

// Resolve avatar source for sidebar footer
$avatarSrc = resolveAvatarSrc($user['avatar_url'] ?? null);
$styleVer = @filemtime(__DIR__ . '/style.css') ?: time();
$scriptVer = @filemtime(__DIR__ . '/script.js') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= escHtml($room['name']) ?> — NexusChat</title>
<link rel="stylesheet" href="style.css?v=<?= $styleVer ?>">
</head>
<body class="chat-body">

<!-- Account modal -->
<div class="overlay" id="account-modal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal">
    <div class="modal-title">👤 Account Settings</div>
    <div id="account-err"  class="alert alert-error"   style="display:none"></div>
    <div id="account-ok"   class="alert alert-success"  style="display:none"></div>

    <!-- Profile picture section -->
    <div class="acc-avatar-row">
        <div class="acc-avatar-preview" id="acc-avatar-preview">
            <?php if ($avatarSrc): ?>
                <img src="<?= escHtml($avatarSrc) ?>" alt="avatar" id="acc-avatar-img" style="cursor:pointer" onclick='openAvatarViewer(<?= json_encode($avatarSrc) ?>, <?= json_encode($user["username"]) ?>)'>
            <?php else: ?>
                <div class="acc-avatar-initials" id="acc-avatar-initials" style="background:<?= escHtml($user['avatar_color']) ?>">
                    <?= escHtml(strtoupper(substr($user['username'],0,2))) ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="acc-avatar-actions">
            <label class="btn btn-ghost" style="cursor:pointer;font-size:.8rem;padding:.38rem .8rem">
                📷 Change Photo
                <input type="file" id="avatar-file-input" accept="image/*" style="display:none" onchange="uploadAvatar(this)">
            </label>
            <?php if ($avatarSrc): ?>
            <button class="btn btn-ghost" style="font-size:.8rem;padding:.38rem .8rem;color:var(--red)" onclick="removeAvatar()" id="remove-avatar-btn">🗑 Remove</button>
            <?php else: ?>
            <button class="btn btn-ghost" style="font-size:.8rem;padding:.38rem .8rem;color:var(--red);display:none" onclick="removeAvatar()" id="remove-avatar-btn">🗑 Remove</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-group">
        <input type="text" id="acc-username" class="form-input" placeholder=" " value="<?= escHtml($user['username']) ?>">
        <label class="form-label">Username</label>
    </div>
    <div class="form-group">
        <input type="email" id="acc-email" class="form-input" placeholder=" " value="<?= escHtml($user['email'] ?? '') ?>">
        <label class="form-label">Email (optional)</label>
    </div>
    <div class="form-group password-group">
        <input type="password" id="acc-password" class="form-input" placeholder=" ">
        <button type="button" class="pw-toggle" onclick="togglePassword('acc-password', this)" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
        <label class="form-label">New password (blank = keep)</label>
    </div>
    <div style="margin-bottom:1.1rem">
        <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text2);display:block;margin-bottom:.5rem">Avatar Color (used when no photo)</label>
        <div class="swatches" id="acc-color-picker"></div>
        <input type="hidden" id="acc-color" value="<?= escHtml($user['avatar_color']) ?>">
    </div>
    <div class="modal-footer" style="justify-content:space-between">
        <button class="btn btn-danger" onclick="showDeleteConfirm()">Delete Account</button>
        <div style="display:flex;gap:.55rem">
            <button class="btn btn-ghost" onclick="document.getElementById('account-modal').classList.remove('open')">Cancel</button>
            <button class="btn btn-primary" onclick="saveAccount()">Save Changes</button>
        </div>
    </div>
    <div id="delete-confirm" style="display:none;margin-top:1rem;padding:.9rem;background:rgba(247,112,106,.06);border:1px solid rgba(247,112,106,.15);border-radius:10px">
        <p style="font-size:.82rem;color:var(--red);font-weight:600;margin-bottom:.7rem">⚠ This cannot be undone. Enter your password:</p>
        <div class="form-group password-group" style="margin-bottom:.75rem">
            <input type="password" id="del-pass" class="form-input" placeholder="Your password">
            <button type="button" class="pw-toggle" onclick="togglePassword('del-pass', this)" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
            <label class="form-label">Your password</label>
        </div>
        <button class="btn btn-danger" onclick="deleteAccount()">Confirm Delete</button>
    </div>
  </div>
</div>

<!-- Members modal -->
<div class="overlay" id="members-modal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal">
    <div class="modal-title">👥 Room Members</div>
    <div id="members-list" style="max-height:300px;overflow-y:auto;margin:1rem 0"></div>
    <?php if ($room['created_by'] == $user['id']): ?>
    <div style="border-top:1px solid var(--border);padding-top:1rem;margin-top:.5rem">
        <p style="font-size:.78rem;color:var(--text2);font-weight:600;margin-bottom:.7rem">Change Room Password</p>
        <div style="display:flex;gap:.6rem;align-items:flex-end">
            <div class="form-group password-group" style="flex:1;margin:0">
                <input type="password" id="new-room-pass" class="form-input" placeholder=" ">
                <button type="button" class="pw-toggle" onclick="togglePassword('new-room-pass', this)" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                <label class="form-label">New password (empty = remove)</label>
            </div>
            <button class="btn btn-primary" style="padding:.68rem 1rem;flex-shrink:0" onclick="changeRoomPassword()">Set</button>
        </div>
    </div>
    <?php endif; ?>
    <div class="modal-footer">
        <button class="btn btn-ghost" onclick="document.getElementById('members-modal').classList.remove('open')">Close</button>
    </div>
  </div>
</div>

<!-- Avatar viewer -->
<div class="overlay" id="avatar-viewer" onclick="closeAvatarViewer()">
    <div class="avatar-viewer-box" onclick="event.stopPropagation()">
        <button class="avatar-viewer-close" type="button" onclick="closeAvatarViewer()" aria-label="Close preview">×</button>
        <img id="avatar-viewer-img" alt="Profile picture">
        <div class="avatar-viewer-name" id="avatar-viewer-name">Profile picture</div>
    </div>
</div>

<div class="chat-layout">
    <!-- Mobile sidebar overlay backdrop -->
    <div class="mobile-sb-overlay" id="mobile-sb-overlay" onclick="closeMobileSidebar()"></div>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sb-header">
            <a href="rooms.php" class="sb-brand">
                <div class="sb-brand-icon">⚡</div>
                <span>NexusChat</span>
            </a>
        </div>

        <div class="sb-section">
            <div class="sb-label">Navigate</div>
            <a href="rooms.php" class="sb-link">
                <div class="sb-link-icon">🏠</div>
                <span>All Rooms</span>
            </a>
        </div>

        <!-- Current Room Info -->
        <div class="sb-section">
            <div class="sb-label">Current Room</div>
            <div class="sb-room">
                <span class="active-dot"></span>
                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= escHtml($room['name']) ?></span>
                <span style="font-size:.62rem;color:var(--text3)"><?= $room['type'] ?></span>
            </div>
        </div>

        <!-- Dashboard Stats Panel -->
        <div class="sb-section sb-stats-section" style="flex:1">
            <div class="sb-label">Dashboard</div>
            <div class="sb-stats-grid">
                <div class="sb-stat">
                    <div class="sb-stat-val" id="stat-active">—</div>
                    <div class="sb-stat-lbl">Online Now</div>
                </div>
                <div class="sb-stat">
                    <div class="sb-stat-val" id="stat-msgs-hour">—</div>
                    <div class="sb-stat-lbl">Msgs/hr</div>
                </div>
                <div class="sb-stat">
                    <div class="sb-stat-val" id="stat-members">—</div>
                    <div class="sb-stat-lbl">Members</div>
                </div>
                <div class="sb-stat">
                    <div class="sb-stat-val" id="stat-total-msgs">—</div>
                    <div class="sb-stat-lbl">Total Msgs</div>
                </div>
                <div class="sb-stat">
                    <div class="sb-stat-val" id="stat-rooms">—</div>
                    <div class="sb-stat-lbl">Rooms</div>
                </div>
                <div class="sb-stat">
                    <div class="sb-stat-val" id="stat-users">—</div>
                    <div class="sb-stat-lbl">Users</div>
                </div>
            </div>
            <div class="sb-stat-time">Updated <span id="stat-time">…</span></div>
        </div>

        <!-- LAN sharing banner — shown when LAN mode is active -->
        <div id="lan-banner" style="display:none;padding:.5rem .8rem;background:rgba(0,212,170,.07);
             border-top:1px solid rgba(0,212,170,.2);border-bottom:1px solid rgba(0,212,170,.2)">
            <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;
                        letter-spacing:.4px;color:var(--teal);margin-bottom:.25rem">
                📡 LAN Mode
            </div>
            <div style="font-size:.72rem;color:var(--text2);word-break:break-all;
                        cursor:pointer;line-height:1.4" id="lan-share-url"
                 onclick="copyLanUrl()" title="Click to copy">
                Loading…
            </div>
            <div style="font-size:.65rem;color:var(--text3);margin-top:.2rem">
                Share with devices on this network ·
                <a id="lan-setup-link" href="lan_setup.php" style="color:var(--teal);text-decoration:none">Setup guide</a>
            </div>
        </div>

        <div class="sb-footer">
            <div id="room-avatar-trigger" style="position:relative;display:inline-flex;flex-shrink:0;cursor:pointer" onclick="openAccountModal()" title="Account Settings">
                <?php if ($avatarSrc): ?>
                    <img id="room-avatar-img" src="<?= escHtml($avatarSrc) ?>" class="avatar avatar-img sb-avatar" alt="avatar" onclick='event.stopPropagation(); openAvatarViewer(<?= json_encode($avatarSrc) ?>, <?= json_encode($user["username"]) ?>)'>
                <?php else: ?>
                    <div class="avatar" style="background:<?= escHtml($user['avatar_color']) ?>">
                        <?= escHtml(strtoupper(substr($user['username'],0,2))) ?>
                    </div>
                <?php endif; ?>
                <span class="online-pip"></span>
            </div>
            <div style="flex:1;min-width:0">
                <div class="sb-footer-name"><?= escHtml($user['username']) ?></div>
                <div class="sb-footer-status" id="ws-status" style="color:var(--teal)">● Live</div>
            </div>
            <button class="ibtn" onclick="logout()" title="Sign Out" style="font-size:1.1rem">⏻</button>
        </div>
    </aside>

    <!-- Chat -->
    <main class="chat-main">
        <header class="chat-header">
            <!-- Hamburger — mobile only -->
            <button class="hamburger-btn" id="hamburger-btn" onclick="toggleMobileSidebar()" title="Menu" aria-label="Open sidebar">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                    <line x1="3" y1="6"  x2="21" y2="6"/>
                    <line x1="3" y1="12" x2="21" y2="12"/>
                    <line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>
            <div class="chat-title">
                <div class="chat-room-name">
                    <span class="room-hash">#</span><?= escHtml($room['name']) ?>
                </div>
                <div class="chat-sub">
                    <span class="chat-sub-dot"></span>
                    <span id="members-count">Loading…</span>
                </div>
            </div>
            <div class="chat-actions">
                <button class="ibtn" onclick="openMembersModal()" title="Members" style="font-size:1.1rem">👥</button>
                <div class="dd-wrap">
                    <button class="ibtn" id="settings-btn" onclick="toggleSettings()" style="font-size:1.1rem">⚙️</button>
                    <div class="dd" id="settings-menu">
                        <div class="dd-item" onclick="openAccountModal()">👤 Account Settings</div>
                        <div class="dd-item" onclick="clearChat()">🗑️ Clear Chat</div>
                        <div class="dd-sep"></div>
                        <?php if ($room['created_by'] != $user['id']): ?>
                        <div class="dd-item red" onclick="leaveRoom()">🚪 Leave Room</div>
                        <?php endif; ?>
                        <div class="dd-item red" onclick="logout()">⏻ Sign Out</div>
                    </div>
                </div>
            </div>
        </header>

        <div class="msgs-area" id="msgs-area">
            <div class="msgs-loading" id="msgs-loading">
                <div class="spinner"></div>
                Loading messages…
            </div>
        </div>

        <!-- Scroll-to-bottom button — appears when user scrolls up -->
        <button class="scroll-to-bottom" id="scroll-to-bottom" onclick="scrollToBottom(true)" aria-label="Scroll to latest messages" style="display:none">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9"/>
            </svg>
        </button>

        <div class="typing-area" id="typing-indicator" style="display:none">
            <div class="dots"><span></span><span></span><span></span></div>
            <span id="typing-text"></span>
        </div>

        <footer class="chat-footer">
            <!-- File preview bar -->
            <div class="file-preview-bar" id="file-preview-bar" style="display:none">
                <div class="file-preview-inner" id="file-preview-inner"></div>
                <button class="file-preview-cancel" onclick="cancelAttachment()" title="Remove attachment">✕</button>
            </div>
            <!-- Voice recording panel -->
            <div class="voice-bar" id="voice-bar" style="display:none">
                <div class="voice-pulse" id="voice-pulse">
                    <span class="voice-dot"></span><span class="voice-dot"></span><span class="voice-dot"></span>
                </div>
                <span class="voice-timer" id="voice-timer">0:00</span>
                <div class="voice-level-wrap"><div class="voice-level" id="voice-level"></div></div>
                <button class="voice-cancel-btn" onclick="cancelVoice()" title="Discard">✕</button>
                <button class="voice-send-btn" onclick="stopAndSendVoice()" title="Send voice message">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                </button>
            </div>
            <div class="input-shell" id="input-shell">
                <input type="file" id="file-input" style="display:none"
                    accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.txt,.csv"
                    onchange="handleFileSelect(this)">
                <input type="file" id="voice-file-input" style="display:none"
                    accept="audio/*,.m4a,.mp3,.wav,.ogg,.webm,.aac,.mp4"
                    capture
                    onchange="handleVoiceFileSelect(this)">
                <button class="attach-btn" onclick="document.getElementById('file-input').click()" title="Attach file">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                    </svg>
                </button>
                <!-- Emoji picker toggle button -->
                <button class="attach-btn emoji-toggle-btn" id="emoji-toggle-btn" onclick="toggleEmojiPicker()" title="Emoji">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M8 14s1.5 2 4 2 4-2 4-2"/>
                        <line x1="9" y1="9" x2="9.01" y2="9"/>
                        <line x1="15" y1="9" x2="15.01" y2="9"/>
                    </svg>
                </button>
                <textarea id="msg-input" class="msg-input"
                    placeholder="Message #<?= escHtml($room['name']) ?>…" rows="1"
                    onkeydown="handleKey(event)"
                    oninput="autoResize(this);notifyTyping();toggleMicSend()"></textarea>
                <button class="mic-btn" id="mic-btn" onclick="startVoice()" title="Record voice message">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="9" y="2" width="6" height="12" rx="3"/>
                        <path d="M19 10a7 7 0 0 1-14 0"/>
                        <line x1="12" y1="19" x2="12" y2="22"/>
                        <line x1="8"  y1="22" x2="16" y2="22"/>
                    </svg>
                </button>
                <button class="send-btn" id="send-btn" style="display:none" onclick="sendMessage()" title="Send">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                    </svg>
                </button>
            </div>

            <!-- Emoji picker panel -->
            <div class="emoji-picker" id="emoji-picker" style="display:none">
                <div class="emoji-picker-header">
                    <input type="text" class="emoji-search" id="emoji-search"
                           placeholder="Search emoji…" oninput="filterEmoji(this.value)" autocomplete="off">
                </div>
                <div class="emoji-category-tabs" id="emoji-cat-tabs"></div>
                <div class="emoji-grid-wrap">
                    <div class="emoji-section-label" id="emoji-section-label">Frequently Used</div>
                    <div class="emoji-grid" id="emoji-grid"></div>
                </div>
            </div>
            <!-- Connection info bar: shows user's IP address and port -->
            <div class="conn-bar" id="conn-bar" title="Your IP address and session port">
                <span class="conn-dot">●</span>
                <span class="conn-bar-label">IP</span>
                <span class="conn-val conn-ip-val" id="conn-ip">…</span>
                <span class="conn-sep">·</span>
                <span class="conn-bar-label">Port</span>
                <span class="conn-val conn-port-val" id="conn-port">…</span>
                <span class="conn-label" id="conn-ws-label"></span>
            </div>
        </footer>
    </main>
</div>

<script>
const ROOM_ID    = <?= $roomId ?>;
const ROOM_NAME  = '<?= escHtml($room['name']) ?>';
const IS_CREATOR = <?= ($room['created_by'] == $user['id']) ? 'true' : 'false' ?>;
const ME = {
    id: <?= $user['id'] ?>,
    username: '<?= escHtml($user['username']) ?>',
    avatarColor: '<?= escHtml($user['avatar_color']) ?>',
    avatarUrl: <?= json_encode($user['avatar_url'] ? 'uploads/avatars/' . basename($user['avatar_url']) : null) ?>,
};
const WS_URL = `ws://<?= defined('WS_PUBLIC_HOST') ? WS_PUBLIC_HOST : (isset($_SERVER['HTTP_HOST']) ? explode(':', $_SERVER['HTTP_HOST'])[0] : 'localhost') ?>:<?= WS_PORT ?>`;
const COLORS = ['#00d4aa','#4f8ef7','#f7706a','#f5a623','#9b7ff5','#3dd68c','#f093fb','#00b4d8','#ff6584','#43e97b'];
const CSRF_TOKEN = '<?= getCsrfToken() ?>';
const DELETE_FOR_EVERYONE_WINDOW_SECONDS = <?= (int)DELETE_FOR_EVERYONE_WINDOW_SECONDS ?>;
const ROOM_ADMIN_DELETE_FOR_EVERYONE_WINDOW_SECONDS = <?= (int)ROOM_ADMIN_DELETE_FOR_EVERYONE_WINDOW_SECONDS ?>;
const EDIT_MESSAGE_WINDOW_SECONDS = <?= (int)EDIT_MESSAGE_WINDOW_SECONDS ?>;
</script>
<script src="script.js?v=<?= $scriptVer ?>"></script>
<?php include __DIR__ . '/_admin_bar.php'; ?>
</body>
</html>
