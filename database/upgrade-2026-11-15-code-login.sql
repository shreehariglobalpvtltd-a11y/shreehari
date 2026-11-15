-- =====================================================================
--  upgrade-2026-11-15-code-login.sql
--
--  Owner, 15 Nov 2026: staff / agent / admin sign-in is CODE -> PIN or
--  pattern -> own dashboard. First-time setup and "Forgot PIN / Pattern"
--  check the code + registered date of birth + registered mobile, then
--  prove the registered WhatsApp (or an office-approved activation code)
--  before a PIN / pattern may be set (includes/codelogin.php).
--
--  1. admins.unlock_strikes  how many times quick unlock was locked in a
--     row; each new lock lasts twice as long (15, 30, 60 ... 1440 min).
--     Back to 0 after a correct PIN / pattern.
--  2. login_recovery_requests  "office, please verify me" requests and the
--     one-time activation codes the office issues (bcrypt only).
--
--  Additive and re-runnable. Nothing existing is changed or removed:
--  codes, roles, passwords, PIN / pattern hashes, bookings, wallets and
--  commissions are untouched.
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'unlock_strikes');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admins` ADD COLUMN `unlock_strikes` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''PIN/pattern lockouts in a row (progressive cooldown)''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'pin_len');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admins` ADD COLUMN `pin_len` TINYINT UNSIGNED NULL COMMENT ''4 or 6: how many PIN boxes the lock screen draws (NULL = older PIN, OK button)''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS `login_recovery_requests` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`      BIGINT UNSIGNED NULL COMMENT 'the account the typed code points at (NULL = no such code)',
  `code_typed`    VARCHAR(40)  NOT NULL DEFAULT '' COMMENT 'what was typed in the code box',
  `kind`          VARCHAR(10)  NOT NULL DEFAULT 'setup' COMMENT 'setup | reset',
  `reason`        VARCHAR(30)  NOT NULL DEFAULT '' COMMENT 'no_number | no_match | no_details | asked',
  `details_match` TINYINT(1)   NULL COMMENT '1 = DOB + mobile matched the record, 0 = not, NULL = not checked',
  `contact_note`  VARCHAR(160) NOT NULL DEFAULT '' COMMENT 'how the person says the office can reach them',
  `status`        VARCHAR(10)  NOT NULL DEFAULT 'open' COMMENT 'open | approved | rejected | used | expired',
  `act_hash`      VARCHAR(255) NULL COMMENT 'bcrypt of the one-time activation code',
  `act_expires`   DATETIME     NULL,
  `act_tries`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `ip_address`    VARCHAR(45)  NOT NULL DEFAULT '',
  `decided_by`    BIGINT UNSIGNED NULL,
  `decided_at`    DATETIME     NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lrr_status` (`status`, `created_at`),
  KEY `idx_lrr_admin` (`admin_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
