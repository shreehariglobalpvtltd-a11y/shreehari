-- =====================================================================
--  upgrade-2026-11-16b-W6-facebook.sql  (Master v2 P6-8a/b, 16 Nov 2026)
--
--  The Facebook post queue (includes/socialposts.php, admin/social-posts.php,
--  cron/social-publish.php).
--
--  1. social_posts — the SAME shape the live server already has (it was
--     created on 24 Sep 2026 by upgrade-2026-09-24-ai-manager-v2.sql and has
--     0 rows), copied from SHOW CREATE TABLE on live. On live this is a
--     no-op; on a fresh server it makes the table.
--  2. social_publish_on — the switch, OFF. Live never got the row (the old
--     INSERT IGNORE was not applied there), so Admin -> Settings could not
--     show it. While it is 0 the cron publishes nothing, ever.
--     social_daily_cap already exists on live (6); INSERT IGNORE keeps it.
--
--  Additive and safe to re-run. The code works before this file runs:
--  without the table the page shows a notice and the cron exits idle;
--  without the switch row Settings::getBool() reads the default (off).
--  No Page token is written here: the posts use the token the Messenger
--  page (admin/facebook.php) already holds.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `social_posts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel`      ENUM('facebook','instagram','telegram') NOT NULL,
  `kind`         ENUM('text','photo') NOT NULL DEFAULT 'text',
  `topic`        VARCHAR(60) NULL DEFAULT NULL,
  `caption_en`   TEXT NULL DEFAULT NULL,
  `caption_hi`   TEXT NULL DEFAULT NULL,
  `caption_ne`   TEXT NULL DEFAULT NULL,
  `media_path`   VARCHAR(255) NULL DEFAULT NULL,
  `link_url`     VARCHAR(255) NULL DEFAULT NULL,
  `publish_at`   DATETIME NOT NULL,
  `status`       ENUM('draft','approved','publishing','published','failed','cancelled') NOT NULL DEFAULT 'draft',
  `remote_id`    VARCHAR(120) NULL DEFAULT NULL,
  `error`        VARCHAR(500) NULL DEFAULT NULL,
  `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`   BIGINT UNSIGNED NULL DEFAULT NULL,
  `approved_by`  BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `published_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_sp_due` (`status`, `publish_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('social_publish_on', '0', 'bool', 'marketing', 'Publish APPROVED Facebook posts (Admin -> FB posts) when their time comes. OFF = nothing is ever posted', 0),
  ('social_daily_cap',  '6', 'int',  'marketing', 'Max social posts per day, all channels', 0);
