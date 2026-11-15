-- =====================================================================
--  upgrade-2026-11-16-fb-inbound.sql  (Master v2, 16 Nov 2026)
--
--  1. fb_inbound — the Facebook Messenger conversation, so the office can
--     SEE it (admin/messenger-inbox.php) and answer inside Meta's 24-hour
--     window. One row per message:
--       direction 'in'   what the person sent to the Page (kind message |
--                        postback | attachment); mid = Meta's id, UNIQUE,
--                        so a re-delivered event is stored once
--       direction 'out'  what we sent back (kind text | image | buttons),
--                        purpose bot_reply (the assistant) or inbox_reply
--                        (a person from the office, admin_id set)
--     message_logs cannot hold these: its channel is an enum without
--     'facebook'. psid is the Page-scoped id Meta gives the person (no
--     phone number). The words are blanked after 30 days by
--     cron/wa-inbound.php.
--
--  2. wa_noreply_alert_on (OFF) — cron/wa-inbound.php tells the office
--     (one bell alert + one office WhatsApp per number per hour) when a
--     WhatsApp message has had no reply for 10 minutes.
--
--  Additive and safe to re-run. Before this file runs, Messenger keeps
--  answering exactly as before, nothing is stored, the inbox page says the
--  table is missing, and the no-reply alert stays off.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `fb_inbound` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mid`        VARCHAR(191)  NULL DEFAULT NULL COMMENT 'Meta message id (inbound) - UNIQUE dedupe; NULL for our sends',
  `psid`       VARCHAR(40)   NOT NULL COMMENT 'Page-scoped id of the person on Messenger',
  `direction`  ENUM('in','out') NOT NULL DEFAULT 'in',
  `kind`       VARCHAR(20)   NOT NULL DEFAULT 'message' COMMENT 'in: message | postback | attachment; out: text | image | buttons',
  `body`       VARCHAR(2000) NULL DEFAULT NULL COMMENT 'the words (blanked after 30 days)',
  `purpose`    VARCHAR(40)   NULL DEFAULT NULL COMMENT 'out: bot_reply | inbox_reply',
  `status`     ENUM('received','sent','failed') NOT NULL DEFAULT 'received',
  `admin_id`   BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'out: admins.id who replied from the inbox',
  `error`      VARCHAR(255)  NULL DEFAULT NULL,
  `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fbi_mid` (`mid`),
  KEY `ix_fbi_psid` (`psid`, `id`),
  KEY `ix_fbi_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('wa_noreply_alert_on', '0', 'bool', 'whatsapp', 'Tell the office (bell + office WhatsApp, once per number per hour) when a WhatsApp message has had no reply for 10 minutes', 0);
