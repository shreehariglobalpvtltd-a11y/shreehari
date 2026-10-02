-- =====================================================================
--  Upgrade 2026-09-27 — agreement applications + agreement state on agents
--
--  Run ONCE on the live database:
--      mysql shari < database/upgrade-2026-09-27-agent-applications.sql
--  or from the checkout:
--      php tests/apply-sql.php database/upgrade-2026-09-27-agent-applications.sql
--
--  Fully idempotent: CREATE TABLE IF NOT EXISTS, and every ALTER is guarded
--  against information_schema, so re-running is a no-op and it is safe to
--  run while the site is live. Adds no settings switch.
--
--  WHAT THIS ADDS
--  --------------
--  1) agent_applications — one row per "apply for an agreement" form sent
--     from /agreements/ (api/agreement-apply.php). The office reads and
--     works them on admin/agreements.php.
--       ref               AGR-000123, made from the id right after insert
--       type              org = organization / Pvt. Ltd. partner
--                         ind = individual agent / connector
--       phone             normalisePhone() form (the dedupe key)
--       fields_json       every posted field the page knows, trimmed,
--                         at most 300 characters each
--       photo             the applicant's photo as a data: URI, kept IN THE
--                         DATABASE on purpose. A new folder under /uploads
--                         would be served publicly by nginx until a deny
--                         rule is added on live, and an ID photo must never
--                         sit on an open URL. Only signed-in staff see it.
--       public_ok         the applicant agreed that the public list may show
--                         their FULL phone number (otherwise it is masked)
--       status            new -> reviewing -> kyc_pending ->
--                         agreement_pending -> approved | rejected
--       kyc_status        submitted by default: the form itself carries the
--                         ID details the office has to check
--       agreement_status  none | sent | signed | expired, with the signed
--                         date and the end date (signed + 11 months)
--       admin_id          the agent account (admins.id) once one is made
--       payload_hash      sha256 of the whole form. The SAME form again
--                         inside 24 h is a retry and gets its first ref back
--       duplicate_of      a DIFFERENT form from the same phone + type inside
--                         24 h is stored as a new row pointing at the first
--                         one, so nothing anybody sends is thrown away
--
--  2) admin_profiles gets the same agreement state for EXISTING agents and
--     organizations, plus two public-list switches:
--       public_listing    1 = the agent may appear on the public list of
--                         agents and partners (names and numbers only).
--                         DEFAULT 0 = opt-in: nobody is published until the
--                         office ticks "Listed" on admin/agreements.php
--       public_phone      1 = show the full phone there, 0 = masked
--                         (first 2 and last 3 digits). Default masked.
--
--  A database that ran the first draft of this file (public_listing
--  DEFAULT 1, no payload_hash / duplicate_of) is brought up to this one:
--  the two columns are added and public_listing flips to DEFAULT 0 with
--  every row set to 0 — once, only while the old default is still there.
--
--  NB: no semicolon inside any quoted string or COMMENT — tests/apply-sql.php
--  splits this file on the semicolon and would cut a statement in half.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `agent_applications` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref`                 VARCHAR(16)  NULL COMMENT 'AGR-000123, set from the id after insert',
  `type`                ENUM('org','ind') NOT NULL COMMENT 'org = organization partner, ind = individual agent',
  `lang`                CHAR(2)      NOT NULL DEFAULT 'ne',
  `name`                VARCHAR(160) NOT NULL,
  `phone`               VARCHAR(20)  NOT NULL COMMENT 'normalisePhone() digits',
  `phone_raw`           VARCHAR(40)  NULL COMMENT 'as typed',
  `email`               VARCHAR(191) NULL,
  `area`                VARCHAR(160) NULL,
  `fields_json`         MEDIUMTEXT   NULL COMMENT 'every posted field, known names only, max 300 chars each',
  `photo`               MEDIUMTEXT   NULL COMMENT 'data URI image, staff-only, never under /uploads',
  `public_ok`           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = the public list may show the full phone',
  `status`              ENUM('new','reviewing','kyc_pending','agreement_pending','approved','rejected') NOT NULL DEFAULT 'new',
  `kyc_status`          ENUM('none','submitted','verified','rejected') NOT NULL DEFAULT 'submitted',
  `agreement_status`    ENUM('none','sent','signed','expired') NOT NULL DEFAULT 'none',
  `agreement_signed_at` DATE NULL,
  `agreement_end`       DATE NULL COMMENT 'signed + 11 months',
  `admin_id`            BIGINT UNSIGNED NULL COMMENT 'the agent account (admins.id) once created',
  `note`                VARCHAR(255) NULL COMMENT 'office note',
  `reviewed_by`         BIGINT UNSIGNED NULL COMMENT 'admins.id of the last change',
  `reviewed_at`         DATETIME NULL,
  `payload_hash`        CHAR(64)     NULL COMMENT 'sha256 of the whole form - an exact resend is a retry',
  `duplicate_of`        BIGINT UNSIGNED NULL COMMENT 'first application from the same phone + type inside 24 h',
  `ip`                  VARCHAR(45)  NULL,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agent_app_ref` (`ref`),
  KEY `ix_agent_app_phone` (`phone`),
  KEY `ix_agent_app_status` (`status`),
  KEY `ix_agent_app_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The first draft of this table had no payload_hash / duplicate_of.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'payload_hash');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `payload_hash` CHAR(64) NULL COMMENT ''sha256 of the whole form - an exact resend is a retry'' AFTER `reviewed_at`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'duplicate_of');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `duplicate_of` BIGINT UNSIGNED NULL COMMENT ''first application from the same phone + type inside 24 h'' AFTER `payload_hash`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- admin_profiles: agreement state + public-list switches for agents
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'agreement_status');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `agreement_status` ENUM(''none'',''sent'',''signed'',''expired'') NOT NULL DEFAULT ''none'' COMMENT ''agent or partner agreement state''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'agreement_signed_at');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `agreement_signed_at` DATE NULL AFTER `agreement_status`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'agreement_end');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `agreement_end` DATE NULL COMMENT ''signed + 11 months'' AFTER `agreement_signed_at`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'public_listing');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `public_listing` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = may appear on the public list of agents - opt-in'' AFTER `agreement_end`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- First draft had DEFAULT 1 (everybody public at once). Flip it to opt-in,
-- once: only while the column still carries the old default.
SET @def := (SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'public_listing');
SET @sql := IF(@def IS NOT NULL AND TRIM(BOTH '''' FROM @def) = '1',
  'ALTER TABLE `admin_profiles` MODIFY COLUMN `public_listing` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = may appear on the public list of agents - opt-in''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@def IS NOT NULL AND TRIM(BOTH '''' FROM @def) = '1',
  'UPDATE `admin_profiles` SET `public_listing` = 0',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'public_phone');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `public_phone` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = the public list shows the full phone, 0 = masked'' AFTER `public_listing`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
