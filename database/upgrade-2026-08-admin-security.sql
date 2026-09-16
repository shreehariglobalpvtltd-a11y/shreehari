-- =====================================================================
--  Upgrade 2026-08 — Login history, device tracking and the counter PIN lock
--
--  Run ONCE on the live database (hPanel -> phpMyAdmin -> your database ->
--  SQL tab -> paste -> Go), or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-08-admin-security.sql
--
--  Idempotent and additive: CREATE TABLE IF NOT EXISTS plus guarded ALTERs,
--  so re-running is a no-op and it is safe to run while the site is live.
--
--  NB tests/apply-sql.php splits this file on ";" — never put a semicolon
--  inside a string literal or a COMMENT here, or the statement is cut in two.
--
--  WHY A SEPARATE TABLE FROM audit_logs
--  ------------------------------------
--  Successful sign-ins already reach audit_logs via Logger::audit(). FAILED
--  ones never did — includes/auth.php sent them to Logger::warning(), which
--  writes app_logs — so the security view could show a success but never the
--  three failures before it, which is the one pattern you actually want to
--  see. audit_logs is also shaped for "what changed from what" and has
--  nowhere to put a user agent or a device. Hence a purpose-built record.
--
--  Deliberately NO foreign key on admin_login_events.admin_id: a security
--  record has to outlive the account it describes. Deleting a staff member
--  must not erase the evidence of how they signed in.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1) Every sign-in attempt, successful or not.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_login_events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    BIGINT UNSIGNED NULL COMMENT 'NULL when the username matched no account',
  `username`    VARCHAR(60) NOT NULL COMMENT 'as typed, so failed attempts stay attributable',
  `outcome`     ENUM('success','bad_password','unknown_user','locked','disabled','logout')
                NOT NULL DEFAULT 'success',
  `ip_address`  VARCHAR(45)  NULL,
  `user_agent`  VARCHAR(255) NULL,
  `device_hash` CHAR(64)     NULL COMMENT 'SHA-256 of the device cookie, never the cookie itself',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_le_admin`   (`admin_id`, `created_at`),
  KEY `ix_le_user`    (`username`, `created_at`),
  KEY `ix_le_outcome` (`outcome`, `created_at`),
  KEY `ix_le_ip`      (`ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 2) The devices a staff member signs in from.
--
--    device_hash is the SHA-256 of a random cookie value. The raw cookie
--    is never stored, so a leaked database row cannot be replayed as a
--    device. Revoking sets revoked_at, which forces that browser to be
--    treated as new on its next sign-in.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_devices` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    BIGINT UNSIGNED NOT NULL,
  `device_hash` CHAR(64) NOT NULL,
  `label`       VARCHAR(120) NULL COMMENT 'derived from the user agent, e.g. Chrome on Windows',
  `last_ip`     VARCHAR(45)  NULL,
  `sign_ins`    INT UNSIGNED NOT NULL DEFAULT 0,
  `first_seen`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen`   DATETIME NULL,
  `revoked_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device` (`admin_id`, `device_hash`),
  KEY `ix_dev_admin` (`admin_id`, `last_seen`),
  CONSTRAINT `fk_dev_admin` FOREIGN KEY (`admin_id`)
    REFERENCES `admins` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 3) Counter PIN lock.
--
--    A short PIN that re-opens an ALREADY authenticated session — the
--    counter agent who steps away from the desk. It is a screen lock, not
--    a second factor and not a password: it can never start a session, and
--    a locked session that fails the PIN too often is signed out entirely.
--    Hashed with password_hash() like any other secret.
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'pin_hash');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `pin_hash` VARCHAR(255) NULL COMMENT ''screen-lock PIN, password_hash()'' AFTER `photo_path`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ---------------------------------------------------------------------
-- 4) Settings (Admin -> Settings, group "security").
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('login_history_days','180','int','security','Days of sign-in history to keep',0),
('device_tracking_enabled','1','bool','security','Remember which devices staff sign in from',0),
('pin_lock_enabled','1','bool','security','Allow staff to set a screen-lock PIN',0),
('pin_lock_idle_min','0','int','security','Auto-lock the screen after this many idle minutes (0 = off)',0);
