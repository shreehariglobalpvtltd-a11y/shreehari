-- =====================================================================
--  WhatsApp Manager (5 Oct 2026) — one ledger for every message the
--  system sends, plus the AiSensy wallet history.
--
--  Additive only: two new tables, nothing existing is altered. Safe to
--  run twice. includes/msgledger.php checks for the tables and stays
--  silent until this has been applied.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `msg_ledger` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_id`       BIGINT UNSIGNED NULL COMMENT 'message_logs.id this row was copied from / matched to',
  `provider`     VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'aisensy / cloud_api / twilio / brevo / manual',
  `channel`      VARCHAR(10)  NOT NULL DEFAULT 'whatsapp' COMMENT 'whatsapp / sms / email',
  `to_number`    VARCHAR(40)  NOT NULL DEFAULT '',
  `country`      CHAR(2)      NOT NULL DEFAULT '' COMMENT 'IN / NP / OT from the number',
  `who_name`     VARCHAR(120) NULL COMMENT 'customer / agent / staff name found for the number',
  `who_kind`     VARCHAR(12)  NULL COMMENT 'customer / agent / counter / staff / owner',
  `booking_id`   BIGINT UNSIGNED NULL,
  `pnr`          VARCHAR(40)  NULL,
  `purpose`      VARCHAR(40)  NULL COMMENT 'ticket / seller_copy / bot_reply / statement ...',
  `label`        VARCHAR(80)  NOT NULL DEFAULT '' COMMENT 'template name, or text / image',
  `category`     VARCHAR(16)  NOT NULL DEFAULT '' COMMENT 'utility / marketing / authentication / service / sms / email',
  `body`         VARCHAR(700) NULL,
  `media_url`    VARCHAR(255) NULL,
  `status`       VARCHAR(12)  NOT NULL DEFAULT 'accepted' COMMENT 'accepted / sent / delivered / read / failed / skipped / queued',
  `error`        VARCHAR(255) NULL,
  `provider_ref` VARCHAR(190) NULL COMMENT 'wamid / SID; NULL when the provider gave none',
  `cost`         DECIMAL(9,3) NOT NULL DEFAULT 0 COMMENT 'INR, from the rate card once the status is final',
  `admin_id`     BIGINT UNSIGNED NULL COMMENT 'staff member who pressed send',
  `created_at`   DATETIME     NOT NULL,
  `status_at`    DATETIME     NULL,
  `checked_at`   DATETIME     NULL,
  `polls`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ml_ref` (`provider_ref`),
  UNIQUE KEY `uq_ml_log` (`log_id`),
  KEY `ix_ml_created` (`created_at`),
  KEY `ix_ml_status` (`status`, `created_at`),
  KEY `ix_ml_to` (`to_number`),
  KEY `ix_ml_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wam_credit` (
  `id`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `at`      DATETIME NOT NULL,
  `credit`  DECIMAL(12,2) NOT NULL COMMENT 'AiSensy WhatsApp conversation credit, INR',
  `quality` VARCHAR(12) NULL,
  `tier`    VARCHAR(20) NULL,
  PRIMARY KEY (`id`),
  KEY `ix_wc_at` (`at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `is_public`) VALUES
  ('openai_api_key', '',            'string', 'ai', 0),
  ('openai_model',   '',            'string', 'ai', 0),
  ('wam_on',         '1',           'bool',   'whatsapp', 0);
