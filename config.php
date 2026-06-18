<?php
// config.php — NexusChat Configuration

// ─── Database ─────────────────────────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'chatapp');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

// ─── LAN / Host Detection ─────────────────────────────────────────────────────
// Auto-detects the server's LAN IP so other devices on the same Wi-Fi /
// Ethernet can connect without any manual configuration.
// Override by setting LAN_HOST_OVERRIDE to your IP, e.g. '192.168.1.10'.
// Leave as '' for auto-detection (recommended).
define('LAN_HOST_OVERRIDE', '');

function detectLanIp() {
    if (LAN_HOST_OVERRIDE !== '') return LAN_HOST_OVERRIDE;

    // Use the hostname the browser sent — correct for cross-device LAN access
    if (!empty($_SERVER['HTTP_HOST'])) {
        $ip = explode(':', $_SERVER['HTTP_HOST'])[0];
        if (filter_var($ip, FILTER_VALIDATE_IP) &&
            !in_array($ip, ['127.0.0.1', '::1'], true)) {
            return $ip;
        }
    }

    // Fall back to resolving the machine's hostname
    $hostname = gethostname();
    if ($hostname) {
        $ip = gethostbyname($hostname);
        if (filter_var($ip, FILTER_VALIDATE_IP) &&
            !in_array($ip, ['127.0.0.1', '::1'], true)) {
            return $ip;
        }
    }

    return 'localhost';
}

// ─── WebSocket ────────────────────────────────────────────────────────────────
define('WS_HOST',        '0.0.0.0');        // listen on ALL interfaces
define('WS_PORT',        8080);
define('WS_PUBLIC_HOST', detectLanIp());    // auto-detected LAN IP for browsers

// ─── App base URL ─────────────────────────────────────────────────────────────
function appBaseUrl() {
    $ip   = detectLanIp();
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return 'http://' . $ip . $path;
}

// ─── App ──────────────────────────────────────────────────────────────────────
define('APP_NAME',           'NexusChat');
define('SESSION_LIFETIME',   86400);
define('MAX_MESSAGE_LENGTH', 2000);
define('MESSAGES_PER_PAGE',  50);
define('BCRYPT_COST',        10);
define('DELETE_FOR_EVERYONE_WINDOW_SECONDS', 120);
define('ROOM_ADMIN_DELETE_FOR_EVERYONE_WINDOW_SECONDS', 86400);
define('EDIT_MESSAGE_WINDOW_SECONDS', 60);

// ─── Admin ────────────────────────────────────────────────────────────────────
// Set this to your own username to get Network Dashboard access.
define('ADMIN_USERNAME', 'admin');

// ─── Profile picture (avatar) ────────────────────────────────────────────────
define('AVATAR_DIR',          __DIR__ . '/uploads/avatars/');
define('AVATAR_MAX_BYTES',    5 * 1024 * 1024);
define('AVATAR_ALLOWED_MIME', serialize(['image/jpeg','image/png','image/gif','image/webp']));

// ─── Uploads ──────────────────────────────────────────────────────────────────
define('UPLOAD_DIR',      __DIR__ . '/uploads/');
define('UPLOAD_MAX_BYTES', 10 * 1024 * 1024 * 1024);
define('UPLOAD_ALLOWED_MIME', serialize([
    'image/jpeg','image/png','image/gif','image/webp','image/svg+xml',
    'video/mp4','video/webm','video/ogg',
    'audio/mpeg','audio/ogg','audio/wav','audio/webm',
    'audio/mp4','audio/x-m4a','audio/aac','audio/mp4a-latm',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/zip',
    'application/x-rar-compressed','application/vnd.rar',
    'text/plain','text/csv',
]));

// ─── Allowed avatar colours ────────────────────────────────────────────────────
define('ALLOWED_COLORS', implode(',', [
    '#00d4aa','#4f8ef7','#f7706a','#f5a623',
    '#9b7ff5','#3dd68c','#f093fb','#00b4d8',
    '#ff6584','#43e97b',
]));

// ─── DB Connection ────────────────────────────────────────────────────────────
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ─── Session ──────────────────────────────────────────────────────────────────
function initSession() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_samesite', 'Lax');
        // Enable secure flag only over HTTPS — avoids breaking LAN HTTP deployments
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', 1);
        }
        session_start();
    }
}

// ─── Auth Helpers ─────────────────────────────────────────────────────────────
function isLoggedIn() {
    initSession();
    return !empty($_SESSION['user_id']) && !empty($_SESSION['username']);
}

function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: auth.php');
        exit;
    }
}

function currentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id'           => $_SESSION['user_id'],
        'username'     => $_SESSION['username'],
        'email'        => $_SESSION['email']         ?? '',
        'avatar_color' => $_SESSION['avatar_color']  ?? '#6c63ff',
        'avatar_url'   => $_SESSION['avatar_url']    ?? null,
    ];
}

/** Custom uploaded avatar only. */
function resolveAvatarSrc($avatarUrl) {
    if ($avatarUrl) return 'uploads/avatars/' . basename($avatarUrl);
    return null;
}

// ─── CSRF ─────────────────────────────────────────────────────────────────────
function getCsrfToken() {
    initSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf() {
    initSession();
    $expected = $_SESSION['csrf_token'] ?? '';
    $incoming = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!$expected || !hash_equals($expected, $incoming)) {
        jsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
    }
}

// ─── Avatar colour validation ─────────────────────────────────────────────────
function sanitizeColor($color) {
    $allowed = explode(',', ALLOWED_COLORS);
    return in_array($color, $allowed, true) ? $color : '#6c63ff';
}

// ─── Admin check ──────────────────────────────────────────────────────────────
function requireAdmin($user) {
    if (!$user || $user['username'] !== ADMIN_USERNAME) {
        jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
    }
}

// ─── Ban check ────────────────────────────────────────────────────────────────
// Returns the active ban row (if any) for the given user_id or IP, or false.
function getActiveBan($db, $userId, $ip) {
    // Auto-expire first
    $db->exec("UPDATE bans SET active = 0
               WHERE active = 1 AND expires_at IS NOT NULL AND expires_at < NOW()");
    $stmt = $db->prepare(
        "SELECT * FROM bans
         WHERE active = 1
           AND (user_id = ? OR (ip IS NOT NULL AND ip = ?))
         LIMIT 1"
    );
    $stmt->execute([$userId, $ip]);
    return $stmt->fetch();
}

// ─── Audit log helper ─────────────────────────────────────────────────────────
function auditLog($db, $actor, $action, $targetUser = null, $targetRoom = null, $detail = null) {
    $ip = function_exists('clientIp') ? clientIp() : ($_SERVER['REMOTE_ADDR'] ?? null);
    $db->prepare(
        "INSERT INTO audit_log (actor, action, target_user, target_room, detail, ip)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$actor, $action, $targetUser, $targetRoom, $detail, $ip]);
}

// ─── Bandwidth tracking helper ────────────────────────────────────────────────
// Adds $bytesIn / $bytesOut to the current 1-minute bucket in bandwidth_log.
// Called from API endpoints with approximate byte counts.
function _bwTrack($db, $bytesIn, $bytesOut) {
    if ($bytesIn == 0 && $bytesOut == 0) return;
    try {
        $bucket = date('Y-m-d H:i:00');
        // Compatible MySQL syntax for all versions
        $db->prepare(
            "INSERT INTO bandwidth_log (bucket, bytes_in, bytes_out)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 bytes_in  = bytes_in  + VALUES(bytes_in),
                 bytes_out = bytes_out + VALUES(bytes_out)"
        )->execute([$bucket, (int)$bytesIn, (int)$bytesOut]);
    } catch (Exception $e) {
        // Log the error for debugging
        error_log("_bwTrack error: " . $e->getMessage());
    }
}

// ─── Output Helpers ───────────────────────────────────────────────────────────
function jsonResponse($data, $code = 200) {
    if (ob_get_level()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $body = json_encode($data);

    // ── Auto bandwidth tracking ───────────────────────────────────────────────
    // Only selected chat actions set _bw_track_request; admin/dashboard polling is ignored.
    try {
        if (!empty($GLOBALS['_bw_track_request'])) {
            $bwDb = getDB();
            $bytesIn  = isset($GLOBALS['_bw_bytes_in'])  ? (int)$GLOBALS['_bw_bytes_in']  : 0;
            $bytesOut = strlen($body) + 200; // body + approx HTTP headers
            _bwTrack($bwDb, $bytesIn, $bytesOut);
        }
    } catch (Exception $e) { /* bandwidth tracking is best-effort */ }

    echo $body;
    exit;
}

function escHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
