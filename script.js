// script.js - NexusChat Client Logic

'use strict';

// ─── CSRF ─────────────────────────────────────────────────────────────────────
// CSRF_TOKEN is injected by index.php as a <script> variable.
// postData() always attaches it so every mutating request is protected.
function postData(formData) {
    if (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) {
        formData.append('_csrf', CSRF_TOKEN);
    }
    return { method: 'POST', body: formData };
}

// ─── State ────────────────────────────────────────────────────────────────────
let ws = null;
let wsReady = false;
let wsEverConnected = false;  // did WS succeed at least once this session?
let wsGaveUp = false;         // permanently fell back to polling, stop trying WS
let wsClosing = false;        // we initiated the close (logout/navigate), ignore onclose
let wsRetryTimer = null;
let polling = false;
let pollingTimer = null;
let pollingInterval = 0;
let lastMessageTs = null;
let typingTimer = null;
let typingUsers = new Set();
let avatarCache = {};
let clearTimestamp = null;
const CLEAR_STORAGE_KEY = (typeof ROOM_ID !== 'undefined' && typeof ME !== 'undefined')
    ? `clear_${ROOM_ID}_${ME.id}`
    : null;
// Load clearTimestamp and normalize to MySQL DATETIME format
// (old sessions may have stored ISO format with 'Z' suffix which MySQL rejects)
(function() {
    if (!CLEAR_STORAGE_KEY) return;
    const stored = localStorage.getItem(CLEAR_STORAGE_KEY);
    if (!stored) { clearTimestamp = null; return; }
    // If it looks like ISO format (contains 'T' or 'Z'), convert to MySQL format
    if (stored.includes('T') || stored.includes('Z')) {
        const d = new Date(stored);
        clearTimestamp = d.getFullYear() + '-' +
            String(d.getMonth()+1).padStart(2,'0') + '-' +
            String(d.getDate()).padStart(2,'0') + ' ' +
            String(d.getHours()).padStart(2,'0') + ':' +
            String(d.getMinutes()).padStart(2,'0') + ':' +
            String(d.getSeconds()).padStart(2,'0');
        localStorage.setItem(CLEAR_STORAGE_KEY, clearTimestamp);
    } else {
        clearTimestamp = stored;
    }
})();
let messageMap = {};

const AVATAR_SYNC_KEY = 'nexuschat_avatar_sync';
let avatarSyncChannel = null;
if (typeof BroadcastChannel !== 'undefined') {
    try { avatarSyncChannel = new BroadcastChannel('nexuschat-avatar-sync'); } catch(e) {}
}

let roomAvatarSyncTimer = null;

function _publishAvatarSync(payload) {
    const data = {
        username: payload && payload.username ? payload.username : '',
        avatar_color: payload && payload.avatar_color ? payload.avatar_color : '',
        avatar_url: payload && Object.prototype.hasOwnProperty.call(payload, 'avatar_url') ? payload.avatar_url : null,
        ts: Date.now(),
    };
    try {
        localStorage.setItem(AVATAR_SYNC_KEY, JSON.stringify(data));
    } catch(e) {}
    if (avatarSyncChannel) {
        try { avatarSyncChannel.postMessage(data); } catch(e) {}
    }
    _applyAvatarUpdate(data.username, data.avatar_color, data.avatar_url);
}

function _applyIncomingAvatarSync(data) {
    if (!data || !data.username) return;
    _applyAvatarUpdate(data.username, data.avatar_color, data.avatar_url);
}

if (typeof window !== 'undefined') {
    window.addEventListener('storage', e => {
        if (e.key !== AVATAR_SYNC_KEY || !e.newValue) return;
        try { _applyIncomingAvatarSync(JSON.parse(e.newValue)); } catch(e) {}
    });
    if (avatarSyncChannel) {
        avatarSyncChannel.onmessage = e => _applyIncomingAvatarSync(e.data);
    }
}

async function syncRoomAvatars() {
    if (typeof ROOM_ID === 'undefined') return;
    try {
        const res = await fetch(`ajax_server.php?action=room_members&room_id=${ROOM_ID}`);
        const data = await res.json();
        if (!data.success || !Array.isArray(data.members)) return;
        data.members.forEach(member => {
            _applyAvatarUpdate(member.username, member.avatar_color || '#6c63ff', member.avatar_url, { skipMembersRefresh: true });
        });
    } catch (e) {}
}

// ─── File attachment state ────────────────────────────────────────────────────
let pendingFile   = null;   // File object staged for sending
let pendingFileId = null;   // DB id returned after upload completes
let uploadXhr     = null;   // Active XHR so we can abort on cancel

// ─── Voice recording state ────────────────────────────────────────────────────
let mediaRecorder    = null;
let audioChunks      = [];
let voiceTimerHandle = null;
let voiceSeconds     = 0;
let analyserNode     = null;
let audioCtx         = null;
let micStream        = null;
let levelRafHandle   = null;

const EMOJIS = ['👍','❤️','😂','😮','😢'];
const DELETE_EVERYONE_WINDOW_SECONDS = Number(typeof DELETE_FOR_EVERYONE_WINDOW_SECONDS !== 'undefined'
    ? DELETE_FOR_EVERYONE_WINDOW_SECONDS
    : 120);
const ROOM_ADMIN_DELETE_EVERYONE_WINDOW_SECONDS = Number(typeof ROOM_ADMIN_DELETE_FOR_EVERYONE_WINDOW_SECONDS !== 'undefined'
    ? ROOM_ADMIN_DELETE_FOR_EVERYONE_WINDOW_SECONDS
    : 86400);
const EDIT_WINDOW_SECONDS = Number(typeof EDIT_MESSAGE_WINDOW_SECONDS !== 'undefined'
    ? EDIT_MESSAGE_WINDOW_SECONDS
    : 60);

function isRealMessageId(id) {
    return /^\d+$/.test(String(id || ''));
}

function canDeleteForEveryone(msg) {
    if (!msg) return false;
    if (!isRealMessageId(msg.id)) return false;
    if (Number(msg.is_deleted || 0) === 1) return false;
    const isSender = msg.username === ME.username;
    const isRoomAdmin = (typeof IS_CREATOR !== 'undefined' && !!IS_CREATOR);
    if (!isSender && !isRoomAdmin) return false;
    const sentAt = new Date(msg.timestamp || Date.now()).getTime();
    if (!Number.isFinite(sentAt)) return false;
    const allowedWindowSeconds = isRoomAdmin
        ? ROOM_ADMIN_DELETE_EVERYONE_WINDOW_SECONDS
        : DELETE_EVERYONE_WINDOW_SECONDS;
    return (Date.now() - sentAt) <= (allowedWindowSeconds * 1000);
}

function canEditMessage(msg) {
    if (!msg || msg.username !== ME.username) return false;
    if (!isRealMessageId(msg.id)) return false;
    if (Number(msg.is_deleted || 0) === 1) return false;
    const sentAt = new Date(msg.timestamp || Date.now()).getTime();
    if (!Number.isFinite(sentAt)) return false;
    return (Date.now() - sentAt) <= (EDIT_WINDOW_SECONDS * 1000);
}

// ─── WebSocket ────────────────────────────────────────────────────────────────
function connectWS() {
    if (wsGaveUp || wsClosing) return;
    if (wsRetryTimer) { clearTimeout(wsRetryTimer); wsRetryTimer = null; }

    // Always start polling immediately — chat works from the first second
    // regardless of whether WS succeeds. Status stays green the whole time.
    startPolling(2000);
    setStatus('● Live', '#43e97b');

    let openTimer = null;

    try {
        ws = new WebSocket(WS_URL);
    } catch (e) {
        // Bad URL — stay on polling permanently
        wsGaveUp = true;
        return;
    }

    // If WS doesn't open within 2s, give up and stay on polling
    openTimer = setTimeout(() => {
        if (!wsReady) {
            wsClosing = true;
            try { ws.close(); } catch(e) {}
            wsClosing = false;
            wsGaveUp = true;
            // Already polling and showing Live — nothing else to do
        }
    }, 2000);

    ws.onopen = () => {
        clearTimeout(openTimer);
        wsReady = true;
        wsEverConnected = true;
        setStatus('● Live', '#43e97b');
        ws.send(JSON.stringify({ type: 'auth', username: ME.username }));
        ws.send(JSON.stringify({ type: 'join_room', room_id: ROOM_ID }));
        startPolling(5000); // slow poll as safety net — WS handles instant delivery
        startPing();
        _renderConnBar();   // update "· WebSocket ✓" label
    };

    ws.onclose = () => {
        clearTimeout(openTimer);
        wsReady = false;
        stopPing();

        if (wsClosing || wsGaveUp) return;

        if (!wsEverConnected) {
            // Never connected — server not running, stay on polling silently
            wsGaveUp = true;
            startPolling();
            setStatus('● Live', '#43e97b');
            return;
        }

        // Was connected but dropped — speed polling back to 2s
        startPolling(2000);
        setStatus('● Live', '#43e97b');
        wsRetryTimer = setTimeout(() => {
            if (!wsGaveUp && !wsClosing) {
                wsEverConnected = false;
                connectWS();
            }
        }, 3000);
    };

    ws.onerror = () => { /* onclose always fires after onerror */ };

    ws.onmessage = ({ data }) => {
        try { handleWsMessage(JSON.parse(data)); } catch(e) {}
    };
}

function fallbackToPolling() {
    wsGaveUp = true;
    ws = null;
    setStatus('● Live', '#43e97b');
    startPolling(2000);
}

function closeWSGracefully() {
    wsClosing = true;
    wsGaveUp = true;
    clearTimeout(wsRetryTimer);
    stopPing();
    if (ws) { try { ws.close(); } catch(e) {} }
}

// ─── Ping (keeps WS alive) ────────────────────────────────────────────────────
let pingTimer = null;
function startPing() {
    stopPing();
    pingTimer = setInterval(() => {
        if (wsReady && ws && ws.readyState === WebSocket.OPEN)
            ws.send(JSON.stringify({ type: 'ping' }));
    }, 25000);
}
function stopPing() {
    clearInterval(pingTimer);
    pingTimer = null;
}

function handleWsMessage(msg) {
    switch(msg.type) {
        case 'auth_ok':
            break;
        case 'joined':
            break;
        case 'message':
            if (msg.room_id == ROOM_ID && msg.username !== ME.username) {
                const wsMsg = {
                    id: msg.id || ('ws_' + Date.now()),
                    username: msg.username,
                    message: msg.message,
                    timestamp: msg.ts,
                    avatar_color: msg.avatar_color || '#6c63ff',
                    avatar_url: msg.avatar_url || null,
                    reactions: {},
                    file_id:   msg.file_id   || null,
                    file_name: msg.file_name || null,
                    file_mime: msg.file_mime || null,
                    file_size: msg.file_size || null,
                };
                appendMessage(wsMsg);
                // Keep lastMessageTs in sync so polling doesn't re-fetch this message
                if (msg.ts && msg.ts > (lastMessageTs || '')) lastMessageTs = msg.ts;
                // Mark the message as delivered so the sender's tick updates
                if (msg.id && !String(msg.id).startsWith('ws_')) markDelivered([msg.id]);
            }
            break;
        case 'system':
            if (msg.room_id == ROOM_ID) appendSystem(msg.message);
            break;
        case 'reaction':
            if (msg.room_id == ROOM_ID) updateReactions(msg.message_id, msg.reactions);
            break;
        case 'typing':
            if (msg.room_id == ROOM_ID && msg.username !== ME.username) showTyping(msg.username);
            break;
        case 'room_updated':
            if (msg.room_id == ROOM_ID) {
                appendSystem('Room settings changed. Refreshing…');
                setTimeout(() => location.reload(), 1500);
            }
            break;
        case 'avatar_updated':
            if (msg.room_id == ROOM_ID) {
                _applyAvatarUpdate(msg.username, msg.avatar_color, msg.avatar_url);
            }
            break;
        case 'message_deleted':
            if (msg.room_id == ROOM_ID && msg.scope === 'everyone') {
                applyDeletedState(msg.message_id);
            }
            break;
        case 'message_edited':
            if (msg.room_id == ROOM_ID) {
                applyEditedState(msg.message_id, msg.message, msg.edited_at || null);
            }
            break;

        case 'pong':
            break;
    }
}

function setStatus(text, color) {
    const el = document.getElementById('ws-status');
    if (el) { el.textContent = text; el.style.color = color; }
}

// ─── HTTP Polling fallback ────────────────────────────────────────────────────
function startPolling(interval) {
    interval = interval || 2000;
    // If already polling at same or faster rate, skip
    if (polling && pollingInterval <= interval) return;
    stopPolling();
    polling = true;
    pollingInterval = interval;
    pollingTimer = setInterval(fetchMessages, interval);
}
function stopPolling() {
    if (!polling) return;
    polling = false;
    pollingInterval = 0;
    clearInterval(pollingTimer);
    pollingTimer = null;
}

// ─── Messages ─────────────────────────────────────────────────────────────────
// ─── History loading state ────────────────────────────────────────────────────
let oldestMessageId  = null;   // id of the oldest message currently in the DOM
let hasOlderMessages = false;  // whether the server has pages before oldestMessageId
let loadingOlder     = false;  // guard against concurrent fetches

async function fetchMessages(initial = false) {
    const params = new URLSearchParams({
        action:  'get_messages',
        room_id: ROOM_ID,
    });
    if (initial) params.append('anchor_unread', '1');
    if (!initial && lastMessageTs) params.append('since', lastMessageTs);
    if (clearTimestamp) params.append('clear_ts', clearTimestamp);

    try {
        const res  = await fetch(`ajax_server.php?${params}`);
        const data = await res.json();
        if (!data.success) return;

        const loading = document.getElementById('msgs-loading');
        if (loading) { loading.style.opacity = '0'; setTimeout(() => loading.remove(), 200); }

        const wasAtBottom = isAtBottom();

        data.messages.forEach(m => {
            if (!messageMap[m.id]) {
                appendMessage(m);
            } else if (Number(m.is_deleted || 0) === 1) {
                applyDeletedState(m.id);
            } else if (Number(m.is_edited || 0) === 1) {
                applyEditedState(m.id, m.message, m.edited_at || null);
            }
            if (m.timestamp > (lastMessageTs || '')) lastMessageTs = m.timestamp;
        });

        // Track oldest message for "load older" cursor
        if (initial && data.messages && data.messages.length) {
            oldestMessageId  = data.oldest_id  ?? data.messages[0]?.id ?? null;
            hasOlderMessages = data.has_more   ?? false;
            _updateLoadMoreButton();
        }

        if (initial) {
            const firstUnreadId = data.first_unread_id ? String(data.first_unread_id) : null;
            const firstUnreadEl = firstUnreadId ? messageMap[firstUnreadId] : null;
            if (firstUnreadEl) {
                const area = document.getElementById('msgs-area');
                area.scrollTop = Math.max(0, firstUnreadEl.offsetTop - 14);
                firstUnreadEl.classList.add('unread-focus');
                setTimeout(() => firstUnreadEl.classList.remove('unread-focus'), 1700);
            } else {
                scrollToBottom();
            }
        } else if (wasAtBottom) {
            scrollToBottom();
        }
        markVisibleAsRead();
        fetchReactions();
        refreshMemberCount();
    } catch(e) {}
}

// ── Load older messages (called when user scrolls to the top) ─────────────────
async function loadOlderMessages() {
    if (loadingOlder || !hasOlderMessages || !oldestMessageId) return;
    loadingOlder = true;

    const btn = document.getElementById('load-more-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Loading…'; }

    const area         = document.getElementById('msgs-area');
    const prevScrollH  = area.scrollHeight;  // snapshot before prepend

    try {
        const params = new URLSearchParams({
            action:    'get_messages',
            room_id:   ROOM_ID,
            before_id: oldestMessageId,
        });
        if (clearTimestamp) params.append('clear_ts', clearTimestamp);

        const res  = await fetch(`ajax_server.php?${params}`);
        const data = await res.json();
        if (!data.success) { loadingOlder = false; return; }

        if (!data.messages || !data.messages.length) {
            // No more messages at all
            hasOlderMessages = false;
            _updateLoadMoreButton();
            loadingOlder = false;
            return;
        }

        // Prepend messages oldest-first above the current ones
        const container = document.getElementById('msgs-area');
        const anchor    = document.getElementById('load-more-bar');  // sentinel at top

        data.messages.forEach(m => {
            if (messageMap[m.id]) return;   // already rendered (shouldn't happen)
            const el = _buildMessageElement(m);
            if (!el) return;
            // Insert before the anchor (load-more bar) so new rows appear above existing ones
            container.insertBefore(el, anchor ? anchor.nextSibling : container.firstChild);
            messageMap[m.id] = el;
            if (readObserver) readObserver.observe(el);
        });

        // Update oldest cursor
        oldestMessageId  = data.oldest_id  ?? data.messages[0]?.id ?? oldestMessageId;
        hasOlderMessages = data.has_more   ?? false;
        _updateLoadMoreButton();

        // Preserve scroll position — user should stay on the same message
        area.scrollTop = area.scrollHeight - prevScrollH;

    } catch(e) {}
    loadingOlder = false;
}

// ── Load-more bar at the top of msgs-area ─────────────────────────────────────
function _updateLoadMoreButton() {
    let bar = document.getElementById('load-more-bar');
    const area = document.getElementById('msgs-area');
    if (!area) return;

    if (!hasOlderMessages) {
        // No more history — show a "beginning of history" label, remove button
        if (bar) bar.remove();
        if (!document.getElementById('history-start-label')) {
            const lbl = document.createElement('div');
            lbl.id        = 'history-start-label';
            lbl.className = 'history-start-label';
            lbl.textContent = '— Beginning of conversation —';
            area.insertBefore(lbl, area.firstChild);
        }
        return;
    }

    // Has more — show the load-more bar
    if (!bar) {
        bar = document.createElement('div');
        bar.id        = 'load-more-bar';
        bar.className = 'load-more-bar';
        bar.innerHTML = '<button id="load-more-btn" class="load-more-btn" onclick="loadOlderMessages()">⬆ Load older messages</button>';
        area.insertBefore(bar, area.firstChild);
    } else {
        // Reset button state
        const btn = document.getElementById('load-more-btn');
        if (btn) { btn.disabled = false; btn.textContent = '⬆ Load older messages'; }
    }
}

// ── Scroll-to-top detection — trigger loadOlderMessages automatically ──────────
function _initScrollSentinel() {
    const area = document.getElementById('msgs-area');
    if (!area) return;
    area.addEventListener('scroll', () => {
        // When user scrolls within 120 px of the top, auto-load older messages
        if (area.scrollTop < 120 && hasOlderMessages && !loadingOlder) {
            loadOlderMessages();
        }
    }, { passive: true });
}

// ── Build a message DOM element without appending it (used by prepend) ─────────
function closeAllMessageMenus() {
    document.querySelectorAll('.msg-menu.open').forEach(menu => menu.classList.remove('open'));
    document.querySelectorAll('.msg-row.menu-open').forEach(row => row.classList.remove('menu-open'));
}

function closeAllReactionBars() {
    document.querySelectorAll('.rxn-bar.show').forEach(bar => bar.classList.remove('show'));
    document.querySelectorAll('.msg-row.rxn-open').forEach(row => row.classList.remove('rxn-open'));
}

function positionReactionBar(row) {
    const rbar = row.querySelector('.rxn-bar');
    const bubble = row.querySelector('.msg-bubble');
    if (!rbar || !bubble) return;

    const rect = bubble.getBoundingClientRect();
    const spaceAbove = rect.top;
    const spaceBelow = window.innerHeight - rect.bottom;
    rbar.classList.remove('open-up', 'open-down');
    if (spaceAbove > spaceBelow && spaceAbove > 72) {
        rbar.classList.add('open-up');
    } else {
        rbar.classList.add('open-down');
    }
}

function showReactionBar(row) {
    if (!row.classList.contains('rxn-open')) closeAllReactionBars();
    closeAllMessageMenus();
    positionReactionBar(row);
    row.classList.add('rxn-open');
    const rbar = row.querySelector('.rxn-bar');
    if (rbar) rbar.classList.add('show');
}

function hideReactionBar(row) {
    const rbar = row.querySelector('.rxn-bar');
    if (rbar) rbar.classList.remove('show');
    row.classList.remove('rxn-open');
}

function positionMessageMenu(row) {
    const menu = row.querySelector('.msg-menu');
    const bubble = row.querySelector('.msg-bubble');
    if (!menu || !bubble) return;

    const rect = bubble.getBoundingClientRect();
    const spaceAbove = rect.top;
    const spaceBelow = window.innerHeight - rect.bottom;
    menu.classList.remove('open-up', 'open-down');
    if (spaceAbove > spaceBelow && spaceAbove > 96) {
        menu.classList.add('open-up');
    } else {
        menu.classList.add('open-down');
    }
}

let deleteDialogState = {
    msgId: null,
    allowEveryone: false,
};
let editingMessageId = null;

function ensureDeleteDialog() {
    let backdrop = document.getElementById('wa-delete-backdrop');
    if (backdrop) return backdrop;

    backdrop = document.createElement('div');
    backdrop.id = 'wa-delete-backdrop';
    backdrop.className = 'wa-delete-backdrop';
    backdrop.innerHTML = `
        <div class="wa-delete-dialog" role="dialog" aria-modal="true" aria-labelledby="wa-delete-title">
            <div class="wa-delete-title" id="wa-delete-title">Delete message?</div>
            <button class="wa-delete-btn danger" id="wa-delete-everyone">Delete for everyone</button>
            <button class="wa-delete-btn" id="wa-delete-me">Delete for me</button>
            <button class="wa-delete-btn cancel" id="wa-delete-cancel">Cancel</button>
        </div>
    `;
    document.body.appendChild(backdrop);

    backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) closeDeleteDialog();
    });

    const cancelBtn = backdrop.querySelector('#wa-delete-cancel');
    const meBtn = backdrop.querySelector('#wa-delete-me');
    const everyoneBtn = backdrop.querySelector('#wa-delete-everyone');

    cancelBtn.addEventListener('click', closeDeleteDialog);
    meBtn.addEventListener('click', async () => {
        const id = deleteDialogState.msgId;
        closeDeleteDialog();
        if (id !== null) await deleteMessage(id, 'me');
    });
    everyoneBtn.addEventListener('click', async () => {
        const id = deleteDialogState.msgId;
        closeDeleteDialog();
        if (id !== null) await deleteMessage(id, 'everyone');
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeDeleteDialog();
    });

    return backdrop;
}

function openDeleteDialog(msgId, allowEveryone) {
    closeAllReactionBars();
    const backdrop = ensureDeleteDialog();
    const everyoneBtn = backdrop.querySelector('#wa-delete-everyone');
    deleteDialogState = { msgId, allowEveryone: !!allowEveryone };

    everyoneBtn.style.display = allowEveryone ? 'block' : 'none';
    backdrop.classList.add('open');
}

function closeDeleteDialog() {
    const backdrop = document.getElementById('wa-delete-backdrop');
    if (!backdrop) return;
    backdrop.classList.remove('open');
    deleteDialogState = { msgId: null, allowEveryone: false };
}

document.addEventListener('click', (e) => {
    if (!e.target.closest('.msg-menu-wrap')) closeAllMessageMenus();
    if (!e.target.closest('.msg-bubble') && !e.target.closest('.rxn-bar')) closeAllReactionBars();
});

function _messageActionsHtml(msg, isMine) {
    const idStr = String(msg.id || '');
    if (!isRealMessageId(idStr)) return '';
    const canEdit = isMine && canEditMessage(msg);
    return `
        <div class="msg-menu-wrap">
            <button class="msg-menu-btn" data-msg-id="${idStr}" type="button" title="Message options">▾</button>
            <div class="msg-menu">
                ${canEdit ? `<button class="msg-menu-item" data-msg-id="${idStr}" data-action="edit">Edit message</button>` : ''}
                <button class="msg-menu-item" data-msg-id="${idStr}" data-action="delete">Delete message</button>
            </div>
        </div>
    `;
}

function _menuInnerHtml(msgId, allowEdit) {
    return `
        ${allowEdit ? `<button class="msg-menu-item" data-msg-id="${msgId}" data-action="edit">Edit message</button>` : ''}
        <button class="msg-menu-item" data-msg-id="${msgId}" data-action="delete">Delete message</button>
    `;
}

function ensureEditBanner() {
    let bar = document.getElementById('edit-msg-bar');
    if (bar) return bar;

    const footer = document.querySelector('.chat-footer');
    if (!footer) return null;

    bar = document.createElement('div');
    bar.id = 'edit-msg-bar';
    bar.className = 'edit-msg-bar';
    bar.style.display = 'none';
    bar.innerHTML = `
        <div class="edit-msg-meta">
            <span class="edit-msg-title">Editing message</span>
            <span class="edit-msg-preview" id="edit-msg-preview"></span>
        </div>
        <button class="edit-msg-cancel" id="edit-msg-cancel" title="Cancel editing">✕</button>
    `;
    footer.insertBefore(bar, footer.firstChild);

    const cancelBtn = bar.querySelector('#edit-msg-cancel');
    cancelBtn.addEventListener('click', cancelEditMessage);
    return bar;
}

function startEditMessage(msgId) {
    const key = String(msgId);
    const row = messageMap[key] || document.querySelector(`.msg-row[data-msg-id="${key}"]`);
    if (!row) return;

    const model = {
        id: key,
        username: row.dataset.author,
        timestamp: row.dataset.ts,
        is_deleted: Number(row.dataset.deleted || 0),
    };
    if (!canEditMessage(model)) {
        alert('You can edit a message only within 1 minute of sending.');
        return;
    }

    const textEl = row.querySelector('.msg-text');
    if (!textEl || textEl.classList.contains('msg-text-deleted')) return;
    const currentText = (textEl.textContent || '').trim();
    if (!currentText) return;

    const input = document.getElementById('msg-input');
    if (!input) return;

    if (pendingFile || pendingFileId) cancelAttachment();

    editingMessageId = key;
    input.value = currentText;
    autoResize(input);
    toggleMicSend();
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);

    const bar = ensureEditBanner();
    if (!bar) return;
    const preview = bar.querySelector('#edit-msg-preview');
    if (preview) preview.textContent = currentText.length > 48 ? (currentText.slice(0, 48) + '...') : currentText;
    bar.style.display = 'flex';
}

function cancelEditMessage() {
    editingMessageId = null;
    const bar = document.getElementById('edit-msg-bar');
    if (bar) bar.style.display = 'none';
}

function _wireMessageInteractions(el, msg) {
    const bubble = el.querySelector('.msg-bubble');
    const rbar   = el.querySelector('.rxn-bar');

    if (bubble && rbar) {
        let hideTimer = null;
        const showBar = () => {
            clearTimeout(hideTimer);
            showReactionBar(el);
        };
        const hideBar = () => { hideTimer = setTimeout(() => { hideReactionBar(el); }, 200); };
        bubble.addEventListener('mouseenter', showBar);
        bubble.addEventListener('mouseleave', hideBar);
        rbar.addEventListener('mouseenter', showBar);
        rbar.addEventListener('mouseleave', hideBar);
        bubble.addEventListener('click', (e) => {
            if (window.innerWidth > 600 && navigator.maxTouchPoints <= 1) return;
            if (e.target.closest('.msg-menu-wrap')) return;
            e.preventDefault();
            e.stopPropagation();
            if (rbar.classList.contains('show')) {
                hideReactionBar(el);
            } else {
                showBar();
            }
        });
        const handleEmojiPress = function(e) {
            const btn = e.target.closest('.emoji-btn');
            if (!btn) return;
            if (e.type === 'pointerdown') {
                if (e.button !== undefined && e.button !== 0) return;
            }
            e.preventDefault();
            e.stopPropagation();
            const msgId = btn.dataset.msg;
            const emoji = btn.dataset.emoji;
            react(msgId, emoji);
        };
        if ('PointerEvent' in window) {
            rbar.addEventListener('pointerdown', handleEmojiPress);
        } else {
            rbar.addEventListener('click', handleEmojiPress);
        }
    }

    const menuWrap = el.querySelector('.msg-menu-wrap');
    const menuBtn  = el.querySelector('.msg-menu-btn');
    const menu     = el.querySelector('.msg-menu');
    if (!menuWrap || !menuBtn || !menu) return;

    menuBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const wasOpen = menu.classList.contains('open');
        closeAllMessageMenus();
        closeAllReactionBars();

        const rowMessage = {
            id: el.dataset.msgId,
            username: el.dataset.author,
            timestamp: el.dataset.ts,
            is_deleted: Number(el.dataset.deleted || 0),
        };
        menu.dataset.allowEveryone = canDeleteForEveryone(rowMessage) ? '1' : '0';
        const allowEdit = canEditMessage(rowMessage);
        menu.dataset.allowEdit = allowEdit ? '1' : '0';
        menu.innerHTML = _menuInnerHtml(el.dataset.msgId, allowEdit);

        if (!wasOpen) {
            el.classList.add('menu-open');
            menu.classList.add('open');
            requestAnimationFrame(() => positionMessageMenu(el));
        }
    });

    menu.addEventListener('click', async (e) => {
        const item = e.target.closest('.msg-menu-item');
        if (!item) return;
        e.stopPropagation();
        closeAllMessageMenus();
        const action = item.dataset.action || 'delete';
        if (action === 'edit') {
            closeAllReactionBars();
            startEditMessage(item.dataset.msgId || el.dataset.msgId);
            return;
        }
        const allowEveryone = menu.dataset.allowEveryone === '1';
        openDeleteDialog(item.dataset.msgId || el.dataset.msgId, allowEveryone);
    });
}

function _buildMessageElement(msg) {
    const isMine   = msg.username === ME.username;
    const isDeleted = Number(msg.is_deleted || 0) === 1;
    const initials = (msg.username || '?').slice(0, 2).toUpperCase();
    const color    = msg.avatar_color || avatarCache[msg.username]?.color || '#6c63ff';
    const avatarImg = msg.avatar_url
        ? 'uploads/avatars/' + msg.avatar_url.split('/').pop()
        : null;
    avatarCache[msg.username] = { color, img: avatarImg };

    const ts = new Date(msg.timestamp || Date.now()).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    const avatarHtml = avatarImg
        ? `<img src="${avatarImg}" class="avatar small avatar-img" alt="${escHtml(msg.username)}">`
        : `<div class="avatar small" style="background:${color}">${initials}</div>`;

    const attachHtml = isDeleted ? '' : buildAttachmentHtml(msg.file_id, msg.file_name, msg.file_mime, msg.file_size);
    const msgTextHtml = isDeleted
        ? '<div class="msg-text msg-text-deleted">This message was deleted</div>'
        : (msg.message ? `<div class="msg-text">${escHtml(msg.message)}</div>` : '');
    const actionsHtml = _messageActionsHtml(msg, isMine);
    const editedLabel = (!isDeleted && Number(msg.is_edited || 0) === 1) ? '<span class="msg-edited">edited</span>' : '';

    const el = document.createElement('div');
    el.className  = `msg-row ${isMine ? 'mine' : 'theirs'}`;
    el.dataset.msgId  = msg.id;
    el.dataset.author = msg.username;
    el.dataset.ts = msg.timestamp || new Date().toISOString();
    el.dataset.deleted = isDeleted ? '1' : '0';
    el.innerHTML = `
        ${!isMine ? avatarHtml : ''}
        <div class="msg-content">
            ${!isMine ? `<div class="msg-author">${escHtml(msg.username)}</div>` : ''}
            <div class="msg-bubble" data-id="${msg.id}">
                ${attachHtml}
                ${msgTextHtml}
                <div class="msg-meta">
                    <span class="msg-time">${ts}</span>
                    ${editedLabel}
                    ${actionsHtml}
                    ${isMine ? `<span class="msg-tick" id="tick-${msg.id}">✓</span>` : ''}
                </div>
                ${isDeleted ? '' : `<div class="rxn-bar" id="rbar-${msg.id}">
                    ${EMOJIS.map(e => `<button class="emoji-btn" data-msg="${msg.id}" data-emoji="${e}">${e}</button>`).join('')}
                </div>`}
            </div>
            <div class="rxn-row" id="reactions-${msg.id}"></div>
        </div>
        ${isMine ? avatarHtml : ''}
    `;

    _wireMessageInteractions(el, msg);

    if (msg.reactions && Object.keys(msg.reactions).length) {
        updateReactions(msg.id, msg.reactions, el);
    }

    return el;
}

// Refresh member count display in chat header
async function refreshMemberCount() {
    try {
        const res = await fetch(`ajax_server.php?action=room_members&room_id=${ROOM_ID}`);
        const data = await res.json();
        if (!data.success) return;
        const el = document.getElementById('members-count');
        if (el) el.textContent = `${data.members.length} member${data.members.length !== 1 ? 's' : ''}`;
    } catch(e) {}
}

// Fetch latest reactions for all currently visible messages and update UI
async function fetchReactions() {
    const ids = Object.keys(messageMap).filter(id =>
        !String(id).startsWith('temp_') && !String(id).startsWith('ws_')
    );
    if (!ids.length) return;

    try {
        const res = await fetch('ajax_server.php?action=get_reactions&room_id=' + ROOM_ID
            + '&message_ids=' + encodeURIComponent(JSON.stringify(ids)));
        const data = await res.json();
        if (!data.success) return;

        // data.reactions = { message_id: { emoji: [username, ...] } }
        Object.entries(data.reactions).forEach(([msgId, reactions]) => {
            updateReactions(msgId, reactions);
        });
    } catch(e) {}
}

// Check if the current user was removed from the room by the creator
async function checkMembership() {
    try {
        const res = await fetch(`ajax_server.php?action=check_membership&room_id=${ROOM_ID}`);
        const data = await res.json();
        if (!data.success || data.member) return;

        // User was removed — show banner then redirect
        showRemovedBanner();
    } catch(e) {}
}

let removedBannerShown = false;
function showRemovedBanner() {
    if (removedBannerShown) return;
    removedBannerShown = true;
    // Stop all polling immediately
    stopPolling();
    wsGaveUp = true;
    if (ws) { try { ws.close(); } catch(e) {} }

    // Overlay the chat with a clear message
    const overlay = document.createElement('div');
    overlay.style.cssText = `
        position:fixed; inset:0; z-index:9999;
        background:rgba(5,6,8,.92); backdrop-filter:blur(12px);
        display:flex; flex-direction:column;
        align-items:center; justify-content:center; gap:1.2rem;
        animation:fadein .3s ease;
    `;
    overlay.innerHTML = `
        <div style="
            width:56px; height:56px; border-radius:16px;
            background:rgba(247,112,106,.15); border:1px solid rgba(247,112,106,.25);
            display:flex; align-items:center; justify-content:center; font-size:26px;
        ">🚫</div>
        <div style="text-align:center">
            <div style="font-size:1.15rem;font-weight:700;color:#dde2f0;margin-bottom:.4rem">
                You've been removed
            </div>
            <div style="font-size:.88rem;color:#8892a4">
                The room creator has removed you from <strong style="color:#dde2f0">#${ROOM_NAME}</strong>
            </div>
        </div>
        <div style="display:flex;gap:.75rem;margin-top:.4rem">
            <button onclick="window.location='rooms.php'" style="
                background:linear-gradient(135deg,#00d4aa,#00b4d8);
                color:#020a08; border:none; border-radius:9px;
                padding:.65rem 1.4rem; font-size:.9rem; font-weight:700;
                cursor:pointer; font-family:inherit;
            ">Back to Rooms →</button>
        </div>
        <div style="font-size:.76rem;color:#3d4558;margin-top:-.3rem">
            Redirecting in <span id="redirect-count">5</span>s…
        </div>
    `;
    document.body.appendChild(overlay);

    // Countdown and auto redirect
    let count = 5;
    const timer = setInterval(() => {
        count--;
        const el = document.getElementById('redirect-count');
        if (el) el.textContent = count;
        if (count <= 0) {
            clearInterval(timer);
            window.location = 'rooms.php';
        }
    }, 1000);
}

function appendMessage(msg) {
    if (messageMap[msg.id]) return;

    const area = document.getElementById('msgs-area');
    const isMine = msg.username === ME.username;
    const isDeleted = Number(msg.is_deleted || 0) === 1;
    const initials = (msg.username || '?').slice(0, 2).toUpperCase();
    const color = msg.avatar_color || avatarCache[msg.username]?.color || '#6c63ff';

    // Resolve avatar image: custom upload only; otherwise show initials
    const avatarImg = msg.avatar_url
        ? 'uploads/avatars/' + msg.avatar_url.split('/').pop()
        : null;
    avatarCache[msg.username] = { color, img: avatarImg };

    const ts = new Date(msg.timestamp || Date.now()).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});

    const area2 = document.getElementById('msgs-area');
    const lastRow = area2 ? area2.querySelector('.msg-row:last-child') : null;
    const lastAuthor = lastRow ? lastRow.dataset.author : null;
    const isSameSender = lastAuthor === msg.username && !isMine ? false : (lastAuthor === msg.username);

    // Build avatar HTML: image if available, else colour circle with initials
    const avatarHtml = avatarImg
        ? `<img src="${avatarImg}" class="avatar small avatar-img" alt="${escHtml(msg.username)}">`
        : `<div class="avatar small" style="background:${color}">${initials}</div>`;

    const el = document.createElement('div');
    el.className = `msg-row ${isMine ? 'mine' : 'theirs'}${isSameSender ? ' grouped' : ''}`;
    el.dataset.msgId = msg.id;
    el.dataset.author = msg.username;
    el.dataset.ts = msg.timestamp || new Date().toISOString();
    el.dataset.deleted = isDeleted ? '1' : '0';
    const attachHtml = isDeleted ? '' : buildAttachmentHtml(msg.file_id, msg.file_name, msg.file_mime, msg.file_size);
    const msgTextHtml = isDeleted
        ? '<div class="msg-text msg-text-deleted">This message was deleted</div>'
        : (msg.message ? `<div class="msg-text">${escHtml(msg.message)}</div>` : '');
    const actionsHtml = _messageActionsHtml(msg, isMine);
    const editedLabel = (!isDeleted && Number(msg.is_edited || 0) === 1) ? '<span class="msg-edited">edited</span>' : '';
    el.innerHTML = `
        ${!isMine ? avatarHtml : ''}
        <div class="msg-content">
            ${!isMine ? `<div class="msg-author">${escHtml(msg.username)}</div>` : ''}
            <div class="msg-bubble" data-id="${msg.id}">
                ${attachHtml}
                ${msgTextHtml}
                <div class="msg-meta">
                    <span class="msg-time">${ts}</span>
                    ${editedLabel}
                    ${actionsHtml}
                    ${isMine ? `<span class="msg-tick" id="tick-${msg.id}">✓</span>` : ''}
                </div>
                ${isDeleted ? '' : `<div class="rxn-bar" id="rbar-${msg.id}">
                    ${EMOJIS.map(e => `<button class="emoji-btn" data-msg="${msg.id}" data-emoji="${e}">${e}</button>`).join('')}
                </div>`}
            </div>
            <div class="rxn-row" id="reactions-${msg.id}"></div>
        </div>
        ${isMine ? avatarHtml : ''}
    `;

    _wireMessageInteractions(el, msg);

    if (msg.reactions && Object.keys(msg.reactions).length) {
        updateReactions(msg.id, msg.reactions, el);
    }

    area.appendChild(el);
    messageMap[msg.id] = el;

    // Intersection observer for read receipts
    if (readObserver) readObserver.observe(el);
}

function appendSystem(text) {
    const area = document.getElementById('msgs-area');
    const el = document.createElement('div');
    el.className = 'msg-sys';
    el.textContent = text;
    area.appendChild(el);
    scrollToBottom();
}

function removeMessageFromView(msgId) {
    const key = String(msgId);
    const row = messageMap[key] || document.querySelector(`.msg-row[data-msg-id="${key}"]`);
    if (!row) return;
    if (editingMessageId === key) cancelEditMessage();
    if (readObserver) readObserver.unobserve(row);
    row.remove();
    delete messageMap[key];
}

function applyEditedState(msgId, nextText, editedAt) {
    const key = String(msgId);
    const row = messageMap[key] || document.querySelector(`.msg-row[data-msg-id="${key}"]`);
    if (!row) return;
    if (Number(row.dataset.deleted || 0) === 1) return;

    const bubble = row.querySelector('.msg-bubble');
    if (!bubble) return;

    let textEl = bubble.querySelector('.msg-text');
    if (!textEl) {
        textEl = document.createElement('div');
        textEl.className = 'msg-text';
        const meta = bubble.querySelector('.msg-meta');
        if (meta) bubble.insertBefore(textEl, meta);
        else bubble.appendChild(textEl);
    }
    textEl.classList.remove('msg-text-deleted');
    textEl.textContent = String(nextText || '');

    const meta = bubble.querySelector('.msg-meta');
    if (meta && !meta.querySelector('.msg-edited')) {
        const timeEl = meta.querySelector('.msg-time');
        const label = document.createElement('span');
        label.className = 'msg-edited';
        label.textContent = 'edited';
        if (timeEl && timeEl.nextSibling) meta.insertBefore(label, timeEl.nextSibling);
        else meta.appendChild(label);
    }

    if (editedAt) row.dataset.editedAt = editedAt;

    const menu = row.querySelector('.msg-menu');
    if (menu) {
        const rowMessage = {
            id: row.dataset.msgId,
            username: row.dataset.author,
            timestamp: row.dataset.ts,
            is_deleted: Number(row.dataset.deleted || 0),
        };
        const allowEdit = canEditMessage(rowMessage);
        menu.dataset.allowEdit = allowEdit ? '1' : '0';
        menu.innerHTML = _menuInnerHtml(row.dataset.msgId, allowEdit);
    }

    if (editingMessageId === key) cancelEditMessage();
}

function applyDeletedState(msgId) {
    const key = String(msgId);
    const row = messageMap[key] || document.querySelector(`.msg-row[data-msg-id="${key}"]`);
    if (!row) return;
    row.dataset.deleted = '1';
    if (editingMessageId === key) cancelEditMessage();

    const bubble = row.querySelector('.msg-bubble');
    if (!bubble) return;

    bubble.querySelectorAll('.attach-wrap').forEach(el => el.remove());

    let textEl = bubble.querySelector('.msg-text');
    if (!textEl) {
        textEl = document.createElement('div');
        textEl.className = 'msg-text';
        const meta = bubble.querySelector('.msg-meta');
        if (meta) bubble.insertBefore(textEl, meta);
        else bubble.appendChild(textEl);
    }
    textEl.textContent = 'This message was deleted';
    textEl.classList.add('msg-text-deleted');

    const editedLabel = bubble.querySelector('.msg-edited');
    if (editedLabel) editedLabel.remove();

    const rbar = bubble.querySelector('.rxn-bar');
    if (rbar) rbar.remove();
    const rrow = row.querySelector('.rxn-row');
    if (rrow) rrow.innerHTML = '';

    const menu = row.querySelector('.msg-menu');
    if (menu) {
        menu.dataset.allowEdit = '0';
        menu.dataset.allowEveryone = '0';
        menu.innerHTML = _menuInnerHtml(key, false);
    }
}

async function deleteMessage(msgId, scope) {
    const key = String(msgId);
    if (!isRealMessageId(key)) return;

    const fd = new FormData();
    fd.append('action', 'delete_message');
    fd.append('message_id', key);
    fd.append('scope', scope);

    try {
        const res = await fetch('ajax_server.php', postData(fd));
        const data = await res.json();
        if (!data.success) {
            alert(data.error || 'Could not delete message');
            return;
        }

        if (scope === 'me') {
            removeMessageFromView(key);
            return;
        }

        applyDeletedState(key);
        if (wsReady && ws) {
            ws.send(JSON.stringify({
                type: 'message_deleted',
                message_id: Number(key),
                room_id: ROOM_ID,
                scope: 'everyone',
            }));
        }
    } catch (e) {
        alert('Network error while deleting message');
    }
}

function updateReactions(msgId, reactions, container) {
    const el = container
        ? container.querySelector(`#reactions-${msgId}`)
        : document.getElementById(`reactions-${msgId}`);
    if (!el) return;
    el.innerHTML = '';
    Object.entries(reactions || {}).forEach(([emoji, users]) => {
        if (!users.length) return;
        const btn = document.createElement('button');
        const isMine = users.includes(ME.username);
        btn.className = 'rxn-chip' + (isMine ? ' mine' : '');
        btn.innerHTML = `${emoji} <span>${users.length}</span>`;
        btn.title = users.join(', ');
        btn.onclick = () => {
            const isTouchDevice = window.innerWidth <= 900 || navigator.maxTouchPoints > 0;
            if (isTouchDevice) {
                alert(`${emoji} reactions:\n${users.join('\n')}`);
                return;
            }
            react(msgId, emoji);
        };
        el.appendChild(btn);
    });

    // Also update the emoji bar buttons — highlight which one this user picked
    // and visually dim the others so it's clear only one can be active
    const msgRow = el.closest('.msg-row') || el.parentElement.closest('.msg-row');
    if (!msgRow) return;
    const emojiBar = msgRow.querySelector('.rxn-bar');
    if (!emojiBar) return;
    // Find which emoji (if any) this user currently has on this message
    let myEmoji = null;
    Object.entries(reactions || {}).forEach(([emoji, users]) => {
        if (users.includes(ME.username)) myEmoji = emoji;
    });
    emojiBar.querySelectorAll('.emoji-btn').forEach(btn => {
        const e = btn.dataset.emoji;
        if (myEmoji && e === myEmoji) {
            btn.style.background = 'rgba(108,99,255,0.35)';
            btn.style.borderRadius = '8px';
            btn.title = 'Remove reaction';
        } else {
            btn.style.background = '';
            btn.title = '';
        }
    });
}

// ─── File Attachment ─────────────────────────────────────────────────────────
function handleFileSelect(input) {
    const file = input.files[0];
    if (!file) return;
    input.value = '';   // reset so same file can be re-selected after cancel

    const MAX = 10 * 1024 * 1024 * 1024;
    if (file.size > MAX) { alert('File too large (max 10 GB).'); return; }

    pendingFile   = file;
    pendingFileId = null;
    showFilePreview(file);
    uploadFile(file);
}

function showFilePreview(file) {
    const bar   = document.getElementById('file-preview-bar');
    const inner = document.getElementById('file-preview-inner');
    bar.style.display = 'flex';

    const isImg = file.type.startsWith('image/');
    if (isImg) {
        const reader = new FileReader();
        reader.onload = e => {
            inner.innerHTML = `
                <img src="${e.target.result}" class="fp-thumb" alt="preview">
                <div class="fp-info">
                    <div class="fp-name">${escHtml(file.name)}</div>
                    <div class="fp-size">${fmtSize(file.size)}</div>
                    <div class="fp-progress-wrap"><div class="fp-progress" id="fp-progress"></div></div>
                </div>`;
        };
        reader.readAsDataURL(file);
    } else {
        inner.innerHTML = `
            <div class="fp-icon">${fileIcon(file.type)}</div>
            <div class="fp-info">
                <div class="fp-name">${escHtml(file.name)}</div>
                <div class="fp-size">${fmtSize(file.size)}</div>
                <div class="fp-progress-wrap"><div class="fp-progress" id="fp-progress"></div></div>
            </div>`;
    }
}

function uploadFile(file) {
    const fd = new FormData();
    fd.append('action',  'upload_file');
    fd.append('room_id', ROOM_ID);
    fd.append('file',    file);
    if (typeof CSRF_TOKEN !== 'undefined') fd.append('_csrf', CSRF_TOKEN);

    uploadXhr = new XMLHttpRequest();
    uploadXhr.open('POST', 'ajax_server.php');

    uploadXhr.upload.onprogress = e => {
        if (!e.lengthComputable) return;
        const pct = Math.round(e.loaded / e.total * 100);
        const bar = document.getElementById('fp-progress');
        if (bar) bar.style.width = pct + '%';
    };

    uploadXhr.onload = () => {
        try {
            const data = JSON.parse(uploadXhr.responseText);
            if (data.success) {
                pendingFileId = data.file_id;
                const bar = document.getElementById('fp-progress');
                if (bar) { bar.style.width = '100%'; bar.style.background = 'var(--teal)'; }
                toggleMicSend();   // show Send button now that file is ready
            } else {
                alert('Upload failed: ' + (data.error || 'Unknown error'));
                cancelAttachment();
            }
        } catch(e) { alert('Upload error'); cancelAttachment(); }
    };

    uploadXhr.onerror = () => { alert('Upload failed: network error'); cancelAttachment(); };
    uploadXhr.send(fd);
}

function cancelAttachment() {
    if (uploadXhr) { uploadXhr.abort(); uploadXhr = null; }
    pendingFile   = null;
    pendingFileId = null;
    document.getElementById('file-preview-bar').style.display = 'none';
    document.getElementById('file-preview-inner').innerHTML   = '';
    toggleMicSend();
}

// ─── Mic / Send toggle ────────────────────────────────────────────────────────
// Shows mic button when input is empty (and no file staged), send button otherwise.
function toggleMicSend() {
    const hasText = document.getElementById('msg-input').value.trim().length > 0;
    const hasFile = !!pendingFileId;
    const micBtn  = document.getElementById('mic-btn');
    const sendBtn = document.getElementById('send-btn');
    if (!micBtn || !sendBtn) return;
    if (hasText || hasFile) {
        micBtn.style.display  = 'none';
        sendBtn.style.display = 'flex';
    } else {
        micBtn.style.display  = 'flex';
        sendBtn.style.display = 'none';
    }
}

// ─── Voice Recording ──────────────────────────────────────────────────────────
function _openVoiceFileFallback(message) {
    if (message) appendSystem(message);
    const input = document.getElementById('voice-file-input');
    if (!input) return;
    input.value = '';
    input.click();
}

function handleVoiceFileSelect(input) {
    const file = input.files && input.files[0];
    if (!file) return;
    input.value = '';

    const MAX = 10 * 1024 * 1024 * 1024;
    if (file.size > MAX) {
        alert('Voice file too large (max 10 GB).');
        return;
    }

    const isAudioByMime = (file.type || '').startsWith('audio/');
    const isAudioByExt  = /\.(m4a|mp3|wav|ogg|webm|aac|mp4)$/i.test(file.name || '');
    if (!isAudioByMime && !isAudioByExt) {
        alert('Please select a valid audio file.');
        return;
    }

    pendingFile   = file;
    pendingFileId = null;
    voiceSeconds  = 0;
    showVoiceUploadingPreview(file);
    uploadFile(file);
}

async function startVoice() {
    if (mediaRecorder && mediaRecorder.state === 'recording') return;

    // Check browser support
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        _openVoiceFileFallback('Direct microphone recording is unavailable in this browser/context. Use your device recorder and upload.');
        return;
    }
    if (typeof MediaRecorder === 'undefined') {
        _openVoiceFileFallback('Built-in recorder is not supported here. Use your device recorder and upload.');
        return;
    }

    try {
        micStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
    } catch (err) {
        if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
            alert('Microphone permission denied. You can allow microphone access, or select a recorded audio file instead.');
            _openVoiceFileFallback('Microphone blocked. Select a recorded audio file to send voice message.');
        } else if (err.name === 'NotFoundError' || err.name === 'OverconstrainedError') {
            _openVoiceFileFallback('No usable microphone found. Select a recorded audio file to send.');
        } else {
            _openVoiceFileFallback('Could not start live recording here. Select a recorded audio file to send.');
        }
        return;
    }

    // Pick the best supported MIME type
    const mimeType = [
        'audio/webm;codecs=opus',
        'audio/webm',
        'audio/ogg;codecs=opus',
        'audio/ogg',
        'audio/mp4;codecs=mp4a.40.2',
        'audio/mp4',
        'audio/aac',
    ]
        .find(m => MediaRecorder.isTypeSupported(m)) || '';

    audioChunks  = [];
    voiceSeconds = 0;

    mediaRecorder = new MediaRecorder(micStream, mimeType ? { mimeType } : {});
    mediaRecorder.ondataavailable = e => { if (e.data && e.data.size > 0) audioChunks.push(e.data); };
    mediaRecorder.onstop = _onRecordingStop;
    mediaRecorder.start(200);   // collect data every 200 ms

    // Show voice bar, hide input shell
    document.getElementById('voice-bar').style.display  = 'flex';
    document.getElementById('input-shell').style.display = 'none';

    // Start timer
    _updateVoiceTimer();
    voiceTimerHandle = setInterval(() => {
        voiceSeconds++;
        _updateVoiceTimer();
        // Auto-stop at 5 minutes
        if (voiceSeconds >= 300) stopAndSendVoice();
    }, 1000);

    // Live mic level meter via Web Audio API
    try {
        audioCtx     = new (window.AudioContext || window.webkitAudioContext)();
        analyserNode = audioCtx.createAnalyser();
        analyserNode.fftSize = 256;
        audioCtx.createMediaStreamSource(micStream).connect(analyserNode);
        _animateLevel();
    } catch(e) { /* level meter is cosmetic — fail silently */ }
}

function _updateVoiceTimer() {
    const m = Math.floor(voiceSeconds / 60);
    const s = String(voiceSeconds % 60).padStart(2, '0');
    const el = document.getElementById('voice-timer');
    if (el) el.textContent = `${m}:${s}`;
}

function _animateLevel() {
    if (!analyserNode) return;
    const data = new Uint8Array(analyserNode.frequencyBinCount);
    analyserNode.getByteFrequencyData(data);
    const avg = data.reduce((a, b) => a + b, 0) / data.length;
    const pct = Math.min(100, (avg / 128) * 100);
    const bar = document.getElementById('voice-level');
    if (bar) bar.style.width = pct + '%';
    levelRafHandle = requestAnimationFrame(_animateLevel);
}

function _stopAudioInfra() {
    clearInterval(voiceTimerHandle);
    voiceTimerHandle = null;
    cancelAnimationFrame(levelRafHandle);
    levelRafHandle = null;
    if (audioCtx) { try { audioCtx.close(); } catch(e) {} audioCtx = null; }
    analyserNode = null;
    if (micStream) { micStream.getTracks().forEach(t => t.stop()); micStream = null; }
    // Restore input shell
    document.getElementById('voice-bar').style.display   = 'none';
    document.getElementById('input-shell').style.display = 'flex';
}

function cancelVoice() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.onstop = null;   // suppress the send-on-stop handler
        mediaRecorder.stop();
    }
    mediaRecorder = null;
    audioChunks   = [];
    _stopAudioInfra();
}

function stopAndSendVoice() {
    if (!mediaRecorder || mediaRecorder.state === 'inactive') return;
    if (voiceSeconds < 1) {
        cancelVoice();
        appendSystem('Recording too short (minimum 1 second).');
        return;
    }
    mediaRecorder.stop();   // triggers onstop → _onRecordingStop
}

async function _onRecordingStop() {
    _stopAudioInfra();
    if (!audioChunks.length) { mediaRecorder = null; return; }

    const recMime  = mediaRecorder ? (mediaRecorder.mimeType || 'audio/webm') : 'audio/webm';
    const ext      = recMime.includes('ogg') ? 'ogg' : recMime.includes('mp4') ? 'mp4' : 'webm';
    const blob     = new Blob(audioChunks, { type: recMime });
    const fileName = `voice-${Date.now()}.${ext}`;
    const file     = new File([blob], fileName, { type: recMime });

    mediaRecorder = null;
    audioChunks   = [];

    // Reuse the existing file upload pipeline
    pendingFile   = file;
    pendingFileId = null;
    showVoiceUploadingPreview(file);
    uploadFile(file);
}

function showVoiceUploadingPreview(file) {
    const bar   = document.getElementById('file-preview-bar');
    const inner = document.getElementById('file-preview-inner');
    bar.style.display = 'flex';
    const dur = voiceSeconds > 0
        ? ` (${Math.floor(voiceSeconds / 60)}:${String(voiceSeconds % 60).padStart(2,'0')})`
        : '';
    inner.innerHTML = `
        <div class="fp-icon">🎤</div>
        <div class="fp-info">
            <div class="fp-name">Voice message${dur}</div>
            <div class="fp-size">${fmtSize(file.size)}</div>
            <div class="fp-progress-wrap"><div class="fp-progress" id="fp-progress"></div></div>
        </div>`;
    toggleMicSend();
}

function fmtSize(bytes) {
    if (bytes < 1024)        return bytes + ' B';
    if (bytes < 1024*1024)   return (bytes/1024).toFixed(1) + ' KB';
    return (bytes/1024/1024).toFixed(1) + ' MB';
}

function fileIcon(mime) {
    if (mime.startsWith('image/'))       return '🖼️';
    if (mime.startsWith('video/'))       return '🎬';
    if (mime.startsWith('audio/'))       return '🎵';
    if (mime === 'application/pdf')      return '📄';
    if (mime.includes('word'))           return '📝';
    if (mime.includes('excel') || mime.includes('spreadsheet')) return '📊';
    if (mime.includes('powerpoint') || mime.includes('presentation')) return '📽️';
    if (mime.includes('zip') || mime.includes('rar')) return '🗜️';
    return '📎';
}

// Build HTML for a file attachment inside a message bubble
// ─── JS-powered download (bypasses CSP blocking the <a download> attribute) ───
function downloadFile(fileId, fileName) {
    const a = document.createElement('a');
    a.href = `ajax_server.php?action=serve_file&file_id=${fileId}&download=1`;
    a.download = fileName || 'file';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

function buildAttachmentHtml(fileId, fileName, fileMime, fileSize) {
    if (!fileId) return '';
    const url      = `ajax_server.php?action=serve_file&file_id=${fileId}`;
    const safeName = escHtml(fileName || 'file');
    const rawName  = (fileName || 'file').replace(/'/g, "\\'");
    const safeSize = fileSize ? fmtSize(Number(fileSize)) : '';
    const dlBtn    = `onclick="downloadFile(${fileId},'${rawName}')"`;

    if (fileMime && fileMime.startsWith('image/')) {
        return `<div class="attach-wrap">
            <a href="${url}" target="_blank" rel="noopener">
                <img src="${url}" class="attach-img" alt="${safeName}" loading="lazy">
            </a>
            <div class="attach-footer">
                <span class="attach-name">${safeName}</span>
                <span class="attach-size">${safeSize}</span>
                <a href="#" class="attach-dl" ${dlBtn}>⬇ Download</a>
            </div>
        </div>`;
    }

    if (fileMime && fileMime.startsWith('video/')) {
        return `<div class="attach-wrap">
            <video controls class="attach-video" preload="metadata">
                <source src="${url}" type="${escHtml(fileMime)}">
                Your browser does not support video.
            </video>
            <div class="attach-footer">
                <span class="attach-name">${safeName}</span>
                <span class="attach-size">${safeSize}</span>
                <a href="#" class="attach-dl" ${dlBtn}>⬇ Download</a>
            </div>
        </div>`;
    }

    if (fileMime && fileMime.startsWith('audio/')) {
        const isVoice = (fileName || '').startsWith('voice-');
        if (isVoice) {
            return `<div class="attach-wrap attach-voice">
                <div class="voice-msg-icon">🎤</div>
                <audio controls class="attach-audio-voice" preload="metadata">
                    <source src="${url}" type="${escHtml(fileMime)}">
                </audio>
                <a href="#" class="attach-dl-sm" ${dlBtn} title="Download">⬇</a>
            </div>`;
        }
        return `<div class="attach-wrap">
            <audio controls class="attach-audio" preload="metadata">
                <source src="${url}" type="${escHtml(fileMime)}">
            </audio>
            <div class="attach-footer">
                <span class="attach-name">${safeName}</span>
                <span class="attach-size">${safeSize}</span>
                <a href="#" class="attach-dl" ${dlBtn}>⬇ Download</a>
            </div>
        </div>`;
    }

    // Generic file card
    const icon = fileIcon(fileMime || '');
    return `<div class="attach-wrap attach-file">
        <div class="attach-file-icon">${icon}</div>
        <div class="attach-file-info">
            <div class="attach-name">${safeName}</div>
            <div class="attach-size">${safeSize}</div>
        </div>
        <a href="#" class="attach-dl-btn" ${dlBtn} title="Download">⬇</a>
    </div>`;
}

// ─── Send Message ─────────────────────────────────────────────────────────────
async function sendMessage() {
    const input = document.getElementById('msg-input');
    const text  = input.value.trim();

    if (editingMessageId) {
        if (!text) return;
        const editId = String(editingMessageId);
        const fd = new FormData();
        fd.append('action', 'edit_message');
        fd.append('message_id', editId);
        fd.append('message', text);

        try {
            const res = await fetch('ajax_server.php', postData(fd));
            const data = await res.json();
            if (!data.success) {
                alert(data.error || 'Could not edit message');
                return;
            }

            applyEditedState(editId, data.message, data.edited_at || null);
            input.value = '';
            autoResize(input);
            cancelEditMessage();
            toggleMicSend();

            if (wsReady && ws) {
                ws.send(JSON.stringify({
                    type: 'message_edited',
                    message_id: Number(editId),
                    message: data.message,
                    edited_at: data.edited_at || null,
                    room_id: ROOM_ID,
                }));
            }
        } catch (e) {
            alert('Network error while editing message');
        }
        return;
    }

    // Must have text OR a fully uploaded file
    if (!text && !pendingFileId) return;
    if (pendingFile && !pendingFileId) {
        appendSystem('⏳ File is still uploading, please wait…');
        return;
    }

    const fileId   = pendingFileId;
    const fileData = pendingFile ? { name: pendingFile.name, mime: pendingFile.type, size: pendingFile.size } : null;

    input.value = '';
    autoResize(input);
    cancelAttachment();
    toggleMicSend();

    // Optimistic UI
    const tempId = 'temp_' + Date.now();
    const optimistic = {
        id: tempId, username: ME.username, message: text,
        timestamp: new Date().toISOString(), avatar_color: ME.avatarColor,
        avatar_url: ME.avatarUrl,
        reactions: {},
        file_id: fileId,
        file_name: fileData ? fileData.name : null,
        file_mime: fileData ? fileData.mime : null,
        file_size: fileData ? fileData.size : null,
    };
    appendMessage(optimistic);
    scrollToBottom();
    // Animate the sender's avatar with a teal glow
    _glowSenderAvatar(messageMap[tempId]);

    // Send via WS
    if (wsReady && ws) {
        const wsPayload = {
            type: 'message', message: text, room_id: ROOM_ID,
            avatar_color: ME.avatarColor,
            avatar_url: ME.avatarUrl,
            file_id: fileId,
        };
        // Include file metadata so the socket server can broadcast it to peers
        if (fileId && fileData) {
            wsPayload.file_name = fileData.name;
            wsPayload.file_mime = fileData.mime;
            wsPayload.file_size = fileData.size;
        }
        ws.send(JSON.stringify(wsPayload));
    }

    // Persist via HTTP
    try {
        const fd = new FormData();
        fd.append('action',  'send_message');
        fd.append('room_id', ROOM_ID);
        fd.append('message', text);
        if (fileId) fd.append('file_id', fileId);
        const res  = await fetch('ajax_server.php', postData(fd));
        const data = await res.json();
        // If banned mid-session, the send_message endpoint also returns banned:true
        if (data.banned) {
            showBanOverlay(data.error || 'Your account has been banned.');
            return;
        }
        if (data.success) {
            const old = messageMap[tempId];
            if (old) {
                const finalMessage = {
                    id: data.message_id,
                    username: ME.username,
                    message: text,
                    timestamp: old.dataset.ts || new Date().toISOString(),
                    avatar_color: ME.avatarColor,
                    avatar_url: ME.avatarUrl,
                    reactions: {},
                    file_id: fileId,
                    file_name: fileData ? fileData.name : null,
                    file_mime: fileData ? fileData.mime : null,
                    file_size: fileData ? fileData.size : null,
                };
                const fresh = _buildMessageElement(finalMessage);
                old.replaceWith(fresh);
                messageMap[data.message_id] = fresh;
                delete messageMap[tempId];
            }
        }
    } catch(e) {}
}

function handleKey(e) {
    // On mobile / touch devices, Enter adds a newline (natural for virtual keyboard).
    // On desktop, Enter sends; Shift+Enter adds newline.
    const isMobile = window.innerWidth <= 600 || navigator.maxTouchPoints > 1;
    if (e.key === 'Enter' && !e.shiftKey && !isMobile) {
        e.preventDefault();
        sendMessage();
    }
}

function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 160) + 'px';
}

// ─── Typing Indicator ─────────────────────────────────────────────────────────
let _typingThrottled = false;
function notifyTyping() {
    if (!wsReady || !ws) return;
    if (_typingThrottled) return;
    _typingThrottled = true;
    ws.send(JSON.stringify({ type: 'typing', room_id: ROOM_ID }));
    typingTimer = setTimeout(() => { _typingThrottled = false; }, 2000);
}

function showTyping(username) {
    typingUsers.add(username);
    renderTyping();
    setTimeout(() => { typingUsers.delete(username); renderTyping(); }, 3000);
}

function renderTyping() {
    const el = document.getElementById('typing-indicator');
    const textEl = document.getElementById('typing-text');
    if (typingUsers.size === 0) { el.style.display = 'none'; return; }
    el.style.display = 'flex';
    const names = [...typingUsers].join(', ');
    if (textEl) textEl.textContent = `${names} ${typingUsers.size === 1 ? 'is' : 'are'} typing…`;
}

// ─── Reactions ────────────────────────────────────────────────────────────────
async function react(msgId, emoji) {
    const fd = new FormData();
    fd.append('action', 'react');
    fd.append('message_id', msgId);
    fd.append('emoji', emoji);
    const res = await fetch('ajax_server.php', postData(fd));
    const data = await res.json();
    if (data.success) {
        updateReactions(msgId, data.reactions);
        // Broadcast via WS
        if (wsReady && ws) {
            ws.send(JSON.stringify({ type: 'reaction', message_id: msgId, emoji, reactions: data.reactions }));
        }
    }
}

// ─── Read Receipts ────────────────────────────────────────────────────────────
let readObserver = null;
const pendingRead = new Set();
let readFlushTimer = null;

function initReadObserver() {
    if (!('IntersectionObserver' in window)) return;
    readObserver = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const row = entry.target;
                const id  = row.dataset.msgId;
                // Only mark OTHER people's messages as read — never your own
                if (id && !id.startsWith('temp_') && !id.startsWith('ws_')
                        && !row.classList.contains('mine')) {
                    pendingRead.add(id);
                    clearTimeout(readFlushTimer);
                    readFlushTimer = setTimeout(flushRead, 1000);
                }
            }
        });
    }, { threshold: 0.5 });
}

async function flushRead() {
    if (!pendingRead.size) return;
    const ids = [...pendingRead];
    pendingRead.clear();
    const fd = new FormData();
    fd.append('action', 'read');
    fd.append('message_ids', JSON.stringify(ids));
    await fetch('ajax_server.php', postData(fd));
    // Do NOT update ticks here — ticks belong to the SENDER and turn green
    // only when other users read the message. pollReadStatus() handles that.
}

function markVisibleAsRead() {
    document.querySelectorAll('.msg-row[data-msg-id]').forEach(el => {
        const id = el.dataset.msgId;
        // Skip own messages and temp IDs
        if (id && !id.startsWith('temp_') && !id.startsWith('ws_')
                && !el.classList.contains('mine')) {
            pendingRead.add(id);
        }
    });
    if (pendingRead.size) flushRead();
}

async function markDelivered(ids) {
    if (!ids.length) return;
    for (const id of ids) {
        const fd = new FormData();
        fd.append('action', 'delivered');
        fd.append('message_id', id);
        await fetch('ajax_server.php', postData(fd));
    }
}

// Poll read status for your own sent messages so ticks update correctly.
// Collects all sent message IDs visible on screen, asks server who has read them.
async function pollReadStatus() {
    // Gather IDs of messages sent by ME that have a real DB id (not temp_)
    const myMsgIds = [];
    document.querySelectorAll('.msg-row.mine[data-msg-id]').forEach(el => {
        const id = el.dataset.msgId;
        if (id && !id.startsWith('temp_') && !id.startsWith('ws_')) {
            myMsgIds.push(id);
        }
    });
    if (!myMsgIds.length) return;

    try {
        const res = await fetch('ajax_server.php?action=read_status&room_id=' + ROOM_ID
            + '&message_ids=' + encodeURIComponent(JSON.stringify(myMsgIds)));
        const data = await res.json();
        if (!data.success) return;

        // data.statuses = {
        //   message_id: { readers: ['user1'], total_members: 2, target_readers: ['user1','user2'] }
        // }
        Object.entries(data.statuses).forEach(([msgId, status]) => {
            const tick = document.getElementById('tick-' + msgId);
            if (!tick) return;

            const readers = status.readers || [];
            const targets = Array.isArray(status.target_readers) ? status.target_readers : [];
            const targetSet = new Set(targets);
            const totalOthers = targets.length > 0
                ? targets.length
                : (Number.isFinite(Number(status.total_members)) ? Number(status.total_members) : 0);

            // Exclude sender + dedupe + only consider valid target members.
            const otherReaders = [...new Set(
                readers.filter(u => u !== ME.username && (targetSet.size === 0 || targetSet.has(u)))
            )];

            if (otherReaders.length === 0) {
                tick.textContent = '✓';
                tick.style.color = '';
            } else if (otherReaders.length >= totalOthers && totalOthers > 0) {
                // Everyone read — green double tick with pop
                const wasGrey = tick.textContent !== '✓✓' || tick.style.color !== '#43e97b';
                tick.textContent = '✓✓';
                tick.style.color = '#43e97b';
                if (wasGrey) _popTick(tick);
            } else {
                // Some (but not all) others have read it — double grey tick
                tick.textContent = '✓✓';
                tick.style.color = '';
            }
        });
    } catch(e) {}
}

// ─── Scroll ───────────────────────────────────────────────────────────────────
function scrollToBottom(smooth = false) {
    const area = document.getElementById('msgs-area');
    if (!area) return;
    if (smooth) {
        area.scrollTo({ top: area.scrollHeight, behavior: 'smooth' });
    } else {
        area.scrollTop = area.scrollHeight;
    }
}

function isAtBottom() {
    const area = document.getElementById('msgs-area');
    if (!area) return true;
    return area.scrollTop + area.clientHeight >= area.scrollHeight - 80;
}

// ── Scroll-to-bottom button visibility ────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const area = document.getElementById('msgs-area');
    const btn  = document.getElementById('scroll-to-bottom');
    if (!area || !btn) return;
    area.addEventListener('scroll', () => {
        const atBottom = area.scrollTop + area.clientHeight >= area.scrollHeight - 120;
        btn.style.display = atBottom ? 'none' : 'flex';
    }, { passive: true });
});

// ─── Members Modal ────────────────────────────────────────────────────────────
async function refreshMembersList() {
    const modal = document.getElementById('members-modal');
    if (!modal || !modal.classList.contains('open')) return;

    const res = await fetch(`ajax_server.php?action=room_members&room_id=${ROOM_ID}`);
    const data = await res.json();
    if (!data.success) return;

    const list = document.getElementById('members-list');
    list.innerHTML = data.members.map(m => `
        <div style="display:flex;align-items:center;gap:.8rem;padding:.6rem 0;border-bottom:1px solid var(--border)">
            ${_avatarMarkup(m.username, m.avatar_color || '#6c63ff', m.avatar_url)}
            <div style="flex:1">
                <div style="font-weight:600;font-size:.9rem">${escHtml(m.username)} ${m.is_creator ? '👑' : ''}</div>
                <div style="font-size:.75rem;color:var(--text-muted)">${m.last_seen ? 'Active' : 'Offline'}</div>
            </div>
            ${IS_CREATOR && !m.is_creator ? `<button class="btn-sm" style="background:rgba(255,80,80,.15);color:#ff6b6b;border:1px solid rgba(255,80,80,.3);cursor:pointer;font-size:.75rem;padding:.3rem .6rem;border-radius:6px" onclick="removeMember(${m.id},'${escAttr(m.username)}')">Remove</button>` : ''}
        </div>
    `).join('');
    const count = document.getElementById('members-count');
    if (count) count.textContent = `${data.members.length} members`;
}

async function openMembersModal() {
    document.getElementById('members-modal').classList.add('open');
    await refreshMembersList();
}

async function removeMember(userId, username) {
    if (!confirm(`Remove ${username} from the room?`)) return;
    const fd = new FormData();
    fd.append('action', 'remove_member');
    fd.append('room_id', ROOM_ID);
    fd.append('user_id', userId);
    const res = await fetch('ajax_server.php', postData(fd));
    const data = await res.json();
    if (data.success) {
        refreshMembersList();
        if (wsReady && ws) ws.send(JSON.stringify({ type: 'room_updated', room_id: ROOM_ID, reason: 'member_removed' }));
    }
}

async function changeRoomPassword() {
    const pass = document.getElementById('new-room-pass').value;
    const fd = new FormData();
    fd.append('action', 'change_room_password');
    fd.append('room_id', ROOM_ID);
    fd.append('new_password', pass);
    const res = await fetch('ajax_server.php', postData(fd));
    const data = await res.json();
    if (data.success) {
        alert('Password updated. Non-members will need to rejoin.');
        if (wsReady && ws) ws.send(JSON.stringify({ type: 'room_updated', room_id: ROOM_ID, reason: 'password_changed' }));
        document.getElementById('members-modal').classList.remove('open');
    } else alert(data.error);
}

// ─── Account Modal ────────────────────────────────────────────────────────────
// ─── Mobile Sidebar ───────────────────────────────────────────────────────────
function toggleMobileSidebar() {
    const sb  = document.querySelector('.sidebar');
    const ov  = document.getElementById('mobile-sb-overlay');
    const btn = document.getElementById('hamburger-btn');
    if (!sb) return;
    const isOpen = sb.classList.toggle('mobile-open');
    if (ov)  ov.classList.toggle('visible', isOpen);
    if (btn) btn.classList.toggle('active',  isOpen);
    // Prevent body scroll while drawer is open
    document.body.style.overflow = isOpen ? 'hidden' : '';
}

function closeMobileSidebar() {
    const sb  = document.querySelector('.sidebar');
    const ov  = document.getElementById('mobile-sb-overlay');
    const btn = document.getElementById('hamburger-btn');
    if (sb)  sb.classList.remove('mobile-open');
    if (ov)  ov.classList.remove('visible');
    if (btn) btn.classList.remove('active');
    document.body.style.overflow = '';
}

// Close on Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMobileSidebar(); });

// Close when a room link is tapped inside the drawer
document.querySelectorAll('.sb-link, .sb-brand').forEach(el => {
    el.addEventListener('click', () => setTimeout(closeMobileSidebar, 80));
});

function openAccountModal() {
    document.getElementById('account-modal').classList.add('open');
    document.getElementById('settings-menu').classList.remove('open');
    // Color picker
    const picker = document.getElementById('acc-color-picker');
    const currentColor = document.getElementById('acc-color').value;
    picker.innerHTML = COLORS.map(c => `
        <div class="swatch ${c === currentColor ? 'on' : ''}" style="background:${c}"
             data-color="${c}" onclick="accSelectColor(this)"></div>
    `).join('');
}

function accSelectColor(el) {
    document.querySelectorAll('#acc-color-picker .swatch').forEach(s => s.classList.remove('on'));
    el.classList.add('on');
    document.getElementById('acc-color').value = el.dataset.color;
}

async function saveAccount() {
    const fd = new FormData();
    fd.append('action', 'user_update');
    fd.append('username', document.getElementById('acc-username').value);
    fd.append('email', document.getElementById('acc-email').value);
    const pw = document.getElementById('acc-password').value;
    if (pw) fd.append('password', pw);
    fd.append('avatar_color', document.getElementById('acc-color').value);

    const res = await fetch('ajax_server.php', postData(fd));
    const data = await res.json();
    const okEl = document.getElementById('account-ok');
    const errEl = document.getElementById('account-err');
    if (data.success) {
        okEl.textContent = 'Saved! Reloading…'; okEl.style.display = 'block'; errEl.style.display = 'none';
        setTimeout(() => location.reload(), 1200);
    } else {
        errEl.textContent = data.error; errEl.style.display = 'block'; okEl.style.display = 'none';
    }
}

function showDeleteConfirm() {
    document.getElementById('delete-confirm').style.display = 'block';
}

async function deleteAccount() {
    const pass = document.getElementById('del-pass').value;
    const fd = new FormData();
    fd.append('action', 'user_delete');
    fd.append('password', pass);
    const res = await fetch('ajax_server.php', postData(fd));
    const data = await res.json();
    if (data.success) window.location = 'auth.php';
    else alert(data.error);
}

// ─── Settings Dropdown ────────────────────────────────────────────────────────
function toggleSettings() {
    document.getElementById('settings-menu').classList.toggle('open');
}

document.addEventListener('click', e => {
    const menu = document.getElementById('settings-menu');
    const btn = document.getElementById('settings-btn');
    if (menu && !menu.contains(e.target) && e.target !== btn) menu.classList.remove('open');
});

// ─── Misc Actions ─────────────────────────────────────────────────────────────
function clearChat() {
    if (!confirm('Clear your chat history? This only affects your view — other users are not affected.')) return;

    // Format timestamp as MySQL DATETIME string: 'YYYY-MM-DD HH:MM:SS'
    // JS ISO string has milliseconds + 'Z' which MySQL DATETIME doesn't understand
    const d = new Date();
    // Add 1 second so the filter is strictly AFTER now (avoids boundary edge case)
    d.setSeconds(d.getSeconds() + 1);
    const mysqlNow = d.getFullYear() + '-' +
        String(d.getMonth()+1).padStart(2,'0') + '-' +
        String(d.getDate()).padStart(2,'0') + ' ' +
        String(d.getHours()).padStart(2,'0') + ':' +
        String(d.getMinutes()).padStart(2,'0') + ':' +
        String(d.getSeconds()).padStart(2,'0');

    clearTimestamp = mysqlNow;
    localStorage.setItem(CLEAR_STORAGE_KEY, mysqlNow);

    // Persist to server so the clear survives across devices/sessions
    const fd = new FormData();
    fd.append('action', 'clear_messages');
    fd.append('room_id', ROOM_ID);
    fetch('ajax_server.php', postData(fd)).catch(() => {});

    // Wipe visible chat and reset state
    document.getElementById('msgs-area').innerHTML = '';
    messageMap          = {};
    lastMessageTs       = mysqlNow;
    oldestMessageId     = null;
    hasOlderMessages    = false;
    loadingOlder        = false;

    document.getElementById('settings-menu').classList.remove('open');
    appendSystem('Chat cleared — only you can see this change.');
}

async function leaveRoom() {
    if (!confirm('Leave this room?')) return;
    closeWSGracefully();
    const fd = new FormData();
    fd.append('action', 'leave_room');
    fd.append('room_id', ROOM_ID);
    const res = await fetch('ajax_server.php', postData(fd));
    const data = await res.json();
    if (data.success) window.location = 'rooms.php';
    else alert(data.error);
}

async function logout() {
    closeWSGracefully();
    await fetch('ajax_server.php', (() => { const fd = new FormData(); fd.append('action','auth_logout'); return postData(fd); })());
    window.location = 'auth.php';
}

// ─── Utils ────────────────────────────────────────────────────────────────────
function escHtml(s) {
    if (typeof s !== 'string') s = String(s || '');
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
function escAttr(s) { return String(s||'').replace(/'/g,'&apos;').replace(/"/g,'&quot;'); }

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

// ─── Profile Picture (Avatar Upload) ─────────────────────────────────────────
async function uploadAvatar(input) {
    const file = input.files[0];
    if (!file) return;
    input.value = '';

    if (file.size > 5 * 1024 * 1024) { alert('Avatar must be under 5 MB.'); return; }

    const fd = new FormData();
    fd.append('action', 'upload_avatar');
    fd.append('avatar', file);
    if (typeof CSRF_TOKEN !== 'undefined') fd.append('_csrf', CSRF_TOKEN);

    const okEl  = document.getElementById('account-ok');
    const errEl = document.getElementById('account-err');
    okEl.textContent  = '⏳ Uploading…'; okEl.style.display  = 'block';
    errEl.style.display = 'none';

    try {
        const res  = await fetch('ajax_server.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            // Update preview in modal
            const preview = document.getElementById('acc-avatar-preview');
            preview.innerHTML = _avatarImageMarkup(data.avatar_url, ME.username, 'avatar avatar-img');
            document.getElementById('remove-avatar-btn').style.display = 'inline-flex';
            // Update sidebar footer avatar
            _refreshSidebarAvatar(data.avatar_url);
            // Update all visible references immediately
            _applyAvatarUpdate(ME.username, ME.avatarColor, data.avatar_url);
            _publishAvatarSync({
                username: ME.username,
                avatar_color: ME.avatarColor,
                avatar_url: data.avatar_url,
            });
            if (wsReady && ws) {
                ws.send(JSON.stringify({
                    type: 'avatar_updated',
                    room_id: ROOM_ID,
                    username: ME.username,
                    avatar_color: ME.avatarColor,
                    avatar_url: data.avatar_url,
                }));
            }
            okEl.textContent = '✓ Avatar updated!';
        } else {
            errEl.textContent = data.error || 'Upload failed'; errEl.style.display = 'block';
            okEl.style.display = 'none';
        }
    } catch(e) {
        errEl.textContent = 'Network error'; errEl.style.display = 'block';
        okEl.style.display = 'none';
    }
}

async function removeAvatar() {
    if (!confirm('Remove your profile picture?')) return;
    const fd = new FormData();
    fd.append('action', 'remove_avatar');
    if (typeof CSRF_TOKEN !== 'undefined') fd.append('_csrf', CSRF_TOKEN);
    const res  = await fetch('ajax_server.php', postData(fd));
    const data = await res.json();
    if (data.success) {
        const preview = document.getElementById('acc-avatar-preview');
        const color   = document.getElementById('acc-color').value || ME.avatarColor;
        const initials = ME.username.slice(0,2).toUpperCase();
        preview.innerHTML = `<div class="acc-avatar-initials" id="acc-avatar-initials" style="background:${color}">${initials}</div>`;
        document.getElementById('remove-avatar-btn').style.display = 'none';
        _applyAvatarUpdate(ME.username, ME.avatarColor, null);
        _publishAvatarSync({
            username: ME.username,
            avatar_color: ME.avatarColor,
            avatar_url: null,
        });
        if (wsReady && ws) {
            ws.send(JSON.stringify({
                type: 'avatar_updated',
                room_id: ROOM_ID,
                username: ME.username,
                avatar_color: ME.avatarColor,
                avatar_url: null,
            }));
        }
    }
}

function _refreshSidebarAvatar(avatarUrl) {
    const footer = document.getElementById('room-avatar-trigger') || document.querySelector('.sb-footer > div:first-child');
    if (!footer) return;
    const pip = footer.querySelector('.online-pip');
    if (avatarUrl) {
        footer.innerHTML = _avatarImageMarkup(avatarUrl, ME.username, 'avatar avatar-img sb-avatar');
    } else {
        const color = ME.avatarColor;
        const init  = ME.username.slice(0,2).toUpperCase();
        footer.innerHTML = `<div class="avatar" style="background:${color}">${init}</div>`;
    }
    if (pip) footer.appendChild(pip);
}

function _normalizeAvatarUrl(avatarUrl) {
    return avatarUrl ? 'uploads/avatars/' + avatarUrl.split('/').pop() : null;
}

function _avatarImageMarkup(avatarUrl, username, className) {
    const normalizedUrl = _normalizeAvatarUrl(avatarUrl);
    if (!normalizedUrl) return '';
    const safeClass = className || 'avatar-img';
    const safeAlt = username || 'avatar';
    const canOpenViewer = typeof openAvatarViewer === 'function';
    const clickAttr = canOpenViewer
        ? ` style="cursor:pointer" onclick='event.stopPropagation(); openAvatarViewer(${JSON.stringify(normalizedUrl)}, ${JSON.stringify(safeAlt)})'`
        : '';
    return `<img src="${normalizedUrl}?t=${Date.now()}" class="${safeClass}" alt="${escHtml(safeAlt)}"${clickAttr}>`;
}

function _avatarMarkup(username, color, avatarUrl) {
    const initials = (username || '?').slice(0, 2).toUpperCase();
    const normalizedUrl = _normalizeAvatarUrl(avatarUrl);
    return normalizedUrl
        ? _avatarImageMarkup(normalizedUrl, username, 'avatar small avatar-img')
        : `<div class="avatar small" style="background:${color || '#6c63ff'}">${initials}</div>`;
}

function _applyAvatarUpdate(username, avatarColor, avatarUrl, options) {
    if (!username) return;
    const skipMembersRefresh = !!(options && options.skipMembersRefresh);

    const normalizedUrl = _normalizeAvatarUrl(avatarUrl);
    const color = avatarColor || avatarCache[username]?.color || '#6c63ff';
    avatarCache[username] = { color, img: normalizedUrl };

    if (username === ME.username) {
        if (avatarColor) ME.avatarColor = avatarColor;
        ME.avatarUrl = normalizedUrl;
        _refreshSidebarAvatar(normalizedUrl);
        const roomAvatar = document.getElementById('room-avatar-img');
        if (roomAvatar && normalizedUrl) {
            roomAvatar.src = `${normalizedUrl}?t=${Date.now()}`;
            roomAvatar.alt = username;
        }
        const roomsAvatarWrap = document.getElementById('rooms-user-avatar');
        if (roomsAvatarWrap) {
            roomsAvatarWrap.innerHTML = normalizedUrl
                ? _avatarImageMarkup(normalizedUrl, username, 'avatar avatar-img sb-avatar')
                : `<div class="avatar" style="background:${avatarColor || color};cursor:default;width:32px;height:32px;border-radius:9px;font-size:.75rem" title="${escHtml(username)}">${(username || '?').slice(0, 2).toUpperCase()}</div>`;
        }
        const adminAvatar = document.getElementById('admin-bar-avatar');
        if (adminAvatar) {
            adminAvatar.innerHTML = normalizedUrl
                ? _avatarImageMarkup(normalizedUrl, username, 'avatar avatar-img admin-avatar-img')
                : `<div class="avatar" style="background:${avatarColor || color}">${(username || '?').slice(0, 2).toUpperCase()}</div>`;
        }
    }

    document.querySelectorAll('.msg-row').forEach(row => {
        if (row.dataset.author !== username) return;
        const avatar = row.querySelector('.avatar.small, .avatar.small.avatar-img');
        if (!avatar) return;
        // Only replace the DOM if the avatar data actually changed — prevents
        // the 2-second flicker caused by the periodic syncRoomAvatars poll.
        const currentSrc = avatar.tagName === 'IMG' ? avatar.getAttribute('src') : null;
        const currentBg  = avatar.style.background || '';
        const newSrc = normalizedUrl ? normalizedUrl : null;
        const isSameImg   = currentSrc !== null && newSrc !== null && currentSrc.split('?')[0] === newSrc.split('?')[0];
        const isSameInit  = currentSrc === null && newSrc === null && currentBg.includes(color);
        if (isSameImg || isSameInit) return;   // nothing changed — skip noisy DOM mutation
        avatar.outerHTML = _avatarMarkup(username, color, normalizedUrl);
    });

    if (!skipMembersRefresh) refreshMembersList();
}

function openAvatarViewer(avatarUrl, username) {
    const overlay = document.getElementById('avatar-viewer');
    const img = document.getElementById('avatar-viewer-img');
    const label = document.getElementById('avatar-viewer-name');
    if (!overlay || !img) return;
    const normalizedUrl = _normalizeAvatarUrl(avatarUrl);
    if (!normalizedUrl) return;
    img.src = `${normalizedUrl}?t=${Date.now()}`;
    img.alt = username || 'avatar';
    if (label) label.textContent = username ? `${username}'s profile picture` : 'Profile picture';
    overlay.classList.add('open');
}

function closeAvatarViewer() {
    const overlay = document.getElementById('avatar-viewer');
    if (overlay) overlay.classList.remove('open');
}

// ─── Emoji Picker ─────────────────────────────────────────────────────────────

// Full emoji dataset organised by category.
// Each entry: [emoji, short name for search]
const EMOJI_DATA = {
    '😀 Smileys': [
        ['😀','grinning'],['😃','grinning big eyes'],['😄','grinning smiling eyes'],['😁','beaming'],
        ['😆','grinning squinting'],['😅','sweat smile'],['🤣','rolling laughing'],['😂','joy tears'],
        ['🙂','slightly smiling'],['🙃','upside down'],['😉','winking'],['😊','smiling eyes'],
        ['😇','halo'],['🥰','hearts smiling'],['😍','heart eyes'],['🤩','star struck'],
        ['😘','kissing heart'],['😗','kissing'],['☺️','relaxed'],['😚','kissing closed'],
        ['😙','kissing smiling'],['🥲','smiling tear'],['😋','yum'],['😛','tongue'],
        ['😜','winking tongue'],['🤪','zany'],['😝','squinting tongue'],['🤑','money mouth'],
        ['🤗','hugging'],['🤭','hand over mouth'],['🤫','shushing'],['🤔','thinking'],
        ['🤐','zipper mouth'],['🤨','raised eyebrow'],['😐','neutral'],['😑','expressionless'],
        ['😶','no mouth'],['😶‍🌫️','cloud face'],['😏','smirking'],['😒','unamused'],
        ['🙄','rolling eyes'],['😬','grimacing'],['🤥','lying'],['😌','relieved'],
        ['😔','pensive'],['😪','sleepy'],['🤤','drooling'],['😴','sleeping'],
        ['😷','mask'],['🤒','thermometer'],['🤕','bandage'],['🤢','nauseated'],
        ['🤮','vomiting'],['🤧','sneezing'],['🥵','hot'],['🥶','cold'],
        ['🥴','woozy'],['😵','dizzy'],['😵‍💫','spiral eyes'],['🤯','exploding head'],
        ['🤠','cowboy'],['🥳','party'],['🥸','disguised'],['😎','sunglasses'],
        ['🤓','nerd'],['🧐','monocle'],['😕','confused'],['😟','worried'],
        ['🙁','slightly frowning'],['☹️','frowning'],['😮','open mouth'],['😯','hushed'],
        ['😲','astonished'],['😳','flushed'],['🥺','pleading'],['😦','frowning open'],
        ['😧','anguished'],['😨','fearful'],['😰','anxious sweat'],['😥','sad relieved'],
        ['😢','crying'],['😭','loudly crying'],['😱','screaming fear'],['😖','confounded'],
        ['😣','persevering'],['😞','disappointed'],['😓','downcast sweat'],['😩','weary'],
        ['😫','tired'],['🥱','yawning'],['😤','triumph'],['😡','pouting'],
        ['😠','angry'],['🤬','swearing'],['😈','smiling devil'],['👿','angry devil'],
        ['💀','skull'],['☠️','skull crossbones'],['💩','pile of poo'],['🤡','clown'],
        ['👹','ogre'],['👺','goblin'],['👻','ghost'],['👽','alien'],
        ['👾','alien monster'],['🤖','robot'],['😺','smiling cat'],['😸','grinning cat'],
        ['😹','cat joy'],['😻','heart eyes cat'],['😼','cat smirk'],['😽','kissing cat'],
        ['🙀','weary cat'],['😿','crying cat'],['😾','pouting cat'],
    ],
    '👋 People': [
        ['👋','waving hand'],['🤚','raised back'],['🖐️','hand fingers splayed'],['✋','raised hand'],
        ['🖖','vulcan salute'],['👌','ok hand'],['🤌','pinched fingers'],['🤏','pinching hand'],
        ['✌️','victory'],['🤞','crossed fingers'],['🤟','love you'],['🤘','sign of horns'],
        ['🤙','call me'],['👈','backhand left'],['👉','backhand right'],['👆','backhand up'],
        ['🖕','middle finger'],['👇','backhand down'],['☝️','index up'],['👍','thumbs up'],
        ['👎','thumbs down'],['✊','raised fist'],['👊','oncoming fist'],['🤛','left fist'],
        ['🤜','right fist'],['👏','clapping'],['🙌','raising hands'],['👐','open hands'],
        ['🤲','palms up'],['🙏','folded hands'],['✍️','writing hand'],['💅','nail polish'],
        ['🤳','selfie'],['💪','flexed bicep'],['🦾','mechanical arm'],['🦵','leg'],
        ['🦶','foot'],['👂','ear'],['🦻','ear hearing aid'],['👃','nose'],
        ['🫀','anatomical heart'],['🫁','lungs'],['🧠','brain'],['🦷','tooth'],
        ['🦴','bone'],['👀','eyes'],['👁️','eye'],['👅','tongue'],['👄','mouth'],
        ['💋','kiss mark'],['🫦','biting lip'],
        ['👶','baby'],['🧒','child'],['👦','boy'],['👧','girl'],
        ['🧑','person'],['👱','blond person'],['👨','man'],['🧔','bearded person'],
        ['👩','woman'],['🧓','older person'],['👴','old man'],['👵','old woman'],
        ['👮','police'],['💂','guard'],['🥷','ninja'],['👷','construction worker'],
        ['🤴','prince'],['👸','princess'],['👳','person turban'],['👲','person skullcap'],
        ['🧕','woman headscarf'],['🤵','person tuxedo'],['👰','person veil'],
        ['🤰','pregnant woman'],['🤱','breast feeding'],['👼','baby angel'],
        ['🎅','santa'],['🤶','mrs claus'],['🧙','mage'],['🧝','elf'],
        ['🧛','vampire'],['🧟','zombie'],['🧞','genie'],['🧜','merperson'],
        ['🧚','fairy'],['🧌','troll'],['👼','angel'],['🤦','person facepalm'],
        ['🤷','person shrug'],
    ],
    '❤️ Hearts': [
        ['❤️','red heart'],['🧡','orange heart'],['💛','yellow heart'],['💚','green heart'],
        ['💙','blue heart'],['💜','purple heart'],['🖤','black heart'],['🤍','white heart'],
        ['🤎','brown heart'],['💔','broken heart'],['❤️‍🔥','heart on fire'],['❤️‍🩹','mending heart'],
        ['❣️','exclamation heart'],['💕','two hearts'],['💞','revolving hearts'],['💓','beating heart'],
        ['💗','growing heart'],['💖','sparkling heart'],['💘','heart arrow'],['💝','heart ribbon'],
        ['💟','heart decoration'],['☮️','peace'],['✝️','cross'],['☪️','star crescent'],
        ['🕉️','om'],['✡️','star david'],['🔯','dotted six star'],['🛐','worship'],
        ['⛎','ophiuchus'],['♈','aries'],['♉','taurus'],['♊','gemini'],
        ['♋','cancer'],['♌','leo'],['♍','virgo'],['♎','libra'],
        ['♏','scorpio'],['♐','sagittarius'],['♑','capricorn'],['♒','aquarius'],
        ['♓','pisces'],
    ],
    '🎉 Activities': [
        ['⚽','soccer'],['🏀','basketball'],['🏈','football'],['⚾','baseball'],
        ['🥎','softball'],['🎾','tennis'],['🏐','volleyball'],['🏉','rugby'],
        ['🥏','flying disc'],['🎱','billiard'],['🏓','ping pong'],['🏸','badminton'],
        ['🏒','ice hockey'],['🥍','lacrosse'],['🏑','field hockey'],['🏏','cricket'],
        ['🛹','skateboard'],['🛼','roller skate'],['🥅','goal net'],['⛳','golf'],
        ['🎣','fishing'],['🤿','diving mask'],['🎽','running shirt'],['🎿','skis'],
        ['🛷','sled'],['🥌','curling stone'],['🎯','bullseye'],['🪀','yoyo'],
        ['🪁','boomerang'],['🔫','pistol'],['🪃','boomerang'],['🏋️','weight lifting'],
        ['🤸','person cartwheel'],['🤼','wrestling'],['🤺','fencing'],['🤾','handball'],
        ['🏌️','golfing'],['🏇','horse racing'],['🧘','lotus position'],['🏄','surfing'],
        ['🏊','swimming'],['🚣','rowing'],['🧗','climbing'],['🚵','mountain biking'],
        ['🚴','cycling'],['🏆','trophy'],['🥇','gold medal'],['🥈','silver medal'],
        ['🥉','bronze medal'],['🏅','sports medal'],['🎖️','military medal'],['🎗️','reminder ribbon'],
        ['🎫','ticket'],['🎟️','admission ticket'],['🎪','circus'],['🤹','juggling'],
        ['🎭','arts'],['🎨','artist palette'],['🎬','clapper'],['🎤','microphone'],
        ['🎧','headphone'],['🎼','musical score'],['🎵','musical note'],['🎶','notes'],
        ['🎹','piano'],['🥁','drum'],['🪘','long drum'],['🎷','saxophone'],
        ['🎺','trumpet'],['🎸','guitar'],['🪕','banjo'],['🎻','violin'],
        ['🪗','accordion'],['🎲','game die'],['♟️','chess'],['🎮','video game'],
        ['🕹️','joystick'],['🎰','slot machine'],['🧩','puzzle'],['🪄','magic wand'],
        ['🎃','jack o lantern'],['🎄','christmas tree'],['🎆','fireworks'],['🎇','sparkler'],
        ['🧨','firecracker'],['✨','sparkles'],['🎉','party popper'],['🎊','confetti'],
        ['🎋','tanabata tree'],['🎍','pine decoration'],['🎎','dolls'],['🎏','carp streamer'],
        ['🎐','wind chime'],['🎑','moon ceremony'],['🧧','red envelope'],['🎀','ribbon'],
        ['🎁','gift'],['🎗️','reminder ribbon'],['🎟️','admission tickets'],['🎫','ticket'],
    ],
    '🍕 Food': [
        ['🍇','grapes'],['🍈','melon'],['🍉','watermelon'],['🍊','tangerine'],
        ['🍋','lemon'],['🍌','banana'],['🍍','pineapple'],['🥭','mango'],
        ['🍎','red apple'],['🍏','green apple'],['🍐','pear'],['🍑','peach'],
        ['🍒','cherries'],['🍓','strawberry'],['🫐','blueberries'],['🥝','kiwi'],
        ['🍅','tomato'],['🫒','olive'],['🥥','coconut'],['🥑','avocado'],
        ['🍆','eggplant'],['🥔','potato'],['🥕','carrot'],['🌽','corn'],
        ['🌶️','pepper'],['🫑','bell pepper'],['🥒','cucumber'],['🥬','leafy green'],
        ['🥦','broccoli'],['🧄','garlic'],['🧅','onion'],['🍄','mushroom'],
        ['🥜','peanuts'],['🫘','beans'],['🌰','chestnut'],['🍞','bread'],
        ['🥐','croissant'],['🥖','baguette'],['🫓','flatbread'],['🥨','pretzel'],
        ['🥯','bagel'],['🥞','pancakes'],['🧇','waffle'],['🧀','cheese'],
        ['🍖','meat on bone'],['🍗','poultry leg'],['🥩','cut of meat'],['🥓','bacon'],
        ['🍔','hamburger'],['🍟','fries'],['🍕','pizza'],['🌭','hotdog'],
        ['🫔','tamale'],['🥪','sandwich'],['🥙','stuffed flatbread'],['🧆','falafel'],
        ['🥚','egg'],['🍳','cooking'],['🥘','pan of food'],['🍲','pot of food'],
        ['🫕','fondue'],['🥣','bowl spoon'],['🥗','salad'],['🍿','popcorn'],
        ['🧈','butter'],['🧂','salt'],['🥫','canned food'],['🍱','bento box'],
        ['🍘','rice cracker'],['🍙','rice ball'],['🍚','cooked rice'],['🍛','curry rice'],
        ['🍜','spaghetti'],['🍝','spaghetti'],['🍠','roasted potato'],['🍢','oden'],
        ['🍣','sushi'],['🍤','fried shrimp'],['🍥','fish cake'],['🥮','moon cake'],
        ['🍡','dango'],['🥟','dumpling'],['🥠','fortune cookie'],['🥡','takeout box'],
        ['🦀','crab'],['🦞','lobster'],['🦐','shrimp'],['🦑','squid'],
        ['🦪','oyster'],['🍦','soft ice cream'],['🍧','shaved ice'],['🍨','ice cream'],
        ['🍩','doughnut'],['🍪','cookie'],['🎂','birthday cake'],['🍰','shortcake'],
        ['🧁','cupcake'],['🥧','pie'],['🍫','chocolate'],['🍬','candy'],
        ['🍭','lollipop'],['🍮','custard'],['🍯','honey pot'],['☕','coffee'],
        ['🫖','teapot'],['🍵','teacup'],['🧃','juice box'],['🥤','cup straw'],
        ['🧋','bubble tea'],['🍶','sake'],['🍾','champagne'],['🍷','wine'],
        ['🍸','cocktail'],['🍹','tropical drink'],['🍺','beer'],['🍻','beers'],
        ['🥂','clinking glasses'],['🥃','tumbler'],['🫗','pouring liquid'],['🥛','milk'],
        ['🫙','jar'],['🍼','baby bottle'],['🧊','ice'],['🥢','chopsticks'],
        ['🍽️','plate cutlery'],['🍴','fork knife'],['🥄','spoon'],
    ],
    '🌍 Travel': [
        ['🚗','car'],['🚕','taxi'],['🚙','suv'],['🚌','bus'],
        ['🚎','trolleybus'],['🏎️','racing car'],['🚓','police car'],['🚑','ambulance'],
        ['🚒','fire engine'],['🚐','minibus'],['🛻','pickup truck'],['🚚','truck'],
        ['🚛','semi truck'],['🚜','tractor'],['🏍️','motorcycle'],['🛵','scooter'],
        ['🦽','manual wheelchair'],['🦼','motorized wheelchair'],['🛺','auto rickshaw'],
        ['🚲','bicycle'],['🛴','kick scooter'],['🛹','skateboard'],['🛼','roller skate'],
        ['🚏','bus stop'],['🛣️','motorway'],['🛤️','railway track'],['⛽','fuel pump'],
        ['🚨','siren'],['🚥','traffic light'],['🚦','traffic light'],['🛑','stop sign'],
        ['⚓','anchor'],['🛟','life ring'],['⛵','sailboat'],['🛶','canoe'],
        ['🚤','speedboat'],['🛳️','cruise ship'],['⛴️','ferry'],['🛥️','motor boat'],
        ['🚢','ship'],['✈️','airplane'],['🛩️','small plane'],['🛫','departure'],
        ['🛬','arrival'],['🪂','parachute'],['💺','seat'],['🚁','helicopter'],
        ['🚟','suspension railway'],['🚠','mountain cableway'],['🚡','aerial tramway'],
        ['🛰️','satellite'],['🚀','rocket'],['🛸','flying saucer'],['🪐','ringed planet'],
        ['🌠','shooting star'],['🌌','milky way'],['🌑','new moon'],['🌒','waxing crescent'],
        ['🌓','first quarter'],['🌔','waxing gibbous'],['🌕','full moon'],['🌖','waning gibbous'],
        ['🌗','last quarter'],['🌘','waning crescent'],['🌙','crescent moon'],['🌚','new moon face'],
        ['🌛','quarter moon face'],['🌜','last quarter face'],['🌝','full moon face'],['🌞','sun face'],
        ['🌟','glowing star'],['⭐','star'],['💫','dizzy star'],['✨','sparkles'],
        ['⚡','lightning'],['🌈','rainbow'],['☀️','sun'],['🌤️','partly cloudy'],
        ['⛅','cloud sun'],['🌦️','sun rain'],['🌧️','rain cloud'],['⛈️','thunder'],
        ['🌩️','lightning cloud'],['🌨️','snow cloud'],['❄️','snowflake'],['☃️','snowman'],
        ['⛄','snowman no snow'],['🌬️','wind face'],['💨','dashing away'],['🌀','cyclone'],
        ['🌊','water wave'],['💧','droplet'],['💦','sweat droplets'],['🫧','bubbles'],
        ['🌋','volcano'],['⛰️','mountain'],['🏔️','snow mountain'],['🗻','mount fuji'],
        ['🏕️','camping'],['🏖️','beach'],['🏜️','desert'],['🏝️','island'],
        ['🏞️','national park'],['🏟️','stadium'],['🏛️','classical building'],['🏗️','building construction'],
        ['🏘️','houses'],['🏚️','derelict house'],['🏠','house'],['🏡','house garden'],
        ['🏢','office'],['🏣','post office japan'],['🏤','post office'],['🏥','hospital'],
        ['🏦','bank'],['🏨','hotel'],['🏩','love hotel'],['🏪','convenience store'],
        ['🏫','school'],['🏬','department store'],['🏭','factory'],['🗼','tokyo tower'],
        ['🗽','statue liberty'],['⛪','church'],['🕌','mosque'],['🛕','hindu temple'],
        ['🕍','synagogue'],['⛩️','shinto shrine'],['🕋','kaaba'],['⛲','fountain'],
        ['⛺','tent'],['🌁','foggy'],['🌃','night stars'],['🏙️','cityscape'],
        ['🌄','sunrise mountain'],['🌅','sunrise'],['🌆','city dusk'],['🌇','city sunset'],
        ['🌉','bridge night'],['🎠','carousel horse'],['🎡','ferris wheel'],['🎢','roller coaster'],
        ['💈','barber pole'],['🎪','circus tent'],['🚂','locomotive'],['🚃','railway car'],
        ['🚄','bullet train'],['🚅','shinkansen'],['🚆','train'],['🚇','metro'],
        ['🚈','light rail'],['🚉','station'],['🚊','tram'],['🚝','monorail'],
        ['🚞','mountain railway'],['🚋','tram car'],
    ],
    '💡 Objects': [
        ['⌚','watch'],['📱','mobile'],['💻','laptop'],['🖥️','desktop'],
        ['🖨️','printer'],['⌨️','keyboard'],['🖱️','mouse'],['🖲️','trackball'],
        ['💾','floppy disk'],['💿','cd'],['📀','dvd'],['🧮','abacus'],
        ['📷','camera'],['📸','flash camera'],['📹','video camera'],['🎥','movie camera'],
        ['📽️','film projector'],['🎞️','film frames'],['📞','telephone'],['☎️','old telephone'],
        ['📟','pager'],['📠','fax'],['📺','tv'],['📻','radio'],
        ['🧭','compass'],['⏱️','stopwatch'],['⏲️','timer'],['⏰','alarm clock'],
        ['🕰️','mantel clock'],['⌛','hourglass done'],['⏳','hourglass flowing'],['📡','satellite antenna'],
        ['🔋','battery'],['🪫','low battery'],['🔌','electric plug'],['💡','light bulb'],
        ['🔦','flashlight'],['🕯️','candle'],['🪔','lamp'],['🧱','brick'],
        ['💰','money bag'],['💴','yen'],['💵','dollar'],['💶','euro'],
        ['💷','pound'],['💸','money wings'],['💳','credit card'],['🪙','coin'],
        ['💹','chart increasing yen'],['📈','chart up'],['📉','chart down'],['📊','bar chart'],
        ['✉️','envelope'],['📧','email'],['📨','incoming'],['📩','outgoing'],
        ['📤','outbox'],['📥','inbox'],['📦','package'],['📫','mailbox flag up'],
        ['📪','mailbox flag down'],['📬','open mailbox flag up'],['📭','open mailbox flag down'],
        ['📮','mailbox'],['🗳️','ballot box'],['✏️','pencil'],['✒️','black nib'],
        ['🖋️','fountain pen'],['🖊️','pen'],['🖌️','paintbrush'],['🖍️','crayon'],
        ['📝','memo'],['📁','folder'],['📂','open folder'],['🗂️','card dividers'],
        ['📅','calendar'],['📆','tear off calendar'],['🗒️','spiral notepad'],['🗓️','spiral calendar'],
        ['📇','card index'],['📈','chart up'],['📋','clipboard'],['📌','pushpin'],
        ['📍','round pushpin'],['📎','paperclip'],['🖇️','linked paperclips'],['📏','straight ruler'],
        ['📐','triangular ruler'],['✂️','scissors'],['🗃️','card file box'],['🗄️','file cabinet'],
        ['🗑️','wastebasket'],['🔒','locked'],['🔓','unlocked'],['🔏','locked pen'],
        ['🔐','locked key'],['🔑','key'],['🗝️','old key'],['🔨','hammer'],
        ['🪓','axe'],['⛏️','pick'],['⚒️','hammer pick'],['🛠️','tools'],
        ['🗡️','dagger'],['⚔️','crossed swords'],['🛡️','shield'],['🪃','boomerang'],
        ['🔧','wrench'],['🪛','screwdriver'],['🔩','nut bolt'],['⚙️','gear'],
        ['🗜️','clamp'],['⚖️','balance scale'],['🦯','cane'],['🔗','link'],
        ['⛓️','chains'],['🪝','hook'],['🧲','magnet'],['🪜','ladder'],
        ['🧪','test tube'],['🧫','petri dish'],['🧬','dna'],['🔬','microscope'],
        ['🔭','telescope'],['📡','satellite'],['💉','syringe'],['🩸','blood'],
        ['💊','pill'],['🩹','bandage'],['🩺','stethoscope'],['🩻','x-ray'],
        ['🚪','door'],['🪞','mirror'],['🪟','window'],['🛏️','bed'],
        ['🛋️','couch'],['🪑','chair'],['🚽','toilet'],['🪠','plunger'],
        ['🚿','shower'],['🛁','bathtub'],['🪥','toothbrush'],['🧴','lotion bottle'],
        ['🧷','safety pin'],['🧹','broom'],['🧺','basket'],['🧻','roll of paper'],
        ['🪣','bucket'],['🧼','soap'],['🫧','bubbles'],['🪒','razor'],
        ['🧽','sponge'],['🧯','extinguisher'],['🛒','shopping cart'],['🚩','flag'],
        ['🎌','crossed flags'],['🏴','black flag'],['🏳️','white flag'],
    ],
    '🔣 Symbols': [
        ['❗','exclamation'],['❓','question'],['‼️','double exclamation'],['⁉️','exclamation question'],
        ['🔅','dim'],['🔆','bright'],['🔱','trident'],['⚜️','fleur de lis'],
        ['🔰','beginner'],['♻️','recycle'],['✅','check'],['🆗','ok'],
        ['🆙','up'],['🆕','new'],['🆓','free'],['🆒','cool'],
        ['🆖','ng'],['🅰️','a blood'],['🅱️','b blood'],['🆎','ab blood'],
        ['🅾️','o blood'],['🆑','cl'],['🆘','sos'],['⛔','no entry'],
        ['🚫','prohibited'],['📵','no mobile'],['🔞','no one under eighteen'],['❌','cross mark'],
        ['⭕','hollow red circle'],['🛑','stop'],['⚠️','warning'],['🚷','no pedestrians'],
        ['🚯','no littering'],['🚳','no bicycles'],['🚱','non potable water'],['🔕','bell slash'],
        ['🔇','muted speaker'],['📴','mobile off'],['📳','vibration'],['🔈','speaker low'],
        ['🔉','speaker medium'],['🔊','speaker high'],['📢','loudspeaker'],['📣','megaphone'],
        ['🔔','bell'],['🔕','bell slash'],['🎵','musical note'],['🎶','notes'],
        ['💹','yen chart'],['💱','currency exchange'],['💲','dollar'],['Ⓜ️','m'],
        ['🅿️','p parking'],['🈳','vacant'],['🈴','pass'],['🈵','no vacancy'],
        ['🈹','discount'],['🈲','prohibited'],['🉐','bargain'],['🈶','paid'],
        ['🈚','free of charge'],['🈸','apply'],['🈺','open for business'],['🈷️','monthly'],
        ['✴️','eight pointed'],['🆚','vs'],['🉑','acceptable'],['💮','white flower'],
        ['🈁','here'],['📛','name badge'],['🔝','top'],['🔛','on'],
        ['🔜','soon'],['🔚','end'],['⏫','up fast'],['⏬','down fast'],
        ['⏩','fast forward'],['⏪','rewind'],['⏭️','next track'],['⏮️','previous track'],
        ['🔀','shuffle'],['🔁','repeat'],['🔂','repeat one'],['🔃','clockwise'],
        ['🔄','counterclockwise'],['🔙','back'],['🔛','on'],['🔜','soon'],
        ['🔝','top'],['🔰','beginner'],['🔹','small blue diamond'],['🔸','small orange diamond'],
        ['🔷','large blue diamond'],['🔶','large orange diamond'],['🔺','red triangle up'],
        ['🔻','red triangle down'],['💠','diamond blue'],['🔘','radio button'],
        ['🔲','black square button'],['🔳','white square button'],['⬛','black square'],
        ['⬜','white square'],['◼️','medium black square'],['◻️','medium white square'],
        ['▪️','small black square'],['▫️','small white square'],['🟥','red square'],
        ['🟧','orange square'],['🟨','yellow square'],['🟩','green square'],
        ['🟦','blue square'],['🟪','purple square'],['🟫','brown square'],
        ['⚫','black circle'],['⚪','white circle'],['🔴','red circle'],['🟠','orange circle'],
        ['🟡','yellow circle'],['🟢','green circle'],['🔵','blue circle'],['🟣','purple circle'],
        ['🟤','brown circle'],['0️⃣','zero'],['1️⃣','one'],['2️⃣','two'],
        ['3️⃣','three'],['4️⃣','four'],['5️⃣','five'],['6️⃣','six'],
        ['7️⃣','seven'],['8️⃣','eight'],['9️⃣','nine'],['🔟','ten'],
        ['🔠','letters'],['🔡','small letters'],['🔢','numbers'],['🔣','symbols'],
        ['🔤','latin letters'],['#️⃣','hash'],['*️⃣','asterisk'],
        ['➕','plus'],['➖','minus'],['➗','division'],['✖️','multiplication'],
        ['💯','hundred'],['🔑','key'],['🔐','locked key'],['🔏','locked pen'],
        ['🔓','unlocked'],['🔒','locked'],['💡','bulb'],['🔧','wrench'],
        ['🔨','hammer'],['⚙️','gear'],['🛠️','tools'],
    ],
    avatarUrl: null,
};

const EMOJI_CATEGORY_ICONS = {
    '😀 Smileys': '😀', '👋 People': '👋', '❤️ Hearts': '❤️',
    '🎉 Activities': '🎉', '🍕 Food': '🍕', '🌍 Travel': '🌍',
    '💡 Objects': '💡', '🔣 Symbols': '🔣',
};

let emojiPickerOpen    = false;
let emojiCurrentCat    = null;
let emojiRecentKey     = 'nexuschat_recent_emoji';
let emojiPickerInited  = false;

function _loadRecentEmoji() {
    try { return JSON.parse(localStorage.getItem(emojiRecentKey) || '[]'); } catch(e) { return []; }
}
function _saveRecentEmoji(arr) {
    try { localStorage.setItem(emojiRecentKey, JSON.stringify(arr.slice(0, 36))); } catch(e) {}
}
function _addRecentEmoji(emoji) {
    let recent = _loadRecentEmoji().filter(e => e !== emoji);
    recent.unshift(emoji);
    _saveRecentEmoji(recent);
}

function _initEmojiPicker() {
    if (emojiPickerInited) return;
    emojiPickerInited = true;

    // Build category tab bar
    const tabsEl = document.getElementById('emoji-cat-tabs');
    tabsEl.innerHTML = '';

    // "Recent" tab first
    const recentTab = document.createElement('button');
    recentTab.className = 'emoji-cat-tab';
    recentTab.title = 'Recently Used';
    recentTab.textContent = '🕐';
    recentTab.onclick = () => _showEmojiCategory('__recent__');
    tabsEl.appendChild(recentTab);

    Object.keys(EMOJI_CATEGORY_ICONS).forEach(cat => {
        const btn = document.createElement('button');
        btn.className = 'emoji-cat-tab';
        btn.title = cat.replace(/^[^\s]+ /, '');
        btn.textContent = EMOJI_CATEGORY_ICONS[cat];
        btn.dataset.cat = cat;
        btn.onclick = () => _showEmojiCategory(cat);
        tabsEl.appendChild(btn);
    });

    // Show first category
    _showEmojiCategory('__recent__');
}

function _showEmojiCategory(cat) {
    emojiCurrentCat = cat;

    // Highlight active tab
    document.querySelectorAll('.emoji-cat-tab').forEach(b => {
        b.classList.toggle('active', b.dataset.cat === cat || (cat === '__recent__' && b.title === 'Recently Used'));
    });

    const grid  = document.getElementById('emoji-grid');
    const label = document.getElementById('emoji-section-label');

    let emojis;
    if (cat === '__recent__') {
        label.textContent = 'Recently Used';
        const recent = _loadRecentEmoji();
        emojis = recent.length ? recent.map(e => [e, '']) : null;
        if (!emojis) {
            grid.innerHTML = '<div class="emoji-empty">No recent emoji yet</div>';
            return;
        }
    } else {
        label.textContent = cat.replace(/^[^\s]+ /, '');
        emojis = EMOJI_DATA[cat] || [];
    }

    _renderEmojiGrid(emojis);
}

function _renderEmojiGrid(emojis) {
    const grid = document.getElementById('emoji-grid');
    grid.innerHTML = '';
    if (!emojis || !emojis.length) {
        grid.innerHTML = '<div class="emoji-empty">No results</div>';
        return;
    }
    emojis.forEach(([emoji]) => {
        const btn = document.createElement('button');
        btn.className = 'emoji-cell';
        btn.textContent = emoji;
        btn.onclick = () => insertEmoji(emoji);
        grid.appendChild(btn);
    });
}

function filterEmoji(query) {
    const q = query.trim().toLowerCase();
    const label = document.getElementById('emoji-section-label');

    if (!q) {
        _showEmojiCategory(emojiCurrentCat || '__recent__');
        return;
    }

    label.textContent = 'Search results';
    const results = [];
    Object.values(EMOJI_DATA).forEach(arr => {
        arr.forEach(([emoji, name]) => {
            if (name.includes(q) || emoji.includes(q)) results.push([emoji, name]);
        });
    });
    _renderEmojiGrid(results.slice(0, 60));
}

function insertEmoji(emoji) {
    const input = document.getElementById('msg-input');
    if (!input) return;

    // Insert at cursor position
    const start = input.selectionStart;
    const end   = input.selectionEnd;
    const val   = input.value;
    input.value = val.slice(0, start) + emoji + val.slice(end);

    // Move cursor after the inserted emoji
    const newPos = start + emoji.length;
    input.setSelectionRange(newPos, newPos);
    input.focus();

    // Update UI state
    autoResize(input);
    toggleMicSend();

    // Track in recent
    _addRecentEmoji(emoji);
}

function toggleEmojiPicker() {
    const picker = document.getElementById('emoji-picker');
    const btn    = document.getElementById('emoji-toggle-btn');
    emojiPickerOpen = !emojiPickerOpen;

    if (emojiPickerOpen) {
        picker.style.display = 'flex';
        btn.classList.add('active');
        _initEmojiPicker();
        // On desktop, focus the search; on mobile skip to avoid keyboard stealing space
        const isMobile = window.innerWidth <= 600;
        if (!isMobile) {
            setTimeout(() => {
                const search = document.getElementById('emoji-search');
                if (search) search.focus();
            }, 50);
        }
    } else {
        picker.style.display = 'none';
        btn.classList.remove('active');
        if (window.innerWidth > 600) {
            document.getElementById('msg-input')?.focus();
        }
    }
}

function closeEmojiPicker() {
    if (!emojiPickerOpen) return;
    emojiPickerOpen = false;
    const picker = document.getElementById('emoji-picker');
    const btn    = document.getElementById('emoji-toggle-btn');
    if (picker) picker.style.display = 'none';
    if (btn)    btn.classList.remove('active');
}

// Close picker when clicking/touching outside
document.addEventListener('click', e => {
    if (!emojiPickerOpen) return;
    const picker = document.getElementById('emoji-picker');
    const btn    = document.getElementById('emoji-toggle-btn');
    if (picker && !picker.contains(e.target) && e.target !== btn && !btn?.contains(e.target)) {
        closeEmojiPicker();
    }
}, true);

// Close on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && emojiPickerOpen) closeEmojiPicker();
});

// ─── Sidebar Dashboard Stats ──────────────────────────────────────────────────
async function fetchRoomStats() {
    try {
        const res  = await fetch(`ajax_server.php?action=get_room_stats&room_id=${ROOM_ID}`);
        const data = await res.json();
        if (!data.success) return;
        _animateStatVal('stat-active',     data.active_now);
        _animateStatVal('stat-msgs-hour',  data.msgs_hour);
        _animateStatVal('stat-members',    data.member_count);
        _animateStatVal('stat-total-msgs', data.total_msgs);
        _animateStatVal('stat-rooms',      data.total_rooms);
        _animateStatVal('stat-users',      data.total_users);
        const timeEl = document.getElementById('stat-time');
        if (timeEl) timeEl.textContent = data.server_time;
    } catch(e) {}
}

// ─── Session info: IP + Port display ──────────────────────────────────────────
let _sessionPort = null;
let _sessionIp   = null;

// Fetch IP + port once at startup and populate the conn-bar.
async function fetchSessionInfo() {
    try {
        const res  = await fetch('ajax_server.php?action=get_session_info');
        const data = await res.json();
        if (!data.success) return;
        _sessionPort = data.session_port ? String(data.session_port) : '—';
        _sessionIp   = data.session_ip   || '—';
        _renderConnBar();
    } catch(e) {}
}

function _renderConnBar() {
    const portEl = document.getElementById('conn-port');
    const ipEl   = document.getElementById('conn-ip');
    const wsEl   = document.getElementById('conn-ws-label');
    if (portEl) portEl.textContent = _sessionPort || '…';
    if (ipEl)   ipEl.textContent   = _sessionIp   || '…';
    if (wsEl)   wsEl.textContent   = wsReady ? '· WebSocket ✓' : '· HTTP';
}

// ─── Heartbeat (presence only — no port update) ────────────────────────────────
// Runs every 30 s to keep the user in active_users so the admin dashboard
// shows them as online. Also refreshes the conn-bar WS status label.
// If the server returns banned:true the user was banned mid-session — show
// the ban overlay, kill all connections, and redirect to auth.php.
async function sendHeartbeat() {
    try {
        const res  = await fetch('ajax_server.php?action=heartbeat');
        const data = await res.json();

        // ── Banned mid-session ──────────────────────────────────────────────
        if (data.banned) {
            showBanOverlay(data.message || 'Your account has been banned.');
            return;
        }

        if (data.success) {
            if (data.session_ip   && data.session_ip   !== _sessionIp)   { _sessionIp   = data.session_ip;   }
            if (data.session_port && String(data.session_port) !== _sessionPort) { _sessionPort = String(data.session_port); }
        }
        _renderConnBar();
    } catch(e) {}
}

// ─── Ban overlay — shown when admin bans a logged-in user ─────────────────────
let _banOverlayShown = false;
function showBanOverlay(message) {
    if (_banOverlayShown) return;
    _banOverlayShown = true;

    // Kill everything: polling, WebSocket, heartbeat interval
    stopPolling();
    wsGaveUp = true;
    if (ws) { try { ws.close(); } catch(e) {} ws = null; }

    // Parse reason / expiry out of the message string for nicer display
    const reasonMatch  = message.match(/Reason:\s*([^.]+)\./);
    const expiryMatch  = message.match(/Expires:\s*([^.]+)\./);
    const permanent    = /permanent/i.test(message);
    const reason       = reasonMatch  ? reasonMatch[1].trim()  : null;
    const expiry       = expiryMatch  ? expiryMatch[1].trim()  : null;

    const overlay = document.createElement('div');
    overlay.id = 'ban-overlay';
    overlay.style.cssText = [
        'position:fixed', 'inset:0', 'z-index:99999',
        'background:rgba(4,4,10,.96)', 'backdrop-filter:blur(18px)',
        '-webkit-backdrop-filter:blur(18px)',
        'display:flex', 'flex-direction:column',
        'align-items:center', 'justify-content:center', 'gap:1.4rem',
        'animation:fadein .35s ease', 'padding:2rem',
    ].join(';');

    overlay.innerHTML = `
        <div style="
            width:72px; height:72px; border-radius:20px;
            background:rgba(255,80,80,.12); border:1.5px solid rgba(255,80,80,.3);
            display:flex; align-items:center; justify-content:center; font-size:36px;
            box-shadow:0 0 40px rgba(255,80,80,.15);
        ">🚫</div>

        <div style="text-align:center; max-width:420px;">
            <div style="font-size:1.5rem; font-weight:800; color:#ff6b6b; margin-bottom:.5rem; letter-spacing:-.01em;">
                You have been banned
            </div>
            <div style="font-size:.95rem; color:#8892a4; line-height:1.6;">
                An administrator has removed your access to this server.
            </div>
        </div>

        ${reason ? `
        <div style="
            background:rgba(255,80,80,.07); border:1px solid rgba(255,80,80,.2);
            border-radius:12px; padding:1rem 1.5rem; max-width:380px; width:100%;
            display:flex; flex-direction:column; gap:.4rem;
        ">
            <div style="font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:rgba(255,107,107,.6); font-weight:700;">Reason</div>
            <div style="font-size:.95rem; color:#dde2f0; font-weight:600;">${escHtml(reason)}</div>
        </div>` : ''}

        <div style="
            background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08);
            border-radius:12px; padding:.9rem 1.5rem; max-width:380px; width:100%;
            display:flex; flex-direction:column; gap:.35rem;
        ">
            <div style="font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:rgba(255,255,255,.3); font-weight:700;">Ban Duration</div>
            <div style="font-size:.92rem; color:#dde2f0; font-weight:600;">
                ${expiry
                    ? '⏳ Temporary — expires ' + escHtml(expiry)
                    : permanent
                        ? '⛔ Permanent'
                        : '⛔ Permanent'}
            </div>
        </div>

        <div style="font-size:.8rem; color:#3d4558; margin-top:-.4rem;">
            Signing you out in <span id="ban-countdown">5</span>s…
        </div>
    `;
    document.body.appendChild(overlay);

    // Silent server-side logout then redirect
    const _doLogout = async () => {
        try {
            const fd = new FormData();
            fd.append('action', 'auth_logout');
            await fetch('ajax_server.php', postData(fd));
        } catch(e) {}
        // Redirect to auth page with ban param so the login page shows a message
        const reason_enc = reason ? encodeURIComponent(reason) : '';
        const expiry_enc = expiry ? encodeURIComponent(expiry) : '';
        window.location = 'auth.php?banned=1'
            + (reason_enc ? '&reason=' + reason_enc : '')
            + (expiry_enc ? '&expiry=' + expiry_enc : '')
            + (permanent  ? '&permanent=1' : '');
    };

    let count = 5;
    const timer = setInterval(() => {
        count--;
        const el = document.getElementById('ban-countdown');
        if (el) el.textContent = count;
        if (count <= 0) { clearInterval(timer); _doLogout(); }
    }, 1000);
}

// ─── LAN Info — shows shareable link when running in LAN mode ─────────────────
async function fetchLanInfo() {
    try {
        const res  = await fetch('ajax_server.php?action=get_lan_info');
        const data = await res.json();
        if (!data.success || !data.lan_mode) return; // localhost-only — no banner

        const banner  = document.getElementById('lan-banner');
        const urlEl   = document.getElementById('lan-share-url');
        const setupEl = document.getElementById('lan-setup-link');
        if (!banner || !urlEl) return;

        urlEl.textContent   = data.auth_url;
        urlEl.dataset.url   = data.auth_url;
        if (setupEl) setupEl.href = data.setup_url;
        banner.style.display = 'block';
    } catch(e) {}
}

function copyLanUrl() {
    const el  = document.getElementById('lan-share-url');
    const url = el && el.dataset.url;
    if (!url) return;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(() => {
            const orig = el.textContent;
            el.textContent = '✓ Copied to clipboard!';
            setTimeout(() => { el.textContent = orig; }, 1500);
        });
    }
}

// ─── Toast notification system ────────────────────────────────────────────────
(function() {
    const container = document.createElement('div');
    container.id = 'toast-container';
    document.body.appendChild(container);
})();

function showToast(message, type = 'info', duration = 3200) {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const icons = { success: '✓', error: '✕', info: 'ℹ' };
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `<span style="font-size:1rem;flex-shrink:0">${icons[type] || icons.info}</span><span>${message}</span>`;
    container.appendChild(toast);

    const remove = () => {
        toast.classList.add('hiding');
        toast.addEventListener('animationend', () => toast.remove(), { once: true });
    };
    const timer = setTimeout(remove, duration);
    toast.addEventListener('click', () => { clearTimeout(timer); remove(); });
}

// ─── Animation helpers ────────────────────────────────────────────────────────

// Animate the sidebar stat value when it changes
function _animateStatVal(id, newVal) {
    const el = document.getElementById(id);
    if (!el) return;
    const old = el.textContent;
    if (old === String(newVal)) return;
    el.textContent = newVal;
    el.classList.remove('updated');
    void el.offsetWidth;   // reflow
    el.classList.add('updated');
    setTimeout(() => el.classList.remove('updated'), 450);
}

// Dashboard stat card number bump
function _bumpStatValue(el) {
    if (!el) return;
    el.classList.remove('bump');
    void el.offsetWidth;
    el.classList.add('bump');
    setTimeout(() => el.classList.remove('bump'), 400);
}

// Flash the green double-tick with a pop animation
function _popTick(tickEl) {
    if (!tickEl) return;
    tickEl.classList.add('all-read');
    setTimeout(() => tickEl.classList.remove('all-read'), 400);
}

// Avatar send glow — fires when user sends a message
function _glowSenderAvatar(msgEl) {
    if (!msgEl) return;
    const av = msgEl.querySelector('.avatar.small');
    if (!av) return;
    av.classList.add('sending-glow');
    setTimeout(() => av.classList.remove('sending-glow'), 650);
}

// ─── Init ─────────────────────────────────────────────────────────────────────
initReadObserver();
_initScrollSentinel();   // auto-load older messages on scroll to top
fetchMessages(true).then(() => {
    fetch(`ajax_server.php?action=room_members&room_id=${ROOM_ID}`)
        .then(r => r.json())
        .then(d => {
            if (d.success) document.getElementById('members-count').textContent = `${d.members.length} members`;
        });
});
connectWS();

// Avatar sync fallback — keep avatars current even if WebSocket delivery is delayed or unavailable
syncRoomAvatars();
roomAvatarSyncTimer = setInterval(syncRoomAvatars, 30000); // avatars change rarely — 30 s is enough

// Sidebar stats — fetch immediately then every 30 s
fetchRoomStats();
setInterval(fetchRoomStats, 30000);

// Session port — fetch once on load, display permanently for this tab session
fetchSessionInfo();

// LAN info — fetch once; shows shareable link in sidebar if running on LAN
fetchLanInfo();

// Presence heartbeat — keeps user visible in admin dashboard every 30 s
setInterval(sendHeartbeat, 30000);

// Check membership independently every 2s — runs even if fetchMessages fails
// This is critical: when user is removed, get_messages returns "Not a member"
// error which kills fetchMessages, so membership check must be separate
setInterval(checkMembership, 2000);

// Close WS cleanly when user navigates away (prevents ghost reconnect attempts)
window.addEventListener('beforeunload', closeWSGracefully);
window.addEventListener('pagehide', closeWSGracefully);

// ── Virtual keyboard handling (iOS / Android) ──────────────────────────────────
// Fires when the soft keyboard appears/disappears — keep scroll at bottom
// and close emoji picker which would be obscured by the keyboard.
if (window.visualViewport) {
    let _lastVpH = window.visualViewport.height;
    window.visualViewport.addEventListener('resize', () => {
        const h      = window.visualViewport.height;
        const shrunk = h < _lastVpH - 80;   // keyboard opened
        _lastVpH     = h;
        if (shrunk) {
            closeEmojiPicker();
            setTimeout(() => { if (isAtBottom()) scrollToBottom(); }, 120);
        }
    });
}

// Poll read status for sent messages every 4 seconds
setInterval(pollReadStatus, 4000);

// Ping is now managed by startPing()/stopPing() — started on WS connect

// Color picker for auth page (if present)
if (typeof COLORS !== 'undefined') {
    const cp = document.getElementById('color-picker');
    if (cp) {
        cp.querySelectorAll('.swatch').forEach(el => {
            el.addEventListener('click', function() {
                cp.querySelectorAll('.swatch').forEach(s => s.classList.remove('on'));
                this.classList.add('on');
                const inp = document.getElementById('avatar-color');
                if (inp) inp.value = this.dataset.color;
            });
        });
    }
}