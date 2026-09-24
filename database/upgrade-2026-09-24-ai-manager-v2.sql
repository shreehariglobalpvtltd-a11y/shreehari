-- 24 Sep 2026 — AI Manager v2: memory that follows the person, learning from
-- corrections the office approves, and a marketing queue. Everything OFF.
--
--   ai_memory_on        the assistant (WhatsApp and web) remembers each person:
--                       trips, usual pickup, corrections, complaints — written
--                       by the register's own events, read into the prompt
--   ai_examples_on      approved examples (learned from 👎 / "galat") are shown
--                       to the assistant as "how we answer this"
--   ai_refresh_on       cron/ai-refresh.php refreshes the company-facts article
--                       nightly and prunes old memory
--   social_publish_on   cron/social-publish.php publishes approved posts
--   social_daily_cap    at most this many posts a day across channels (6)
--   meta_page_id / meta_page_token / ig_user_id   Facebook Page + Instagram
--   telegram_bot_token / telegram_channel         Telegram channel
--
-- Additive, idempotent.

CREATE TABLE IF NOT EXISTS `ai_memory_profile` (
  `owner_key`       CHAR(64) NOT NULL,
  `display_name`    VARCHAR(120) NULL,
  `language`        VARCHAR(10) NULL,
  `usual_pickup`    VARCHAR(120) NULL,
  `usual_direction` VARCHAR(10) NULL,
  `trips`           INT UNSIGNED NOT NULL DEFAULT 0,
  `last_trip_date`  DATE NULL,
  `last_seen`       DATETIME NULL,
  `notes`           VARCHAR(500) NULL,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`owner_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_memory_episodes` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_key`   CHAR(64) NOT NULL,
  `channel`     VARCHAR(20) NOT NULL DEFAULT 'system',
  `kind`        VARCHAR(30) NOT NULL,
  `summary`     VARCHAR(500) NOT NULL,
  `booking_id`  BIGINT UNSIGNED NULL,
  `importance`  TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `happened_at` DATETIME NOT NULL,
  `expires_at`  DATE NULL,
  PRIMARY KEY (`id`),
  KEY `ix_mem_owner_time` (`owner_key`, `happened_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_feedback_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_key`  CHAR(64) NOT NULL,
  `channel`    VARCHAR(20) NOT NULL,
  `verdict`    ENUM('up','down','correction') NOT NULL,
  `user_text`  TEXT NULL,
  `reply_text` TEXT NULL,
  `note`       VARCHAR(500) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_fb_time` (`created_at`),
  KEY `ix_fb_verdict` (`verdict`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_examples` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `intent`      VARCHAR(50) NOT NULL DEFAULT 'general',
  `language`    VARCHAR(10) NOT NULL DEFAULT 'ne',
  `user_text`   TEXT NOT NULL,
  `bad_reply`   TEXT NULL,
  `good_reply`  TEXT NOT NULL,
  `rule_text`   VARCHAR(500) NULL,
  `source`      VARCHAR(30) NOT NULL DEFAULT 'feedback',
  `source_ref`  VARCHAR(64) NULL,
  `status`      ENUM('candidate','approved','retired') NOT NULL DEFAULT 'candidate',
  `approved_by` BIGINT UNSIGNED NULL,
  `approved_at` DATETIME NULL,
  `hits`        INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_ex_status` (`status`, `language`, `intent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `social_posts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel`      ENUM('facebook','instagram','telegram') NOT NULL,
  `kind`         ENUM('text','photo') NOT NULL DEFAULT 'text',
  `topic`        VARCHAR(60) NULL,
  `caption_en`   TEXT NULL,
  `caption_hi`   TEXT NULL,
  `caption_ne`   TEXT NULL,
  `media_path`   VARCHAR(255) NULL,
  `link_url`     VARCHAR(255) NULL,
  `publish_at`   DATETIME NOT NULL,
  `status`       ENUM('draft','approved','publishing','published','failed','cancelled') NOT NULL DEFAULT 'draft',
  `remote_id`    VARCHAR(120) NULL,
  `error`        VARCHAR(500) NULL,
  `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`   BIGINT UNSIGNED NULL,
  `approved_by`  BIGINT UNSIGNED NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `published_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_sp_due` (`status`, `publish_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
  ('ai_memory_on',        '0', 'bool',   'ai',        'AI remembers each person (trips, pickup, corrections)', 0),
  ('ai_examples_on',      '0', 'bool',   'ai',        'AI uses office-approved examples learned from corrections', 0),
  ('ai_refresh_on',       '0', 'bool',   'ai',        'Nightly refresh of the AI company-facts article + memory hygiene', 0),
  ('social_publish_on',   '0', 'bool',   'marketing', 'Publish approved social posts (Facebook / Instagram / Telegram)', 0),
  ('social_daily_cap',    '6', 'int',    'marketing', 'Max social posts per day, all channels', 0),
  ('meta_page_id',        '',  'string', 'marketing', 'Facebook Page id', 0),
  ('meta_page_token',     '',  'string', 'marketing', 'Facebook Page access token (system user, never expires)', 0),
  ('ig_user_id',          '',  'string', 'marketing', 'Instagram business account id', 0),
  ('telegram_bot_token',  '',  'string', 'marketing', 'Telegram bot token', 0),
  ('telegram_channel',    '',  'string', 'marketing', 'Telegram channel (@name or chat id)', 0);
