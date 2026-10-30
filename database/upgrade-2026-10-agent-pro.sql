-- =====================================================================
--  upgrade-2026-10-agent-pro.sql — Agent Pro (Oct 2026)
--
--  ID cards, visiting cards, the join form, the member wallet screens,
--  the WhatsApp desk for agents and staff, and the local (VPS) AI.
--  See docs/AGENT-PRO-DESIGN.md.
--
--  Run ONCE (or again — it is idempotent):
--      php tests/apply-sql.php database/upgrade-2026-10-agent-pro.sql
--  or Admin -> Agent Pro installer -> "Install all".
--
--  ADDITIVE ONLY: nine new tables (ap_*) and new settings rows. No
--  existing table or row is changed. The wallet stays agent_ledger, the
--  withdrawals stay agent_payout_requests, the agent codes stay in
--  settings.agent_codes, KYC stays on admin_profiles — this file only adds
--  what did not exist anywhere.
--
--  NB: no semicolon inside any quoted string — apply-sql.php splits on it.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1) One row per member of staff (agents included): what the ID card and
--    the visiting card print, plus payout details. The ID NUMBER of an
--    agent is their agent code (SHG-0027, read live), so it is not stored
--    here — only non-agent staff get a staff_no (SHG-S-0005).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_members` (
  `admin_id`          BIGINT UNSIGNED NOT NULL,
  `staff_no`          INT UNSIGNED NULL COMMENT 'non-agent staff serial -> SHG-S-0005',
  `designation`       VARCHAR(80)  NULL COMMENT 'printed under the name, e.g. Ticketing Agent',
  `department`        VARCHAR(80)  NULL,
  `dob`               DATE NULL,
  `gender`            ENUM('Male','Female','Other') NULL,
  `blood_group`       VARCHAR(5)   NULL,
  `guardian`          VARCHAR(120) NULL COMMENT 'father / mother / spouse name',
  `emergency_name`    VARCHAR(120) NULL,
  `emergency_phone`   VARCHAR(20)  NULL,
  `card_token`        CHAR(24)     NOT NULL COMMENT 'public verify token in the ID card QR, rotated on re-issue',
  `card_issued_at`    DATETIME NULL,
  `card_valid_until`  DATE NULL,
  `card_status`       ENUM('active','suspended','revoked') NOT NULL DEFAULT 'active',
  `vc_whatsapp`       VARCHAR(20)  NULL COMMENT 'the number printed as WA: and encoded in the visiting card QR',
  `vc_phone`          VARCHAR(20)  NULL COMMENT 'second number on the visiting card',
  `vc_email`          VARCHAR(191) NULL,
  `vc_tagline`        VARCHAR(120) NULL,
  `vc_greeting`       VARCHAR(160) NULL COMMENT 'pre-filled WhatsApp text when the QR is scanned',
  `vc_theme`          VARCHAR(20)  NOT NULL DEFAULT 'royal',
  `photo_ver`         INT UNSIGNED NOT NULL DEFAULT 1,
  `bank_holder`       VARCHAR(120) NULL,
  `bank_name`         VARCHAR(120) NULL,
  `bank_account_enc`  VARCHAR(255) NULL COMMENT 'sodium secretbox, key from APP_KEY',
  `bank_account_last4` VARCHAR(4)  NULL,
  `bank_ifsc`         VARCHAR(20)  NULL,
  `bank_branch`       VARCHAR(120) NULL,
  `upi_id`            VARCHAR(100) NULL,
  `esewa_id`          VARCHAR(40)  NULL,
  `khalti_id`         VARCHAR(40)  NULL,
  `lang`              ENUM('ne','hi','en') NOT NULL DEFAULT 'ne',
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `uq_ap_members_token` (`card_token`),
  UNIQUE KEY `uq_ap_members_staffno` (`staff_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) The join form. A draft is kept while the applicant fills it (by a
--    random draft_key in their browser), so a dropped connection on step 4
--    loses nothing. Submitted = an inactive admins row exists (admin_id),
--    exactly like agent-signup.php, so Staff & Approvals sees it too.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_applications` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref`              VARCHAR(12)  NOT NULL COMMENT 'JN-7KQ2M, what the applicant quotes',
  `draft_key`        CHAR(32)     NOT NULL,
  `status`           ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
  `kind`             ENUM('agent','staff') NOT NULL DEFAULT 'agent',
  `agent_kind`       ENUM('org','person') NOT NULL DEFAULT 'person',
  `role_wanted`      VARCHAR(20)  NULL COMMENT 'staff invites only',
  `designation`      VARCHAR(80)  NULL,
  `invite_id`        BIGINT UNSIGNED NULL,
  `full_name`        VARCHAR(120) NULL,
  `org_name`         VARCHAR(120) NULL,
  `phone`            VARCHAR(20)  NULL,
  `whatsapp`         VARCHAR(20)  NULL,
  `wa_verified_at`   DATETIME NULL,
  `wa_verified_via`  ENUM('otp','reverse') NULL,
  `verify_code`      VARCHAR(8)   NULL COMMENT 'the JOIN code for reverse verification',
  `email`            VARCHAR(191) NULL,
  `dob`              DATE NULL,
  `gender`           ENUM('Male','Female','Other') NULL,
  `guardian`         VARCHAR(120) NULL,
  `address`          VARCHAR(255) NULL,
  `city`             VARCHAR(80)  NULL,
  `district`         VARCHAR(80)  NULL,
  `state`            VARCHAR(80)  NULL,
  `country`          ENUM('IN','NP') NULL,
  `pincode`          VARCHAR(10)  NULL,
  `id_type`          VARCHAR(40)  NULL,
  `id_number_enc`    VARCHAR(255) NULL,
  `id_number_masked` VARCHAR(40)  NULL,
  `id_expires_on`    DATE NULL,
  `doc_front`        VARCHAR(255) NULL COMMENT 'agents-kyc/<file>',
  `doc_back`         VARCHAR(255) NULL,
  `selfie`           VARCHAR(255) NULL,
  `thumb`            VARCHAR(255) NULL COMMENT 'thumb impression photo',
  `extras`           LONGTEXT NULL COMMENT 'JSON [{label, path}]',
  `bank_holder`      VARCHAR(120) NULL,
  `bank_name`        VARCHAR(120) NULL,
  `bank_account_enc` VARCHAR(255) NULL,
  `bank_account_last4` VARCHAR(4) NULL,
  `bank_ifsc`        VARCHAR(20)  NULL,
  `bank_branch`      VARCHAR(120) NULL,
  `upi_id`           VARCHAR(100) NULL,
  `esewa_id`         VARCHAR(40)  NULL,
  `khalti_id`        VARCHAR(40)  NULL,
  `readout`          LONGTEXT NULL COMMENT 'JSON: fields read from the ID and from where',
  `admin_id`         BIGINT UNSIGNED NULL COMMENT 'the inactive admins row made at submit',
  `decided_by`       BIGINT UNSIGNED NULL,
  `decided_at`       DATETIME NULL,
  `decision_note`    VARCHAR(255) NULL,
  `ip`               VARCHAR(45)  NULL,
  `user_agent`       VARCHAR(255) NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `submitted_at`     DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_app_ref` (`ref`),
  UNIQUE KEY `uq_ap_app_draft` (`draft_key`),
  KEY `ix_ap_app_status` (`status`,`submitted_at`),
  KEY `ix_ap_app_phone` (`phone`),
  KEY `ix_ap_app_verify` (`verify_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3) Staff invites: the public form makes AGENTS only. Office staff
--    (counter, manager, accountant ...) join through a link the office
--    creates, which carries the role.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_invites` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token`          CHAR(24) NOT NULL,
  `role`           VARCHAR(20) NOT NULL,
  `designation`    VARCHAR(80) NULL,
  `note`           VARCHAR(160) NULL,
  `created_by`     BIGINT UNSIGNED NULL,
  `expires_at`     DATETIME NOT NULL,
  `used_at`        DATETIME NULL,
  `application_id` BIGINT UNSIGNED NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_invite_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4) WhatsApp number <-> member, proven by an OTP to the member's
--    registered mobile ("LINK SHG-0027"). One number belongs to one member.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_wa_links` (
  `phone`        VARCHAR(20) NOT NULL COMMENT 'normalised digits, as WaBot sees the sender',
  `admin_id`     BIGINT UNSIGNED NOT NULL,
  `via`          ENUM('otp','registered') NOT NULL DEFAULT 'otp',
  `verified_at`  DATETIME NOT NULL,
  `last_seen_at` DATETIME NULL,
  `revoked_at`   DATETIME NULL,
  PRIMARY KEY (`phone`),
  KEY `ix_ap_wa_links_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5) Per-number conversation state: language, turn counter, the one action
--    a "ho / yes" would confirm, and a short model history.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_wa_state` (
  `phone`      VARCHAR(20) NOT NULL,
  `lang`       ENUM('ne','hi','en') NULL,
  `turn`       INT UNSIGNED NOT NULL DEFAULT 0,
  `pending`    LONGTEXT NULL COMMENT 'JSON {kind, payload, at}',
  `history`    LONGTEXT NULL COMMENT 'JSON model turns, short',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6) The AI queue. One worker, one model call at a time (CPU).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_jobs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone`       VARCHAR(20)  NOT NULL,
  `kind`        VARCHAR(20)  NOT NULL DEFAULT 'wa',
  `text`        TEXT NOT NULL,
  `status`      ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `reply`       TEXT NULL,
  `media`       VARCHAR(500) NULL,
  `error`       VARCHAR(255) NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at`  DATETIME NULL,
  `finished_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_ap_jobs_status` (`status`,`id`),
  KEY `ix_ap_jobs_phone` (`phone`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7) Money the member paid the company (cash they held, or a security
--    deposit), waiting for the office to see it in the bank and confirm.
--    Confirming writes the agent_ledger row (cash_handover) or the deposit.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_deposit_requests` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`     BIGINT UNSIGNED NOT NULL,
  `amount`       DECIMAL(10,2) NOT NULL,
  `purpose`      ENUM('handover','security') NOT NULL DEFAULT 'handover',
  `method`       ENUM('upi','bank','cash','esewa','khalti','other') NOT NULL DEFAULT 'upi',
  `utr`          VARCHAR(80)  NULL,
  `proof_path`   VARCHAR(255) NULL,
  `note`         VARCHAR(200) NULL,
  `status`       ENUM('open','confirmed','declined') NOT NULL DEFAULT 'open',
  `decided_by`   BIGINT UNSIGNED NULL,
  `decided_at`   DATETIME NULL,
  `decided_note` VARCHAR(255) NULL,
  `ledger_id`    BIGINT UNSIGNED NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_ap_dep_status` (`status`,`created_at`),
  KEY `ix_ap_dep_admin` (`admin_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8) Passkeys — sign in to /me/ with the phone's fingerprint / face unlock.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_passkeys` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`       BIGINT UNSIGNED NOT NULL,
  `credential_id`  VARCHAR(255) NOT NULL COMMENT 'base64url',
  `public_key_pem` TEXT NOT NULL,
  `alg`            SMALLINT NOT NULL,
  `sign_count`     INT UNSIGNED NOT NULL DEFAULT 0,
  `label`          VARCHAR(80) NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at`   DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_passkey_cred` (`credential_id`),
  KEY `ix_ap_passkey_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9) Every call to the local model: is it fast enough, is it failing?
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ap_ai_calls` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purpose`    VARCHAR(30)  NOT NULL,
  `model`      VARCHAR(80)  NOT NULL,
  `ms`         INT UNSIGNED NOT NULL DEFAULT 0,
  `ok`         TINYINT(1)   NOT NULL DEFAULT 1,
  `tokens_in`  INT UNSIGNED NULL,
  `tokens_out` INT UNSIGNED NULL,
  `note`       VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_ap_ai_calls_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10) Settings. INSERT IGNORE: a value the office already set is kept.
--     Safe defaults: the join form and the WhatsApp desk are ON (they only
--     answer people who ask), the local AI is OFF until a model server is
--     configured on Admin -> AI (VPS).
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('ap_join_open','1','bool','agentpro','Join form open to new agents',0),
('ap_join_max_extras','6','int','agentpro','Extra documents an applicant may add',0),
('ap_aadhaar_store_full','0','bool','agentpro','Store the full Aadhaar number (off = masked XXXX XXXX 1234, the UIDAI rule)',0),
('ap_card_valid_years','2','int','agentpro','ID card validity (years from issue)',0),
('ap_card_tagline','India & Nepal Travel Service','string','agentpro','Line under the company name on visiting cards',0),
('ap_card_signature','','string','agentpro','Name under the authorised signature on the ID card (empty = company operator)',0),
('ap_vc_greeting','Namaste! SHG card','string','agentpro','Default WhatsApp text when a visiting card QR is scanned',0),
('ap_wa_on','1','bool','agentpro','WhatsApp desk for agents and staff (balance, statement, link, cards)',0),
('ap_wa_sell','1','bool','agentpro','Agents and staff may sell and change tickets from WhatsApp (always quote first, then ho)',0),
('ap_php_cli','','string','agentpro','PHP command-line binary for the background worker (empty = auto)',0),
('ap_worker_seen','','string','agentpro','Last time the background worker ran (set by the worker)',0),
('ap_wa_dir_agents','0','bool','agentpro','Agents may list other agents numbers on WhatsApp (off = office numbers only)',0),
('ap_ai_on','0','bool','agentpro','Use the AI on this VPS (Ollama) for WhatsApp and document reading',0),
('ap_ai_base','http://127.0.0.1:11434','string','agentpro','Model server address (Ollama or OpenAI-compatible)',0),
('ap_ai_model','','string','agentpro','Chat model (empty = best installed)',0),
('ap_ai_vision_model','','string','agentpro','Model that reads ID photos (empty = the chat model if it has vision)',0),
('ap_ai_api_key','','string','agentpro','Key for an OpenAI-compatible server (Ollama needs none)',0),
('ap_ai_timeout','150','int','agentpro','Seconds one model call may take',0),
('ap_ai_replace_cloud','1','bool','agentpro','When the VPS AI is on, it answers WhatsApp instead of Claude / Gemini',0),
('ap_ai_customers','1','bool','agentpro','The VPS AI also answers passengers (off = agents and staff only)',0),
('ap_ai_ack','1','bool','agentpro','Send a short please-wait line when the AI will take more than a few seconds',0),
('ap_ocr_on','1','bool','agentpro','Read ID photos with Tesseract when it is installed on the VPS',0);
