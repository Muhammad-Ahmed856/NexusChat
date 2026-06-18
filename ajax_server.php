<?php
// ajax_server.php - NexusChat API

// ── Inbound byte count — captured immediately before anything else runs ────────
// CONTENT_LENGTH covers POST body. For GET requests we measure the query string.
// Stored in $GLOBALS so jsonResponse() in config.php can read it without params.
$GLOBALS['_bw_bytes_in'] = isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0
    ? (int)$_SERVER['CONTENT_LENGTH']
    : strlen($_SERVER['QUERY_STRING'] ?? '');

// Catch ALL errors and return them as JSON so the UI never shows "Network error"
set_exception_handler(function($e) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('success' => false, 'error' => $e->getMessage()));
    exit;
});
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, $errno, $errno, $errfile, $errline);
});

require_once __DIR__ . '/config.php';

initSession();
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

// Set JSON content type for all actions EXCEPT serve_file (which streams binary)
if ($action !== 'serve_file') {
    header('Content-Type: application/json; charset=utf-8');
}
$user   = currentUser();

// Only real chat traffic should count toward the bandwidth dashboard.
// get_messages is enabled later only when it actually returns messages,
// so empty polling responses do not inflate BW totals.
$GLOBALS['_bw_track_request'] = in_array($action, array('send_message'), true);

// Public actions (no auth, no CSRF check)
$publicActions = array('auth_register', 'auth_login', 'auth_status');

if (!in_array($action, $publicActions) && !isLoggedIn()) {
    jsonResponse(array('success' => false, 'error' => 'Not authenticated'), 401);
}

// ── CSRF: all state-changing (non-GET, non-public) actions must carry a token ──
$csrfExempt = array_merge($publicActions, array(
    'auth_status', 'list_rooms', 'get_messages', 'room_members',
    'get_reactions', 'check_membership', 'read_status',
    'network_stats', 'packet_log', 'list_bans', 'get_audit_log', 'bandwidth_stats',
    'serve_file', 'get_room_stats',
    'heartbeat',
    'get_session_info',   // returns the fixed session port assigned at login
    'get_lan_info',       // returns LAN IP + URLs for the connection banner
));
if (!in_array($action, $csrfExempt)) {
    verifyCsrf();
}

// DB connection
try {
    $db = getDB();
} catch (Exception $e) {
    jsonResponse(array('success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()));
}


// ─── Helpers ──────────────────────────────────────────────────────────────────
function clientIp() {
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
}
function clientPort() {
    $p = isset($_SERVER['REMOTE_PORT']) ? (int)$_SERVER['REMOTE_PORT'] : null;
    return ($p && $p > 0 && $p < 65536) ? $p : null;
}

// ─── Router ───────────────────────────────────────────────────────────────────
switch ($action) {

    // ── Auth: Register ────────────────────────────────────────────────────────
    case 'auth_register':
        $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $email    = trim(isset($_POST['email']) ? $_POST['email'] : '');
        // FIX: sanitize avatar color against whitelist
        $color    = sanitizeColor(isset($_POST['avatar_color']) ? $_POST['avatar_color'] : '');
        $email    = $email === '' ? null : $email;

        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL))
            jsonResponse(array('success' => false, 'error' => 'Invalid email address'));
        if (strlen($username) < 3 || strlen($username) > 50)
            jsonResponse(array('success' => false, 'error' => 'Username must be 3-50 characters'));
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username))
            jsonResponse(array('success' => false, 'error' => 'Username: letters, numbers, _ and - only'));
        if (strlen($password) < 6)
            jsonResponse(array('success' => false, 'error' => 'Password must be at least 6 characters'));

        // Rate-limit registrations per IP (max 5 per hour).
        // Log the attempt FIRST so every request counts — including probes that
        // fail the duplicate-username check (username enumeration prevention).
        $db->prepare("INSERT INTO login_attempts (ip, action, ts) VALUES (?, 'register', NOW())")->execute(array(clientIp()));

        $regAttempts = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND action = 'register' AND ts > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $regAttempts->execute(array(clientIp()));
        if ((int)$regAttempts->fetchColumn() > 5)
            jsonResponse(array('success' => false, 'error' => 'Too many registrations from your IP. Try again later.'), 429);

        // Check duplicate
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute(array($username));
        if ($stmt->fetch())
            jsonResponse(array('success' => false, 'error' => 'Username already taken'));

        $hash = password_hash($password, PASSWORD_BCRYPT, array('cost' => BCRYPT_COST));

        $stmt = $db->prepare("INSERT INTO users (username, password_hash, email, avatar_color) VALUES (?, ?, ?, ?)");
        $stmt->execute(array($username, $hash, $email, $color));
        $userId = (int)$db->lastInsertId();

        // Regenerate session ID after privilege change (prevents session fixation)
        session_regenerate_id(true);

        $_SESSION['user_id']      = $userId;
        $_SESSION['username']     = $username;
        $_SESSION['email']        = $email;
        $_SESSION['avatar_color'] = $color;
        // Capture the TCP port at the moment of login — this is the user's
        // "session port" shown in the conn-bar for the lifetime of this tab.
        $_SESSION['session_port'] = clientPort();
        $_SESSION['session_ip']   = clientIp();

        jsonResponse(array('success' => true, 'username' => $username, 'csrf_token' => getCsrfToken()));

    // ── Auth: Login ───────────────────────────────────────────────────────────
    case 'auth_login':
        $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        // FIX: rate-limit login attempts per IP (max 10 per 15 minutes)
        $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND action = 'login' AND ts > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt->execute(array(clientIp()));
        if ((int)$stmt->fetchColumn() >= 10)
            jsonResponse(array('success' => false, 'error' => 'Too many login attempts. Wait 15 minutes and try again.'), 429);

        $stmt = $db->prepare("SELECT id, username, password_hash, email, avatar_color, avatar_url FROM users WHERE username = ?");
        $stmt->execute(array($username));
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            // Record failed attempt regardless of whether username exists
            $db->prepare("INSERT INTO login_attempts (ip, action, ts) VALUES (?, 'login', NOW())")->execute(array(clientIp()));
            jsonResponse(array('success' => false, 'error' => 'Invalid username or password'));
        }

        // Check if this user or their IP is banned
        $ban = getActiveBan($db, $row['id'], clientIp());
        if ($ban) {
            $msg = 'Your account has been banned.';
            if ($ban['reason'])     $msg .= ' Reason: ' . $ban['reason'] . '.';
            if ($ban['expires_at']) $msg .= ' Expires: ' . $ban['expires_at'] . '.';
            jsonResponse(array('success' => false, 'error' => $msg), 403);
        }

        $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute(array($row['id']));

        // Log successful login in login_attempts so the rate-limit window is accurate
        // (an attacker who succeeds on attempt N still counts toward future lockouts)
        $db->prepare("INSERT INTO login_attempts (ip, action, ts) VALUES (?, 'login', NOW())")->execute(array(clientIp()));

        auditLog($db, $username, 'login', null, null, 'Login from ' . clientIp());
        // FIX: regenerate session ID after login (session fixation prevention)
        session_regenerate_id(true);

        $_SESSION['user_id']       = $row['id'];
        $_SESSION['username']      = $row['username'];
        $_SESSION['email']         = $row['email'];
        $_SESSION['avatar_color']  = $row['avatar_color'];
        $_SESSION['avatar_url']    = $row['avatar_url'];
        // Capture the TCP port at the moment of login — this is the user's
        // "session port" shown in the conn-bar for the lifetime of this tab.
        $_SESSION['session_port'] = clientPort();
        $_SESSION['session_ip']   = clientIp();

        // Track presence on login
        $ip   = clientIp();
        $port = clientPort();
        $db->prepare("INSERT INTO active_users (username, ts, ip, port) VALUES (?, NOW(), ?, ?) ON DUPLICATE KEY UPDATE ts = NOW(), ip = ?, port = ?")
           ->execute(array($row['username'], $ip, $port, $ip, $port));

        jsonResponse(array('success' => true, 'username' => $row['username'], 'avatar_color' => $row['avatar_color'], 'csrf_token' => getCsrfToken()));

    // ── Auth: Logout ──────────────────────────────────────────────────────────
    case 'auth_logout':
        $db->prepare("DELETE FROM active_users WHERE username = ?")->execute(array($user['username']));
        session_destroy();
        jsonResponse(array('success' => true));

    // ── Auth: Status ──────────────────────────────────────────────────────────
    case 'auth_status':
        if ($user)
            jsonResponse(array('success' => true, 'logged_in' => true, 'user' => $user));
        jsonResponse(array('success' => true, 'logged_in' => false));

    // ── User: Update ──────────────────────────────────────────────────────────
    case 'user_update':
        $fields = array();
        $params = array();

        $newUsername = trim(isset($_POST['username']) ? $_POST['username'] : '');
        $newEmail    = trim(isset($_POST['email']) ? $_POST['email'] : '');
        $newPassword = isset($_POST['password']) ? $_POST['password'] : '';
        // FIX: sanitize avatar color against whitelist
        $newColor    = isset($_POST['avatar_color']) && $_POST['avatar_color'] !== ''
                       ? sanitizeColor($_POST['avatar_color']) : '';

        if ($newUsername && $newUsername !== $user['username']) {
            if (!preg_match('/^[a-zA-Z0-9_\-]{3,50}$/', $newUsername))
                jsonResponse(array('success' => false, 'error' => 'Invalid username format'));
            $fields[] = 'username = ?';
            $params[] = $newUsername;
        }
        if ($newEmail !== '') {
            if ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL))
                jsonResponse(array('success' => false, 'error' => 'Invalid email address'));
            $fields[] = 'email = ?';
            $params[] = $newEmail ?: null;
        }
        if ($newPassword) {
            if (strlen($newPassword) < 6)
                jsonResponse(array('success' => false, 'error' => 'Password too short'));
            $fields[] = 'password_hash = ?';
            $params[] = password_hash($newPassword, PASSWORD_BCRYPT, array('cost' => BCRYPT_COST));
        }
        if ($newColor) {
            $fields[] = 'avatar_color = ?';
            $params[] = $newColor;
        }

        if (empty($fields)) jsonResponse(array('success' => false, 'error' => 'Nothing to update'));

        $params[] = $user['id'];
        $db->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

        if ($newUsername) $_SESSION['username'] = $newUsername;
        if ($newEmail !== '') $_SESSION['email'] = $newEmail;
        if ($newColor) $_SESSION['avatar_color'] = $newColor;

        jsonResponse(array('success' => true));

    // ── User: Delete ──────────────────────────────────────────────────────────
    case 'user_delete':
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute(array($user['id']));
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash']))
            jsonResponse(array('success' => false, 'error' => 'Incorrect password'));

        $db->prepare("DELETE FROM users WHERE id = ?")->execute(array($user['id']));
        session_destroy();
        jsonResponse(array('success' => true));

    // ── Rooms: List ───────────────────────────────────────────────────────────
    case 'list_rooms':
        $stmt = $db->prepare("
            SELECT r.id, r.name, r.type, r.created_by,
                   COALESCE(u.username, 'Deleted') AS creator,
                   (SELECT COUNT(*) FROM room_members rm WHERE rm.room_id = r.id) AS member_count,
                   (SELECT COUNT(*) FROM messages m WHERE m.room_id = r.id) AS message_count,
                   (r.room_password IS NOT NULL) AS has_password,
                   EXISTS(SELECT 1 FROM room_members rm2 WHERE rm2.room_id = r.id AND rm2.user_id = ?) AS is_member,
                   (
                       SELECT COUNT(*)
                       FROM messages m2
                       WHERE m2.room_id = r.id
                         AND m2.user_id != ?
                                                 AND m2.deleted_for_everyone = 0
                                                 AND NOT EXISTS (
                                                         SELECT 1
                                                         FROM deleted_messages dm
                                                         WHERE dm.message_id = m2.id
                                                             AND dm.user_id = ?
                                                 )
                         AND EXISTS (
                             SELECT 1
                             FROM room_members rm3
                             WHERE rm3.room_id = r.id
                               AND rm3.user_id = ?
                               AND m2.timestamp >= rm3.joined_at
                         )
                         AND NOT EXISTS (
                             SELECT 1
                             FROM message_status ms
                             WHERE ms.message_id = m2.id
                               AND ms.username = ?
                               AND ms.read_at IS NOT NULL
                         )
                   ) AS unread_count
            FROM rooms r
            LEFT JOIN users u ON u.id = r.created_by
            ORDER BY r.name
        ");
        $stmt->execute(array($user['id'], $user['id'], $user['id'], $user['id'], $user['username']));
        jsonResponse(array('success' => true, 'rooms' => $stmt->fetchAll()));

    // ── Rooms: Create ─────────────────────────────────────────────────────────
    case 'create_room':
        $name     = trim(isset($_POST['name']) ? $_POST['name'] : '');
        $type     = isset($_POST['type']) ? $_POST['type'] : 'public';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        if (strlen($name) < 2 || strlen($name) > 100)
            jsonResponse(array('success' => false, 'error' => 'Room name must be 2-100 characters'));
        if (!in_array($type, array('public', 'private')))
            jsonResponse(array('success' => false, 'error' => 'Invalid room type'));

        $passHash = null;
        if ($type === 'private' && $password)
            $passHash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $db->prepare("INSERT INTO rooms (name, type, created_by, room_password) VALUES (?, ?, ?, ?)");
        $stmt->execute(array($name, $type, $user['id'], $passHash));
        $roomId = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO room_members (room_id, user_id) VALUES (?, ?)")->execute(array($roomId, $user['id']));
        jsonResponse(array('success' => true, 'room_id' => $roomId, 'name' => $name));

    // ── Rooms: Join ───────────────────────────────────────────────────────────
    case 'join_room':
        $roomId   = (int)(isset($_POST['room_id']) ? $_POST['room_id'] : 0);
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        $stmt = $db->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute(array($roomId));
        $room = $stmt->fetch();
        if (!$room) jsonResponse(array('success' => false, 'error' => 'Room not found'));

        $stmt = $db->prepare("SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?");
        $stmt->execute(array($roomId, $user['id']));
        if ($stmt->fetch()) jsonResponse(array('success' => true, 'already_member' => true));

        if ($room['room_password'] && !password_verify($password, $room['room_password']))
            jsonResponse(array('success' => false, 'error' => 'Incorrect room password', 'needs_password' => true));

        $db->prepare("INSERT INTO room_members (room_id, user_id) VALUES (?, ?)")->execute(array($roomId, $user['id']));
        jsonResponse(array('success' => true, 'room' => $room));

    // ── Rooms: Leave ──────────────────────────────────────────────────────────
    case 'leave_room':
        $roomId = (int)(isset($_POST['room_id']) ? $_POST['room_id'] : 0);
        $stmt = $db->prepare("SELECT created_by FROM rooms WHERE id = ?");
        $stmt->execute(array($roomId));
        $room = $stmt->fetch();
        if (!$room) jsonResponse(array('success' => false, 'error' => 'Room not found'));
        if ((int)$room['created_by'] === (int)$user['id'])
            jsonResponse(array('success' => false, 'error' => 'Room creator cannot leave'));

        $db->prepare("DELETE FROM room_members WHERE room_id = ? AND user_id = ?")->execute(array($roomId, $user['id']));
        jsonResponse(array('success' => true));

    // ── Rooms: Members ────────────────────────────────────────────────────────
    case 'room_members':
        $roomId = (int)(isset($_GET['room_id']) ? $_GET['room_id'] : 0);
        $stmt = $db->prepare("
            SELECT u.id, u.username, u.avatar_color, u.avatar_url,
                   (SELECT ts FROM active_users WHERE username = u.username) AS last_seen,
                   rm.joined_at,
                   (r.created_by = u.id) AS is_creator
            FROM room_members rm
            JOIN users u ON u.id = rm.user_id
            JOIN rooms r ON r.id = rm.room_id
            WHERE rm.room_id = ?
            ORDER BY is_creator DESC, u.username
        ");
        $stmt->execute(array($roomId));
        jsonResponse(array('success' => true, 'members' => $stmt->fetchAll()));

    // ── Rooms: Remove Member ──────────────────────────────────────────────────
    case 'remove_member':
        $roomId   = (int)(isset($_POST['room_id']) ? $_POST['room_id'] : 0);
        $targetId = (int)(isset($_POST['user_id']) ? $_POST['user_id'] : 0);

        $stmt = $db->prepare("SELECT created_by FROM rooms WHERE id = ?");
        $stmt->execute(array($roomId));
        $room = $stmt->fetch();
        if (!$room || (int)$room['created_by'] !== (int)$user['id'])
            jsonResponse(array('success' => false, 'error' => 'Not authorized'));

        // Get target username before deleting
        $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute(array($targetId));
        $target = $stmt->fetch();

        $db->prepare("DELETE FROM room_members WHERE room_id = ? AND user_id = ?")->execute(array($roomId, $targetId));

        // Record the removal so the client can detect it on next poll
        if ($target) {
            $db->prepare("INSERT IGNORE INTO removed_members (room_id, user_id, username, removed_at)
                          VALUES (?, ?, ?, NOW())")
               ->execute(array($roomId, $targetId, $target['username']));
            // Get room name for audit log
            $roomName = $db->prepare("SELECT name FROM rooms WHERE id = ?");
            $roomName->execute([$roomId]);
            $rn = ($roomName->fetchColumn()) ?: "room #{$roomId}";
            auditLog($db, $user['username'], 'kick_member', $target['username'], $rn,
                     'Kicked from room');
        }

        jsonResponse(array('success' => true));

    // ── Rooms: Change Password ────────────────────────────────────────────────
    case 'change_room_password':
        $roomId      = (int)(isset($_POST['room_id']) ? $_POST['room_id'] : 0);
        $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';

        $stmt = $db->prepare("SELECT created_by FROM rooms WHERE id = ?");
        $stmt->execute(array($roomId));
        $room = $stmt->fetch();
        if (!$room || (int)$room['created_by'] !== (int)$user['id'])
            jsonResponse(array('success' => false, 'error' => 'Not authorized'));

        $passHash = $newPassword ? password_hash($newPassword, PASSWORD_BCRYPT) : null;
        $db->prepare("UPDATE rooms SET room_password = ?, password_changed_at = NOW() WHERE id = ?")
           ->execute(array($passHash, $roomId));

        if ($newPassword)
            $db->prepare("DELETE FROM room_members WHERE room_id = ? AND user_id != ?")->execute(array($roomId, $user['id']));

        jsonResponse(array('success' => true));

    // ── Messages: Send ────────────────────────────────────────────────────────
    case 'send_message':
        $roomId  = (int)(isset($_POST['room_id']) ? $_POST['room_id'] : 0);
        $message = trim(isset($_POST['message']) ? $_POST['message'] : '');
        $fileId  = isset($_POST['file_id']) && (int)$_POST['file_id'] > 0
                   ? (int)$_POST['file_id'] : null;

        // Belt-and-suspenders ban check (heartbeat is the primary path)
        if (getActiveBan($db, $user['id'], clientIp())) {
            session_unset(); session_destroy();
            jsonResponse(array('success' => false, 'banned' => true,
                               'error'   => 'You have been banned from this server.'), 403);
        }

        // Message text is optional when a file is attached; required otherwise
        if (!$fileId && (!$message || strlen($message) > MAX_MESSAGE_LENGTH))
            jsonResponse(array('success' => false, 'error' => 'Invalid message'));
        if ($fileId !== null && $message && strlen($message) > MAX_MESSAGE_LENGTH)
            jsonResponse(array('success' => false, 'error' => 'Message too long'));

        $stmt = $db->prepare("SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?");
        $stmt->execute(array($roomId, $user['id']));
        if (!$stmt->fetch()) jsonResponse(array('success' => false, 'error' => 'Not a member of this room'));

        // Verify file belongs to this user and room (if attached)
        if ($fileId !== null) {
            $stmt = $db->prepare("SELECT id FROM uploads WHERE id = ? AND user_id = ? AND room_id = ?");
            $stmt->execute(array($fileId, $user['id'], $roomId));
            if (!$stmt->fetch()) jsonResponse(array('success' => false, 'error' => 'Invalid file'));
        }

        $ip   = clientIp();
        $port = clientPort();
        $stmt = $db->prepare("INSERT INTO messages (user_id, username, message, ip, room_id, file_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute(array($user['id'], $user['username'], $message ?: '', $ip, $roomId, $fileId));
        $msgId = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO packet_log (direction, type, payload, username, room_id) VALUES ('in', 'message', ?, ?, ?)")
           ->execute(array(substr($message, 0, 200), $user['username'], $roomId));

        $db->prepare("INSERT INTO active_users (username, ts, ip, port) VALUES (?, NOW(), ?, ?) ON DUPLICATE KEY UPDATE ts = NOW(), ip = ?, port = ?")
           ->execute(array($user['username'], $ip, $port, $ip, $port));

        // Return file metadata along with message_id so the client can render immediately
        $fileData = null;
        if ($fileId !== null) {
            $stmt = $db->prepare("SELECT original_name, mime_type, file_size FROM uploads WHERE id = ?");
            $stmt->execute(array($fileId));
            $fileData = $stmt->fetch();
        }

        jsonResponse(array('success' => true, 'message_id' => $msgId, 'file' => $fileData));

    // ── Messages: Get ─────────────────────────────────────────────────────────
    case 'get_messages':
        $roomId   = (int)(isset($_GET['room_id'])  ? $_GET['room_id']  : 0);
        $since    = isset($_GET['since'])    ? $_GET['since']    : null;
        $clearTs  = isset($_GET['clear_ts']) ? $_GET['clear_ts'] : null;
        $beforeId = isset($_GET['before_id'])? (int)$_GET['before_id'] : null; // cursor for older pages
        $anchorUnread = (int)(isset($_GET['anchor_unread']) ? $_GET['anchor_unread'] : 0);

        $stmt = $db->prepare("SELECT joined_at FROM room_members WHERE room_id = ? AND user_id = ?");
        $stmt->execute(array($roomId, $user['id']));
        $membership = $stmt->fetch();
        if (!$membership) jsonResponse(array('success' => false, 'error' => 'Not a member'));
        $joinedAt = $membership['joined_at'] ?: '1970-01-01 00:00:00';

        // Unread tracking for this member in this room (since join time).
        $unreadStmt = $db->prepare("
            SELECT COUNT(*) AS unread_count, MIN(m.id) AS first_unread_id
            FROM messages m
            WHERE m.room_id = ?
              AND m.user_id != ?
              AND m.timestamp >= ?
              AND m.deleted_for_everyone = 0
              AND NOT EXISTS (
                SELECT 1
                FROM deleted_messages dm
                WHERE dm.message_id = m.id
                  AND dm.user_id = ?
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM message_status ms
                  WHERE ms.message_id = m.id
                    AND ms.username = ?
                    AND ms.read_at IS NOT NULL
              )
        ");
          $unreadStmt->execute(array($roomId, $user['id'], $joinedAt, $user['id'], $user['username']));
        $unreadInfo = $unreadStmt->fetch();
        $unreadCount = (int)($unreadInfo['unread_count'] ?? 0);
        $firstUnreadId = !empty($unreadInfo['first_unread_id']) ? (int)$unreadInfo['first_unread_id'] : null;

        // New members should only see messages sent at/after they joined this room.
        $where  = "m.room_id = ? AND m.timestamp >= ? AND NOT EXISTS (SELECT 1 FROM deleted_messages dm WHERE dm.message_id = m.id AND dm.user_id = ?)";
        $params = array($roomId, $joinedAt);
        $params[] = $user['id'];
        $orderBy = "m.timestamp DESC";
        $reverseRows = true;

        if ($beforeId) {
            // Load older messages: fetch rows with id < before_id (going back in history)
            $where .= " AND m.id < ?";
            $params[] = $beforeId;
        } else {
            // Normal live poll: filter by timestamp since last seen
            if ($since) {
                // Include global-deletion updates for existing messages so peers
                // receive delete-for-everyone changes without a full refresh.
                $where .= " AND (m.timestamp > ? OR (m.deleted_for_everyone = 1 AND m.deleted_at IS NOT NULL AND m.deleted_at > ?) OR (m.edited_at IS NOT NULL AND m.edited_at > ?))";
                $params[] = $since;
                $params[] = $since;
                $params[] = $since;
            }
            if ($clearTs) { $where .= " AND m.timestamp > ?"; $params[] = $clearTs; }

            // Initial open: start from the first unread message when available.
            if (!$since && !$clearTs && $anchorUnread && $firstUnreadId) {
                $where .= " AND m.id >= ?";
                $params[] = $firstUnreadId;
                $orderBy = "m.id ASC";
                $reverseRows = false;
            }
        }
        $params[] = MESSAGES_PER_PAGE;

        // IP addresses are intentionally excluded — must not be sent to clients
        $stmt = $db->prepare("
             SELECT m.id, m.user_id, m.username,
                 CASE WHEN m.deleted_for_everyone = 1 THEN '' ELSE m.message END AS message,
                 m.timestamp,
                 m.deleted_for_everyone AS is_deleted,
                                 m.edited_at,
                                 CASE WHEN m.edited_at IS NOT NULL THEN 1 ELSE 0 END AS is_edited,
                   u.avatar_color, u.avatar_url,
                   up.id            AS file_id,
                   up.original_name AS file_name,
                   up.mime_type     AS file_mime,
                   up.file_size     AS file_size
            FROM messages m
            JOIN users u ON u.id = m.user_id
             LEFT JOIN uploads up ON up.id = m.file_id AND m.deleted_for_everyone = 0
            WHERE $where
            ORDER BY $orderBy
            LIMIT ?
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if ($reverseRows) $rows = array_reverse($rows);

        if (empty($rows)) {
            jsonResponse(array(
                'success'         => true,
                'messages'        => array(),
                'has_more'        => false,
                'oldest_id'       => null,
                'unread_count'    => $unreadCount,
                'first_unread_id' => $firstUnreadId,
            ));
        }

        // Count this request only when it delivered actual chat data.
        $GLOBALS['_bw_track_request'] = true;

        // Check if there are even older messages beyond this page
        $oldestId = (int)$rows[0]['id'];
        $hasMoreStmt = $db->prepare(
            "SELECT 1 FROM messages
                         WHERE room_id = ? AND timestamp >= ? AND id < ?
                             AND NOT EXISTS (SELECT 1 FROM deleted_messages dm WHERE dm.message_id = messages.id AND dm.user_id = ?)" .
            ($clearTs ? " AND timestamp > ?" : "") .
            " LIMIT 1"
        );
                $hasMoreParams = array($roomId, $joinedAt, $oldestId, $user['id']);
        if ($clearTs) $hasMoreParams[] = $clearTs;
        $hasMoreStmt->execute($hasMoreParams);
        $hasMore = (bool)$hasMoreStmt->fetch();

        // Collect message IDs then fetch all their reactions in one query
        $msgIds = array();
        foreach ($rows as $row) $msgIds[] = (int)$row['id'];

        $placeholders = implode(',', array_fill(0, count($msgIds), '?'));
        $rStmt = $db->prepare("
            SELECT message_id, emoji, username
            FROM reactions
            WHERE message_id IN ($placeholders)
            ORDER BY message_id, emoji
        ");
        $rStmt->execute($msgIds);

        // Group reactions as { message_id => { emoji => [username, ...] } }
        $reactionMap = array();
        foreach ($rStmt->fetchAll() as $r) {
            $mid   = (int)$r['message_id'];
            $emoji = $r['emoji'];
            if (!isset($reactionMap[$mid]))         $reactionMap[$mid] = array();
            if (!isset($reactionMap[$mid][$emoji])) $reactionMap[$mid][$emoji] = array();
            $reactionMap[$mid][$emoji][] = $r['username'];
        }

        // Attach reactions to each message
        foreach ($rows as &$row) {
            $mid = (int)$row['id'];
            $row['is_deleted'] = !empty($row['is_deleted']) ? 1 : 0;
            $row['reactions'] = $row['is_deleted'] ? array() : (isset($reactionMap[$mid]) ? $reactionMap[$mid] : array());
        }
        unset($row);

        jsonResponse(array(
            'success'         => true,
            'messages'        => $rows,
            'has_more'        => $hasMore,
            'oldest_id'       => $oldestId,
            'unread_count'    => $unreadCount,
            'first_unread_id' => $firstUnreadId,
        ));

    // ── Messages: Delete (for me / for everyone) ────────────────────────────
    case 'delete_message':
        $msgId = (int)(isset($_POST['message_id']) ? $_POST['message_id'] : 0);
        $scope = isset($_POST['scope']) ? trim($_POST['scope']) : 'me';

        if ($msgId <= 0) jsonResponse(array('success' => false, 'error' => 'Invalid message'));
        if (!in_array($scope, array('me', 'everyone'), true)) {
            jsonResponse(array('success' => false, 'error' => 'Invalid delete scope'));
        }

        $stmt = $db->prepare("SELECT id, user_id, room_id, timestamp, deleted_for_everyone FROM messages WHERE id = ?");
        $stmt->execute(array($msgId));
        $message = $stmt->fetch();
        if (!$message) jsonResponse(array('success' => false, 'error' => 'Message not found'));

        $stmt = $db->prepare("SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?");
        $stmt->execute(array((int)$message['room_id'], $user['id']));
        if (!$stmt->fetch()) jsonResponse(array('success' => false, 'error' => 'Not a member of this room'));

        $stmt = $db->prepare("SELECT created_by FROM rooms WHERE id = ?");
        $stmt->execute(array((int)$message['room_id']));
        $room = $stmt->fetch();
        $isRoomCreator = $room && (int)$room['created_by'] === (int)$user['id'];
        $isSender = (int)$message['user_id'] === (int)$user['id'];

        if ($scope === 'me') {
            $db->prepare("INSERT IGNORE INTO deleted_messages (message_id, user_id, deleted_at) VALUES (?, ?, NOW())")
               ->execute(array($msgId, $user['id']));
            jsonResponse(array('success' => true, 'message_id' => $msgId, 'scope' => 'me'));
        }

        if (!$isSender && !$isRoomCreator) {
            jsonResponse(array('success' => false, 'error' => 'Only the sender or room admin can delete for everyone'));
        }

        if (!empty($message['deleted_for_everyone'])) {
            jsonResponse(array('success' => true, 'message_id' => $msgId, 'scope' => 'everyone', 'already_deleted' => true));
        }

        $sentAt = strtotime((string)$message['timestamp']);
        $allowedWindowSeconds = $isRoomCreator
            ? ROOM_ADMIN_DELETE_FOR_EVERYONE_WINDOW_SECONDS
            : DELETE_FOR_EVERYONE_WINDOW_SECONDS;
        if (!$sentAt || (time() - $sentAt) > $allowedWindowSeconds) {
            $windowLabel = $isRoomCreator ? '1 day' : '2 minutes';
            jsonResponse(array('success' => false, 'error' => 'Delete for everyone is only available for ' . $windowLabel . ' after sending'));
        }

        $db->prepare("UPDATE messages
                      SET message = '',
                          file_id = NULL,
                          deleted_for_everyone = 1,
                          deleted_at = NOW(),
                          deleted_by_user_id = ?
                      WHERE id = ?")
           ->execute(array($user['id'], $msgId));
        $db->prepare("DELETE FROM reactions WHERE message_id = ?")->execute(array($msgId));

        jsonResponse(array(
            'success'    => true,
            'message_id' => $msgId,
            'room_id'    => (int)$message['room_id'],
            'scope'      => 'everyone',
        ));

    // ── Messages: Edit (sender only, 1 minute) ─────────────────────────────
    case 'edit_message':
        $msgId = (int)(isset($_POST['message_id']) ? $_POST['message_id'] : 0);
        $newMessage = trim(isset($_POST['message']) ? $_POST['message'] : '');

        if ($msgId <= 0) jsonResponse(array('success' => false, 'error' => 'Invalid message'));
        if ($newMessage === '' || strlen($newMessage) > MAX_MESSAGE_LENGTH) {
            jsonResponse(array('success' => false, 'error' => 'Edited message must be 1-' . MAX_MESSAGE_LENGTH . ' characters'));
        }

        $stmt = $db->prepare("SELECT id, user_id, room_id, timestamp, deleted_for_everyone FROM messages WHERE id = ?");
        $stmt->execute(array($msgId));
        $message = $stmt->fetch();
        if (!$message) jsonResponse(array('success' => false, 'error' => 'Message not found'));

        $stmt = $db->prepare("SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?");
        $stmt->execute(array((int)$message['room_id'], $user['id']));
        if (!$stmt->fetch()) jsonResponse(array('success' => false, 'error' => 'Not a member of this room'));

        if ((int)$message['user_id'] !== (int)$user['id']) {
            jsonResponse(array('success' => false, 'error' => 'Only the sender can edit this message'));
        }
        if (!empty($message['deleted_for_everyone'])) {
            jsonResponse(array('success' => false, 'error' => 'Deleted messages cannot be edited'));
        }

        $sentAt = strtotime((string)$message['timestamp']);
        if (!$sentAt || (time() - $sentAt) > EDIT_MESSAGE_WINDOW_SECONDS) {
            jsonResponse(array('success' => false, 'error' => 'You can edit a message only within 1 minute of sending'));
        }

        $db->prepare("UPDATE messages SET message = ?, edited_at = NOW() WHERE id = ?")
           ->execute(array($newMessage, $msgId));

        $editedAt = date('Y-m-d H:i:s');
        jsonResponse(array(
            'success'    => true,
            'message_id' => $msgId,
            'room_id'    => (int)$message['room_id'],
            'message'    => $newMessage,
            'edited_at'  => $editedAt,
            'is_edited'  => 1,
        ));

    // ── Messages: Clear (soft-delete for requesting user only) ────────────────
    // The client-side clearChat() already hides messages via a localStorage
    // timestamp (clearTimestamp). This endpoint records a per-user soft-delete
    // in deleted_messages so the "clear" survives across devices/sessions.
    // It does NOT affect other users — that matches the UI confirmation text.
    case 'clear_messages':
        $roomId = (int)(isset($_POST['room_id']) ? $_POST['room_id'] : 0);

        // Must be a member
        $stmt = $db->prepare("SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?");
        $stmt->execute(array($roomId, $user['id']));
        if (!$stmt->fetch())
            jsonResponse(array('success' => false, 'error' => 'Not a member of this room'));

        // Soft-delete all messages in this room for the current user only.
        // INSERT IGNORE skips rows already hidden by an earlier "delete for me".
        $db->prepare("
            INSERT IGNORE INTO deleted_messages (message_id, user_id, deleted_at)
            SELECT m.id, ?, NOW()
            FROM messages m
            WHERE m.room_id = ?
              AND m.deleted_for_everyone = 0
        ")->execute(array($user['id'], $roomId));

        jsonResponse(array('success' => true));

    // ── Reactions ─────────────────────────────────────────────────────────────
    case 'react':
        $msgId = (int)(isset($_POST['message_id']) ? $_POST['message_id'] : 0);
        $emoji = isset($_POST['emoji']) ? $_POST['emoji'] : '';
        $allowed = array('👍','❤️','😂','😮','😢');
        if (!in_array($emoji, $allowed)) jsonResponse(array('success' => false, 'error' => 'Invalid emoji'));

        // Check if the user already reacted with THIS same emoji (toggle off)
        $stmt = $db->prepare("SELECT emoji FROM reactions WHERE message_id = ? AND username = ?");
        $stmt->execute(array($msgId, $user['username']));
        $existing = $stmt->fetch();

        if ($existing && $existing['emoji'] === $emoji) {
            // Same emoji clicked again — remove it (toggle off)
            $db->prepare("DELETE FROM reactions WHERE message_id = ? AND username = ?")
               ->execute(array($msgId, $user['username']));
        } else {
            // Different emoji or no reaction yet — replace existing if any
            $db->prepare("DELETE FROM reactions WHERE message_id = ? AND username = ?")
               ->execute(array($msgId, $user['username']));
            $db->prepare("INSERT INTO reactions (message_id, emoji, username) VALUES (?, ?, ?)")
               ->execute(array($msgId, $emoji, $user['username']));
        }

        // Return the full updated reaction set
        $stmt = $db->prepare("SELECT emoji, username FROM reactions WHERE message_id = ? ORDER BY emoji");
        $stmt->execute(array($msgId));
        $reactions = array();
        foreach ($stmt->fetchAll() as $r) {
            $e = $r['emoji'];
            if (!isset($reactions[$e])) $reactions[$e] = array();
            $reactions[$e][] = $r['username'];
        }

        jsonResponse(array('success' => true, 'reactions' => $reactions));

    // ── Read/Delivered ────────────────────────────────────────────────────────
    case 'delivered':
        $msgId = (int)(isset($_POST['message_id']) ? $_POST['message_id'] : 0);
        $db->prepare("INSERT INTO message_status (message_id, username, delivered_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE delivered_at = IFNULL(delivered_at, NOW())")
           ->execute(array($msgId, $user['username']));
        jsonResponse(array('success' => true));

    case 'read':
        $msgIds = json_decode(isset($_POST['message_ids']) ? $_POST['message_ids'] : '[]', true);
        if (!is_array($msgIds)) jsonResponse(array('success' => false, 'error' => 'Invalid'));
        $stmt = $db->prepare("INSERT INTO message_status (message_id, username, delivered_at, read_at) VALUES (?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE read_at = IFNULL(read_at, NOW()), delivered_at = IFNULL(delivered_at, NOW())");
        foreach (array_slice($msgIds, 0, 100) as $id)
            $stmt->execute(array((int)$id, $user['username']));
        jsonResponse(array('success' => true));

    // ── Get Reactions ─────────────────────────────────────────────────────────
    case 'get_reactions':
        $roomId  = (int)(isset($_GET['room_id']) ? $_GET['room_id'] : 0);
        $msgIds  = json_decode(isset($_GET['message_ids']) ? $_GET['message_ids'] : '[]', true);
        if (!is_array($msgIds) || empty($msgIds))
            jsonResponse(array('success' => true, 'reactions' => array()));

        // Verify user is member of the room
        $stmt = $db->prepare("SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?");
        $stmt->execute(array($roomId, $user['id']));
        if (!$stmt->fetch()) jsonResponse(array('success' => false, 'error' => 'Not a member'));

        $safeIds      = array_map('intval', array_slice($msgIds, 0, 100));
        $placeholders = implode(',', array_fill(0, count($safeIds), '?'));

        $stmt = $db->prepare("
            SELECT message_id, emoji, username
            FROM reactions
            WHERE message_id IN ($placeholders)
            ORDER BY message_id, emoji
        ");
        $stmt->execute($safeIds);

        $result = array();
        foreach ($safeIds as $id) $result[$id] = array();
        foreach ($stmt->fetchAll() as $r) {
            $mid   = (int)$r['message_id'];
            $emoji = $r['emoji'];
            if (!isset($result[$mid][$emoji])) $result[$mid][$emoji] = array();
            $result[$mid][$emoji][] = $r['username'];
        }

        jsonResponse(array('success' => true, 'reactions' => $result));

    // ── Membership check ──────────────────────────────────────────────────────
    case 'check_membership':
        $roomId = (int)(isset($_GET['room_id']) ? $_GET['room_id'] : 0);

        $stmt = $db->prepare("SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?");
        $stmt->execute(array($roomId, $user['id']));
        $isMember = (bool)$stmt->fetch();

        if (!$isMember) {
            $db->prepare("DELETE FROM removed_members WHERE room_id = ? AND user_id = ?")
               ->execute(array($roomId, $user['id']));
            jsonResponse(array('success' => true, 'member' => false, 'removed' => true));
        }

        jsonResponse(array('success' => true, 'member' => true, 'removed' => false));

    // ── Read Status ───────────────────────────────────────────────────────────
    case 'read_status':
        $roomId    = (int)(isset($_GET['room_id']) ? $_GET['room_id'] : 0);
        $msgIds    = json_decode(isset($_GET['message_ids']) ? $_GET['message_ids'] : '[]', true);
        if (!is_array($msgIds) || empty($msgIds))
            jsonResponse(array('success' => false, 'error' => 'No message IDs'));

        $membersStmt = $db->prepare("
            SELECT u.username
            FROM room_members rm
            JOIN users u ON u.id = rm.user_id
            WHERE rm.room_id = ? AND rm.user_id != ?
        ");
        $membersStmt->execute(array($roomId, $user['id']));
        $targetReaders = array();
        foreach ($membersStmt->fetchAll() as $m) {
            if (!empty($m['username'])) $targetReaders[] = $m['username'];
        }
        $targetReaderSet = array_flip($targetReaders);
        $totalOthers = count($targetReaders);

        $statuses = array();
        $safeIds  = array_map('intval', array_slice($msgIds, 0, 50));
        $placeholders = implode(',', array_fill(0, count($safeIds), '?'));

        $stmt = $db->prepare("
                        SELECT DISTINCT message_id, username
            FROM message_status
            WHERE message_id IN ($placeholders)
              AND read_at IS NOT NULL
        ");
        $stmt->execute($safeIds);
        $rows = $stmt->fetchAll();

        $readers = array();
        foreach ($safeIds as $id) $readers[$id] = array();
        foreach ($rows as $row) {
            $name = $row['username'];
            // Count only users who are currently valid recipients in this room.
            if (!isset($targetReaderSet[$name])) continue;
            $readers[(int)$row['message_id']][] = $name;
        }

        foreach ($safeIds as $id) {
            $statuses[$id] = array(
                'readers'       => $readers[$id],
                'total_members' => $totalOthers,
                'target_readers'=> $targetReaders,
            );
        }

        jsonResponse(array('success' => true, 'statuses' => $statuses));

    // ── Session Info ──────────────────────────────────────────────────────────
    // Returns the fixed port and IP captured at login time.
    // This never changes during a session — it is the user's stable "session port".
    case 'get_session_info':
        jsonResponse(array(
            'success'      => true,
            'session_port' => isset($_SESSION['session_port']) ? $_SESSION['session_port'] : null,
            'session_ip'   => isset($_SESSION['session_ip'])   ? $_SESSION['session_ip']   : clientIp(),
        ));

    // ── LAN Info ──────────────────────────────────────────────────────────────
    // Returns the server's detected LAN IP and shareable URLs.
    // Used by the chat UI to display a "Share with your LAN" banner.
    case 'get_lan_info':
        $lanIp   = detectLanIp();
        $appPath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        jsonResponse(array(
            'success'   => true,
            'lan_ip'    => $lanIp,
            'lan_mode'  => ($lanIp !== 'localhost'),
            'app_url'   => 'http://' . $lanIp . $appPath,
            'auth_url'  => 'http://' . $lanIp . $appPath . '/auth.php',
            'setup_url' => 'http://' . $lanIp . $appPath . '/lan_setup.php',
            'ws_url'    => 'ws://'   . $lanIp . ':' . WS_PORT,
        ));

    // ── Heartbeat ─────────────────────────────────────────────────────────────
    // Refreshes presence (ts) so the user stays visible in active_users.
    // We do NOT update the port here — the port shown to the user is fixed
    // at login time (session_port) and does not change during the session.
    case 'heartbeat':
        $ip = clientIp();

        // ── Ban check on every heartbeat ──────────────────────────────────────
        // If the admin banned this user while they were already logged in,
        // the next heartbeat (≤30 s) will catch it and force a client logout.
        $activeBan = getActiveBan($db, $user['id'], $ip);
        if ($activeBan) {
            $msg = 'Your account has been banned.';
            if ($activeBan['reason'])     $msg .= ' Reason: ' . $activeBan['reason'] . '.';
            if ($activeBan['expires_at']) $msg .= ' Expires: ' . $activeBan['expires_at'] . '.';
            else                          $msg .= ' This ban is permanent.';
            // Destroy the session so they are truly logged out server-side
            session_unset();
            session_destroy();
            jsonResponse(array(
                'success' => true,
                'banned'  => true,
                'message' => $msg,
            ));
        }

        $db->prepare(
            "INSERT INTO active_users (username, ts, ip, port)
             VALUES (?, NOW(), ?, ?) AS au_new
             ON DUPLICATE KEY UPDATE ts = NOW(), ip = au_new.ip, port = au_new.port"
        )->execute(array($user['username'], $ip,
            isset($_SESSION['session_port']) ? $_SESSION['session_port'] : clientPort()));
        jsonResponse(array(
            'success'      => true,
            'banned'       => false,
            'session_port' => isset($_SESSION['session_port']) ? $_SESSION['session_port'] : null,
            'session_ip'   => isset($_SESSION['session_ip'])   ? $_SESSION['session_ip']   : $ip,
            'ts'           => date('Y-m-d H:i:s'),
        ));

    // ── Ban user (admin only) ─────────────────────────────────────────────────
    case 'ban_user':
        requireAdmin($user);
        $targetUsername = trim(isset($_POST['username'])   ? $_POST['username']   : '');
        $reason         = trim(isset($_POST['reason'])     ? $_POST['reason']     : '');
        $banIp          = (int)(isset($_POST['ban_ip'])    ? $_POST['ban_ip']     : 0);
        $hours          = (int)(isset($_POST['hours'])     ? $_POST['hours']      : 0); // 0 = permanent

        if (!$targetUsername) jsonResponse(['success' => false, 'error' => 'Username required']);
        if ($targetUsername === ADMIN_USERNAME)
            jsonResponse(['success' => false, 'error' => 'Cannot ban the admin account']);

        // Look up the target user
        $stmt = $db->prepare("SELECT id, username FROM users WHERE username = ?");
        $stmt->execute([$targetUsername]);
        $targetUser = $stmt->fetch();
        if (!$targetUser) jsonResponse(['success' => false, 'error' => 'User not found']);

        // Resolve their last known IP from active_users
        $ipRow = $db->prepare("SELECT ip FROM active_users WHERE username = ?");
        $ipRow->execute([$targetUsername]);
        $targetIp = ($banIp && ($row = $ipRow->fetch())) ? $row['ip'] : null;

        $expires = $hours > 0 ? date('Y-m-d H:i:s', strtotime("+{$hours} hours")) : null;

        // Deactivate any existing active ban for this user first
        $db->prepare("UPDATE bans SET active = 0 WHERE user_id = ? AND active = 1")
           ->execute([$targetUser['id']]);

        $db->prepare("INSERT INTO bans (user_id, username, ip, reason, banned_by, expires_at, active)
                      VALUES (?, ?, ?, ?, ?, ?, 1)")
           ->execute([$targetUser['id'], $targetUsername, $targetIp, $reason ?: null,
                      $user['username'], $expires]);

        // Write audit log entry
        $db->prepare("INSERT INTO audit_log (actor, action, target_user, detail, ip)
                      VALUES (?, 'ban_user', ?, ?, ?)")
           ->execute([$user['username'], $targetUsername,
                      ($reason ?: 'No reason given') . ($hours ? " ({$hours}h)" : ' (permanent)'),
                      clientIp()]);

        jsonResponse(['success' => true, 'message' => "User '{$targetUsername}' has been banned"]);

    // ── Unban user (admin only) ───────────────────────────────────────────────
    case 'unban_user':
        requireAdmin($user);
        $targetUsername = trim(isset($_POST['username']) ? $_POST['username'] : '');
        if (!$targetUsername) jsonResponse(['success' => false, 'error' => 'Username required']);

        $affected = $db->prepare("UPDATE bans SET active = 0 WHERE username = ? AND active = 1");
        $affected->execute([$targetUsername]);

        $db->prepare("INSERT INTO audit_log (actor, action, target_user, detail, ip)
                      VALUES (?, 'unban_user', ?, 'Ban lifted', ?)")
           ->execute([$user['username'], $targetUsername, clientIp()]);
        jsonResponse(['success' => true, 'message' => "User '{$targetUsername}' has been unbanned"]);

    // ── List bans (admin only) ────────────────────────────────────────────────
    case 'list_bans':
        requireAdmin($user);
        // Auto-expire bans whose expires_at has passed
        $db->exec("UPDATE bans SET active = 0
                   WHERE active = 1 AND expires_at IS NOT NULL AND expires_at < NOW()");

        $bans = $db->query(
            "SELECT id, username, ip, reason, banned_by, banned_at, expires_at, active
             FROM bans ORDER BY banned_at DESC LIMIT 200"
        )->fetchAll();
        jsonResponse(['success' => true, 'bans' => $bans]);

    // ── Audit log (admin only) ────────────────────────────────────────────────
    case 'get_audit_log':
        requireAdmin($user);
        $limit  = min((int)(isset($_GET['limit'])  ? $_GET['limit']  : 100), 500);
        $filter = trim(isset($_GET['action_filter']) ? $_GET['action_filter'] : '');

        if ($filter) {
            $stmt = $db->prepare(
                "SELECT * FROM audit_log WHERE action = ? ORDER BY ts DESC LIMIT ?"
            );
            $stmt->execute([$filter, $limit]);
        } else {
            $stmt = $db->prepare("SELECT * FROM audit_log ORDER BY ts DESC LIMIT ?");
            $stmt->execute([$limit]);
        }
        $rows = $stmt->fetchAll();
        jsonResponse(['success' => true, 'entries' => $rows]);

    // ── Bandwidth stats (admin only) ──────────────────────────────────────────
    case 'bandwidth_stats':
        requireAdmin($user);
        // Use PHP-generated minute buckets for threshold to avoid timezone mismatches
        $threshold = date('Y-m-d H:i:00', strtotime('-60 minutes'));
        try {
            // Last 60 minutes, one row per minute (gap-filled in PHP)
            $stmt = $db->prepare(
                "SELECT bucket, bytes_in, bytes_out
                 FROM bandwidth_log
                 WHERE bucket >= ?
                 ORDER BY bucket ASC"
            );
            $stmt->execute(array($threshold));
            $rows = $stmt->fetchAll();
        } catch (Exception $e) {
            $rows = []; // table not yet created or other error
        }

        // Fill gaps so the chart always has a 60-point series
        $series = [];
        for ($i = 59; $i >= 0; $i--) {
            $series[] = ['bucket' => date('Y-m-d H:i:00', strtotime("-{$i} minutes")),
                         'bytes_in' => 0, 'bytes_out' => 0];
        }
        foreach ($rows as $r) {
            foreach ($series as &$s) {
                if (substr($s['bucket'], 0, 16) === substr($r['bucket'], 0, 16)) {
                    $s['bytes_in']  = (int)$r['bytes_in'];
                    $s['bytes_out'] = (int)$r['bytes_out'];
                }
            }
            unset($s);
        }

        // Current totals (last 60 min) — compute using the same PHP threshold
        try {
            $stmt = $db->prepare(
                "SELECT COALESCE(SUM(bytes_in),0) AS total_in, COALESCE(SUM(bytes_out),0) AS total_out
                 FROM bandwidth_log WHERE bucket >= ?"
            );
            $stmt->execute(array($threshold));
            $totals = $stmt->fetch();
        } catch (Exception $e) {
            $totals = ['total_in' => 0, 'total_out' => 0];
        }

        jsonResponse([
            'success'    => true,
            'series'     => $series,
            'total_in'   => (int)$totals['total_in'],
            'total_out'  => (int)$totals['total_out'],
        ]);

    // ── Network Stats (admin only) ─────────────────────────────────────────────
    case 'network_stats':
        requireAdmin($user);

        // Active user count: anyone whose heartbeat ts is within the last 5 minutes.
        // We count DISTINCT usernames to avoid double-counting.
        $activeCount = (int)$db->query(
            "SELECT COUNT(DISTINCT username) FROM active_users
             WHERE ts > DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        )->fetchColumn();

        // Full active user list with ip, port, ts — sorted by most-recently-seen first
        $activeUsers = $db->query(
            "SELECT username, ip, port, ts
             FROM active_users
             WHERE ts > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             ORDER BY ts DESC"
        )->fetchAll();

        $msgHour  = (int)$db->query("SELECT COUNT(*) FROM messages WHERE timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn();
        $msgDay   = (int)$db->query("SELECT COUNT(*) FROM messages WHERE timestamp > DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
        $roomCount= (int)$db->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
        $userCount= (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $mpm      = (float)$db->query("SELECT COUNT(*)/10 FROM messages WHERE timestamp > DATE_SUB(NOW(), INTERVAL 10 MINUTE)")->fetchColumn();
        $chart    = $db->query("SELECT HOUR(timestamp) AS hour, COUNT(*) AS count FROM messages WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY HOUR(timestamp) ORDER BY hour")->fetchAll();

        jsonResponse(array(
            'success'             => true,
            'active_users'        => $activeCount,
            'active_users_list'   => $activeUsers,
            'messages_last_hour'  => $msgHour,
            'messages_last_day'   => $msgDay,
            'room_count'          => $roomCount,
            'user_count'          => $userCount,
            'messages_per_minute' => round($mpm, 2),
            'activity_chart'      => $chart,
            'server_time'         => date('Y-m-d H:i:s'),
            'ws_port'             => WS_PORT,
            'ws_host'             => WS_PUBLIC_HOST,
            'active_bans'         => (function() use ($db) {
                try { return (int)$db->query("SELECT COUNT(*) FROM bans WHERE active = 1")->fetchColumn(); }
                catch (Exception $e) { return 0; }
            })(),
            // Use PHP threshold to avoid timezone mismatches with DB NOW()
            'bw_in_last_min'      => (function() use ($db) {
                try {
                    $threshold = date('Y-m-d H:i:00', strtotime('-1 minute'));
                    $stmt = $db->prepare("SELECT COALESCE(SUM(bytes_in),0) FROM bandwidth_log WHERE bucket >= ?");
                    $stmt->execute([$threshold]);
                    return (int)$stmt->fetchColumn();
                } catch (Exception $e) { return 0; }
            })(),
            'bw_out_last_min'     => (function() use ($db) {
                try {
                    $threshold = date('Y-m-d H:i:00', strtotime('-1 minute'));
                    $stmt = $db->prepare("SELECT COALESCE(SUM(bytes_out),0) FROM bandwidth_log WHERE bucket >= ?");
                    $stmt->execute([$threshold]);
                    return (int)$stmt->fetchColumn();
                } catch (Exception $e) { return 0; }
            })(),
        ));

    // ── Packet Log (admin only) ────────────────────────────────────────────────
    case 'packet_log':
        // FIX: restrict to admin user only
        requireAdmin($user);

        $limit = min((int)(isset($_GET['limit']) ? $_GET['limit'] : 50), 200);
        $stmt = $db->prepare("SELECT * FROM packet_log ORDER BY ts DESC LIMIT ?");
        $stmt->execute(array($limit));
        jsonResponse(array('success' => true, 'packets' => $stmt->fetchAll()));

    // ── File Upload ───────────────────────────────────────────────────────────
    case 'upload_file':
        $roomId = (int)(isset($_POST['room_id']) ? $_POST['room_id'] : 0);

        // Must be a room member
        $stmt = $db->prepare("SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?");
        $stmt->execute(array($roomId, $user['id']));
        if (!$stmt->fetch()) jsonResponse(array('success' => false, 'error' => 'Not a member of this room'));

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errMap = array(
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            );
            $code = isset($_FILES['file']) ? $_FILES['file']['error'] : UPLOAD_ERR_NO_FILE;
            $msg  = isset($errMap[$code]) ? $errMap[$code] : 'Upload error';
            jsonResponse(array('success' => false, 'error' => $msg));
        }

        $file     = $_FILES['file'];
        $size     = (int)$file['size'];
        $origName = basename($file['name']);

        if ($size === 0)
            jsonResponse(array('success' => false, 'error' => 'File is empty'));
        if ($size > UPLOAD_MAX_BYTES)
            jsonResponse(array('success' => false, 'error' => 'File too large (max 10 GB)'));

        // Detect MIME from actual file content; fall back safely for mobile voice formats.
        $finfo      = new finfo(FILEINFO_MIME_TYPE);
        $mimeType   = strtolower((string)$finfo->file($file['tmp_name']));
        $clientMime = strtolower((string)($file['type'] ?? ''));
        $allowed    = unserialize(UPLOAD_ALLOWED_MIME);

        if (!in_array($mimeType, $allowed, true)) {
            // Mobile browsers sometimes send a useful client MIME while finfo is generic.
            if (in_array($clientMime, $allowed, true)) {
                $mimeType = $clientMime;
            } elseif ($mimeType === 'application/octet-stream' || $mimeType === '' || $mimeType === 'application/ogg') {
                // Extension fallback for recorded voice clips when MIME sniffing is ambiguous.
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $extMap = array(
                    'm4a'  => 'audio/x-m4a',
                    'mp4'  => 'audio/mp4',
                    'aac'  => 'audio/aac',
                    'webm' => 'audio/webm',
                    'ogg'  => 'audio/ogg',
                    'wav'  => 'audio/wav',
                    'mp3'  => 'audio/mpeg',
                );
                if (isset($extMap[$ext]) && in_array($extMap[$ext], $allowed, true)) {
                    $mimeType = $extMap[$ext];
                }
            }
        }

        if (!in_array($mimeType, $allowed, true)) {
            jsonResponse(array('success' => false, 'error' => 'File type not allowed: ' . $mimeType));
        }

        // Build a safe stored filename: uuid + original extension (lowercase, stripped)
        $ext        = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $ext        = preg_replace('/[^a-z0-9]/', '', $ext);   // only alphanum
        $storedName = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');

        // Create uploads directory if it doesn't exist
        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0755, true);
            // Drop an .htaccess in the folder so Apache won't execute anything in it
            file_put_contents(UPLOAD_DIR . '.htaccess',
                "Options -Indexes\n" .
                "<FilesMatch \".\">\n" .
                "  SetHandler default-handler\n" .
                "  php_flag engine off\n" .
                "</FilesMatch>\n"
            );
        }

        $dest = UPLOAD_DIR . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $dest))
            jsonResponse(array('success' => false, 'error' => 'Failed to save file'));

        // Record in DB
        $stmt = $db->prepare("INSERT INTO uploads (user_id, room_id, original_name, stored_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute(array($user['id'], $roomId, $origName, $storedName, $mimeType, $size));
        $fileId = (int)$db->lastInsertId();

        jsonResponse(array(
            'success'       => true,
            'file_id'       => $fileId,
            'original_name' => $origName,
            'mime_type'     => $mimeType,
            'file_size'     => $size,
        ));

    // ── File Serve ────────────────────────────────────────────────────────────
    // Streams a file to the browser after verifying the requester is a room member.
    // Usage: ajax_server.php?action=serve_file&file_id=N[&download=1]
    case 'serve_file':
        $fileId   = (int)(isset($_GET['file_id']) ? $_GET['file_id'] : 0);
        $download = !empty($_GET['download']);

        $stmt = $db->prepare("SELECT * FROM uploads WHERE id = ?");
        $stmt->execute(array($fileId));
        $upload = $stmt->fetch();
        if (!$upload) jsonResponse(array('success' => false, 'error' => 'File not found'), 404);

        // Verify requester is a member of the room the file was shared in
        $stmt = $db->prepare("SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?");
        $stmt->execute(array($upload['room_id'], $user['id']));
        if (!$stmt->fetch()) jsonResponse(array('success' => false, 'error' => 'Not a member'), 403);

        $path = UPLOAD_DIR . $upload['stored_name'];
        if (!file_exists($path)) jsonResponse(array('success' => false, 'error' => 'File missing from server'), 404);

        // Clear ALL output buffer levels
        while (ob_get_level()) ob_end_clean();

        // No time limit for large file streaming
        set_time_limit(0);

        // Security headers — prevent the browser from treating the file as a page
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Content-Security-Policy: default-src \'none\'');

        $safeName  = rawurlencode($upload['original_name']);
        // Strip CR, LF, and double-quotes to prevent HTTP header injection via filename
        $safeAscii = str_replace(['"', "\r", "\n"], ['', '', ''], $upload['original_name']);
        $disp      = $download ? 'attachment' : 'inline';
        header('Content-Type: ' . $upload['mime_type']);
        header('Content-Disposition: ' . $disp . '; filename="' . $safeAscii . '"; filename*=UTF-8\'\'' . $safeName);
        header('Content-Length: ' . $upload['file_size']);
        header('Cache-Control: private, max-age=3600');
        header('Accept-Ranges: bytes');

        // Stream in 1MB chunks — avoids loading entire file into memory
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            http_response_code(500);
            exit('Could not open file');
        }
        while (!feof($handle)) {
            echo fread($handle, 1048576);
            flush();
        }
        fclose($handle);
        exit;

    // ── Avatar Upload ─────────────────────────────────────────────────────────
    case 'upload_avatar':
        if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(array('success' => false, 'error' => 'No avatar file received'));
        }
        $file = $_FILES['avatar'];
        if ($file['size'] > AVATAR_MAX_BYTES)
            jsonResponse(array('success' => false, 'error' => 'Avatar must be under 5 MB'));

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        $allowed  = unserialize(AVATAR_ALLOWED_MIME);
        if (!in_array($mimeType, $allowed, true))
            jsonResponse(array('success' => false, 'error' => 'Avatar must be JPEG, PNG, GIF or WebP'));

        // Create avatar directory with PHP-execution protection
        if (!is_dir(AVATAR_DIR)) {
            mkdir(AVATAR_DIR, 0755, true);
            file_put_contents(dirname(AVATAR_DIR) . '/.htaccess',
                "Options -Indexes\n<FilesMatch \".\">\n  SetHandler default-handler\n  php_flag engine off\n</FilesMatch>\n");
        }

        // Delete the user's old avatar file if it exists
        if (!empty($_SESSION['avatar_url'])) {
            $old = AVATAR_DIR . basename($_SESSION['avatar_url']);
            if (file_exists($old)) @unlink($old);
        }

        $ext        = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $ext        = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'jpg';
        $storedName = bin2hex(random_bytes(12)) . '.' . $ext;
        $dest       = AVATAR_DIR . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $dest))
            jsonResponse(array('success' => false, 'error' => 'Failed to save avatar'));

        $db->prepare("UPDATE users SET avatar_url = ? WHERE id = ?")->execute(array($storedName, $user['id']));
        $_SESSION['avatar_url'] = $storedName;

        jsonResponse(array('success' => true, 'avatar_url' => 'uploads/avatars/' . $storedName));

    // ── Remove Avatar ─────────────────────────────────────────────────────────
    case 'remove_avatar':
        if (!empty($_SESSION['avatar_url'])) {
            $old = AVATAR_DIR . basename($_SESSION['avatar_url']);
            if (file_exists($old)) @unlink($old);
        }
        $db->prepare("UPDATE users SET avatar_url = NULL WHERE id = ?")->execute(array($user['id']));
        $_SESSION['avatar_url'] = null;
        jsonResponse(array('success' => true));

    // ── Room Stats (sidebar dashboard mini-panel) ──────────────────────────────
    case 'get_room_stats':
        $roomId = (int)(isset($_GET['room_id']) ? $_GET['room_id'] : 0);
        $stmt = $db->prepare("SELECT 1 FROM room_members WHERE room_id = ? AND user_id = ?");
        $stmt->execute(array($roomId, $user['id']));
        if (!$stmt->fetch()) jsonResponse(array('success' => false, 'error' => 'Not a member'));

        $activeGlobal = (int)$db->query(
            "SELECT COUNT(DISTINCT username) FROM active_users WHERE ts > DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        )->fetchColumn();

        $stR = $db->prepare("SELECT COUNT(*) FROM messages WHERE room_id = ? AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stR->execute(array($roomId));
        $msgHour = (int)$stR->fetchColumn();

        $stT = $db->prepare("SELECT COUNT(*) FROM messages WHERE room_id = ?");
        $stT->execute(array($roomId));
        $totalMsgs = (int)$stT->fetchColumn();

        $stM = $db->prepare("SELECT COUNT(*) FROM room_members WHERE room_id = ?");
        $stM->execute(array($roomId));
        $memberCount = (int)$stM->fetchColumn();

        $totalRooms = (int)$db->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
        $totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();

        jsonResponse(array(
            'success'      => true,
            'active_now'   => $activeGlobal,
            'msgs_hour'    => $msgHour,
            'total_msgs'   => $totalMsgs,
            'member_count' => $memberCount,
            'total_rooms'  => $totalRooms,
            'total_users'  => $totalUsers,
            'server_time'  => date('H:i:s'),
        ));

    // ── Unknown action ────────────────────────────────────────────────────────
    default:
        jsonResponse(array('success' => false, 'error' => 'Unknown action: ' . $action), 400);
}
