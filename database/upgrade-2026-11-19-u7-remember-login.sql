-- =====================================================================
--  19 Nov 2026 (u7, LG-1 / LG-2): stay signed in on this device.
--
--  Staff: a device-bound remember token on the existing admin_sessions row
--  (AdminSessions::remember / resume). Only the SHA-256 of the token is
--  stored; the previous hash is kept for 2 minutes after a rotation so two
--  tabs waking up together do not sign each other out.
--
--  Customers: customer_sessions, one row per remembered phone browser,
--  minted only after a PROVEN door (WhatsApp code / hi, e-mail code,
--  passkey) in CustomerProfile::markVerified.
--
--  Idempotent: safe to run twice. Rollback = set login_remember_days = 0,
--  login_remember_office_hours = 0 and customer_remember_days = 0 (the
--  columns / table can stay; nothing reads them then).
-- =====================================================================
SET NAMES utf8mb4;

-- admin_sessions is normally created by AdminSessions::ensure(); make sure it exists first.
CREATE TABLE IF NOT EXISTS `admin_sessions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `token_hash`  CHAR(64)     NOT NULL,
  `admin_id`    INT UNSIGNED NOT NULL,
  `device_hash` CHAR(64)     NULL,
  `label`       VARCHAR(120) NULL,
  `ip`          VARCHAR(45)  NULL,
  `via`         VARCHAR(20)  NOT NULL DEFAULT 'password',
  `created_at`  DATETIME     NOT NULL,
  `last_seen`   DATETIME     NOT NULL,
  `ended_at`    DATETIME     NULL,
  `ended_by`    INT UNSIGNED NULL,
  `end_reason`  VARCHAR(120) NULL,
  UNIQUE KEY `uq_token` (`token_hash`),
  KEY `idx_admin` (`admin_id`, `ended_at`),
  KEY `idx_live` (`ended_at`, `last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_sessions' AND COLUMN_NAME = 'remember_hash');
SET @s := IF(@c = 0,
  'ALTER TABLE `admin_sessions`
     ADD COLUMN `remember_hash`       CHAR(64) NULL,
     ADD COLUMN `remember_prev_hash`  CHAR(64) NULL,
     ADD COLUMN `remember_rotated_at` DATETIME NULL,
     ADD COLUMN `remember_until`      DATETIME NULL,
     ADD COLUMN `pw_stamp`            CHAR(16) NULL,
     ADD UNIQUE KEY `uq_remember` (`remember_hash`),
     ADD KEY `idx_remember_prev` (`remember_prev_hash`)',
  'SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

CREATE TABLE IF NOT EXISTS `customer_sessions` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `token_hash`   CHAR(64)     NOT NULL,
  `prev_hash`    CHAR(64)     NULL,
  `rotated_at`   DATETIME     NULL,
  `device_hash`  CHAR(64)     NULL,
  `label`        VARCHAR(120) NULL,
  `via`          VARCHAR(20)  NULL,
  `created_at`   DATETIME     NOT NULL,
  `last_seen`    DATETIME     NOT NULL,
  `expires_at`   DATETIME     NOT NULL,
  `ended_at`     DATETIME     NULL,
  `end_reason`   VARCHAR(120) NULL,
  UNIQUE KEY `uq_cs_token` (`token_hash`),
  KEY `idx_cs_prev` (`prev_hash`),
  KEY `idx_cs_user` (`user_id`, `ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('login_mobile_first_on', '0', 'bool', 'security', 'मोबाइलबाट पहिलो लगइन · Staff door starts with the mobile: first time = WhatsApp code once, then own password; PIN / pattern optional. Needs the shg_login_code WhatsApp template first', 0),
  ('login_remember_days', '365', 'int', 'security', 'यो यन्त्रमा लगइन रहोस् · Field staff stay signed in on their own device for N days (0 = off). Ended by Sign out, office End, or a password change', 0),
  ('login_remember_roles', '["agent","counter","scanner","support","operations","staff"]', 'json', 'security', 'Roles that may stay signed in for login_remember_days (office roles get login_remember_office_hours)', 0),
  ('login_remember_office_hours', '24', 'int', 'security', 'Office roles (superadmin, manager, accountant ...) and 2FA accounts stay signed in on their device for N hours (0 = off)', 0),
  ('login_phone_id_on', '1', 'bool', 'security', 'मोबाइल नम्बरले लगइन · Staff may type their mobile instead of the code / username (then password, then PIN / pattern)', 0),
  ('customer_remember_days', '365', 'int', 'customers', 'यात्री लगइन रहोस् · Passengers stay signed in (verified) on their phone for N days after a WhatsApp / e-mail / passkey proof (0 = off)', 0);
