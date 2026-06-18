<?php
/**
 * _admin_bar.php — Floating admin navigation bar
 *
 * Include this file on any authenticated page.
 * It renders nothing for non-admin users.
 *
 * Usage:
 *   <?php $user = currentUser(); include __DIR__ . '/_admin_bar.php'; ?>
 *
 * The $user variable must be set before including this file.
 * config.php must already be required.
 */
if (!isset($user) || $user['username'] !== ADMIN_USERNAME) return;
$adminAvatarSrc = resolveAvatarSrc($user['avatar_url'] ?? null);

// Determine the current page so we can highlight the active link
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<style>
/* ── Admin floating bar ──────────────────────────────────────────────────────── */
#admin-bar {
    position: fixed;
    bottom: 1.2rem;
    right: 1.2rem;
    z-index: 9999;
    display: flex;
    align-items: center;
    gap: .45rem;
    background: rgba(15, 15, 30, 0.92);
    border: 1px solid rgba(0, 212, 170, 0.35);
    border-radius: 50px;
    padding: .45rem .7rem;
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    box-shadow: 0 4px 24px rgba(0,0,0,.5), 0 0 0 1px rgba(0,212,170,.08);
    font-size: .78rem;
    font-weight: 600;
    transition: opacity .2s, transform .2s;
    user-select: none;
}
#admin-bar:hover { opacity: 1 !important; }

/* Subtle fade when not hovered so it doesn't distract from content */
#admin-bar { opacity: .82; }

.ab-label {
    color: rgba(0, 212, 170, 0.7);
    font-size: .65rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    padding: 0 .3rem 0 .15rem;
    border-right: 1px solid rgba(255,255,255,.08);
    margin-right: .1rem;
    white-space: nowrap;
}
.ab-link {
    display: flex;
    align-items: center;
    gap: .3rem;
    padding: .35rem .7rem;
    border-radius: 30px;
    text-decoration: none;
    color: var(--text-muted, #aaa);
    font-size: .78rem;
    font-weight: 600;
    transition: background .15s, color .15s;
    white-space: nowrap;
}
.ab-link:hover {
    background: rgba(255,255,255,.07);
    color: #fff;
}
.ab-link.ab-active {
    background: rgba(0, 212, 170, 0.15);
    color: #00d4aa;
    border: 1px solid rgba(0, 212, 170, 0.25);
}
.ab-sep {
    width: 1px;
    height: 16px;
    background: rgba(255,255,255,.08);
    flex-shrink: 0;
}
.ab-dismiss {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: transparent;
    border: none;
    color: rgba(255,255,255,.3);
    cursor: pointer;
    font-size: .8rem;
    line-height: 1;
    padding: 0;
    margin-left: .1rem;
    transition: color .15s, background .15s;
}
.ab-dismiss:hover { color: #ff6b6b; background: rgba(255,107,107,.1); }

#admin-bar-avatar {
    width: 24px;
    height: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 24px;
    overflow: hidden;
    border-radius: 50%;
    background: rgba(255,255,255,.05);
}
.admin-avatar-img {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    object-fit: cover;
    display: block;
    flex-shrink: 0;
}
.ab-admin-avatar-fallback {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    font-size: .65rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

/* Collapsed state — shows just the ⚙ toggle pill */
#admin-bar.ab-collapsed .ab-label,
#admin-bar.ab-collapsed .ab-link,
#admin-bar.ab-collapsed .ab-sep {
    display: none;
}
#admin-bar.ab-collapsed {
    padding: .45rem .7rem;
    opacity: .6;
}
#admin-bar.ab-collapsed:hover { opacity: 1; }
.ab-toggle {
    display: flex;
    align-items: center;
    gap: .3rem;
    background: transparent;
    border: none;
    color: rgba(0, 212, 170, 0.8);
    cursor: pointer;
    font-size: .8rem;
    font-weight: 700;
    padding: 0 .15rem;
    transition: color .15s;
}
.ab-toggle:hover { color: #00d4aa; }

/* ── Mobile responsive admin bar ─────────────────────────────────────────── */
@media (max-width: 640px) {
    #admin-bar {
        /* Pin bar within viewport — never overflow the screen edges */
        bottom: .75rem;
        left: .75rem;
        right: .75rem;
        width: auto;
        max-width: calc(100vw - 1.5rem);
        border-radius: 18px;
        padding: .4rem .65rem;
        gap: .3rem;
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        /* Smooth collapse/expand */
        transition: left .2s ease, opacity .2s, transform .2s;
    }
    #admin-bar::-webkit-scrollbar { display: none; }

    .ab-label { display: none; }
    .ab-link { padding: .3rem .5rem; }

    /* When collapsed on mobile — shrink back to a small right-anchored pill */
    #admin-bar.ab-collapsed {
        left: auto;           /* release left anchor */
        right: .75rem;        /* stay pinned to right */
        width: auto;          /* shrink to content */
        max-width: none;
        overflow-x: visible;
        border-radius: 50px;
    }
    /* Also hide avatar in collapsed state */
    #admin-bar.ab-collapsed #admin-bar-avatar { display: none; }
}

@media (max-width: 380px) {
    /* On very narrow phones only show icons, hide link text */
    .ab-link span:last-child { display: none; }
    .ab-sep { display: none; }
    #admin-bar { gap: .2rem; padding: .35rem .5rem; }
}
</style>

<div id="admin-bar">
    <span class="ab-label">⚙ Admin</span>

    <div id="admin-bar-avatar" style="display:inline-flex;align-items:center;justify-content:center">
        <?php if ($adminAvatarSrc): ?>
            <img src="<?= escHtml($adminAvatarSrc) ?>" class="avatar avatar-img admin-avatar-img" alt="<?= escHtml($user['username']) ?>">
        <?php else: ?>
            <div class="avatar ab-admin-avatar-fallback" style="background:<?= escHtml($user['avatar_color']) ?>"><?= escHtml(strtoupper(substr($user['username'],0,2))) ?></div>
        <?php endif; ?>
    </div>

    <?php if ($currentPage !== 'rooms.php'): ?>
    <a href="rooms.php" class="ab-link<?= $currentPage === 'rooms.php' ? ' ab-active' : '' ?>">
        <span>🏠</span><span>Rooms</span>
    </a>
    <div class="ab-sep"></div>
    <?php endif; ?>

    <a href="network_dashboard.php"
       class="ab-link<?= $currentPage === 'network_dashboard.php' ? ' ab-active' : '' ?>">
        <span>📡</span><span>Dashboard</span>
    </a>

    <div class="ab-sep"></div>

    <a href="lan_setup.php"
       class="ab-link<?= $currentPage === 'lan_setup.php' ? ' ab-active' : '' ?>">
        <span>🔗</span><span>LAN Setup</span>
    </a>

    <div class="ab-sep"></div>

    <button class="ab-toggle" onclick="toggleAdminBar()" id="ab-toggle-btn" title="Collapse">‹‹</button>
    <button class="ab-dismiss" onclick="hideAdminBar()" title="Hide (refresh to restore)">✕</button>
</div>

<script>
(function () {
    const bar       = document.getElementById('admin-bar');
    const toggleBtn = document.getElementById('ab-toggle-btn');
    const SK        = 'nexus_ab_collapsed';

    // Restore collapsed state across pages
    if (sessionStorage.getItem(SK) === '1') collapse(false);

    function collapse(save = true) {
        bar.classList.add('ab-collapsed');
        toggleBtn.textContent = '≫';
        toggleBtn.title = 'Expand admin bar';
        if (save) sessionStorage.setItem(SK, '1');
    }
    function expand(save = true) {
        bar.classList.remove('ab-collapsed');
        toggleBtn.textContent = '‹‹';
        toggleBtn.title = 'Collapse admin bar';
        if (save) sessionStorage.removeItem(SK);
    }

    window.toggleAdminBar = function () {
        bar.classList.contains('ab-collapsed') ? expand() : collapse();
    };
    window.hideAdminBar = function () {
        bar.style.display = 'none';
    };
})();
</script>
