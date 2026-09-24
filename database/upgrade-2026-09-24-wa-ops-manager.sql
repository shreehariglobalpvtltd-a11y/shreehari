-- =====================================================================
--  upgrade-2026-09-24-wa-ops-manager.sql — the WhatsApp assistant as a
--  company OPERATIONS MANAGER (24 Sep 2026).
--
--  What this adds, all additive and re-runnable, all switched OFF:
--
--   1. company_documents (+ _versions, _access) — the admin-approved
--      company knowledge and document vault: profile, policies,
--      procedures, agent/counter instructions, emergency contacts, and
--      the registration / tax / identity papers. Files are stored
--      ENCRYPTED under uploads/company/ (AES-256-GCM, key derived from
--      APP_KEY) and are only ever read through admin/company-doc-file.php
--      (staff session) or a single-use, short-lived share link the
--      assistant mints for a WhatsApp send. No public path is printed.
--      Every view, send and refusal is a row in company_document_access
--      (plus the usual audit_logs line).
--
--   2. wa_identity_links — step-up verification for staff and office
--      numbers. A WhatsApp number is already matched against the admins
--      table (AiTools::whoIs); for the actions that move money or open
--      confidential papers the sender must ALSO have opened a one-time
--      link while signed in to the staff panel within the last
--      wa_ops_stepup_minutes. The link token is stored hashed, never
--      logged, and dies on first use or expiry.
--
--   3. wa_share_links — the single-use tokens behind a document send.
--
--   4. support_tickets gains the columns a WhatsApp handoff needs:
--      where it came from, the sender's role and language, an
--      idempotency key so a retried message never opens two tickets,
--      and a pointer to any evidence file (payment screenshot).
--
--  Nothing here changes a booking, a seat, a fare or a refund. Nothing
--  changes until the office flips a switch in Admin -> Settings -> ai.
--
--  NOTE: no semicolons inside string literals — tests/apply-sql.php
--  splits on them.
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Company documents vault
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `company_documents` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`          VARCHAR(200) NOT NULL,
  `doc_type`       VARCHAR(40)  NOT NULL DEFAULT 'other' COMMENT 'profile, services, routes, schedule, fares, luggage, refund_policy, procedure, agent_guide, counter_guide, support_faq, marketing, emergency, registration, tax, identity, other',
  `sensitivity`    VARCHAR(20)  NOT NULL DEFAULT 'internal' COMMENT 'public, internal, confidential, restricted',
  `audience`       TEXT         NOT NULL COMMENT 'JSON list of roles that may see it: public, customer, agent, counter, support, manager, superadmin',
  `summary`        TEXT         NULL COMMENT 'the approved, shareable text the assistant may quote (masked before it leaves)',
  `search_text`    MEDIUMTEXT   NULL COMMENT 'extracted text used ONLY to match a question, never quoted verbatim',
  `file_path`      VARCHAR(255) NULL COMMENT 'relative to UPLOAD_PATH, encrypted blob, never a public link',
  `file_name`      VARCHAR(160) NULL COMMENT 'original name, sanitised, for the download header',
  `mime_type`      VARCHAR(80)  NULL,
  `file_size`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'plaintext bytes',
  `sha256`         CHAR(64)     NULL COMMENT 'of the plaintext',
  `encryption`     VARCHAR(20)  NOT NULL DEFAULT 'aes-256-gcm',
  `version`        INT UNSIGNED NOT NULL DEFAULT 1,
  `status`         VARCHAR(20)  NOT NULL DEFAULT 'draft' COMMENT 'draft, approved, archived',
  `owner_admin_id` BIGINT UNSIGNED NULL,
  `uploaded_by`    BIGINT UNSIGNED NULL,
  `approved_by`    BIGINT UNSIGNED NULL,
  `approved_at`    DATETIME NULL,
  `review_at`      DATE NULL COMMENT 'when the office should re-check it',
  `expires_at`     DATE NULL COMMENT 'after this date the assistant treats it as expired',
  `notes`          VARCHAR(500) NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_cdoc_status` (`status`, `sensitivity`, `doc_type`),
  KEY `ix_cdoc_expiry` (`expires_at`),
  KEY `ix_cdoc_review` (`review_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_document_versions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `version`     INT UNSIGNED NOT NULL,
  `snapshot`    LONGTEXT NOT NULL COMMENT 'JSON of the row as it was, minus search_text',
  `file_path`   VARCHAR(255) NULL COMMENT 'the superseded encrypted file, kept for rollback',
  `created_by`  BIGINT UNSIGNED NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cdoc_version` (`document_id`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `company_document_access` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id`    BIGINT UNSIGNED NULL,
  `version`        INT UNSIGNED NULL,
  `action`         VARCHAR(20) NOT NULL COMMENT 'search, view, download, send, link_fetch, deny',
  `channel`        VARCHAR(20) NOT NULL DEFAULT 'whatsapp' COMMENT 'whatsapp, admin, link',
  `actor_role`     VARCHAR(20) NULL COMMENT 'customer, staff, admin, or the admins.role',
  `actor_admin_id` BIGINT UNSIGNED NULL,
  `phone`          VARCHAR(20) NULL COMMENT 'normalised WhatsApp sender, when the channel is WhatsApp',
  `purpose`        VARCHAR(200) NULL,
  `ok`             TINYINT(1) NOT NULL DEFAULT 1,
  `detail`         VARCHAR(255) NULL,
  `ip_address`     VARCHAR(45) NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_cda_doc` (`document_id`, `created_at`),
  KEY `ix_cda_phone` (`phone`, `created_at`),
  KEY `ix_cda_action` (`action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. Step-up verification of a WhatsApp number
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wa_identity_links` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone`                VARCHAR(20) NOT NULL COMMENT 'normalised WhatsApp number',
  `admin_id`             BIGINT UNSIGNED NULL COMMENT 'the staff account that proved it owns this number',
  `verified_at`          DATETIME NULL,
  `verified_via`         VARCHAR(20) NULL COMMENT 'panel_link',
  `challenge_hash`       CHAR(64) NULL COMMENT 'sha256 of the outstanding one-time token, never the token',
  `challenge_expires_at` DATETIME NULL,
  `challenge_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `last_challenge_at`    DATETIME NULL,
  `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wail_phone` (`phone`),
  KEY `ix_wail_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  3. Single-use share links behind a WhatsApp document send
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wa_share_links` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_hash`   CHAR(64) NOT NULL,
  `document_id`  BIGINT UNSIGNED NOT NULL,
  `version`      INT UNSIGNED NOT NULL DEFAULT 1,
  `phone`        VARCHAR(20) NOT NULL COMMENT 'the WhatsApp number it was minted for',
  `actor_role`   VARCHAR(20) NULL,
  `expires_at`   DATETIME NOT NULL,
  `max_uses`     TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Meta may fetch a media link more than once while sending',
  `uses`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_used_at` DATETIME NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsl_token` (`token_hash`),
  KEY `ix_wsl_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  4. support_tickets — what a WhatsApp handoff needs (guarded ALTERs)
-- ---------------------------------------------------------------------
SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_tickets' AND COLUMN_NAME = 'source');
SET @sql := IF(@have = 0,
  'ALTER TABLE `support_tickets` ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT ''web'' COMMENT ''web, whatsapp_ai, admin'' AFTER `status`',
  'SELECT ''support_tickets.source already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_tickets' AND COLUMN_NAME = 'sender_role');
SET @sql := IF(@have = 0,
  'ALTER TABLE `support_tickets` ADD COLUMN `sender_role` VARCHAR(20) NULL COMMENT ''customer, staff, admin (as the assistant resolved it)'' AFTER `source`',
  'SELECT ''support_tickets.sender_role already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_tickets' AND COLUMN_NAME = 'language');
SET @sql := IF(@have = 0,
  'ALTER TABLE `support_tickets` ADD COLUMN `language` VARCHAR(8) NULL COMMENT ''ne, hi, en, gu'' AFTER `sender_role`',
  'SELECT ''support_tickets.language already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_tickets' AND COLUMN_NAME = 'dedupe_key');
SET @sql := IF(@have = 0,
  'ALTER TABLE `support_tickets` ADD COLUMN `dedupe_key` CHAR(64) NULL COMMENT ''sha256 of sender + category + redacted summary, so a retried message never opens two tickets'' AFTER `language`',
  'SELECT ''support_tickets.dedupe_key already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_tickets' AND COLUMN_NAME = 'evidence_path');
SET @sql := IF(@have = 0,
  'ALTER TABLE `support_tickets` ADD COLUMN `evidence_path` VARCHAR(255) NULL COMMENT ''encrypted inbound attachment kept for the desk, relative to UPLOAD_PATH'' AFTER `dedupe_key`',
  'SELECT ''support_tickets.evidence_path already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_tickets' AND COLUMN_NAME = 'office_notified_at');
SET @sql := IF(@have = 0,
  'ALTER TABLE `support_tickets` ADD COLUMN `office_notified_at` DATETIME NULL COMMENT ''when the office WhatsApp accepted the alert (NULL = nobody was told yet)'' AFTER `evidence_path`',
  'SELECT ''support_tickets.office_notified_at already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_tickets' AND INDEX_NAME = 'ix_support_dedupe');
SET @sql := IF(@have = 0,
  'ALTER TABLE `support_tickets` ADD KEY `ix_support_dedupe` (`dedupe_key`, `created_at`)',
  'SELECT ''ix_support_dedupe already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
--  5. The switches — every one OFF, so applying this file changes nothing.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('wa_ops_docs_on',        '0',  'bool',   'ai', 'Company documents vault: the assistant may search approved documents and send a file to an authorised WhatsApp number',0),
('wa_ops_doc_link_minutes','10','int',    'ai', 'How long a single-use document share link stays valid (minutes)',0),
('wa_ops_handoff_on',     '0',  'bool',   'ai', 'Human handoff: the assistant may open a trackable support request (SUP-...) for complaints, disputes, approvals and anything it must not decide',0),
('wa_ops_handoff_notify', '1',  'bool',   'ai', 'Alert the office WhatsApp number when the assistant opens a support request (needs a working WhatsApp driver)',0),
('wa_ops_stepup_on',      '0',  'bool',   'ai', 'Step-up verification: staff and office numbers must open a one-time link while signed in to the staff panel before sensitive WhatsApp actions',0),
('wa_ops_stepup_minutes', '30', 'int',    'ai', 'How long a step-up verification stays fresh (minutes)',0),
('wa_ops_stepup_actions', 'office_confirm,cancel_ticket,fix_ticket,company_doc_send,agent_day,office_day','string','ai','Comma-separated tools that need a fresh step-up verification from a staff or office number',0),
('wa_ops_media_on',       '0',  'bool',   'ai', 'Inbound attachments: tell the assistant a photo / document arrived (metadata only) and keep it as evidence for a handoff',0),
('wa_ops_media_keep_days','30', 'int',    'ai', 'How many days an inbound attachment is kept unless a support request still points at it (cron/rotate.php)',0),
('wa_ops_voice_on',       '0',  'bool',   'ai', 'Voice notes: transcribe with Gemini and read the words back for confirmation before acting (needs gemini_api_key)',0);
