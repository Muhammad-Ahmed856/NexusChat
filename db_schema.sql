-- ─────────────────────────────────────────────────────────────────────────────
-- NexusChat — Database Schema
-- Usage:  mysql -u root -p < db_schema.sql
-- Safe to re-run: uses IF NOT EXISTS / IF EXISTS throughout
-- ─────────────────────────────────────────────────────────────────────────────

CREATE DATABASE IF NOT EXISTS chatapp
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE chatapp;

-- Disable FK checks during setup so table order doesn't matter
SET FOREIGN_KEY_CHECKS = 0;

-- ─── users ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INT          AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email         VARCHAR(255) DEFAULT NULL,
    avatar_color  VARCHAR(20)  DEFAULT '#6c63ff',
    avatar_url    VARCHAR(500) DEFAULT NULL,       -- stored filename (uploads/avatars/) or NULL
    created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    last_login    DATETIME     DEFAULT NULL,
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── rooms ───────────────────────────────────────────────────────────────────
-- created_by is nullable: if the creator deletes their account the room
-- stays alive and shows "Deleted" in the UI via COALESCE on the JOIN.
CREATE TABLE IF NOT EXISTS rooms (
    id                  INT          AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(100) NOT NULL,
    type                ENUM('public','private') DEFAULT 'public',
    created_by          INT          DEFAULT NULL,
    created_at          DATETIME     DEFAULT CURRENT_TIMESTAMP,
    room_password       VARCHAR(255) DEFAULT NULL,
    password_changed_at DATETIME     DEFAULT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_type (type),
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── room_members ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS room_members (
    room_id   INT      NOT NULL,
    user_id   INT      NOT NULL,
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (room_id, user_id),
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── uploads ─────────────────────────────────────────────────────────────────
-- Stores metadata for every uploaded file. The physical file lives in
-- uploads/<stored_name> relative to the app root.
-- NOTE: defined before messages so messages.file_id FK resolves without
-- needing FOREIGN_KEY_CHECKS=0.
CREATE TABLE IF NOT EXISTS uploads (
    id            INT           AUTO_INCREMENT PRIMARY KEY,
    user_id       INT           NOT NULL,
    room_id       INT           NOT NULL,
    original_name VARCHAR(255)  NOT NULL,   -- original filename shown to users
    stored_name   VARCHAR(100)  NOT NULL,   -- uuid-based name on disk (no path traversal)
    mime_type     VARCHAR(127)  NOT NULL,
    file_size     INT UNSIGNED  NOT NULL,   -- bytes
    uploaded_at   DATETIME      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    INDEX idx_room (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── messages ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS messages (
    id           INT           AUTO_INCREMENT PRIMARY KEY,
    user_id      INT           NOT NULL,
    username     VARCHAR(50)   NOT NULL,
    message      TEXT          NOT NULL,
    timestamp    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    ip           VARCHAR(45)   DEFAULT NULL,
    room_id      INT           NOT NULL,
    -- File/media attachment (NULL when no attachment)
    file_id      INT           DEFAULT NULL,
    deleted_for_everyone TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at   DATETIME      DEFAULT NULL,
    deleted_by_user_id INT     DEFAULT NULL,
    edited_at    DATETIME      DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id)  ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES uploads(id) ON DELETE SET NULL,
    FOREIGN KEY (deleted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_room_ts (room_id, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── deleted_messages ────────────────────────────────────────────────────────
-- Stores per-user hides for "Delete for me" without affecting other members.
CREATE TABLE IF NOT EXISTS deleted_messages (
    message_id  INT NOT NULL,
    user_id     INT NOT NULL,
    deleted_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id, user_id),
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_deleted (user_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── reactions ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reactions (
    message_id INT         NOT NULL,
    emoji      VARCHAR(10) NOT NULL,
    username   VARCHAR(50) NOT NULL,
    PRIMARY KEY (message_id, emoji, username),
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── message_status ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS message_status (
    message_id   INT         NOT NULL,
    username     VARCHAR(50) NOT NULL,
    delivered_at DATETIME    DEFAULT NULL,
    read_at      DATETIME    DEFAULT NULL,
    PRIMARY KEY (message_id, username),
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── active_users ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS active_users (
    username VARCHAR(50)       PRIMARY KEY,
    ts       DATETIME          DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ip       VARCHAR(45)       DEFAULT NULL,
    port     SMALLINT UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── packet_log ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS packet_log (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    direction ENUM('in','out') NOT NULL,
    type      VARCHAR(50)      NOT NULL,
    payload   TEXT,
    username  VARCHAR(50)      DEFAULT NULL,
    room_id   INT              DEFAULT NULL,
    ts        DATETIME         DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── removed_members ─────────────────────────────────────────────────────────
-- Tracks members kicked from a room so the client can detect it on next poll
CREATE TABLE IF NOT EXISTS removed_members (
    room_id    INT          NOT NULL,
    user_id    INT          NOT NULL,
    username   VARCHAR(50)  NOT NULL,
    removed_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (room_id, user_id),
    FOREIGN KEY (room_id) REFERENCES rooms(id)  ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────────────────────
-- Done. No seed data — register your first account through the app.
-- ─────────────────────────────────────────────────────────────────────────────
-- ─── login_attempts ──────────────────────────────────────────────────────────
-- Tracks login and registration attempts per IP for rate limiting.
-- Rows older than 1 hour are irrelevant; clean them up periodically.
CREATE TABLE IF NOT EXISTS login_attempts (
    id     INT          AUTO_INCREMENT PRIMARY KEY,
    ip     VARCHAR(45)  NOT NULL,
    action ENUM('login','register') NOT NULL DEFAULT 'login',
    ts     DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX  idx_ip_action_ts (ip, action, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Migrations already present in the schema ────────────────────────────────
-- The following were previously applied at runtime in ajax_server.php on every
-- request. They are now here where they belong. Safe to re-run (IF NOT EXISTS).

ALTER TABLE active_users
    MODIFY COLUMN port SMALLINT UNSIGNED DEFAULT NULL;   -- already existed; no-op if present

-- ─── Media / file-sharing migration (existing installs) ──────────────────────
-- If you already have the messages table from a previous version, run these:
--   ALTER TABLE messages ADD COLUMN file_id INT DEFAULT NULL;
--   ALTER TABLE messages ADD FOREIGN KEY (file_id) REFERENCES uploads(id) ON DELETE SET NULL;
--   ALTER TABLE messages ADD COLUMN deleted_for_everyone TINYINT(1) NOT NULL DEFAULT 0;
--   ALTER TABLE messages ADD COLUMN deleted_at DATETIME DEFAULT NULL;
--   ALTER TABLE messages ADD COLUMN deleted_by_user_id INT DEFAULT NULL;
--   ALTER TABLE messages ADD COLUMN edited_at DATETIME DEFAULT NULL;
--   ALTER TABLE messages ADD FOREIGN KEY (deleted_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
--   CREATE TABLE deleted_messages (...);
-- The CREATE TABLE above already includes file_id for fresh installs.

-- ─── bans ─────────────────────────────────────────────────────────────────────
-- Admin-managed user bans. Supports both account bans (user_id) and IP bans.
CREATE TABLE IF NOT EXISTS bans (
    id          INT           AUTO_INCREMENT PRIMARY KEY,
    user_id     INT           DEFAULT NULL,               -- NULL = IP-only ban
    username    VARCHAR(50)   DEFAULT NULL,               -- snapshot at ban time
    ip          VARCHAR(45)   DEFAULT NULL,               -- NULL = account-only ban
    reason      VARCHAR(255)  DEFAULT NULL,
    banned_by   VARCHAR(50)   NOT NULL,                   -- admin username
    banned_at   DATETIME      DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME      DEFAULT NULL,               -- NULL = permanent
    active      TINYINT(1)    NOT NULL DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user   (user_id, active),
    INDEX idx_ip     (ip, active),
    INDEX idx_active (active, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── audit_log ────────────────────────────────────────────────────────────────
-- Immutable record of every admin and key user action in the system.
CREATE TABLE IF NOT EXISTS audit_log (
    id          INT           AUTO_INCREMENT PRIMARY KEY,
    actor       VARCHAR(50)   NOT NULL,                   -- who did it
    action      VARCHAR(60)   NOT NULL,                   -- e.g. 'ban_user', 'kick_member'
    target_user VARCHAR(50)   DEFAULT NULL,               -- affected user (if any)
    target_room VARCHAR(100)  DEFAULT NULL,               -- affected room (if any)
    detail      TEXT          DEFAULT NULL,               -- free-form context
    ip          VARCHAR(45)   DEFAULT NULL,               -- actor's IP
    ts          DATETIME      DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ts     (ts),
    INDEX idx_actor  (actor, ts),
    INDEX idx_action (action, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── bandwidth_log ────────────────────────────────────────────────────────────
-- 1-minute buckets of inbound/outbound byte counts for the bandwidth meter.
CREATE TABLE IF NOT EXISTS bandwidth_log (
    id        INT           AUTO_INCREMENT PRIMARY KEY,
    bucket    DATETIME      NOT NULL,                     -- truncated to the minute
    bytes_in  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ts        DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bucket (bucket),
    INDEX idx_bucket (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
