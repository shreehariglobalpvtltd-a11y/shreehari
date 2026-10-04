-- =====================================================================
--  5 Oct 2026 - Customer directory + marketing campaigns
--  includes/customerdirectory.php, includes/campaigns.php,
--  admin/customer-directory.php, admin/campaigns.php, cron/campaigns.php
--  Additive only. Re-running this file is harmless.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `mk_campaigns` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(120) NOT NULL,
  `channel`         ENUM('wa_template','wa_manual','sms') NOT NULL DEFAULT 'wa_manual',
  `template_name`   VARCHAR(80) NULL COMMENT 'AiSensy template (wa_template)',
  `template_params` TEXT NULL COMMENT 'JSON list of the template variables - {name} {first} {link} {phone} allowed',
  `header_image`    VARCHAR(500) NULL COMMENT 'https image for an IMAGE template',
  `message`         TEXT NULL COMMENT 'text for one-tap WhatsApp and SMS',
  `link_url`        VARCHAR(500) NULL COMMENT 'offer link - src=cmp<id> is added',
  `audience`        VARCHAR(1000) NULL COMMENT 'customer-directory filter query string',
  `status`          ENUM('draft','scheduled','running','paused','done','cancelled') NOT NULL DEFAULT 'draft',
  `start_at`        DATETIME NULL,
  `rate_per_min`    SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  `total`           INT UNSIGNED NOT NULL DEFAULT 0,
  `sent`            INT UNSIGNED NOT NULL DEFAULT 0,
  `failed`          INT UNSIGNED NOT NULL DEFAULT 0,
  `delivered`       INT UNSIGNED NOT NULL DEFAULT 0,
  `read_n`          INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`      BIGINT UNSIGNED NULL,
  `launched_by`     BIGINT UNSIGNED NULL,
  `launched_at`     DATETIME NULL,
  `finished_at`     DATETIME NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_mk_status` (`status`, `start_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mk_recipients` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id`  INT UNSIGNED NOT NULL,
  `phone`        VARCHAR(20) NOT NULL COMMENT 'international digits 91.. / 977..',
  `name`         VARCHAR(120) NULL,
  `state`        ENUM('queued','sending','sent','delivered','read','failed','skipped','optout') NOT NULL DEFAULT 'queued',
  `provider_ref` VARCHAR(160) NULL,
  `error`        VARCHAR(255) NULL,
  `sent_at`      DATETIME NULL,
  `checked_at`   DATETIME NULL COMMENT 'last delivery-status read from AiSensy',
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mk_rcpt` (`campaign_id`, `phone`),
  KEY `ix_mk_state` (`campaign_id`, `state`),
  KEY `ix_mk_sent` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mk_optout` (
  `phone`      VARCHAR(20) NOT NULL COMMENT 'last 10 digits',
  `reason`     VARCHAR(120) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('mk_daily_cap',      '300', 'int',    'marketing', 'Campaigns: at most this many automatic messages a day', 0),
 ('mk_quiet_from',     '21',  'int',    'marketing', 'Campaigns: no automatic sends from this hour (24 h)', 0),
 ('mk_quiet_to',       '8',   'int',    'marketing', 'Campaigns: automatic sends start again at this hour', 0),
 ('mk_max_recipients', '5000','int',    'marketing', 'Campaigns: largest audience one campaign may have', 0),
 ('mk_optout_line',    'यस्ता सन्देश नचाहिए STOP लेख्नुहोस्।', 'string', 'marketing', 'Campaigns: last line of every one-tap WhatsApp / SMS', 0);
