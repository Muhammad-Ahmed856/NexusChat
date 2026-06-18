# ⚡ NexusChat

A full-featured, real-time multi-room chat application built with PHP, MySQL, WebSocket, and vanilla JavaScript — no frameworks, no Composer, no npm. Designed to run on a standard XAMPP stack. Built as a Computer Networks university project to demonstrate OSI layers, TCP/WebSocket connections, and real-time network traffic analysis.

---

## Feature Overview

### Messaging
- **Real-time delivery** — WebSocket server with seamless HTTP-polling fallback
- **Emoji picker** — full categorised picker (850+ emoji) with search, recently-used history, and cursor-position insertion
- **File & media sharing** — images (inline preview), video (inline player), audio files, PDFs, Office docs, archives — up to 10 GB per file
- **Voice messages** — record directly in the browser with a live mic-level meter; delivered as a compact inline audio player
- **Message reactions** — five emoji reactions per message with one-click toggle and live sync to all participants
- **Read receipts** — ✓ sent → ✓✓ grey delivered → ✓✓ green read by all
- **Typing indicators** — live broadcast via WebSocket (throttled to one event per 2 seconds per user)
- **Edit messages** — sender can edit within 1 minute of sending
- **Delete messages** — "delete for me" (soft-delete, your view only) or "delete for everyone" within 2 minutes; room admins have 24 hours

### Rooms
- Public and password-protected private rooms
- Room creator can kick members and change room password
- **"Clear Chat"** — hides all current messages from your view only; persisted to the server so it survives across devices and sessions (other members are never affected)

### Accounts
- Register with username + password
- **Profile picture** — upload a photo (JPEG/PNG/GIF/WebP, max 5 MB)
- Change username, email, password, avatar colour
- Per-user avatar shown in messages, sidebar, and the members list

### Network Visibility
- **Live IP + Port display** — every user sees their own IP address and session port in the chat footer, updated on each heartbeat
- Port numbers are captured at login and remain stable for the session, demonstrating TCP transport-layer socket identification

### Dashboard (admin only)
- Live stats sidebar in every chat room (online users, messages/hr, member count, total messages, room count, registered users)
- **Floating admin bar** — a persistent pill in the bottom-right corner, visible to the admin on every page (chat room, rooms list, LAN setup, dashboard). Provides one-click navigation to Dashboard, LAN Setup, and Rooms. Collapses to an icon to stay out of the way, and remembers its state across pages for the session.
- Full-screen Network Dashboard with six tabs:
  - **Overview** — 24-hour message activity chart, live WebSocket/transport status
  - **Active Users** — real-time IP and port tracking, port-change history badge (↻N), one-click Ban button per user
  - **Bandwidth** — dual inbound/outbound bar chart across the last 60 minutes with totals and peak-minute stats
  - **Ban Manager** — ban users by name with duration (1h → 30d or permanent), optional IP ban, reason field; full ban history with one-click Unban
  - **Audit Log** — immutable record of every admin action (ban, unban, kick, login, message delete) with actor, target, detail, IP, and timestamp; filterable by action type
  - **Packet Log** — live raw packet viewer
- Dashboard polls every 3 seconds; active-user table diffs in place (no flicker)

### System Overview
- `index.php` renders the chat UI and bootstraps the current room, user state, and CSRF token into `script.js`.
- `script.js` handles message sending, polling fallback, emoji, reactions, file uploads, read receipts, voice messages, and presence updates.
- `ajax_server.php` is the single HTTP API surface for auth, rooms, messages, presence, uploads, bans, audit history, packet logs, and network stats.
- `socket_server.php` provides real-time broadcast transport for messages, typing, edits, deletions, reactions, and room updates.
- `db_schema.sql` defines the full data model used by the app; it is safe to re-run on a fresh install.

### Data Model
- `users` stores accounts, avatars, email, and login timestamps.
- `rooms` and `room_members` manage public and private rooms and membership.
- `messages` stores message content, edits, deletions, attachments, timestamps, and sender metadata.
- `uploads` stores file metadata for attachments and avatar images.
- `message_status`, `deleted_messages`, and `reactions` track delivery, read state, per-user hiding, and emoji reactions.
- `active_users`, `packet_log`, `audit_log`, `bans`, `login_attempts`, and `bandwidth_log` power the live admin dashboard and security controls.

### Security
- CSRF tokens on all state-changing requests
- bcrypt password hashing (cost 10)
- Rate limiting — login capped at 10 attempts/IP/15 min; registration at 5/IP/hr (all attempts are counted before the duplicate-username check to prevent username enumeration)
- Email addresses validated with `filter_var(FILTER_VALIDATE_EMAIL)` on register and profile update
- MIME-type verified from file content (`finfo`), never from browser headers
- Randomised stored filenames; `.htaccess` auto-written to `uploads/` to block PHP execution
- Session fixation prevention (`session_regenerate_id(true)` on login/register)
- IP addresses stored server-side only; never broadcast to other clients
- Admin-only endpoints protected server-side
- `lan_setup.php` requires authentication — unauthenticated visitors cannot see server network info

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.x |
| Database | MySQL 5.7+ / MariaDB 10.4+ |
| Real-time | Pure PHP WebSocket server (no extensions needed) + HTTP polling fallback |
| Frontend | Vanilla JavaScript ES2017+, no frameworks |
| Styles | Custom CSS with CSS variables, dark theme |
| Auth | Session-based username + password |
| Local dev | XAMPP (Apache + MySQL + PHP) |

---

## File Structure

```
nexuschat/
├── config.php              # DB credentials, all constants, LAN auto-detection
├── auth.php                # Login / register entry page
├── rooms.php               # Room browser and creation
├── index.php               # Main chat room UI
├── ajax_server.php         # All AJAX/REST API endpoints
├── socket_server.php       # WebSocket server (run via CLI)
├── lan_setup.php           # LAN setup helper — login required; shows shareable URL + status
├── network_dashboard.php   # Admin-only network stats page (6 tabs: overview, users, bandwidth, bans, audit, packets)
├── _admin_bar.php          # Shared admin navigation bar — included on every page for the admin
├── style.css               # All chat UI styles
├── script.js               # All client-side logic
├── db_schema.sql           # Full database schema (safe to re-run)
├── uploads/                # Auto-created on first file upload
│   ├── avatars/            # Profile picture uploads
│   └── .htaccess           # Auto-written: disables PHP execution
└── README.md               # This file
```

---

## Fully Offline Operation

NexusChat is **100% offline** — it makes zero requests to the internet at any point.

### What was removed / replaced

| Item | Was | Now |
|---|---|---|
| Fonts | Google Fonts CDN | System font stack (Segoe UI / SF Pro / Ubuntu) |
| Auth | Username + password only | N/A — no third-party auth |
| Profile pics | Custom uploaded avatars (JPEG/PNG/GIF/WebP) | N/A — no external avatar URLs |
| STUN/TURN | Already removed | N/A |

### Offline font stack

```css
--font-sans: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, Ubuntu, sans-serif;
--font-mono: Consolas, 'Cascadia Code', Menlo, 'DejaVu Sans Mono', monospace;
```

---

## LAN Mode — Chat Without Internet

NexusChat supports **fully offline LAN chat** — multiple people can use it at the same time without any internet connection, as long as they are on the same Wi-Fi network or Ethernet switch.

### How It Works

```
Host machine (192.168.1.10)          LAN devices
┌──────────────────────────┐         ┌─────────────────────┐
│  Apache  :80             │◄───────►│ Phone / Laptop / PC │
│  WebSocket :8080         │         │ Browser → 192.168.1.10 │
│  MySQL   :3306 (local)   │         └─────────────────────┘
└──────────────────────────┘
```

### Quick LAN Setup

**Step 1 — Find the host's LAN IP**
- **Windows:** `ipconfig` → IPv4 Address
- **macOS:** `System Settings → Wi-Fi → Details → IP Address`
- **Linux:** `ip addr show` or `hostname -I`

**Step 2 — Start XAMPP:** Start both Apache and MySQL.

**Step 3 — Start the WebSocket server:**
```bash
php C:\xampp\htdocs\nexuschat\socket_server.php
```

**Step 4 — Open the firewall (Windows):**
```
Windows Defender Firewall → Advanced Settings → Inbound Rules → New Rule
  TCP → ports 80, 8080 → Allow → Private
```
On **macOS**, accept the "Allow incoming connections?" dialog.
On **Linux (ufw):** `sudo ufw allow 80/tcp && sudo ufw allow 8080/tcp`

**Step 5 — Share the URL:** Log in first, then visit:
```
http://localhost/nexuschat/lan_setup.php
```
> **Note:** `lan_setup.php` requires you to be logged in. This prevents unauthenticated visitors from seeing your server's IP and network configuration.

This page shows the exact URL to share (e.g. `http://192.168.1.10/nexuschat/auth.php`), live status checks, and a copy button. Other devices open that URL in any browser — no installation needed.

### Zero-Config Auto-Detection

`config.php` automatically detects the host's LAN IP from `$_SERVER['HTTP_HOST']`. If it returns `localhost`, override manually:
```php
define('LAN_HOST_OVERRIDE', '192.168.1.10');
```

### Troubleshooting LAN Issues

| Problem | Fix |
|---|---|
| Other devices get "This site can't be reached" | Allow TCP port 80 in Windows Firewall |
| Messages appear but not in real time | Allow TCP port 8080 in firewall |
| IP shows as `localhost` in the setup page | Set `LAN_HOST_OVERRIDE` in `config.php` |
| Can connect but get logged out immediately | Session cookie issue — `session.cookie_samesite = Lax` (already set) |
| Apache not found from other devices | Check `httpd.conf` — `Listen 80`, not `Listen 127.0.0.1:80` |

---

## Quick Start (XAMPP)

### 1 — Copy files

```
C:\xampp\htdocs\nexuschat\
```

### 2 — Create the database

Import `db_schema.sql` in phpMyAdmin, or run:
```sql
SOURCE C:/xampp/htdocs/nexuschat/db_schema.sql;
```

### 3 — Configure

Open `config.php` and set your admin username before registering:
```php
define('ADMIN_USERNAME', 'your_username');
```

### 4 — Start the WebSocket server

```bash
php C:\xampp\htdocs\nexuschat\socket_server.php
```

### 5 — Open the app

```
http://localhost/nexuschat/auth.php
```

Register your first account. The account matching `ADMIN_USERNAME` gets Network Dashboard access.

---

## Configuration Reference

| Constant | Default | Description |
|---|---|---|
| `DB_HOST` | `localhost` | MySQL host |
| `DB_NAME` | `chatapp` | Database name |
| `DB_USER` | `root` | MySQL username |
| `DB_PASS` | *(empty)* | MySQL password |
| `LAN_HOST_OVERRIDE` | *(empty — auto)* | Force a specific LAN IP |
| `WS_PORT` | `8080` | WebSocket server port |
| `WS_PUBLIC_HOST` | *(auto-detected)* | LAN IP used by browsers for WebSocket |
| `ADMIN_USERNAME` | `admin` | Username with Network Dashboard access |
| `MAX_MESSAGE_LENGTH` | `2000` | Max characters per text message |
| `MESSAGES_PER_PAGE` | `50` | Messages fetched per poll |
| `BCRYPT_COST` | `10` | bcrypt work factor |
| `UPLOAD_MAX_BYTES` | `10737418240` | Max file upload size (10 GB) |
| `UPLOAD_DIR` | `uploads/` | Physical storage folder for files |
| `AVATAR_MAX_BYTES` | `5242880` | Max profile picture size (5 MB) |
| `AVATAR_DIR` | `uploads/avatars/` | Physical folder for profile pictures |
| `DELETE_FOR_EVERYONE_WINDOW_SECONDS` | `120` | Sender's delete-for-everyone window (2 min) |
| `ROOM_ADMIN_DELETE_FOR_EVERYONE_WINDOW_SECONDS` | `86400` | Room admin's delete window (24 hr) |
| `EDIT_MESSAGE_WINDOW_SECONDS` | `60` | Window to edit a sent message (1 min) |
---

## API Endpoints

All served by `ajax_server.php`. GET actions use query strings; POST actions use `multipart/form-data`. All POST actions except login, register, and status require `_csrf`.

### Auth
| Action | Method | Key Parameters | Returns |
|---|---|---|---|
| `auth_register` | POST | `username`, `password`, `email`?, `avatar_color` | `{success, csrf_token}` |
| `auth_login` | POST | `username`, `password` | `{success, csrf_token}` |
| `auth_logout` | POST | `_csrf` | `{success}` |
| `auth_status` | GET | — | `{success, logged_in, user?}` |

### Rooms
| Action | Method | Key Parameters | Returns |
|---|---|---|---|
| `list_rooms` | GET | — | `{success, rooms[]}` |
| `create_room` | POST | `name`, `type` (`public`\|`private`), `password`? | `{success, room_id}` |
| `join_room` | POST | `room_id`, `password`? | `{success}` |
| `leave_room` | POST | `room_id` | `{success}` |
| `room_members` | GET | `room_id` | `{success, members[]}` |
| `remove_member` | POST | `room_id`, `user_id` | `{success}` |
| `change_room_password` | POST | `room_id`, `new_password` | `{success}` |

### Messages
| Action | Method | Key Parameters | Returns |
|---|---|---|---|
| `get_messages` | GET | `room_id`, `since`?, `clear_ts`?, `before_id`?, `anchor_unread`? | `{success, messages[], has_more, oldest_id, unread_count, first_unread_id}` |
| `send_message` | POST | `room_id`, `message`, `file_id`? | `{success, message_id}` |
| `edit_message` | POST | `message_id`, `message` | `{success, message_id, message, edited_at}` |
| `delete_message` | POST | `message_id`, `scope` (`me`\|`everyone`) | `{success, message_id, scope}` |
| `clear_messages` | POST | `room_id` | `{success}` — soft-deletes all room messages **for requesting user only** |
| `react` | POST | `message_id`, `emoji` | `{success, reactions}` |
| `read` | POST | `message_ids` (JSON array) | `{success}` |
| `delivered` | POST | `message_id` | `{success}` |
| `read_status` | GET | `room_id`, `message_ids` | `{success, statuses}` |

### Files & Avatars
| Action | Method | Key Parameters | Returns |
|---|---|---|---|
| `upload_file` | POST | `room_id`, `file` | `{success, file_id, mime_type, file_size}` |
| `serve_file` | GET | `file_id`, `download`? | File stream |
| `upload_avatar` | POST | `avatar` | `{success, avatar_url}` |
| `remove_avatar` | POST | — | `{success}` |

### User Account
| Action | Method | Key Parameters | Returns |
|---|---|---|---|
| `user_update` | POST | `username`?, `email`?, `password`?, `avatar_color`? | `{success}` |
| `user_delete` | POST | `password` | `{success}` |

### Presence & Stats
| Action | Method | Returns |
|---|---|---|
| `heartbeat` | GET | `{success, session_ip, session_port, ts}` |
| `get_session_info` | GET | `{success, session_ip, session_port}` |
| `get_room_stats` | GET | Active users, msg counts, member count, totals |
| `get_lan_info` | GET | `{success, lan_mode, lan_ip, app_url, auth_url, ws_url}` |

### Admin Only
| Action | Method | Key Parameters | Returns |
|---|---|---|---|
| `network_stats` | GET | — | Active users list, rates, chart, active ban count, bandwidth snapshot |
| `packet_log` | GET | `limit`? | Recent packet log entries |
| `ban_user` | POST | `username`, `reason`?, `hours`?, `ban_ip`? | `{success, message}` |
| `unban_user` | POST | `username` | `{success, message}` |
| `list_bans` | GET | — | Full ban history (auto-expires timed bans) |
| `get_audit_log` | GET | `limit`?, `action_filter`? | Audit entries, newest first |
| `bandwidth_stats` | GET | — | 60-minute series + totals + peaks |

---

## WebSocket Protocol

**Client → Server**

| Type | Key Fields | Purpose |
|---|---|---|
| `auth` | `username` | Identify after connecting |
| `join_room` | `room_id` | Enter a chat room |
| `message` | `message`, `room_id`, `avatar_color`, `file_id`? | Send a message |
| `typing` | `room_id` | Broadcast typing indicator (throttled to 1 per 2s client-side) |
| `reaction` | `message_id`, `emoji`, `reactions` | Broadcast reaction update |
| `message_deleted` | `message_id`, `scope`, `room_id` | Notify peers of a deletion |
| `message_edited` | `message_id`, `message`, `edited_at`, `room_id` | Notify peers of an edit |
| `room_updated` | `room_id`, `reason` | Notify of room setting change |
| `ping` | — | Keep-alive |

**Server → Client**

| Type | Key Fields | Purpose |
|---|---|---|
| `auth_ok` | — | Authentication confirmed |
| `joined` | `room_id` | Room join confirmed |
| `message` | `id`, `username`, `message`, `ts`, `avatar_color`, `file_id`? | Incoming message (plain text; HTML-escaped by the client renderer) |
| `system` | `message`, `room_id` | System notice (join/leave) |
| `reaction` | `message_id`, `reactions`, `room_id` | Reaction updated |
| `typing` | `username`, `room_id` | Typing indicator |
| `message_deleted` | `message_id`, `scope`, `room_id` | Message deleted for everyone |
| `message_edited` | `message_id`, `message`, `edited_at`, `room_id` | Message edited |
| `room_updated` | `room_id`, `reason` | Room settings changed |
| `pong` | — | Heartbeat reply |

---

## Browser Support

| Feature | Minimum |
|---|---|
| Core chat + emoji picker | Chrome 80 · Firefox 75 · Safari 14 · Edge 80 |
| WebSocket | All modern browsers (auto-falls-back to HTTP polling) |
| File sharing | All modern browsers (`fetch` + `FormData`) |
| Voice messages | Chrome 47 · Firefox 25 · Safari 14.1 (`MediaRecorder`) |
| Read receipts | All modern browsers (`IntersectionObserver`) |

---

## Known Limitations

- The WebSocket server is single-process PHP, suited for small teams and demos. For high-concurrency production use, replace it with [Ratchet](https://github.com/ratchetphp/Ratchet), [Swoole](https://github.com/swoole/swoole-src), or a Node.js WebSocket server.
- LAN mode requires all devices to be on the same network segment.
- Files are stored on the local filesystem — for multi-server deployments, use shared storage (S3, NFS) and update `UPLOAD_DIR`.
- `login_attempts` rows are never cleaned automatically — add a MySQL event or cron: `DELETE FROM login_attempts WHERE ts < DATE_SUB(NOW(), INTERVAL 24 HOUR)`.
---

## Changelog

### v1.2 — Admin / Network Features

#### Floating Admin Bar (`_admin_bar.php`)
A shared PHP include that renders a floating navigation pill (bottom-right) on every authenticated page for the admin account. Non-admin users see nothing. Links to Dashboard, LAN Setup, and Rooms with the current page highlighted. Collapses to a ⚙ icon to stay out of the way; state persists for the session via `sessionStorage`. Replaces the old scattered one-off admin links in `rooms.php` and `index.php`.

#### Ban Manager
- New `bans` table in `db_schema.sql` — stores user_id, username snapshot, optional IP, reason, who banned, timestamp, expiry, and active flag.
- Three new API endpoints: `ban_user`, `unban_user`, `list_bans`.
- **Ban enforcement** in `auth_login` — a banned user receives a clear error including reason and expiry; they cannot log in even with the correct password.
- Supports timed bans (1h / 6h / 24h / 3d / 7d / 30d) or permanent. Optional IP ban using the user's last-known IP from `active_users`.
- Dashboard Ban Manager tab: form with username, reason, duration, and IP-ban toggle; ban history table with Active/Expired badges and one-click Unban; Quick-ban button on every row of the Active Users tab.

#### Audit Log
- New `audit_log` table — immutable record of admin and key user actions (ban, unban, kick, login, message delete).
- `auditLog()` helper in `config.php` — one call from any endpoint.
- Automatically logged: every login (with IP), every room kick, every ban/unban.
- Dashboard Audit Log tab: scrollable terminal-style viewer with colour-coded action types; filter dropdown by action type.

#### Bandwidth Meter
- New `bandwidth_log` table — one row per minute using `ON DUPLICATE KEY UPDATE` for safe concurrent accumulation.
- `_bwTrack($db, $in, $out)` helper in `config.php` — instrumented on `send_message`, `ban_user`, `unban_user`, `list_bans`, `get_audit_log`.
- New `bandwidth_stats` API endpoint returns a filled 60-point series, hour totals, and is consumed by the Bandwidth tab.
- Dashboard Bandwidth tab: dual inbound (green) / outbound (blue) bar chart across 60 minutes; stats panel with total in/out/combined and peak-minute values.
- `network_stats` now also returns `bw_in_last_min` and `active_bans` for the top stat cards.

#### Network Dashboard Restructure
- Replaced single-page layout with six tabs: Overview, Active Users, Bandwidth, Ban Manager, Audit Log, Packet Log.
- Active Users tab now includes a per-row Ban button for quick banning from the live user list.

---

### v1.1 — Bug Fix Release

#### Security Fixes

**Rate-limit bypass via username enumeration** (`ajax_server.php`)
The `login_attempts` row was inserted only after a *successful* registration. A bot probing usernames ("Username already taken") never had those attempts counted. The attempt is now logged before the duplicate check, so every registration request — successful or not — counts toward the 5/hour limit.

**Email not validated** (`ajax_server.php`)
`auth_register` and `user_update` accepted any string as an email. `filter_var($email, FILTER_VALIDATE_EMAIL)` is now enforced before storing.

**Unauthenticated access to `lan_setup.php`**
`lan_setup.php` exposed the server's LAN IP, WebSocket URL, and live port status to anyone with the URL — no login required. `requireAuth()` is now called at the top of the file.

#### Logic / Correctness Fixes

**`clear_messages` permanently deleted messages for everyone** (`ajax_server.php` + `script.js`)
The server endpoint executed `DELETE FROM messages WHERE room_id = ?`, destroying all messages in the room for every user, while the UI confirmation dialog said "only affects your view." These are now consistent: the endpoint does a per-user soft-delete into `deleted_messages` (matching the client-side `clearTimestamp` approach). The client also now persists the clear to the server so it survives across devices and sessions.

**`messages.file_id` missing FK constraint** (`db_schema.sql`)
The `messages` CREATE TABLE defined `file_id INT DEFAULT NULL` with no foreign key. Deleting an `uploads` row could leave orphaned `file_id` values in `messages`. `FOREIGN KEY (file_id) REFERENCES uploads(id) ON DELETE SET NULL` is now present. The `uploads` table has been moved before `messages` so the FK resolves correctly without relying on `FOREIGN_KEY_CHECKS = 0`.

#### WebSocket / Real-time Fixes

**Double HTML-escaping of messages** (`socket_server.php`)
The WebSocket server called `htmlspecialchars()` on outgoing message payloads, then `_buildMessageElement()` in `script.js` called `escHtml()` again. Messages containing `&`, `<`, `>`, `"`, or `'` displayed as broken entities (e.g. `&amp;`). Server-side escaping removed — HTML-escaping belongs exclusively in the client renderer.

**Typing indicator sent on every keystroke** (`script.js`)
`notifyTyping()` used `setTimeout(() => {}, 2000)` — an empty callback — so the throttle flag was never reset and a WebSocket event was sent on every single keypress, flooding the server. Replaced with a proper `_typingThrottled` boolean that blocks subsequent sends for 2 seconds.

**`markDelivered` called with empty array on WS message receive** (`script.js`)
When a message arrived via WebSocket, `markDelivered([])` was called with no IDs, causing the function to return immediately. The sender's "delivered" double-tick was never updated for WebSocket-delivered messages. The actual `msg.id` is now passed.

---

### Schema Cleanup

**Removed `google_id` and `google_avatar` columns from `users`** (`db_schema.sql`)
Google OAuth was explored during development but never shipped. The `google_id VARCHAR(128) UNIQUE`, `google_avatar VARCHAR(500)`, and their associated `idx_google_id` index were left in the schema after the feature was removed. No PHP or JavaScript code ever read or wrote these columns. All three have been dropped from the table definition. The `password_hash` column comment "empty string for OAuth-only accounts" has also been removed since it no longer applies.

**Removed `direct` from `rooms.type` ENUM** (`db_schema.sql` + `ajax_server.php`)
Direct messaging was planned but never implemented. The `ENUM('public','private','direct')` definition and the `WHERE r.type != 'direct'` filter in `list_rooms` were the only traces. The ENUM is now `ENUM('public','private')` and the filter clause has been removed. The `create_room` endpoint already rejected `type = 'direct'` via its `in_array` validation, so there is no change in runtime behaviour.

---

## License

MIT — free to use, modify, and distribute. Built as a university computer networks project.
